<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\MarketData\NseClient;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncIndiaUniverseJob implements ShouldQueue
{
    use Queueable;

    public function handle(NseClient $nse): void
    {
        $recorder = new SyncRunRecorder('nse_universe', 'IN');
        $equities = $nse->fetchEquityList();
        $usedFallback = false;

        if (empty($equities)) {
            $equities = collect(config('market_screenr.fallback_nse_symbols', []))
                ->map(fn ($row) => [
                    'symbol' => $row['symbol'],
                    'companyName' => $row['name'],
                ])
                ->all();
            $usedFallback = true;
            Log::warning('SyncIndiaUniverseJob: NSE API returned empty, using fallback list');
        }

        $count = 0;
        foreach ($equities as $row) {
            $symbol = $row['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            $attrs = [
                'market' => 'IN',
                'name' => $row['companyName'] ?? $row['name'] ?? $symbol,
                'sector' => $row['industry'] ?? null,
                'industry' => $row['industry'] ?? null,
                'yahoo_symbol' => "{$symbol}.NS",
                'is_active' => true,
            ];

            if ($usedFallback) {
                $attrs['is_mtf_eligible'] = true;
                $attrs['mtf_group'] = 'Group I (fallback)';
            }

            Company::query()->updateOrCreate(
                ['symbol' => $symbol, 'exchange' => 'NSE'],
                $attrs,
            );
            $count++;
        }

        $message = $usedFallback
            ? "Used fallback list ({$count} symbols). NSE API may be blocked from Cloud."
            : "Synced {$count} symbols from NSE API.";

        $recorder->finish(
            $usedFallback ? 'partial' : 'success',
            count($equities),
            $count,
            $message,
            ['fallback' => $usedFallback],
        );

        Log::info("SyncIndiaUniverseJob: {$message}");
    }
}
