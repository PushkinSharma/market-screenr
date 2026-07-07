<?php

use App\Models\Company;
use App\Models\MetricHistory;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company->load(['latestMetric', 'intel' => fn ($q) => $q->latest('published_at')->limit(5)]);
    }

    public function with(): array
    {
        $peHistory = MetricHistory::query()
            ->where('company_id', $this->company->id)
            ->where('metric_key', 'pe')
            ->orderBy('period_date')
            ->get(['period_date', 'value']);

        $roceHistory = MetricHistory::query()
            ->where('company_id', $this->company->id)
            ->where('metric_key', 'roce')
            ->orderBy('period_date')
            ->get(['period_date', 'value']);

        $latestScore = $this->company->screenerScores()
            ->latest('computed_at')
            ->first();

        return [
            'metric' => $this->company->latestMetric,
            'peHistory' => $peHistory,
            'roceHistory' => $roceHistory,
            'latestScore' => $latestScore,
            'peChart' => [
                'labels' => $peHistory->pluck('period_date')->map(fn ($d) => $d->format('Y'))->toArray(),
                'values' => $peHistory->pluck('value')->toArray(),
            ],
            'roceChart' => [
                'labels' => $roceHistory->pluck('period_date')->map(fn ($d) => $d->format('Y'))->toArray(),
                'values' => $roceHistory->pluck('value')->toArray(),
            ],
        ];
    }
};
?>

