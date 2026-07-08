<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyMetric;
use App\Models\MetricHistory;
use App\Services\FundamentalsSyncService;
use App\Services\MarketData\BusinessQuantClient;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncUsFundamentalsJob implements ShouldQueue
{
    use Queueable;

    public function handle(BusinessQuantClient $bq, FundamentalsSyncService $sync): void
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
        $businessQuantSucceeded = 0;
        $yahooFallbackOnly = 0;
        $businessQuantUnavailable = false;
        $lastError = null;

        foreach ($companies as $company) {
            try {
                $appliedBusinessQuant = false;

                if (! $businessQuantUnavailable) {
                    $appliedBusinessQuant = $this->applyBusinessQuantMetrics($company, $bq);
                    $lastError = $bq->lastError() ?: $lastError;

                    if (! $appliedBusinessQuant && $bq->lastError()) {
                        $businessQuantUnavailable = true;
                    }
                }

                $sync->syncCompany($company);

                if ($appliedBusinessQuant) {
                    $businessQuantSucceeded++;
                } else {
                    $yahooFallbackOnly++;
                }

                $succeeded++;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::error("US sync failed: {$company->symbol}", ['error' => $lastError]);
            }
        }

        $message = "Synced {$succeeded}/{$companies->count()} US stocks.";
        if ($businessQuantSucceeded > 0) {
            $message .= " BusinessQuant enriched {$businessQuantSucceeded}.";
        }
        if ($yahooFallbackOnly > 0) {
            $message .= " Yahoo fallback used for {$yahooFallbackOnly}.";
        }
        if ($lastError) {
            $message .= ' BusinessQuant note: '.$lastError;
        }

        $recorder->finish(
            match (true) {
                $succeeded === 0 => 'failed',
                $businessQuantSucceeded === 0 && $companies->isNotEmpty() => 'partial',
                default => 'success',
            },
            $companies->count(),
            $succeeded,
            $message,
        );
    }

    private function applyBusinessQuantMetrics(Company $company, BusinessQuantClient $bq): bool
    {
        $snapshot = $bq->ratioSnapshot($company->symbol);

        if (empty($snapshot['metrics']) && empty($snapshot['pe_history'])) {
            return false;
        }

        $peValues = collect($snapshot['pe_history'])->pluck('value')->filter();

        CompanyMetric::query()->updateOrCreate(
            ['company_id' => $company->id, 'as_of_date' => today()],
            array_filter([
                ...$snapshot['metrics'],
                'pe_avg_5y' => $peValues->take(-5)->avg(),
                'pe_avg_10y' => $peValues->avg(),
            ], fn ($v) => $v !== null),
        );

        foreach ($snapshot['pe_history'] as $row) {
            MetricHistory::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'metric_key' => 'pe',
                    'period_date' => $row['period'],
                    'period_type' => 'annual',
                ],
                ['value' => $row['value'], 'source' => 'businessquant'],
            );
        }

        return true;
    }
}
