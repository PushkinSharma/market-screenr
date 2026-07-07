<?php

namespace App\Console\Commands;

use App\Services\ScreenerEngine;
use App\Models\ScreenerPreset;
use Illuminate\Console\Command;

class ComputeScoresCommand extends Command
{
    protected $signature = 'screener:compute-scores {--preset= : Preset ID}';

    protected $description = 'Compute weighted MTF screener scores for all companies';

    public function handle(ScreenerEngine $engine): int
    {
        $presetId = $this->option('preset');

        if ($presetId) {
            $preset = ScreenerPreset::query()->findOrFail($presetId);
            $count = $engine->computeRanksAndScores($preset);
        } else {
            $presets = ScreenerPreset::query()->get();
            $count = 0;
            foreach ($presets as $preset) {
                $count += $engine->computeRanksAndScores($preset);
            }
        }

        $this->info("Computed scores for {$count} companies.");

        return self::SUCCESS;
    }
}
