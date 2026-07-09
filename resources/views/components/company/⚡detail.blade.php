<?php

use App\Models\Company;
use App\Models\MetricHistory;
use App\Models\PriceHistory;
use App\Models\ScreenerPreset;
use App\Models\ScreenerScore;
use App\Services\Llm\StockBriefingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Company $company;

    public string $preferences = "I want multi-year holds. Prefer high/improving ROCE, reasonable valuation vs peers, and clear catalysts. Flag value traps.";
    public ?string $briefing = null;
    public ?string $briefingError = null;
    public ?string $briefingModel = null;
    public bool $briefingGrounded = false;
    public bool $briefingTruncated = false;
    public bool $briefingLoading = false;
    public bool $showPayload = false;
    public string $payloadPreview = '';

    public function mount(Company $company, StockBriefingService $briefings): void
    {
        $this->company = $company->load(['latestMetric', 'intel' => fn ($q) => $q->latest('published_at')->limit(5)]);
        $this->payloadPreview = json_encode($briefings->buildContext($this->company), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function runBriefing(StockBriefingService $briefings): void
    {
        $this->briefing = null;
        $this->briefingError = null;
        $this->briefingTruncated = false;
        $this->briefingLoading = true;
        $this->payloadPreview = json_encode($briefings->buildContext($this->company), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';

        try {
            if (! $briefings->enabled()) {
                throw new \RuntimeException('Set GEMINI_API_KEY in .env (free key from https://aistudio.google.com/apikey), then php artisan config:clear');
            }

            $result = $briefings->brief($this->company, $this->preferences);
            $this->briefing = $result['text'];
            $this->briefingModel = $result['model'];
            $this->briefingGrounded = $result['grounded'];
            $this->briefingTruncated = (bool) ($result['truncated'] ?? false);
        } catch (\Throwable $e) {
            $this->briefingError = $e->getMessage();
        } finally {
            $this->briefingLoading = false;
        }
    }

    public function togglePayload(): void
    {
        $this->showPayload = ! $this->showPayload;
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

        $prices = PriceHistory::query()
            ->where('company_id', $this->company->id)
            ->orderBy('trade_date')
            ->get(['trade_date', 'close']);

        // Downsample long series for chart readability
        if ($prices->count() > 400) {
            $step = (int) ceil($prices->count() / 300);
            $prices = $prices->values()->filter(fn ($_, $i) => $i % $step === 0)->values();
        }

        $latestScore = $this->company->screenerScores()
            ->latest('computed_at')
            ->first();

        $nav = $this->neighborCompanies($latestScore);

        return [
            'metric' => $this->company->latestMetric,
            'peHistory' => $peHistory,
            'roceHistory' => $roceHistory,
            'latestScore' => $latestScore,
            'prevCompany' => $nav['prev'],
            'nextCompany' => $nav['next'],
            'llmEnabled' => filled(config('market_screenr.gemini.api_key')),
            'priceChart' => [
                'labels' => $prices->pluck('trade_date')->map(fn ($d) => $d->format('Y-m-d'))->toArray(),
                'values' => $prices->pluck('close')->map(fn ($v) => round((float) $v, 2))->toArray(),
            ],
            'peChart' => [
                'labels' => $peHistory->pluck('period_date')->map(fn ($d) => $d->format('Y-m'))->toArray(),
                'values' => $peHistory->pluck('value')->map(fn ($v) => round((float) $v, 2))->toArray(),
            ],
            'roceChart' => [
                'labels' => $roceHistory->pluck('period_date')->map(fn ($d) => $d->format('Y'))->toArray(),
                'values' => $roceHistory->pluck('value')->map(fn ($v) => round((float) $v, 2))->toArray(),
            ],
        ];
    }

    /**
     * @return array{prev: ?Company, next: ?Company}
     */
    private function neighborCompanies(?ScreenerScore $latestScore): array
    {
        $preset = ScreenerPreset::defaultPreset();
        $date = $latestScore?->computed_at
            ?? ScreenerScore::query()->where('screener_preset_id', $preset->id)->max('computed_at');

        if (! $date) {
            return ['prev' => null, 'next' => null];
        }

        $ordered = ScreenerScore::query()
            ->where('screener_preset_id', $preset->id)
            ->where('computed_at', $date)
            ->whereHas('company', fn ($q) => $q->where('market', 'IN')->where('is_active', true))
            ->orderBy('rank')
            ->with('company')
            ->get();

        $index = $ordered->search(fn (ScreenerScore $s) => $s->company_id === $this->company->id);

        if ($index === false) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $index > 0 ? $ordered[$index - 1]->company : null,
            'next' => $index < $ordered->count() - 1 ? $ordered[$index + 1]->company : null,
        ];
    }
};
?>

<div class="space-y-8" x-data x-on:keydown.window.left="window.__msPrev && (window.location = window.__msPrev)" x-on:keydown.window.right="window.__msNext && (window.location = window.__msNext)">
    @php $m = $metric; @endphp
    <script>
        window.__msPrev = @json($prevCompany ? route('company', $prevCompany) : null);
        window.__msNext = @json($nextCompany ? route('company', $nextCompany) : null);
    </script>

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <a href="{{ route('screener') }}" class="text-slate-500 hover:text-slate-300 text-sm">← Dashboard</a>
                    <h1 class="text-2xl font-bold">{{ $company->symbol }}</h1>
                    @if($company->is_mtf_eligible)
                        <span class="bg-emerald-500/20 text-emerald-400 text-xs px-2 py-0.5 rounded-full">MTF</span>
                    @endif
                </div>
                <p class="text-slate-400">{{ $company->name }} · {{ $company->sector ?: '—' }}</p>
                <p class="text-xs text-slate-600 mt-1">← → keys jump prev/next ranked stock</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex gap-2">
                    @if($prevCompany)
                        <a href="{{ route('company', $prevCompany) }}" class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-sm text-slate-300 hover:border-emerald-500/40">← {{ $prevCompany->symbol }}</a>
                    @endif
                    @if($nextCompany)
                        <a href="{{ route('company', $nextCompany) }}" class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-sm text-slate-300 hover:border-emerald-500/40">{{ $nextCompany->symbol }} →</a>
                    @endif
                </div>
                @if($latestScore)
                    <div class="bg-slate-900 border border-slate-800 rounded-xl px-6 py-4 text-center">
                        <div class="text-3xl font-bold text-emerald-400">{{ number_format($latestScore->final_score, 0) }}</div>
                        <div class="text-xs text-slate-500">Score / 100 · Rank #{{ $latestScore->rank }}</div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Price chart --}}
        @if(count($priceChart['values']) > 1)
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-1">Price</h2>
            <p class="text-sm text-slate-500 mb-4">Daily close (Yahoo / NSE)</p>
            <div
                wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        if (typeof ApexCharts === 'undefined') return;
                        this.chart = new ApexCharts(this.$refs.price, {
                            chart: { type: 'area', height: 280, background: 'transparent', foreColor: '#94a3b8', toolbar: { show: false }, zoom: { enabled: true } },
                            series: [{ name: 'Close', data: @js($priceChart['values']) }],
                            xaxis: { categories: @js($priceChart['labels']), labels: { show: false }, axisBorder: { show: false } },
                            yaxis: { labels: { formatter: (v) => v?.toFixed(0) } },
                            stroke: { curve: 'smooth', width: 2 },
                            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
                            colors: ['#34d399'],
                            grid: { borderColor: '#1e293b' },
                            dataLabels: { enabled: false },
                            tooltip: { x: { format: 'dd MMM yyyy' } },
                        });
                        this.chart.render();
                    }
                }"
            >
                <div x-ref="price" class="h-72"></div>
            </div>
        </section>
        @endif

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
                <div
                    class="mt-6"
                    wire:ignore
                    x-data="{
                        init() {
                            if (typeof ApexCharts === 'undefined') return;
                            new ApexCharts(this.$refs.pe, {
                                chart: { type: 'line', height: 240, background: 'transparent', foreColor: '#94a3b8', toolbar: { show: false } },
                                series: [{ name: 'P/E', data: @js($peChart['values']) }],
                                xaxis: { categories: @js($peChart['labels']) },
                                stroke: { curve: 'smooth', width: 2 },
                                colors: ['#34d399'],
                                grid: { borderColor: '#1e293b' },
                                dataLabels: { enabled: false },
                            }).render();
                        }
                    }"
                >
                    <div x-ref="pe" class="h-60"></div>
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
            @if(count($roceChart['values']) > 1)
                <div
                    class="mt-6"
                    wire:ignore
                    x-data="{
                        init() {
                            if (typeof ApexCharts === 'undefined') return;
                            new ApexCharts(this.$refs.roce, {
                                chart: { type: 'line', height: 240, background: 'transparent', foreColor: '#94a3b8', toolbar: { show: false } },
                                series: [{ name: 'ROCE %', data: @js($roceChart['values']) }],
                                xaxis: { categories: @js($roceChart['labels']) },
                                stroke: { curve: 'smooth', width: 2 },
                                colors: ['#60a5fa'],
                                grid: { borderColor: '#1e293b' },
                                dataLabels: { enabled: false },
                            }).render();
                        }
                    }"
                >
                    <p class="text-sm text-slate-500 mb-2">ROCE history</p>
                    <div x-ref="roce" class="h-60"></div>
                </div>
            @endif
        </section>

        {{-- Section 4: Momentum --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-1">Section 4 — Momentum</h2>
            <p class="text-sm text-slate-500 mb-4">Confirmation, not trading signals</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['50 DMA', $m?->dma_50, 'price'],
                    ['100 DMA', $m?->dma_100, 'price'],
                    ['200 DMA', $m?->dma_200, 'price'],
                    ['Dist from 200 DMA', $m?->distance_from_dma_200_pct, '%'],
                    ['52W Rel. Strength', $m?->rs_52w, '%'],
                    ['Volume Spike', $m?->volume_spike_ratio, 'x'],
                    ['Delivery %', $m?->delivery_pct, '%'],
                ] as [$label, $val, $suffix])
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-mono font-medium">
                            @if($val !== null)
                                {{ $suffix === 'price' ? number_format((float) $val, 2) : number_format((float) $val, 1).$suffix }}
                            @else — @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Section 5: Score breakdown --}}
        @if($latestScore)
        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4">Section 5 — Score Breakdown</h2>
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

        <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            <div>
                <h2 class="text-lg font-semibold mb-1">AI briefing</h2>
                <p class="text-sm text-slate-500">
                    Sends <strong class="text-slate-400">only this stock’s</strong> metrics + your preferences (not the full dashboard).
                    Gemini can also Google-search recent news/results.
                </p>
            </div>

            <label class="block space-y-1">
                <span class="text-xs text-slate-500">Your preferences for this name</span>
                <textarea
                    wire:model="preferences"
                    rows="3"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500/40 outline-none"
                ></textarea>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    wire:click="runBriefing"
                    wire:loading.attr="disabled"
                    class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="runBriefing">Analyze with web search</span>
                    <span wire:loading wire:target="runBriefing">Thinking…</span>
                </button>
                <button
                    type="button"
                    wire:click="togglePayload"
                    class="px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-xs text-slate-400 hover:text-slate-200"
                >
                    {{ $showPayload ? 'Hide' : 'Show' }} JSON sent to Gemini
                </button>
                @if(! $llmEnabled)
                    <span class="text-xs text-amber-400">Add <code class="text-amber-300">GEMINI_API_KEY</code> to .env</span>
                @elseif($briefingModel)
                    <span class="text-xs text-slate-500">
                        {{ $briefingModel }}
                        @if($briefingGrounded) · web-grounded @endif
                        @if($briefingTruncated) · truncated @endif
                    </span>
                @endif
            </div>

            @if($showPayload)
                <pre class="rounded-lg border border-slate-700 bg-slate-950/80 px-3 py-3 text-[11px] text-slate-400 overflow-x-auto max-h-72">{{ $payloadPreview }}</pre>
            @endif

            @if($briefingError)
                <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $briefingError }}</div>
            @endif

            @if($briefingTruncated)
                <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
                    Answer hit the token limit. Raise <code class="text-amber-100">GEMINI_MAX_OUTPUT_TOKENS</code> or lower <code class="text-amber-100">GEMINI_THINKING_BUDGET</code> in `.env`, then <code class="text-amber-100">php artisan config:clear</code>.
                </div>
            @endif

            @if($briefing)
                <div class="briefing-md rounded-lg border border-slate-700 bg-slate-800/40 px-5 py-4 text-sm text-slate-200 leading-relaxed">
                    {!! \Illuminate\Support\Str::markdown($briefing, [
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ]) !!}
                </div>
            @endif
        </section>
</div>
