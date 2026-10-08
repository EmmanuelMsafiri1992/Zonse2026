<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Workspace;
use App\Support\Automations;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Carries out one automation's actions for the record that set it off, and logs the outcome. */
class RunAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $data the record as it was when the event happened */
    public function __construct(
        public Automation $automation,
        public string $event,
        public string $subjectType,
        public int|string $subjectId,
        public array $data,
    ) {}

    public function handle(WorkspaceContext $context): void
    {
        $automation = $this->automation->fresh();
        $workspace = $automation ? Workspace::find($automation->workspace_id) : null;
        if (! $automation?->is_active || ! $workspace) {
            return;
        }

        $context->run($workspace, function () use ($automation, $workspace) {
            $class = Relation::getMorphedModel($this->subjectType) ?? $this->subjectType;
            /** @var Model|null $subject */
            $subject = class_exists($class) ? $class::query()->find($this->subjectId) : null;

            $log = [];
            if (! $subject) {
                $log[] = ['ok' => false, 'text' => 'The record was deleted before the automation ran.'];
            } else {
                Automations::quietly(function () use ($automation, $subject, $workspace, &$log) {
                    foreach ($automation->actions as $action) {
                        try {
                            $log[] = ['ok' => true, 'text' => Automations::perform($automation, $action, $subject, $this->data, $workspace)];
                        } catch (RuntimeException $e) {
                            $log[] = ['ok' => false, 'text' => $e->getMessage()];
                        } catch (Throwable $e) {
                            report($e);
                            $log[] = ['ok' => false, 'text' => 'Something went wrong with "'.(Automations::ACTIONS[$action['type'] ?? '']['label'] ?? 'an action').'".'];
                        }
                    }
                });
            }

            AutomationRun::create([
                'workspace_id' => $workspace->id,
                'automation_id' => $automation->id,
                'event' => $this->event,
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'subject_label' => $subject ? Automations::label($subject, $this->data) : null,
                'status' => ! $subject ? 'skipped' : (collect($log)->every('ok') ? 'succeeded' : 'failed'),
                'log' => $log,
            ]);

            $automation->forceFill(['run_count' => $automation->run_count + 1, 'last_run_at' => now()])->saveQuietly();
        });
    }
}
