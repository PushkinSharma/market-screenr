<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NseClient
{
    private ?string $cookie = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEquityList(): array
    {
        $this->warmSession();

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get(config('market_screenr.nse.base_url').'/api/equity-stockIndices', [
                'index' => 'NIFTY TOTAL MARKET',
            ]);

        if ($response->failed()) {
            Log::warning('NSE equity list failed', ['status' => $response->status()]);

            return [];
        }

        return $response->json('data', []) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteEquity(string $symbol): array
    {
        $this->warmSession();

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get(config('market_screenr.nse.base_url')."/api/quote-equity?symbol={$symbol}");

        if ($response->failed()) {
            Log::warning('NSE quote failed', ['symbol' => $symbol, 'status' => $response->status()]);

            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function fetchMtfEligibleSymbols(): array
    {
        // BSE Group I list — MTF eligible securities
        $response = Http::timeout(30)
            ->get('https://www.bseindia.com/markets/equity/EQReports/varmargin.aspx', [
                'flag' => 1,
            ]);

        if ($response->failed()) {
            return [];
        }

        // Parse HTML table for Group I scrips — simplified extraction
        preg_match_all('/>([A-Z0-9&.-]+)<\/td>\s*<td[^>]*>\s*Group\s*I/i', $response->body(), $matches);

        return array_unique($matches[1] ?? []);
    }

    private function warmSession(): void
    {
        if ($this->cookie !== null) {
            return;
        }

        $response = Http::withHeaders([
            'User-Agent' => config('market_screenr.nse.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
        ])->get(config('market_screenr.nse.base_url'));

        $this->cookie = collect($response->headers()['Set-Cookie'] ?? [])
            ->map(fn ($c) => explode(';', $c)[0])
            ->implode('; ');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return array_filter([
            'User-Agent' => config('market_screenr.nse.user_agent'),
            'Accept' => 'application/json',
            'Referer' => config('market_screenr.nse.base_url'),
            'Cookie' => $this->cookie,
        ]);
    }
}
