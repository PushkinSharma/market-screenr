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

        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => config('market_screenr.nse.user_agent')])
            ->get(config('market_screenr.yahoo.base_url')."/{$symbol}", [
                'interval' => '1d',
                'range' => $range,
            ]);

        if ($response->failed()) {
            Log::warning('Yahoo history failed', ['symbol' => $symbol]);

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
