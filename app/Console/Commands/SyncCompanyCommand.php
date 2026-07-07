<?php

namespace App\Console\Commands;

use App\Jobs\ComputeScreenerScoresJob;
use App\Jobs\SyncIndiaFundamentalsJob;
use App\Jobs\SyncIndiaUniverseJob;
use App\Jobs\SyncMtfGroupListJob;
use App\Jobs\SyncUsFundamentalsJob;
use App\Jobs\SyncUsUniverseJob;
use App\Models\Company;
use App\Services\FundamentalsSyncService;
use App\Services\ScreenerEngine;
use App\Services\SyncStatusService;
use Illuminate\Console\Command;

class SyncCompanyCommand extends Command
{
    protected $signature = 'screener:sync {symbol? : NSE/NASDAQ symbol} {--market=IN : IN or US} {--sync : Run inline instead of dispatching to queue}';

    protected $description = 'Sync fundamentals for a single company or batch';

    public function handle(FundamentalsSyncService $sync, ScreenerEngine $engine): int
    {
        $symbol = $this->argument('symbol');

        if ($symbol) {
            $company = Company::query()
                ->where('symbol', strtoupper($symbol))
                ->where('market', strtoupper($this->option('market')))
                ->firstOrFail();

            $this->info("Syncing {$company->symbol}...");
            $sync->syncCompany($company);
            $count = $engine->computeRanksAndScores();
            $this->info("Done. Scored {$count} companies.");

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->info('Running full sync inline...');
            (new SyncIndiaUniverseJob)->handle(app(\App\Services\MarketData\NseClient::class));
            (new SyncUsUniverseJob)->handle();
            (new SyncMtfGroupListJob)->handle(app(\App\Services\MarketData\NseClient::class));
            (new SyncIndiaFundamentalsJob)->handle($sync);
            (new SyncUsFundamentalsJob)->handle(app(\App\Services\MarketData\BusinessQuantClient::class), app(\App\Services\MetricCalculator::class));
            (new ComputeScreenerScoresJob)->handle($engine);
            $this->call('screener:status');

            return self::SUCCESS;
        }

        $this->info('Dispatching batch sync jobs to queue...');
        SyncIndiaUniverseJob::dispatch();
        SyncUsUniverseJob::dispatch();
        SyncMtfGroupListJob::dispatch();
        SyncIndiaFundamentalsJob::dispatch();
        SyncUsFundamentalsJob::dispatch();
        ComputeScreenerScoresJob::dispatch();

        $this->warn('Jobs queued. Ensure a queue worker is running on Laravel Cloud.');
        $this->line('Or run inline: php artisan screener:sync --sync');

        return self::SUCCESS;
    }
}
