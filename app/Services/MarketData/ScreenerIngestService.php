<?php

namespace App\Services\MarketData;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ScreenerIngestService
{
    /**
     * Fetch fundamentals from Python sidecar (screener.in scraper).
     *
     * @return array<string, mixed>
     */
    public function fetchCompanyData(Company $company): array
    {
        if (! config('market_screenr.screener_ingest.enabled')) {
            return [];
        }

        $python = config('market_screenr.screener_ingest.python_path');
        $script = config('market_screenr.screener_ingest.script_path');

        if (! file_exists($script)) {
            Log::warning('Screener ingest script missing', ['path' => $script]);

            return [];
        }

        $result = Process::timeout(config('market_screenr.screener_ingest.timeout'))->run([
            $python,
            $script,
            '--symbol',
            $company->symbol,
            '--format',
            'json',
        ]);

        if ($result->failed()) {
            Log::warning('Screener ingest failed', [
                'symbol' => $company->symbol,
                'error' => $result->errorOutput(),
            ]);

            return [];
        }

        $data = json_decode($result->output(), true);

        return is_array($data) ? $data : [];
    }
}
