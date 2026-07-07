<?php

namespace App\Services;

use App\Models\SyncRun;

class SyncRunRecorder
{
    private float $startedAt;

    public function __construct(
        private string $source,
        private ?string $market = null,
    ) {
        $this->startedAt = microtime(true);
    }

    public function finish(string $status, int $processed, int $succeeded, ?string $message = null, array $metadata = []): SyncRun
    {
        return SyncRun::query()->create([
            'source' => $this->source,
            'market' => $this->market,
            'status' => $status,
            'records_processed' => $processed,
            'records_succeeded' => $succeeded,
            'message' => $message,
            'metadata' => $metadata,
            'started_at' => now()->subMilliseconds((int) ((microtime(true) - $this->startedAt) * 1000)),
            'finished_at' => now(),
        ]);
    }
}
