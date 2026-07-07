<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = [
        'source',
        'market',
        'status',
        'records_processed',
        'records_succeeded',
        'message',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function latestFor(string $source): ?self
    {
        return static::query()
            ->where('source', $source)
            ->latest('finished_at')
            ->first();
    }
}
