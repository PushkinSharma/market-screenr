<?php

namespace App\Services;

use App\Enums\ScoreComponent;
use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;
use Illuminate\Support\Collection;

class ScreenerEngine
{
    /**
     * Compute percentile ranks for all active companies, then score against preset.
     */
    public function computeRanksAndScores(?ScreenerPreset $preset = null): int
    {
        $preset ??= ScreenerPreset::defaultPreset();
        $today = today();

        $metrics = CompanyMetric::query()
            ->with('company')
            ->where('as_of_date', $today)
            ->whereHas('company', function ($q) use ($preset) {
                $q->where('is_active', true);
                if ($preset->market !== 'ALL') {
                    $q->where('market', $preset->market);
                }
                if ($preset->mtf_only) {
                    $q->where('is_mtf_eligible', true);
                }
            })
            ->get();

        if ($metrics->isEmpty()) {
            return 0;
        }

        $this->computeComponentRanks($metrics);

        $weights = $preset->normalizedWeights();
        $scores = [];

        foreach ($metrics as $metric) {
            $componentScores = $this->componentScores($metric);
            $finalScore = 0;

            foreach (ScoreComponent::cases() as $component) {
                $key = $component->value;
                $weight = $weights[$key] ?? 0;
                $finalScore += ($componentScores[$key] ?? 0) * ($weight / 100);
            }

            $scores[] = [
                'metric' => $metric,
                'final_score' => round($finalScore, 2),
                'components' => $componentScores,
            ];
        }

        usort($scores, fn ($a, $b) => $b['final_score'] <=> $a['final_score']);

        foreach ($scores as $rank => $entry) {
            /** @var CompanyMetric $metric */
            $metric = $entry['metric'];

            ScreenerScore::query()->updateOrCreate(
                [
                    'screener_preset_id' => $preset->id,
                    'company_id' => $metric->company_id,
                    'computed_at' => $today,
                ],
                [
                    'final_score' => $entry['final_score'],
                    'business_quality_score' => $entry['components']['business_quality'] ?? null,
                    'sector_tailwind_score' => $entry['components']['sector_tailwind'] ?? null,
                    'valuation_score' => $entry['components']['valuation'] ?? null,
                    'correction_score' => $entry['components']['correction'] ?? null,
                    'momentum_score' => $entry['components']['momentum'] ?? null,
                    'results_quality_score' => $entry['components']['results_quality'] ?? null,
                    'rank' => $rank + 1,
                ],
            );
        }

        return count($scores);
    }

    /**
     * @param  Collection<int, CompanyMetric>  $metrics
     */
    private function computeComponentRanks(Collection $metrics): void
    {
        foreach (ScoreComponent::cases() as $component) {
            $column = 'rank_'.$component->value;
            $underlyingMetrics = $component->metrics();

            $componentValues = $metrics->map(function (CompanyMetric $m) use ($underlyingMetrics) {
                $values = [];
                foreach ($underlyingMetrics as $field => $higherIsBetter) {
                    $val = $m->{$field};
                    if ($val !== null) {
                        $values[] = $higherIsBetter ? (float) $val : -(float) $val;
                    }
                }

                return count($values) > 0 ? array_sum($values) / count($values) : null;
            });

            $ranked = $this->percentileRank($componentValues);

            foreach ($metrics as $i => $metric) {
                $metric->{$column} = $ranked[$i] ?? null;
                $metric->save();
            }
        }
    }

    /**
     * @return array<string, float>
     */
    private function componentScores(CompanyMetric $metric): array
    {
        $scores = [];
        foreach (ScoreComponent::cases() as $component) {
            $column = 'rank_'.$component->value;
            $scores[$component->value] = (float) ($metric->{$column} ?? 0);
        }

        return $scores;
    }

    /**
     * Convert values to percentile ranks (0-100).
     *
     * @param  Collection<int, float|null>  $values
     * @return array<int, float|null>
     */
    private function percentileRank(Collection $values): array
    {
        $valid = $values->filter(fn ($v) => $v !== null)->values();
        $n = $valid->count();

        if ($n === 0) {
            return array_fill(0, $values->count(), null);
        }

        $sorted = $valid->sort()->values();
        $ranks = [];

        foreach ($values as $i => $val) {
            if ($val === null) {
                $ranks[$i] = null;
                continue;
            }

            $below = $sorted->filter(fn ($v) => $v < $val)->count();
            $ranks[$i] = ($below / $n) * 100;
        }

        return $ranks;
    }

    /**
     * @return Collection<int, ScreenerScore>
     */
    public function topStocks(ScreenerPreset $preset, int $limit = 50): Collection
    {
        return ScreenerScore::query()
            ->with(['company.latestMetric'])
            ->where('screener_preset_id', $preset->id)
            ->where('computed_at', today())
            ->orderByDesc('final_score')
            ->limit($limit)
            ->get();
    }
}
