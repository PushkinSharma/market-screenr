<?php

use App\Jobs\ComputeScreenerScoresJob;
use App\Jobs\RunBootstrapSyncJob;
use App\Jobs\SyncIndiaFundamentalsJob;
use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;
use App\Services\FundamentalsSyncService;
use App\Services\ScreenerEngine;
use App\Services\ScreenerSyncOrchestrator;
use App\Services\SyncStatusService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app', ['title' => 'India Screener'])] class extends Component
{
    use WithPagination;

    public string $market = 'IN';
    public bool $mtfOnly = false;
    public string $search = '';
    public string $sortBy = 'final_score';
    public string $sortDir = 'desc';
    public ?int $presetId = null;

    // Quick research filters (applied on latestMetric)
    public ?string $maxPe = null;
    public ?string $minRoce = null;
    public ?string $maxValuationPct = null;
    public ?string $minFiiChange = null;
    public ?string $minPctBelowAth = null;
    public string $sector = '';
    public bool $hasFundamentalsOnly = true;

    public int $syncLimit = 20;
    public bool $syncRefreshMtf = false;
    public bool $syncIncludeUs = false;
    public bool $syncPanelOpen = false;
    public ?string $syncMessage = null;
    public string $syncMessageType = 'info';

    public function mount(): void
    {
        $this->presetId = ScreenerPreset::defaultPreset()->id;
        $this->syncLimit = config('market_screenr.sync.bootstrap_company_limit');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedMtfOnly(): void
    {
        $this->resetPage();
    }

    public function updatedMarket(): void
    {
        $this->resetPage();
    }

    public function updatedPresetId(): void
    {
        $this->resetPage();
    }

    public function updatedMaxPe(): void
    {
        $this->resetPage();
    }

    public function updatedMinRoce(): void
    {
        $this->resetPage();
    }

    public function updatedMaxValuationPct(): void
    {
        $this->resetPage();
    }

    public function updatedMinFiiChange(): void
    {
        $this->resetPage();
    }

    public function updatedMinPctBelowAth(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedHasFundamentalsOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->maxPe = null;
        $this->minRoce = null;
        $this->maxValuationPct = null;
        $this->minFiiChange = null;
        $this->minPctBelowAth = null;
        $this->sector = '';
        $this->search = '';
        $this->hasFundamentalsOnly = true;
        $this->resetPage();
    }

    public function applyQuickFilter(string $preset): void
    {
        $this->maxPe = null;
        $this->minRoce = null;
        $this->maxValuationPct = null;
        $this->minFiiChange = null;
        $this->minPctBelowAth = null;
        $this->sector = '';
        $this->search = '';
        $this->hasFundamentalsOnly = true;

        if ($preset === 'cheap_quality') {
            $this->maxPe = '25';
            $this->minRoce = '15';
            $this->maxValuationPct = '50';
        } elseif ($preset === 'fii_buying') {
            $this->minFiiChange = '0.2';
            $this->maxValuationPct = '60';
        } elseif ($preset === 'drawdown') {
            $this->minPctBelowAth = '20';
            $this->minRoce = '12';
        }

        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $allowed = [
            'final_score', 'rank', 'symbol', 'current_pe', 'valuation_percentile',
            'pct_below_ath', 'roce', 'distance_from_dma_200_pct', 'fii_holding_change_qoq',
            'sector', 'valuation_score', 'correction_score', 'momentum_score',
        ];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            // Cheaper / higher quality first by default for metric columns
            $this->sortDir = in_array($column, ['current_pe', 'valuation_percentile', 'rank'], true)
                ? 'asc'
                : 'desc';
        }

        $this->resetPage();
    }

    public function sortIndicator(string $column): string
    {
        if ($this->sortBy !== $column) {
            return '';
        }

        return $this->sortDir === 'desc' ? '↓' : '↑';
    }

    public function queueBootstrapSync(): void
    {
        $this->syncLimit = max(1, min(100, $this->syncLimit));

        RunBootstrapSyncJob::dispatch(
            limit: $this->syncLimit,
            refreshMtf: $this->syncRefreshMtf,
            includeUs: $this->syncIncludeUs,
        );

        $this->syncMessageType = 'success';
        $this->syncMessage = "Bootstrap queued (limit {$this->syncLimit}). Watch pipeline status below — usually 2–10 min on Cloud.";
        $this->syncPanelOpen = true;
    }

    public function runBootstrapSyncNow(): void
    {
        $this->syncLimit = max(1, min(100, $this->syncLimit));

        try {
            app(ScreenerSyncOrchestrator::class)->runBootstrap(
                $this->syncLimit,
                $this->syncRefreshMtf,
                $this->syncIncludeUs,
            );

            $this->syncMessageType = 'success';
            $this->syncMessage = "Bootstrap finished inline (limit {$this->syncLimit}).";
        } catch (\Throwable $e) {
            $this->syncMessageType = 'error';
            $this->syncMessage = 'Bootstrap failed: '.$e->getMessage();
        }
    }

    public function queueComputeScores(): void
    {
        ComputeScreenerScoresJob::dispatch();
        $this->syncMessageType = 'success';
        $this->syncMessage = 'Score computation queued.';
    }

    public function computeScoresNow(): void
    {
        try {
            $engine = app(ScreenerEngine::class);
            $presets = ScreenerPreset::query()->get();
            $total = 0;

            foreach ($presets as $preset) {
                $total += $engine->computeRanksAndScores($preset);
            }

            $this->syncMessageType = 'success';
            $this->syncMessage = "Computed scores for {$total} preset rows.";
        } catch (\Throwable $e) {
            $this->syncMessageType = 'error';
            $this->syncMessage = 'Score compute failed: '.$e->getMessage();
        }
    }

    public function queueAllSyncJobs(): void
    {
        app(ScreenerSyncOrchestrator::class)->dispatchAllJobs($this->syncLimit);
        $this->syncMessageType = 'success';
        $this->syncMessage = 'All scheduled sync jobs queued (universe, MTF, fundamentals, scores).';
    }

    public function syncIndiaFundamentalsNow(): void
    {
        $this->syncLimit = max(1, min(100, $this->syncLimit));

        try {
            (new SyncIndiaFundamentalsJob(limit: $this->syncLimit))->handle(app(FundamentalsSyncService::class));
            $this->syncMessageType = 'success';
            $this->syncMessage = "India fundamentals synced (limit {$this->syncLimit}). Run compute scores next.";
        } catch (\Throwable $e) {
            $this->syncMessageType = 'error';
            $this->syncMessage = 'India fundamentals failed: '.$e->getMessage();
        }
    }

    public function clearSyncMessage(): void
    {
        $this->syncMessage = null;
    }

    private function baseScoreQuery(ScreenerPreset $preset, ?string $latestDate): \Illuminate\Database\Eloquent\Builder
    {
        $query = ScreenerScore::query()
            ->where('screener_preset_id', $preset->id)
            ->whereHas('company', function ($q) {
                $q->where('is_active', true);
                if ($this->market !== 'ALL') {
                    $q->where('market', $this->market);
                }
                if ($this->search) {
                    $q->where(function ($sq) {
                        $sq->where('symbol', 'like', "%{$this->search}%")
                            ->orWhere('name', 'like', "%{$this->search}%");
                    });
                }
                if ($this->sector !== '') {
                    $q->where('sector', $this->sector);
                }
            });

        $metricFilters = $this->hasFundamentalsOnly
            || ($this->maxPe !== null && $this->maxPe !== '')
            || ($this->minRoce !== null && $this->minRoce !== '')
            || ($this->maxValuationPct !== null && $this->maxValuationPct !== '')
            || ($this->minFiiChange !== null && $this->minFiiChange !== '')
            || ($this->minPctBelowAth !== null && $this->minPctBelowAth !== '');

        if ($metricFilters) {
            $query->whereHas('company.latestMetric', function ($q) {
                if ($this->hasFundamentalsOnly) {
                    $q->whereNotNull('roce');
                }
                if ($this->maxPe !== null && $this->maxPe !== '') {
                    $q->where('current_pe', '<=', (float) $this->maxPe);
                }
                if ($this->minRoce !== null && $this->minRoce !== '') {
                    $q->where('roce', '>=', (float) $this->minRoce);
                }
                if ($this->maxValuationPct !== null && $this->maxValuationPct !== '') {
                    $q->where('valuation_percentile', '<=', (float) $this->maxValuationPct);
                }
                if ($this->minFiiChange !== null && $this->minFiiChange !== '') {
                    $q->where('fii_holding_change_qoq', '>=', (float) $this->minFiiChange);
                }
                if ($this->minPctBelowAth !== null && $this->minPctBelowAth !== '') {
                    $q->where('pct_below_ath', '>=', (float) $this->minPctBelowAth);
                }
            });
        }

        if ($latestDate) {
            $query->where('computed_at', $latestDate);
        }

        return $query;
    }

    public function with(): array
    {
        $preset = ScreenerPreset::query()->find($this->presetId) ?? ScreenerPreset::defaultPreset();

        $latestDate = ScreenerScore::query()
            ->where('screener_preset_id', $preset->id)
            ->max('computed_at');

        $baseQuery = $this->baseScoreQuery($preset, $latestDate);
        $allScoredCount = (clone $baseQuery)->count();
        $mtfScoredCount = (clone $baseQuery)->whereHas('company', fn ($q) => $q->where('is_mtf_eligible', true))->count();

        $query = clone $baseQuery;
        if ($this->mtfOnly) {
            $query->whereHas('company', fn ($q) => $q->where('is_mtf_eligible', true));
        }

        $metricSorts = [
            'current_pe', 'valuation_percentile', 'pct_below_ath', 'roce',
            'distance_from_dma_200_pct', 'fii_holding_change_qoq',
        ];
        $companySorts = ['symbol', 'sector'];
        $scoreSorts = ['final_score', 'rank', 'valuation_score', 'correction_score', 'momentum_score'];

        $query->with(['company.latestMetric']);

        if (in_array($this->sortBy, $metricSorts, true)) {
            $query->join('companies', 'companies.id', '=', 'screener_scores.company_id')
                ->join('company_metrics', function ($join) {
                    $join->on('company_metrics.company_id', '=', 'companies.id')
                        ->whereRaw('company_metrics.id = (
                            select max(cm2.id) from company_metrics cm2
                            where cm2.company_id = companies.id
                        )');
                })
                ->orderBy('company_metrics.'.$this->sortBy, $this->sortDir)
                ->select('screener_scores.*');
        } elseif (in_array($this->sortBy, $companySorts, true)) {
            $query->join('companies', 'companies.id', '=', 'screener_scores.company_id')
                ->orderBy('companies.'.$this->sortBy, $this->sortDir)
                ->select('screener_scores.*');
        } elseif (in_array($this->sortBy, $scoreSorts, true)) {
            $query->orderBy($this->sortBy, $this->sortDir);
        } else {
            $query->orderByDesc('final_score');
        }

        $countQuery = clone $baseQuery;
        if ($this->mtfOnly) {
            $countQuery->whereHas('company', fn ($q) => $q->where('is_mtf_eligible', true));
        }
        $scoredTotal = $countQuery->count();

        $scores = $query->paginate(25);

        $sectors = \App\Models\Company::query()
            ->where('market', 'IN')
            ->where('is_active', true)
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->whereHas('screenerScores')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector');

        $syncStatus = app(SyncStatusService::class)->dashboardStats();
        $scoreFunnel = app(SyncStatusService::class)->scoreDiagnostics($this->mtfOnly, $this->market);

        return [
            'scores' => $scores,
            'scoredTotal' => $scoredTotal,
            'allScoredCount' => $allScoredCount,
            'mtfScoredCount' => $mtfScoredCount,
            'scoreFunnel' => $scoreFunnel,
            'preset' => $preset,
            'presets' => ScreenerPreset::query()->orderBy('name')->get(),
            'latestDate' => $latestDate,
            'syncStatus' => $syncStatus,
            'sectors' => $sectors,
        ];
    }
};
?>

