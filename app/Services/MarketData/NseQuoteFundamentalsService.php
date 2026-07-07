<?php

namespace App\Services\MarketData;

use App\Models\Company;
use App\Models\CompanyMetric;

class NseQuoteFundamentalsService
{
    public function __construct(
        private NseClient $nse,
    ) {}

    /**
     * PHP-only fallback for Laravel Cloud (no Python/screener.in).
     *
     * @return array<string, float|string|null>
     */
    public function extractMetrics(Company $company): array
    {
        $quote = $this->nse->quoteEquity($company->symbol);

        if (empty($quote)) {
            return [];
        }

        $info = $quote['info'] ?? [];
        $metadata = $quote['metadata'] ?? [];
        $priceInfo = $quote['priceInfo'] ?? [];

        $currentPrice = $priceInfo['lastPrice'] ?? null;
        $week52High = $priceInfo['weekHighLow']['max'] ?? null;
        $week52Low = $priceInfo['weekHighLow']['min'] ?? null;

        return array_filter([
            'current_price' => is_numeric($currentPrice) ? (float) $currentPrice : null,
            'week_52_high' => is_numeric($week52High) ? (float) $week52High : null,
            'week_52_low' => is_numeric($week52Low) ? (float) $week52Low : null,
            'market_cap' => isset($info['marketCap']) ? (float) $info['marketCap'] : null,
            'current_pe' => isset($metadata['pdSymbolPe']) && is_numeric($metadata['pdSymbolPe'])
                ? (float) $metadata['pdSymbolPe']
                : null,
            'sector' => $info['industry'] ?? $info['sector'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function applyToCompany(Company $company): void
    {
        $attrs = $this->extractMetrics($company);

        if (isset($attrs['sector'])) {
            $company->update([
                'sector' => $attrs['sector'],
                'industry' => $attrs['sector'],
            ]);
            unset($attrs['sector']);
        }

        if (! empty($attrs)) {
            CompanyMetric::query()->updateOrCreate(
                ['company_id' => $company->id, 'as_of_date' => today()],
                $attrs,
            );
        }
    }
}
