<?php

namespace App\Console\Commands;

use App\Jobs\SyncIndiaFundamentalsJob;
use App\Models\Company;
use App\Models\CompanyMetric;
use App\Services\FundamentalsSyncService;
use App\Services\ScreenerEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnrichIndiaCommand extends Command
{
    protected $signature = 'screener:enrich
        {--target=100 : Stop when this many India stocks have ROCE filled}
        {--batch=20 : Companies per batch}
        {--sleep=2 : Extra seconds to sleep between batches (on top of per-stock delay)}
        {--max-batches=20 : Safety cap so a runaway loop cannot hammer Screener.in}
        {--skip-scores : Do not recompute scores at the end}';

    protected $description = 'Loop India fundamentals sync in batches until target coverage is reached';

    public function handle(FundamentalsSyncService $sync, ScreenerEngine $engine): int
    {
        $target = max(1, (int) $this->option('target'));
        $batch = max(1, min(50, (int) $this->option('batch')));
        $sleep = max(0, (float) $this->option('sleep'));
        $maxBatches = max(1, (int) $this->option('max-batches'));

        $this->info("Enriching India stocks until {$target} have ROCE (batch={$batch}).");
        $this->line('Tip: leave this running; it skips names already synced in the last day.');
        $this->newLine();

        $started = microtime(true);
        $batchesRun = 0;
        $totalSynced = 0;

        while ($batchesRun < $maxBatches) {
            $withRoce = $this->indiaWithRoceCount();
            $pending = $this->pendingIndiaCount();

            $this->line(sprintf(
                '[%s] coverage=%d/%d  pending_stale=%d  elapsed=%s',
                now()->format('H:i:s'),
                $withRoce,
                $target,
                $pending,
                $this->formatSeconds(microtime(true) - $started),
            ));

            if ($withRoce >= $target) {
                $this->info("Target reached: {$withRoce} India stocks have ROCE.");
                break;
            }

            if ($pending === 0) {
                $this->warn('No more stale India companies to sync. Expand preferred_nse_symbols or wait until tomorrow.');
                break;
            }

            $batchStarted = microtime(true);
            $before = $withRoce;

            (new SyncIndiaFundamentalsJob(limit: $batch))->handle($sync);

            $batchesRun++;
            $after = $this->indiaWithRoceCount();
            $gained = max(0, $after - $before);
            $totalSynced += $batch;
            $batchSeconds = microtime(true) - $batchStarted;
            $perStock = $batchSeconds / $batch;

            $this->line(sprintf(
                '  batch #%d done in %s (≈%.1fs/stock)  +%d ROCE  now=%d',
                $batchesRun,
                $this->formatSeconds($batchSeconds),
                $perStock,
                $gained,
                $after,
            ));

            $remaining = max(0, $target - $after);
            if ($remaining > 0 && $perStock > 0) {
                $etaBatches = (int) ceil($remaining / max(1, $gained ?: $batch));
                $eta = $etaBatches * $batchSeconds;
                $this->line(sprintf('  ETA to target ≈ %s (rough)', $this->formatSeconds($eta)));
            }

            if ($after >= $target) {
                break;
            }

            if ($sleep > 0 && $batchesRun < $maxBatches) {
                $this->line("  sleeping {$sleep}s between batches…");
                sleep((int) ceil($sleep));
            }
        }

        if (! $this->option('skip-scores')) {
            $this->newLine();
            $this->info('Computing scores…');
            $scored = $engine->computeRanksAndScores();
            $this->info("Scored {$scored} companies.");
        }

        $elapsed = microtime(true) - $started;
        $final = $this->indiaWithRoceCount();

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['India with ROCE', $final],
                ['Batches run', $batchesRun],
                ['Approx stocks attempted', $totalSynced],
                ['Wall time', $this->formatSeconds($elapsed)],
                ['Avg per attempted stock', $totalSynced > 0 ? sprintf('%.1fs', $elapsed / $totalSynced) : '—'],
            ],
        );

        if ($final < $target) {
            $this->warn("Stopped at {$final}/{$target}. Re-run: php artisan screener:enrich --target={$target} --batch={$batch}");

            return self::SUCCESS;
        }

        $this->info('Done. Refresh the dashboard.');

        return self::SUCCESS;
    }

    private function indiaWithRoceCount(): int
    {
        $latestIds = CompanyMetric::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('company_id');

        return CompanyMetric::query()
            ->whereIn('id', $latestIds)
            ->whereNotNull('roce')
            ->whereHas('company', fn ($q) => $q->where('market', 'IN')->where('is_active', true))
            ->count();
    }

    private function pendingIndiaCount(): int
    {
        return Company::query()
            ->where('market', 'IN')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('fundamentals_synced_at')
                    ->orWhere('fundamentals_synced_at', '<', now()->subDay());
            })
            ->count();
    }

    private function formatSeconds(float $seconds): string
    {
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $rem = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$rem}s";
        }

        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        return "{$hours}h {$minutes}m";
    }
}
