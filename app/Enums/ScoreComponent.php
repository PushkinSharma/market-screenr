<?php

namespace App\Enums;

enum ScoreComponent: string
{
    case BusinessQuality = 'business_quality';
    case SectorTailwind = 'sector_tailwind';
    case Valuation = 'valuation';
    case Correction = 'correction';
    case Momentum = 'momentum';
    case ResultsQuality = 'results_quality';

    public function label(): string
    {
        return match ($this) {
            self::BusinessQuality => 'Business Quality',
            self::SectorTailwind => 'Sector Tailwind',
            self::Valuation => 'Valuation',
            self::Correction => 'Correction',
            self::Momentum => 'Momentum',
            self::ResultsQuality => 'Results Quality',
        };
    }

    /**
     * Underlying metric columns on company_metrics used to compute this component.
     *
     * @return array<string, bool> metric => higher_is_better
     */
    public function metrics(): array
    {
        return match ($this) {
            self::BusinessQuality => [
                'roce' => true,
                'roe' => true,
                'debt_to_equity' => false,
                'interest_coverage' => true,
                'promoter_holding' => true,
            ],
            self::SectorTailwind => [
                'rs_52w' => true,
                'revenue_cagr_3y' => true,
            ],
            self::Valuation => [
                'valuation_percentile' => false, // lower percentile = cheaper = better
                'current_pe' => false,
                'current_pb' => false,
            ],
            self::Correction => [
                'pct_below_ath' => true, // deeper correction can mean opportunity
                'drawdown_percentile_10y' => false, // lower price in range = better entry
            ],
            self::Momentum => [
                'distance_from_dma_200_pct' => true,
                'volume_spike_ratio' => true,
                'delivery_pct' => true,
            ],
            self::ResultsQuality => [
                'profit_cagr_3y' => true,
                'revenue_cagr_3y' => true,
                'fcf' => true,
                'fii_holding_change_qoq' => true,
            ],
        };
    }
}
