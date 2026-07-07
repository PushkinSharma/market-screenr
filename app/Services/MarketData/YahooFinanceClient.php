<?php

namespace App\Services\MarketData;

use App\Models\Company;
use App\Models\PriceHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YahooFinanceClient
{
    /**
     * @return array<int, array{date: string, open: float|null, high: float|null, low: float|null, close: float|null, volume: int|null}>
     */
    public function history(Company $company, int $years = 10): array
    {
        $symbol = $company->yahoo_symbol ?? $this->resolveYahooSymbol($company);
        $range = match (true) {
            $years >= 10 => '10y',
            $years >= 5 => '5y',
            default => '1y',
        };

        try {
            $response = Http::connectTimeout(config('market_screenr.http.connect_timeout'))
                ->timeout(config('market_screenr.http.timeout'))
                ->withHeaders(['User-Agent' => config('market_screenr.nse.user_agent')])
                ->get(config('market_screenr.yahoo.base_url')."/{$symbol}", [
                    'interval' => '1d',
                    'range' => $range,
                ]);

            if ($response->failed()) {
                Log::warning('Yahoo history failed', ['symbol' => $symbol, 'status' => $response->status()]);

                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('Yahoo history exception', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return [];
        }

        $result = $response->json('chart.result.0', []);
        $timestamps = $result['timestamp'] ?? [];
        $quotes = $result['indicators']['quote'][0] ?? [];

        $bars = [];
        foreach ($timestamps as $i => $ts) {
            $bars[] = [
                'date' => Carbon::createFromTimestamp($ts)->toDateString(),
                'open' => $quotes['open'][$i] ?? null,
                'high' => $quotes['high'][$i] ?? null,
                'low' => $quotes['low'][$i] ?? null,
                'close' => $quotes['close'][$i] ?? null,
                'volume' => $quotes['volume'][$i] ?? null,
            ];
        }

        return $bars;
    }

    /**
     * Lightweight valuation snapshot from Yahoo chart meta (works on Cloud when NSE is blocked).
     *
     * @return array<string, float>
     */
    public function fetchValuationSnapshot(Company $company): array
    {
        $symbol = $company->yahoo_symbol ?? $this->resolveYahooSymbol($company);

        try {
            $response = Http::connectTimeout(config('market_screenr.http.connect_timeout'))
                ->timeout(config('market_screenr.http.timeout'))
                ->withHeaders(['User-Agent' => config('market_screenr.nse.user_agent')])
                ->get(config('market_screenr.yahoo.base_url')."/{$symbol}", [
                    'interval' => '1d',
                    'range' => '5d',
                ]);

            if ($response->failed()) {
                return [];
            }

            $meta = $response->json('chart.result.0.meta', []);

            return array_filter([
                'current_price' => isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null,
                'current_pe' => isset($meta['trailingPE']) && is_numeric($meta['trailingPE']) ? (float) $meta['trailingPE'] : null,
                'current_pb' => isset($meta['priceToBook']) && is_numeric($meta['priceToBook']) ? (float) $meta['priceToBook'] : null,
                'week_52_high' => isset($meta['fiftyTwoWeekHigh']) ? (float) $meta['fiftyTwoWeekHigh'] : null,
                'week_52_low' => isset($meta['fiftyTwoWeekLow']) ? (float) $meta['fiftyTwoWeekLow'] : null,
            ], fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            Log::warning('Yahoo valuation snapshot failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function syncPriceHistory(Company $company, int $years = 10): int
    {
        $bars = $this->history($company, $years);
        $count = 0;

        foreach ($bars as $bar) {
            if ($bar['close'] === null) {
                continue;
            }

            PriceHistory::query()->updateOrCreate(
                ['company_id' => $company->id, 'trade_date' => $bar['date']],
                [
                    'open' => $bar['open'],
                    'high' => $bar['high'],
                    'low' => $bar['low'],
                    'close' => $bar['close'],
                    'volume' => $bar['volume'],
                ],
            );
            $count++;
        }

        return $count;
    }

    private function resolveYahooSymbol(Company $company): string
    {
        return match ($company->market) {
            'IN' => "{$company->symbol}.NS",
            default => $company->symbol,
        };
    }
}
