<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Models\PriceHistory;
use App\Services\MarketData\YahooFinanceClient;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MetricCalculator
{
    public function __construct(
        private YahooFinanceClient $yahoo,
    ) {}

    public function computeAndStore(Company $company): CompanyMetric
    {
        $this->yahoo->syncPriceHistory($company, 10);

        $prices = PriceHistory::query()
            ->where('company_id', $company->id)
            ->orderBy('trade_date')
            ->get();

        $drawdown = $this->computeDrawdownMetrics($prices);
        $momentum = $this->computeMomentumMetrics($prices);
        $valuationPercentile = $this->computeValuationPercentile($company);

        $metric = CompanyMetric::query()->updateOrCreate(
            ['company_id' => $company->id, 'as_of_date' => today()],
            array_merge($drawdown, $momentum, [
                'valuation_percentile' => $valuationPercentile,
            ]),
        );

        $this->storePeHistory($company, $metric);

        return $metric;
    }

    /**
     * @param  Collection<int, PriceHistory>  $prices
     * @return array<string, float|null>
     */
    private function computeDrawdownMetrics(Collection $prices): array
    {
        if ($prices->isEmpty()) {
            return [];
        }

        $latest = $prices->last();
        $currentPrice = (float) $latest->close;

        $week52 = $prices->where('trade_date', '>=', now()->subYear());
        $week52High = $week52->max('high') ?? $currentPrice;
        $week52Low = $week52->min('low') ?? $currentPrice;

        $ath = (float) $prices->max('high');
        $atl = (float) $prices->min('low');

        $pctBelowAth = $ath > 0 ? (($ath - $currentPrice) / $ath) * 100 : null;
        $pctAboveAtl = $atl > 0 ? (($currentPrice - $atl) / $atl) * 100 : null;

        // Where current price sits in 10y range (0 = at ATL, 100 = at ATH)
        $range = $ath - $atl;
        $drawdownPercentile = $range > 0
            ? (($currentPrice - $atl) / $range) * 100
            : null;

        return [
            'current_price' => $currentPrice,
            'week_52_high' => $week52High,
            'week_52_low' => $week52Low,
            'ath_price' => $ath,
            'atl_price' => $atl,
            'pct_below_ath' => $pctBelowAth,
            'pct_above_atl' => $pctAboveAtl,
            'drawdown_percentile_10y' => $drawdownPercentile,
        ];
    }

    /**
     * @param  Collection<int, PriceHistory>  $prices
     * @return array<string, float|null>
     */
    private function computeMomentumMetrics(Collection $prices): array
    {
        if ($prices->count() < 200) {
            return [];
        }

        $closes = $prices->pluck('close')->map(fn ($v) => (float) $v);
        $volumes = $prices->pluck('volume')->map(fn ($v) => (int) ($v ?? 0));

        $latest = $closes->last();
        $dma50 = $closes->slice(-50)->avg();
        $dma100 = $closes->slice(-100)->avg();
        $dma200 = $closes->slice(-200)->avg();

        $distanceFrom200 = $dma200 > 0
            ? (($latest - $dma200) / $dma200) * 100
            : null;

        $avgVol20 = $volumes->slice(-20)->avg() ?: 1;
        $latestVol = $volumes->last();
        $volumeSpike = $latestVol / $avgVol20;

        // 52w relative strength: stock return vs its own 52w low
        $yearAgo = $closes->slice(-252)->first();
        $rs52w = $yearAgo > 0 ? (($latest - $yearAgo) / $yearAgo) * 100 : null;

        return [
            'dma_50' => round($dma50, 4),
            'dma_100' => round($dma100, 4),
            'dma_200' => round($dma200, 4),
            'distance_from_dma_200_pct' => $distanceFrom200,
            'rs_52w' => $rs52w,
            'volume_spike_ratio' => round($volumeSpike, 2),
        ];
    }

    private function computeValuationPercentile(Company $company): ?float
    {
        $peHistory = MetricHistory::query()
            ->where('company_id', $company->id)
            ->where('metric_key', 'pe')
            ->orderBy('period_date')
            ->pluck('value')
            ->filter(fn ($v) => $v > 0);

        if ($peHistory->isEmpty()) {
            return null;
        }

        $currentPe = $company->latestMetric?->current_pe;
        if ($currentPe === null || $currentPe <= 0) {
            return null;
        }

        $below = $peHistory->filter(fn ($v) => $v >= $currentPe)->count();

        return ($below / $peHistory->count()) * 100;
    }

    private function storePeHistory(Company $company, CompanyMetric $metric): void
    {
        if ($metric->current_pe === null) {
            return;
        }

        MetricHistory::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'metric_key' => 'pe',
                'period_date' => today(),
                'period_type' => 'daily',
            ],
            ['value' => $metric->current_pe, 'source' => 'computed'],
        );
    }

    /**
     * Compute CAGR from a series of values.
     *
     * @param  array<int, float>  $values  oldest to newest
     */
    public static function cagr(array $values, int $years): ?float
    {
        if (count($values) < 2 || $years <= 0) {
            return null;
        }

        $start = reset($values);
        $end = end($values);

        if ($start <= 0 || $end <= 0) {
            return null;
        }

        return (pow($end / $start, 1 / $years) - 1) * 100;
    }
}
