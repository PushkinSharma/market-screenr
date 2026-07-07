<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Services\MarketData\NseQuoteFundamentalsService;
use App\Services\MarketData\ScreenerIngestService;

class FundamentalsSyncService
{
    public function __construct(
        private ScreenerIngestService $screener,
        private NseQuoteFundamentalsService $nseQuote,
        private MetricCalculator $calculator,
    ) {}

    public function syncCompany(Company $company): CompanyMetric
    {
        $screenerData = [];

        if ($company->market === 'IN') {
            // Always try NSE quote (works without Python — important on Laravel Cloud)
            $this->nseQuote->applyToCompany($company);

            $screenerData = $this->screener->fetchCompanyData($company);
            if (! empty($screenerData)) {
                $this->applyScreenerData($company, $screenerData);
            }
        }

        $metric = $this->calculator->computeAndStore($company);

        $company->update(['fundamentals_synced_at' => now()]);

        return $metric->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyScreenerData(Company $company, array $data): void
    {
        $keyMetrics = $data['key_metrics'] ?? [];
        $ratios = $data['ratios'] ?? [];
        $shareholding = $data['shareholding'] ?? [];

        $attrs = [
            'current_pe' => $this->parseFloat($keyMetrics['pe'] ?? null),
            'current_pb' => $this->parseFloat($keyMetrics['pb'] ?? null),
            'roce' => $this->parseFloat($keyMetrics['roce'] ?? $ratios['roce'] ?? null),
            'roe' => $this->parseFloat($keyMetrics['roe'] ?? $ratios['roe'] ?? null),
            'debt_to_equity' => $this->parseFloat($keyMetrics['debt_to_equity'] ?? null),
            'market_cap' => $this->parseFloat($keyMetrics['market_cap'] ?? null),
            'promoter_holding' => $this->parseFloat($shareholding['promoter_pct'] ?? null),
            'fii_holding' => $this->parseFloat($shareholding['fii_pct'] ?? null),
            'dii_holding' => $this->parseFloat($shareholding['dii_pct'] ?? null),
            'revenue_cagr_3y' => $this->parseFloat($data['revenue_cagr_3y'] ?? null),
            'profit_cagr_3y' => $this->parseFloat($data['profit_cagr_3y'] ?? null),
            'pe_avg_5y' => $this->parseFloat($data['pe_avg_5y'] ?? null),
            'pe_avg_10y' => $this->parseFloat($data['pe_avg_10y'] ?? null),
        ];

        CompanyMetric::query()->updateOrCreate(
            ['company_id' => $company->id, 'as_of_date' => today()],
            array_filter($attrs, fn ($v) => $v !== null),
        );

        $this->storeHistoricalMetrics($company, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeHistoricalMetrics(Company $company, array $data): void
    {
        foreach (['pe', 'roce', 'roe', 'ev_ebitda'] as $key) {
            $series = $data['history'][$key] ?? [];
            foreach ($series as $point) {
                if (! isset($point['date'], $point['value'])) {
                    continue;
                }

                MetricHistory::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'metric_key' => $key,
                        'period_date' => $point['date'],
                        'period_type' => $point['type'] ?? 'annual',
                    ],
                    ['value' => $point['value'], 'source' => 'screener.in'],
                );
            }
        }
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        $clean = str_replace([',', '%'], '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }
}
