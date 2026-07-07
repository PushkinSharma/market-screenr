<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ScreenerPreset;
use App\Services\MarketData\NseClient;
use App\Services\ScreenerEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMtfGroupListJob implements ShouldQueue
{
    use Queueable;

    public function handle(NseClient $nse): void
    {
        $symbols = $nse->fetchMtfEligibleSymbols();

        // Reset all, then mark eligible — conservative approach
        Company::query()
            ->where('market', 'IN')
            ->update(['is_mtf_eligible' => false, 'mtf_group' => null]);

        $count = 0;
        foreach ($symbols as $symbol) {
            $updated = Company::query()
                ->where('symbol', $symbol)
                ->where('market', 'IN')
                ->update([
                    'is_mtf_eligible' => true,
                    'mtf_group' => 'Group I',
                    'mtf_effective_from' => today(),
                ]);

            $count += $updated;
        }

        Log::info("SyncMtfGroupListJob: marked {$count} MTF-eligible stocks");
    }
}
