<?php

namespace App\Console\Commands;

use App\Blueprints\BlueprintRegistry;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Console\Command;
use Throwable;

class RunAppSchedules extends Command
{
    protected $signature = 'zonseo:run-app-schedules {--app= : Only run this app key}';

    protected $description = 'Run each enabled app\'s daily work (monthly rent invoices, rent escalations) in every workspace';

    public function handle(BlueprintRegistry $blueprints, WorkspaceContext $context): int
    {
        $total = 0;
        $failures = 0;

        Workspace::query()->with('modules')->chunkById(100, function ($workspaces) use ($blueprints, $context, &$total, &$failures) {
            foreach ($workspaces as $workspace) {
                foreach ($workspace->modules->pluck('key') as $key) {
                    $app = $blueprints->get($key);
                    if (! $app || ($this->option('app') && $this->option('app') !== $key)) {
                        continue;
                    }

                    try {
                        $changed = $context->run($workspace, fn (Workspace $workspace) => $app->logic()->daily($workspace));
                        if ($changed) {
                            $this->line($workspace->name.' · '.$app->name.': '.$changed.' changed');
                        }
                        $total += $changed;
                    } catch (Throwable $e) {
                        $failures++;
                        report($e);
                        $this->error($workspace->name.' · '.$app->name.': '.$e->getMessage());
                    }
                }
            }
        });

        $this->info($total.' changes made'.($failures ? ', '.$failures.' failed' : '').'.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
