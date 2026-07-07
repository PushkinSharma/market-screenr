<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\FundamentalsSyncService;
use App\Services\SyncRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncIndiaFundamentalsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public ?int $companyId = null,
        public ?int $limit = null,
    ) {}

    public function handle(FundamentalsSyncService $sync): void
    {
        $recorder = new SyncRunRecorder('screener_in', 'IN');

        $query = Company::query()
            ->where('market', 'IN')
            ->where('is_active', true);

        if ($this->companyId) {
            $query->where('id', $this->companyId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('fundamentals_synced_at')
                    ->orWhere('fundamentals_synced_at', '<', now()->subDay());
            })->limit($this->limit ?? config('market_screenr.sync.bootstrap_company_limit'));
        }

        $companies = $query->get();
        $succeeded = 0;
        $delay = config('market_screenr.screener_ingest.delay_seconds');

        foreach ($companies as $company) {
            try {
                $sync->syncCompany($company);
                $succeeded++;
                Log::info("Synced fundamentals: {$company->symbol}");
            } catch (\Throwable $e) {
                Log::error("Fundamentals sync failed: {$company->symbol}", ['error' => $e->getMessage()]);
            }

            if (! $this->companyId) {
                usleep((int) ($delay * 1_000_000));
            }
        }

        $status = match (true) {
            $succeeded === 0 && $companies->isNotEmpty() => 'failed',
            $succeeded < $companies->count() => 'partial',
            default => 'success',
        };

        $recorder->finish(
            $status,
            $companies->count(),
            $succeeded,
            "Synced {$succeeded}/{$companies->count()} Indian stocks (NSE quote + Yahoo + screener.in).",
        );
    }
}
