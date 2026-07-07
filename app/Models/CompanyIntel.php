<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyIntel extends Model
{
    protected $fillable = [
        'company_id',
        'intel_type',
        'published_at',
        'title',
        'raw_content',
        'ai_summary',
        'sentiment',
        'is_temporary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'is_temporary' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
