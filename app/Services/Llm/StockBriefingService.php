<?php

namespace App\Services\Llm;

use App\Models\Company;
use App\Models\MetricHistory;
use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;

class StockBriefingService
{
    public function __construct(private GeminiClient $gemini) {}

    public function enabled(): bool
    {
        return $this->gemini->enabled();
    }

    /**
     * @return array{text: string, model: string, grounded: bool, truncated: bool, finish_reason: ?string}
     */
    public function brief(Company $company, ?string $preferences = null): array
    {
        return $this->gemini->analyzeStock(
            $this->buildContext($company),
            $preferences ?: $this->defaultPreferences(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContext(Company $company): array
    {
        $company->loadMissing('latestMetric');
        $m = $company->latestMetric;

        $preset = ScreenerPreset::defaultPreset();
        $score = ScreenerScore::query()
            ->where('company_id', $company->id)
            ->where('screener_preset_id', $preset->id)
            ->latest('computed_at')
            ->first();

        $roceHistory = MetricHistory::query()
            ->where('company_id', $company->id)
            ->where('metric_key', 'roce')
            ->orderBy('period_date')
            ->get(['period_date', 'value'])
            ->map(fn ($row) => [
                'date' => $row->period_date?->format('Y-m-d'),
                'value' => (float) $row->value,
            ])
            ->all();

        return [
            'symbol' => $company->symbol,
            'name' => $company->name,
            'sector' => $company->sector,
            'industry' => $company->industry,
            'market' => $company->market,
            'score' => $score ? [
                'final' => (float) $score->final_score,
                'rank' => $score->rank,
                'business_quality' => (float) $score->business_quality_score,
                'valuation' => (float) $score->valuation_score,
                'correction' => (float) $score->correction_score,
                'momentum' => (float) $score->momentum_score,
                'results_quality' => (float) $score->results_quality_score,
                'sector_tailwind' => (float) $score->sector_tailwind_score,
            ] : null,
            'metrics' => $m ? [
                'price' => $m->current_price,
                'pe' => $m->current_pe,
                'pb' => $m->current_pb,
                'valuation_percentile' => $m->valuation_percentile,
                'valuation_note' => 'Percentile is cross-sectional vs dashboard universe today: 0=cheapest PE, 100=most expensive PE.',
                'roce' => $m->roce,
                'roe' => $m->roe,
                'debt_to_equity' => $m->debt_to_equity,
                'revenue_cagr_3y' => $m->revenue_cagr_3y,
                'profit_cagr_3y' => $m->profit_cagr_3y,
                'pct_below_ath' => $m->pct_below_ath,
                'distance_from_dma_200_pct' => $m->distance_from_dma_200_pct,
                'promoter_holding' => $m->promoter_holding,
                'fii_holding' => $m->fii_holding,
                'dii_holding' => $m->dii_holding,
                'fii_holding_change_qoq' => $m->fii_holding_change_qoq,
                'dii_holding_change_qoq' => $m->dii_holding_change_qoq,
                'market_cap_cr' => $m->market_cap,
            ] : null,
            'roce_history' => $roceHistory,
            'links' => [
                'screener' => "https://www.screener.in/company/{$company->symbol}/consolidated/",
                'yahoo' => 'https://finance.yahoo.com/quote/'.($company->yahoo_symbol ?: $company->symbol.'.NS'),
            ],
        ];
    }

    private function defaultPreferences(): string
    {
        return <<<'TXT'
I am looking for Indian stocks to enter for a multi-year hold.
Prefer improving or high ROCE, reasonable valuation vs peers, and avoid obvious value traps.
Tell me if this looks like a good entry, wait, or avoid — and why.
Search for the latest quarterly results, management commentary, and material news.
TXT;
    }
}
