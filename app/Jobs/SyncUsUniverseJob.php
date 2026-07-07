<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncUsUniverseJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $recorder = new SyncRunRecorder('us_universe', 'US');
        $symbols = config('market_screenr.fallback_us_symbols', []);
        $count = 0;

        foreach ($symbols as $row) {
            Company::query()->updateOrCreate(
                ['symbol' => $row['symbol'], 'exchange' => 'NASDAQ'],
                [
                    'market' => 'US',
                    'name' => $row['name'],
                    'sector' => $row['sector'] ?? null,
                    'yahoo_symbol' => $row['symbol'],
                    'is_active' => true,
                    'is_mtf_eligible' => false,
                ],
            );
            $count++;
        }

        $recorder->finish('success', count($symbols), $count, "Seeded {$count} US symbols.");
        Log::info("SyncUsUniverseJob: seeded {$count} US symbols");
    }
}
