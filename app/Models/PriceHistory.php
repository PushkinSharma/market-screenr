<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    protected $fillable = [
        'company_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'delivery_pct',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
