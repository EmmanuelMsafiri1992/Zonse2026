<?php

namespace App\Console\Commands;

use App\Support\Fiscal\Fiscaliser;
use Illuminate\Console\Command;

class FiscalRetry extends Command
{
    protected $signature = 'zonseo:fiscal-retry';

    protected $description = 'Send fiscal documents the tax authority could not be reached for';

    public function handle(Fiscaliser $fiscaliser): int
    {
        $results = $fiscaliser->retryPending();
        $this->info($results['signed'].' accepted, '.$results['waiting'].' still waiting.');

        return self::SUCCESS;
    }
}
