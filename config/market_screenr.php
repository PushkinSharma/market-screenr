<?php

return [

    'businessquant' => [
        'api_key' => env('BUSINESSQUANT_API_KEY'),
        'base_url' => env('BUSINESSQUANT_BASE_URL', 'https://data.businessquant.com'),
    ],

    'fmp' => [
        'api_key' => env('FMP_API_KEY'),
        'base_url' => env('FMP_BASE_URL', 'https://financialmodelingprep.com/api/v3'),
    ],

    'nse' => [
        'base_url' => env('NSE_BASE_URL', 'https://www.nseindia.com'),
        'user_agent' => env('NSE_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'),
    ],

    'yahoo' => [
        'base_url' => env('YAHOO_BASE_URL', 'https://query1.finance.yahoo.com/v8/finance/chart'),
    ],

    'screener_ingest' => [
        'python_path' => env('SCREENER_PYTHON_PATH', 'python3'),
        'script_path' => base_path('services/screener-ingest/ingest.py'),
        'delay_seconds' => (float) env('SCREENER_INGEST_DELAY', 1.5),
    ],

    'default_weights' => [
        'business_quality' => 25,
        'sector_tailwind' => 20,
        'valuation' => 20,
        'correction' => 15,
        'momentum' => 10,
        'results_quality' => 10,
    ],

    'sync' => [
        'india_universe_at' => '20:30',
        'india_fundamentals_at' => '21:00',
        'us_fundamentals_at' => '06:00',
        'mtf_list_day' => 2, // monthly on 2nd
        'scores_at' => '22:30',
    ],

];
