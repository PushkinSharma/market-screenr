<?php

namespace App\Jobs;

use App\Models\ScreenerPreset;
use App\Services\ScreenerEngine;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComputeScreenerScoresJob implements ShouldQueue
{
    use Queueable;

    public function handle(ScreenerEngine $engine): void
    {
        $recorder = new SyncRunRecorder('scores', 'ALL');
        $presets = ScreenerPreset::query()->get();

        if ($presets->isEmpty()) {
            ScreenerPreset::defaultPreset();
            $presets = ScreenerPreset::query()->get();
        }

        $total = 0;
        foreach ($presets as $preset) {
            $count = $engine->computeRanksAndScores($preset);
            $total += $count;
            Log::info("Computed scores for preset: {$preset->name}", ['count' => $count]);
        }

        $status = $total > 0 ? 'success' : 'failed';
        $message = $total > 0
            ? "Computed scores for {$total} companies."
            : 'No companies scored — check universe sync, fundamentals sync, and MTF filter.';

        $recorder->finish($status, $presets->count(), $total, $message);
    }
}
