<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\ScreenerScore;
use App\Models\SyncRun;
use Illuminate\Support\Collection;

class SyncStatusService
{
    /** @var array<string, array{label: string, market: string}> */
    public const SOURCES = [
        'nse_universe' => ['label' => 'NSE Universe', 'market' => 'IN'],
        'us_universe' => ['label' => 'US Universe', 'market' => 'US'],
        'bse_mtf' => ['label' => 'BSE MTF (Group I)', 'market' => 'IN'],
        'screener_in' => ['label' => 'India Fundamentals', 'market' => 'IN'],
        'businessquant' => ['label' => 'BusinessQuant (US)', 'market' => 'US'],
        'yahoo_prices' => ['label' => 'Yahoo Finance Prices', 'market' => 'ALL'],
        'scores' => ['label' => 'MTF Score Engine', 'market' => 'ALL'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function dashboardStats(): array
    {
        $latestMetricDate = CompanyMetric::query()->max('as_of_date');
        $latestScoreDate = ScreenerScore::query()->max('computed_at');

        return [
            'companies' => [
                'total' => Company::query()->where('is_active', true)->count(),
                'india' => Company::query()->where('market', 'IN')->where('is_active', true)->count(),
                'us' => Company::query()->where('market', 'US')->where('is_active', true)->count(),
                'mtf_eligible' => Company::query()->where('market', 'IN')->where('is_mtf_eligible', true)->count(),
                'with_metrics' => CompanyMetric::query()->distinct('company_id')->count('company_id'),
                'with_metrics_latest' => $latestMetricDate
                    ? CompanyMetric::query()->where('as_of_date', $latestMetricDate)->distinct('company_id')->count('company_id')
                    : 0,
            ],
            'dates' => [
                'latest_metric' => $latestMetricDate,
                'latest_score' => $latestScoreDate,
            ],
            'syncs' => $this->syncFeed(),
            'diagnostics' => $this->diagnostics(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function syncFeed(): Collection
    {
        return collect(self::SOURCES)->map(function (array $meta, string $source) {
            $run = SyncRun::latestFor($source);

            return [
                'source' => $source,
                'label' => $meta['label'],
                'market' => $meta['market'],
                'status' => $run?->status ?? 'never',
                'finished_at' => $run?->finished_at,
                'records_succeeded' => $run?->records_succeeded ?? 0,
                'records_processed' => $run?->records_processed ?? 0,
                'message' => $run?->message,
            ];
        });
    }

    /**
     * @return array<int, string>
     */
    public function diagnostics(): array
    {
        $issues = [];
        $stats = $this->dashboardStats()['companies'];

        if ($stats['total'] === 0) {
            $issues[] = 'No companies in database. Run `php artisan screener:sync` or wait for the NSE universe job. NSE may block Cloud IPs — a fallback list is used when the API fails.';
        }

        if ($stats['india'] > 0 && $stats['mtf_eligible'] === 0) {
            $issues[] = 'No MTF-eligible stocks flagged. BSE Group I sync may have failed. Turn off "MTF eligible only" to see stocks, or run `php artisan screener:sync`.';
        }

        if ($stats['with_metrics'] === 0) {
            $issues[] = 'No company metrics stored yet. Fundamentals sync has not completed — Python/screener.in is unavailable on Laravel Cloud; NSE quote + Yahoo price fallback is used instead.';
        }

        $latestMetric = CompanyMetric::query()->max('as_of_date');
        if ($latestMetric && ScreenerScore::query()->count() === 0) {
            $issues[] = "Metrics exist (as of {$latestMetric}) but no scores computed. Run `php artisan screener:compute-scores`.";
        }

        if (empty(config('market_screenr.businessquant.api_key'))) {
            $issues[] = 'BUSINESSQUANT_API_KEY is not set — US fundamentals will not sync.';
        }

        return $issues;
    }

    /**
     * @return array<string, int>
     */
    public function scoreDiagnostics(?bool $mtfOnly = true, ?string $market = 'IN'): array
    {
        $metricDate = CompanyMetric::query()->max('as_of_date');

        if (! $metricDate) {
            return [
                'metric_date' => 0,
                'total_companies' => Company::query()->where('is_active', true)->count(),
                'metrics_on_date' => 0,
                'after_market_filter' => 0,
                'after_mtf_filter' => 0,
            ];
        }

        $metricsQuery = CompanyMetric::query()->where('as_of_date', $metricDate);
        $metricsOnDate = (clone $metricsQuery)->count();

        $afterMarket = CompanyMetric::query()
            ->where('as_of_date', $metricDate)
            ->whereHas('company', function ($q) use ($market) {
                $q->where('is_active', true);
                if ($market && $market !== 'ALL') {
                    $q->where('market', $market);
                }
            })
            ->count();

        $afterMtf = CompanyMetric::query()
            ->where('as_of_date', $metricDate)
            ->whereHas('company', function ($q) use ($market, $mtfOnly) {
                $q->where('is_active', true);
                if ($market && $market !== 'ALL') {
                    $q->where('market', $market);
                }
                if ($mtfOnly) {
                    $q->where('is_mtf_eligible', true);
                }
            })
            ->count();

        return [
            'metric_date' => $metricDate,
            'total_companies' => Company::query()->where('is_active', true)->count(),
            'metrics_on_date' => $metricsOnDate,
            'after_market_filter' => $afterMarket,
            'after_mtf_filter' => $afterMtf,
        ];
    }
}
