<?php

namespace App\Console\Commands;

use App\Jobs\ComputeScreenerScoresJob;
use App\Jobs\SyncIndiaFundamentalsJob;
use App\Jobs\SyncIndiaUniverseJob;
use App\Jobs\SyncMtfGroupListJob;
use App\Jobs\SyncUsFundamentalsJob;
use App\Models\Company;
use App\Services\FundamentalsSyncService;
use Illuminate\Console\Command;

class SyncCompanyCommand extends Command
{
    protected $signature = 'screener:sync {symbol? : NSE/NASDAQ symbol} {--market=IN : IN or US}';

    protected $description = 'Sync fundamentals for a single company or batch';

    public function handle(FundamentalsSyncService $sync): int
    {
        $symbol = $this->argument('symbol');

        if ($symbol) {
            $company = Company::query()
                ->where('symbol', strtoupper($symbol))
                ->where('market', strtoupper($this->option('market')))
                ->firstOrFail();

            $this->info("Syncing {$company->symbol}...");
            $sync->syncCompany($company);
            $this->info('Done.');

            return self::SUCCESS;
        }

        $this->info('Dispatching batch sync jobs...');
        SyncIndiaUniverseJob::dispatch();
        SyncMtfGroupListJob::dispatch();
        SyncIndiaFundamentalsJob::dispatch();
        SyncUsFundamentalsJob::dispatch();
        ComputeScreenerScoresJob::dispatch();

        return self::SUCCESS;
    }
}
