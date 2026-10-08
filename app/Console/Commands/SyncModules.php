<?php

namespace App\Console\Commands;

use App\Registries\ModuleRegistry;
use Illuminate\Console\Command;

class SyncModules extends Command
{
    protected $signature = 'zonseo:sync-modules';

    protected $description = 'Mark catalogue modules as installed when a code module in Modules/ provides them';

    public function handle(ModuleRegistry $registry): int
    {
        $changed = $registry->sync();
        $installed = $registry->installedKeys();

        $this->info(count($installed).' module keys have code behind them: '.implode(', ', $installed));
        $this->info($changed.' catalogue rows updated.');

        return self::SUCCESS;
    }
}
