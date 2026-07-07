<?php

use App\Jobs\ComputeScreenerScoresJob;
use App\Jobs\SyncIndiaFundamentalsJob;
use App\Jobs\SyncIndiaUniverseJob;
use App\Jobs\SyncMtfGroupListJob;
use App\Jobs\SyncUsFundamentalsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncIndiaUniverseJob)
    ->dailyAt(config('market_screenr.sync.india_universe_at'))
    ->timezone('Asia/Kolkata');

Schedule::job(new SyncMtfGroupListJob)
    ->monthlyOn(config('market_screenr.sync.mtf_list_day'), '08:00')
    ->timezone('Asia/Kolkata');

Schedule::job(new SyncIndiaFundamentalsJob)
    ->dailyAt(config('market_screenr.sync.india_fundamentals_at'))
    ->timezone('Asia/Kolkata');

Schedule::job(new SyncUsFundamentalsJob)
    ->dailyAt(config('market_screenr.sync.us_fundamentals_at'))
    ->timezone('America/New_York');

Schedule::job(new ComputeScreenerScoresJob)
    ->dailyAt(config('market_screenr.sync.scores_at'))
    ->timezone('Asia/Kolkata');
