# Market Screenr

Personal stock screener for **US and Indian (NSE/BSE)** markets with custom weighted MTF scoring, valuation analysis, drawdown tracking, and historical P/E & ROCE charts.

Built with **Laravel 13**, **Livewire 4**, deployable on **Laravel Cloud**.

## Features

### Section 1 — Valuation
- Current P/E, 5Y & 10Y average P/E
- EV/EBITDA (current + 5Y avg)
- Price/Book
- Historical percentile → **"Is it cheap?"**

### Section 2 — Drawdown Engine
- Current price, 52W high/low
- % below ATH, % above ATL
- 10-year price percentile

### Section 3 — Fundamentals
- Revenue & profit CAGR, ROE, ROCE
- Debt/equity, interest coverage, FCF
- Promoter holding, FII/DII buying trends

### Section 4 — Momentum (confirmation, not trading)
- 50/100/200 DMA, distance from 200 DMA
- 52W relative strength, volume spike, delivery %

### Section 5 — MTF Score
- Customizable weights across 6 components
- Daily ranked screener for MTF-eligible NSE stocks
- Score breakdown per stock (e.g. 83/100)

## Data Sources

| Market | Source | Purpose |
|--------|--------|---------|
| US | [BusinessQuant](https://businessquant.com) (free) | Fundamentals, ratios, P/E history |
| US backup | [FMP](https://financialmodelingprep.com) (250 calls/day free) | EOD prices |
| India universe | NSE public API | Symbol list, quotes |
| India fundamentals | Screener.in (Python sidecar) | ROCE, P/E, financials |
| India prices | Yahoo Finance `.NS` | 10Y OHLCV, DMAs |
| MTF eligibility | BSE Group I list | Monthly MTF flag |

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan screener:compute-scores
npm install && npm run build
php artisan serve
```

Visit `http://localhost:8000`

## Commands

```bash
# Sync a single stock
php artisan screener:sync RELIANCE --market=IN

# India-only bootstrap (one batch)
php artisan screener:sync --sync --india-only --limit=20

# Loop until 100 (or 250) India stocks have ROCE — leave running
php artisan screener:enrich --target=100 --batch=20
php artisan screener:enrich --target=250 --batch=20 --max-batches=20

# Recompute weighted scores
php artisan screener:compute-scores

# Pipeline health
php artisan screener:status
```

**Timing (local Mac, measured):** ~4.5s/stock → limit 20 ≈ 90s, 100 stocks ≈ 8–10 min, 250 ≈ 20–25 min. Be polite to Screener.in (`SCREENER_INGEST_DELAY=1.5`).

## Python Sidecar (India fundamentals)

```bash
cd services/screener-ingest
pip install -r requirements.txt
python ingest.py --symbol RELIANCE --format json
```

## Scheduled Jobs (Laravel Cloud)

Enable **Scheduler** and **Managed Queues** in Laravel Cloud dashboard.

| Job | Schedule | Purpose |
|-----|----------|---------|
| SyncIndiaUniverseJob | Daily 8:30 PM IST | NSE symbol list |
| SyncMtfGroupListJob | Monthly 2nd | BSE Group I MTF list |
| SyncIndiaFundamentalsJob | Daily 9:00 PM IST | Screener.in ingest (50/day batch) |
| SyncUsFundamentalsJob | Daily 6:00 AM ET | BusinessQuant US data |
| ComputeScreenerScoresJob | Daily 10:30 PM IST | Weighted ranking |

## Laravel Cloud Deployment

1. Push to GitHub → connect repo in Laravel Cloud
2. Add Postgres database cluster
3. Enable Managed Queues (requires `aws/aws-sdk-php` — already included)
4. Enable Scheduler toggle
5. Set env vars: `BUSINESSQUANT_API_KEY`, `FMP_API_KEY` (optional)

## AI briefing (cheapest LLM + web)

Uses **Gemini Flash + Google Search grounding** — usually free on [Google AI Studio](https://aistudio.google.com/apikey).

```bash
# .env
GEMINI_API_KEY=your_key
GEMINI_MODEL=gemini-2.5-flash
php artisan config:clear
```

Open any company page → set preferences → **Analyze with web search**. The prompt includes your dashboard metrics + score breakdown; Gemini can search for results/news.

## Roadmap

- [x] LLM stock briefing with web search (Gemini Flash)
- [ ] US screener parity
- [ ] Sector-relative percentile scoring
- [ ] Email alerts for score changes

## License

MIT
