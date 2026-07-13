# Market Screenr

A personal stock screener for **Indian (NSE)** and **US** markets.

It pulls fundamentals and prices, ranks stocks with a custom weighted score, and shows valuation / drawdown / momentum on a simple dashboard. Optional AI briefings (Gemini) can add recent news context on a company page.

Built with Laravel + Livewire. India is the main focus today; US support works but is thinner.

> Not investment advice. Data comes from public sources and scrapers — treat numbers as research inputs, not gospel.

## What you get

- **Ranked screener** — score stocks on 6 buckets you can re-weight (quality, sector trend, valuation, drawdown, momentum, results)
- **Company page** — key ratios, drawdown vs highs/lows, moving averages, score breakdown, charts
- **Weight presets** — tweak what matters to you and recompute ranks
- **Daily sync jobs** — refresh universe, fundamentals, MTF list, and scores
- **Optional AI briefing** — Gemini Flash + web search on a company page

## Requirements

- PHP 8.3+
- Composer
- Node.js 20+ (for Vite / Tailwind)
- SQLite (default) or Postgres
- Python 3.9+ (only if you want India fundamentals from Screener.in)

API keys are optional depending on what you sync:

| Key | Needed for |
|-----|------------|
| _(none)_ | Local demo seed + Yahoo prices |
| `GEMINI_API_KEY` | AI company briefings |
| `BUSINESSQUANT_API_KEY` | US fundamentals |
| `FMP_API_KEY` | US price backup (optional) |

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite (easiest local setup)
touch database/database.sqlite

php artisan migrate
php artisan db:seed                  # small demo universe
php artisan screener:compute-scores  # rank the demo set

npm install && npm run build
php artisan serve
```

Open [http://localhost:8000](http://localhost:8000).

For live India data instead of (or after) the demo seed:

```bash
# Python deps for Screener.in scrape
cd services/screener-ingest && pip install -r requirements.txt && cd ../..

# Pull ~20 India names (prices + fundamentals), then score
php artisan screener:sync --sync --india-only --limit=20
php artisan screener:compute-scores
```

Leave this running overnight if you want a larger set:

```bash
php artisan screener:enrich --target=100 --batch=20
```

Be polite to Screener.in (`SCREENER_INGEST_DELAY=1.5` in `.env`). Rough local pace: ~4–5 seconds per stock.

## Useful commands

```bash
# One stock
php artisan screener:sync RELIANCE --market=IN

# Batch India sync (inline)
php artisan screener:sync --sync --india-only --limit=20

# Grow coverage until N stocks have ROCE
php artisan screener:enrich --target=100 --batch=20

# Recompute ranks after changing weights
php artisan screener:compute-scores

# Pipeline health (counts, last syncs)
php artisan screener:status
```

Dev servers (app + queue + Vite) in one go:

```bash
composer run dev
```

## How scoring works

Each stock gets a 0–100 score from six parts. Default weights:

| Part | Default | Roughly based on |
|------|---------|------------------|
| Business quality | 25% | ROCE, ROE, debt, interest cover, promoter holding |
| Sector trend | 20% | 52-week return, revenue growth |
| Valuation | 20% | P/E, P/B, cheapness vs peers |
| Correction | 15% | Distance from highs / long-range price position |
| Momentum | 10% | vs 200-day average, volume |
| Results quality | 10% | Profit/revenue growth, FCF, FII flow |

Change weights at `/preset`. Scores are **relative ranks within your synced universe**, not absolute “buy” grades.

## Data sources

| Market | Source | Used for |
|--------|--------|----------|
| India | NSE public endpoints | Symbol list, quotes |
| India | Screener.in (Python script) | ROCE, P/E, shareholding, etc. |
| India | Yahoo Finance (`.NS`) | Price history, moving averages |
| India | BSE Group I list | MTF eligibility flag |
| US | BusinessQuant | Fundamentals |
| US | Yahoo / FMP | Prices |

India fundamentals depend on scraping Screener.in. That can break if their HTML changes, and it is not an official API.

## Optional: AI briefings

1. Get a key from [Google AI Studio](https://aistudio.google.com/apikey)
2. Set in `.env`:

```bash
GEMINI_API_KEY=your_key
GEMINI_MODEL=gemini-2.5-flash
```

3. Open a company page → **Analyze with web search**

## Deploy (Laravel Cloud or similar)

1. Connect the GitHub repo
2. Add a Postgres database
3. Turn on **Scheduler** and a **queue worker**
4. Set env vars you need (`GEMINI_API_KEY`, `BUSINESSQUANT_API_KEY`, …)
5. Ensure Python + `services/screener-ingest` deps exist on the worker if you sync India fundamentals

Scheduled jobs (IST unless noted):

| Job | When | What |
|-----|------|------|
| India universe | Daily 20:30 | NSE symbols |
| India fundamentals | Daily 21:00 | Screener.in batch |
| MTF list | Monthly (2nd) | BSE Group I |
| US fundamentals | Daily 06:00 ET | BusinessQuant |
| Scores | Daily 22:30 | Recompute ranks |

## Project layout

```
app/Services/          Scoring, sync, metric math
app/Services/MarketData/  NSE, Yahoo, BusinessQuant, Screener ingest
app/Jobs/              Scheduled sync + score jobs
resources/views/       Livewire dashboard, weights, company page
services/screener-ingest/  Python HTML → JSON for India fundamentals
config/market_screenr.php  Weights, watchlist, API settings
```

## Status / known gaps

Working well for a personal India research loop. Still rough as a polished open-source product:

- **US is incomplete** — universe + BusinessQuant path exists; dashboard/scoring is India-first
- **No login** — fine for local/personal use; do not expose publicly without auth
- **Almost no tests** — only Laravel skeleton examples
- **Scraping is fragile** — Screener.in / NSE can rate-limit or change markup
- **“10-year” drawdown** — limited by how much price history you actually sync (`MARKET_SCREENR_PRICE_HISTORY_YEARS`, default 1)
- **Relative strength** is the stock’s own 52-week return, not vs Nifty/S&P
- **No alerts**, sector-relative scoring, or Docker setup yet

## License

MIT — see [LICENSE](LICENSE).
