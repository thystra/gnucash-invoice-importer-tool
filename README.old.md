# GnuCash Vendor Bill Tool v186

Local web tool for staging vendor orders as GnuCash vendor bill CSVs, reviewing line-item categories, exporting payment/stored-value rows, and auditing/matching imported payments.

## v181 changes

- Added initial Home Depot module for the Purchase Tracking / Purchase History CSV export.
- Home Depot CSV import groups line rows by order/invoice/transaction and stages reviewable bills or credit memos using the shared category/export engine.
- Added `DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT` for the Home Depot free-floating register scan.
- Home Depot is now included in the generic free-floating/unmatched register transaction report and ignore/suppress workflow.
- Updated TSC Chromium launch commands to include `--remote-allow-origins=http://127.0.0.1:9222`, which is required by newer Chromium builds that reject DevTools WebSocket connections with 403.
- TSC CDP client now reports a clearer message if Chromium was launched without the remote-allow-origins flag.

## v180 changes

- Updated Tractor Supply capture to avoid Playwright-managed browser automation on Ubuntu 26.04.
- TSC `capture-cdp` now attaches to an already-open user-launched Chromium/Chrome session through raw Chrome DevTools Protocol using `websocket-client`.
- TSC requirements now use:
  - `beautifulsoup4`
  - `websocket-client`
- Added/updated TSC instructions to launch system Chromium/Chrome with `--remote-debugging-port=9222`, log in manually, then run the auto-walk scraper.
- Shared scraper instructions now explicitly warn not to run `python -m playwright install chromium` on Ubuntu 26.04. Lowe's may still install the Python Playwright package for its existing CDP adapter, but the workflow should use the existing browser session rather than Playwright's bundled Chromium.

## v179 changes retained

- Added a generic vendor free-floating register transaction report, available for Amazon, Costco, Walmart, Lowe's, Home Depot, and Tractor Supply.
- Added an ignore/suppress workflow for reviewed free-floating register transactions so they can be hidden from future scans and re-enabled later.
- Lowe's retains its Step 5a unmatched/refund scan, now backed by the generic vendor scan engine.
- Added an initial Tractor Supply module:
  - active Tractor Supply vendor tab,
  - `./tractorsupply_scraper/` directory convention,
  - saved webpage parser for TSC order-detail pages,
  - normalized CSV output (`tsc_orders.csv`, `tsc_order_items.csv`, `tsc_order_payments.csv`),
  - normalized ZIP/folder import into the shared bill review/export engine,
  - TSC free-floating register scan.
- Switched vendor scrapers to a shared root-level `scraper_venv/` environment.
- Added `scrape_all_vendors.py` wrapper for periodic refreshes of Lowe's and Tractor Supply.

## Tractor Supply workflow

1. Start system Chromium/Chrome manually with `--remote-debugging-port=9222` and `--remote-allow-origins=http://127.0.0.1:9222`.
2. Log in to Tractor Supply normally in that browser.
3. Run the TSC `capture-cdp` command from the Tractor Supply tab to walk order details and build the normalized ZIP.
4. Alternatively, save TSC order detail pages as "Webpage, Complete" and run `parse-saved`.
5. Import the normalized folder or ZIP from the Tractor Supply tab.
6. Categorize/export bills using the shared review engine.
7. Use the free-floating register scan to identify TSC card/register transactions not yet tied to bills or credit memos.

The Tractor Supply payment apply/matching automation is not yet enabled; this version establishes the parser/import/review/diagnostic foundation using the Lowe's module as the starting model.


## Home Depot workflow

1. Download the Home Depot Purchase Tracking / Purchase History CSV.
2. Open the Home Depot tab and import the CSV.
3. Categorize/export bills or credit memos through the shared review engine.
4. Use the Home Depot free-floating register scan to find matching card transactions, refunds, or purchases that were imported in a register but not tied to a bill/credit memo.

Caveat: the Home Depot purchase-history CSV does not include tender detail or sales-tax totals in the observed export. The module therefore stages the CSV line subtotal as the bill/credit review amount and expects the register scan/payment mapping to be used for reconciliation.

## v182
- Added a second “Save this invoice” button at the bottom of long Lowe's, Tractor Supply, and Home Depot invoice cards when the invoice has more than 10 line items.
- Fixed stored-value export behavior so Lowe's My Lowe's Money transaction CSV generation uses all currently staged stored-value evidence instead of being silently constrained by the Step 5 payment-matching date window. This prevents 2025 My Lowe's Money rows from being omitted when the match window is still set to 2026.

