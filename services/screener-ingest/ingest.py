#!/usr/bin/env python3
"""
Screener.in fundamentals ingest sidecar for market-screenr.
Outputs structured JSON for Laravel to consume.

Usage:
  python ingest.py --symbol RELIANCE --format json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.request
from typing import Any

from bs4 import BeautifulSoup

BASE_URL = "https://www.screener.in/company"
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
REQUEST_DELAY = 1.0

TOP_RATIO_ALIASES = {
    "market_cap": ("market cap",),
    "current_price": ("current price",),
    "pe": ("stock p/e", "stock pe"),
    "pb": ("price to book", "price to book value"),
    "book_value": ("book value",),
    "roe": ("roe",),
    "roce": ("roce",),
    "debt_to_equity": ("debt to equity", "debt to equity ratio"),
    "dividend_yield": ("dividend yield",),
}


def fetch_html(symbol: str) -> str:
    url = f"{BASE_URL}/{symbol}/consolidated/"
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Language": "en-US,en;q=0.9",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def parse_float(value: str | None) -> float | None:
    if value is None:
        return None

    text = value.strip()
    if not text or text in {"-", "—", "NA", "N/A"}:
        return None

    # Indian grouping: 17,45,968 / percentages: 10.3%
    cleaned = (
        text.replace("₹", "")
        .replace("Cr.", "")
        .replace("Cr", "")
        .replace("%", "")
        .replace(",", "")
        .strip()
    )
    cleaned = re.sub(r"[^\d.\-]", "", cleaned)
    if cleaned in {"", "-", ".", "-."}:
        return None

    try:
        return float(cleaned)
    except ValueError:
        return None


def normalize_label(label: str) -> str:
    return re.sub(r"\s+", " ", label.replace("+", " ").strip().lower())


def extract_top_ratios(soup: BeautifulSoup) -> dict[str, Any]:
    ratios: dict[str, Any] = {}
    ul = soup.select_one("#top-ratios")
    if not ul:
        return ratios

    for li in ul.select("li"):
        name_el = li.select_one(".name")
        if not name_el:
            continue

        label = normalize_label(name_el.get_text(" ", strip=True))
        number_el = li.select_one(".number")
        value_el = li.select_one(".value")
        raw = number_el.get_text(" ", strip=True) if number_el else (
            value_el.get_text(" ", strip=True) if value_el else None
        )
        parsed = parse_float(raw)

        for key, aliases in TOP_RATIO_ALIASES.items():
            if any(alias == label or alias in label for alias in aliases):
                ratios[key] = parsed
                break

    return ratios


def extract_table_series(soup: BeautifulSoup, section_id: str, metric_row: str) -> list[dict]:
    section = soup.select_one(f"#{section_id}")
    if not section:
        return []

    table = section.select_one("table")
    if not table:
        return []

    rows = table.select("tr")
    if not rows:
        return []

    header_cells = [c.get_text(" ", strip=True) for c in rows[0].select("th, td")]
    headers = header_cells[1:]
    target = normalize_label(metric_row)

    values: list[float | None] = []
    for row in rows[1:]:
        cells = [c.get_text(" ", strip=True) for c in row.select("td")]
        if not cells:
            continue

        label = normalize_label(cells[0])
        if label == target or label.startswith(target) or target in label:
            values = [parse_float(c) for c in cells[1:]]
            break

    series: list[dict] = []
    for header, val in zip(headers, values):
        if val is None:
            continue

        date_match = re.search(r"(\w{3})\s+(\d{4})", header)
        if date_match:
            month_map = {"Mar": "03", "Jun": "06", "Sep": "09", "Dec": "12"}
            month = month_map.get(date_match.group(1), "03")
            date_str = f"{date_match.group(2)}-{month}-01"
            period_type = "annual" if date_match.group(1) == "Mar" else "quarterly"
        else:
            date_str = header
            period_type = "annual"

        series.append({"date": date_str, "value": val, "type": period_type})

    return series


def extract_sector(soup: BeautifulSoup) -> dict[str, str | None]:
    """Screener peer breadcrumb: Energy → Oil, Gas & Consumable Fuels → …"""
    links = soup.select('a[href^="/market/"]')
    texts = [a.get_text(" ", strip=True) for a in links if a.get_text(" ", strip=True)]
    return {
        "sector": texts[0] if texts else None,
        "industry": texts[1] if len(texts) > 1 else (texts[0] if texts else None),
    }


def extract_shareholding(soup: BeautifulSoup) -> dict[str, Any]:
    series_promoter = extract_table_series(soup, "shareholding", "Promoters")
    series_fii = extract_table_series(soup, "shareholding", "FIIs")
    series_dii = extract_table_series(soup, "shareholding", "DIIs")

    def latest_and_change(series: list[dict]) -> tuple[float | None, float | None]:
        if not series:
            return None, None
        latest = series[-1]["value"]
        prev = series[-2]["value"] if len(series) >= 2 else None
        change = round(latest - prev, 2) if latest is not None and prev is not None else None
        return latest, change

    promoter, _ = latest_and_change(series_promoter)
    fii, fii_change = latest_and_change(series_fii)
    dii, dii_change = latest_and_change(series_dii)

    return {
        "promoter_pct": promoter,
        "fii_pct": fii,
        "dii_pct": dii,
        "fii_change_qoq": fii_change,
        "dii_change_qoq": dii_change,
    }


def compute_cagr(values: list[float], years: int) -> float | None:
    if len(values) < 2 or years <= 0:
        return None
    start, end = values[0], values[-1]
    if start <= 0 or end <= 0:
        return None
    return round(((end / start) ** (1 / years) - 1) * 100, 2)


def build_payload(symbol: str) -> dict[str, Any]:
    html = fetch_html(symbol)
    soup = BeautifulSoup(html, "html.parser")
    key_metrics = extract_top_ratios(soup)

    # Screener ratios table has ROCE/ROE history; P/E history is not on this page.
    roce_series = extract_table_series(soup, "ratios", "ROCE")
    roe_series = extract_table_series(soup, "ratios", "ROE")
    revenue_series = extract_table_series(soup, "profit-loss", "Sales")
    profit_series = extract_table_series(soup, "profit-loss", "Net Profit")
    shareholding = extract_shareholding(soup)
    taxonomy = extract_sector(soup)

    revenue_values = [p["value"] for p in revenue_series if p["value"] is not None]
    profit_values = [p["value"] for p in profit_series if p["value"] is not None]

    return {
        "symbol": symbol,
        "sector": taxonomy.get("sector"),
        "industry": taxonomy.get("industry"),
        "key_metrics": {
            "market_cap": key_metrics.get("market_cap"),
            "pe": key_metrics.get("pe"),
            "pb": key_metrics.get("pb"),
            "roe": key_metrics.get("roe"),
            "roce": key_metrics.get("roce"),
            "debt_to_equity": key_metrics.get("debt_to_equity"),
            "current_price": key_metrics.get("current_price"),
        },
        "revenue_cagr_3y": compute_cagr(revenue_values[-4:], 3) if len(revenue_values) >= 4 else None,
        "profit_cagr_3y": compute_cagr(profit_values[-4:], 3) if len(profit_values) >= 4 else None,
        # True multi-year PE averages need a PE history series Screener no longer
        # exposes on this page — leave null rather than faking with today's PE.
        "pe_avg_5y": None,
        "pe_avg_10y": None,
        "ratios": {
            "roce": key_metrics.get("roce"),
            "roe": key_metrics.get("roe"),
        },
        "shareholding": shareholding,
        "history": {
            "pe": [],
            "roce": roce_series,
            "roe": roe_series,
        },
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Fetch screener.in fundamentals")
    parser.add_argument("--symbol", required=True, help="NSE symbol e.g. RELIANCE")
    parser.add_argument("--format", default="json", choices=["json"])
    args = parser.parse_args()

    time.sleep(REQUEST_DELAY)

    try:
        payload = build_payload(args.symbol.upper())
        print(json.dumps(payload, indent=2))
        return 0
    except urllib.error.HTTPError as e:
        print(json.dumps({"error": f"HTTP {e.code}", "symbol": args.symbol}), file=sys.stderr)
        return 1
    except Exception as e:
        print(json.dumps({"error": str(e), "symbol": args.symbol}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
