<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\MarketData\NseClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncIndiaUniverseJob implements ShouldQueue
{
    use Queueable;

    public function handle(NseClient $nse): void
    {
        $equities = $nse->fetchEquityList();
        $count = 0;

        foreach ($equities as $row) {
            $symbol = $row['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            Company::query()->updateOrCreate(
                ['symbol' => $symbol, 'exchange' => 'NSE'],
                [
                    'market' => 'IN',
                    'name' => $row['companyName'] ?? $symbol,
                    'sector' => $row['industry'] ?? null,
                    'industry' => $row['industry'] ?? null,
                    'yahoo_symbol' => "{$symbol}.NS",
                    'is_active' => true,
                ],
            );
            $count++;
        }

        Log::info("SyncIndiaUniverseJob: synced {$count} NSE symbols");
    }
}
