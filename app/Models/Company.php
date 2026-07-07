<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'symbol',
        'exchange',
        'market',
        'name',
        'sector',
        'industry',
        'yahoo_symbol',
        'isin',
        'bse_code',
        'is_mtf_eligible',
        'mtf_group',
        'mtf_effective_from',
        'is_active',
        'fundamentals_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_mtf_eligible' => 'boolean',
            'is_active' => 'boolean',
            'mtf_effective_from' => 'date',
            'fundamentals_synced_at' => 'datetime',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(CompanyMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(CompanyMetric::class)->latestOfMany('as_of_date');
    }

    public function metricHistories(): HasMany
    {
        return $this->hasMany(MetricHistory::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function intel(): HasMany
    {
        return $this->hasMany(CompanyIntel::class);
    }

    public function screenerScores(): HasMany
    {
        return $this->hasMany(ScreenerScore::class);
    }

    public function displaySymbol(): string
    {
        return "{$this->symbol}.{$this->exchange}";
    }
}
