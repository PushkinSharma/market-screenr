<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMetric extends Model
{
    protected $fillable = [
        'company_id',
        'as_of_date',
        'current_pe',
        'pe_avg_5y',
        'pe_avg_10y',
        'current_ev_ebitda',
        'ev_ebitda_avg_5y',
        'current_pb',
        'valuation_percentile',
        'current_price',
        'week_52_high',
        'week_52_low',
        'ath_price',
        'atl_price',
        'pct_below_ath',
        'pct_above_atl',
        'drawdown_percentile_10y',
        'revenue_cagr_3y',
        'revenue_cagr_5y',
        'profit_cagr_3y',
        'profit_cagr_5y',
        'roe',
        'roce',
        'debt_to_equity',
        'interest_coverage',
        'fcf',
        'promoter_holding',
        'fii_holding',
        'dii_holding',
        'fii_holding_change_qoq',
        'dii_holding_change_qoq',
        'market_cap',
        'dma_50',
        'dma_100',
        'dma_200',
        'distance_from_dma_200_pct',
        'rs_52w',
        'volume_spike_ratio',
        'delivery_pct',
        'rank_business_quality',
        'rank_sector_tailwind',
        'rank_valuation',
        'rank_correction',
        'rank_momentum',
        'rank_results_quality',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isCheap(): ?bool
    {
        if ($this->valuation_percentile === null) {
            return null;
        }

        return $this->valuation_percentile <= 30;
    }

    public function valuationVerdict(): string
    {
        if ($this->valuation_percentile === null) {
            return 'Unknown';
        }

        return match (true) {
            $this->valuation_percentile <= 20 => 'Very Cheap',
            $this->valuation_percentile <= 40 => 'Cheap',
            $this->valuation_percentile <= 60 => 'Fair',
            $this->valuation_percentile <= 80 => 'Expensive',
            default => 'Very Expensive',
        };
    }
}
