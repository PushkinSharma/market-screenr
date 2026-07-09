<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenerPreset extends Model
{
    protected $fillable = [
        'name',
        'market',
        'mtf_only',
        'weights',
        'filters',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'filters' => 'array',
            'mtf_only' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(ScreenerScore::class);
    }

    public static function defaultPreset(): self
    {
        $preset = static::query()->firstOrCreate(
            ['is_default' => true, 'market' => 'IN'],
            [
                'name' => 'India Default',
                'mtf_only' => false,
                'weights' => config('market_screenr.default_weights'),
                'filters' => [],
            ],
        );

        // Migrate older installs away from the MTF-centric default label.
        if ($preset->name === 'MTF Default') {
            $preset->update(['name' => 'India Default', 'mtf_only' => false]);
        }

        return $preset->fresh();
    }

    /**
     * @return array<string, float>
     */
    public function normalizedWeights(): array
    {
        $weights = $this->weights ?? config('market_screenr.default_weights');
        $total = array_sum($weights) ?: 100;

        return collect($weights)
            ->map(fn (float|int $w) => ($w / $total) * 100)
            ->all();
    }
}
