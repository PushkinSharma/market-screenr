<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricHistory extends Model
{
    protected $fillable = [
        'company_id',
        'metric_key',
        'period_date',
        'period_type',
        'value',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'value' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
