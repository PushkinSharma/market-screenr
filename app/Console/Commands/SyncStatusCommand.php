<?php

namespace App\Console\Commands;

use App\Services\SyncStatusService;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    protected $signature = 'screener:status';

    protected $description = 'Show data pipeline health, sync times, and diagnostics';

    public function handle(SyncStatusService $status): int
    {
        $stats = $status->dashboardStats();

        $this->info('Market Screenr — Pipeline Status');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total companies', $stats['companies']['total']],
                ['India (NSE)', $stats['companies']['india']],
                ['United States', $stats['companies']['us']],
                ['MTF eligible', $stats['companies']['mtf_eligible']],
                ['With any metrics', $stats['companies']['with_metrics']],
                ['Metrics (latest batch)', $stats['companies']['with_metrics_latest']],
                ['Latest metric date', $stats['dates']['latest_metric'] ?? '—'],
                ['Latest score date', $stats['dates']['latest_score'] ?? '—'],
            ],
        );

        $this->newLine();
        $this->info('Last sync by source:');

        $this->table(
            ['Source', 'Market', 'Status', 'Succeeded', 'Last run', 'Message'],
            $stats['syncs']->map(fn ($s) => [
                $s['label'],
                $s['market'],
                $s['status'],
                $s['records_succeeded'],
                $s['finished_at']?->diffForHumans() ?? 'never',
                \Illuminate\Support\Str::limit($s['message'] ?? '', 50),
            ])->all(),
        );

        if (! empty($stats['diagnostics'])) {
            $this->newLine();
            $this->warn('Issues detected:');
            foreach ($stats['diagnostics'] as $issue) {
                $this->line(" • {$issue}");
            }
        }

        $this->newLine();
        $this->line('Bootstrap on Cloud: php artisan screener:sync --sync');

        return self::SUCCESS;
    }
}
