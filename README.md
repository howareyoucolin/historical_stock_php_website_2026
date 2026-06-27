# Historical Stock Market Data Engine

A self-contained PHP + MySQL engine that stores ~25 years (2001–2026) of daily
stock data for the S&P 500 universe — including **delisted, acquired, renamed,
and bankrupt companies** — so backtests and simulations can run without
survivorship bias.

> ⚠️ **Data accuracy note.** Prices for currently/recently listed names are real
> (sourced from Yahoo Finance). Prices for the ~225 delisted/acquired names are
> **estimated** — reconstructed from researched anchor points (IPO/peak/terminal
> values) with realistic daily texture. They are "close enough" to remove
> survivorship bias, **not** tick-accurate. See [Data sources](#data-sources--accuracy).

## Stack

- **PHP 8** (CLI scripts + a small report site under `public/`)
- **MySQL 8** (the data store)
- **Docker Compose** (`php`, `mysql`, `phpmyadmin`)

## Quick start

```bash
docker compose up -d            # php :8700, mysql :8701, phpmyadmin :8702
docker exec stock_report_php php /var/www/html/public/migrate.php   # create tables
docker exec stock_report_php php /var/www/html/data/seeders/seed.php # seed universe + corp actions
```

Then populate prices (see [Data pipeline](#data-pipeline)). All data scripts run
inside the `php` container and read DB credentials from its environment.

## Database schema

All market-data tables are prefixed `stock_` (plus the base `stocks` table).

| Table | Rows* | What it holds |
|---|---:|---|
| `stocks` | 954 | Universe + metadata (symbol, company, sector, industry, description) |
| `stock_daily_prices` | 4.5M | Daily `close`, `adj_close`, `volume` per stock |
| `stock_corporate_actions` | 295 | `cash_buyout`, `stock_swap`, `equity_wipeout`, `delisted`, `renamed`, `otc_continuation` |
| `stock_quarterly_metrics` | 16.7k | PE, forward PE, PEG + TTM EPS, per fiscal quarter |
| `stock_dividends` | 11.1k | `ex_date`, `amount`, `pay_date` |
| `stock_quarterly_market_cap` | 20.8k | Shares outstanding + market cap, per quarter |
| `stock_quarterly_liquidity` | 72k | Annualized realized volatility + average daily volume, per quarter |
| `stock_index_membership` | 12.3k | Point-in-time S&P 500 roster (annual snapshot, 2001–2026) |
| `stock_market_index` | 6.4k | Equal-weight benchmark index (`index_code = 'EW'`), daily |
| `stock_trading_calendar` | 6.4k | Distinct market trading days |

\* Approximate, as of the latest build.

Non-market tables (`reports`, `report_uploaders`, `migrations`) belong to the
report website / framework and are out of scope here.

## Data pipeline

Everything lives under `data/`. Run scripts with
`docker exec stock_report_php php /var/www/html/data/<path>`.

### Migrations — `public/migrates/`
Plain forward-only migrations applied by `public/migrate.php` (tracked in the
`migrations` table). Each file returns `['name' => ..., 'up' => <SQL>]`.

### Seeders — `data/seeders/`
Run all via `seed.php`, or individually:
- `stocks.php` — seed the universe from `data/raw/sp500-members.json`
- `stock_corporate_actions.php` — load `data/config/stock-corporate-actions.json`
- `unique_stock_codes.php` — write the public stock-code list

### Importers / builders — `data/importers/`
| Script | Purpose |
|---|---|
| `stock_daily_prices.php` / `_all.php` | Fetch real daily prices from Yahoo (single / batch) |
| `retry_failed_stock_daily_prices.php` | Retry symbols from the failure log |
| `backfill_from_anchors.php` | Build estimated daily series from researched anchors (Brownian-bridge interpolation) |
| `synthesize_corporate_actions.php` | Extend terminal value past a corporate action (run **after** backfill) |
| `generate_delisting_actions.php` | Derive `delisted` actions from series that ended |
| `fill_price_gaps.php` | Interpolate internal date gaps in a real series |
| `despike_prices.php` | Repair isolated single-day bad ticks |
| `stock_quarterly_metrics.php` | Build PE/PEG/forward-PE from quarterly EPS |
| `stock_dividends.php` | Load dividend events |
| `stock_quarterly_market_cap.php` | Build quarterly shares/market cap |
| `stock_quarterly_liquidity.php` | Build quarterly realized vol + ADV |
| `stock_index_membership.php` | Load S&P 500 membership snapshots |
| `stock_market_index.php` | Build the equal-weight benchmark |
| `stock_trading_calendar.php` | Populate the trading calendar |

### Checkers — `data/checkers/`
- `stock_daily_prices_integrity.php` — missing data, duplicate symbols/rows, and
  gaps > N days (default 7). Exits non-zero on failure (CI-friendly).
- `stock_corporate_actions_coverage.php` — which no-price symbols are covered by a corporate action.
- `stocks_nulls.php` — stocks missing metadata.

### Shared — `data/support/`
`db.php` (PDO factory), `stock_symbol_utils.php` (symbol normalize/remap),
`corporate_actions.php` (corporate-action validation, mirrors the simulator).

## Data sources & accuracy

- **Real prices:** Yahoo Finance, for currently/recently listed symbols.
- **Estimated prices:** the ~225 delisted/acquired names Yahoo no longer serves
  (it purges delisted tickers, and old tickers are often reused by other
  securities). These were reconstructed from researched anchor points and are
  approximate but magnitude-consistent and free of survivorship bias.
- **Corporate actions, EPS, dividends, shares:** seeded from project config and
  the companion simulator's market data; quarterly fundamentals cover ~230–280
  real-EPS names (the backfilled delisted names have no fundamentals).
- **Benchmark (`EW`):** computed from this universe's own prices (equal-weight),
  not a licensed S&P 500 series.

Symbol remaps (e.g. `GPS → GAP`, `FB → META`) live in
`data/config/stock-symbol-remaps.json`.

## Backups

Database snapshots are written to `backup/` (git-ignored). To create one:

```bash
docker exec stock_report_mysql mysqldump -ustock_user -pstock_pass \
  --no-tablespaces --single-transaction stock_report \
  stocks stock_daily_prices stock_corporate_actions stock_quarterly_metrics \
  stock_dividends stock_quarterly_market_cap stock_quarterly_liquidity \
  stock_index_membership stock_market_index stock_trading_calendar \
  > backup/stock_market_data_$(date +%F).sql
```

## Directory layout

```
data/
  config/      symbol remaps, corporate-action rules
  raw/         source data (sp500 members, researched anchors, eps, shares)
  seeders/     universe + corporate-action seeders
  importers/   price/fundamentals builders
  checkers/    data-integrity checks
  support/     shared db/symbol/corp-action helpers
public/        report website + migrations (migrate.php, migrates/)
backup/        local DB snapshots (git-ignored)
deploy/        DreamHost deploy workflow
```
