<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\FundamentalsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncIndiaFundamentalsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public ?int $companyId = null,
    ) {}

    public function handle(FundamentalsSyncService $sync): void
    {
        $query = Company::query()
            ->where('market', 'IN')
            ->where('is_active', true);

        if ($this->companyId) {
            $query->where('id', $this->companyId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('fundamentals_synced_at')
                    ->orWhere('fundamentals_synced_at', '<', now()->subDay());
            })->limit(50);
        }

        $companies = $query->get();
        $delay = config('market_screenr.screener_ingest.delay_seconds');

        foreach ($companies as $company) {
            try {
                $sync->syncCompany($company);
                Log::info("Synced fundamentals: {$company->symbol}");
            } catch (\Throwable $e) {
                Log::error("Fundamentals sync failed: {$company->symbol}", ['error' => $e->getMessage()]);
            }

            if (! $this->companyId) {
                usleep((int) ($delay * 1_000_000));
            }
        }
    }
}
