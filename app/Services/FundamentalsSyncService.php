<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Services\MarketData\NseQuoteFundamentalsService;
use App\Services\MarketData\ScreenerIngestService;
use App\Services\MarketData\YahooFinanceClient;

class FundamentalsSyncService
{
    public function __construct(
        private ScreenerIngestService $screener,
        private NseQuoteFundamentalsService $nseQuote,
        private YahooFinanceClient $yahoo,
        private MetricCalculator $calculator,
    ) {}

    public function syncCompany(Company $company): CompanyMetric
    {
        $screenerData = [];

        if ($company->market === 'IN') {
            // NSE first, then Yahoo fallback for valuation when Cloud blocks NSE
            $this->nseQuote->applyToCompany($company);
            $this->applyYahooValuationIfMissing($company);

            $screenerData = $this->screener->fetchCompanyData($company);
            if (! empty($screenerData)) {
                $this->applyScreenerData($company, $screenerData);
            }
        } else {
            $this->applyYahooValuationIfMissing($company);
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

        $companyAttrs = array_filter([
            'sector' => is_string($data['sector'] ?? null) ? $data['sector'] : null,
            'industry' => is_string($data['industry'] ?? null) ? $data['industry'] : null,
        ]);
        if ($companyAttrs !== []) {
            $company->update($companyAttrs);
        }

        $currentPrice = $this->parseFloat($keyMetrics['current_price'] ?? null);
        $bookValue = $this->parseFloat($keyMetrics['book_value'] ?? null);
        $pb = $this->parseFloat($keyMetrics['pb'] ?? null);
        if ($pb === null && $currentPrice !== null && $bookValue !== null && $bookValue > 0) {
            $pb = round($currentPrice / $bookValue, 2);
        }

        $attrs = [
            'current_pe' => $this->parseFloat($keyMetrics['pe'] ?? null),
            'current_pb' => $pb,
            'current_price' => $currentPrice,
            'roce' => $this->parseFloat($keyMetrics['roce'] ?? $ratios['roce'] ?? null),
            'roe' => $this->parseFloat($keyMetrics['roe'] ?? $ratios['roe'] ?? null),
            'debt_to_equity' => $this->parseFloat($keyMetrics['debt_to_equity'] ?? null),
            'market_cap' => $this->parseFloat($keyMetrics['market_cap'] ?? null),
            'promoter_holding' => $this->parseFloat($shareholding['promoter_pct'] ?? null),
            'fii_holding' => $this->parseFloat($shareholding['fii_pct'] ?? null),
            'dii_holding' => $this->parseFloat($shareholding['dii_pct'] ?? null),
            'fii_holding_change_qoq' => $this->parseFloat($shareholding['fii_change_qoq'] ?? null),
            'dii_holding_change_qoq' => $this->parseFloat($shareholding['dii_change_qoq'] ?? null),
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

                // SQLite may store dates as "Y-m-d H:i:s"; match with whereDate then upsert.
                $periodDate = \Carbon\Carbon::parse($point['date'])->startOfDay();
                $periodType = $point['type'] ?? 'annual';

                $existing = MetricHistory::query()
                    ->where('company_id', $company->id)
                    ->where('metric_key', $key)
                    ->where('period_type', $periodType)
                    ->whereDate('period_date', $periodDate->toDateString())
                    ->first();

                if ($existing) {
                    $existing->update([
                        'value' => $point['value'],
                        'source' => 'screener.in',
                    ]);
                } else {
                    MetricHistory::query()->create([
                        'company_id' => $company->id,
                        'metric_key' => $key,
                        'period_date' => $periodDate->toDateString(),
                        'period_type' => $periodType,
                        'value' => $point['value'],
                        'source' => 'screener.in',
                    ]);
                }
            }
        }
    }

    private function applyYahooValuationIfMissing(Company $company): void
    {
        $existing = CompanyMetric::query()
            ->where('company_id', $company->id)
            ->where('as_of_date', today())
            ->first();

        if ($existing?->current_pe !== null) {
            return;
        }

        $snapshot = $this->yahoo->fetchValuationSnapshot($company);

        if (empty($snapshot)) {
            return;
        }

        CompanyMetric::query()->updateOrCreate(
            ['company_id' => $company->id, 'as_of_date' => today()],
            $snapshot,
        );
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
