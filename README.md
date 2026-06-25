# GnuCash Vendor Bill Tool v204

Local web tool for staging vendor orders as GnuCash vendor bill CSVs, reviewing line-item categories, exporting payment/stored-value rows, and auditing/matching imported payments.



## v204
- Tractor Supply bill CSV export now forces posted bill fields for TSC rows (`date_posted`, `due_date`, `account_posted`, and posted memo) even if the global **Post invoices** checkbox was not enabled. This prevents successfully imported TSC bills from appearing missing/unposted in GnuCash.
- Export validation now validates the configured Accounts Payable posting account whenever the selected batch contains Tractor Supply rows.
- The Review Bills form now preserves `mode=bills`, `vendor_hint`, `vendor_step=review`, pagination, filter, search, date-sort, and skipped-row state during AJAX review saves. This fixes **Apply same SKU / item id** returning to the Import Data tab after it reloads the page.

## v200
- Updated the Tractor Supply CDP scraper to avoid the site's default 30-day purchase-history filter.
- TSC `capture-cdp` now scans both `ONLINE` and `INSTORE` order types by default.
- For each requested year, the scraper builds URLs using `fromOptionChosen=<year>` plus the TSC day-range value expected by the site, then also tries to apply the visible year/order-type controls after the page hydrates.
- Added `--order-types` to TSC `capture-cdp`; examples use `--order-types ONLINE,INSTORE`.

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

The Lowe's scraper now also probes direct order-list pages for All Orders, Online Orders, and Store Purchases using `--direct-summary-pages` and `--order-types BOTH,ONLINE,INSTOREBACKROOM`. This catches pages where Lowe's visible DOM has later orders but the embedded `orderHistory` state remains stale.

## v191 notes
- Lowe's local import now detects readable normalized outputs under `./lowes_scraper/` and pre-fills/suggests the newest readable folder or ZIP.
- Lowe's local import errors now list candidate normalized output paths when the entered path does not exist or is not readable.


## v192 notes
- Reorganized top navigation around the normalized workflow: Instructions, Config, Import Data, Review Bills & Line Items, Transaction Matching, Utility.
- Vendor-specific import/scraper/stored-value tasks now live under Import Data, while normalized invoices flow through the shared bill review engine.
- Removed the Lowe's limited test-capture guidance from the UI; the full per-year capture is now the primary documented path.
- Added a Transaction Matching landing page that links to Lowe's 5a/5b, Amazon payment matching, and vendor free-floating register scans.

## v193 notes

- Widened the Skipped orders review amount column so large dollar amounts stay on one line instead of wrapping.
- Lowe's scraper/direct probes now cover all three visible order-history tabs: All Orders, Online Orders, and Store Purchases (`BOTH,ONLINE,INSTOREBACKROOM`).
- Lowe's rendered order-list parsing now generates stable direct detail URLs from order numbers when Lowe's does not expose a usable nearby `View Details` href.
- Lowe's detail capture now canonicalizes detail URLs to the stable `t=<base64 order>&ih=Qg==` form, retries that direct URL when an encrypted/stale detail link does not resolve to the expected order, and queues any rendered order links discovered on a failed/stale detail page.
- `lowes_detail_links_to_capture.csv` now remains useful when an order number is visible in the rendered DOM but the detail page was not parsed.

## v194 notes

- Lowe's scraper now preserves the original rendered order-detail URL, including Lowe's encrypted `s=` token, and only uses the stable `t=<base64>&ih=Qg==` URL as a fallback. This is intended to fix old/split online orders that appear in the rendered order list but do not hydrate when the scraper drops the `s=` token.
- Lowe's detail capture now waits/retries for detail page hydration before saving a page as `_unparsed`.
- New capture options: `--detail-retry-count` and `--detail-retry-ms`.
- If a normalized ZIP still lists an order in `lowes_detail_links_to_capture.csv`, the order was found in the summary/list DOM but no usable detail page with line items/payments was captured.

## v195 notes

- Added a rendered-DOM fallback parser for Lowe's order-detail pages. Some Lowe's pages save with `orderDetails.error` / `ECONNABORTED` in the embedded state even though the visible saved page contains the order number, date, items, totals, tax, and delivery. v195 can recover those visible DOM rows and stage them as reviewable bills.
- Added explicit detail-capture failure output: `lowes_detail_capture_errors.csv` / `.json`. The parser also prints an end-of-run list of unsuccessful order numbers so they can be searched/browsed manually.
- Added a manual import directory convention: `./lowes_scraper/import/`. Save troublesome Lowe's order/search pages there and run the new manual parse command from the Lowe's scraper UI.
- Lowe's UI now advises saving manual pages as **Webpage, Complete**. The parser reads HTML files recursively and ignores asset folders, but Webpage Complete is more likely to preserve Lowe's hydrated visible DOM than page source or PDF.
- Lowe's workflow Step 1 links on bill-review pages now jump directly to the scraper instruction card.
- Skipped-order review amount column width increased again to avoid wrapping large amounts.
- Summary-only return/search-result pages are still treated as capture/error evidence unless enough item/amount detail is present. Automatic reconstruction of CREDIT memos from summary-only return cards remains review-only because returns may span multiple source invoices.


