# Tractor Supply scraper / parser

This module writes normalized Tractor Supply CSVs for the GnuCash Vendor Bill Tool.

## Ubuntu 26.04 browser approach

Do **not** run `python -m playwright install chromium` for Tractor Supply.  The
TSC auto-walk mode uses the same Ubuntu-friendly pattern as the Lowe's workflow:
launch your normal system Chromium/Chrome yourself with a remote debugging port,
log in manually, and let the scraper attach to that already-open session.

The TSC module uses a small raw Chrome DevTools Protocol client plus
`websocket-client`; it does not need a Playwright-managed browser.

## Shared venv

From the tool root:

```bash
cd /home/alan/public_html/gnu2
python3 -m venv scraper_venv
. scraper_venv/bin/activate
pip install -U pip
pip install -r tractorsupply_scraper/requirements.txt
```

If you also use Lowe's in the same venv, install both requirements files.  Lowe's
may still install the Python `playwright` package for its CDP adapter, but do not
install Playwright's bundled Chromium on Ubuntu 26.04.

## Start browser manually

```bash
mkdir -p /home/alan/snap/chromium/common/tsc-manual-profile

/snap/bin/chromium \
  --remote-debugging-port=9222 \
  --remote-allow-origins=http://127.0.0.1:9222 \
  --user-data-dir=/home/alan/snap/chromium/common/tsc-manual-profile \
  https://www.tractorsupply.com/AccountDashboardView?topNav=purchaseOrder#tscOrders
```

Log in normally in that browser.

By default, `capture-cdp` scans both online and in-store TSC order tabs for the selected year. The site defaults to 30 days, so the scraper now sends both `fromOptionChosen=<year>` and the matching TSC day-range value, then tries to apply the visible page filters after hydration.

## Auto-walk orders and build ZIP

```bash
cd /home/alan/public_html/gnu2
. scraper_venv/bin/activate

YEAR=2026
OUT=./tractorsupply_scraper/${YEAR}-export
ZIP=./tractorsupply_scraper/${YEAR}-normalized.zip

python tractorsupply_scraper/tsc_extract.py capture-cdp \
  --years "$YEAR" \
  --order-types ONLINE,INSTORE \
  --cdp-endpoint http://127.0.0.1:9222 \
  --out "$OUT" \
  --zip-out "$ZIP"
```

## Parse saved webpage-complete files

```bash
python tractorsupply_scraper/tsc_extract.py parse-saved \
  --input ./tractorsupply_scraper/saved_pages \
  --out ./tractorsupply_scraper/2026-export/normalized \
  --zip-out ./tractorsupply_scraper/2026-normalized.zip
```

## Output files

- `tsc_orders.csv`
- `tsc_order_items.csv`
- `tsc_order_payments.csv`
- `tsc_parse_diagnostics.csv`

## SKU/title resolution

TSC order-detail pages sometimes render a blank product-title anchor, leaving the
visible text as only `SKU:` plus quantity/price/subtotal.  In v202 the parser
resolves those rows in this order:

1. Use any non-empty title from the order-detail page.
2. Use `tractorsupply_scraper/tsc_sku_descriptions.csv` or any extra `--sku-db`
   CSV passed on the command line.  CSV columns may be `sku,description,item_url`.
3. Learn SKU/title rows from saved TSC product-detail pages found anywhere under
   the parse input, including the Next.js `__NEXT_DATA__` product JSON.
4. During `capture-cdp`, visit each unique linked product page after order-detail
   capture unless `--no-product-pages` is used, then reparse those product pages
   into the normalized output.
5. If no product page/helper row is available, derive a readable fallback title
   from the product URL slug instead of using only `Tractor Supply item <sku>`.

Every parse writes `tsc_sku_descriptions_suggested.csv` beside the normalized
CSVs.  Review/merge useful rows into `tractorsupply_scraper/tsc_sku_descriptions.csv`
when TSC later omits product titles for the same SKU.
