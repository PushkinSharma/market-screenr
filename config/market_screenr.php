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
        'archive_base_url' => env('NSE_ARCHIVE_BASE_URL', 'https://nsearchives.nseindia.com'),
        'user_agent' => env('NSE_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'),
    ],

    'yahoo' => [
        'base_url' => env('YAHOO_BASE_URL', 'https://query1.finance.yahoo.com/v8/finance/chart'),
    ],

    'http' => [
        'timeout' => (int) env('MARKET_SCREENR_HTTP_TIMEOUT', 8),
        'connect_timeout' => (int) env('MARKET_SCREENR_HTTP_CONNECT_TIMEOUT', 3),
    ],

    'screener_ingest' => [
        'enabled' => (bool) env('SCREENER_INGEST_ENABLED', false),
        'python_path' => env('SCREENER_PYTHON_PATH', 'python3'),
        'script_path' => base_path('services/screener-ingest/ingest.py'),
        'delay_seconds' => (float) env('SCREENER_INGEST_DELAY', 1.5),
        'timeout' => (int) env('SCREENER_INGEST_TIMEOUT', 15),
    ],

    /*
    | Cheapest LLM + web search for personal use: Gemini Flash with Google Search grounding.
    | Get a key at https://aistudio.google.com/apikey (free tier is usually enough).
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // 2.0 Flash was shut down June 2026; 2.5 Flash is the free-tier default.
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        // Visible answer + thinking share this budget; 1200 was truncating briefs.
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 8192),
        // Keep thinking small so most tokens go to the written answer.
        'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 1024),
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
        'bootstrap_company_limit' => (int) env('MARKET_SCREENR_BOOTSTRAP_LIMIT', 20),
        'price_history_years' => (int) env('MARKET_SCREENR_PRICE_HISTORY_YEARS', 1),
        // Measured locally ~4–5s/stock (Screener.in + Yahoo). Used for ETA hints.
        'seconds_per_stock_estimate' => (float) env('MARKET_SCREENR_SECONDS_PER_STOCK', 4.5),
    ],

    /*
    | Symbols to enrich first during local India sync (before alphabetical junk).
    | Add your watchlist here — sync will prioritize these every day.
    */
    /*
    | ~100 liquid / thematic names. screener:enrich prioritizes these before
    | alphabetical fill. Edit freely — this is your daily research universe.
    */
    'preferred_nse_symbols' => [
        // Nifty heavyweights
        'RELIANCE', 'TCS', 'HDFCBANK', 'INFY', 'ICICIBANK', 'HINDUNILVR', 'ITC',
        'SBIN', 'BHARTIARTL', 'KOTAKBANK', 'LT', 'AXISBANK', 'ASIANPAINT', 'MARUTI',
        'TITAN', 'SUNPHARMA', 'WIPRO', 'ULTRACEMCO', 'NESTLEIND', 'BAJFINANCE',
        'POWERGRID', 'NTPC', 'ONGC', 'TATASTEEL', 'JSWSTEEL', 'HCLTECH', 'TECHM',
        'ADANIENT', 'ADANIPORTS', 'BAJAJFINSV', 'BAJAJ-AUTO', 'HEROMOTOCO', 'EICHERMOT',
        'M&M', 'TATAMOTORS', 'INDUSINDBK', 'HDFCLIFE', 'SBILIFE', 'CIPLA', 'DRREDDY',
        'DIVISLAB', 'APOLLOHOSP', 'GRASIM', 'HINDALCO', 'COALINDIA', 'BPCL', 'IOC',
        'BRITANNIA', 'TATACONSUM', 'JINDALSTEL', 'VEDL', 'TRENT', 'DMART', 'PIDILITIND',
        // Cap goods / defence / EMS / power chain (bottleneck themes)
        'BEL', 'HAL', 'BHEL', 'SIEMENS', 'ABB', 'POLYCAB', 'DIXON', 'CGPOWER', 'KEI',
        'APLAPOLLO', 'HAVELLS', 'VOLTAS', 'BLUESTARCO', 'THERMAX', 'CUMMINSIND',
        'SCHAEFFLER', 'TIMKEN', 'SKFINDIA', 'BOSCHLTD', 'MOTHERSON', 'BHARATFORG',
        'SOLARINDS', 'DEEPAKNTR', 'AARTIIND', 'SRF', 'PIIND', 'NAVINFLUOR',
        'LTIM', 'PERSISTENT', 'COFORGE', 'MPHASIS', 'OFSS',
        'INDIGO', 'ZOMATO', 'PAYTM', 'POLICYBZR', 'NYKAA',
        'IRCTC', 'IRFC', 'RECLTD', 'PFC', 'NHPC', 'SJVN',
        'GODREJCP', 'DABUR', 'MARICO', 'COLPAL', 'UNITDSPR',
        'PAGEIND', 'ABBOTINDIA', 'TORNTPHARM', 'LUPIN', 'AUROPHARMA',
        'BANKBARODA', 'PNB', 'CANBK', 'UNIONBANK', 'IDFCFIRSTB',
        'CHOLAFIN', 'MUTHOOTFIN', 'SHRIRAMFIN', 'MFSL',
        'DLF', 'GODREJPROP', 'OBEROIRLTY', 'PRESTIGE', 'LODHA',
        'AMBUJACEM', 'SHREECEM', 'DALBHARAT', 'RAMCOCEM',
        'GAIL', 'PETRONET', 'IGL', 'MGL',
    ],

    /*
    | Fallback when NSE API is unreachable (common on Cloud datacenter IPs).
    | These are liquid NSE large-caps.
    */
    'fallback_nse_symbols' => [
        ['symbol' => 'RELIANCE', 'name' => 'Reliance Industries'],
        ['symbol' => 'TCS', 'name' => 'Tata Consultancy Services'],
        ['symbol' => 'HDFCBANK', 'name' => 'HDFC Bank'],
        ['symbol' => 'INFY', 'name' => 'Infosys'],
        ['symbol' => 'ICICIBANK', 'name' => 'ICICI Bank'],
        ['symbol' => 'HINDUNILVR', 'name' => 'Hindustan Unilever'],
        ['symbol' => 'ITC', 'name' => 'ITC'],
        ['symbol' => 'SBIN', 'name' => 'State Bank of India'],
        ['symbol' => 'BHARTIARTL', 'name' => 'Bharti Airtel'],
        ['symbol' => 'KOTAKBANK', 'name' => 'Kotak Mahindra Bank'],
        ['symbol' => 'LT', 'name' => 'Larsen & Toubro'],
        ['symbol' => 'AXISBANK', 'name' => 'Axis Bank'],
        ['symbol' => 'ASIANPAINT', 'name' => 'Asian Paints'],
        ['symbol' => 'MARUTI', 'name' => 'Maruti Suzuki'],
        ['symbol' => 'TITAN', 'name' => 'Titan Company'],
        ['symbol' => 'SUNPHARMA', 'name' => 'Sun Pharmaceutical'],
        ['symbol' => 'WIPRO', 'name' => 'Wipro'],
        ['symbol' => 'ULTRACEMCO', 'name' => 'UltraTech Cement'],
        ['symbol' => 'NESTLEIND', 'name' => 'Nestle India'],
        ['symbol' => 'BAJFINANCE', 'name' => 'Bajaj Finance'],
    ],

    'fallback_us_symbols' => [
        ['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'sector' => 'Technology'],
        ['symbol' => 'MSFT', 'name' => 'Microsoft Corp.', 'sector' => 'Technology'],
        ['symbol' => 'GOOGL', 'name' => 'Alphabet Inc.', 'sector' => 'Technology'],
        ['symbol' => 'AMZN', 'name' => 'Amazon.com Inc.', 'sector' => 'Consumer'],
        ['symbol' => 'NVDA', 'name' => 'NVIDIA Corp.', 'sector' => 'Technology'],
        ['symbol' => 'META', 'name' => 'Meta Platforms', 'sector' => 'Technology'],
        ['symbol' => 'BRK-B', 'name' => 'Berkshire Hathaway', 'sector' => 'Financials'],
        ['symbol' => 'JPM', 'name' => 'JPMorgan Chase', 'sector' => 'Financials'],
        ['symbol' => 'V', 'name' => 'Visa Inc.', 'sector' => 'Financials'],
        ['symbol' => 'JNJ', 'name' => 'Johnson & Johnson', 'sector' => 'Healthcare'],
    ],

];
