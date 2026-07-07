<?php

use App\Enums\ScoreComponent;
use App\Models\ScreenerPreset;
use App\Jobs\ComputeScreenerScoresJob;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.app', ['title' => 'Score Weights'])] class extends Component
{
    public ?int $presetId = null;
    public string $name = 'MTF Default';
    public string $market = 'IN';
    public bool $mtfOnly = true;

    /** @var array<string, int> */
    public array $weights = [];

    public function mount(): void
    {
        $preset = ScreenerPreset::defaultPreset();
        $this->loadPreset($preset);
    }

    public function loadPreset(ScreenerPreset $preset): void
    {
        $this->presetId = $preset->id;
        $this->name = $preset->name;
        $this->market = $preset->market;
        $this->mtfOnly = $preset->mtf_only;
        $this->weights = $preset->weights ?? config('market_screenr.default_weights');
    }

    public function save(): void
    {
        $total = array_sum($this->weights);
        if ($total !== 100) {
            $this->addError('weights', "Weights must sum to 100 (currently {$total})");
            return;
        }

        $preset = ScreenerPreset::query()->updateOrCreate(
            ['id' => $this->presetId],
            [
                'name' => $this->name,
                'market' => $this->market,
                'mtf_only' => $this->mtfOnly,
                'weights' => $this->weights,
                'is_default' => true,
            ],
        );

        $this->presetId = $preset->id;
        ComputeScreenerScoresJob::dispatch();

        session()->flash('saved', 'Preset saved. Recomputing scores...');
    }

    public function with(): array
    {
        return [
            'components' => ScoreComponent::cases(),
            'weightTotal' => array_sum($this->weights),
        ];
    }
};
?>

<div class="max-w-2xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold">MTF Score Weights</h1>
            <p class="text-slate-400 text-sm mt-1">Assign weights to each scoring component. Must total 100%.</p>
        </div>

        @if(session('saved'))
            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-lg px-4 py-3 text-sm">
                {{ session('saved') }}
            </div>
        @endif

        @error('weights')
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 rounded-lg px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-6">
            <div>
                <label class="block text-sm text-slate-400 mb-1">Preset Name</label>
                <input wire:model="name" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" />
            </div>

            @foreach($components as $component)
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="text-sm font-medium">{{ $component->label() }}</label>
                        <span class="text-emerald-400 font-mono text-sm">{{ $weights[$component->value] ?? 0 }}%</span>
                    </div>
                    <input
                        type="range"
                        min="0"
                        max="50"
                        step="5"
                        wire:model.live="weights.{{ $component->value }}"
                        class="w-full accent-emerald-500"
                    />
                    <p class="text-xs text-slate-500 mt-1">
                        @php $metrics = $component->metrics(); @endphp
                        Based on: {{ implode(', ', array_keys($metrics)) }}
                    </p>
                </div>
            @endforeach

            <div class="flex items-center justify-between pt-4 border-t border-slate-800">
                <div class="text-sm {{ $weightTotal === 100 ? 'text-emerald-400' : 'text-amber-400' }}">
                    Total: {{ $weightTotal }}%
                </div>
                <button
                    wire:click="save"
                    class="bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-2 rounded-lg text-sm font-medium transition"
                >
                    Save & Recompute
                </button>
            </div>
        </div>

        {{-- Component explanation --}}
        <div class="bg-slate-900/50 border border-slate-800 rounded-xl p-6 space-y-4 text-sm text-slate-400">
            <h3 class="text-white font-medium">How scoring works</h3>
            <ul class="space-y-2 list-disc list-inside">
                <li><strong class="text-slate-300">Business Quality</strong> — ROCE, ROE, low debt, promoter holding</li>
                <li><strong class="text-slate-300">Sector Tailwind</strong> — Relative strength & revenue growth vs peers</li>
                <li><strong class="text-slate-300">Valuation</strong> — Current P/E vs historical percentile ("Is it cheap?")</li>
                <li><strong class="text-slate-300">Correction</strong> — Drawdown from ATH, price position in 10y range</li>
                <li><strong class="text-slate-300">Momentum</strong> — Distance from 200 DMA, volume, delivery % (confirmation only)</li>
                <li><strong class="text-slate-300">Results Quality</strong> — Profit/revenue CAGR, FCF, FII buying trend</li>
            </ul>
        </div>
</div>
