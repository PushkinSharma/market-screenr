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

BASE_URL = "https://www.screener.in/company"
USER_AGENT = "market-screenr/1.0 (+https://github.com/PushkinSharma/market-screenr)"
REQUEST_DELAY = 1.0


def fetch_html(symbol: str) -> str:
    url = f"{BASE_URL}/{symbol}/consolidated/"
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8", errors="replace")


def parse_float(value: str | None) -> float | None:
    if not value or value.strip() in ("-", ""):
        return None
    cleaned = re.sub(r"[,%₹Cr\s]", "", value.strip())
    try:
        return float(cleaned)
    except ValueError:
        return None


def extract_top_ratios(html: str) -> dict[str, Any]:
    """Parse key metrics from screener.in company page top ratios section."""
    ratios: dict[str, Any] = {}

    patterns = {
        "market_cap": r"Market Cap[^<]*</span>\s*<span[^>]*>([^<]+)",
        "pe": r"Stock P/E[^<]*</span>\s*<span[^>]*>([^<]+)",
        "pb": r"Price to book[^<]*</span>\s*<span[^>]*>([^<]+)",
        "roe": r"ROE[^<]*</span>\s*<span[^>]*>([^<]+)",
        "roce": r"ROCE[^<]*</span>\s*<span[^>]*>([^<]+)",
        "debt_to_equity": r"Debt to equity[^<]*</span>\s*<span[^>]*>([^<]+)",
    }

    for key, pattern in patterns.items():
        match = re.search(pattern, html, re.IGNORECASE)
        if match:
            ratios[key] = parse_float(match.group(1))

    return ratios


def extract_table_series(html: str, section_id: str, metric_row: str) -> list[dict]:
    """Extract a time series from a financial table row."""
    section_match = re.search(
        rf'id="{section_id}"[^>]*>.*?<table[^>]*>(.*?)</table>',
        html,
        re.DOTALL | re.IGNORECASE,
    )
    if not section_match:
        return []

    table = section_match.group(1)
    rows = re.findall(r"<tr[^>]*>(.*?)</tr>", table, re.DOTALL)

    headers: list[str] = []
    values: list[float | None] = []

    for i, row in enumerate(rows):
        cells = re.findall(r"<td[^>]*>(.*?)</td>", row, re.DOTALL)
        cells = [re.sub(r"<[^>]+>", "", c).strip() for c in cells]
        if not cells:
            continue

        if i == 0:
            headers = cells[1:]  # skip label column
            continue

        label = cells[0].lower()
        if metric_row.lower() in label:
            values = [parse_float(c) for c in cells[1:]]
            break

    series = []
    for header, val in zip(headers, values):
        if val is None:
            continue
        # Parse month-year headers like "Mar 2024"
        date_match = re.search(r"(\w{3})\s+(\d{4})", header)
        if date_match:
            month_map = {"Mar": "03", "Jun": "06", "Sep": "09", "Dec": "12"}
            m = month_map.get(date_match.group(1), "03")
            date_str = f"{date_match.group(2)}-{m}-01"
        else:
            date_str = header

        series.append({"date": date_str, "value": val, "type": "annual"})

    return series


def compute_cagr(values: list[float], years: int) -> float | None:
    if len(values) < 2 or years <= 0:
        return None
    start, end = values[0], values[-1]
    if start <= 0 or end <= 0:
        return None
    return round(((end / start) ** (1 / years) - 1) * 100, 2)


def build_payload(symbol: str) -> dict[str, Any]:
    html = fetch_html(symbol)
    key_metrics = extract_top_ratios(html)

    pe_series = extract_table_series(html, "ratios", "P/E")
    roce_series = extract_table_series(html, "ratios", "ROCE")
    roe_series = extract_table_series(html, "ratios", "ROE")
    revenue_series = extract_table_series(html, "profit-loss", "Sales")
    profit_series = extract_table_series(html, "profit-loss", "Net Profit")

    pe_values = [p["value"] for p in pe_series if p["value"]]
    revenue_values = [p["value"] for p in revenue_series if p["value"]]
    profit_values = [p["value"] for p in profit_series if p["value"]]

    return {
        "symbol": symbol,
        "key_metrics": key_metrics,
        "revenue_cagr_3y": compute_cagr(revenue_values[-4:], 3) if len(revenue_values) >= 4 else None,
        "profit_cagr_3y": compute_cagr(profit_values[-4:], 3) if len(profit_values) >= 4 else None,
        "pe_avg_5y": round(sum(pe_values[-5:]) / len(pe_values[-5:]), 2) if pe_values else None,
        "pe_avg_10y": round(sum(pe_values[-10:]) / len(pe_values[-10:]), 2) if pe_values else None,
        "ratios": {
            "roce": key_metrics.get("roce"),
            "roe": key_metrics.get("roe"),
        },
        "shareholding": {},
        "history": {
            "pe": pe_series,
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
