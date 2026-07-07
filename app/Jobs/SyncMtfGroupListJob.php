<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\MarketData\NseClient;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMtfGroupListJob implements ShouldQueue
{
    use Queueable;

    public function handle(NseClient $nse): void
    {
        $recorder = new SyncRunRecorder('bse_mtf', 'IN');
        $symbols = $nse->fetchMtfEligibleSymbols();

        if (empty($symbols)) {
            // Do NOT wipe MTF flags when BSE fetch fails — common on Cloud
            $existing = Company::query()->where('market', 'IN')->where('is_mtf_eligible', true)->count();
            $message = $existing > 0
                ? "BSE fetch failed; kept {$existing} existing MTF flags unchanged."
                : 'BSE fetch failed; no MTF flags set. Using fallback universe MTF flags if present.';

            $recorder->finish('failed', 0, 0, $message);
            Log::warning("SyncMtfGroupListJob: {$message}");

            return;
        }

        Company::query()
            ->where('market', 'IN')
            ->update(['is_mtf_eligible' => false, 'mtf_group' => null]);

        $count = 0;
        foreach ($symbols as $symbol) {
            $count += Company::query()
                ->where('symbol', $symbol)
                ->where('market', 'IN')
                ->update([
                    'is_mtf_eligible' => true,
                    'mtf_group' => 'Group I',
                    'mtf_effective_from' => today(),
                ]);
        }

        $recorder->finish('success', count($symbols), $count, "Marked {$count} stocks as MTF eligible.");
        Log::info("SyncMtfGroupListJob: marked {$count} MTF-eligible stocks");
    }
}
