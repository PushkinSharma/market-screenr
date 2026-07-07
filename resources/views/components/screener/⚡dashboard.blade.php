<?php

use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;
use App\Services\SyncStatusService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app', ['title' => 'MTF Screener'])] class extends Component
{
    use WithPagination;

    public string $market = 'IN';
    public bool $mtfOnly = true;
    public string $search = '';
    public string $sortBy = 'final_score';
    public string $sortDir = 'desc';
    public ?int $presetId = null;

    public function mount(): void
    {
        $this->presetId = ScreenerPreset::defaultPreset()->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function with(): array
    {
        $preset = ScreenerPreset::query()->find($this->presetId) ?? ScreenerPreset::defaultPreset();

        $query = ScreenerScore::query()
            ->with(['company.latestMetric'])
            ->where('screener_preset_id', $preset->id)
            ->whereHas('company', function ($q) {
                $q->where('is_active', true);
                if ($this->market !== 'ALL') {
                    $q->where('market', $this->market);
                }
                if ($this->mtfOnly) {
                    $q->where('is_mtf_eligible', true);
                }
                if ($this->search) {
                    $q->where(function ($sq) {
                        $sq->where('symbol', 'like', "%{$this->search}%")
                            ->orWhere('name', 'like', "%{$this->search}%");
                    });
                }
            });

        // Latest computed scores
        $latestDate = ScreenerScore::query()
            ->where('screener_preset_id', $preset->id)
            ->max('computed_at');

        if ($latestDate) {
            $query->where('computed_at', $latestDate);
        }

        $allowedSorts = ['final_score', 'rank', 'valuation_score', 'correction_score', 'momentum_score'];
        $sortCol = in_array($this->sortBy, $allowedSorts) ? $this->sortBy : 'final_score';

        $scores = $query->orderBy($sortCol, $this->sortDir)->paginate(25);

        $syncStatus = app(SyncStatusService::class)->dashboardStats();

        return [
            'scores' => $scores,
            'preset' => $preset,
            'presets' => ScreenerPreset::query()->orderBy('name')->get(),
            'latestDate' => $latestDate,
            'syncStatus' => $syncStatus,
        ];
    }
};
?>

<div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">MTF Stock Screener</h1>
                <p class="text-slate-400 text-sm mt-1">
                    Weighted scoring across valuation, drawdown, fundamentals & momentum
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
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-300">Data Pipeline</h2>
                <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                    <span>{{ $syncStatus['companies']['total'] }} companies</span>
                    <span>{{ $syncStatus['companies']['india'] }} IN</span>
                    <span>{{ $syncStatus['companies']['us'] }} US</span>
                    <span>{{ $syncStatus['companies']['mtf_eligible'] }} MTF</span>
                    <span>{{ $syncStatus['companies']['with_metrics_latest'] }} with metrics</span>
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
                    <p class="text-xs text-slate-500 pt-1">On Cloud run: <code class="text-emerald-400">php artisan screener:sync --sync</code></p>
                </div>
            @endif
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap gap-3 items-center bg-slate-900/50 border border-slate-800 rounded-xl p-4">
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search symbol or name..."
                class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm w-64 focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 outline-none"
            />
            <select wire:model.live="market" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                <option value="IN">India (NSE)</option>
                <option value="US">United States</option>
                <option value="ALL">All Markets</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
                <input type="checkbox" wire:model.live="mtfOnly" class="rounded bg-slate-800 border-slate-600 text-emerald-500 focus:ring-emerald-500/50">
                MTF eligible only
            </label>
        </div>

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
                        <th class="px-4 py-3 text-left">#</th>
                        <th class="px-4 py-3 text-left">Stock</th>
                        <th class="px-4 py-3 text-right cursor-pointer hover:text-white" wire:click="sort('final_score')">
                            Score {!! $sortBy === 'final_score' ? ($sortDir === 'desc' ? '↓' : '↑') : '' !!}
                        </th>
                        <th class="px-4 py-3 text-right">P/E</th>
                        <th class="px-4 py-3 text-right">Valuation</th>
                        <th class="px-4 py-3 text-right">% Below ATH</th>
                        <th class="px-4 py-3 text-right">ROCE</th>
                        <th class="px-4 py-3 text-right">200 DMA</th>
                        <th class="px-4 py-3 text-center">MTF</th>
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
                            <td class="px-4 py-3 text-right">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                    {{ $score->final_score >= 70 ? 'bg-emerald-500/20 text-emerald-400' : ($score->final_score >= 50 ? 'bg-amber-500/20 text-amber-400' : 'bg-slate-700 text-slate-400') }}">
                                    {{ number_format($score->final_score, 0) }}/100
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $m?->current_pe ? number_format($m->current_pe, 1) : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($m?->valuationVerdict())
                                    <span class="text-xs {{ $m->isCheap() ? 'text-emerald-400' : 'text-slate-400' }}">
                                        {{ $m->valuationVerdict() }}
                                    </span>
                                @else — @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-amber-400/80">
                                {{ $m?->pct_below_ath ? number_format($m->pct_below_ath, 1).'%' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $m?->roce ? number_format($m->roce, 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($m?->distance_from_dma_200_pct !== null)
                                    <span class="{{ $m->distance_from_dma_200_pct > 0 ? 'text-emerald-400' : 'text-red-400' }}">
                                        {{ $m->distance_from_dma_200_pct > 0 ? '+' : '' }}{{ number_format($m->distance_from_dma_200_pct, 1) }}%
                                    </span>
                                @else — @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($score->company->is_mtf_eligible)
                                    <span class="text-emerald-400">✓</span>
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-slate-500">
                                <p class="mb-2">No scored stocks yet.</p>
                                @if($syncStatus['companies']['total'] === 0)
                                    <p class="text-xs">Database is empty — run the bootstrap sync on Cloud.</p>
                                @elseif($syncStatus['companies']['with_metrics'] === 0)
                                    <p class="text-xs">Companies exist but no metrics — fundamentals sync hasn't completed.</p>
                                @elseif($mtfOnly && $syncStatus['companies']['mtf_eligible'] === 0)
                                    <p class="text-xs">Try turning off "MTF eligible only" — BSE MTF sync may have failed.</p>
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
