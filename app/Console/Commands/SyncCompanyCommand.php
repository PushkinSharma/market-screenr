<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\FundamentalsSyncService;
use App\Services\ScreenerEngine;
use App\Services\ScreenerSyncOrchestrator;
use Illuminate\Console\Command;

class SyncCompanyCommand extends Command
{
    protected $signature = 'screener:sync
        {symbol? : NSE/NASDAQ symbol}
        {--market=IN : IN or US}
        {--sync : Run inline instead of dispatching to queue}
        {--limit= : Number of Indian companies to enrich in this run}
        {--refresh-mtf : Refresh the BSE MTF list inline (slow/fragile on Cloud)}';

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
            $limit = (int) ($this->option('limit') ?: config('market_screenr.sync.bootstrap_company_limit'));

            $this->info('Running full sync inline...');
            app(ScreenerSyncOrchestrator::class)->runBootstrap(
                $limit,
                (bool) $this->option('refresh-mtf'),
                includeUs: true,
            );
            $this->call('screener:status');

            return self::SUCCESS;
        }

        $this->info('Dispatching batch sync jobs to queue...');
        app(ScreenerSyncOrchestrator::class)->dispatchAllJobs();

        $this->warn('Jobs queued. Ensure a queue worker is running on Laravel Cloud.');
        $this->line('Or run inline: php artisan screener:sync --sync');

        return self::SUCCESS;
    }
}
