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
            ->connectTimeout(config('market_screenr.http.connect_timeout'))
            ->timeout(config('market_screenr.http.timeout'))
            ->retry(2, 500);
    }

    /**
     * Latest ratio snapshot keyed for company_metrics + PE history rows.
     *
     * @return array{
     *     metrics: array<string, float|null>,
     *     pe_history: array<int, array{period: string, value: float}>
     * }
     */
    public function ratioSnapshot(string $ticker, string $frequency = 'Annual', string $period = '10y'): array
    {
        $response = $this->http->get('/statements', [
            'ticker' => $ticker,
            'statement' => 'Ratios',
            'frequency' => $frequency,
            'period' => $period,
            'api_key' => config('market_screenr.businessquant.api_key'),
        ]);

        if ($response->failed()) {
            Log::warning('BusinessQuant ratios failed', [
                'ticker' => $ticker,
                'status' => $response->status(),
                'body' => $response->json('detail') ?? $response->body(),
            ]);

            return ['metrics' => [], 'pe_history' => []];
        }

        return $this->parseNestedStatement($response->json('data', []) ?? []);
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

        $parsed = $this->parseNestedStatement($response->json('data', []) ?? []);

        return $parsed['metrics'];
    }

    /**
     * Parse BusinessQuant nested sections/values into flat metrics.
     *
     * @param  array<string, mixed>  $data
     * @return array{metrics: array<string, float|null>, pe_history: array<int, array{period: string, value: float}>}
     */
    private function parseNestedStatement(array $data): array
    {
        $byDate = [];

        foreach ($data as $category) {
            if (! is_array($category)) {
                continue;
            }

            foreach ($category['sections'] ?? [] as $sectionName => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $field = $this->mapSectionName((string) $sectionName);
                if ($field === null) {
                    continue;
                }

                foreach ($section['values'] ?? [] as $point) {
                    if (! is_array($point)) {
                        continue;
                    }

                    $date = $point['normalizedDate'] ?? $point['date'] ?? null;
                    $raw = $point['reportedValue']['raw'] ?? null;

                    if (! $date || ! is_numeric($raw)) {
                        continue;
                    }

                    $byDate[$date][$field] = (float) $raw;
                }
            }
        }

        // Latest value per field = value on the most recent period date.
        ksort($byDate);
        $latest = [
            'current_pe' => null,
            'current_pb' => null,
            'roe' => null,
            'roce' => null,
            'debt_to_equity' => null,
        ];

        foreach ($byDate as $metrics) {
            foreach ($metrics as $field => $value) {
                $latest[$field] = $value;
            }
        }

        $peHistory = [];
        foreach ($byDate as $date => $metrics) {
            if (! isset($metrics['current_pe'])) {
                continue;
            }

            $peHistory[] = [
                'period' => $date,
                'value' => $metrics['current_pe'],
            ];
        }

        return [
            'metrics' => array_filter($latest, fn ($v) => $v !== null),
            'pe_history' => $peHistory,
        ];
    }

    private function mapSectionName(string $sectionName): ?string
    {
        $normalized = strtolower($sectionName);

        return match (true) {
            str_contains($normalized, 'p/e') || str_contains($normalized, 'price to earnings') => 'current_pe',
            str_contains($normalized, 'p/b') || str_contains($normalized, 'price to book') => 'current_pb',
            str_contains($normalized, 'roce') || str_contains($normalized, 'return on capital employed') => 'roce',
            str_contains($normalized, 'return on equity') || (str_contains($normalized, 'roe') && ! str_contains($normalized, 'roce')) => 'roe',
            str_contains($normalized, 'debt') && str_contains($normalized, 'equity') => 'debt_to_equity',
            default => null,
        };
    }
}
