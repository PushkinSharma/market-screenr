<?php

namespace App\Jobs;

use App\Services\ScreenerSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunBootstrapSyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public int $limit = 20,
        public bool $refreshMtf = false,
        public bool $includeUs = true,
    ) {}

    public function handle(ScreenerSyncOrchestrator $orchestrator): void
    {
        Log::info('Dashboard bootstrap sync started', [
            'limit' => $this->limit,
            'refresh_mtf' => $this->refreshMtf,
            'include_us' => $this->includeUs,
        ]);

        $orchestrator->runBootstrap($this->limit, $this->refreshMtf, $this->includeUs);
    }
}
