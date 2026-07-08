<?php

namespace App\Services\MarketData;

use App\Models\Company;
use App\Models\PriceHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NseArchiveClient
{
    /** @var array{date: string|null, rows: array<int, array<string, mixed>>}|null */
    private ?array $latestCache = null;

    /**
     * @return array{date: string|null, rows: array<int, array<string, mixed>>}
     */
    public function latestEquityBhavcopy(int $lookbackDays = 10): array
    {
        if ($this->latestCache !== null) {
            return $this->latestCache;
        }

        for ($date = now()->subDay()->startOfDay(), $i = 0; $i < $lookbackDays; $date->subDay(), $i++) {
            if ($date->isWeekend()) {
                continue;
            }

            $rows = $this->equityBhavcopy($date);
            if (! empty($rows)) {
                return $this->latestCache = [
                    'date' => $date->toDateString(),
                    'rows' => $rows,
                ];
            }
        }

        return $this->latestCache = ['date' => null, 'rows' => []];
    }

    public function syncLatestPriceHistory(Company $company): bool
    {
        if ($company->market !== 'IN') {
            return false;
        }

        $archive = $this->latestEquityBhavcopy();
        $row = collect($archive['rows'])->firstWhere('symbol', $company->symbol);

        if (! $row || ! $row['trade_date'] || $row['close'] === null) {
            return false;
        }

        PriceHistory::query()->updateOrCreate(
            ['company_id' => $company->id, 'trade_date' => $row['trade_date']],
            [
                'open' => $row['open'],
                'high' => $row['high'],
                'low' => $row['low'],
                'close' => $row['close'],
                'volume' => $row['volume'],
                'delivery_pct' => $row['delivery_pct'],
            ],
        );

        return true;
    }

    /**
     * Fetch NSE CM UDiFF bhavcopy. This is an archive file, so it is much more
     * reliable for Cloud ETL than live NSE JSON endpoints.
     *
     * @return array<int, array<string, mixed>>
     */
    public function equityBhavcopy(Carbon $date): array
    {
        if (! class_exists(\ZipArchive::class)) {
            Log::warning('NSE archive bhavcopy skipped: PHP zip extension is unavailable.');

            return [];
        }

        $url = rtrim(config('market_screenr.nse.archive_base_url'), '/')
            .'/content/cm/BhavCopy_NSE_CM_0_0_0_'.$date->format('Ymd').'_F_0000.csv.zip';

        try {
            $response = Http::connectTimeout(config('market_screenr.http.connect_timeout'))
                ->timeout(config('market_screenr.http.timeout'))
                ->withHeaders(['User-Agent' => config('market_screenr.nse.user_agent')])
                ->get($url);

            if ($response->failed()) {
                Log::info('NSE archive bhavcopy unavailable', [
                    'date' => $date->toDateString(),
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $this->parseZipCsv($response->body());
        } catch (\Throwable $e) {
            Log::warning('NSE archive bhavcopy exception', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseZipCsv(string $zipBytes): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'nse-bhavcopy-');
        if ($zipPath === false) {
            return [];
        }

        file_put_contents($zipPath, $zipBytes);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);

            return [];
        }

        $csv = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name && str_ends_with(strtolower($name), '.csv')) {
                $csv = (string) $zip->getFromIndex($i);
                break;
            }
        }

        $zip->close();
        @unlink($zipPath);

        if ($csv === '') {
            return [];
        }

        return $this->parseCsv($csv);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $line);
            if (! is_array($row)) {
                continue;
            }

            $symbol = $row['symbol'] ?? $row['tckrsymb'] ?? null;
            $series = $row['series'] ?? $row['sctysrs'] ?? 'EQ';

            if (! $symbol || ! in_array($series, ['EQ', 'BE'], true)) {
                continue;
            }

            $rows[] = [
                'symbol' => trim((string) $symbol),
                'companyName' => trim((string) ($row['securityname'] ?? $row['fininstrmnm'] ?? $symbol)),
                'industry' => $row['industry'] ?? null,
                'trade_date' => $this->parseDate($row['date1'] ?? $row['trad_dt'] ?? $row['trad_dt_tm'] ?? null),
                'open' => $this->parseFloat($row['open_price'] ?? $row['opnpric'] ?? null),
                'high' => $this->parseFloat($row['high_price'] ?? $row['hghpric'] ?? null),
                'low' => $this->parseFloat($row['low_price'] ?? $row['lwpric'] ?? null),
                'close' => $this->parseFloat($row['close_price'] ?? $row['clspric'] ?? $row['last_price'] ?? null),
                'volume' => $this->parseInt($row['ttl_trd_qnty'] ?? $row['ttltradgvol'] ?? null),
                'delivery_pct' => $this->parseFloat($row['deliv_per'] ?? $row['dlvryqty_pct'] ?? null),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $header)));
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        $clean = str_replace([',', '%'], '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function parseInt(mixed $value): ?int
    {
        $float = $this->parseFloat($value);

        return $float === null ? null : (int) $float;
    }

    private function parseDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
