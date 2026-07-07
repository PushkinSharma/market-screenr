<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BusinessQuantClient
{
    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl(config('market_screenr.businessquant.base_url'))
            ->timeout(30)
            ->retry(2, 500);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ratios(string $ticker, string $frequency = 'Annual', string $period = '10y'): array
    {
        $response = $this->http->get('/statements', [
            'ticker' => $ticker,
            'statement' => 'Ratios',
            'frequency' => $frequency,
            'period' => $period,
            'api_key' => config('market_screenr.businessquant.api_key'),
        ]);

        if ($response->failed()) {
            Log::warning('BusinessQuant ratios failed', ['ticker' => $ticker, 'status' => $response->status()]);

            return [];
        }

        return $response->json('data', []) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function keyMetrics(string $ticker): array
    {
        $response = $this->http->get('/statements', [
            'ticker' => $ticker,
            'statement' => 'Growth',
            'frequency' => 'Annual',
            'period' => '5y',
            'api_key' => config('market_screenr.businessquant.api_key'),
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('data', []) ?? [];
    }
}
