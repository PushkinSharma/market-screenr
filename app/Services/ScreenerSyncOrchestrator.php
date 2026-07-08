<?php

namespace App\Services;

use App\Jobs\ComputeScreenerScoresJob;
use App\Jobs\SyncIndiaFundamentalsJob;
use App\Jobs\SyncIndiaUniverseJob;
use App\Jobs\SyncMtfGroupListJob;
use App\Jobs\SyncUsFundamentalsJob;
use App\Jobs\SyncUsUniverseJob;
use App\Models\ScreenerPreset;
use App\Services\MarketData\BusinessQuantClient;
use App\Services\MarketData\NseClient;
use Illuminate\Support\Facades\Log;

class ScreenerSyncOrchestrator
{
    /**
     * Run the full bootstrap pipeline inline (CLI or queue worker).
     *
     * @return array{india_fundamentals: int, scores: int}
     */
    public function runBootstrap(
        int $limit,
        bool $refreshMtf = false,
        bool $includeUs = true,
    ): array {
        $recorder = new SyncRunRecorder('manual_bootstrap', 'ALL');

        try {
            (new SyncIndiaUniverseJob)->handle(app(NseClient::class));

            if ($includeUs) {
                (new SyncUsUniverseJob)->handle();
            }

            if ($refreshMtf) {
                (new SyncMtfGroupListJob)->handle(app(NseClient::class));
            } else {
                Log::info('Bootstrap sync: skipping BSE MTF refresh (use refresh_mtf to enable).');
            }

            (new SyncIndiaFundamentalsJob(limit: $limit))->handle(app(FundamentalsSyncService::class));

            if ($includeUs) {
                (new SyncUsFundamentalsJob)->handle(
                    app(BusinessQuantClient::class),
                    app(FundamentalsSyncService::class),
                );
            }

            $engine = app(ScreenerEngine::class);
            $presets = ScreenerPreset::query()->get();
            $scores = 0;

            if ($presets->isEmpty()) {
                ScreenerPreset::defaultPreset();
                $presets = ScreenerPreset::query()->get();
            }

            foreach ($presets as $preset) {
                $scores += $engine->computeRanksAndScores($preset);
            }

            $message = "Bootstrap finished: enriched up to {$limit} India stocks";
            if ($includeUs) {
                $message .= ', synced US fundamentals';
            }
            $message .= ", scored {$scores} preset rows.";

            $recorder->finish('success', $limit, $scores, $message, [
                'limit' => $limit,
                'refresh_mtf' => $refreshMtf,
                'include_us' => $includeUs,
            ]);

            return [
                'india_fundamentals' => $limit,
                'scores' => $scores,
            ];
        } catch (\Throwable $e) {
            $recorder->finish('failed', $limit, 0, $e->getMessage(), [
                'limit' => $limit,
                'refresh_mtf' => $refreshMtf,
                'include_us' => $includeUs,
            ]);

            throw $e;
        }
    }

    public function dispatchAllJobs(?int $indiaLimit = null): void
    {
        SyncIndiaUniverseJob::dispatch();
        SyncUsUniverseJob::dispatch();
        SyncMtfGroupListJob::dispatch();
        SyncIndiaFundamentalsJob::dispatch(limit: $indiaLimit);
        SyncUsFundamentalsJob::dispatch();
        ComputeScreenerScoresJob::dispatch();
    }
}
