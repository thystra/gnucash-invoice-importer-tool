# Lowe's retail scraper/parser v10

Local-only scraper/parser for Lowe's **retail** purchase history, intended to produce normalized JSON/CSV for later import into the GnuCash vendor import tool.

The Lowe's retail pages tested so far expose order history/detail data in `window['__PRELOADED_STATE__']`, so the parser extracts structured page data rather than using OCR.

## v10 changes

- Keeps v9 header-tax validation, cancelled-line exclusion, summary de-duplication, detail backfill, synthesized return handling, and detail-link buffering.
- Classifies payment/tender rows for importer use:
  - Lowe's `method=GC` is normalized to `method_normalized=MYLOWES_MONEY` and `payment_class=stored_value`.
  - Stored-value rows get an account hint of `Assets:Other Current Assets:My Lowe's Money`.
  - `MC`, `Master Card`, `Mastercard`, and `VISA` are normalized as credit cards.
- Adds payment columns:
  - `method_normalized`
  - `payment_label`
  - `payment_class`
  - `stored_value_program`
  - `display_last4`
  - `payment_identifier`
  - `net_amount`
  - `has_refund`
  - `account_hint`
  - `importer_treatment`
- Preserves the raw Lowe's identifier in `payment_identifier` / `last4`; `display_last4` is just the final four digits for UI display.
- Writes convenience files:
  - `lowes_order_stored_value_payments.json/csv`
  - `lowes_order_payment_refunds.json/csv`

## Important accounting notes for later GnuCash importer

- `type=sale` should become a vendor bill.
- `type=return` should become a vendor credit / return document.
- Use `extended_amount` for item/category lines.
- Use the order-level `tax` as the sales-tax line.
- Do not import `lowes_order_excluded_items.csv`; it is evidence for cancelled lines only.
- If `line_item_tax_delta_vs_order_tax` is non-zero, prefer the order-level `tax`.
- Lowe's military discount appears to be reflected in item/discount values and should be treated as a discount / price reduction, not a stored-value payment.
- Lowe's My Lowe's Money appears in the payment list as `method=GC`; v10 classifies those rows as stored value.
- Credit-card `refund_amount` rows are evidence for a refund against a prior charge. If a separate Lowe's return order exists for the same amount, avoid double-counting the refund; use the separate return document for the vendor credit and the payment refund as matching evidence.

## Install / update

From your current layout:

```bash
cd /home/alan/public_html/gnu2
unzip /path/to/lowes_retail_scraper_v10.zip

cp -a lowes_retail_scraper_v10/README.md lowes/README.md
cp -a lowes_retail_scraper_v10/lowes_retail_scraper lowes/
cp -a lowes_retail_scraper_v10/requirements.txt lowes/requirements.txt

. .venv-lowes/bin/activate
pip install -r lowes/requirements.txt
```

On Ubuntu 26.04, do **not** run `python -m playwright install chromium`. Use an existing browser through CDP or a system Chrome/Chromium executable.

## Recommended CDP workflow

Start Chromium/Chrome manually with remote debugging enabled, then log in normally:

```bash
mkdir -p /home/alan/snap/chromium/common/lowes-retail-manual-profile

/snap/bin/chromium \
  --remote-debugging-port=9222 \
  --user-data-dir=/home/alan/snap/chromium/common/lowes-retail-manual-profile \
  https://www.lowes.com/mylowes/orders
```

Then capture from a second terminal:

```bash
cd /home/alan/public_html/gnu2/lowes
. ../.venv-lowes/bin/activate

python lowes_retail_scraper/lowes_extract.py \
  capture-cdp \
  --years 2026 \
  --cdp-endpoint http://127.0.0.1:9222 \
  --max-details 25 \
  --out /home/alan/public_html/gnu2/exports/lowes-scrape-test-v10-2026
```

For a full scrape, omit `--max-details` or set it to `0`:

```bash
python lowes_retail_scraper/lowes_extract.py \
  capture-cdp \
  --years 2024,2025,2026 \
  --cdp-endpoint http://127.0.0.1:9222 \
  --out /home/alan/public_html/gnu2/exports/lowes-scrape-full
```

## Parse saved/captured HTML

```bash
cd /home/alan/public_html/gnu2/lowes
. ../.venv-lowes/bin/activate

python lowes_retail_scraper/lowes_extract.py \
  parse-saved \
  --input /home/alan/public_html/gnu2/exports/lowes-scrape-test-v9-2026/raw_html \
  --out /home/alan/public_html/gnu2/exports/lowes-scrape-test-v10-2026-normalized
```

## Output files

- `lowes_order_summaries.json/csv`
- `lowes_order_details.json`
- `lowes_orders.csv`
- `lowes_order_items.csv`
- `lowes_order_payments.csv`
- `lowes_order_stored_value_payments.json/csv`
- `lowes_order_payment_refunds.json/csv`
- `lowes_order_excluded_items.json/csv`
- `lowes_parse_diagnostics.json/csv`