## v197

- Added `php8.5-zip` to the recommended local package list in the README and Config page. ZIP imports can fall back to CLI `unzip`, but the PHP zip extension is recommended for PHP-FPM.
- Added a Config → Recommended local packages card with `python3-venv`, `unzip`, and `php8.5-zip`, plus the PHP-FPM restart command.
- Updated Tractor Supply browser-launch instructions to pick the first available Chrome/Chromium launcher instead of hard-coding Snap Chromium.
- Added TSC notes explaining that Snap Chromium namespace/D-Bus/GPU/GCM/SSL/P2P messages are usually non-fatal if `DevTools listening on ws://127.0.0.1:9222/...` appears.
- Added an optional Snap Chromium `user-dirs.dirs` repair command for the specific `unexpected EOF while looking for matching quote` warning.

## v196

- Fixed Tractor Supply normalized ZIP import on PHP-FPM installs without the PHP `zip` extension.
- The TSC importer now matches the Lowe's ZIP behavior: it uses `ZipArchive` when available and falls back to the system `unzip` command when `ZipArchive` is not installed.
- If both methods are unavailable, the UI now returns a clear error instead of a PHP fatal; users can either install `php-zip` / `unzip` or import the extracted normalized folder directly.

## v198

- Added TSC parser version output so CLI runs clearly show which bundled scraper is being used.
- Added empty-output detection for Tractor Supply normalization. If raw HTML files exist but normalized CSVs would be header-only, the scraper now prints an explicit warning/error and writes a diagnostic row instead of silently producing an empty-looking ZIP.
- Added a documented reparse path for existing TSC raw captures: parse `./tractorsupply_scraper/<YEAR>-export/raw_html` into a fresh normalized folder/ZIP without rerunning browser capture.
- Verified against a user-supplied TSC capture where `2026-export/normalized/*.csv` were header-only but `2026-export/raw_html/*.html` contained parsable orders. The current parser recovers 10 orders, 31 item rows, and 10 payment rows from that raw capture.



## v200 notes

- Fixed the Tractor Supply capture command shown in the web UI. In v199, PHP interpolated the shell variables inside the displayed command, producing `--years ""`, `--out ""`, and `--zip-out ""`. The displayed command now preserves `$YEAR`, `$OUT`, and `$ZIP` correctly for the shell.
- No parser logic changes were required for this fix.


## v201 notes

- Tractor Supply capture now clears the selected run's `raw_html/` and `normalized/` directories by default before saving a fresh run. This prevents stale pages from earlier captures from being mixed into a new year/order-type scrape. Use `--append` only when intentionally merging captures.
- Tractor Supply in-store receipt detail pages often do not display an `Order Number:` label. The parser now recovers those pages by deriving a readable invoice ID from the captured `externalOrderId`, such as `TSC-INSTORE-20260605-100768210`.
- Manual Tractor Supply saved-page parsing should use `./tractorsupply_scraper/import/` as the input folder and write to `YYYY-manual-normalized/`; the parser now refuses to write an empty ZIP if the input folder contains no `.html`/`.htm` files.


## v202 Tractor Supply SKU/title resolution

Tractor Supply parser now resolves blank order-detail item titles using linked product pages, saved product-detail pages, and `tractorsupply_scraper/tsc_sku_descriptions.csv` as a fallback helper database. It also writes `tsc_sku_descriptions_suggested.csv` from learned PDP/URL slug mappings.


## v203 Tractor Supply repeated-SKU line preservation


Fixed Tractor Supply normalized ZIP import so repeated SKU rows on the same receipt are not overwritten in the review database. The normalized scraper already emits each source receipt line with a `line_index`, but the web importer previously used only SKU as `item_key`; because `order_items` is keyed by `(vendor, order_id, item_key)`, duplicate SKUs replaced earlier rows. TSC import now uses a line-specific key such as `TSC-LINE-0004-5050107` while still storing the actual SKU in the SKU/ASIN field for category propagation and account-rule matching.

Also added TSC discount inference for in-store pages where per-item subtotals are gross but the order-summary Subtotal is already net and no visible Discount row is present. The importer adds a reviewable `[DISCOUNT:TSC]` line for the difference, and the scraper writes that inferred discount into freshly normalized output.
