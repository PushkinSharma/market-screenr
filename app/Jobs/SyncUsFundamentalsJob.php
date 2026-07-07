<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Services\MarketData\BusinessQuantClient;
use App\Services\MetricCalculator;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncUsFundamentalsJob implements ShouldQueue
{
    use Queueable;

    public function handle(BusinessQuantClient $bq, MetricCalculator $calculator): void
    {
        $recorder = new SyncRunRecorder('businessquant', 'US');

        if (empty(config('market_screenr.businessquant.api_key'))) {
            $recorder->finish('failed', 0, 0, 'BUSINESSQUANT_API_KEY is not configured.');
            Log::warning('SyncUsFundamentalsJob: missing BUSINESSQUANT_API_KEY');

            return;
        }

        $companies = Company::query()
            ->where('market', 'US')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('fundamentals_synced_at')
                    ->orWhere('fundamentals_synced_at', '<', now()->subDay());
            })
            ->limit(25)
            ->get();

        $succeeded = 0;

        foreach ($companies as $company) {
            try {
                $this->syncUsCompany($company, $bq);
                $calculator->computeAndStore($company);
                $company->update(['fundamentals_synced_at' => now()]);
                $succeeded++;
            } catch (\Throwable $e) {
                Log::error("US sync failed: {$company->symbol}", ['error' => $e->getMessage()]);
            }
        }

        $recorder->finish(
            $succeeded > 0 ? 'success' : 'failed',
            $companies->count(),
            $succeeded,
            "Synced {$succeeded}/{$companies->count()} US stocks via BusinessQuant.",
        );
    }

    private function syncUsCompany(Company $company, BusinessQuantClient $bq): void
    {
        $ratios = $bq->ratios($company->symbol);

        if (empty($ratios)) {
            return;
        }

        $latest = $ratios[0] ?? [];
        $peValues = collect($ratios)->pluck('P/E Ratio')->filter()->map(fn ($v) => (float) $v);

        CompanyMetric::query()->updateOrCreate(
            ['company_id' => $company->id, 'as_of_date' => today()],
            array_filter([
                'current_pe' => isset($latest['P/E Ratio']) ? (float) $latest['P/E Ratio'] : null,
                'pe_avg_5y' => $peValues->take(5)->avg(),
                'pe_avg_10y' => $peValues->avg(),
                'current_pb' => isset($latest['P/B Ratio']) ? (float) $latest['P/B Ratio'] : null,
                'roe' => isset($latest['ROE']) ? (float) $latest['ROE'] : null,
                'roce' => isset($latest['ROCE']) ? (float) $latest['ROCE'] : null,
                'debt_to_equity' => isset($latest['Debt/Equity']) ? (float) $latest['Debt/Equity'] : null,
            ], fn ($v) => $v !== null),
        );

        foreach ($ratios as $row) {
            if (! isset($row['period'], $row['P/E Ratio'])) {
                continue;
            }

            MetricHistory::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'metric_key' => 'pe',
                    'period_date' => $row['period'],
                    'period_type' => 'annual',
                ],
                ['value' => (float) $row['P/E Ratio'], 'source' => 'businessquant'],
            );
        }
    }
}