## v184
- Restored a visible Lowe's Step 5 payment-match date-window control directly on Step 5a and Step 5b. The control saves in-place and returns to the same Lowe's workflow step instead of jumping to Config.
- Account dropdowns for `DEFAULT_...ACCOUNT...` values now use an all-account datalist including Assets, Liabilities, Expenses, Income, and Equity. This allows stored-value defaults such as `DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY` to browse/select asset accounts.
- Enabled Tractor Supply local normalized folder import from the Tractor Supply tab. The local import handler now accepts TSC normalized folders containing `tsc_orders.csv` / `tsc_order_items.csv` and TSC normalized ZIPs when the vendor is Tractor Supply.
- Auto local-folder detection now routes folders containing `tsc_*.csv` files to the Tractor Supply importer and folders containing `lowes_*.csv` files to the Lowe's importer.


## v184
- Restored the Lowe's Step 5 payment match date-window control directly under the Lowe's workflow navigation for both Step 5a and Step 5b, so it is visible before the action buttons.
- The visible control saves back to the same Lowe's workflow step and continues to update `DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS`.


## v185
- Fixed Lowe's Step 5 payment match date-window visibility by rendering the control directly in the Lowe's workflow header/footer on bill review pages as well as on the Lowe's Step 5a/5b pages.
- The Lowe's Step 5 date-window save handler now supports returning to the bills workflow page, preserving the Lowe's vendor context.


## v186
- Clarified the Lowe's Step 5 controls by splitting them into two visible settings:
  - **Step 5 payment date range filter**: start/end dates that determine which staged Lowe's payment rows are included in Step 5 reports/plans.
  - **Posting-lag tolerance**: number of days after the vendor payment/order date to search mapped accounts for shipped/split online charges.
- The Step 5 date range filter now appears directly in the Lowe's workflow header, the bills workflow header/footer, Step 5a, and Step 5b.
- Saving the Step 5 date range from the Lowe's pages preserves the current Lowe's workflow context instead of returning to the Amazon transactions page.
- Renamed the old “payment match date window” wording to “posting-lag tolerance” to avoid confusing it with the date range filter.

## v187
- Added report-only split-set aggregation suggestions to the vendor free-floating/unmatched register transaction report.
- The scan now looks for multiple free-floating vendor register transactions that sum to an open posted bill/CREDIT lot balance within the configured posting-lag tolerance. This helps identify online pickup/shipping orders that settle as several card transactions instead of one exact payment.
- Suggestions are shown separately from the raw free-floating rows and do not automatically modify the book; they are intended for review before creating a payment allocation override or extending the apply workflow.

## v188
- Fixed a free-floating / unmatched vendor register scan fatal caused by numeric-looking Lowe's order IDs being converted to integer array keys before string helpers were called.
- Hardened related vendor ID/string handling in ledger/free-floating scan paths.


## v191

- Displayed version now reports v191.
- The vendor free-floating register transaction report now always includes an explicit **Potential split-set invoice matches** box.
- If aggregate/split-set candidates are found, the box lists which register transactions are proposed against which bill/CREDIT invoice.
- If no aggregate candidates are found, the box says so explicitly and gives the main reasons to check: open bill/CREDIT state, Step 5 date range, posting-lag tolerance, and vendor description patterns.


## v191 Lowe's pagination note

The Lowe's scraper now also probes direct online order-list pages such as `/mylowes/orders?orderType=ONLINE&page=4` using `--direct-summary-pages` and `--order-types ONLINE`. This catches pages where Lowe's visible DOM has later orders but the embedded `orderHistory` state remains stale.

## v191 notes
- Lowe's local import now detects readable normalized outputs under `./lowes_scraper/` and pre-fills/suggests the newest readable folder or ZIP.
- Lowe's local import errors now list candidate normalized output paths when the entered path does not exist or is not readable.
- Lowe's scrape instructions now distinguish `2026-test-export/normalized` from full `2026-export/normalized`; use the exact path printed by the scrape command.
- Lowe's parse-saved command now uses a `RUN` variable so reparsing a test capture points at `2026-test-export/raw_html` instead of the full-run path by default.
