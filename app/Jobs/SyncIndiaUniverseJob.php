<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\MarketData\NseArchiveClient;
use App\Services\MarketData\NseClient;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncIndiaUniverseJob implements ShouldQueue
{
    use Queueable;

    public function handle(NseClient $nse, NseArchiveClient $archives): void
    {
        $recorder = new SyncRunRecorder('nse_universe', 'IN');
        $archive = $archives->latestEquityBhavcopy();
        $equities = $archive['rows'];
        $source = $archive['date'] ? 'nse_archive' : null;
        $usedFallback = false;

        if (empty($equities)) {
            $equities = $nse->fetchEquityList();
            $source = empty($equities) ? null : 'nse_live';
        }

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
                $attrs['mtf_group'] = 'Group I';
            }

            Company::query()->updateOrCreate(
                ['symbol' => $symbol, 'exchange' => 'NSE'],
                $attrs,
            );
            $count++;
        }

        $message = match ($source) {
            'nse_archive' => "Synced {$count} symbols from NSE archive bhavcopy ({$archive['date']}).",
            'nse_live' => "Synced {$count} symbols from NSE live API.",
            default => "Used fallback list ({$count} symbols). NSE archive/live API may be unavailable from Cloud.",
        };

        $recorder->finish(
            $usedFallback ? 'partial' : 'success',
            count($equities),
            $count,
            $message,
            ['fallback' => $usedFallback, 'source' => $source, 'archive_date' => $archive['date']],
        );

        Log::info("SyncIndiaUniverseJob: {$message}");
    }
}
