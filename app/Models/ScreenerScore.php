<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenerScore extends Model
{
    protected $fillable = [
        'screener_preset_id',
        'company_id',
        'computed_at',
        'final_score',
        'business_quality_score',
        'sector_tailwind_score',
        'valuation_score',
        'correction_score',
        'momentum_score',
        'results_quality_score',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'computed_at' => 'date',
        ];
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(ScreenerPreset::class, 'screener_preset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
