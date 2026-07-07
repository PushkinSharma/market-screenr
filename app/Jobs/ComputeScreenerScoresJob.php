<?php

namespace App\Jobs;

use App\Models\ScreenerPreset;
use App\Services\ScreenerEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComputeScreenerScoresJob implements ShouldQueue
{
    use Queueable;

    public function handle(ScreenerEngine $engine): void
    {
        $presets = ScreenerPreset::query()->get();

        if ($presets->isEmpty()) {
            ScreenerPreset::defaultPreset();
            $presets = ScreenerPreset::query()->get();
        }

        $total = 0;
        foreach ($presets as $preset) {
            $total += $engine->computeRanksAndScores($preset);
            Log::info("Computed scores for preset: {$preset->name}", ['count' => $total]);
        }
    }
}
