<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        ScreenerPreset::defaultPreset();

        $stocks = [
            ['symbol' => 'RELIANCE', 'name' => 'Reliance Industries', 'sector' => 'Oil & Gas', 'mtf' => true],
            ['symbol' => 'TCS', 'name' => 'Tata Consultancy Services', 'sector' => 'IT', 'mtf' => true],
            ['symbol' => 'INFY', 'name' => 'Infosys', 'sector' => 'IT', 'mtf' => true],
            ['symbol' => 'HDFCBANK', 'name' => 'HDFC Bank', 'sector' => 'Banking', 'mtf' => true],
            ['symbol' => 'ITC', 'name' => 'ITC Limited', 'sector' => 'FMCG', 'mtf' => true],
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'sector' => 'Technology', 'market' => 'US', 'exchange' => 'NASDAQ', 'mtf' => false],
            ['symbol' => 'MSFT', 'name' => 'Microsoft Corp.', 'sector' => 'Technology', 'market' => 'US', 'exchange' => 'NASDAQ', 'mtf' => false],
        ];

        foreach ($stocks as $s) {
            $company = Company::query()->updateOrCreate(
                ['symbol' => $s['symbol'], 'exchange' => $s['exchange'] ?? 'NSE'],
                [
                    'market' => $s['market'] ?? 'IN',
                    'name' => $s['name'],
                    'sector' => $s['sector'],
                    'yahoo_symbol' => ($s['market'] ?? 'IN') === 'US' ? $s['symbol'] : "{$s['symbol']}.NS",
                    'is_mtf_eligible' => $s['mtf'],
                    'mtf_group' => $s['mtf'] ? 'Group I' : null,
                    'is_active' => true,
                ],
            );

            CompanyMetric::query()->updateOrCreate(
                ['company_id' => $company->id, 'as_of_date' => today()],
                $this->demoMetrics($s['symbol']),
            );

            $this->seedMetricHistory($company, $s['symbol']);
        }

        $this->command?->info('Demo data seeded. Run: php artisan screener:compute-scores');
    }

    /**
     * @return array<string, float|int|null>
     */
    private function demoMetrics(string $symbol): array
    {
        $base = [
            'RELIANCE' => ['pe' => 28.5, 'roce' => 12.1, 'roe' => 9.8, 'price' => 1450],
            'TCS' => ['pe' => 32.1, 'roce' => 52.3, 'roe' => 48.2, 'price' => 4100],
            'INFY' => ['pe' => 26.8, 'roce' => 38.5, 'roe' => 32.1, 'price' => 1850],
            'HDFCBANK' => ['pe' => 18.2, 'roce' => 7.5, 'roe' => 16.8, 'price' => 1680],
            'ITC' => ['pe' => 29.5, 'roce' => 28.2, 'roe' => 24.5, 'price' => 465],
            'AAPL' => ['pe' => 31.2, 'roce' => 55.0, 'roe' => 160.0, 'price' => 195],
            'MSFT' => ['pe' => 35.8, 'roce' => 28.5, 'roe' => 38.2, 'price' => 420],
        ][$symbol] ?? ['pe' => 20, 'roce' => 15, 'roe' => 12, 'price' => 100];

        return [
            'current_pe' => $base['pe'],
            'pe_avg_5y' => $base['pe'] * 0.95,
            'pe_avg_10y' => $base['pe'] * 1.05,
            'current_pb' => round($base['pe'] / 5, 2),
            'valuation_percentile' => rand(15, 75),
            'current_price' => $base['price'],
            'week_52_high' => $base['price'] * 1.15,
            'week_52_low' => $base['price'] * 0.82,
            'pct_below_ath' => rand(5, 25),
            'pct_above_atl' => rand(40, 120),
            'drawdown_percentile_10y' => rand(20, 60),
            'revenue_cagr_3y' => rand(8, 18),
            'profit_cagr_3y' => rand(10, 22),
            'roe' => $base['roe'],
            'roce' => $base['roce'],
            'debt_to_equity' => rand(0, 80) / 100,
            'interest_coverage' => rand(5, 20),
            'promoter_holding' => rand(45, 75),
            'fii_holding_change_qoq' => rand(-2, 3),
            'dii_holding_change_qoq' => rand(-1, 2),
            'dma_50' => $base['price'] * 0.98,
            'dma_100' => $base['price'] * 0.95,
            'dma_200' => $base['price'] * 0.92,
            'distance_from_dma_200_pct' => rand(5, 15),
            'rs_52w' => rand(10, 40),
            'volume_spike_ratio' => rand(80, 150) / 100,
            'delivery_pct' => rand(35, 65),
        ];
    }

    private function seedMetricHistory(Company $company, string $symbol): void
    {
        $basePe = [
            'RELIANCE' => 28, 'TCS' => 30, 'INFY' => 25,
            'HDFCBANK' => 18, 'ITC' => 28, 'AAPL' => 30, 'MSFT' => 32,
        ][$symbol] ?? 22;

        for ($year = 2016; $year <= 2025; $year++) {
            $pe = $basePe + rand(-5, 8);
            MetricHistory::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'metric_key' => 'pe',
                    'period_date' => "{$year}-03-31",
                    'period_type' => 'annual',
                ],
                ['value' => $pe, 'source' => 'demo'],
            );

            MetricHistory::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'metric_key' => 'roce',
                    'period_date' => "{$year}-03-31",
                    'period_type' => 'annual',
                ],
                ['value' => rand(10, 45), 'source' => 'demo'],
            );
        }
    }
}