<x-layouts.app :title="$company->symbol">
    @php $m = $metric; @endphp
    <div class="space-y-8">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold">{{ $company->symbol }}</h1>
                    @if($company->is_mtf_eligible)
                        <span class="bg-emerald-500/20 text-emerald-400 text-xs px-2 py-0.5 rounded-full">MTF Eligible</span>
                    @endif
                </div>
                <p class="text-slate-400">{{ $company->name }} · {{ $company->sector }}</p>
            </div>
            @if($latestScore)
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-6 py-4 text-center">
                    <div class="text-3xl font-bold text-emerald-400">{{ number_format($latestScore->final_score, 0) }}</div>
                    <div class="text-xs text-slate-500">MTF Score / 100 · Rank #{{ $latestScore->rank }}</div>
                </div>
            @endif
        </div>

        {{-- Section 1: Valuation --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-1">Section 1 — Valuation</h2>
            <p class="text-sm text-slate-500 mb-4">Is it cheap?</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['Current P/E', $m?->current_pe],
                    ['5Y Avg P/E', $m?->pe_avg_5y],
                    ['10Y Avg P/E', $m?->pe_avg_10y],
                    ['Current P/B', $m?->current_pb],
                    ['EV/EBITDA', $m?->current_ev_ebitda],
                    ['5Y Avg EV/EBITDA', $m?->ev_ebitda_avg_5y],
                ] as [$label, $val])
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-mono font-medium">{{ $val ? number_format($val, 1) : '—' }}</div>
                    </div>
                @endforeach
                <div class="bg-slate-800/50 rounded-lg p-3 col-span-2">
                    <div class="text-xs text-slate-500">Historical Percentile</div>
                    <div class="text-lg font-medium {{ $m?->isCheap() ? 'text-emerald-400' : 'text-slate-300' }}">
                        {{ $m?->valuationVerdict() ?? 'Unknown' }}
                        @if($m?->valuation_percentile !== null)
                            ({{ number_format($m->valuation_percentile, 0) }}th percentile)
                        @endif
                    </div>
                </div>
            </div>
            @if(count($peChart['values']) > 0)
                <div class="mt-6" x-data="{ init() { this.renderChart() } }" x-init="init()">
                    <div id="pe-chart" wire:ignore class="h-64"></div>
                    <script>
                        document.addEventListener('livewire:navigated', () => {
                            if (document.getElementById('pe-chart') && typeof ApexCharts !== 'undefined') {
                                new ApexCharts(document.getElementById('pe-chart'), {
                                    chart: { type: 'line', height: 260, background: 'transparent', foreColor: '#94a3b8', toolbar: { show: false } },
                                    series: [{ name: 'P/E', data: @json($peChart['values']) }],
                                    xaxis: { categories: @json($peChart['labels']) },
                                    stroke: { curve: 'smooth', width: 2 },
                                    colors: ['#34d399'],
                                    grid: { borderColor: '#334155' },
                                }).render();
                            }
                        });
                        if (document.getElementById('pe-chart')) {
                            new ApexCharts(document.getElementById('pe-chart'), {
                                chart: { type: 'line', height: 260, background: 'transparent', foreColor: '#94a3b8', toolbar: { show: false } },
                                series: [{ name: 'P/E', data: @json($peChart['values']) }],
                                xaxis: { categories: @json($peChart['labels']) },
                                stroke: { curve: 'smooth', width: 2 },
                                colors: ['#34d399'],
                                grid: { borderColor: '#334155' },
                            }).render();
                        }
                    </script>
                </div>
            @endif
        </section>

        {{-- Section 2: Drawdown --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4">Section 2 — Drawdown Engine</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['Current Price', $m?->current_price, '₹'],
                    ['52W High', $m?->week_52_high, '₹'],
                    ['52W Low', $m?->week_52_low, '₹'],
                    ['% Below ATH', $m?->pct_below_ath, '%'],
                    ['% Above ATL', $m?->pct_above_atl, '%'],
                    ['10Y Percentile', $m?->drawdown_percentile_10y, '%'],
                ] as [$label, $val, $suffix])
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-mono font-medium {{ str_contains($label, 'Below') ? 'text-amber-400' : '' }}">
                            @if($val !== null)
                                {{ $suffix === '₹' ? number_format($val, 2) : number_format($val, 1).$suffix }}
                            @else — @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Section 3: Fundamentals --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4">Section 3 — Fundamentals</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['Revenue CAGR (3Y)', $m?->revenue_cagr_3y, '%'],
                    ['Profit CAGR (3Y)', $m?->profit_cagr_3y, '%'],
                    ['ROE', $m?->roe, '%'],
                    ['ROCE', $m?->roce, '%'],
                    ['Debt/Equity', $m?->debt_to_equity, ''],
                    ['Interest Coverage', $m?->interest_coverage, 'x'],
                    ['Promoter Holding', $m?->promoter_holding, '%'],
                    ['FII Change (QoQ)', $m?->fii_holding_change_qoq, '%'],
                    ['DII Change (QoQ)', $m?->dii_holding_change_qoq, '%'],
                ] as [$label, $val, $suffix])
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-mono font-medium">
                            @if($val !== null){{ number_format($val, 1) }}{{ $suffix }}@else — @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Section 4: Momentum --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-1">Section 4 — Momentum</h2>
            <p class="text-sm text-slate-500 mb-4">Confirmation, not trading signals</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['50 DMA', $m?->dma_50],
                    ['100 DMA', $m?->dma_100],
                    ['200 DMA', $m?->dma_200],
                    ['Dist from 200 DMA', $m?->distance_from_dma_200_pct, '%'],
                    ['52W Rel. Strength', $m?->rs_52w, '%'],
                    ['Volume Spike', $m?->volume_spike_ratio, 'x'],
                    ['Delivery %', $m?->delivery_pct, '%'],
                ] as [$label, $val, $suffix = ''])
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-mono font-medium">
                            @if($val !== null){{ is_float($val) && $suffix === '' ? number_format($val, 2) : number_format($val, 1).$suffix }}@else — @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Section 5: Score breakdown --}}
        @if($latestScore)
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4">Section 5 — MTF Score Breakdown</h2>
            <div class="space-y-3">
                @foreach([
                    ['Business Quality', $latestScore->business_quality_score],
                    ['Sector Tailwind', $latestScore->sector_tailwind_score],
                    ['Valuation', $latestScore->valuation_score],
                    ['Correction', $latestScore->correction_score],
                    ['Momentum', $latestScore->momentum_score],
                    ['Results Quality', $latestScore->results_quality_score],
                ] as [$label, $score])
                    <div class="flex items-center gap-3">
                        <div class="w-36 text-sm text-slate-400">{{ $label }}</div>
                        <div class="flex-1 bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: {{ $score ?? 0 }}%"></div>
                        </div>
                        <div class="w-12 text-right font-mono text-sm">{{ $score ? number_format($score, 0) : '—' }}</div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Future: Why is this stock falling? --}}
        <section class="bg-slate-900/50 border border-dashed border-slate-700 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-2">Why is this stock falling?</h2>
            <p class="text-sm text-slate-500">
                LLM-powered analysis coming soon — will summarize latest results, concalls, news, and broker actions
                to answer: <em>Is this temporary, or has the business fundamentally deteriorated?</em>
            </p>
        </section>
    </div>
</x-layouts.app>