<div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">India Stock Dashboard</h1>
                <p class="text-slate-400 text-sm mt-1">
                    Daily sync of valuation, ROCE, drawdown & momentum for Indian stocks
                    @if($latestDate)
                        · Data as of {{ \Carbon\Carbon::parse($latestDate)->format('M j, Y') }}
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <select wire:model.live="presetId" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                    @foreach($presets as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Pipeline / sync status --}}
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4 space-y-4" wire:poll.15s>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-300">Data Pipeline</h2>
                <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                    <span>{{ $syncStatus['companies']['total'] }} companies</span>
                    <span>{{ $syncStatus['companies']['india'] }} IN</span>
                    <span>{{ $syncStatus['companies']['us'] }} US</span>
                    <span>{{ $syncStatus['companies']['with_metrics_latest'] }} with metrics</span>
                    <span>{{ $scoredTotal }} in table</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 text-xs">
                @foreach($syncStatus['syncs'] as $sync)
                    <div class="bg-slate-800/50 rounded-lg px-3 py-2 border border-slate-700/50">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-300 font-medium truncate">{{ $sync['label'] }}</span>
                            <span @class([
                                'shrink-0 px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold',
                                'bg-emerald-500/20 text-emerald-400' => $sync['status'] === 'success',
                                'bg-amber-500/20 text-amber-400' => $sync['status'] === 'partial',
                                'bg-red-500/20 text-red-400' => in_array($sync['status'], ['failed', 'never']),
                            ])>{{ $sync['status'] }}</span>
                        </div>
                        <div class="text-slate-500 mt-1">
                            @if($sync['finished_at'])
                                {{ $sync['finished_at']->diffForHumans() }}
                                @if($sync['records_succeeded'] > 0)
                                    · {{ $sync['records_succeeded'] }} rows
                                @endif
                            @else
                                Never synced
                            @endif
                        </div>
                        @if($sync['message'])
                            <div class="text-slate-600 mt-0.5 truncate" title="{{ $sync['message'] }}">{{ $sync['message'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if(!empty($syncStatus['diagnostics']))
                <div class="border-t border-slate-800 pt-3 space-y-1">
                    @foreach($syncStatus['diagnostics'] as $issue)
                        <p class="text-xs text-amber-400/90">⚠ {{ $issue }}</p>
                    @endforeach
                </div>
            @endif

            {{-- Sync controls --}}
            <div class="border-t border-slate-800 pt-4">
                <button
                    type="button"
                    wire:click="$toggle('syncPanelOpen')"
                    class="flex items-center gap-2 text-sm font-semibold text-slate-300 hover:text-white"
                >
                    <span>{{ $syncPanelOpen ? '▼' : '▶' }}</span>
                    Run sync from dashboard
                </button>

                @if($syncPanelOpen)
                    <div class="mt-4 space-y-4">
                        @if($syncMessage)
                            <div @class([
                                'rounded-lg px-3 py-2 text-sm flex items-start justify-between gap-3',
                                'bg-emerald-500/10 border border-emerald-500/30 text-emerald-200' => $syncMessageType === 'success',
                                'bg-red-500/10 border border-red-500/30 text-red-200' => $syncMessageType === 'error',
                                'bg-slate-800 border border-slate-700 text-slate-300' => $syncMessageType === 'info',
                            ])>
                                <span>{{ $syncMessage }}</span>
                                <button type="button" wire:click="clearSyncMessage" class="text-slate-400 hover:text-white shrink-0">×</button>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                            <label class="space-y-1">
                                <span class="text-xs text-slate-400">India company limit</span>
                                <input
                                    type="number"
                                    min="1"
                                    max="100"
                                    wire:model="syncLimit"
                                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2"
                                />
                            </label>
                            <label class="flex items-end gap-2 pb-2 cursor-pointer">
                                <input type="checkbox" wire:model="syncIncludeUs" class="rounded bg-slate-800 border-slate-600 text-emerald-500">
                                <span class="text-slate-300">Include US fundamentals</span>
                            </label>
                            <label class="flex items-end gap-2 pb-2 cursor-pointer">
                                <input type="checkbox" wire:model="syncRefreshMtf" class="rounded bg-slate-800 border-slate-600 text-emerald-500">
                                <span class="text-slate-300">Also refresh BSE MTF flags <span class="text-slate-500">(optional)</span></span>
                            </label>
                        </div>

                        <p class="text-xs text-slate-500">
                            Equivalent CLI:
                            <code class="text-emerald-400">php artisan screener:sync --sync --india-only --limit={{ $syncLimit }}</code>
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                wire:click="queueBootstrapSync"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="queueBootstrapSync">Queue full bootstrap</span>
                                <span wire:loading wire:target="queueBootstrapSync">Queuing…</span>
                            </button>
                            <button
                                type="button"
                                wire:click="runBootstrapSyncNow"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm disabled:opacity-50"
                            >
                                Run bootstrap now <span class="text-slate-400">(may timeout)</span>
                            </button>
                            <button
                                type="button"
                                wire:click="queueComputeScores"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-sm disabled:opacity-50"
                            >
                                Queue compute scores
                            </button>
                            <button
                                type="button"
                                wire:click="computeScoresNow"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-sm disabled:opacity-50"
                            >
                                Compute scores now
                            </button>
                            <button
                                type="button"
                                wire:click="syncIndiaFundamentalsNow"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-sm disabled:opacity-50"
                            >
                                India fundamentals only
                            </button>
                            <button
                                type="button"
                                wire:click="queueAllSyncJobs"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 text-sm disabled:opacity-50"
                            >
                                Queue all jobs
                            </button>
                        </div>

                        <p class="text-xs text-slate-500">
                            Local tip: use <strong class="text-slate-400">India fundamentals only</strong> or CLI <code class="text-emerald-400">--india-only</code>. Preferred watchlist symbols sync first.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Filters --}}
        <div class="space-y-3 bg-slate-900/50 border border-slate-800 rounded-xl p-4">
            <div class="flex flex-wrap gap-2 items-center">
                <span class="text-xs text-slate-500 uppercase tracking-wide mr-1">Quick</span>
                <button type="button" wire:click="applyQuickFilter('cheap_quality')" class="px-2.5 py-1 rounded-md text-xs bg-slate-800 border border-slate-700 hover:border-emerald-500/50 text-slate-300">Cheap + quality</button>
                <button type="button" wire:click="applyQuickFilter('fii_buying')" class="px-2.5 py-1 rounded-md text-xs bg-slate-800 border border-slate-700 hover:border-emerald-500/50 text-slate-300">FII buying</button>
                <button type="button" wire:click="applyQuickFilter('drawdown')" class="px-2.5 py-1 rounded-md text-xs bg-slate-800 border border-slate-700 hover:border-emerald-500/50 text-slate-300">Deep drawdown</button>
                <button type="button" wire:click="clearFilters" class="px-2.5 py-1 rounded-md text-xs text-slate-500 hover:text-slate-300">Clear</button>
                <span class="text-xs text-slate-600 ml-auto">{{ $scoredTotal }} matching</span>
            </div>

            <div class="flex flex-wrap gap-3 items-end">
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Search</span>
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="text"
                        placeholder="Symbol or name..."
                        class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-48 focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 outline-none"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Sector</span>
                    <select wire:model.live="sector" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm min-w-[140px]">
                        <option value="">All sectors</option>
                        @foreach($sectors as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Max P/E</span>
                    <input wire:model.live.debounce.400ms="maxPe" type="number" step="0.1" placeholder="e.g. 25" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-24" />
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Min ROCE %</span>
                    <input wire:model.live.debounce.400ms="minRoce" type="number" step="0.1" placeholder="e.g. 15" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-24" />
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Max val %</span>
                    <input wire:model.live.debounce.400ms="maxValuationPct" type="number" step="1" placeholder="e.g. 40" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-24" title="Valuation percentile (lower = cheaper vs history)" />
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Min FII Δ</span>
                    <input wire:model.live.debounce.400ms="minFiiChange" type="number" step="0.1" placeholder="e.g. 0.2" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-24" />
                </label>
                <label class="space-y-1">
                    <span class="text-[11px] text-slate-500">Min % below ATH</span>
                    <input wire:model.live.debounce.400ms="minPctBelowAth" type="number" step="1" placeholder="e.g. 20" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-28" />
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer text-slate-400 pb-2">
                    <input type="checkbox" wire:model.live.boolean="hasFundamentalsOnly" class="rounded bg-slate-800 border-slate-600 text-emerald-500 focus:ring-emerald-500/50">
                    Has ROCE
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer text-slate-500 pb-2">
                    <input type="checkbox" wire:model.live.boolean="mtfOnly" class="rounded bg-slate-800 border-slate-600 text-emerald-500 focus:ring-emerald-500/50">
                    MTF
                </label>
            </div>
        </div>

        @if($scoreFunnel['metrics_on_date'] > $scoredTotal)
            <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl px-4 py-3 text-sm text-amber-200/90">
                <strong>Why fewer stocks than "with metrics"?</strong>
                The table lists <em>scored</em> rows for your filters ({{ $scoredTotal }} shown), not every synced company.
                Pipeline: {{ $scoreFunnel['metrics_on_date'] }} companies have metrics today
                → {{ $scoreFunnel['after_market_filter'] }} match market "{{ $market }}"
                {{ $mtfOnly ? '→ '.$scoreFunnel['after_mtf_filter'].' are MTF-eligible' : '' }}.
                US stocks ({{ $syncStatus['companies']['us'] }}) are hidden while market is India.
                Uncheck MTF or pick "All Markets" to see more; run sync with a higher <code class="text-emerald-400">--limit</code> to enrich more India names.
            </div>
        @endif

        {{-- Weight legend --}}
        @if($preset)
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 text-xs">
            @foreach($preset->weights ?? [] as $key => $weight)
                <div class="bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-center">
                    <div class="text-slate-400 capitalize">{{ str_replace('_', ' ', $key) }}</div>
                    <div class="text-emerald-400 font-semibold">{{ $weight }}%</div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Results table --}}
        <div class="overflow-x-auto border border-slate-800 rounded-xl">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left cursor-pointer hover:text-white" wire:click="sort('rank')">
                            # {{ $this->sortIndicator('rank') }}
                        </th>
                        <th class="px-4 py-3 text-left cursor-pointer hover:text-white" wire:click="sort('symbol')">
                            Stock {{ $this->sortIndicator('symbol') }}
                        </th>
                        <th class="px-4 py-3 text-left cursor-pointer hover:text-white" wire:click="sort('sector')">
                            Sector {{ $this->sortIndicator('sector') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('final_score')">
                            Score {{ $this->sortIndicator('final_score') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('current_pe')">
                            P/E {{ $this->sortIndicator('current_pe') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('valuation_percentile')">
                            Valuation {{ $this->sortIndicator('valuation_percentile') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('pct_below_ath')">
                            % Below ATH {{ $this->sortIndicator('pct_below_ath') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('roce')">
                            ROCE {{ $this->sortIndicator('roce') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('distance_from_dma_200_pct')">
                            200 DMA {{ $this->sortIndicator('distance_from_dma_200_pct') }}
                        </th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('fii_holding_change_qoq')">
                            FII Δ {{ $this->sortIndicator('fii_holding_change_qoq') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @forelse($scores as $score)
                        @php $m = $score->company->latestMetric; @endphp
                        <tr class="hover:bg-slate-900/50 transition cursor-pointer" onclick="window.location='{{ route('company', $score->company) }}'">
                            <td class="px-4 py-3 text-slate-500">{{ $score->rank }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $score->company->symbol }}</div>
                                <div class="text-xs text-slate-500 truncate max-w-[180px]">{{ $score->company->name }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-400 max-w-[120px] truncate" title="{{ $score->company->sector }}">
                                {{ $score->company->sector ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                    {{ $score->final_score >= 70 ? 'bg-emerald-500/20 text-emerald-400' : ($score->final_score >= 50 ? 'bg-amber-500/20 text-amber-400' : 'bg-slate-700 text-slate-400') }}">
                                    {{ number_format($score->final_score, 0) }}/100
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $m?->current_pe ? number_format($m->current_pe, 1) : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($m !== null && $m->valuation_percentile !== null)
                                    <span class="text-xs {{ $m->isCheap() ? 'text-emerald-400' : 'text-slate-400' }}" title="{{ number_format($m->valuation_percentile, 0) }}th pct vs peers today (0=cheapest)">
                                        {{ $m->valuationVerdict() }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-amber-400/80">
                                {{ $m?->pct_below_ath ? number_format($m->pct_below_ath, 1).'%' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $m?->roce ? number_format($m->roce, 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($m !== null && $m->distance_from_dma_200_pct !== null)
                                    <span class="{{ $m->distance_from_dma_200_pct > 0 ? 'text-emerald-400' : 'text-red-400' }}">
                                        {{ $m->distance_from_dma_200_pct > 0 ? '+' : '' }}{{ number_format($m->distance_from_dma_200_pct, 1) }}%
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($m !== null && $m->fii_holding_change_qoq !== null)
                                    <span class="{{ $m->fii_holding_change_qoq > 0 ? 'text-emerald-400' : ($m->fii_holding_change_qoq < 0 ? 'text-red-400' : 'text-slate-400') }}">
                                        {{ $m->fii_holding_change_qoq > 0 ? '+' : '' }}{{ number_format($m->fii_holding_change_qoq, 2) }}%
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center text-slate-500">
                                <p class="mb-2">No scored stocks yet.</p>
                                @if($syncStatus['companies']['total'] === 0)
                                    <p class="text-xs">Database is empty — run <code class="text-emerald-400">php artisan screener:sync --sync --india-only --limit=20</code></p>
                                @elseif($syncStatus['companies']['with_metrics'] === 0)
                                    <p class="text-xs">Companies exist but no metrics — run India fundamentals sync.</p>
                                @else
                                    <p class="text-xs">Run <code class="text-emerald-400">php artisan screener:compute-scores</code></p>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $scores->links() }}</div>
</div>
