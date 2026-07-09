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

        if ($this->companyId) {
            $companies = Company::query()
                ->where('market', 'IN')
                ->where('is_active', true)
                ->where('id', $this->companyId)
                ->get();
        } else {
            // Prefer liquid large-caps first. Alphabetical sync was enriching
            // ETFs/junk (20MICRONS, SILVER360) before names you actually care about.
            $limit = $this->limit ?? config('market_screenr.sync.bootstrap_company_limit');
            $preferred = collect(config('market_screenr.preferred_nse_symbols', []))
                ->merge(collect(config('market_screenr.fallback_nse_symbols', []))->pluck('symbol'))
                ->map(fn ($s) => strtoupper((string) $s))
                ->unique()
                ->values()
                ->all();

            $stale = fn ($q) => $q->where(function ($inner) {
                $inner->whereNull('fundamentals_synced_at')
                    ->orWhere('fundamentals_synced_at', '<', now()->subDay());
            });

            $preferredSet = array_flip($preferred);

            $priority = Company::query()
                ->where('market', 'IN')
                ->where('is_active', true)
                ->whereIn('symbol', $preferred)
                ->where($stale)
                ->get()
                ->sortBy(fn (Company $c) => $preferredSet[$c->symbol] ?? PHP_INT_MAX)
                ->take($limit)
                ->values();

            $remaining = $limit - $priority->count();
            $rest = collect();

            if ($remaining > 0) {
                $rest = Company::query()
                    ->where('market', 'IN')
                    ->where('is_active', true)
                    ->whereNotIn('symbol', $preferred)
                    ->where($stale)
                    ->orderBy('symbol')
                    ->limit($remaining)
                    ->get();
            }

            $companies = $priority->concat($rest)->values();
        }
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
