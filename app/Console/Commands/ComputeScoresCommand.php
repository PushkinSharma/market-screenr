<?php

namespace App\Console\Commands;

use App\Models\ScreenerPreset;
use App\Services\ScreenerEngine;
use App\Services\SyncStatusService;
use Illuminate\Console\Command;

class ComputeScoresCommand extends Command
{
    protected $signature = 'screener:compute-scores {--preset= : Preset ID}';

    protected $description = 'Compute weighted MTF screener scores for all companies';

    public function handle(ScreenerEngine $engine, SyncStatusService $status): int
    {
        $presetId = $this->option('preset');

        if ($presetId) {
            $preset = ScreenerPreset::query()->findOrFail($presetId);
            $count = $engine->computeRanksAndScores($preset);
        } else {
            $presets = ScreenerPreset::query()->get();
            if ($presets->isEmpty()) {
                ScreenerPreset::defaultPreset();
                $presets = ScreenerPreset::query()->get();
            }
            $count = 0;
            foreach ($presets as $preset) {
                $count += $engine->computeRanksAndScores($preset);
            }
        }

        $this->info("Computed scores for {$count} companies.");

        if ($count === 0) {
            $this->newLine();
            $this->warn('Score pipeline diagnostics:');
            $diag = $status->scoreDiagnostics(true, 'IN');
            $this->table(
                ['Check', 'Count'],
                collect($diag)->map(fn ($v, $k) => [$k, is_string($v) ? $v : (string) $v])->values()->all(),
            );

            foreach ($status->diagnostics() as $issue) {
                $this->line(" • {$issue}");
            }
        }

        return self::SUCCESS;
    }
}
