<?php
// Local-only Amazon/Costco/Walmart/Lowes order review tool for GnuCash bill CSV import.
// Run with: php -S 127.0.0.1:8080 -t /path/to/gnucash_vendor_import_tool
/*
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


declare(strict_types=1);

const APP_VERSION = '1.0.2';
const APP_DB = __DIR__ . '/data/review.sqlite';
const DEFAULT_VENDOR_AMAZON = '000005';
const DEFAULT_VENDOR_COSTCO = '000001';
const DEFAULT_VENDOR_WALMART = '000006';
const DEFAULT_VENDOR_LOWES = '000020';
const DEFAULT_VENDOR_HOME_DEPOT = '000000';
const DEFAULT_VENDOR_TRACTOR_SUPPLY = '000000';
const DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT = '';
const DEFAULT_TAX_ACCOUNT = 'Expenses:Tax:Sales Tax';
const DEFAULT_SHIPPING_ACCOUNT = 'Expenses:Shipping';
const DEFAULT_GIFT_WRAP_ACCOUNT = 'Expenses:Gifts Given';
if (function_exists('set_time_limit')) { @set_time_limit(300); }
const DEFAULT_PAYMENT_ACCOUNT_AMAZON = 'Liabilities:Credit Cards:Amazon Credit Card';
const DEFAULT_REWARDS_ACCOUNT_AMAZON = 'Assets:Other Current Assets:Amazon Credit Card Cash Back';
const DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON = 'Assets:Other Current Assets:Amazon Gift Card (Returns)';
const DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON = 'Assets:Other Current Assets:Amazon Prime for Young Adults cash back';
const DEFAULT_STORED_VALUE_OFFSET_ACCOUNT = 'Income:Credit Card Rewards';
const DEFAULT_STORED_VALUE_ACCOUNT_WALMART = 'Assets:Other Current Assets:Walmart Gift Card';
const DEFAULT_STORED_VALUE_ACCOUNT_COSTCO = 'Assets:Other Current Assets:Costco Shop Card';
const DEFAULT_STORED_VALUE_ACCOUNT_LOWES = "Assets:Other Current Assets:My Lowe's Money";
const DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY = '';
const DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS = '14';
const DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT = '100.00';
const DEFAULT_AP_ACCOUNT = 'Liabilities:Accounts Payable';
const DEFAULT_SCRAPER_YEARS = '';

// Tip/support banner settings.
// Set GNUCASH_TOOL_SHOW_DONATION_BANNER=0 to hide.
// Set GNUCASH_TOOL_DONATION_URL to override the default support link.
$donationUrl = getenv('GNUCASH_TOOL_DONATION_URL') ?: 'https://ko-fi.com/thewolfandtheraven';
$showDonationBanner = getenv('GNUCASH_TOOL_SHOW_DONATION_BANNER') !== '0';

function default_variable_config_builtin_defaults(): array {
    return [
        'DEFAULT_VENDOR_AMAZON' => DEFAULT_VENDOR_AMAZON,
        'DEFAULT_VENDOR_COSTCO' => DEFAULT_VENDOR_COSTCO,
        'DEFAULT_VENDOR_WALMART' => DEFAULT_VENDOR_WALMART,
        'DEFAULT_VENDOR_LOWES' => DEFAULT_VENDOR_LOWES,
        'DEFAULT_VENDOR_HOME_DEPOT' => DEFAULT_VENDOR_HOME_DEPOT,
        'DEFAULT_VENDOR_TRACTOR_SUPPLY' => DEFAULT_VENDOR_TRACTOR_SUPPLY,
        'DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT' => DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT,
        'DEFAULT_TAX_ACCOUNT' => DEFAULT_TAX_ACCOUNT,
        'DEFAULT_SHIPPING_ACCOUNT' => DEFAULT_SHIPPING_ACCOUNT,
        'DEFAULT_GIFT_WRAP_ACCOUNT' => DEFAULT_GIFT_WRAP_ACCOUNT,
        'DEFAULT_PAYMENT_ACCOUNT_AMAZON' => DEFAULT_PAYMENT_ACCOUNT_AMAZON,
        'DEFAULT_REWARDS_ACCOUNT_AMAZON' => DEFAULT_REWARDS_ACCOUNT_AMAZON,
        'DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON' => DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON,
        'DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON' => DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON,
        'DEFAULT_STORED_VALUE_OFFSET_ACCOUNT' => DEFAULT_STORED_VALUE_OFFSET_ACCOUNT,
        'DEFAULT_STORED_VALUE_ACCOUNT_WALMART' => DEFAULT_STORED_VALUE_ACCOUNT_WALMART,
        'DEFAULT_STORED_VALUE_ACCOUNT_COSTCO' => DEFAULT_STORED_VALUE_ACCOUNT_COSTCO,
        'DEFAULT_STORED_VALUE_ACCOUNT_LOWES' => DEFAULT_STORED_VALUE_ACCOUNT_LOWES,
        'DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY' => DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY,
        'DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS' => DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS,
        'DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT' => DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT,
        'DEFAULT_AP_ACCOUNT' => DEFAULT_AP_ACCOUNT,
        'DEFAULT_SCRAPER_YEARS' => (DEFAULT_SCRAPER_YEARS !== '' ? DEFAULT_SCRAPER_YEARS : date('Y')),
    ];
}

function user_defaults_config_path(): string {
    return __DIR__ . '/config/user_defaults.php';
}

function invalidate_user_defaults_config_cache(): void {
    $path = user_defaults_config_path();

    clearstatcache(true, $path);

    if (function_exists('opcache_invalidate') && is_file($path)) {
        @opcache_invalidate($path, true);
    }
}

function load_user_default_config_overrides(): array {
    $path = user_defaults_config_path();
    invalidate_user_defaults_config_cache();

    if (!is_readable($path)) {
        return [];
    }

    $data = require $path;

    if (!is_array($data)) {
        return [];
    }

    $allowed = array_fill_keys(array_keys(default_variable_config_builtin_defaults()), true);
    $out = [];

    foreach ($data as $key => $value) {
        $key = strtoupper(trim((string)$key));

        if ($key === '' || !isset($allowed[$key])) {
            continue;
        }

        $out[$key] = is_scalar($value) || $value === null ? trim((string)$value) : '';
    }

    return $out;
}

function default_variable_config_defaults(): array {
    return array_replace(
        default_variable_config_builtin_defaults(),
        load_user_default_config_overrides()
    );
}

function write_user_default_config(array $values): void {
    $path = user_defaults_config_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create config directory: ' . $dir);
    }

    if (file_exists($path) && !is_writable($path)) {
        throw new RuntimeException('User defaults file is not writable by PHP: ' . $path);
    }

    if (!file_exists($path) && !is_writable($dir)) {
        throw new RuntimeException('Config directory is not writable by PHP: ' . $dir);
    }

    $defaults = default_variable_config_builtin_defaults();
    $current = array_replace($defaults, load_user_default_config_overrides());

    foreach ($values as $key => $value) {
        $key = strtoupper(trim((string)$key));

        if (!array_key_exists($key, $defaults)) {
            continue;
        }

        $current[$key] = is_scalar($value) || $value === null ? trim((string)$value) : '';
    }

    $body = "<?php\n"
        . "/*\n"
        . " * Local default vendor/account configuration.\n"
        . " * This file is generated/updated by the Config page.\n"
        . " * It is intentionally ignored by Git and should survive hard resets.\n"
        . " */\n\n"
        . "return " . var_export($current, true) . ";\n";

    $tmp = $path . '.tmp.' . getmypid();

    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('Could not write temporary user defaults file: ' . $tmp);
    }

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace user defaults file: ' . $path);
    }
    invalidate_user_defaults_config_cache();
}

function clear_legacy_default_config_db_values(SQLite3 $db): void {
    foreach (array_keys(default_variable_config_builtin_defaults()) as $name) {
        $stmt = $db->prepare('DELETE FROM app_config WHERE key = :key');
        $stmt->bindValue(':key', default_config_key($name), SQLITE3_TEXT);
        $stmt->execute();
    }

    foreach ([
        'vendor_id_amazon',
        'vendor_id_costco',
        'vendor_id_walmart',
        'vendor_id_lowes',
        'vendor_id_home_depot',
        'vendor_id_tractor_supply',
    ] as $legacyKey) {
        $stmt = $db->prepare('DELETE FROM app_config WHERE key = :key');
        $stmt->bindValue(':key', $legacyKey, SQLITE3_TEXT);
        $stmt->execute();
    }
}

function default_variable_config_metadata(): array {
    return [
        'DEFAULT_VENDOR_AMAZON' => ['label'=>'Amazon vendor ID', 'group'=>'Vendor IDs', 'status'=>'Active importer'],
        'DEFAULT_VENDOR_COSTCO' => ['label'=>'Costco vendor ID', 'group'=>'Vendor IDs', 'status'=>'Active importer'],
        'DEFAULT_VENDOR_WALMART' => ['label'=>'Walmart vendor ID', 'group'=>'Vendor IDs', 'status'=>'Active importer'],
        'DEFAULT_VENDOR_LOWES' => ['label'=>"Lowe's vendor ID", 'group'=>'Vendor IDs', 'status'=>'Active Lowe\'s v10 normalized ZIP importer'],
        'DEFAULT_VENDOR_HOME_DEPOT' => ['label'=>'Home Depot vendor ID', 'group'=>'Vendor IDs', 'status'=>'Active CSV importer'],
        'DEFAULT_VENDOR_TRACTOR_SUPPLY' => ['label'=>'Tractor Supply vendor ID', 'group'=>'Vendor IDs', 'status'=>'Active initial parser/importer'],
        'DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT' => ['label'=>'Home Depot default card/payment account', 'group'=>'Payment accounts', 'status'=>'Optional; also inferred from payment mapping/register scans'],
        'DEFAULT_TAX_ACCOUNT' => ['label'=>'Sales tax expense account', 'group'=>'Bill export defaults', 'status'=>'Used for sales tax lines'],
        'DEFAULT_SHIPPING_ACCOUNT' => ['label'=>'Shipping expense account', 'group'=>'Bill export defaults', 'status'=>'Used for shipping/freight lines'],
        'DEFAULT_GIFT_WRAP_ACCOUNT' => ['label'=>'Gift wrap expense account', 'group'=>'Bill export defaults', 'status'=>'Used for Amazon gift wrap lines'],
        'DEFAULT_AP_ACCOUNT' => ['label'=>'Accounts payable posting account', 'group'=>'Bill export defaults', 'status'=>'Used when exporting posted bills'],
        'DEFAULT_PAYMENT_ACCOUNT_AMAZON' => ['label'=>'Amazon default card payment account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used for Amazon card/payment hints'],
        'DEFAULT_REWARDS_ACCOUNT_AMAZON' => ['label'=>'Amazon rewards/cash-back account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used for Amazon rewards/gift-card payments'],
        'DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON' => ['label'=>'Amazon gift card returns account', 'group'=>'Payment/stored-value accounts', 'status'=>'Reserved for Amazon return/gift-card handling'],
        'DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON' => ['label'=>'Amazon Prime Young Adults cash-back account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used when Amazon text identifies this reward type'],
        'DEFAULT_STORED_VALUE_OFFSET_ACCOUNT' => ['label'=>'Stored-value offset income account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used when exporting stored-value offset entries'],
        'DEFAULT_STORED_VALUE_ACCOUNT_WALMART' => ['label'=>'Walmart stored-value account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used for Walmart Cash / gift card payments'],
        'DEFAULT_STORED_VALUE_ACCOUNT_COSTCO' => ['label'=>'Costco stored-value account', 'group'=>'Payment/stored-value accounts', 'status'=>'Used for Costco Shop Card / reward payments'],
        'DEFAULT_STORED_VALUE_ACCOUNT_LOWES' => ['label'=>"Lowe's stored-value account", 'group'=>'Payment/stored-value accounts', 'status'=>"Used for My Lowe's Money payments"],
        'DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY' => ['label'=>'Tractor Supply stored-value/gift-card account', 'group'=>'Payment/stored-value accounts', 'status'=>'Reserved for Tractor Supply gift-card/reward support'],
        'DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS' => ['label'=>"Lowe's payment posting-lag tolerance, days", 'group'=>"Lowe's module settings", 'status'=>"Forward-looking window for online-order ship/charge settlement matching in Step 5a"],
        'DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT' => ['label'=>"Lowe's partial-return manual-stage minimum amount", 'group'=>"Lowe's module settings", 'status'=>"Returned lines at or above this amount are staged as reviewable CREDIT memos even when exact refund evidence is not found during import"],
        'DEFAULT_SCRAPER_YEARS' => ['label'=>'Years to process', 'group'=>'Scraper settings', 'status'=>'Enter years scrapers should process separated by a space, e.g. 2024 2025 2026. Defaults to the current system year.'],
    ];
}
function render_donation_banner(string $donationUrl, bool $showDonationBanner): void
{
    if (!$showDonationBanner || trim($donationUrl) === '') {
        return;
    }

    $safeUrl = htmlspecialchars($donationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<div class="donation-card">
  <div>
    <strong>GnuCash Vendor Import Tool is free and open source.</strong>
    <span>If this project saves you time, optional tips help fund continued maintenance and new vendor modules.</span>
  </div>
  <a href="{$safeUrl}" target="_blank" rel="noopener noreferrer">Support development</a>
</div>
HTML;
}
function default_config_key(string $defaultName): string {
    return 'default_var_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper(trim($defaultName)));
}
function default_config_cache_reset(): void {
    $GLOBALS['_default_config_cache'] = null;
}
function default_config_value(string $defaultName, ?SQLite3 $db = null): string {
    $defaults = default_variable_config_defaults();
    $defaultName = strtoupper(trim($defaultName));

    return array_key_exists($defaultName, $defaults)
        ? (string)$defaults[$defaultName]
        : '';
}

function default_config_values(?SQLite3 $db = null): array {
    return default_variable_config_defaults();
}

function scraper_years_config(?SQLite3 $db = null): string {
    $raw = trim((string)default_config_value('DEFAULT_SCRAPER_YEARS', $db));
    if ($raw === '') $raw = date('Y');
    preg_match_all('/\b(19\d{2}|20\d{2}|21\d{2})\b/', $raw, $m);
    $years = [];
    foreach (($m[1] ?? []) as $year) {
        $year = (string)$year;
        if (!isset($years[$year])) $years[$year] = $year;
    }
    if (!$years) $years[date('Y')] = date('Y');
    return implode(' ', array_values($years));
}

function scraper_years_csv_config(?SQLite3 $db = null): string {
    return str_replace(' ', ',', scraper_years_config($db));
}

function lowes_payment_match_date_window_days(?SQLite3 $db = null): int {
    $raw = trim((string)default_config_value('DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS', $db));
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return 14;
    return max(0, min(365, (int)$raw));
}
function lowes_partial_return_manual_stage_min_amount(?SQLite3 $db = null): float {
    $raw = trim((string)default_config_value('DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT', $db));
    if ($raw === '' || !is_numeric($raw)) return 100.00;
    return max(0.0, min(100000.0, round((float)$raw, 2)));
}
function render_lowes_step5_controls(SQLite3 $db, string $modeValue, string $vendorStepValue, string $anchor = 'lowes-payment-dry-run'): void {
    [$rangeStart, $rangeEnd] = payment_match_window($db);
    $returnMode = in_array($modeValue, ['lowes','bills'], true) ? $modeValue : 'lowes';
    $returnStep = in_array($vendorStepValue, ['scrape','import','review','stored_value','match_dry_run','match_apply','diagnostics'], true) ? $vendorStepValue : 'match_dry_run';
    ?>
<div class="card" id="lowes-step5-controls" style="background:#eef7ff;border-color:#1f6fb2">
<h3>Lowe's Step 5 payment matching controls</h3>
<p class="small">These are two separate settings. The <strong>payment date range filter</strong> chooses which staged Lowe's payment rows are included in Step 5 plans/reports. The <strong>posting-lag tolerance</strong> controls how many days after the vendor payment/order date the matcher may search the mapped account for shipped/split online charges.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;margin-bottom:.6rem;background:#fff;border:1px solid #b7d7f0;border-radius:6px;padding:.5rem">
<input type="hidden" name="action" value="save_transaction_match_window">
<input type="hidden" name="mode" value="<?=h($returnMode)?>">
<input type="hidden" name="vendor_hint" value="lowes">
<input type="hidden" name="vendor_step" value="<?=h($returnStep)?>">
<input type="hidden" name="return_mode" value="<?=h($returnMode)?>">
<input type="hidden" name="return_vendor_step" value="<?=h($returnStep)?>">
<label>Step 5 payment date start<input type="date" name="payment_match_start_date" value="<?=h($rangeStart)?>" style="width:11rem"></label>
<label>Step 5 payment date end<input type="date" name="payment_match_end_date" value="<?=h($rangeEnd)?>" style="width:11rem"></label>
<button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Save Step 5 date range filter</button>
<span class="small">Current range: <strong><?=h($rangeStart !== '' ? $rangeStart : 'open')?></strong> through <strong><?=h($rangeEnd !== '' ? $rangeEnd : 'open')?></strong>. Leave both blank for all staged Lowe's payment rows.</span>
</form>
<form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;background:#fff;border:1px solid #b7d7f0;border-radius:6px;padding:.5rem">
<input type="hidden" name="action" value="save_lowes_module_config">
<input type="hidden" name="mode" value="<?=h($returnMode)?>">
<input type="hidden" name="vendor_hint" value="lowes">
<input type="hidden" name="vendor_step" value="<?=h($returnStep)?>">
<input type="hidden" name="return_mode" value="<?=h($returnMode)?>">
<input type="hidden" name="return_vendor_step" value="<?=h($returnStep)?>">
<input type="hidden" name="lowes_partial_return_manual_stage_min_amount" value="<?=h(fmt_money(lowes_partial_return_manual_stage_min_amount($db)))?>">
<label>Posting-lag tolerance, days<input type="number" min="0" max="365" name="lowes_payment_match_date_window_days" value="<?=h((string)lowes_payment_match_date_window_days($db))?>" style="width:8rem;font-weight:bold"></label>
<button type="submit">Save posting-lag tolerance</button>
<span class="small">Current tolerance: <strong><?=h((string)lowes_payment_match_date_window_days($db))?> day(s)</strong>. Used by Step 5a and Step 5b to match charges that post after shipment.</span>
</form>
</div>
<?php
}
function save_default_config_values(SQLite3 $db, array $request): void {
    $posted = (array)($request['default_var'] ?? []);
    write_user_default_config($posted);
    clear_legacy_default_config_db_values($db);
    default_config_cache_reset();
}


function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function path_is_under(string $path, string $root): bool {
    $rp = realpath($path);
    $rr = realpath($root);
    if ($rp === false || $rr === false) return false;
    $rp = rtrim(str_replace('\\', '/', $rp), '/') . '/';
    $rr = rtrim(str_replace('\\', '/', $rr), '/') . '/';
    return str_starts_with($rp, $rr);
}
function resolve_app_local_path(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home !== '') $path = rtrim($home, '/') . substr($path, 1);
    }
    if ($path === '.' || str_starts_with($path, './')) {
        return __DIR__ . '/' . ltrim($path, './');
    }
    if (!preg_match('~^/~', $path)) {
        return __DIR__ . '/' . $path;
    }
    return $path;
}

function app_relative_path(string $path): string {
    $base = rtrim(str_replace('\\', '/', __DIR__), '/') . '/';
    $path = str_replace('\\', '/', $path);
    if (str_starts_with($path, $base)) return './' . ltrim(substr($path, strlen($base)), '/');
    return $path;
}
function find_vendor_normalized_path_candidates(string $vendor, int $limit = 8): array {
    $vendor = strtolower($vendor);
    $patterns = [];
    if ($vendor === 'lowes' || $vendor === 'auto') {
        $patterns[] = __DIR__ . '/lowes_scraper/*/normalized';
        $patterns[] = __DIR__ . '/lowes_scraper/*-normalized';
        $patterns[] = __DIR__ . '/lowes_scraper/*-normalized.zip';
        $patterns[] = __DIR__ . '/lowes_scraper/*-test-normalized.zip';
        $patterns[] = __DIR__ . '/lowes_scraper/*-reparse-normalized.zip';
    }
    if ($vendor === 'tractor_supply' || $vendor === 'auto') {
        $patterns[] = __DIR__ . '/tractorsupply_scraper/*/normalized';
        $patterns[] = __DIR__ . '/tractorsupply_scraper/*-normalized';
        $patterns[] = __DIR__ . '/tractorsupply_scraper/*-normalized.zip';
    }
    $seen = [];
    $rows = [];
    foreach ($patterns as $pat) {
        foreach (glob($pat, GLOB_NOSORT) ?: [] as $cand) {
            if (!is_readable($cand) || (!is_dir($cand) && !is_file($cand))) continue;
            $real = realpath($cand) ?: $cand;
            if (isset($seen[$real])) continue;
            $seen[$real] = true;
            $mtime = @filemtime($cand) ?: 0;
            $rows[] = ['path'=>$cand, 'mtime'=>$mtime];
        }
    }
    usort($rows, fn($a,$b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['path'],$b['path']));
    return array_slice(array_map(fn($r) => app_relative_path((string)$r['path']), $rows), 0, max(1,$limit));
}
function local_import_path_error_with_suggestions(string $vendor, string $localPath, string $input): string {
    $msg = 'Local import path is not readable by PHP-FPM: ' . $localPath . ' (input was: ' . $input . ')';
    $cands = find_vendor_normalized_path_candidates($vendor, 6);
    if (!empty($cands)) {
        $msg .= '. Existing normalized outputs found: ' . implode(', ', array_map(fn($x) => $x, $cands)) . '. Copy one of these exact paths into the local import field.';
    }
    return $msg;
}
function clean_generated_scraper_files(string $path, string $kind = 'normalized'): array {
    $resolved = resolve_app_local_path($path);
    if ($resolved === '') return [0, 0, 'Clean path is blank.', []];
    $real = realpath($resolved);
    if ($real === false || !is_dir($real)) return [0, 0, 'Clean path must be an existing directory: ' . $resolved, []];
    $allowedRoots = [__DIR__ . '/lowes_scraper', __DIR__ . '/tractorsupply_scraper', __DIR__ . '/homedepot_scraper', __DIR__ . '/exports'];
    $okRoot = false;
    foreach ($allowedRoots as $root) {
        if (is_dir($root) && path_is_under($real, $root)) { $okRoot = true; break; }
    }
    if (!$okRoot) return [0, 0, 'Refusing to clean outside the tool scraper/export directories. Move scraper output under ./lowes_scraper/, ./tractorsupply_scraper/, ./homedepot_scraper/, or ./exports/.', []];

    $kind = strtolower(trim($kind));
    $allowed = match ($kind) {
        'normalized' => ['csv'=>true, 'json'=>true],
        'raw' => ['html'=>true, 'htm'=>true, 'json'=>true],
        'zip' => ['zip'=>true],
        'all' => ['csv'=>true, 'json'=>true, 'html'=>true, 'htm'=>true, 'zip'=>true],
        default => ['csv'=>true, 'json'=>true],
    };
    $deleted = 0; $skipped = 0; $samples = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) { $skipped++; continue; }
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) { $skipped++; continue; }
        $filePath = $file->getPathname();
        if (!path_is_under($filePath, $real)) { $skipped++; continue; }
        if (@unlink($filePath)) {
            $deleted++;
            if (count($samples) < 8) $samples[] = str_replace(rtrim($real, '/') . '/', '', $filePath);
        } else {
            $skipped++;
        }
    }
    return [$deleted, $skipped, '', $samples];
}
function money_to_float(?string $s): float {
    $s = trim((string)$s);
    if ($s === '' || preg_match('/^(total|tax|shipping|price)$/i', $s)) return 0.0;
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    return $s === '' ? 0.0 : (float)$s;
}
function fmt_money(float $v): string { return number_format($v, 2, '.', ''); }
function fmt_unit_price(float $v, float $qty = 1.0): string {
    // GnuCash accepts decimal prices. For fuel and variable-weight Costco rows,
    // rounding unit price to cents can make quantity*price drift by a penny or two.
    // Use up to 6 decimals when needed, trimmed for readability.
    $two = round($v, 2);
    if (abs(round($qty * $v, 2) - round($qty * $two, 2)) > 0.0001) {
        $out = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return $out === '' || $out === '-0' ? '0' : $out;
    }
    return number_format($v, 2, '.', '');
}
function fmt_quantity(float $v): string {
    $s = number_format($v, 6, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    if ($s === '' || $s === '-0') return '0';
    return $s;
}
function now_sql(): string { return date('Y-m-d H:i:s'); }

function dedupe_warning_text(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    if ($s === '') return '';
    $parts = preg_split('/(?<=\.)\s+/', $s) ?: [$s];
    $seen = [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $key = strtolower($part);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $part;
    }
    return trim(implode(' ', $out));
}

function normalize_account_name_for_compare(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return strtolower($s ?? '');
}
function account_names_equivalent(string $a, string $b): bool {
    return normalize_account_name_for_compare($a) === normalize_account_name_for_compare($b);
}
function normalize_import_date(string $s, string $timezone = 'America/New_York'): string {
    $s = trim($s);
    if ($s === '') return '';
    // Date-only values should not be shifted by timezone.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $s, $m)) {
        $y=(int)$m[3]; if($y<100) $y += 2000;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
    }
    try {
        $dt = new DateTimeImmutable($s);
        return $dt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    } catch (Throwable $e) {
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : $s;
    }
}
function date_diff_days(?string $a, ?string $b): ?int {
    $a = normalize_import_date((string)$a); $b = normalize_import_date((string)$b);
    if ($a === '' || $b === '') return null;
    try {
        $da = new DateTimeImmutable($a);
        $db = new DateTimeImmutable($b);
        return (int)$da->diff($db)->format('%r%a');
    } catch (Throwable $e) { return null; }
}
function first_payment_date_from_state(array $state, string $preferredAccount = ''): string {
    $preferredAccount = trim($preferredAccount);
    foreach ((array)($state['payment_accounts'] ?? []) as $pa) {
        $acct = (string)($pa['account'] ?? '');
        if ($preferredAccount !== '' && !account_names_equivalent($acct, $preferredAccount)) continue;
        $d = normalize_import_date((string)($pa['date'] ?? ''));
        if ($d !== '') return $d;
    }
    foreach ((array)($state['payment_accounts'] ?? []) as $pa) {
        $d = normalize_import_date((string)($pa['date'] ?? ''));
        if ($d !== '') return $d;
    }
    return '';
}

function export_account_name(string $acct, string $prefix): string {
    $acct = trim($acct);
    $prefix = trim($prefix);
    if ($prefix === '' || $acct === '' || str_starts_with($acct, $prefix . ':')) return $acct;
    return $prefix . ':' . $acct;
}
function count_exportable_orders(SQLite3 $db): int {
    return (int)$db->querySingle('SELECT COUNT(*) FROM orders WHERE skip=0');
}
function count_staged_orders(SQLite3 $db): int {
    return (int)$db->querySingle('SELECT COUNT(*) FROM orders');
}
function count_skipped_orders(SQLite3 $db): int {
    return (int)$db->querySingle('SELECT COUNT(*) FROM orders WHERE skip<>0');
}

function sqlite_exec_retry(SQLite3 $db, string $sql, int $tries = 8): bool {
    $last = null;
    for ($i = 0; $i < $tries; $i++) {
        try {
            $ok = $db->exec($sql);
            if ($ok) return true;
        } catch (Throwable $e) {
            $last = $e;
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'locked') && !str_contains($msg, 'busy')) throw $e;
        }
        usleep(250000 * ($i + 1));
    }
    if ($last) throw $last;
    return false;
}

function retry_sqlite_write(callable $fn, int $tries = 8) {
    $last = null;

    for ($i = 0; $i < $tries; $i++) {
        try {
            $result = $fn();
            if ($result !== false) {
                return $result;
            }
        } catch (Throwable $e) {
            $last = $e;
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'locked') && !str_contains($msg, 'busy')) {
                throw $e;
            }
        }

        usleep(250000 * ($i + 1));
    }

    if ($last) {
        throw $last;
    }

    return false;
}


function clear_working_dataset(SQLite3 $db): void {
    // Soft clear should not touch account_rules or extra_accounts; those are the SKU/ASIN training data.
    // Do not run WAL checkpoint here.  The app now uses DELETE journal mode and a checkpoint inside
    // an active transaction can leave the reset path stuck behind a SQLite lock.
    try { $db->exec('ROLLBACK'); } catch (Throwable $ignore) {}
    sqlite_exec_retry($db, 'BEGIN IMMEDIATE');
    try {
        sqlite_exec_retry($db, 'DELETE FROM order_items');
        sqlite_exec_retry($db, 'DELETE FROM orders');
        sqlite_exec_retry($db, "DELETE FROM app_config WHERE key IN ('last_invalid_accounts','export_batch_number')");
        sqlite_exec_retry($db, 'COMMIT');
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignore) {}
        throw $e;
    }
}


function soft_reset_for_new_import_preserve_rules(SQLite3 $db): void {
    // Clears only active staged/review data so a new Amazon/Costco dataset can be imported.
    // Keeps learned SKU/ASIN/category rules, local account options, GnuCash path, and export settings.
    clear_working_dataset($db);
}

function count_vendor_payment_rows(SQLite3 $db, string $vendor = ''): int {
    $where = $vendor !== '' ? " WHERE vendor='" . SQLite3::escapeString($vendor) . "'" : '';
    return (int)$db->querySingle('SELECT COUNT(*) FROM vendor_payments' . $where);
}

function clear_transaction_matching_dataset(SQLite3 $db, string $vendor = 'amazon'): int {
    // Transaction/payment matching reset is intentionally separate from bill/invoice reset.
    // It clears imported payment rows and generated payment reports, but keeps payment-method
    // account mappings/exclusions so cards do not need to be remapped every time.
    $vendor = strtolower(trim($vendor));
    if ($vendor === '' || $vendor === 'all') {
        $before = count_vendor_payment_rows($db, '');
        sqlite_exec_retry($db, 'DELETE FROM vendor_payments');
    } else {
        $before = count_vendor_payment_rows($db, $vendor);
        $stmt = $db->prepare('DELETE FROM vendor_payments WHERE vendor=:vendor');
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $stmt->execute();
    }
    return $before;
}

function table_exists(SQLite3 $db, string $table): bool {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:name");
    $stmt->bindValue(':name', $table, SQLITE3_TEXT);
    $res = $stmt->execute();
    return (bool)$res->fetchArray(SQLITE3_ASSOC);
}
function dump_table_assoc(SQLite3 $db, string $table): array {
    if (!table_exists($db, $table)) return [];
    $rows = [];
    $res = $db->query('SELECT * FROM ' . $table);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}
function insert_assoc_row(SQLite3 $db, string $table, array $row): void {
    if (!$row) return;
    $cols = array_keys($row);
    $quoted = array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols);
    $ph = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $quoted) . ') VALUES (' . implode(',', $ph) . ')';
    $stmt = $db->prepare($sql);
    foreach ($row as $k => $v) {
        if ($v === null) $stmt->bindValue(':' . $k, null, SQLITE3_NULL);
        elseif (is_int($v)) $stmt->bindValue(':' . $k, $v, SQLITE3_INTEGER);
        elseif (is_float($v)) $stmt->bindValue(':' . $k, $v, SQLITE3_FLOAT);
        else $stmt->bindValue(':' . $k, (string)$v, SQLITE3_TEXT);
    }
    $stmt->execute();
}
function hard_reset_review_database_preserving_settings(SQLite3 $db): SQLite3 {
    $preserveConfig = dump_table_assoc($db, 'app_config');
    $preserveRules = dump_table_assoc($db, 'account_rules');
    $preserveExtra = dump_table_assoc($db, 'extra_accounts');
    $preservePaymentMethods = dump_table_assoc($db, 'payment_method_accounts');
    try { $db->exec('ROLLBACK'); } catch (Throwable $ignore) {}
    $db->close();
    clearstatcache();
    foreach ([APP_DB . '-wal', APP_DB . '-shm', APP_DB . '-journal', APP_DB] as $file) {
        for ($i = 0; $i < 8 && file_exists($file); $i++) {
            if (@unlink($file)) break;
            usleep(250000 * ($i + 1));
            clearstatcache(false, $file);
        }
        if (file_exists($file)) {
            throw new RuntimeException('Could not delete ' . $file . '. Check ownership/permissions or stop php8.5-fpm and remove it manually.');
        }
    }
    $new = connect_app_db();
    foreach ($preserveConfig as $row) {
        if (in_array((string)($row['key'] ?? ''), ['last_invalid_accounts','export_batch_number'], true)) continue;
        insert_assoc_row($new, 'app_config', $row);
    }
    foreach ($preserveRules as $row) insert_assoc_row($new, 'account_rules', $row);
    foreach ($preserveExtra as $row) insert_assoc_row($new, 'extra_accounts', $row);
    foreach ($preservePaymentMethods as $row) insert_assoc_row($new, 'payment_method_accounts', $row);
    return $new;
}
function db_file_diagnostics(): string {
    $files = [APP_DB, APP_DB . '-wal', APP_DB . '-shm'];
    $parts = [];
    foreach ($files as $f) {
        if (!file_exists($f)) { $parts[] = basename($f) . ': missing'; continue; }
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid((int)fileowner($f))['name'] ?? (string)fileowner($f)) : (string)fileowner($f);
        $group = function_exists('posix_getgrgid') ? (posix_getgrgid((int)filegroup($f))['name'] ?? (string)filegroup($f)) : (string)filegroup($f);
        $parts[] = basename($f) . ': ' . substr(sprintf('%o', fileperms($f)), -4) . ' ' . $owner . ':' . $group . ' writable=' . (is_writable($f) ? 'yes' : 'no');
    }
    return implode('; ', $parts);
}
function clean_review_url(string $gnucashPath, string $vendorHint = ''): string {
    // Do not include gnucash_path in the URL.  A stale gnucash_path query string can
    // override the stored path and make the tool appear to revert to an older book copy.
    // The path is stored in app_config and updated only by the Save/load path form or upload.
    $q = ['page' => 1, 'per_page' => 25, 'filter' => 'all', 'search' => ''];
    if ($vendorHint !== '') $q['vendor_hint'] = $vendorHint;
    $q['new_dataset_ready'] = '1';
    return strtok((string)($_SERVER['PHP_SELF'] ?? ''), '?') . '?' . http_build_query($q);
}

function stored_value_payment_amount(array $order): float {
    // The orders.gift column is now used generically for stored-value payments that
    // reduce the card charge but should not reduce the vendor bill: Amazon gift-card
    // balances/rewards, Walmart Cash/gift cards, and Costco Shop Card/Cash/rebate tenders.
    return abs((float)($order['gift'] ?? 0.0));
}

function amazon_gift_payment_amount(array $order): float { return stored_value_payment_amount($order); }

function amazon_method_is_prime_young_cashback(string $method): bool {
    return amazon_text_mentions_prime_young_adults_cashback($method);
}

function amazon_method_is_points_or_rewards(string $method): bool {
    $m = strtolower($method);
    return str_contains($m, 'visa points') || str_contains($m, 'points used') || str_contains($m, 'reward') || str_contains($m, 'cash-back') || str_contains($m, 'cash back') || str_contains($m, 'gift card');
}


function amazon_extract_labeled_amount_from_text(string $text, array $labels): float {
    $text = trim($text);
    if ($text === '') return 0.0;
    foreach ($labels as $label) {
        $q = preg_quote($label, '/');
        $patterns = [
            '/(?:'.$q.')[^$\d-]{0,80}-?\$\s*(\d[\d,]*(?:\.\d{1,2})?)/i',
            '/-?\$\s*(\d[\d,]*(?:\.\d{1,2})?)[^A-Za-z0-9]{0,40}(?:'.$q.')/i',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text, $m)) return abs(money_to_float((string)$m[1]));
        }
    }
    return 0.0;
}

function amazon_order_text_stored_value_amount_for_method(array $order, string $method): float {
    // v146: Prefer an explicit labeled amount from the Amazon order text when
    // splitting a grouped stored-value/rewards transaction.  This is important for
    // batched rewards rows where the local staged/order math may already have been
    // distorted by an earlier proportional allocation.  Example: a $17.11 Amazon
    // Visa-points transaction naming two orders should use the order summaries
    // "Rewards Points -$6.37" and "Rewards Points -$10.74", not a proportional
    // split such as $14.69/$2.42.
    if (amazon_method_is_prime_young_cashback($method)) return 0.0;
    $m = strtolower($method);
    $text = implode(' ', [
        (string)($order['payments'] ?? ''),
        (string)($order['notes'] ?? ''),
        (string)($order['warning'] ?? ''),
        (string)($order['items'] ?? ''),
    ]);
    if (str_contains($m, 'visa points') || str_contains($m, 'reward') || str_contains($m, 'point')) {
        $v = amazon_extract_labeled_amount_from_text($text, ['Amazon Visa points', 'Rewards Points', 'Reward Points', 'Amazon Points used', 'Amazon Points']);
        if ($v > 0.005) return round($v, 2);
    }
    if (str_contains($m, 'gift')) {
        $v = amazon_extract_labeled_amount_from_text($text, ['Gift Card Amount', 'Amazon gift card balance', 'Gift Card', 'gift card']);
        if ($v > 0.005) return round($v, 2);
    }
    if (str_contains($m, 'cash back') || str_contains($m, 'cash-back')) {
        $v = amazon_extract_labeled_amount_from_text($text, ['cash back', 'cash-back', 'rewards/cash-back']);
        if ($v > 0.005) return round($v, 2);
    }
    return 0.0;
}

function amazon_prime_young_adults_cashback_method(): string {
    return 'Prime for Young Adults cash back';
}
function amazon_text_mentions_prime_young_adults_cashback(string $text): bool {
    $t = strtolower($text);
    return (str_contains($t, 'prime for young adults') && str_contains($t, 'cash back'))
        || str_contains($t, 'young adults cash back')
        || str_contains($t, 'young adult cash back');
}

function amazon_order_mentions_audible_credit(string $items, string $payments): bool {
    $hay = strtolower($items . ' ' . $payments);
    return str_contains($hay, 'audible credit')
        || str_contains($hay, 'audiblecredit')
        || (str_contains($hay, 'audible') && str_contains($hay, 'no current charges'));
}

function mark_audible_credit_no_charge_orders(SQLite3 $db): int {
    // Amazon/Audible orders paid with an Audible credit can be exported as a numeric
    // "1" because the payment text says "1 Audible credit" even though the Amazon
    // order detail page shows no current charges and a 0.00 order total.  Treat these
    // as no-charge records and skip them so a fake $1 gift-wrap/reconciliation line is
    // not created.
    $sel = $db->query("SELECT order_id, items, payments, total, tax, shipping, shipping_refund, gift, refund, warning, notes
                       FROM orders
                       WHERE vendor='amazon'
                         AND COALESCE(skip,0)=0
                         AND ABS(COALESCE(tax,0)) < 0.005
                         AND ABS(COALESCE(shipping,0)) < 0.005
                         AND ABS(COALESCE(shipping_refund,0)) < 0.005
                         AND ABS(COALESCE(gift,0)) < 0.005
                         AND ABS(COALESCE(refund,0)) < 0.005
                         AND ABS(COALESCE(total,0)) <= 1.01");
    $upd = $db->prepare('UPDATE orders
                         SET total=0, item_amount=0, gift=0, skip=1,
                             warning=:warning,
                             notes=trim(COALESCE(notes,"") || " " || :note)
                         WHERE vendor="amazon" AND order_id=:order_id');
    $n = 0;
    while ($r = $sel->fetchArray(SQLITE3_ASSOC)) {
        $items = (string)($r['items'] ?? '');
        $payments = (string)($r['payments'] ?? '');
        if (!amazon_order_mentions_audible_credit($items, $payments)) continue;
        $w = trim((string)($r['warning'] ?? ''));
        $add = 'Audible-credit/no-current-charge Amazon order; treated as a $0 no-charge record and skipped.';
        if (!str_contains(strtolower($w), 'audible-credit')) $w = trim($w . ' ' . $add);
        $upd->bindValue(':warning', $w, SQLITE3_TEXT);
        $upd->bindValue(':note', 'Audible credit detected in payment/order text; no vendor bill exported.', SQLITE3_TEXT);
        $upd->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
        $upd->execute();
        $n++;
    }
    return $n;
}

function order_bill_total_for_validation(array $order): float {
    $vendor = strtolower((string)($order['vendor'] ?? ''));
    $total = (float)($order['total'] ?? 0.0);
    if (in_array($vendor, ['amazon','walmart','costco','lowes','tractor_supply','home_depot'], true)) {
        return round($total + stored_value_payment_amount($order), 2);
    }
    return round($total, 2);
}

function vendor_payment_hint(array $order): string {
    $vendor = strtolower((string)($order['vendor'] ?? ''));
    $stored = stored_value_payment_amount($order);
    $charged = round((float)($order['total'] ?? 0.0), 2);
    $bill = order_bill_total_for_validation($order);
    $parts = [];
    if ($vendor === '') return '';
    $label = ucfirst($vendor);
    $parts[] = $label . ' bill total: $' . fmt_money($bill);
    if ($vendor === 'amazon') {
        if ($charged > 0.005) $parts[] = 'Prime Visa / card charged: $' . fmt_money($charged) . ' -> ' . default_config_value('DEFAULT_PAYMENT_ACCOUNT_AMAZON');
        $paymentText = (string)($order['payments'] ?? '');
        if ($stored > 0.005) {
            $acct = amazon_text_mentions_prime_young_adults_cashback($paymentText) ? default_config_value('DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON') : default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON');
            $label = amazon_text_mentions_prime_young_adults_cashback($paymentText) ? 'Prime for Young Adults cash back' : 'Amazon Gift Card / Rewards payment';
            $parts[] = $label . ': $' . fmt_money($stored) . ' -> ' . $acct;
        }
    } elseif ($vendor === 'walmart') {
        if ($charged > 0.005) $parts[] = 'Walmart card/remaining charge: $' . fmt_money($charged);
        if ($stored > 0.005) $parts[] = 'Walmart Cash / Gift Card payment: $' . fmt_money($stored) . ' -> ' . default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_WALMART');
    } elseif ($vendor === 'costco') {
        if ($charged > 0.005) $parts[] = 'Costco card/remaining charge: $' . fmt_money($charged);
        if ($stored > 0.005) $parts[] = 'Costco Cash / Shop Card / 2% reward payment: $' . fmt_money($stored) . ' -> ' . default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_COSTCO');
    } elseif ($vendor === 'lowes') {
        if ($charged > 0.005) $parts[] = "Lowe's card/remaining charge: $" . fmt_money($charged);
        if ($stored > 0.005) $parts[] = "My Lowe's Money payment: $" . fmt_money($stored) . ' -> ' . default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES');
    } elseif ($vendor === 'home_depot') {
        if (abs($charged) > 0.005) $parts[] = 'Home Depot CSV line subtotal / expected register amount: $' . fmt_money($charged);
        $parts[] = 'Home Depot purchase-history CSV does not include tender detail or tax; reconcile against register/free-floating scan.';
    } elseif ($vendor === 'tractor_supply') {
        if ($charged > 0.005) $parts[] = 'Tractor Supply card/remaining charge: $' . fmt_money($charged);
        if ($stored > 0.005) $parts[] = 'Tractor Supply gift-card/reward payment: $' . fmt_money($stored) . ' -> ' . default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY');
    }
    if ($charged <= 0.005 && $stored <= 0.005) $parts[] = 'No payment amount detected in export.';
    return implode('; ', $parts);
}

function amazon_payment_hint(array $order): string { return vendor_payment_hint($order); }

function invoice_missing_payment_method_identified(array $order): bool {
    $payments = trim((string)($order['payments'] ?? ''));
    if ($payments === '') return true;
    $p = strtolower($payments);
    foreach ([
        'does not include tender',
        'tender detail not included',
        'tender details not included',
        'payment detail not included',
        'payment details not included',
        'payment method not identified',
        'register/tender details not included',
        'register details not included',
        'no payment amount detected',
        'use payment mapping/free-floating scan',
    ] as $needle) {
        if (str_contains($p, $needle)) return true;
    }
    return false;
}


function stored_value_payment_from_text(string $text, string $vendor): float {
    $text = trim($text);
    if ($text === '') return 0.0;
    $patterns = [];
    if ($vendor === 'walmart') {
        $patterns = [
            '/(?:walmart\s+cash|gift\s*card|e\s*gift\s*card|reward(?:s)?)[^$\d-]*\$?\s*(-?\d[\d,]*(?:\.\d{1,2})?)/i',
            '/\$\s*(-?\d[\d,]*(?:\.\d{1,2})?)\s*(?:walmart\s+cash|gift\s*card|e\s*gift\s*card|reward(?:s)?)/i',
        ];
    } elseif ($vendor === 'costco') {
        $patterns = [
            '/(?:costco\s+cash|shop\s*card|cash\s*card|reward\s*certificate|executive\s+reward|2%\s*reward|rebate)[^$\d-]*\$?\s*(-?\d[\d,]*(?:\.\d{1,2})?)/i',
            '/\$\s*(-?\d[\d,]*(?:\.\d{1,2})?)\s*(?:costco\s+cash|shop\s*card|cash\s*card|reward\s*certificate|executive\s+reward|2%\s*reward|rebate)/i',
        ];
    } elseif ($vendor === 'lowes') {
        $patterns = [
            '/(?:my\s*lowe\'?s\s+money|lowe\'?s\s+money|gift\s*card|gc)[^$\d-]*\$?\s*(-?\d[\d,]*(?:\.\d{1,2})?)/i',
            '/\$\s*(-?\d[\d,]*(?:\.\d{1,2})?)\s*(?:my\s*lowe\'?s\s+money|lowe\'?s\s+money|gift\s*card|gc)/i',
        ];
    } elseif ($vendor === 'tractor_supply') {
        $patterns = [
            '/(?:gift\s*card|reward|neighbor\'?s\s*club)[^$\d-]*\$?\s*(-?\d[\d,]*(?:\.\d{1,2})?)/i',
            '/\$\s*(-?\d[\d,]*(?:\.\d{1,2})?)\s*(?:gift\s*card|reward|neighbor\'?s\s*club)/i',
        ];
    }
    $sum = 0.0;
    foreach ($patterns as $pat) {
        if (preg_match_all($pat, $text, $m)) {
            foreach ($m[1] as $amt) $sum += abs(money_to_float((string)$amt));
        }
    }
    return round($sum, 2);
}

function costco_stored_value_payment_from_tenders(array $rec): float {
    $stored = 0.0;
    $allTender = 0.0;
    foreach (($rec['tenderArray'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $amt = abs((float)($t['amountTender'] ?? 0.0));
        $allTender += $amt;
        $desc = strtolower(trim((string)($t['tenderDescription'] ?? '') . ' ' . (string)($t['tenderTypeCode'] ?? '')));
        // Do not treat generic Costco online "Coupon" tenders as payment. Those are
        // merchant discounts and are already handled through orderDiscountAmount.
        if (preg_match('/shop\s*card|cash\s*card|costco\s+cash|reward|rebate|certificate|2\s*%/', $desc)) {
            $stored += $amt;
        }
    }
    $reported = abs((float)($rec['total'] ?? 0.0));
    // Warehouse receipts usually report the full bill total, and tender lines merely
    // show how that total was paid. In that case, do not add stored-value tender again.
    // Online/order-history receipts may report only the remaining card charge; then
    // tender sum exceeds reported total and the stored-value amount must be added back.
    if ($stored > 0.005 && $allTender > $reported + 0.01) return round($stored, 2);
    return 0.0;
}

function clean_text(string $s, int $max = 240): string {
    $s = trim((string)preg_replace('/\s+/', ' ', $s));
    $s = (string)preg_replace('/;\s*$/', '', $s);
    // Avoid requiring php-mbstring on a minimal desktop PHP install.
    // Amazon descriptions are normally UTF-8, but plain substr is acceptable for display truncation.
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 3, 'UTF-8') . '...' : $s;
    }
    return strlen($s) > $max ? substr($s, 0, max(0, $max - 3)) . '...' : $s;
}

function is_valid_http_url(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;
    if (!preg_match('#^https?://#i', $url)) return false;
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
function walmart_search_url(string $description, string $productKey = ''): string {
    $q = trim($description);
    if ($q === '' || preg_match('/^\[WALMART:[^\]]+\]\s*(.*)$/i', $q, $m)) {
        $q = trim($m[1] ?? $q);
    }
    if ($q === '') $q = $productKey;
    return 'https://www.walmart.com/search?q=' . rawurlencode($q);
}
function item_display_url(string $vendor, string $itemUrl, string $description, string $productKey = ''): string {
    $itemUrl = trim($itemUrl);
    if (is_valid_http_url($itemUrl)) return $itemUrl;
    if (strtolower($vendor) === 'walmart') return walmart_search_url($description, $productKey);
    return '';
}
function walmart_order_url(string $orderId): string {
    $orderId = trim($orderId);
    if ($orderId === '') return '';
    // Walmart changes order-detail routes periodically; this is still a useful logged-in landing link.
    return 'https://www.walmart.com/orders/' . rawurlencode($orderId);
}
function amazon_base_order_id(string $orderId): string {
    $orderId = trim($orderId);
    if (str_ends_with($orderId, '-CREDIT')) return substr($orderId, 0, -7);
    return $orderId;
}
function amazon_order_url(string $orderId, string $storedUrl = ''): string {
    $baseOrderId = amazon_base_order_id($orderId);
    if ($baseOrderId === '') return '';

    // Prefer a stored Amazon detail URL only when it is clearly order-specific.
    // Some 2026 exports store only a generic Your Orders URL; those open the default
    // orders page rather than the target order.  CREDIT memo targets also need to link
    // to the base Amazon order, not ORDERID-CREDIT.
    $storedUrl = trim($storedUrl);
    if (is_valid_http_url($storedUrl)) {
        $u = @parse_url($storedUrl);
        $query = [];
        if (is_array($u) && !empty($u['query'])) parse_str((string)$u['query'], $query);
        $path = is_array($u) ? (string)($u['path'] ?? '') : '';
        $storedOrder = (string)($query['orderID'] ?? $query['orderId'] ?? $query['order_id'] ?? '');
        if (($storedOrder !== '' && $storedOrder === $baseOrderId) || (str_contains($path, 'order-details') && str_contains($storedUrl, $baseOrderId))) {
            return $storedUrl;
        }
    }

    return 'https://www.amazon.com/gp/css/order-details?orderID=' . rawurlencode($baseOrderId);
}
function costco_order_url(string $orderId, string $storedUrl = ''): string {
    $storedUrl = trim($storedUrl);
    if (is_valid_http_url($storedUrl)) return $storedUrl;
    return '';
}
function vendor_order_url(string $vendor, string $orderId, string $storedUrl = ''): string {
    $vendor = strtolower(trim($vendor));
    if ($vendor === 'amazon') return amazon_order_url($orderId, $storedUrl);
    if ($vendor === 'walmart') return is_valid_http_url($storedUrl) ? trim($storedUrl) : walmart_order_url($orderId);
    if ($vendor === 'costco') return costco_order_url($orderId, $storedUrl);
    return is_valid_http_url($storedUrl) ? trim($storedUrl) : '';
}
function order_review_url(string $vendor, string $orderId, int $perPage = 25): string {
    return '?mode=bills&filter=all&search=' . rawurlencode($orderId) . '&per_page=' . max(1, $perPage) . '#review-top';
}
function order_links_html(string $vendor, string $orderId, string $storedUrl = '', int $perPage = 25): string {
    $label = h($orderId);
    $vendorUrl = vendor_order_url($vendor, $orderId, $storedUrl);
    $reviewUrl = order_review_url($vendor, $orderId, $perPage);
    $out = $vendorUrl !== ''
        ? '<a href="' . h($vendorUrl) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>'
        : $label;
    $out .= '<div class="small"><a href="' . h($reviewUrl) . '">tool review</a></div>';
    return $out;
}

function anchor_id(string $prefix, string $vendor, string $orderId, string $itemKey = ''): string {
    $raw = $prefix . '-' . $vendor . '-' . $orderId . ($itemKey !== '' ? '-' . $itemKey : '');
    return preg_replace('/[^A-Za-z0-9_-]+/', '-', $raw);
}
function invalid_account_link_html($where, string $gnucashPath, int $perPage): string {
    // Supports both old string-only invalid account records and new structured records.
    if (is_array($where)) {
        $label = (string)($where['label'] ?? (($where['vendor'] ?? '') . ' ' . ($where['order_id'] ?? '')));
        $vendor = (string)($where['vendor'] ?? '');
        $orderId = (string)($where['order_id'] ?? '');
        $itemKey = (string)($where['item_key'] ?? '');
        if ($orderId !== '') {
            $fragment = $itemKey !== '' ? anchor_id('item', $vendor, $orderId, $itemKey) : anchor_id('order', $vendor, $orderId);
            $q = http_build_query([
                'gnucash_path' => $gnucashPath,
                'search' => $orderId,
                'filter' => 'all',
                'per_page' => max(5, min(100, $perPage)),
                'page' => 1,
            ]);
            return '<a href="?' . h($q) . '#' . h($fragment) . '">' . h($label) . '</a>';
        }
        return h($label);
    }
    $text = (string)$where;
    if (preg_match('/\b(amazon|costco)\s+([A-Z0-9-]+)/i', $text, $m)) {
        $vendor = strtolower($m[1]);
        $orderId = $m[2];
        $fragment = anchor_id('order', $vendor, $orderId);
        $q = http_build_query([
            'gnucash_path' => $gnucashPath,
            'search' => $orderId,
            'filter' => 'all',
            'per_page' => max(5, min(100, $perPage)),
            'page' => 1,
        ]);
        return '<a href="?' . h($q) . '#' . h($fragment) . '">' . h($text) . '</a>';
    }
    return h($text);
}

function account_names_set(array $accounts): array {
    $valid = [];
    foreach ($accounts as $a) {
        $name = trim((string)($a['name'] ?? ''));
        if ($name !== '') $valid[$name] = true;
    }
    return $valid;
}
function load_valid_account_set(string $gnucashPath): array {
    if (trim($gnucashPath) === '') return [];
    return account_names_set(load_gnucash_accounts($gnucashPath));
}
function is_valid_suggestion_account(string $account, array $validAccounts): bool {
    $account = trim($account);
    if ($account === '') return false;
    if ($account === 'Expenses:Uncategorized') return true;
    // If no GnuCash account list is loaded, do not guess. The user can still choose manually.
    if (!$validAccounts) return false;
    return isset($validAccounts[$account]);
}
function valid_or_uncategorized(string $account, array $validAccounts): string {
    $account = trim($account);
    return is_valid_suggestion_account($account, $validAccounts) ? $account : 'Expenses:Uncategorized';
}
function default_account_for_items(string $items, array $validAccounts = []): string {
    $s = strtolower($items);
    $rules = [
        'Expenses:Computer:Hardware' => ['ssd','hard drive','sfp','ethernet','raspberry pi','zigbee','usb','server','screen protector','router','switch','poe'],
        'Expenses:Farm Supplies' => ['chicken','goat','fence','soil moisture','garden','seedling','nursery pots','hose','pool','tractor','feed'],
        'Expenses:Auto' => ['motor oil','rotella','mobil 1','torx','carport','trailer','tire','brake'],
        'Expenses:Pets' => ['cat','dog','pet','flea','friskies','urine destroyer','litter'],
        'Expenses:Groceries' => ['banana','kirkland','food','cheese','milk','flour','vital wheat gluten','bread'],
        'Expenses:Books' => ['book','guide','edition','paperback','hardcover'],
        'Expenses:Household' => ['sheet','lamp','floor mat','fireplace','dishwasher','cleaner','detergent'],
        'Expenses:Office Supplies' => ['paper','document','shipping boxes','letter tray','ink','toner','label'],
    ];
    foreach ($rules as $account => $needles) {
        if (!is_valid_suggestion_account($account, $validAccounts)) continue;
        foreach ($needles as $n) if (str_contains($s, $n)) return $account;
    }
    return 'Expenses:Uncategorized';
}
function item_key($order_id, $asin, $desc, $price, $qty, int $occurrence = 1): string {
    // Preserve the historical v106 key for the first occurrence so re-importing
    // an existing dataset updates current rows instead of duplicating them.
    // For duplicate Amazon item rows with the same order/ASIN/title/price/qty,
    // add the occurrence number to the hash so separate purchased lines are not
    // collapsed into one row.
    $base = (string)$order_id . "\n" . (string)$asin . "\n" . (string)$desc . "\n" . (string)$price . "\n" . (string)$qty;
    if ($occurrence > 1) $base .= "\nDUP#" . (string)$occurrence;
    return substr(hash('sha256', $base), 0, 24);
}

function vendor_config(string $vendor): array {
    $vendor = strtolower(trim($vendor));
    $map = [
        'amazon' => ['label'=>'Amazon', 'vendor_id'=>default_config_value('DEFAULT_VENDOR_AMAZON'), 'product_key_label'=>'ASIN'],
        'costco' => ['label'=>'Costco', 'vendor_id'=>default_config_value('DEFAULT_VENDOR_COSTCO'), 'product_key_label'=>'SKU'],
        'walmart' => ['label'=>'Walmart', 'vendor_id'=>default_config_value('DEFAULT_VENDOR_WALMART'), 'product_key_label'=>'Product/title key'],
        'lowes' => ['label'=>"Lowe's", 'vendor_id'=>default_config_value('DEFAULT_VENDOR_LOWES'), 'product_key_label'=>'SKU / item id'],
        'home_depot' => ['label'=>'Home Depot', 'vendor_id'=>default_config_value('DEFAULT_VENDOR_HOME_DEPOT'), 'product_key_label'=>'SKU / item id'],
        'tractor_supply' => ['label'=>'Tractor Supply', 'vendor_id'=>default_config_value('DEFAULT_VENDOR_TRACTOR_SUPPLY'), 'product_key_label'=>'SKU / item id'],
    ];
    return $map[$vendor] ?? ['label'=>ucfirst(str_replace('_', ' ', $vendor)), 'vendor_id'=>'', 'product_key_label'=>'Item key'];
}

function vendor_requires_posted_bill_export(string $vendor): bool {
    // All supported vendor bill imports should arrive in GnuCash as posted bills.
    // GnuCash's business UI does not show unposted bill documents in the usual
    // payable views, which made successful imports look missing and prevented
    // payment application workflows from proceeding.
    return in_array(strtolower(trim($vendor)), [
        'amazon',
        'costco',
        'walmart',
        'lowes',
        'home_depot',
        'tractor_supply',
    ], true);
}













function vendor_uses_sku_product_rules(string $vendor): bool {
    return in_array(strtolower(trim($vendor)), ['costco','lowes','home_depot','tractor_supply'], true);
}

function product_rule_label_for_vendor(string $vendor, string $keyType): string {
    $vendor = strtolower(trim($vendor));
    $keyType = strtolower(trim($keyType));
    if (vendor_uses_sku_product_rules($vendor)) {
        return vendor_config($vendor)['product_key_label'] ?? 'SKU / item id';
    }
    if ($vendor === 'walmart') return 'product title';
    return strtoupper($keyType);
}

function canonical_sku_rule_key_from_values(string $vendor, string $skuOrAsin = '', string $itemKey = '', string $description = ''): string {
    $vendor = strtolower(trim($vendor));
    $candidates = [];
    foreach ([$skuOrAsin, $itemKey] as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '' || $raw === 'AMAZON-RECONCILE') continue;
        $candidates[] = $raw;
    }
    if (!$candidates && trim($description) !== '') $candidates[] = trim($description);

    foreach ($candidates as $raw) {
        // TSC and several scraper importers use line ids like TSC-LINE-0001-1496731.
        // The product key is the terminal SKU/item id, not the line-instance id.
        if (preg_match('/(?:^|[-_\s])(\d{4,12})(?:\D*)$/', $raw, $m)) return (string)$m[1];
        if (preg_match('/(?:^|[-_\s])([A-Za-z0-9]{5,20})(?:\D*)$/', $raw, $m)) {
            $tail = strtoupper((string)$m[1]);
            if (!in_array($tail, ['LINE','SKU','ITEM','DISCOUNT','COUNT','CREDIT'], true)) return (string)$m[1];
        }
    }
    return trim((string)($candidates[0] ?? ''));
}

function product_rule_key_from_item_key(string $itemKey): string {
    return canonical_sku_rule_key_from_values('', '', $itemKey, '');
}

function product_rule_where_sql(string $vendor, string $pendingClause = ''): string {
    $vendor = strtolower(trim($vendor));
    $base = "vendor=:vendor AND skip=0 AND COALESCE(locked,0)=0
              AND item_key<>'AMAZON-RECONCILE' AND COALESCE(asin,'')<>'AMAZON-RECONCILE'
              AND description NOT LIKE '[DISCOUNT:%' AND description NOT LIKE '[ADJUSTMENT:%'";
    if ($vendor === 'costco') {
        return $base . " AND (asin=:key OR item_key=:key OR notes LIKE :target_note OR description LIKE :target_desc)" . $pendingClause;
    }
    if (vendor_uses_sku_product_rules($vendor)) {
        return $base . " AND (
                    asin=:key
                 OR item_key=:key
                 OR asin LIKE :sku_suffix
                 OR item_key LIKE :sku_suffix
                 OR asin LIKE :sku_middle
                 OR item_key LIKE :sku_middle
                 OR notes LIKE :sku_text
                 OR description LIKE :sku_text
              )" . $pendingClause;
    }
    return $base . " AND asin=:key" . $pendingClause;
}

function bind_product_rule_params(SQLite3Stmt $stmt, string $vendor, string $key): void {
    $vendor = strtolower(trim($vendor));
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    if ($vendor === 'costco') {
        $stmt->bindValue(':target_note', '%Target SKU: ' . $key . '%', SQLITE3_TEXT);
        $stmt->bindValue(':target_desc', '%for SKU ' . $key . '%', SQLITE3_TEXT);
    }
    if (vendor_uses_sku_product_rules($vendor) && $vendor !== 'costco') {
        $stmt->bindValue(':sku_suffix', '%-' . $key, SQLITE3_TEXT);
        $stmt->bindValue(':sku_middle', '%-' . $key . '-%', SQLITE3_TEXT);
        $stmt->bindValue(':sku_text', '%' . $key . '%', SQLITE3_TEXT);
    }
}

function same_product_candidate_count(SQLite3 $db, string $vendor, string $key, bool $pendingOnly): int {
    $vendor = strtolower(trim($vendor));
    $key = trim($key);
    if ($vendor === '' || $key === '') return 0;
    $pendingClause = $pendingOnly ? ' AND (expense_account IS NULL OR trim(expense_account)="" OR expense_account LIKE "%Uncategorized%")' : '';
    $sql = 'SELECT COUNT(*) AS c FROM order_items WHERE ' . product_rule_where_sql($vendor, $pendingClause);
    $stmt = $db->prepare($sql);
    bind_product_rule_params($stmt, $vendor, $key);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int)($row['c'] ?? 0);
}

function same_product_sample_rows(SQLite3 $db, string $vendor, string $key, int $limit = 12): array {
    $vendor = strtolower(trim($vendor));
    $key = trim($key);
    if ($vendor === '' || $key === '') return [];
    $limit = max(1, min(50, $limit));
    $sql = 'SELECT order_id, item_key, asin, description, expense_account, locked, skip
            FROM order_items
            WHERE ' . product_rule_where_sql($vendor, '') . '
            ORDER BY order_date DESC, order_id, item_key
            LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    bind_product_rule_params($stmt, $vendor, $key);
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = [
            'order_id' => (string)($r['order_id'] ?? ''),
            'item_key' => (string)($r['item_key'] ?? ''),
            'asin' => (string)($r['asin'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'expense_account' => (string)($r['expense_account'] ?? ''),
            'locked' => (int)($r['locked'] ?? 0),
            'skip' => (int)($r['skip'] ?? 0),
        ];
    }
    return $rows;
}

function set_action_debug(array $debug): void {
    $GLOBALS['last_action_debug'] = $debug;
}

function normalize_key_text(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\[[^\]]+\]/', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
function receipt_id_for_costco(array $r): string {
    $barcode = trim((string)($r['transactionBarcode'] ?? ''));
    if ($barcode !== '') return $barcode;
    $date = trim((string)($r['transactionDate'] ?? substr((string)($r['transactionDateTime'] ?? ''),0,10)));
    return trim((string)($r['warehouseNumber'] ?? 'WH')) . '-' . $date . '-' . trim((string)($r['registerNumber'] ?? 'R')) . '-' . trim((string)($r['transactionNumber'] ?? 'T'));
}

function canonical_order_id_for_export(string $vendor, string $orderId): string {
    $vendor = strtolower(trim($vendor));
    $orderId = trim($orderId);
    if ($vendor === 'costco') {
        // Older staged rows and older exports used COSTCO-<receipt barcode>.
        // GnuCash duplicate detection works best when the bill id is the raw
        // receipt/transaction barcode, so strip the display/vendor prefix at
        // export and duplicate-scan time.
        $orderId = preg_replace('/^COSTCO[-_:\s]+/i', '', $orderId);
    }
    return $orderId;
}

function amazon_order_credit_base_pair(string $a, string $b): bool {
    // Amazon refund credit memos must remain separate ORDERID-CREDIT invoices.
    // A normal base order invoice with ID ORDERID is valid and can coexist with the
    // credit memo. The scanner must never fuzzy-match these two documents in either
    // direction. Prior versions only blocked CREDIT->base matching; base->CREDIT
    // fuzzy matching could cause the repair wizard to attach a normal purchase target
    // to the credit memo GUID and then try to repair the credit memo as though it were
    // the purchase bill.
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') return false;
    if (str_ends_with($a, '-CREDIT') && substr($a, 0, -7) === $b) return true;
    if (str_ends_with($b, '-CREDIT') && substr($b, 0, -7) === $a) return true;
    return false;
}

function is_amazon_credit_target_base_id(string $wanted, string $actual): bool {
    // Backward-compatible wrapper for older call sites.
    return amazon_order_credit_base_pair($wanted, $actual);
}

function connect_app_db(): SQLite3 {
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0770, true);
    if (!is_dir(__DIR__ . '/exports')) mkdir(__DIR__ . '/exports', 0770, true);
    @chmod(__DIR__ . '/data', 02770);
    @chmod(__DIR__ . '/exports', 02770);
    // Use rollback journal instead of WAL for this local PHP-FPM app. WAL files can
    // wind up owned differently from review.sqlite and cause readonly/locked errors.
    $db = new SQLite3(APP_DB, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    if (method_exists($db, 'enableExceptions')) $db->enableExceptions(true);
    $db->busyTimeout(30000);
    @chmod(APP_DB, 0660);
    $db->exec('PRAGMA journal_mode=DELETE');
    $db->exec('PRAGMA busy_timeout=30000');
    $db->exec('CREATE TABLE IF NOT EXISTS orders (
        vendor TEXT NOT NULL,
        order_id TEXT NOT NULL,
        order_url TEXT,
        order_date TEXT,
        recipient TEXT,
        items TEXT,
        total REAL DEFAULT 0,
        shipping REAL DEFAULT 0,
        shipping_refund REAL DEFAULT 0,
        gift REAL DEFAULT 0,
        tax REAL DEFAULT 0,
        refund REAL DEFAULT 0,
        payments TEXT,
        expense_account TEXT,
        item_amount REAL,
        tax_account TEXT DEFAULT "Expenses:Tax:Sales Tax",
        shipping_account TEXT DEFAULT "Expenses:Shipping",
        skip INTEGER DEFAULT 0,
        locked INTEGER DEFAULT 0,
        warning TEXT,
        notes TEXT,
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, order_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS order_items (
        vendor TEXT NOT NULL,
        order_id TEXT NOT NULL,
        item_key TEXT NOT NULL,
        order_url TEXT,
        order_date TEXT,
        quantity REAL DEFAULT 1,
        description TEXT,
        item_url TEXT,
        asin TEXT,
        unit_price REAL DEFAULT 0,
        source_amount REAL DEFAULT NULL,
        subscribe_save REAL DEFAULT 0,
        expense_account TEXT,
        skip INTEGER DEFAULT 0,
        locked INTEGER DEFAULT 0,
        notes TEXT,
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, order_id, item_key)
    )');
    // Migrations for users upgrading an existing review.sqlite.
    $existingCols = [];
    $colRes = $db->query('PRAGMA table_info(order_items)');
    while ($c = $colRes->fetchArray(SQLITE3_ASSOC)) $existingCols[$c['name']] = true;
    if (!isset($existingCols['match_reason'])) $db->exec('ALTER TABLE order_items ADD COLUMN match_reason TEXT');
    if (!isset($existingCols['locked'])) $db->exec('ALTER TABLE order_items ADD COLUMN locked INTEGER DEFAULT 0');
    if (!isset($existingCols['source_amount'])) $db->exec('ALTER TABLE order_items ADD COLUMN source_amount REAL DEFAULT NULL');
    if (!isset($existingCols['account_change_source'])) $db->exec("ALTER TABLE order_items ADD COLUMN account_change_source TEXT DEFAULT ''");
    if (!isset($existingCols['account_last_changed_at'])) $db->exec("ALTER TABLE order_items ADD COLUMN account_last_changed_at TEXT DEFAULT ''");
    $existingOrderCols = [];
    $colRes = $db->query('PRAGMA table_info(orders)');
    while ($c = $colRes->fetchArray(SQLITE3_ASSOC)) $existingOrderCols[$c['name']] = true;
    if (!isset($existingOrderCols['match_reason'])) $db->exec('ALTER TABLE orders ADD COLUMN match_reason TEXT');
    if (!isset($existingOrderCols['locked'])) $db->exec('ALTER TABLE orders ADD COLUMN locked INTEGER DEFAULT 0');
    $db->exec('CREATE TABLE IF NOT EXISTS account_rules (
        vendor TEXT NOT NULL,
        match_type TEXT NOT NULL,
        match_key TEXT NOT NULL,
        account_fullname TEXT NOT NULL,
        source TEXT NOT NULL,
        confidence INTEGER NOT NULL DEFAULT 100,
        usage_count INTEGER NOT NULL DEFAULT 1,
        last_used_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, match_type, match_key)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS app_config (key TEXT PRIMARY KEY, value TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS extra_accounts (
        name TEXT PRIMARY KEY,
        account_type TEXT NOT NULL DEFAULT "EXPENSE",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS payment_method_accounts (
        vendor TEXT NOT NULL,
        method_key TEXT NOT NULL,
        display_name TEXT NOT NULL,
        account_fullname TEXT NOT NULL,
        excluded INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, method_key)
    )');
    try { $db->exec('ALTER TABLE payment_method_accounts ADD COLUMN excluded INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE payment_method_accounts ADD COLUMN invoice_handling TEXT NOT NULL DEFAULT 'normal'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE payment_method_accounts ADD COLUMN external_account TEXT NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    $db->exec('CREATE TABLE IF NOT EXISTS vendor_payments (
        vendor TEXT NOT NULL,
        payment_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        order_url TEXT,
        payment_date TEXT,
        payee TEXT,
        payment_method TEXT,
        method_key TEXT,
        amount REAL DEFAULT 0,
        account_fullname TEXT,
        match_status TEXT,
        notes TEXT,
        imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, payment_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS payment_manual_verifications (
        vendor TEXT NOT NULL,
        payment_id TEXT NOT NULL,
        verified_at TEXT DEFAULT CURRENT_TIMESTAMP,
        note TEXT DEFAULT "",
        PRIMARY KEY (vendor, payment_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS payment_target_exclusions (
        vendor TEXT NOT NULL,
        target_id TEXT NOT NULL,
        reason TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, target_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS payment_allocation_overrides (
        vendor TEXT NOT NULL,
        order_id TEXT NOT NULL,
        payment_date TEXT NOT NULL,
        payment_method TEXT NOT NULL,
        amount REAL NOT NULL,
        note TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, order_id, payment_date, payment_method)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS register_transaction_exclusions (
        vendor TEXT NOT NULL,
        tx_guid TEXT NOT NULL,
        reason TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor, tx_guid)
    )');
    // Normalize older staged Costco bill IDs where possible. If both prefixed
    // and raw rows exist, keep the raw row and delete the stale prefixed row.
    $db->exec("DELETE FROM orders WHERE vendor='costco' AND order_id LIKE 'COSTCO-%' AND EXISTS (SELECT 1 FROM orders o2 WHERE o2.vendor='costco' AND o2.order_id=substr(orders.order_id,8))");
    $db->exec("DELETE FROM order_items WHERE vendor='costco' AND order_id LIKE 'COSTCO-%' AND EXISTS (SELECT 1 FROM orders o2 WHERE o2.vendor='costco' AND o2.order_id=substr(order_items.order_id,8))");
    $db->exec("UPDATE orders SET order_id=substr(order_id,8) WHERE vendor='costco' AND order_id LIKE 'COSTCO-%'");
    $db->exec("UPDATE order_items SET order_id=substr(order_id,8) WHERE vendor='costco' AND order_id LIKE 'COSTCO-%'");
    return $db;
}
function get_config(SQLite3 $db, string $key, string $default = ''): string {
    $stmt = $db->prepare('SELECT value FROM app_config WHERE key=:key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $v = $stmt->execute()->fetchArray(SQLITE3_NUM);
    return $v ? (string)$v[0] : $default;
}
function set_config(SQLite3 $db, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO app_config (key,value) VALUES (:key,:value) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function read_app_config_values(array $keys): array {
    $out = [];
    if (!file_exists(APP_DB) || !is_readable(APP_DB)) return $out;
    try {
        $db = new SQLite3(APP_DB, SQLITE3_OPEN_READONLY);
        foreach ($keys as $k) {
            $stmt = $db->prepare('SELECT value FROM app_config WHERE key=:key');
            $stmt->bindValue(':key', $k, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
            if ($row) $out[$k] = (string)$row[0];
        }
    } catch (Throwable $e) {}
    return $out;
}
function gnucash_backend_config(?SQLite3 $db = null): array {
    $defaults = [
        'gnucash_backend' => 'sqlite',
        'gnucash_path' => '',
        'gnucash_db_host' => 'localhost',
        'gnucash_db_port' => '',
        'gnucash_db_name' => '',
        'gnucash_db_user' => '',
        'gnucash_db_pass' => '',
    ];
    $keys = array_keys($defaults);
    $vals = [];
    if ($db instanceof SQLite3) {
        foreach ($keys as $k) $vals[$k] = get_config($db, $k, $defaults[$k]);
    } else {
        $vals = array_merge($defaults, read_app_config_values($keys));
    }
    $backend = strtolower(trim((string)($vals['gnucash_backend'] ?? 'sqlite')));
    if (!in_array($backend, ['sqlite','pgsql','mysql'], true)) $backend = 'sqlite';
    $vals['gnucash_backend'] = $backend;
    return $vals;
}
function pdo_for_gnucash(array $cfg): ?PDO {
    $backend = $cfg['gnucash_backend'] ?? 'sqlite';
    if ($backend === 'sqlite') return null;
    $host = trim((string)($cfg['gnucash_db_host'] ?? 'localhost')) ?: 'localhost';
    $port = trim((string)($cfg['gnucash_db_port'] ?? ''));
    $name = trim((string)($cfg['gnucash_db_name'] ?? ''));
    $user = trim((string)($cfg['gnucash_db_user'] ?? ''));
    $pass = (string)($cfg['gnucash_db_pass'] ?? '');
    if ($name === '') throw new RuntimeException('Database name is blank.');
    if ($backend === 'pgsql') {
        if (!extension_loaded('pdo_pgsql')) throw new RuntimeException('PHP extension pdo_pgsql is not loaded. Install the matching php8.5-pgsql package.');
        $dsn = 'pgsql:host='.$host.';dbname='.$name.($port !== '' ? ';port='.$port : '');
    } elseif ($backend === 'mysql') {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PHP extension pdo_mysql is not loaded. Install the matching php8.5-mysql package.');
        $dsn = 'mysql:host='.$host.';dbname='.$name.($port !== '' ? ';port='.$port : '').';charset=utf8mb4';
    } else return null;
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $pdo;
}
function table_columns_pdo(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->query('SELECT * FROM ' . $table . ' WHERE 1=0');
        $cols = [];
        for ($i=0; $i<$stmt->columnCount(); $i++) { $meta=$stmt->getColumnMeta($i); if(isset($meta['name'])) $cols[]=$meta['name']; }
        return $cols;
    } catch (Throwable $e) { return []; }
}
function account_fullnames_by_rows(array $rows): array {
    $byGuid=[]; foreach($rows as $r) $byGuid[(string)$r['guid']]=$r;
    $memo=[]; $full=function($guid) use (&$full,&$byGuid,&$memo): string {
        if(isset($memo[$guid])) return $memo[$guid]; if(!isset($byGuid[$guid])) return '';
        $name=(string)$byGuid[$guid]['name']; $parent=(string)($byGuid[$guid]['parent_guid'] ?? '');
        if($parent==='' || !isset($byGuid[$parent]) || strtolower((string)$byGuid[$parent]['name'])==='root account') return $memo[$guid]=$name;
        $p=$full($parent); return $memo[$guid]=($p?$p.':':'').$name;
    };
    $out=[]; foreach($byGuid as $guid=>$r) $out[$guid]=['name'=>$full($guid), 'type'=>(string)($r['account_type'] ?? '')];
    return $out;
}
function normalize_header_name(mixed $v): string {
    // Header normalization is used during auto-detect. Uploaded ZIP/binary content
    // can make Unicode regex normalization return null; never let a malformed/binary
    // header candidate fatal the import page.
    if ($v === null) return '';
    $v = (string)$v;
    $withoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $v);
    if ($withoutBom !== null) $v = $withoutBom;
    $withoutUtfBom = preg_replace('/^\x{FEFF}/u', '', $v);
    if ($withoutUtfBom !== null) $v = $withoutUtfBom;
    $v = strtolower(trim($v));
    $collapsed = preg_replace('/\s+/', ' ', $v);
    return $collapsed !== null ? $collapsed : $v;
}

function file_looks_like_zip(string $path, string $prefix = ''): bool {
    if ($prefix === '') {
        $prefix = (string)@file_get_contents($path, false, null, 0, 4);
    }
    // Local file header, empty archive EOCD, or split/spanned descriptor signatures.
    return str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "PK\x05\x06") || str_starts_with($prefix, "PK\x07\x08");
}
function first_csv_value(array $row, array $keys): string {
    foreach ($keys as $k) {
        $k = normalize_header_name((string)$k);
        if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return trim((string)$row[$k]);
    }
    return '';
}
function first_csv_date(array $row, array $keys): string {
    $raw = first_csv_value($row, $keys);
    return $raw === '' ? '' : normalize_import_date($raw);
}
function amazon_csv_order_date(array $row): string {
    return first_csv_date($row, ['date','order date','ordered date','ordered on','order placed date','purchase date','order date/time','order placed']);
}
function detect_format(array $header): string {
    $h = array_flip($header);
    if (isset($h['quantity'],$h['description'],$h['item url'],$h['price'],$h['asin'])) return 'amazon_items';
    if (isset($h['items'],$h['total'],$h['shipping'],$h['tax'],$h['payments'])) return 'amazon_orders';
    if (isset($h['date'],$h['order ids'],$h['card_details'],$h['amount'])) return 'amazon_transactions';
    if (isset($h['order number'],$h['order date'],$h['product name'],$h['price'],$h['order total'])) return 'walmart_orders';
    if (isset($h['date'],$h['store number'],$h['transaction id'],$h['sku number'],$h['sku description'],$h['extended retail (before discount)'])) return 'home_depot_purchase_history';
    return 'unknown';
}

function normalize_payment_method_key(string $method): string {
    $m = strtolower(trim($method));
    $m = preg_replace('/\s+/', ' ', $m);
    $m = str_replace(['ending in ', 'ending ', '****'], ['', '', ''], $m);
    $m = preg_replace('/[^a-z0-9]+/', '-', $m);
    $m = trim($m, '-');
    return $m === '' ? 'unknown' : $m;
}
function default_payment_account_for_method(string $vendor, string $method): string {
    $hay = strtolower($method);
    if ($vendor === 'amazon') {
        if (amazon_text_mentions_prime_young_adults_cashback($hay)) return default_config_value('DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON');
        if (str_contains($hay, 'visa') && (str_contains($hay, '2246') || str_contains($hay, 'prime'))) return default_config_value('DEFAULT_PAYMENT_ACCOUNT_AMAZON');
        if (str_contains($hay, 'point') || str_contains($hay, 'reward') || str_contains($hay, 'gift') || str_contains($hay, 'cash back')) return default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON');
    }
    if ($vendor === 'walmart') {
        if (str_contains($hay, 'cash') || str_contains($hay, 'gift') || str_contains($hay, 'reward')) return default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_WALMART');
    }
    if ($vendor === 'costco') {
        if (str_contains($hay, 'shop') || str_contains($hay, 'cash') || str_contains($hay, 'reward') || str_contains($hay, 'rebate')) return default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_COSTCO');
    }
    if ($vendor === 'lowes') {
        if (str_contains($hay, 'mylowes') || str_contains($hay, "lowe's money") || str_contains($hay, 'lowes money') || str_contains($hay, 'gift') || preg_match('/\bgc\b/', $hay)) return default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES');
    }
    if ($vendor === 'home_depot') {
        $acct = default_config_value('DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT');
        if ($acct !== '') return $acct;
    }
    return '';
}

function amazon_staged_item_sum(SQLite3 $db, string $orderId): float {
    $stmt = $db->prepare("SELECT SUM(CASE WHEN skip=0 AND item_key<>'AMAZON-RECONCILE' AND item_key NOT LIKE 'AMAZON-INFERRED-%' AND item_key<>'AMAZON-FEE' THEN COALESCE(source_amount, quantity*unit_price) ELSE 0 END) AS s FROM order_items WHERE vendor='amazon' AND order_id=:oid");
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return round((float)($r['s'] ?? 0.0), 2);
}
function amazon_staged_order_payment_basis(SQLite3 $db, string $orderId, bool $assumeFreeShippingForZeroStored = false): float {
    // Estimated gross bill amount for allocating one Amazon transaction row that
    // names multiple order IDs.  For all-rewards/zero-grand-total orders, Amazon's
    // transaction history often excludes a "Free Shipping" offset that the order
    // page shows separately.  When requested, use item subtotal + tax as the
    // allocation basis so a combined rewards row can be split across its orders.
    $stmt = $db->prepare("SELECT * FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$o) return 0.0;
    $itemSum = amazon_staged_item_sum($db, $orderId);
    $tax = round((float)($o['tax'] ?? 0.0), 2);
    $ship = round((float)($o['shipping'] ?? 0.0), 2);
    $shipRefund = round((float)($o['shipping_refund'] ?? 0.0), 2);
    $total = round((float)($o['total'] ?? 0.0), 2);
    $payments = strtolower((string)($o['payments'] ?? ''));
    $hasStoredText = str_contains($payments, 'gift card') || str_contains($payments, 'rewards point') || str_contains($payments, 'reward points') || str_contains($payments, 'cash back') || str_contains($payments, 'points');
    if ($assumeFreeShippingForZeroStored && $hasStoredText && abs($total) < 0.005 && $ship > 0.005 && $shipRefund < 0.005) {
        return round($itemSum + $tax, 2);
    }
    if ($itemSum > 0.005) return round($itemSum + max(0.0, $ship - $shipRefund) + $tax, 2);
    return round(order_bill_total_for_validation($o), 2);
}

function delete_legacy_multi_order_payment_rows(SQLite3 $db, string $vendor, string $date, array $ids, string $method, float $amount): int {
    // v112: When re-importing Amazon transaction rows that name multiple order IDs,
    // remove stale rows created by older versions that assigned the full grouped
    // payment amount to each individual order.  The new import then inserts one
    // allocated row per order.  Keep this narrowly scoped so ordinary single-order
    // payments and already-allocated rows are not touched.
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string)$id);
        if ($id !== '') $clean[$id] = true;
    }
    $clean = array_keys($clean);
    if (count($clean) <= 1 || trim($date) === '' || trim($method) === '') return 0;

    $placeholders = [];
    foreach ($clean as $i => $_) $placeholders[] = ':oid'.$i;

    // Delete rows for each individual order that have the original grouped amount.
    // Older payment_id generation did not include the ALLOCATED-FROM marker and had
    // the grouped amount for every member order.  Matching by amount avoids deleting
    // the new per-order allocations such as 29.01 and 9.64 for a 38.65 group row.
    $sql = 'DELETE FROM vendor_payments
            WHERE vendor=:vendor
              AND payment_date=:pdate
              AND payment_method=:method
              AND order_id IN ('.implode(',', $placeholders).')
              AND ABS(ABS(amount) - ABS(:amount)) <= 0.005';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':vendor', strtolower(trim($vendor)), SQLITE3_TEXT);
    $stmt->bindValue(':pdate', $date, SQLITE3_TEXT);
    $stmt->bindValue(':method', $method, SQLITE3_TEXT);
    $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
    foreach ($clean as $i => $oid) $stmt->bindValue(':oid'.$i, $oid, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();

    // Also remove a previous synthetic combined-key row if one exists for the same
    // transaction.  This is harmless if absent and prevents stale group-only rows
    // from continuing to appear in payment-only review after allocation succeeds.
    $combined = implode(', ', $clean);
    $stmt2 = $db->prepare('DELETE FROM vendor_payments
            WHERE vendor=:vendor
              AND payment_date=:pdate
              AND payment_method=:method
              AND order_id=:combined
              AND ABS(ABS(amount) - ABS(:amount)) <= 0.005');
    $stmt2->bindValue(':vendor', strtolower(trim($vendor)), SQLITE3_TEXT);
    $stmt2->bindValue(':pdate', $date, SQLITE3_TEXT);
    $stmt2->bindValue(':method', $method, SQLITE3_TEXT);
    $stmt2->bindValue(':combined', $combined, SQLITE3_TEXT);
    $stmt2->bindValue(':amount', $amount, SQLITE3_FLOAT);
    $stmt2->execute();
    $changed += $db->changes();

    // v144: remove stale proportional allocations created by earlier versions for the
    // same grouped Amazon transaction.  Those rows do not equal the original grouped
    // amount, so the legacy delete above missed them (for example 14.69/2.42 rows
    // from a 17.11 grouped Visa-points transaction).  They carry the original grouped
    // order list in the notes; delete them before inserting the corrected per-order
    // allocation so the payment plan cannot keep using stale split amounts.
    $stmt3 = $db->prepare('DELETE FROM vendor_payments
            WHERE vendor=:vendor
              AND payment_date=:pdate
              AND payment_method=:method
              AND order_id IN ('.implode(',', $placeholders).')
              AND notes LIKE :note');
    $stmt3->bindValue(':vendor', strtolower(trim($vendor)), SQLITE3_TEXT);
    $stmt3->bindValue(':pdate', $date, SQLITE3_TEXT);
    $stmt3->bindValue(':method', $method, SQLITE3_TEXT);
    $stmt3->bindValue(':note', '%Original Amazon transaction row named multiple orders ['.$combined.']%', SQLITE3_TEXT);
    foreach ($clean as $i => $oid) $stmt3->bindValue(':oid'.$i, $oid, SQLITE3_TEXT);
    $stmt3->execute();
    $changed += $db->changes();

    // v145: if a user re-imports the same grouped Amazon stored-value row after an
    // earlier version created proportional split rows, remove every same-date,
    // same-method row for the affected order IDs before inserting the corrected
    // allocation.  The older rows may lack the original-group note, so amount/note
    // scoped deletes can miss them (e.g. 14.69/2.42 rows for a 17.11 Visa-points
    // batch).  This cleanup is only called while processing a transaction-history
    // row that explicitly names multiple order IDs.
    $stmt4 = $db->prepare('DELETE FROM vendor_payments
            WHERE vendor=:vendor
              AND payment_date=:pdate
              AND payment_method=:method
              AND order_id IN ('.implode(',', $placeholders).')');
    $stmt4->bindValue(':vendor', strtolower(trim($vendor)), SQLITE3_TEXT);
    $stmt4->bindValue(':pdate', $date, SQLITE3_TEXT);
    $stmt4->bindValue(':method', $method, SQLITE3_TEXT);
    foreach ($clean as $i => $oid) $stmt4->bindValue(':oid'.$i, $oid, SQLITE3_TEXT);
    $stmt4->execute();
    return $changed + $db->changes();
}

function amazon_staged_order_summary_stored_value_amount(SQLite3 $db, string $orderId, string $method = ''): float {
    // Prefer the per-order stored-value amount implied by that order's own Amazon
    // order-summary math, rather than orders.gift when orders.gift may have been
    // inferred from an already-misallocated grouped transaction.
    // This fixes grouped rewards rows such as:
    //   one Amazon Visa points row for ORDER-A, ORDER-B = 17.11
    //   ORDER-A summary Rewards Points = 6.37
    //   ORDER-B summary Rewards Points = 10.74
    // Prime for Young Adults cash back is intentionally excluded here because Amazon
    // can display it on the order summary while still charging the full card amount.
    if (amazon_method_is_prime_young_cashback($method)) return 0.0;
    if ($method !== '' && !amazon_method_is_points_or_rewards($method)) return 0.0;
    $stmt = $db->prepare("SELECT order_id, total, tax, shipping, shipping_refund, gift, payments, warning, notes, items FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$o) return 0.0;
    $hay = strtolower((string)($o['payments'] ?? ''));
    if (!(str_contains($hay, 'gift card') || str_contains($hay, 'rewards point') || str_contains($hay, 'reward points') || str_contains($hay, 'amazon points') || str_contains($hay, 'visa points') || str_contains($hay, 'cash back') || str_contains($hay, 'points'))) return 0.0;
    if (amazon_text_mentions_prime_young_adults_cashback($hay) && !(str_contains($hay, 'rewards point') || str_contains($hay, 'reward points') || str_contains($hay, 'amazon points') || str_contains($hay, 'visa points'))) return 0.0;
    $explicit = amazon_order_text_stored_value_amount_for_method($o, $method);
    if ($explicit > 0.005) return round($explicit, 2);

    $q = $db->prepare("SELECT SUM(CASE WHEN COALESCE(skip,0)=0 AND item_key<>'AMAZON-RECONCILE' THEN COALESCE(source_amount, quantity*unit_price) ELSE 0 END) AS item_sum
                       FROM order_items WHERE vendor='amazon' AND order_id=:oid");
    $q->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $rr = $q->execute()->fetchArray(SQLITE3_ASSOC);
    $itemSum = round((float)($rr['item_sum'] ?? 0.0), 2);
    if ($itemSum <= 0.005) return 0.0;
    $tax = round((float)($o['tax'] ?? 0.0), 2);
    $netShipping = round((float)($o['shipping'] ?? 0.0) - (float)($o['shipping_refund'] ?? 0.0), 2);
    $reportedTotal = round((float)($o['total'] ?? 0.0), 2);
    $grossBill = round($itemSum + $tax + $netShipping, 2);
    $candidate = round($grossBill - $reportedTotal, 2);
    if ($candidate <= 0.005) return 0.0;
    // Do not return a nonsensical value.  A small tolerance allows Amazon's rounded
    // tax/order displays to differ by a cent or two.
    if ($candidate > $grossBill + 0.02) return 0.0;
    return abs($candidate);
}

function amazon_allocate_multi_order_payment_amounts(SQLite3 $db, array $ids, float $amount, string $method = ''): array {
    // Return per-order signed amounts for a single Amazon transaction row that
    // references multiple order IDs.  Avoid the old behavior of assigning the
    // full combined amount to each order, which created false payment exceptions.
    //
    // v143: For multi-order stored-value rows (Amazon Points, Amazon Visa points,
    // rewards/cash-back), allocate by each order's staged stored-value amount when
    // those stored-value amounts add up to the grouped transaction.  This matches
    // Amazon's related-transactions page: one rewards/points transaction may list
    // two order IDs, but each order page shows its own points component.  Using gross
    // bill/payment basis here over-allocates the points to the larger order and can
    // make Step 3 appear clean while the later API dry-run cannot find a matching
    // imported stored-value register split.
    $out = [];
    $clean = [];
    foreach ($ids as $id) { $id = trim((string)$id); if ($id !== '') $clean[] = $id; }
    $clean = array_values(array_unique($clean));
    if (count($clean) <= 1) return $clean ? [$clean[0] => $amount] : [];
    $absAmount = abs($amount);

    if (is_stored_value_payment_method($method)) {
        $storedBases = []; $storedSum = 0.0;
        foreach ($clean as $id) {
            $basis = round(amazon_staged_order_summary_stored_value_amount($db, $id, $method), 2);
            if ($basis <= 0.005) {
                $stmt = $db->prepare("SELECT gift, payments, total, tax, shipping, shipping_refund FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
                $stmt->bindValue(':oid', $id, SQLITE3_TEXT);
                $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$o) { $storedBases = []; break; }
                $basis = round(stored_value_payment_amount($o), 2);
            }
            if ($basis <= 0.005) { $storedBases = []; break; }
            $storedBases[$id] = $basis;
            $storedSum = round($storedSum + $basis, 2);
        }
        if ($storedBases && abs($storedSum - $absAmount) <= 0.02) {
            foreach ($storedBases as $id=>$basis) $out[$id] = $amount < 0 ? -$basis : $basis;
            return $out;
        }
    }

    foreach ([true, false] as $freeShipAssumption) {
        $bases = []; $sum = 0.0;
        foreach ($clean as $id) {
            $basis = amazon_staged_order_payment_basis($db, $id, $freeShipAssumption);
            if ($basis <= 0.005) { $bases = []; break; }
            $bases[$id] = $basis; $sum = round($sum + $basis, 2);
        }
        if ($bases && abs($sum - $absAmount) <= 0.02) {
            foreach ($bases as $id=>$basis) $out[$id] = $amount < 0 ? -$basis : $basis;
            return $out;
        }
    }
    // Last-resort proportional split if all orders have a positive basis.  This
    // remains reviewable because the note records the original combined amount.
    $bases = []; $sum = 0.0;
    foreach ($clean as $id) {
        $basis = amazon_staged_order_payment_basis($db, $id, false);
        if ($basis <= 0.005) { $bases = []; break; }
        $bases[$id] = $basis; $sum += $basis;
    }
    if ($bases && $sum > 0.005) {
        $remaining = $absAmount; $last = array_key_last($bases);
        foreach ($bases as $id=>$basis) {
            $part = ($id === $last) ? $remaining : round($absAmount * ($basis / $sum), 2);
            $remaining = round($remaining - $part, 2);
            $out[$id] = $amount < 0 ? -$part : $part;
        }
        return $out;
    }
    // If we cannot allocate safely, keep a single synthetic combined key rather
    // than duplicating the full amount to each order.  It will show as payment-only
    // review instead of corrupting per-order totals.
    $out[implode(', ', $clean)] = $amount;
    return $out;
}
function load_payment_account_map(SQLite3 $db, string $vendor = ''): array {
    $vendor = strtolower(trim($vendor)); $out = [];
    $sql = 'SELECT vendor, method_key, display_name, account_fullname, excluded, invoice_handling, external_account FROM payment_method_accounts' . ($vendor !== '' ? ' WHERE vendor=:vendor' : '') . ' ORDER BY vendor, display_name';
    $stmt = $db->prepare($sql); if ($vendor !== '') $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[$r['vendor'].'|'.$r['method_key']] = $r;
    return $out;
}
function upsert_payment_method_account(SQLite3 $db, string $vendor, string $method, string $account = ''): void {
    $vendor = strtolower(trim($vendor)); $method = trim($method); if ($vendor === '' || $method === '') return;
    $key = normalize_payment_method_key($method); $account = trim($account) ?: default_payment_account_for_method($vendor, $method);
    $stmt = $db->prepare('INSERT INTO payment_method_accounts (vendor, method_key, display_name, account_fullname, updated_at) VALUES (:vendor,:key,:display,:acct,CURRENT_TIMESTAMP)
        ON CONFLICT(vendor, method_key) DO UPDATE SET display_name=excluded.display_name, account_fullname=CASE WHEN excluded.account_fullname<>"" THEN excluded.account_fullname ELSE payment_method_accounts.account_fullname END, updated_at=CURRENT_TIMESTAMP');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $stmt->bindValue(':key', $key, SQLITE3_TEXT); $stmt->bindValue(':display', $method, SQLITE3_TEXT); $stmt->bindValue(':acct', $account, SQLITE3_TEXT); $stmt->execute();
}
function save_payment_method_mappings(SQLite3 $db, array $post): int {
    $n = 0;
    $vendors = $post['pm_vendor'] ?? []; $keys = $post['pm_key'] ?? []; $names = $post['pm_name'] ?? []; $accounts = $post['pm_account'] ?? [];
    $excludedRaw = $post['pm_excluded'] ?? []; $handlingVals = $post['pm_handling'] ?? []; $externalAccounts = $post['pm_external_account'] ?? [];
    if (!is_array($vendors) || !is_array($keys)) return 0;
    if (!is_array($excludedRaw)) $excludedRaw = [];
    $excludedSet = [];
    foreach ($excludedRaw as $v) $excludedSet[(string)$v] = true;
    $stmt = $db->prepare('UPDATE payment_method_accounts SET display_name=:name, account_fullname=:acct, excluded=:excluded, invoice_handling=:handling, external_account=:external, updated_at=CURRENT_TIMESTAMP WHERE vendor=:vendor AND method_key=:key');
    $payStmt = $db->prepare('UPDATE vendor_payments SET account_fullname=:acct, match_status=CASE WHEN :excluded=1 THEN "excluded_payment_method" WHEN match_status="excluded_payment_method" THEN "unmatched" ELSE match_status END, notes=CASE WHEN :excluded=1 AND instr(notes,"Excluded payment method")=0 THEN notes || " Excluded payment method; not included in payment matching report." WHEN :handling="external_expense" AND instr(notes,"Out-of-book payment method")=0 THEN notes || " Out-of-book payment method mapped to an expense/clearing account for manual handling." ELSE notes END WHERE vendor=:vendor AND method_key=:key');
    $seenVendors = [];
    foreach ($keys as $i=>$key) {
        $vendor = strtolower(trim((string)($vendors[$i] ?? ''))); $key = trim((string)$key); if ($vendor==='' || $key==='') continue;
        $seenVendors[$vendor] = true;
        $id = $vendor.'|'.$key;
        $handling = (string)($handlingVals[$i] ?? 'normal');
        if (!in_array($handling, ['normal','exclude_invoice','external_expense'], true)) $handling = 'normal';
        $ex = isset($excludedSet[$id]) || $handling === 'exclude_invoice' ? 1 : 0;
        $acct = trim((string)($accounts[$i] ?? ''));
        $external = trim((string)($externalAccounts[$i] ?? ''));
        $acctForPayments = ($handling === 'external_expense' && $external !== '') ? $external : $acct;
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $stmt->bindValue(':key', $key, SQLITE3_TEXT); $stmt->bindValue(':name', (string)($names[$i] ?? $key), SQLITE3_TEXT); $stmt->bindValue(':acct', $acct, SQLITE3_TEXT); $stmt->bindValue(':excluded', $ex, SQLITE3_INTEGER); $stmt->bindValue(':handling', $handling, SQLITE3_TEXT); $stmt->bindValue(':external', $external, SQLITE3_TEXT); $stmt->execute();
        $payStmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $payStmt->bindValue(':key', $key, SQLITE3_TEXT); $payStmt->bindValue(':acct', $acctForPayments, SQLITE3_TEXT); $payStmt->bindValue(':excluded', $ex, SQLITE3_INTEGER); $payStmt->bindValue(':handling', $handling, SQLITE3_TEXT); $payStmt->execute();
        $n++;
    }
    foreach (array_keys($seenVendors) as $vv) apply_payment_method_invoice_handling($db, $vv);
    return $n;
}

function apply_payment_method_invoice_handling(SQLite3 $db, string $vendor='amazon'): int {
    // If transactions show that all payments for an order are from a business/out-of-book
    // payment method, mark the staged vendor bill skipped so it won't hang unpaid in this book.
    $vendor = strtolower(trim($vendor)); if ($vendor === '') $vendor = 'amazon';
    $sql = 'SELECT p.order_id,
                   SUM(CASE WHEN COALESCE(m.invoice_handling, CASE WHEN COALESCE(m.excluded,0)=1 THEN "exclude_invoice" ELSE "normal" END)="exclude_invoice" OR COALESCE(m.excluded,0)=1 THEN 1 ELSE 0 END) AS excluded_rows,
                   SUM(CASE WHEN COALESCE(m.invoice_handling,"")="external_expense" THEN 1 ELSE 0 END) AS external_rows,
                   COUNT(*) AS total_rows,
                   GROUP_CONCAT(DISTINCT p.payment_method) AS methods,
                   GROUP_CONCAT(DISTINCT COALESCE(m.external_account,"")) AS external_accounts
            FROM vendor_payments p
            LEFT JOIN payment_method_accounts m ON m.vendor=p.vendor AND m.method_key=p.method_key
            WHERE p.vendor=:vendor AND p.order_id<>""
            GROUP BY p.order_id';
    $q = $db->prepare($sql); $q->bindValue(':vendor', $vendor, SQLITE3_TEXT); $rs = $q->execute();
    $skip = $db->prepare('UPDATE orders SET skip=1, warning=trim(COALESCE(warning,"") || " Payment method excluded/out-of-book; skipped from bill import: " || :methods), notes=trim(COALESCE(notes,"") || " Excluded from bill import because all payment rows use excluded payment method(s).") WHERE vendor=:vendor AND order_id=:oid');
    $warn = $db->prepare('UPDATE orders SET warning=trim(COALESCE(warning,"") || " Out-of-book payment method present; mapped to expense/clearing for manual handling: " || :methods), notes=trim(COALESCE(notes,"") || " Out-of-book payment mapping: " || :accounts) WHERE vendor=:vendor AND order_id=:oid AND skip=0');
    $n=0;
    while ($rs && ($r=$rs->fetchArray(SQLITE3_ASSOC))) {
        $oid=(string)$r['order_id']; $total=(int)$r['total_rows']; if($oid==='' || $total<=0) continue;
        $ex=(int)$r['excluded_rows']; $ext=(int)$r['external_rows'];
        if ($ex >= $total) { $skip->bindValue(':vendor',$vendor,SQLITE3_TEXT); $skip->bindValue(':oid',$oid,SQLITE3_TEXT); $skip->bindValue(':methods',(string)($r['methods']??''),SQLITE3_TEXT); $skip->execute(); $n++; }
        elseif ($ext > 0) { $warn->bindValue(':vendor',$vendor,SQLITE3_TEXT); $warn->bindValue(':oid',$oid,SQLITE3_TEXT); $warn->bindValue(':methods',(string)($r['methods']??''),SQLITE3_TEXT); $warn->bindValue(':accounts',(string)($r['external_accounts']??''),SQLITE3_TEXT); $warn->execute(); }
    }
    return $n;
}

function import_amazon_transaction_history_csv(SQLite3 $db, string $path): array {
    $fh = fopen($path, 'r'); if (!$fh) return [0, 'Could not open uploaded Amazon transaction CSV.'];
    $header = fgetcsv($fh); if (!$header) return [0, 'Amazon transaction CSV appears empty.'];
    $header = array_map('normalize_header_name', $header); $idx = array_flip($header);
    foreach (['date','order ids','card_details','amount'] as $need) if (!isset($idx[$need])) return [0, 'Amazon transaction CSV is missing required column: '.$need];
    $stmt = $db->prepare('INSERT INTO vendor_payments (vendor,payment_id,order_id,order_url,payment_date,payee,payment_method,method_key,amount,account_fullname,match_status,notes)
        VALUES ("amazon",:pid,:oid,:url,:pdate,:payee,:method,:mkey,:amount,:acct,:status,:notes)
        ON CONFLICT(vendor,payment_id) DO UPDATE SET order_id=excluded.order_id, order_url=excluded.order_url, payment_date=excluded.payment_date, payee=excluded.payee, payment_method=excluded.payment_method, method_key=excluded.method_key, amount=excluded.amount, account_fullname=excluded.account_fullname, match_status=excluded.match_status, notes=excluded.notes');
    [$windowStart, $windowEnd] = payment_match_window($db);
    $n=0; $methods=[]; $skippedWindow=0;
    while (($row = fgetcsv($fh)) !== false) {
        if (!array_filter($row, fn($v)=>trim((string)$v)!=='')) continue;
        $get = fn($name) => (string)($row[$idx[$name] ?? -1] ?? '');
        if (strtolower(trim($get('date'))) === 'date' && strtolower(trim($get('order ids'))) === 'order ids') continue; // tolerate repeated header rows
        $date = normalize_import_date(trim($get('date'))); $oids = trim($get('order ids')); $method = trim($get('card_details')); $amount = money_to_float($get('amount'));
        if (!payment_date_in_window($date, $windowStart, $windowEnd)) { $skippedWindow++; continue; }
        if ($oids === '' || $method === '' || abs($amount) < 0.0001) continue;
        $url = isset($idx['order urls']) ? trim($get('order urls')) : ''; $payee = isset($idx['vendor']) ? trim($get('vendor')) : 'Amazon';
        $ids = preg_split('/\s*[,;]\s*/', $oids) ?: [$oids];
        $allocated = amazon_allocate_multi_order_payment_amounts($db, $ids, $amount, $method);
        $isAllocatedGroup = count(array_filter($ids, fn($x)=>trim((string)$x)!=='')) > 1;
        if ($isAllocatedGroup) delete_legacy_multi_order_payment_rows($db, 'amazon', $date, $ids, $method, $amount);
        foreach ($allocated as $oid => $rowAmount) {
            $oid = trim((string)$oid); if ($oid==='') continue;
            $methodKey = normalize_payment_method_key($method); upsert_payment_method_account($db, 'amazon', $method);
            $map = load_payment_account_map($db, 'amazon');
            $pm = $map['amazon|'.$methodKey] ?? [];
            $acct = (string)($pm['account_fullname'] ?? default_payment_account_for_method('amazon',$method));
            $excluded = (int)($pm['excluded'] ?? 0) === 1;
            $status = $excluded ? 'excluded_payment_method' : 'unmatched';
            $baseId = preg_replace('/-CREDIT$/','',$oid);
            $has = $db->prepare('SELECT COUNT(*) FROM orders WHERE vendor="amazon" AND (order_id=:oid OR order_id=:credit)');
            $has->bindValue(':oid', $baseId, SQLITE3_TEXT); $has->bindValue(':credit', $baseId.'-CREDIT', SQLITE3_TEXT);
            $rowHas = $has->execute()->fetchArray(SQLITE3_NUM);
            if (!$excluded && $rowHas && (int)$rowHas[0] > 0) $status = 'matched_order_id';
            $pid = sha1('amazon|'.$date.'|'.$oid.'|'.$method.'|'.fmt_money($rowAmount).'|'.($isAllocatedGroup ? 'ALLOCATED-FROM-'.$oids.'|'.fmt_money($amount) : ''));
            $note = $excluded ? 'Amazon transaction history row. Excluded payment method; not included in payment matching report.' : 'Amazon transaction history payment/refund row. Match to bill by order ID; amount sign is preserved from Amazon export.';
            if ($isAllocatedGroup) $note .= ' Original Amazon transaction row named multiple orders ['.$oids.'] for $'.fmt_money(abs($amount)).'; v143 allocated this multi-order row to this order as $'.fmt_money(abs((float)$rowAmount)).' to avoid duplicating the combined amount.';
            $stmt->bindValue(':pid',$pid,SQLITE3_TEXT); $stmt->bindValue(':oid',$oid,SQLITE3_TEXT); $stmt->bindValue(':url',$url,SQLITE3_TEXT); $stmt->bindValue(':pdate',$date,SQLITE3_TEXT); $stmt->bindValue(':payee',$payee,SQLITE3_TEXT); $stmt->bindValue(':method',$method,SQLITE3_TEXT); $stmt->bindValue(':mkey',$methodKey,SQLITE3_TEXT); $stmt->bindValue(':amount',$rowAmount,SQLITE3_FLOAT); $stmt->bindValue(':acct',$acct,SQLITE3_TEXT); $stmt->bindValue(':status',$status,SQLITE3_TEXT); $stmt->bindValue(':notes',$note,SQLITE3_TEXT); $stmt->execute();
            $n++; $methods[$method]=true;
        }
    }
    $windowMsg = ($windowStart !== '' || $windowEnd !== '') ? ' Date window: ' . ($windowStart !== '' ? $windowStart : 'open') . ' through ' . ($windowEnd !== '' ? $windowEnd : 'open') . '; skipped ' . $skippedWindow . ' outside-window row(s).' : '';
    return [$n, 'Imported/updated '.$n.' Amazon transaction payment rows and found '.count($methods).' payment method(s).' . $windowMsg . ' Use the mapping panel below before relying on payment matches.'];
}
function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_OK => 'OK',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the configured size limit.',
        UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially received.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension.',
        default => 'Unknown upload error code ' . $code,
    };
}
function uploaded_file_entries(string $field): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [];
    $f = $_FILES[$field];
    $tmp = $f['tmp_name'] ?? null;
    $entries = [];
    if (is_array($tmp)) {
        foreach ($tmp as $i => $tmpName) {
            $entries[] = [
                'name' => (string)($f['name'][$i] ?? ('upload-' . ((int)$i + 1))),
                'type' => (string)($f['type'][$i] ?? ''),
                'tmp_name' => (string)$tmpName,
                'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($f['size'][$i] ?? 0),
            ];
        }
    } else {
        $entries[] = [
            'name' => (string)($f['name'] ?? $field),
            'type' => (string)($f['type'] ?? ''),
            'tmp_name' => (string)$tmp,
            'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($f['size'] ?? 0),
        ];
    }
    return array_values(array_filter($entries, fn($e) => (int)($e['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || (string)($e['tmp_name'] ?? '') !== ''));
}
function payment_import_message_is_success(string $msg): bool {
    return str_starts_with($msg, 'Imported/updated ');
}
function payment_import_savepoint_name(int $i): string {
    return 'amazon_tx_import_' . max(0, $i);
}
function payment_match_rows(SQLite3 $db, string $vendor = 'amazon', int $limit = 250, bool $includeExcluded = true, bool $respectDateWindow = true): array {
    $vendor = strtolower(trim($vendor));
    $where = $includeExcluded ? 'p.vendor=:vendor' : 'p.vendor=:vendor AND p.match_status<>"excluded_payment_method"';
    if ($respectDateWindow) $where .= payment_match_date_sql_where($db, 'p');
    // Do not join to both ORDERID and ORDERID-CREDIT here.  When both a base bill and
    // credit memo are staged, an OR join duplicates payment rows and creates confusing
    // exception reports.  Payment rows carry the original vendor order ID; use the exact
    // base order for display metadata and let build_payment_application_plan() decide the
    // target invoice/credit memo ID from the payment sign.
    $stmt = $db->prepare('SELECT p.*,
        (SELECT o.order_date FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS order_date,
        (SELECT o.total FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS order_total,
        (SELECT o.gift FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS stored_value,
        (SELECT o.tax FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS order_tax,
        (SELECT o.refund FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS refund_total,
        (SELECT o.skip FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS order_skip,
        (SELECT o.warning FROM orders o WHERE o.vendor=p.vendor AND o.order_id=p.order_id LIMIT 1) AS order_warning
        FROM vendor_payments p
        WHERE '.$where.' ORDER BY p.payment_date ' . (normalize_date_sort($GLOBALS['dateSort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC') . ', p.order_id, p.payment_method LIMIT :lim');
    $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $stmt->bindValue(':lim',$limit,SQLITE3_INTEGER); $res=$stmt->execute(); $out=[]; $seen=[];
    while($r=$res->fetchArray(SQLITE3_ASSOC)) {
        $k=(string)($r['vendor'] ?? '').'|'.(string)($r['payment_id'] ?? '');
        if($k !== '|' && isset($seen[$k])) continue;
        if($k !== '|') $seen[$k]=true;
        $out[]=$r;
    }
    return $out;
}

function payment_manual_verification_map(SQLite3 $db, string $vendor='amazon'): array {
    $vendor = strtolower(trim($vendor));
    $stmt = $db->prepare('SELECT payment_id, verified_at, note FROM payment_manual_verifications WHERE vendor=:vendor');
    $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT);
    $res=$stmt->execute(); $out=[];
    while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) $out[(string)$r['payment_id']]=$r;
    return $out;
}
function payment_target_exclusion_map(SQLite3 $db, string $vendor='amazon'): array {
    $vendor = strtolower(trim($vendor));
    $stmt = $db->prepare('SELECT target_id, reason, created_at FROM payment_target_exclusions WHERE vendor=:vendor');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $stmt->execute();
    $out = [];
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $id = trim((string)($r['target_id'] ?? ''));
        if ($id !== '') $out[$id] = $r;
    }
    return $out;
}
function payment_target_is_excluded(array $map, string $targetId, string $orderId=''): bool {
    $targetId = trim($targetId); $orderId = trim($orderId);
    if ($targetId !== '' && isset($map[$targetId])) return true;
    if ($orderId !== '' && isset($map[$orderId])) return true;
    // If the user excludes a base ORDERID, suppress its matching ORDERID-CREDIT too.
    // Do not do the reverse: excluding an orphan/not-found ORDERID-CREDIT must not
    // hide the normal purchase bill for ORDERID.
    if ($targetId !== '' && str_ends_with($targetId, '-CREDIT') && isset($map[substr($targetId, 0, -7)])) return true;
    return false;
}
function payment_match_window(SQLite3 $db): array {
    $start = normalize_import_date(get_config($db, 'payment_match_start_date', ''));
    $end = normalize_import_date(get_config($db, 'payment_match_end_date', ''));
    if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) { $tmp = $start; $start = $end; $end = $tmp; }
    return [$start, $end];
}
function payment_date_in_window(string $date, string $start='', string $end=''): bool {
    $d = normalize_import_date($date);
    if ($d === '') return true;
    if ($start !== '' && strcmp($d, $start) < 0) return false;
    if ($end !== '' && strcmp($d, $end) > 0) return false;
    return true;
}
function date_outside_active_match_window(SQLite3 $db, string $date): bool {
    $d = normalize_import_date($date);
    if ($d === '') return false;
    [$start, $end] = payment_match_window($db);
    if ($start === '' && $end === '') return false;
    return !payment_date_in_window($d, $start, $end);
}
function payment_match_date_sql_where(SQLite3 $db, string $alias='p'): string {
    [$start, $end] = payment_match_window($db);
    $col = $alias !== '' ? $alias . '.payment_date' : 'payment_date';
    $where = '';
    if ($start !== '') $where .= ' AND substr(COALESCE(' . $col . ',""),1,10) >= "' . SQLite3::escapeString($start) . '"';
    if ($end !== '') $where .= ' AND substr(COALESCE(' . $col . ',""),1,10) <= "' . SQLite3::escapeString($end) . '"';
    return $where;
}
function save_payment_target_exclusion(SQLite3 $db, string $vendor, string $targetId, string $reason=''): bool {
    $vendor = strtolower(trim($vendor)); $targetId = trim($targetId);
    if ($vendor === '') $vendor = 'amazon';
    if ($targetId === '') return false;
    $stmt = $db->prepare('INSERT INTO payment_target_exclusions (vendor,target_id,reason,created_at) VALUES (:vendor,:target,:reason,:ts) ON CONFLICT(vendor,target_id) DO UPDATE SET reason=excluded.reason, created_at=excluded.created_at');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':target', $targetId, SQLITE3_TEXT);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':ts', now_sql(), SQLITE3_TEXT);
    return (bool)$stmt->execute();
}

function account_guids_for_fullname(array $acctMap, string $accountFullname): array {
    $accountFullname = trim($accountFullname);
    if ($accountFullname === '') return [];
    $out=[];
    foreach($acctMap as $guid=>$info) {
        $name = (string)($info['name'] ?? '');
        if ($name !== '' && account_names_equivalent($name, $accountFullname)) $out[]=(string)$guid;
    }
    return $out;
}
function sqlite_in_clause_values(array $values, string $prefix='v'): array {
    $ph=[]; $params=[]; $i=0;
    foreach($values as $v){ $k=':'.$prefix.$i++; $ph[]=$k; $params[$k]=(string)$v; }
    return [$ph,$params];
}
function gnucash_sqlite_find_payment_transaction(SQLite3 $gdb, array $acctMap, string $accountFullname, float $amount, string $paymentDate, string $orderId): array {
    $accountFullname = trim($accountFullname);
    $orderId = trim($orderId);
    $absAmount = abs($amount);
    if ($accountFullname === '') return ['status'=>'no_mapped_account','label'=>'no mapped account','matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
    if ($absAmount < 0.0001) return ['status'=>'zero_amount','label'=>'zero amount','matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
    $guids = account_guids_for_fullname($acctMap, $accountFullname);
    if (!$guids) return ['status'=>'account_not_found','label'=>'account not found in GnuCash copy','matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
    $date = normalize_import_date($paymentDate);
    $start = $date ? date('Y-m-d', strtotime($date.' -7 days')) : '0001-01-01';
    $end = $date ? date('Y-m-d', strtotime($date.' +7 days')) : '9999-12-31';
    [$ph,$params] = sqlite_in_clause_values($guids, 'acct');
    $amtExpr = gnc_amount_sql('s');
    $sql = 'SELECT t.guid AS tx_guid, substr(t.post_date,1,10) AS post_date, COALESCE(t.description,"") AS description, COALESCE(t.num,"") AS num, COALESCE(s.memo,"") AS memo, '.$amtExpr.' AS amount
            FROM splits s JOIN transactions t ON t.guid=s.tx_guid
            WHERE s.account_guid IN ('.implode(',',$ph).')
              AND ABS(ABS('.$amtExpr.') - :amt) <= 0.015
              AND (:start="0001-01-01" OR substr(t.post_date,1,10) BETWEEN :start AND :end)
            ORDER BY CASE WHEN instr(COALESCE(t.description,"") || " " || COALESCE(t.num,"") || " " || COALESCE(s.memo,""), :oid) > 0 THEN 0 ELSE 1 END, ABS(julianday(substr(t.post_date,1,10))-julianday(:pdate)) ASC
            LIMIT 10';
    try {
        $stmt=$gdb->prepare($sql);
        foreach($params as $k=>$v) $stmt->bindValue($k,$v,SQLITE3_TEXT);
        $stmt->bindValue(':amt',$absAmount,SQLITE3_FLOAT);
        $stmt->bindValue(':start',$start,SQLITE3_TEXT); $stmt->bindValue(':end',$end,SQLITE3_TEXT);
        $stmt->bindValue(':pdate',$date ?: $start,SQLITE3_TEXT); $stmt->bindValue(':oid',$orderId,SQLITE3_TEXT);
        $res=$stmt->execute(); $best=null; $fallback=null;
        while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
            $hay = (string)($r['description'] ?? '') . ' ' . (string)($r['num'] ?? '') . ' ' . (string)($r['memo'] ?? '');
            $containsOrder = ($orderId !== '' && stripos($hay, $orderId) !== false);
            if ($containsOrder) { $best=$r; break; }
            if ($fallback===null) $fallback=$r;
        }
        $r = $best ?: $fallback;
        if ($r) {
            $hay = (string)($r['description'] ?? '') . ' ' . (string)($r['num'] ?? '') . ' ' . (string)($r['memo'] ?? '');
            $containsOrder = ($orderId !== '' && stripos($hay, $orderId) !== false);
            $status = $containsOrder ? 'present_order_amount' : 'present_amount_date';
            $label = $containsOrder ? 'present in GnuCash (order+amount)' : 'present in GnuCash (amount/date)';
            return ['status'=>$status,'label'=>$label,'matched'=>true,'date'=>(string)($r['post_date'] ?? ''),'amount'=>(float)($r['amount'] ?? 0),'description'=>clean_text($hay,160),'tx_guid'=>(string)($r['tx_guid'] ?? '')];
        }
    } catch(Throwable $e) {
        return ['status'=>'lookup_error','label'=>'lookup error: '.$e->getMessage(),'matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
    }
    return ['status'=>'not_found','label'=>'not found in mapped account','matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
}
function book_payment_presence_for_vendor_payments(SQLite3 $db, string $gnucashPath, string $vendor='amazon'): array {
    static $cache=[];
    $vendor=strtolower(trim($vendor));
    $resolved=resolve_local_path($gnucashPath);
    $fileStamp=($resolved!=='' && file_exists($resolved)) ? ((string)@filemtime($resolved).':'.(string)@filesize($resolved)) : '';
    $refreshToken=get_config($db,'payment_scan_refresh_token','');
    $cacheKey=$vendor.'|'.$resolved.'|'.$fileStamp.'|'.$refreshToken.'|'.(string)$db->querySingle('SELECT COALESCE(COUNT(*),0) FROM vendor_payments WHERE vendor="'.SQLite3::escapeString($vendor).'"');
    if(isset($cache[$cacheKey])) return $cache[$cacheKey];
    $out=[]; $cfg=gnucash_backend_config();
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') return $cache[$cacheKey]=$out;
    if($resolved==='' || !file_exists($resolved) || !is_readable($resolved)) return $cache[$cacheKey]=$out;
    try {
        $gdb=new SQLite3($resolved, SQLITE3_OPEN_READONLY); $gdb->busyTimeout(30000);
        $acctMap=gnucash_account_fullname_map_sqlite($gdb);
        $stmt=$db->prepare('SELECT payment_id, order_id, payment_date, amount, account_fullname FROM vendor_payments WHERE vendor=:vendor AND match_status<>"excluded_payment_method"' . payment_match_date_sql_where($db, 'vendor_payments'));
        $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT);
        $res=$stmt->execute(); $lookupCache=[];
        while($res && ($p=$res->fetchArray(SQLITE3_ASSOC))) {
            $pid=(string)($p['payment_id'] ?? ''); if($pid==='') continue;
            $key=trim((string)($p['account_fullname'] ?? '')).'|'.fmt_money(abs((float)($p['amount'] ?? 0))).'|'.normalize_import_date((string)($p['payment_date'] ?? '')).'|'.trim((string)($p['order_id'] ?? ''));
            if(!isset($lookupCache[$key])) $lookupCache[$key]=gnucash_sqlite_find_payment_transaction($gdb,$acctMap,(string)($p['account_fullname'] ?? ''),(float)($p['amount'] ?? 0),(string)($p['payment_date'] ?? ''),(string)($p['order_id'] ?? ''));
            $out[$pid]=$lookupCache[$key];
        }
    } catch(Throwable $e) {}
    return $cache[$cacheKey]=$out;
}

function flag_near_duplicate_payment_rows(array &$payments): bool {
    // Flag same-order payment rows that have the same sign and exact same amount within +/- 1 day.
    // This is not automatically wrong; Amazon can split shipments in odd ways.  It is a review aid
    // for accidental duplicate imports or a manually-entered payment in the wrong account.
    $found = false;
    $n = count($payments);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $ai = (float)($payments[$i]['amount'] ?? 0.0);
            $aj = (float)($payments[$j]['amount'] ?? 0.0);
            if (abs($ai) < 0.005 || abs($aj) < 0.005) continue;
            if (($ai < 0 && $aj > 0) || ($ai > 0 && $aj < 0)) continue;
            if (abs(abs($ai) - abs($aj)) >= 0.005) continue;
            $di = normalize_import_date((string)($payments[$i]['payment_date'] ?? ''));
            $dj = normalize_import_date((string)($payments[$j]['payment_date'] ?? ''));
            $dd = date_diff_days($di, $dj);
            if ($dd === null || abs($dd) > 1) continue;
            $note = 'Possible duplicate same-order ' . (($ai < 0) ? 'charge' : 'refund') . ' amount $' . fmt_money(abs($ai)) . ' within +/- 1 day; verify this is not a duplicated or wrong-account GnuCash transaction.';
            foreach ([$i, $j] as $k) {
                $payments[$k]['duplicate_candidate'] = true;
                $payments[$k]['duplicate_candidate_note'] = trim((string)($payments[$k]['duplicate_candidate_note'] ?? '') . ' ' . $note);
            }
            $found = true;
        }
    }
    return $found;
}

function amazon_order_payment_map_rows(SQLite3 $db, string $dateSort = 'desc', string $gnucashPath = ''): array {
    $dir = normalize_date_sort($dateSort) === 'asc' ? 'ASC' : 'DESC';
    $orders = [];
    $manualVerified = payment_manual_verification_map($db, 'amazon');
    $bookPresence = book_payment_presence_for_vendor_payments($db, $gnucashPath, 'amazon');
    $res = $db->query("SELECT * FROM orders WHERE vendor='amazon' AND order_id NOT LIKE '%-CREDIT' ORDER BY order_date $dir, order_id $dir");
    while ($o = $res->fetchArray(SQLITE3_ASSOC)) {
        $oid = trim((string)($o['order_id'] ?? ''));
        if ($oid === '') continue;
        $orders[$oid] = [
            'order_id' => $oid,
            'order_date' => (string)($o['order_date'] ?? ''),
            'order_url' => (string)($o['order_url'] ?? ''),
            'bill_total' => order_bill_total_for_validation($o),
            'card_total' => (float)($o['total'] ?? 0.0),
            'stored_value' => stored_value_payment_amount($o),
            'skip' => (int)($o['skip'] ?? 0),
            'warning' => (string)($o['warning'] ?? ''),
            'payments' => [],
            'payment_abs_sum' => 0.0,
            'payment_charge_abs_sum' => 0.0,
            'payment_refund_abs_sum' => 0.0,
            'payment_net_signed_sum' => 0.0,
            'payment_count' => 0,
            'payment_charge_count' => 0,
            'payment_refund_count' => 0,
            'refund_total' => (float)($o['refund'] ?? 0.0),
            'source' => 'order_export',
        ];
    }
    $res = $db->query("SELECT * FROM vendor_payments WHERE vendor='amazon' AND match_status<>'excluded_payment_method'" . payment_match_date_sql_where($db, '') . " ORDER BY payment_date $dir, order_id, amount");
    while ($p = $res->fetchArray(SQLITE3_ASSOC)) {
        $oid = trim((string)($p['order_id'] ?? ''));
        if ($oid === '') continue;
        if (!isset($orders[$oid])) {
            $orders[$oid] = [
                'order_id' => $oid,
                'order_date' => '',
                'order_url' => (string)($p['order_url'] ?? ''),
                'bill_total' => 0.0,
                'card_total' => 0.0,
                'stored_value' => 0.0,
                'skip' => 0,
                'warning' => 'Payment exists but no staged order row is loaded.',
                'payments' => [],
                'payment_abs_sum' => 0.0,
                'payment_charge_abs_sum' => 0.0,
                'payment_refund_abs_sum' => 0.0,
                'payment_net_signed_sum' => 0.0,
                'payment_count' => 0,
                'payment_charge_count' => 0,
                'payment_refund_count' => 0,
                'refund_total' => 0.0,
                'source' => 'payment_only',
            ];
        }
        $amt = (float)($p['amount'] ?? 0.0);
        $kind = $amt > 0.00001 ? 'refund' : ($amt < -0.00001 ? 'charge' : 'zero');
        $pid = (string)($p['payment_id'] ?? '');
        $presence = $bookPresence[$pid] ?? ['status'=>'not_checked','label'=>'not checked','matched'=>false,'date'=>'','amount'=>0.0,'description'=>''];
        $verified = isset($manualVerified[$pid]);
        if ($verified) $presence = ['status'=>'manual_verified','label'=>'manually verified','matched'=>true,'date'=>(string)($manualVerified[$pid]['verified_at'] ?? ''),'amount'=>$amt,'description'=>(string)($manualVerified[$pid]['note'] ?? '')];
        $orders[$oid]['payments'][] = [
            'payment_id' => $pid,
            'payment_date' => (string)($p['payment_date'] ?? ''),
            'payment_method' => (string)($p['payment_method'] ?? ''),
            'amount' => $amt,
            'kind' => $kind,
            'account_fullname' => (string)($p['account_fullname'] ?? ''),
            'match_status' => (string)($p['match_status'] ?? ''),
            'book_presence' => $presence,
            'manual_verified' => $verified,
        ];
        $orders[$oid]['payment_abs_sum'] = round((float)$orders[$oid]['payment_abs_sum'] + abs($amt), 2);
        $orders[$oid]['payment_net_signed_sum'] = round((float)$orders[$oid]['payment_net_signed_sum'] + $amt, 2);
        if ($amt < -0.00001) {
            $orders[$oid]['payment_charge_abs_sum'] = round((float)$orders[$oid]['payment_charge_abs_sum'] + abs($amt), 2);
            $orders[$oid]['payment_charge_count'] = (int)$orders[$oid]['payment_charge_count'] + 1;
        } elseif ($amt > 0.00001) {
            $orders[$oid]['payment_refund_abs_sum'] = round((float)$orders[$oid]['payment_refund_abs_sum'] + abs($amt), 2);
            $orders[$oid]['payment_refund_count'] = (int)$orders[$oid]['payment_refund_count'] + 1;
        }
        $orders[$oid]['payment_count'] = (int)$orders[$oid]['payment_count'] + 1;
        if ($orders[$oid]['order_url'] === '' && (string)($p['order_url'] ?? '') !== '') $orders[$oid]['order_url'] = (string)$p['order_url'];
    }
    // For manual cleanup, when Amazon reports Grand Total $0 and the transaction history
    // has an explicit stored-value charge (gift card / rewards), prefer the imported
    // transaction charge total as the expected bill total.  This prevents coupons from
    // being hidden as a false delta. Example: item 8.95 - coupon 1.00 + tax 0.60
    // = 8.55 paid by rewards; older order-level inference could show 9.55.
    foreach ($orders as &$om) {
        $chargeAbs = round((float)($om['payment_charge_abs_sum'] ?? 0.0), 2);
        $cardTotal = round((float)($om['card_total'] ?? 0.0), 2);
        $currentExpected = round((float)($om['bill_total'] ?? 0.0), 2);
        $hasStoredCharge = false;
        foreach ((array)($om['payments'] ?? []) as $pp) {
            if ((float)($pp['amount'] ?? 0.0) < -0.00001 && is_stored_value_payment_method((string)($pp['payment_method'] ?? ''))) {
                $hasStoredCharge = true;
                break;
            }
        }
        if (($om['source'] ?? '') === 'order_export' && $chargeAbs > 0.005 && abs($chargeAbs - $currentExpected) >= 0.01) {
            $om['bill_total_original'] = $currentExpected;
            $om['bill_total'] = $chargeAbs;
            $om['expected_total_source'] = ($hasStoredCharge || abs($cardTotal) < 0.005) ? 'transaction_history_stored_value' : 'transaction_history_charge_sum';
            $note = 'Expected bill total adjusted to imported Amazon charge total ($' . fmt_money($chargeAbs) . '); difference from order subtotal/tax is treated as merchant coupon/discount or prior import reconciliation issue.';
            $om['warning'] = trim((string)($om['warning'] ?? '') . ' ' . $note);
        }
        if (flag_near_duplicate_payment_rows($om['payments'])) {
            $om['duplicate_payment_candidate'] = true;
            $om['warning'] = trim((string)($om['warning'] ?? '') . ' Possible duplicate same-amount payment/refund rows detected within +/- 1 day; review before applying payments.');
        }
    }
    unset($om);
    $rows = array_values($orders);
    usort($rows, function($a, $b) use ($dir) {
        $ad = (string)($a['order_date'] ?: (($a['payments'][0]['payment_date'] ?? '')));
        $bd = (string)($b['order_date'] ?: (($b['payments'][0]['payment_date'] ?? '')));
        $cmp = strcmp($ad, $bd);
        if ($cmp === 0) $cmp = strcmp((string)$a['order_id'], (string)$b['order_id']);
        return $dir === 'ASC' ? $cmp : -$cmp;
    });
    return $rows;
}


function amazon_order_id_like(string $id): bool {
    $id = trim($id);
    return (bool)preg_match('/^(?:\d{3}-\d{7}-\d{7}|D01-\d{7}-\d{7})(?:-CREDIT)?$/', $id);
}
function sqlite_column_names(SQLite3 $db, string $table): array {
    $cols = [];
    try {
        $res = $db->query('PRAGMA table_info(' . $table . ')');
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $cols[(string)$r['name']] = true;
    } catch (Throwable $e) {}
    return $cols;
}
function gnucash_vendor_guid_for_tool_vendor(SQLite3 $gdb, string $vendor='amazon'): string {
    $vendor = strtolower(trim($vendor));
    $vendorId = vendor_config($vendor)['vendor_id'];
    $needle = $vendor === 'amazon' ? 'amazon' : str_replace('_', ' ', $vendor);
    if (!table_exists($gdb, 'vendors')) return '';
    try {
        $stmt = $gdb->prepare('SELECT guid, id, name FROM vendors WHERE id=:id OR lower(name) LIKE :name ORDER BY CASE WHEN id=:id THEN 0 ELSE 1 END LIMIT 1');
        $stmt->bindValue(':id', $vendorId, SQLITE3_TEXT);
        $stmt->bindValue(':name', '%' . strtolower($needle) . '%', SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? (string)($row['guid'] ?? '') : '';
    } catch (Throwable $e) { return ''; }
}
function gnucash_book_invoice_ids_for_vendor(string $path, string $vendor='amazon'): array {
    $resolved = resolve_local_path($path);
    if ($resolved === '' || !file_exists($resolved) || !is_readable($resolved)) return [];
    $out = [];
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY); $gdb->busyTimeout(30000);
        $invCols = sqlite_column_names($gdb, 'invoices');
        if (!$invCols || !isset($invCols['id'])) return [];
        $vendorGuid = gnucash_vendor_guid_for_tool_vendor($gdb, $vendor);
        if ($vendorGuid !== '' && isset($invCols['owner_guid'])) {
            $stmt = $gdb->prepare('SELECT id FROM invoices WHERE owner_guid=:guid');
            $stmt->bindValue(':guid', $vendorGuid, SQLITE3_TEXT);
            $res = $stmt->execute();
            while ($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
                $id = trim((string)($r['id'] ?? ''));
                if ($id !== '') $out[$id] = true;
            }
        }
        // Fallback/sanity pass: include Amazon-looking IDs even if owner_guid cannot be resolved
        // or if older/manual entries have vendor metadata inconsistencies.
        if ($vendor === 'amazon') {
            $res = $gdb->query('SELECT id FROM invoices');
            while ($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
                $id = trim((string)($r['id'] ?? ''));
                if ($id !== '' && amazon_order_id_like($id)) $out[$id] = true;
            }
        }
    } catch (Throwable $e) {}
    return array_keys($out);
}
function amazon_staged_expected_invoice_total_for_order(SQLite3 $db, array $order): float {
    $oid = trim((string)($order['order_id'] ?? ''));
    if ($oid === '') return round(order_bill_total_for_validation($order), 2);
    $itemSum = amazon_staged_item_sum($db, $oid);
    $tax = round((float)($order['tax'] ?? 0.0), 2);
    $ship = round((float)($order['shipping'] ?? 0.0), 2);
    $shipRefund = round((float)($order['shipping_refund'] ?? 0.0), 2);
    $total = round((float)($order['total'] ?? 0.0), 2);
    $stored = round(stored_value_payment_amount($order), 2);
    $payments = strtolower((string)($order['payments'] ?? ''));
    $hasStoredText = str_contains($payments, 'gift card') || str_contains($payments, 'rewards point') || str_contains($payments, 'reward points') || str_contains($payments, 'cash back') || str_contains($payments, 'points');
    // For all-rewards/points orders Amazon's order CSV can show grand total 0 and omit
    // the separate Free Shipping offset.  The invoice total should still be the gross
    // vendor bill for the order, not 0 and not the combined rewards row for a group.
    if ($hasStoredText && abs($total) < 0.005 && abs($stored) < 0.005 && $itemSum > 0.005) {
        if ($ship > 0.005 && $shipRefund < 0.005) return round($itemSum + $tax, 2);
        return round($itemSum + max(0.0, $ship - $shipRefund) + $tax, 2);
    }
    return round(order_bill_total_for_validation($order), 2);
}

function staged_expected_invoice_targets(SQLite3 $db, string $vendor='amazon'): array {
    $vendor = strtolower(trim($vendor));
    $targets = [];
    $stmt = $db->prepare('SELECT * FROM orders WHERE vendor=:vendor');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($res && ($o=$res->fetchArray(SQLITE3_ASSOC))) {
        $id = trim((string)($o['order_id'] ?? '')); if ($id === '') continue;
        $targets[$id] = [
            'target_id'=>$id,
            'order_date'=>(string)($o['order_date'] ?? ''),
            'expected_invoice_total'=>($vendor === 'amazon' ? amazon_staged_expected_invoice_total_for_order($db, $o) : round(order_bill_total_for_validation($o), 2)),
            'card_total'=>round((float)($o['total'] ?? 0.0), 2),
            'stored_value'=>round(stored_value_payment_amount($o), 2),
            'staged_status'=>((int)($o['skip'] ?? 0) !== 0 ? 'staged_skipped' : 'staged_exportable'),
            'warning'=>(string)($o['warning'] ?? ''),
            'order_url'=>(string)($o['order_url'] ?? ''),
        ];
    }
    return $targets;
}
function expected_payment_totals_by_target(SQLite3 $db, string $vendor='amazon'): array {
    $vendor = strtolower(trim($vendor));
    $out = [];
    $stmt = $db->prepare('SELECT order_id, payment_date, payment_method, amount, match_status FROM vendor_payments WHERE vendor=:vendor AND match_status<>"excluded_payment_method"' . payment_match_date_sql_where($db, 'vendor_payments'));
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($res && ($p=$res->fetchArray(SQLITE3_ASSOC))) {
        $oid = trim((string)($p['order_id'] ?? '')); if ($oid === '') continue;
        $amt = (float)($p['amount'] ?? 0.0);
        $target = $amt > 0.00001 ? $oid . '-CREDIT' : $oid;
        if (!isset($out[$target])) $out[$target] = ['expected_payment_total'=>0.0,'expected_charge_total'=>0.0,'expected_refund_total'=>0.0,'payment_count'=>0,'methods'=>[],'dates'=>[],'raw_payments'=>[],'duplicate_candidate'=>false];
        $out[$target]['expected_payment_total'] = round((float)$out[$target]['expected_payment_total'] + abs($amt), 2);
        $out[$target]['raw_payments'][] = ['date'=>normalize_import_date((string)($p['payment_date'] ?? '')), 'method'=>(string)($p['payment_method'] ?? ''), 'amount'=>$amt];
        if ($amt < -0.00001) $out[$target]['expected_charge_total'] = round((float)$out[$target]['expected_charge_total'] + abs($amt), 2);
        if ($amt > 0.00001) $out[$target]['expected_refund_total'] = round((float)$out[$target]['expected_refund_total'] + abs($amt), 2);
        $out[$target]['payment_count'] = (int)$out[$target]['payment_count'] + 1;
        $m = trim((string)($p['payment_method'] ?? '')); if($m!=='') $out[$target]['methods'][$m]=true;
        $d = normalize_import_date((string)($p['payment_date'] ?? '')); if($d!=='') $out[$target]['dates'][$d]=true;
    }
    foreach($out as &$r) {
        $raw = (array)($r['raw_payments'] ?? []);
        for ($i=0; $i<count($raw); $i++) {
            for ($j=$i+1; $j<count($raw); $j++) {
                $ai=(float)($raw[$i]['amount'] ?? 0.0); $aj=(float)($raw[$j]['amount'] ?? 0.0);
                if (abs($ai) < 0.005 || abs($aj) < 0.005) continue;
                if (($ai < 0 && $aj > 0) || ($ai > 0 && $aj < 0)) continue;
                if (abs(abs($ai)-abs($aj)) >= 0.005) continue;
                $dd = date_diff_days((string)($raw[$i]['date'] ?? ''), (string)($raw[$j]['date'] ?? ''));
                if ($dd !== null && abs($dd) <= 1) { $r['duplicate_candidate'] = true; break 2; }
            }
        }
        unset($r['raw_payments']);
        $r['methods'] = implode('; ', array_keys((array)$r['methods']));
        $r['dates'] = implode('; ', array_keys((array)$r['dates']));
    }
    unset($r);
    return $out;
}

function excluded_ledger_targets_by_payment_method(SQLite3 $db, string $vendor='amazon'): array {
    $vendor = strtolower(trim($vendor));
    $out = [];
    // Payment rows that the user marked as excluded/out-of-book should not drive the
    // active Amazon vendor-ledger diff.  They are reported in the separate
    // Excluded/out-of-book invoice section instead.
    try {
        $stmt = $db->prepare('SELECT order_id, amount FROM vendor_payments WHERE vendor=:vendor AND match_status="excluded_payment_method"');
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $oid = trim((string)($r['order_id'] ?? ''));
            if ($oid === '') continue;
            $out[$oid] = true;
            if ((float)($r['amount'] ?? 0.0) > 0.00001) $out[$oid . '-CREDIT'] = true;
        }
    } catch (Throwable $e) {}
    try {
        $stmt = $db->prepare('SELECT order_id FROM orders WHERE vendor=:vendor AND (warning LIKE "%Payment method excluded/out-of-book%" OR notes LIKE "%Excluded from bill import%")');
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $oid = trim((string)($r['order_id'] ?? ''));
            if ($oid !== '') $out[$oid] = true;
        }
    } catch (Throwable $e) {}
    try {
        $stmt = $db->prepare('SELECT target_id FROM payment_target_exclusions WHERE vendor=:vendor');
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $tid = trim((string)($r['target_id'] ?? ''));
            if ($tid === '') continue;
            $out[$tid] = true;
            // Base-order exclusions suppress both ORDERID and ORDERID-CREDIT.
            // A CREDIT-only exclusion suppresses only the orphan/not-found credit memo.
            if (!str_ends_with($tid, '-CREDIT')) $out[$tid . '-CREDIT'] = true;
        }
    } catch (Throwable $e) {}
    return $out;
}

function should_suppress_zero_vendor_ledger_row(array $rowContext): bool {
    $expectedInvoice = abs((float)($rowContext['expected_invoice_total'] ?? 0.0));
    $bookInvoice = abs((float)($rowContext['book_invoice_total'] ?? 0.0));
    $expectedPayment = abs((float)($rowContext['expected_payment_total'] ?? 0.0));
    $bookPayment = abs((float)($rowContext['book_payment_total'] ?? 0.0));
    return $expectedInvoice < 0.005 && $bookInvoice < 0.005 && $expectedPayment < 0.005 && $bookPayment < 0.005;
}

function book_payment_total_from_state(array $state): float {
    $sum = 0.0; $seen = [];
    foreach ((array)($state['payment_accounts'] ?? []) as $pa) {
        $tx = (string)($pa['tx_guid'] ?? '');
        $acct = (string)($pa['account'] ?? '');
        $amt = round(abs((float)($pa['amount'] ?? 0.0)), 2);
        $key = $tx.'|'.$acct.'|'.fmt_money($amt);
        if(isset($seen[$key])) continue;
        $seen[$key]=true; $sum = round($sum + $amt, 2);
    }
    return $sum;
}
function book_payment_accounts_summary_from_state(array $state): string {
    $parts=[];
    foreach ((array)($state['payment_accounts'] ?? []) as $pa) {
        $acct = trim((string)($pa['account'] ?? '')); if($acct==='') continue;
        $parts[$acct] = true;
    }
    return implode('; ', array_keys($parts));
}
function vendor_ledger_diff_rows(SQLite3 $db, string $vendor='amazon', string $gnucashPath='', string $dateSort='asc'): array {
    static $cache=[];
    $vendor = strtolower(trim($vendor));
    $resolved = resolve_local_path($gnucashPath);
    $fileStamp = ($resolved!=='' && file_exists($resolved)) ? ((string)@filemtime($resolved).':'.(string)@filesize($resolved)) : '';
    $refreshToken = get_config($db,'payment_scan_refresh_token','');
    $suppressZeroLedgerTargets = get_config($db, 'suppress_zero_vendor_ledger_targets', '1') === '1';
    [$activeStart, $activeEnd] = payment_match_window($db);
    $cacheKey = $vendor.'|'.$resolved.'|'.$fileStamp.'|'.$refreshToken.'|'.$dateSort.'|'.($suppressZeroLedgerTargets?'suppress0':'show0').'|'.$activeStart.'|'.$activeEnd.'|'.(string)$db->querySingle('SELECT COUNT(*) FROM orders').'|'.(string)$db->querySingle('SELECT COUNT(*) FROM vendor_payments').'|'.(string)$db->querySingle('SELECT COALESCE(MAX(rowid),0) FROM vendor_payments').'|'.(string)$db->querySingle('SELECT COALESCE(MAX(rowid),0) FROM payment_target_exclusions');
    if(isset($cache[$cacheKey])) return $cache[$cacheKey];
    $expected = staged_expected_invoice_targets($db, $vendor);
    $expectedPay = expected_payment_totals_by_target($db, $vendor);
    $excludedTargets = excluded_ledger_targets_by_payment_method($db, $vendor);
    $bookIds = gnucash_book_invoice_ids_for_vendor($gnucashPath, $vendor);
    $all = [];
    foreach(array_keys($expected) as $id) $all[$id]=true;
    foreach(array_keys($expectedPay) as $id) $all[$id]=true;
    foreach($bookIds as $id) $all[$id]=true;
    $states = load_gnucash_invoice_states_for_payments($gnucashPath, array_keys($all));
    $rows=[];
    foreach(array_keys($all) as $id) {
        // PHP converts numeric-looking array keys to integers. Lowe's order IDs are
        // numeric-looking, so cast back to string before using string helpers such
        // as str_ends_with() or passing IDs to typed helper functions.
        $id = (string)$id;
        if (isset($excludedTargets[$id])) {
            // Excluded/out-of-book payment methods are tracked separately and should
            // not participate in the active ledger drift/diff workflow.
            continue;
        }
        $exp = $expected[$id] ?? null;
        $pay = $expectedPay[$id] ?? ['expected_payment_total'=>0.0,'payment_count'=>0,'methods'=>'','dates'=>''];
        $st = $states[$id] ?? invoice_empty_state($id);
        // v111: the transaction-matching date window should narrow the active review
        // universe, not just the raw payment rows.  If a staged/book-only target has no
        // imported payment inside the window and its own order/post date is outside the
        // window, suppress it here so 2023/2024/2026 legacy bills do not pollute a 2025 pass.
        $hasWindowPayment = isset($expectedPay[$id]);
        if (!$hasWindowPayment) {
            $candidateDate = normalize_import_date((string)($exp['order_date'] ?? ''));
            if ($candidateDate === '') $candidateDate = normalize_import_date((string)($st['date_posted'] ?? ''));
            if ($candidateDate !== '' && date_outside_active_match_window($db, $candidateDate)) continue;
        }
        $found = (bool)($st['found'] ?? false); $posted = (bool)($st['posted'] ?? false);
        $bookInvoiceTotal = round((float)($st['invoice_total'] ?? 0.0), 2);
        $expectedInvoiceTotal = round((float)($exp['expected_invoice_total'] ?? 0.0), 2);
        if ($expectedInvoiceTotal <= 0.005 && str_ends_with($id, '-CREDIT') && (float)($pay['expected_payment_total'] ?? 0.0) > 0.005) $expectedInvoiceTotal = round((float)$pay['expected_payment_total'], 2);
        $bookPaymentTotal = book_payment_total_from_state($st);
        $expectedPaymentTotal = round((float)($pay['expected_payment_total'] ?? 0.0), 2);
        $expectedInvoiceBasis = 'order_export';
        // Amazon's order export total can represent only the card-charged balance when
        // stored value / rewards / gift cards were also used.  For vendor-bill sanity
        // checking, the GnuCash invoice should equal the gross bill before payments,
        // i.e. the sum of all charge-side payment rows.  Do not apply this to credit
        // memo targets, where refund rows intentionally map to ORDER-CREDIT.
        if (!str_ends_with($id, '-CREDIT') && $expectedPaymentTotal > 0.005 && abs($expectedPaymentTotal - $expectedInvoiceTotal) >= 0.01) {
            // Use transaction-history charge totals to raise the expected invoice amount
            // when Amazon split the bill across card + gift/rewards/cashback payments.
            // v111 guardrail: if the book already matches the staged per-order expected
            // total, do not let a stale/unallocated grouped rewards row raise this one
            // invoice to the entire group amount. Re-importing v111 will also delete those
            // stale full-amount rows, but this keeps the sanity report safe meanwhile.
            if ($bookInvoiceTotal > 0.005 && abs($bookInvoiceTotal - $expectedInvoiceTotal) < 0.01) {
                $expectedInvoiceBasis = 'order_export_book_match_transaction_sum_ignored';
            } elseif ($expectedPaymentTotal > $expectedInvoiceTotal + 0.005) {
                $expectedInvoiceTotal = $expectedPaymentTotal;
                $expectedInvoiceBasis = 'transaction_charge_sum';
            } elseif ($bookInvoiceTotal > 0.005 && abs($bookInvoiceTotal - $expectedPaymentTotal) < 0.01) {
                // v140 guardrail: if the posted book invoice already matches the
                // imported Amazon transaction charge total, do not inflate it to a
                // larger staged item/order total.  This catches bad/stale staged
                // item reconciliation rows such as a false positive order-level
                // adjustment while preserving true card+stored-value gross-up cases
                // handled above when payment total is greater than order export.
                $expectedInvoiceTotal = $expectedPaymentTotal;
                $expectedInvoiceBasis = 'transaction_charge_sum_book_match_order_export_higher_ignored';
            } else {
                $expectedInvoiceBasis = 'order_export_transaction_sum_lower_ignored';
            }
        }
        $status = 'ok_or_reviewed'; $action = 'No obvious difference detected.';
        $isCreditTarget = str_ends_with($id, '-CREDIT');
        $creditMemoPaidInBookOk = $isCreditTarget && $found && $posted && $expectedInvoiceTotal > 0.005 && abs($bookInvoiceTotal - $expectedInvoiceTotal) < 0.01 && abs($bookPaymentTotal - $expectedInvoiceTotal) < 0.01 && $expectedPaymentTotal < 0.005;
        if (!$exp && $found && $posted) { $status='extra_posted_invoice_in_book'; $action='Posted GnuCash invoice/credit exists for vendor but is not present in loaded order/transaction targets; review for duplicate/manual entry.'; }
        elseif (!$exp && $found && !$posted) { $status='extra_unposted_invoice_in_book'; $action='Unposted legacy/noise invoice exists; usually safe to ignore after confirming it is intentionally unposted.'; }
        elseif (($exp || $expectedPaymentTotal > 0.005) && !$found) { $status='missing_in_book'; $action='Expected target from loaded orders/transactions is not found in GnuCash; import/recover or mark out-of-scope.'; }
        elseif ($found && !$posted) { $status='book_invoice_unposted'; $action='GnuCash invoice exists but is unposted; post it or ignore if intentionally out-of-scope.'; }
        elseif (!empty($st['ambiguous_duplicate_posted'])) { $status='duplicate_posted_invoice_id_review'; $action='Multiple posted GnuCash invoices share this visible ID; rename/delete/unpost duplicates before automation.'; }
        elseif ((int)($st['duplicate_invoice_count'] ?? 0) > 1) { $status='duplicate_invoice_id_note'; $action='Duplicate visible ID exists, but one posted record was selected; cleanup is recommended if it confuses reports.'; }
        if ($creditMemoPaidInBookOk) {
            $status='ok_or_reviewed';
            $action='Credit memo is posted and paid/matched in GnuCash. No separate Amazon transaction-history row is required for this target if the refund/payment association was applied manually.';
        } else {
            if ($found && $posted && $expectedInvoiceTotal > 0.005 && abs($bookInvoiceTotal - $expectedInvoiceTotal) >= 0.01) { $status='invoice_total_mismatch'; $action='GnuCash posted invoice total differs from loaded order/credit total.'; }
            if ($found && $posted && $expectedPaymentTotal > 0.005 && abs($bookPaymentTotal - $expectedPaymentTotal) >= 0.01) {
                $status = ($bookPaymentTotal > $expectedPaymentTotal) ? 'extra_or_duplicate_book_payment' : 'missing_book_payment';
                $action = ($bookPaymentTotal > $expectedPaymentTotal) ? 'Book shows more vendor-linked payment/refund amount than Amazon transactions; look for duplicate/extra payment associations.' : 'Book shows less vendor-linked payment/refund amount than Amazon transactions; apply missing payment or verify manually.';
            }
            if ($found && $posted && $expectedPaymentTotal < 0.005 && $bookPaymentTotal > 0.005) { $status='extra_book_payment_no_amazon_transaction'; $action='GnuCash has vendor-linked payment/refund but no imported Amazon transaction for this target.'; }
            if (!empty($pay['duplicate_candidate']) && $status === 'ok_or_reviewed') {
                $status = 'possible_duplicate_payment_review';
                $action = 'Amazon transaction history has same-order payment/refund rows with identical amounts within +/- 1 day. This can be valid, but review for duplicate or wrong-account GnuCash entry.';
            }
        }
        $rowCtx = [
            'expected_invoice_total'=>$expectedInvoiceTotal,
            'book_invoice_total'=>$bookInvoiceTotal,
            'expected_payment_total'=>$expectedPaymentTotal,
            'book_payment_total'=>$bookPaymentTotal,
        ];
        if ($suppressZeroLedgerTargets && should_suppress_zero_vendor_ledger_row($rowCtx)) {
            continue;
        }
        $rows[] = [
            'target_id'=>$id,
            'order_date'=>(string)($exp['order_date'] ?? ''),
            'payment_dates'=>(string)($pay['dates'] ?? ''),
            'status'=>$status,
            'recommended_action'=>$action,
            'staged_status'=>(string)($exp['staged_status'] ?? 'not_staged'),
            'book_found'=>$found ? 'yes':'no',
            'book_posted'=>$posted ? 'yes':'no',
            'expected_invoice_total'=>$expectedInvoiceTotal,
            'expected_invoice_basis'=>$expectedInvoiceBasis,
            'book_invoice_total'=>$bookInvoiceTotal,
            'invoice_delta'=>round($bookInvoiceTotal - $expectedInvoiceTotal, 2),
            'expected_payment_total'=>$expectedPaymentTotal,
            'book_payment_total'=>$bookPaymentTotal,
            'payment_delta'=>round($bookPaymentTotal - $expectedPaymentTotal, 2),
            'payment_methods'=>(string)($pay['methods'] ?? ''),
            'book_payment_accounts'=>book_payment_accounts_summary_from_state($st),
            'book_invoice_guid'=>(string)($st['invoice_guid'] ?? ''),
            'book_notes'=>(string)($st['notes'] ?? ''),
            'warning'=>trim((string)($exp['warning'] ?? '') . (!empty($pay['duplicate_candidate']) ? ' Possible duplicate same-amount payment/refund rows within +/- 1 day.' : '')),
        ];
    }
    $dir = normalize_date_sort($dateSort) === 'asc' ? 'ASC' : 'DESC';
    usort($rows, function($a,$b) use($dir){
        $ad=(string)($a['order_date'] ?: strtok((string)$a['payment_dates'], ';') ?: '9999-99-99');
        $bd=(string)($b['order_date'] ?: strtok((string)$b['payment_dates'], ';') ?: '9999-99-99');
        $cmp=strcmp($ad,$bd); if($cmp===0) $cmp=strcmp((string)$a['target_id'],(string)$b['target_id']);
        return $dir==='ASC' ? $cmp : -$cmp;
    });
    return $cache[$cacheKey]=$rows;
}
function export_vendor_ledger_diff_report(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath='', string $dateSort='asc'): int {
    $rows = vendor_ledger_diff_rows($db, $vendor, $gnucashPath, $dateSort);
    $fh = fopen($outPath, 'w'); if(!$fh) return 0;
    fputcsv($fh, ['target_id','order_date','payment_dates','status','staged_status','book_found','book_posted','expected_invoice_total','expected_invoice_basis','book_invoice_total','invoice_delta','expected_payment_total','book_payment_total','payment_delta','payment_methods','book_payment_accounts','book_invoice_guid','recommended_action','book_notes','warning']);
    foreach($rows as $r) fputcsv($fh, [$r['target_id'],$r['order_date'],$r['payment_dates'],$r['status'],$r['staged_status'],$r['book_found'],$r['book_posted'],fmt_money((float)$r['expected_invoice_total']),(string)($r['expected_invoice_basis'] ?? ''),fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['invoice_delta']),fmt_money((float)$r['expected_payment_total']),fmt_money((float)$r['book_payment_total']),fmt_money((float)$r['payment_delta']),$r['payment_methods'],$r['book_payment_accounts'],$r['book_invoice_guid'],$r['recommended_action'],$r['book_notes'],dedupe_warning_text((string)$r['warning'])]);
    fclose($fh); return count($rows);
}
function vendor_ledger_diff_status_counts(array $rows): array {
    $c=[]; foreach($rows as $r){$s=(string)($r['status'] ?? ''); if($s==='')$s='unknown'; $c[$s]=($c[$s]??0)+1;} ksort($c); return $c;
}

function invoice_sanity_check_rows(SQLite3 $db, string $vendor='amazon', string $gnucashPath='', string $dateSort='asc'): array {
    // Focused review: posted book invoices whose GnuCash total differs from the expected
    // vendor/order/payment total.  This is especially useful after older invoice imports,
    // where Amazon rewards/gift-card payments may have been incorrectly imported as
    // negative discount/coupon bill lines.
    $rows = vendor_ledger_diff_rows($db, $vendor, $gnucashPath, $dateSort);
    $out = [];
    foreach ($rows as $r) {
        $target = (string)($r['target_id'] ?? '');
        $found = (string)($r['book_found'] ?? '') === 'yes';
        $posted = (string)($r['book_posted'] ?? '') === 'yes';
        if (!$found || !$posted) continue;
        $isCreditMemoTarget = str_ends_with($target, '-CREDIT');
        $expectedRaw = (float)($r['expected_invoice_total'] ?? 0.0);
        $bookRaw = (float)($r['book_invoice_total'] ?? 0.0);
        // GnuCash often stores/displays vendor credit memo document totals as positive
        // bill-like amounts even though the document reduces the vendor balance.  The
        // order/import side may carry the credit expected value as negative.  For sanity
        // checking credit memos, compare absolute document totals so a correct $9.66
        // credit memo is not reported as +$19.32 off simply because the signs differ.
        if ($isCreditMemoTarget) {
            $expected = round(abs($expectedRaw), 2);
            $book = round(abs($bookRaw), 2);
            $r['expected_invoice_total'] = $expected;
            $r['book_invoice_total'] = $book;
            $r['invoice_delta'] = round($book - $expected, 2);
        } else {
            $expected = $expectedRaw;
            $book = $bookRaw;
        }
        $delta = round($book - $expected, 2);
        if (abs($delta) < 0.01) continue;
        if (abs($expected) < 0.005 && abs($book) < 0.005) continue;
        $methods = strtolower((string)($r['payment_methods'] ?? ''));
        $hasStoredValue = str_contains($methods, 'gift') || str_contains($methods, 'visa points') || str_contains($methods, 'reward') || str_contains($methods, 'cash-back') || str_contains($methods, 'cash back');
        $basis = (string)($r['expected_invoice_basis'] ?? '');
        $issue = 'invoice_total_mismatch';
        $recommend = 'Review the posted GnuCash invoice total against the Amazon order detail and transaction-history payment total.';
        if ($delta < -0.009 && $hasStoredValue) {
            $issue = 'possible_stored_value_imported_as_discount';
            $recommend = 'Book invoice is lower than the Amazon payment total and stored-value/rewards/gift-card payment is present. Look for a negative DISCOUNT/coupon line equal to the delta and remove/repost it if it is actually a payment source.';
        } elseif ($delta < -0.009) {
            $issue = 'invoice_understated_review';
            $recommend = 'Book invoice is lower than expected. Check for missing item, tax, shipping, fee, or a merchant discount that was too large.';
        } elseif ($delta > 0.009) {
            $issue = 'invoice_overstated_review';
            $recommend = 'Book invoice is higher than expected. Check for missing coupon/discount, duplicated fee/item line, or refund/credit treatment.';
        }
        $r['sanity_status'] = $issue;
        $r['sanity_recommended_action'] = $recommend;
        $r['stored_value_or_discount_delta'] = abs($delta);
        $r['is_credit_memo'] = $isCreditMemoTarget ? 'yes' : 'no';
        $out[] = $r;
    }
    $dir = normalize_date_sort($dateSort) === 'asc' ? 'ASC' : 'DESC';
    usort($out, function($a,$b) use($dir){
        $ad=(string)($a['order_date'] ?: strtok((string)($a['payment_dates'] ?? ''), ';') ?: '9999-99-99');
        $bd=(string)($b['order_date'] ?: strtok((string)($b['payment_dates'] ?? ''), ';') ?: '9999-99-99');
        $cmp=strcmp($ad,$bd); if($cmp===0) $cmp=strcmp((string)$a['target_id'],(string)$b['target_id']);
        return $dir==='ASC' ? $cmp : -$cmp;
    });
    return $out;
}
function invoice_sanity_status_counts(array $rows): array {
    $c=[]; foreach($rows as $r){$s=(string)($r['sanity_status'] ?? 'unknown'); $c[$s]=($c[$s]??0)+1;} ksort($c); return $c;
}
function export_invoice_sanity_report(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath='', string $dateSort='asc'): int {
    $rows = invoice_sanity_check_rows($db, $vendor, $gnucashPath, $dateSort);
    $fh = fopen($outPath, 'w'); if(!$fh) return 0;
    fputcsv($fh, ['target_id','order_date','payment_dates','sanity_status','is_credit_memo','expected_invoice_total','expected_invoice_basis','book_invoice_total','invoice_delta','payment_methods','book_payment_accounts','book_invoice_guid','recommended_action','book_notes','warning']);
    foreach($rows as $r) fputcsv($fh, [$r['target_id'],$r['order_date'],$r['payment_dates'],$r['sanity_status'],$r['is_credit_memo'],fmt_money((float)$r['expected_invoice_total']),(string)($r['expected_invoice_basis'] ?? ''),fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['invoice_delta']),$r['payment_methods'],$r['book_payment_accounts'],$r['book_invoice_guid'],$r['sanity_recommended_action'],$r['book_notes'],dedupe_warning_text((string)$r['warning'])]);
    fclose($fh); return count($rows);
}


function parse_order_id_filter_text(string $text): array {
    $text = str_replace(["\r", "\t", ',', ';'], "\n", $text);
    $ids = [];
    foreach (preg_split('/\s+/', $text) ?: [] as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        if (preg_match('/^(?:[0-9]{3}-[0-9]{7}-[0-9]{7}|D[0-9A-Z]{2}-[0-9]{7}-[0-9]{7})(?:-CREDIT)?$/i', $tok)) {
            $ids[strtoupper((string)$tok)] = true;
        }
    }
    return array_keys($ids);
}
function parse_order_id_filter_from_request(array $request, string $fileField = 'repair_order_ids_file'): array {
    $ids = parse_order_id_filter_text((string)($request['repair_order_ids'] ?? ''));
    if (isset($_FILES[$fileField]) && is_uploaded_file($_FILES[$fileField]['tmp_name'])) {
        $txt = (string)@file_get_contents($_FILES[$fileField]['tmp_name']);
        $ids = array_values(array_unique(array_merge($ids, parse_order_id_filter_text($txt))));
    }
    return $ids;
}
function date_gte_or_blank(string $date, string $fromDate): bool {
    $date = normalize_import_date($date); $fromDate = normalize_import_date($fromDate);
    if ($fromDate === '') return true;
    if ($date === '') return true;
    return strcmp($date, $fromDate) >= 0;
}
function date_lte_or_blank(string $date, string $toDate): bool {
    $date = normalize_import_date($date); $toDate = normalize_import_date($toDate);
    if ($toDate === '') return true;
    if ($date === '') return true;
    return strcmp($date, $toDate) <= 0;
}
function date_in_range_or_blank(string $date, string $fromDate, string $toDate): bool {
    return date_gte_or_blank($date, $fromDate) && date_lte_or_blank($date, $toDate);
}
function is_amazon_stored_value_method(string $method): bool {
    $m = strtolower($method);
    return str_contains($m, 'gift') || str_contains($m, 'visa points') || str_contains($m, 'reward') || str_contains($m, 'cash-back') || str_contains($m, 'cash back');
}
function amazon_stored_value_payment_total_for_order(SQLite3 $db, string $orderId): float {
    $stmt = $db->prepare("SELECT payment_method, amount FROM vendor_payments WHERE vendor='amazon' AND order_id=:oid AND amount < -0.00001 AND match_status<>'excluded_payment_method'");
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute(); $sum = 0.0;
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        if (is_amazon_stored_value_method((string)($r['payment_method'] ?? ''))) $sum = round($sum + abs((float)($r['amount'] ?? 0.0)), 2);
    }
    return $sum;
}
function gnc_numeric_expr(string $numCol, string $denCol): string {
    return 'CASE WHEN ' . $denCol . ' IS NULL OR ' . $denCol . '=0 THEN 0 ELSE CAST(' . $numCol . ' AS REAL)/CAST(' . $denCol . ' AS REAL) END';
}
function gnucash_invoice_entries_for_guids(string $path, array $invoiceGuids): array {
    $resolved = resolve_local_path($path);
    if ($resolved === '' || !file_exists($resolved) || !$invoiceGuids) return [];
    $out = [];
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $acctMap = gnucash_account_fullname_map_sqlite($gdb);
        [$ph, $params] = sqlite_placeholders($invoiceGuids, 'ig');
        $qty = gnc_numeric_expr('e.quantity_num', 'e.quantity_denom');
        $bprice = gnc_numeric_expr('e.b_price_num', 'e.b_price_denom');
        $iprice = gnc_numeric_expr('e.i_price_num', 'e.i_price_denom');
        $sql = 'SELECT e.guid, e.bill, e.invoice, e.description, e.action, e.notes, e.b_acct, e.i_acct, ' . $qty . ' AS qty, ' . $bprice . ' AS b_price, ' . $iprice . ' AS i_price FROM entries e WHERE e.bill IN (' . implode(',', $ph) . ') OR e.invoice IN (' . implode(',', $ph) . ')';
        $stmt = $gdb->prepare($sql);
        sqlite_bind_all($stmt, $params);
        $res = $stmt->execute();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $ig = (string)($r['bill'] ?: $r['invoice']);
            $qtyv = (float)($r['qty'] ?? 0.0); if (abs($qtyv) < 0.00001) $qtyv = 1.0;
            $price = abs((float)($r['b_price'] ?? 0.0)) > 0.00001 ? (float)$r['b_price'] : (float)($r['i_price'] ?? 0.0);
            $acctGuid = (string)($r['b_acct'] ?: $r['i_acct']);
            $out[$ig][] = [
                'entry_guid'=>(string)($r['guid'] ?? ''),
                'description'=>(string)($r['description'] ?? ''),
                'action'=>(string)($r['action'] ?? ''),
                'notes'=>(string)($r['notes'] ?? ''),
                'account'=>$acctMap[$acctGuid]['name'] ?? '',
                'quantity'=>$qtyv,
                'unit_price'=>$price,
                'amount'=>round($qtyv * $price, 2),
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

function amazon_repair_entry_similarity_key(string $description): string {
    $d = strtolower($description);
    $d = preg_replace('/\[[^\]]+\]/', ' ', $d);
    $d = preg_replace('/[^a-z0-9]+/', ' ', $d);
    $d = trim(preg_replace('/\s+/', ' ', $d) ?? '');
    return $d;
}
function amazon_repair_book_has_similar_line(array $bookEntries, float $amount, string $description): bool {
    $want = amazon_repair_entry_similarity_key($description);
    foreach ($bookEntries as $e) {
        $amt = round((float)($e['amount'] ?? 0.0), 2);
        if (abs($amt - $amount) > 0.01) continue;
        $have = amazon_repair_entry_similarity_key((string)($e['description'] ?? ''));
        if ($want !== '' && $have !== '' && (str_contains($have, substr($want, 0, min(24, strlen($want)))) || str_contains($want, substr($have, 0, min(24, strlen($have)))))) return true;
        if ($description === '' || $have === '') return true;
    }
    return false;
}
function amazon_repair_book_shipping_account_for_delta(array $bookEntries, float $absAmount): string {
    foreach ($bookEntries as $e) {
        $desc = strtolower((string)($e['description'] ?? ''));
        $acct = (string)($e['account'] ?? '');
        $amt = round(abs((float)($e['amount'] ?? 0.0)), 2);
        if ($acct !== '' && (str_contains($desc, 'shipping') || str_contains(strtolower($acct), 'shipping')) && abs($amt - $absAmount) <= 0.01) return $acct;
    }
    foreach ($bookEntries as $e) {
        $desc = strtolower((string)($e['description'] ?? ''));
        $acct = (string)($e['account'] ?? '');
        if ($acct !== '' && (str_contains($desc, 'shipping') || str_contains(strtolower($acct), 'shipping'))) return $acct;
    }
    return default_config_value('DEFAULT_SHIPPING_ACCOUNT');
}
function amazon_repair_best_adjustment_account(array $bookEntries, float $absAmount): string {
    $best = '';
    $bestAmt = -1.0;
    foreach ($bookEntries as $e) {
        $acct = (string)($e['account'] ?? '');
        if ($acct === '') continue;
        $desc = strtolower((string)($e['description'] ?? ''));
        if (str_contains($desc, 'sales tax') || str_contains($desc, 'tax')) continue;
        if (str_contains($desc, 'shipping')) continue;
        if (str_contains($desc, 'discount') || str_contains($desc, 'coupon') || str_contains($desc, 'promotion')) continue;
        $amt = round(abs((float)($e['amount'] ?? 0.0)), 2);
        if ($amt <= 0.005) continue;
        // Exact same-amount match is strongest; otherwise fall back to the largest
        // normal item account so the order-level Amazon coupon reduces an expense
        // account that already exists in the book.
        if (abs($amt - $absAmount) <= 0.01) return $acct;
        if ($amt > $bestAmt) { $bestAmt = $amt; $best = $acct; }
    }
    if ($best !== '') return $best;
    foreach ($bookEntries as $e) {
        $acct = (string)($e['account'] ?? '');
        if ($acct !== '') return $acct;
    }
    return 'Expenses:Uncategorized';
}
function amazon_repair_inferred_order_adjustment_candidate(SQLite3 $db, string $orderId, float $delta, array $bookEntries): array {
    // Fallback repair mode: Amazon transaction/order totals are the amount actually billed.
    // If the posted, unpaid GnuCash bill differs from that total and no explicit staged
    // missing line explains it, add one order-level reconciliation line for exactly the
    // book-vs-Amazon delta.  Negative deltas are merchant coupons/promotions; positive
    // deltas are missing order charges.  The apply script still checks the current book
    // total, posted state, unpaid state, and final transaction balance before writing.
    $delta = round($delta, 2);
    if (abs($delta) < 0.005) return [];
    $abs = round(abs($delta), 2);
    $orderId = trim($orderId);
    $notes = 'Inferred from Amazon transaction/order total as source of truth; repair amount equals expected invoice total minus current GnuCash invoice total.';

    // If the amount looks like an omitted shipping offset/charge, keep it in Shipping.
    $looksLikeShipping = false;
    $ost = $db->prepare("SELECT shipping, shipping_refund, shipping_account FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
    $ost->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $o = $ost->execute()->fetchArray(SQLITE3_ASSOC);
    if ($o) {
        $ship = round(abs((float)($o['shipping'] ?? 0.0)), 2);
        $shipRefund = round(abs((float)($o['shipping_refund'] ?? 0.0)), 2);
        if (($ship > 0.005 && abs($ship - $abs) <= 0.01) || ($shipRefund > 0.005 && abs($shipRefund - $abs) <= 0.01)) $looksLikeShipping = true;
    }
    foreach ($bookEntries as $e) {
        $desc = strtolower((string)($e['description'] ?? ''));
        $amt = round(abs((float)($e['amount'] ?? 0.0)), 2);
        if ((str_contains($desc, 'shipping') || str_contains(strtolower((string)($e['account'] ?? '')), 'shipping')) && abs($amt - $abs) <= 0.01) $looksLikeShipping = true;
    }

    if ($delta < -0.005) {
        if ($looksLikeShipping) {
            return [
                'action'=>'add_missing_inferred_shipping_promotion_line',
                'amount'=>$delta,
                'description'=>'Free Shipping / Shipping promotion',
                'account'=>amazon_repair_book_shipping_account_for_delta($bookEntries, $abs),
                'source'=>'inferred_from_amazon_payment_total',
                'notes'=>$notes . ' Classified as shipping promotion because the delta matches an existing/order shipping amount.',
            ];
        }
        return [
            'action'=>'add_missing_inferred_promotion_line',
            'amount'=>$delta,
            'description'=>'[DISCOUNT:AMAZON] Amazon coupon / order adjustment',
            'account'=>amazon_repair_best_adjustment_account($bookEntries, $abs),
            'source'=>'inferred_from_amazon_payment_total',
            'notes'=>$notes . ' Classified as Amazon coupon/promotion because GnuCash bill is higher than the Amazon charged/payment total.',
        ];
    }

    if ($looksLikeShipping) {
        return [
            'action'=>'add_missing_inferred_shipping_charge_line',
            'amount'=>$delta,
            'description'=>'Shipping & Handling',
            'account'=>amazon_repair_book_shipping_account_for_delta($bookEntries, $abs),
            'source'=>'inferred_from_amazon_payment_total',
            'notes'=>$notes . ' Classified as missing shipping/upcharge because the delta matches an existing/order shipping amount.',
        ];
    }
    return [
        'action'=>'add_missing_inferred_order_charge_line',
        'amount'=>$delta,
        'description'=>'[ADJUSTMENT:AMAZON] Amazon order adjustment / unimported charge',
        'account'=>amazon_repair_best_adjustment_account($bookEntries, $abs),
        'source'=>'inferred_from_amazon_payment_total',
        'notes'=>$notes . ' Classified as missing positive order adjustment because Amazon charged more than the current GnuCash bill total.',
    ];
}

function amazon_repair_credit_memo_overstatement_candidate(SQLite3 $db, string $creditId, float $delta, array $bookEntries): array {
    // Credit memo repair mode: Amazon return credits should equal Amazon's Refund Total,
    // not necessarily the original order total.  Older staged/imported credit invoices can
    // be overstated when they contain the full returned item/order subtotal but Amazon later
    // withholds part of the refund for return shipping, points/rewards fees, partial returns,
    // or non-refunded portions of the order.  Add one negative adjustment line so the posted
    // credit memo total equals the Amazon Refund Total currently staged for ORDER-CREDIT.
    $delta = round($delta, 2); // expected credit total - current book credit total.
    if (!str_ends_with($creditId, '-CREDIT') || $delta >= -0.005) return [];
    $abs = round(abs($delta), 2);
    $base = preg_replace('/-CREDIT$/', '', $creditId);
    $acct = amazon_repair_best_adjustment_account($bookEntries, $abs);
    if ($acct === '' || is_placeholder_account($acct)) {
        $stmt = $db->prepare("SELECT expense_account, items FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
        $stmt->bindValue(':oid', $base, SQLITE3_TEXT);
        $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($o) $acct = trim((string)($o['expense_account'] ?? '')) ?: default_account_for_items((string)($o['items'] ?? ''));
    }
    if ($acct === '' || is_placeholder_account($acct)) $acct = 'Expenses:Uncategorized';
    return [
        'action'=>'add_missing_credit_refund_adjustment_line',
        'amount'=>$delta,
        'description'=>'[CREDIT:AMAZON] Adjustment to actual Amazon refund total',
        'account'=>$acct,
        'source'=>'inferred_from_amazon_refund_total',
        'notes'=>'Credit memo total is higher than Amazon Refund Total for ' . $base . '. Add this negative line to reduce the posted credit memo to the actual refund amount. This commonly represents return shipping withheld, rewards/points fee, partial return, or non-refunded part of the order.',
    ];
}

function amazon_repair_order_missing_line_candidate(SQLite3 $db, string $orderId, float $delta, array $bookEntries): array {
    // delta is expected_invoice_total - current_book_invoice_total.
    // Positive delta means add a positive charge line. Negative delta means add a negative
    // promotion/free-shipping line. Prefer explicit staged source lines; if none exist,
    // v113 can infer one order-level adjustment from the Amazon charged/payment total.
    $orderId = trim($orderId);
    $delta = round($delta, 2);
    if ($orderId === '' || abs($delta) < 0.005) return [];
    $candidates = [];

    // Staged generated reconciliation lines are the strongest evidence because they came
    // from the current Amazon order/item exports: promotion applied, gift wrap/service charge,
    // inferred duplicate/missing item, or order-level fee.
    $stmt = $db->prepare("SELECT item_key, description, expense_account, quantity, unit_price, source_amount, notes, match_reason FROM order_items WHERE vendor='amazon' AND order_id=:oid AND COALESCE(skip,0)=0 AND (item_key='AMAZON-RECONCILE' OR item_key LIKE 'AMAZON-INFERRED-%' OR item_key='AMAZON-FEE') ORDER BY item_key");
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($res && ($it = $res->fetchArray(SQLITE3_ASSOC))) {
        $amount = isset($it['source_amount']) && $it['source_amount'] !== null ? round((float)$it['source_amount'], 2) : round((float)($it['quantity'] ?? 1) * (float)($it['unit_price'] ?? 0), 2);
        if (abs($amount - $delta) > 0.01) continue;
        $desc = (string)($it['description'] ?? 'Amazon adjustment');
        if (amazon_repair_book_has_similar_line($bookEntries, $amount, $desc)) continue;
        $key = (string)($it['item_key'] ?? '');
        $action = 'add_missing_invoice_line';
        if ($key === 'AMAZON-RECONCILE' && $amount < -0.00001) $action = 'add_missing_promotion_line';
        elseif ($key === 'AMAZON-RECONCILE' && $amount > 0.00001 && str_contains(strtolower($desc), 'gift')) $action = 'add_missing_gift_wrap_line';
        elseif (str_starts_with($key, 'AMAZON-INFERRED-')) $action = 'add_missing_duplicate_item_line';
        elseif ($key === 'AMAZON-FEE') $action = 'add_missing_fee_line';
        $candidates[] = [
            'action'=>$action,
            'amount'=>$amount,
            'description'=>$desc,
            'account'=>(string)($it['expense_account'] ?? ''),
            'source'=>$key,
            'notes'=>'Auto-repair candidate from staged Amazon reconciliation line. ' . (string)($it['match_reason'] ?? '') . ' ' . (string)($it['notes'] ?? ''),
        ];
    }

    // Shipping promotions are exported from the order header rather than order_items.
    // If the old bill has the positive shipping charge but is missing the matching
    // Free Shipping line, the expected-book delta will equal -shipping_refund.
    $ost = $db->prepare("SELECT shipping_refund, shipping_account, shipping FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
    $ost->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $o = $ost->execute()->fetchArray(SQLITE3_ASSOC);
    if ($o) {
        $shipRefund = round(abs((float)($o['shipping_refund'] ?? 0.0)), 2);
        if ($shipRefund > 0.005 && abs($delta + $shipRefund) <= 0.01) {
            $desc = 'Free Shipping / Shipping promotion';
            $amount = -$shipRefund;
            if (!amazon_repair_book_has_similar_line($bookEntries, $amount, $desc)) {
                $candidates[] = [
                    'action'=>'add_missing_shipping_promotion_line',
                    'amount'=>$amount,
                    'description'=>$desc,
                    'account'=>(string)($o['shipping_account'] ?? default_config_value('DEFAULT_SHIPPING_ACCOUNT')) ?: default_config_value('DEFAULT_SHIPPING_ACCOUNT'),
                    'source'=>'orders.shipping_refund',
                    'notes'=>'Auto-repair candidate from Amazon order-level Free Shipping / shipping promotion amount.',
                ];
            }
        }
    }

    // Deduplicate exact duplicate candidate rows.
    $uniq = [];
    foreach ($candidates as $c) {
        $k = $c['action'].'|'.fmt_money((float)$c['amount']).'|'.$c['description'].'|'.$c['account'];
        $uniq[$k] = $c;
    }
    $candidates = array_values($uniq);
    if (count($candidates) === 1) return $candidates[0];
    if (count($candidates) > 1) return ['ambiguous'=>true, 'count'=>count($candidates), 'notes'=>'Multiple staged missing-line candidates matched the same invoice delta; manual review required.'];

    // v113 fallback: when Amazon's transaction/order total is trusted and no explicit
    // staged line exists, infer exactly one order-level adjustment equal to the delta.
    // This is what captures Amazon page rows such as "Your Coupon Savings" / "Promotion
    // Applied" that are not present in the browser extension's raw CSV fields.
    return amazon_repair_inferred_order_adjustment_candidate($db, $orderId, $delta, $bookEntries);
}

function amazon_invoice_repair_plan_rows(SQLite3 $db, string $gnucashPath, string $fromDate = '2025-01-01', string $toDate = '', array $orderIds = [], bool $includePaid = false, string $dateSort = 'asc'): array {
    $filter = [];
    foreach ($orderIds as $id) $filter[strtoupper(trim($id))] = true;
    $sanity = invoice_sanity_check_rows($db, 'amazon', $gnucashPath, $dateSort);
    $targets = [];
    foreach ($sanity as $r) {
        $id = (string)($r['target_id'] ?? '');
        if ($id === '') continue;
        if ($filter) {
            $idKey = strtoupper((string)$id);
            $baseKey = strtoupper(preg_replace('/-CREDIT$/', '', $id));
            if (!isset($filter[$idKey]) && !isset($filter[$baseKey])) continue;
        }
        if (!date_in_range_or_blank((string)($r['order_date'] ?? ''), $fromDate, $toDate)) continue;
        $st = (string)($r['sanity_status'] ?? '');
        if (!in_array($st, ['possible_stored_value_imported_as_discount','invoice_understated_review','invoice_overstated_review'], true)) continue;
        $targets[$id] = $r;
    }
    $states = load_gnucash_invoice_states_for_payments($gnucashPath, array_keys($targets));
    $guidToTarget = [];
    foreach ($targets as $id => $r) {
        $guid = (string)($states[$id]['invoice_guid'] ?? $r['book_invoice_guid'] ?? '');
        if ($guid !== '') $guidToTarget[$guid] = $id;
    }
    $entriesByGuid = gnucash_invoice_entries_for_guids($gnucashPath, array_keys($guidToTarget));
    $rows = [];
    foreach ($targets as $id => $r) {
        $state = $states[$id] ?? invoice_empty_state($id);
        $guid = (string)($state['invoice_guid'] ?? $r['book_invoice_guid'] ?? '');
        $expected = round((float)($r['expected_invoice_total'] ?? 0.0), 2);
        $book = round((float)($r['book_invoice_total'] ?? 0.0), 2);
        $missing = round($expected - $book, 2);
        $stored = amazon_stored_value_payment_total_for_order($db, $id);
        $entries = $guid !== '' ? ($entriesByGuid[$guid] ?? []) : [];
        $disc = [];
        foreach ($entries as $e) {
            $d = strtolower((string)($e['description'] ?? ''));
            $amt = round((float)($e['amount'] ?? 0.0), 2);
            if ($amt < -0.00001 && (str_contains($d, '[discount:amazon]') || str_contains($d, 'amazon promotion') || str_contains($d, 'coupon'))) $disc[] = $e;
        }
        $discSum = round(array_sum(array_map(fn($e)=>round((float)$e['amount'],2), $disc)), 2);
        // Suppress grouped rewards/points false positives: if there is no negative
        // candidate discount and the posted bill already matches the per-order staged
        // expected amount, do not emit a repair row merely because a combined payment
        // transaction was seen for multiple Amazon order IDs.
        $stageExpected = null;
        $stageStmt = $db->prepare("SELECT * FROM orders WHERE vendor='amazon' AND order_id=:oid LIMIT 1");
        $stageStmt->bindValue(':oid', $id, SQLITE3_TEXT);
        $stageRow = $stageStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($stageRow) $stageExpected = round(order_bill_total_for_validation($stageRow), 2);
        if (!$disc && $stageExpected !== null && abs($book - $stageExpected) < 0.01) {
            continue;
        }
        $paid = !empty($state['paid']);
        $action = 'manual_review'; $safe = 'no'; $reason = '';
        $candidate = $disc[0] ?? [];
        $newCandidateAmount = '';
        $newLine = [];
        $isCreditTarget = str_ends_with($id, '-CREDIT');

        // First, preserve the proven v106 repair path for accidentally imported
        // stored-value/rewards payments that became negative discount entries.
        if ($paid && !$includePaid) {
            $action = 'paid_invoice_manual_review'; $reason = 'Invoice is already paid; do not auto-repair in this pass.';
        } elseif ($isCreditTarget && $missing < -0.005) {
            $newLine = amazon_repair_credit_memo_overstatement_candidate($db, $id, $missing, $entries);
            if ($newLine) {
                $action = (string)$newLine['action'];
                $safe = 'yes';
                $reason = 'Amazon CREDIT invoice is higher than the staged Refund Total; add one negative adjustment line so the credit memo equals the actual Amazon refund total.';
            } else {
                $action = 'manual_review_credit_memo_overstated';
                $reason = 'Credit memo is higher than expected, but no safe credit-memo adjustment candidate was identified.';
            }
        } elseif ($isCreditTarget && $missing > 0.005) {
            $action = 'manual_review_credit_memo_understated';
            $reason = 'Credit memo is lower than Amazon Refund Total; review manually before adding a positive credit line.';
        } elseif ($missing > 0.005 && $stored > 0.005 && $disc && count($disc) === 1) {
            $discAbs = abs($discSum);
            if (abs($discAbs - $missing) < 0.01) {
                $action = 'remove_discount_entry'; $safe = 'yes'; $reason = 'One negative discount entry equals the missing stored-value component.';
            } elseif ($discAbs > $missing + 0.005) {
                $action = 'reduce_discount_entry_by_missing_amount'; $safe = 'yes'; $reason = 'One discount entry appears to combine a real coupon with stored-value payment; reduce discount magnitude by the missing amount.';
            } else {
                $action = 'manual_review_discount_smaller_than_delta'; $reason = 'Candidate discount entry is smaller than the missing amount.';
            }
        } elseif ($missing > 0.005 && $stored > 0.005 && count($disc) > 1) {
            $action = 'manual_review_multiple_discount_entries'; $reason = 'Multiple possible discount/coupon entries found; keep this for manual review.';
        } else {
            // New v110 path: a posted unpaid bill is missing an entire line that the
            // current staged Amazon data can identify (Promotion Applied, Free Shipping,
            // Gift Wrap, duplicate/missing item, or small fee). The plan records the new
            // entry and the apply script inserts it plus a posted split while adjusting A/P.
            $newLine = amazon_repair_order_missing_line_candidate($db, $id, $missing, $entries);
            if ($newLine && empty($newLine['ambiguous'])) {
                $action = (string)$newLine['action'];
                $safe = 'yes';
                $reason = str_contains((string)($newLine['source'] ?? ''), 'inferred_from_amazon_payment_total')
                    ? 'Amazon transaction/order total is treated as the source of truth; inferred exactly one order-level adjustment equal to the book-vs-Amazon delta.'
                    : 'Staged Amazon data has exactly one missing invoice line matching the current book-vs-expected delta.';
            } elseif (!empty($newLine['ambiguous'])) {
                $action = 'manual_review_multiple_missing_line_candidates';
                $reason = (string)($newLine['notes'] ?? 'Multiple missing-line candidates matched; manual review required.');
            } elseif ($missing <= -0.005) {
                $action = 'manual_review_no_missing_negative_line_candidate';
                $reason = 'Book invoice is higher than expected, but no single staged promotion/free-shipping line matched the delta.';
            } elseif ($missing > 0.005) {
                if ($stored <= 0.005) {
                    $action = 'manual_review_no_stored_value_payment_or_missing_line_candidate';
                    $reason = 'Book invoice is lower than expected, but there is no stored-value repair candidate and no single staged missing-line candidate.';
                } elseif (!$disc) {
                    $action = 'manual_review_no_candidate_discount_entry';
                    $reason = 'No negative Amazon discount/coupon entry was found and no staged missing-line candidate matched.';
                } else {
                    $action = 'manual_review_missing_line_unclassified';
                    $reason = 'Invoice differs from staged expected total but no safe automatic missing-line repair was identified.';
                }
            } else {
                $action = 'no_invoice_delta_to_repair';
                $reason = 'Expected and GnuCash invoice totals already match within cents tolerance.';
            }
        }
        if ($safe === 'yes' && $candidate && in_array($action, ['remove_discount_entry','reduce_discount_entry_by_missing_amount'], true)) {
            $old = round((float)$candidate['amount'], 2);
            if ($action === 'remove_discount_entry') $newCandidateAmount = '0.00';
            elseif ($action === 'reduce_discount_entry_by_missing_amount') $newCandidateAmount = fmt_money(round($old + $missing, 2));
        }
        $rows[] = [
            'target_id'=>$id,
            'order_date'=>(string)($r['order_date'] ?? ''),
            'sanity_status'=>(string)($r['sanity_status'] ?? ''),
            'gnucash_invoice_guid'=>$guid,
            'book_paid'=>$paid ? 'yes' : 'no',
            'expected_invoice_total'=>$expected,
            'book_invoice_total'=>$book,
            'missing_amount'=>$missing,
            'stored_value_payment_total'=>$stored,
            'candidate_discount_entry_count'=>count($disc),
            'candidate_discount_entry_guids'=>implode('; ', array_map(fn($e)=>(string)($e['entry_guid'] ?? ''), $disc)),
            'candidate_discount_amount_sum'=>$discSum,
            'candidate_discount_description'=>(string)($candidate['description'] ?? ''),
            'candidate_discount_account'=>(string)($candidate['account'] ?? ''),
            'suggested_action'=>$action,
            'safe_for_auto_repair'=>$safe,
            'new_candidate_discount_amount'=>$newCandidateAmount,
            'new_entry_amount'=>isset($newLine['amount']) ? fmt_money((float)$newLine['amount']) : '',
            'new_entry_description'=>(string)($newLine['description'] ?? ''),
            'new_entry_account'=>(string)($newLine['account'] ?? ''),
            'new_entry_action'=>'',
            'new_entry_notes'=>(string)($newLine['notes'] ?? ''),
            'new_entry_source'=>(string)($newLine['source'] ?? ''),
            'reason'=>$reason,
        ];
    }
    $dir = normalize_date_sort($dateSort) === 'asc' ? 'ASC' : 'DESC';
    usort($rows, function($a,$b) use($dir){ $cmp=strcmp((string)$a['order_date'], (string)$b['order_date']); if($cmp===0)$cmp=strcmp((string)$a['target_id'], (string)$b['target_id']); return $dir==='ASC'?$cmp:-$cmp; });
    return $rows;
}
function export_amazon_invoice_repair_plan(SQLite3 $db, string $outPath, string $gnucashPath, string $fromDate = '2025-01-01', string $toDate = '', array $orderIds = [], bool $includePaid = false, string $dateSort = 'asc'): int {
    $rows = amazon_invoice_repair_plan_rows($db, $gnucashPath, $fromDate, $toDate, $orderIds, $includePaid, $dateSort);
    $fh = fopen($outPath, 'w'); if(!$fh) return 0;
    fputcsv($fh, ['target_id','order_date','sanity_status','gnucash_invoice_guid','book_paid','expected_invoice_total','book_invoice_total','missing_amount','stored_value_payment_total','candidate_discount_entry_count','candidate_discount_entry_guids','candidate_discount_amount_sum','candidate_discount_description','candidate_discount_account','suggested_action','safe_for_auto_repair','new_candidate_discount_amount','new_entry_amount','new_entry_description','new_entry_account','new_entry_action','new_entry_notes','new_entry_source','reason']);
    foreach ($rows as $r) fputcsv($fh, [$r['target_id'],$r['order_date'],$r['sanity_status'],$r['gnucash_invoice_guid'],$r['book_paid'],fmt_money((float)$r['expected_invoice_total']),fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['missing_amount']),fmt_money((float)$r['stored_value_payment_total']),$r['candidate_discount_entry_count'],$r['candidate_discount_entry_guids'],fmt_money((float)$r['candidate_discount_amount_sum']),$r['candidate_discount_description'],$r['candidate_discount_account'],$r['suggested_action'],$r['safe_for_auto_repair'],$r['new_candidate_discount_amount'],(string)($r['new_entry_amount'] ?? ''),(string)($r['new_entry_description'] ?? ''),(string)($r['new_entry_account'] ?? ''),(string)($r['new_entry_action'] ?? ''),(string)($r['new_entry_notes'] ?? ''),(string)($r['new_entry_source'] ?? ''),$r['reason']]);
    fclose($fh); return count($rows);
}

function amazon_invoice_repair_dryrun_rows(SQLite3 $db, string $gnucashPath, string $fromDate = '2025-01-01', string $toDate = '', array $orderIds = [], bool $includePaid = false, string $dateSort = 'asc'): array {
    $planRows = amazon_invoice_repair_plan_rows($db, $gnucashPath, $fromDate, $toDate, $orderIds, $includePaid, $dateSort);
    $ids = [];
    foreach ($planRows as $r) {
        $id = trim((string)($r['target_id'] ?? ''));
        if ($id !== '') $ids[$id] = true;
    }
    $states = $ids ? load_gnucash_invoice_states_for_payments($gnucashPath, array_keys($ids)) : [];
    $out = [];
    $line = 2;
    foreach ($planRows as $r) {
        $id = trim((string)($r['target_id'] ?? ''));
        $st = $states[$id] ?? invoice_empty_state($id);
        $actualPaid = !empty($st['paid']);
        $planPaid = (string)($r['book_paid'] ?? 'no');
        $safe = (string)($r['safe_for_auto_repair'] ?? 'no');
        $status = 'SKIP_NOT_SAFE';
        $notes = [];
        if (!empty($st['malformed_invoice_id'])) $notes[] = 'WARNING: exact GnuCash invoice ID differs; actual ID [' . (string)$st['malformed_invoice_id'] . ']';
        if ($planPaid !== ($actualPaid ? 'yes' : 'no')) $notes[] = 'WARNING: plan book_paid=' . $planPaid . '; actual_book_paid=' . ($actualPaid ? 'yes' : 'no') . '. Continuing based on current GnuCash book.';
        $notes[] = 'Invoice ID: ' . $id . '; GUID: ' . (string)($st['invoice_guid'] ?? $r['gnucash_invoice_guid'] ?? '');
        $notes[] = 'post_lot=' . (string)($st['post_lot'] ?? '') . '; lot_balance=' . fmt_money((float)($st['lot_balance'] ?? 0.0));
        $notes[] = 'Current book total: ' . fmt_money((float)($r['book_invoice_total'] ?? 0.0)) . '; expected: ' . fmt_money((float)($r['expected_invoice_total'] ?? 0.0)) . '; missing amount: ' . fmt_money((float)($r['missing_amount'] ?? 0.0)) . '.';
        $notes[] = 'Candidate discount amount: ' . fmt_money((float)($r['candidate_discount_amount_sum'] ?? 0.0)) . '; planned new discount amount: ' . (string)($r['new_candidate_discount_amount'] ?? '') . '.';
        if (empty($st['found'])) {
            $status = 'ERROR_INVOICE_NOT_FOUND';
            $notes[] = 'No matching GnuCash invoice found for this ID in the selected book.';
        } elseif (empty($st['posted'])) {
            $status = 'SKIP_UNPOSTED_INVOICE';
            $notes[] = 'Invoice exists but is not posted; post or repair manually first.';
        } elseif ($actualPaid) {
            $status = 'SKIP_PAID_INVOICE';
            $notes[] = 'Invoice is paid in the current GnuCash book; do not auto-repair in this pass.';
        } elseif ($safe === 'yes') {
            $status = 'READY_DRYRUN_ONLY';
            $action = (string)($r['suggested_action'] ?? '');
            if ($action === 'remove_discount_entry') $notes[] = 'Repair action would remove/zero the negative discount entry.';
            elseif ($action === 'reduce_discount_entry_by_missing_amount') $notes[] = 'Repair action would reduce the negative discount entry to the planned new amount.';
            elseif (str_starts_with($action, 'add_missing_')) $notes[] = 'Repair action would add invoice entry: ' . (string)($r['new_entry_description'] ?? '') . ' amount ' . (string)($r['new_entry_amount'] ?? '') . ' account ' . (string)($r['new_entry_account'] ?? '') . '.';
            else $notes[] = 'Repair action appears safe but should still be applied only to a copied book.';
        } else {
            $status = 'SKIP_NOT_SAFE';
            $notes[] = 'Plan safe_for_auto_repair=' . $safe . '; not a safe apply candidate. Reason: ' . (string)($r['reason'] ?? '');
        }
        $out[] = [
            'line_no'=>$line++,
            'target_id'=>$id,
            'order_date'=>(string)($r['order_date'] ?? ''),
            'status'=>$status,
            'plan_book_paid'=>$planPaid,
            'actual_book_paid'=>$actualPaid ? 'yes' : 'no',
            'safe_for_auto_repair'=>$safe,
            'book_invoice_total'=>round((float)($r['book_invoice_total'] ?? 0.0), 2),
            'expected_invoice_total'=>round((float)($r['expected_invoice_total'] ?? 0.0), 2),
            'missing_amount'=>round((float)($r['missing_amount'] ?? 0.0), 2),
            'current_discount_amount'=>round((float)($r['candidate_discount_amount_sum'] ?? 0.0), 2),
            'planned_new_discount_amount'=>(string)($r['new_candidate_discount_amount'] ?? ''),
            'new_entry_amount'=>(string)($r['new_entry_amount'] ?? ''),
            'new_entry_description'=>(string)($r['new_entry_description'] ?? ''),
            'new_entry_account'=>(string)($r['new_entry_account'] ?? ''),
            'candidate_entry_guids'=>(string)($r['candidate_discount_entry_guids'] ?? ''),
            'post_lot'=>(string)($st['post_lot'] ?? ''),
            'lot_balance'=>round((float)($st['lot_balance'] ?? 0.0), 2),
            'notes'=>implode(' | ', $notes),
        ];
    }
    return $out;
}
function export_amazon_invoice_repair_dryrun(SQLite3 $db, string $outPath, string $gnucashPath, string $fromDate = '2025-01-01', string $toDate = '', array $orderIds = [], bool $includePaid = false, string $dateSort = 'asc'): int {
    $rows = amazon_invoice_repair_dryrun_rows($db, $gnucashPath, $fromDate, $toDate, $orderIds, $includePaid, $dateSort);
    $fh = fopen($outPath, 'w'); if(!$fh) return 0;
    fputcsv($fh, ['line_no','target_id','order_date','status','plan_book_paid','actual_book_paid','safe_for_auto_repair','book_invoice_total','expected_invoice_total','missing_amount','current_discount_amount','planned_new_discount_amount','new_entry_amount','new_entry_description','new_entry_account','candidate_entry_guids','post_lot','lot_balance','notes']);
    foreach ($rows as $r) fputcsv($fh, [$r['line_no'],$r['target_id'],$r['order_date'],$r['status'],$r['plan_book_paid'],$r['actual_book_paid'],$r['safe_for_auto_repair'],fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['expected_invoice_total']),fmt_money((float)$r['missing_amount']),fmt_money((float)$r['current_discount_amount']),(string)$r['planned_new_discount_amount'],(string)($r['new_entry_amount'] ?? ''),(string)($r['new_entry_description'] ?? ''),(string)($r['new_entry_account'] ?? ''),$r['candidate_entry_guids'],$r['post_lot'],fmt_money((float)$r['lot_balance']),$r['notes']]);
    fclose($fh); return count($rows);
}


function write_amazon_invoice_repair_plan_csv(array $rows, string $outPath): int {
    $fh = fopen($outPath, 'wb');
    fputcsv($fh, ['target_id','order_date','sanity_status','gnucash_invoice_guid','book_paid','expected_invoice_total','book_invoice_total','missing_amount','stored_value_payment_total','candidate_discount_entry_count','candidate_discount_entry_guids','candidate_discount_amount_sum','candidate_discount_description','candidate_discount_account','suggested_action','safe_for_auto_repair','new_candidate_discount_amount','new_entry_amount','new_entry_description','new_entry_account','new_entry_action','new_entry_notes','new_entry_source','reason']);
    foreach ($rows as $r) fputcsv($fh, [$r['target_id'],$r['order_date'],$r['sanity_status'],$r['gnucash_invoice_guid'],$r['book_paid'],fmt_money((float)$r['expected_invoice_total']),fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['missing_amount']),fmt_money((float)$r['stored_value_payment_total']),$r['candidate_discount_entry_count'],$r['candidate_discount_entry_guids'],fmt_money((float)$r['candidate_discount_amount_sum']),$r['candidate_discount_description'],$r['candidate_discount_account'],$r['suggested_action'],$r['safe_for_auto_repair'],$r['new_candidate_discount_amount'],(string)($r['new_entry_amount'] ?? ''),(string)($r['new_entry_description'] ?? ''),(string)($r['new_entry_account'] ?? ''),(string)($r['new_entry_action'] ?? ''),(string)($r['new_entry_notes'] ?? ''),(string)($r['new_entry_source'] ?? ''),$r['reason']]);
    fclose($fh);
    return count($rows);
}
function write_amazon_invoice_repair_dryrun_csv(array $rows, string $outPath): int {
    $fh = fopen($outPath, 'wb');
    fputcsv($fh, ['line_no','target_id','order_date','status','plan_book_paid','actual_book_paid','safe_for_auto_repair','book_invoice_total','expected_invoice_total','missing_amount','current_discount_amount','planned_new_discount_amount','new_entry_amount','new_entry_description','new_entry_account','candidate_entry_guids','post_lot','lot_balance','notes']);
    foreach ($rows as $r) fputcsv($fh, [$r['line_no'],$r['target_id'],$r['order_date'],$r['status'],$r['plan_book_paid'],$r['actual_book_paid'],$r['safe_for_auto_repair'],fmt_money((float)$r['book_invoice_total']),fmt_money((float)$r['expected_invoice_total']),fmt_money((float)$r['missing_amount']),fmt_money((float)$r['current_discount_amount']),(string)$r['planned_new_discount_amount'],(string)($r['new_entry_amount'] ?? ''),(string)($r['new_entry_description'] ?? ''),(string)($r['new_entry_account'] ?? ''),$r['candidate_entry_guids'],$r['post_lot'],fmt_money((float)$r['lot_balance']),$r['notes']]);
    fclose($fh);
    return count($rows);
}
function repair_status_counts(array $rows, string $field='status'): array {
    $counts = [];
    foreach ($rows as $r) { $st = (string)($r[$field] ?? ''); if ($st === '') $st = 'unknown'; $counts[$st] = ($counts[$st] ?? 0) + 1; }
    ksort($counts);
    return $counts;
}
function repair_blocker_count(array $rows): int {
    $n = 0;
    foreach ($rows as $r) if ((string)($r['status'] ?? '') !== 'READY_DRYRUN_ONLY') $n++;
    return $n;
}
function staged_order_date_bounds(SQLite3 $db, string $vendor='amazon'): array {
    $stmt = $db->prepare('SELECT MIN(order_date) AS min_date, MAX(order_date) AS max_date FROM orders WHERE vendor=:vendor AND COALESCE(order_date,"")<>""');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return ['min'=>(string)($r['min_date'] ?? ''), 'max'=>(string)($r['max_date'] ?? '')];
}
function repair_default_from_date(SQLite3 $db): string {
    $saved = normalize_import_date(get_config($db, 'repair_from_date', ''));
    if ($saved !== '') return $saved;
    $bounds = staged_order_date_bounds($db, 'amazon');
    $max = (string)($bounds['max'] ?? '');
    if (preg_match('/^(\d{4})-/', $max, $m)) return $m[1] . '-01-01';
    return '2025-01-01';
}
function repair_default_to_date(SQLite3 $db): string { return normalize_import_date(get_config($db, 'repair_to_date', '')); }

function run_amazon_invoice_repair_apply_script(string $gnucashPath, string $planPath, string $outPath, string $fromDate, string $toDate, bool $apply, string $logPath): array {
    $script = __DIR__ . '/gnucash_invoice_repair_apply_v4.py';
    if (!is_file($script)) return [127, 'Repair apply script missing: ' . $script];
    if (!is_file($gnucashPath)) return [2, 'Selected GnuCash book file not found: ' . $gnucashPath];
    if (!is_readable($gnucashPath)) return [2, 'Selected GnuCash book file is not readable by PHP-FPM: ' . $gnucashPath];
    if ($apply && !is_writable($gnucashPath)) return [2, 'Selected GnuCash book file is not writable by PHP-FPM. For web-run apply tests, make the apitest copy writable by the web user/group, or run the generated command manually as your user.'];
    $runtimeBase = sys_get_temp_dir() . '/gnucash-repair-www';
    @mkdir($runtimeBase, 0770, true);
    @mkdir($runtimeBase . '/cache', 0770, true);
    $cmd = 'HOME=' . escapeshellarg($runtimeBase) . ' ' .
        'XDG_CACHE_HOME=' . escapeshellarg($runtimeBase . '/cache') . ' ' .
        'GSETTINGS_BACKEND=memory ' .
        'XDG_DATA_DIRS=/usr/local/share:/usr/share ' .
        'python3 ' . escapeshellarg($script) .
        ' --book ' . escapeshellarg($gnucashPath) .
        ' --plan ' . escapeshellarg($planPath) .
        ' --from-date ' . escapeshellarg($fromDate) .
        ($toDate !== '' ? ' --to-date ' . escapeshellarg($toDate) : '') .
        ' --out ' . escapeshellarg($outPath) .
        ($apply ? ' --apply' : ' --dry-run');
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    @file_put_contents($logPath, $cmd . "\n\n" . $text . "\nexit_code=" . $code . "\n");
    return [$code, $text];
}

function payment_apply_match_window_before_days(SQLite3 $db): int {
    $raw = trim((string)get_config($db, 'payment_apply_match_window_before_days', '0'));
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return 0;
    return max(0, min(365, (int)$raw));
}

function payment_apply_match_window_after_days(SQLite3 $db): int {
    $raw = trim((string)get_config($db, 'payment_apply_match_window_after_days', '14'));
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return 14;
    return max(0, min(365, (int)$raw));
}

function payment_apply_match_window_label(SQLite3 $db): string {
    return '-' . payment_apply_match_window_before_days($db) . '..+' . payment_apply_match_window_after_days($db) . ' day(s)';
}

function run_payment_apply_script(string $gnucashPath, string $planPath, string $outPath, bool $apply, string $logPath, bool $matchExisting = true, bool $noCreateMissing = true, int $dateWindowDays = 5, bool $allowNonCopyName = false, int $dateWindowBeforeDays = 0): array {
    $script = __DIR__ . '/gnucash_payment_apply_v1.py';
    if (!is_file($script)) return [127, 'Payment apply script missing: ' . $script];
    if (!is_file($planPath)) return [2, 'Payment plan file not found: ' . $planPath];
    if (!is_file($gnucashPath)) return [2, 'Selected GnuCash book file not found: ' . $gnucashPath];
    if (!is_readable($gnucashPath)) return [2, 'Selected GnuCash book file is not readable by PHP-FPM: ' . $gnucashPath];
    if ($apply && !is_writable($gnucashPath)) return [2, 'Selected GnuCash book file is not writable by PHP-FPM. For web-run apply tests, make the apitest copy writable by the web user/group, or run the generated command manually as your user.'];
    $runtimeBase = sys_get_temp_dir() . '/gnucash-repair-www';
    @mkdir($runtimeBase, 0770, true);
    @mkdir($runtimeBase . '/cache', 0770, true);
    $cmd = 'HOME=' . escapeshellarg($runtimeBase) . ' ' .
        'XDG_CACHE_HOME=' . escapeshellarg($runtimeBase . '/cache') . ' ' .
        'GSETTINGS_BACKEND=memory ' .
        'XDG_DATA_DIRS=/usr/local/share:/usr/share ' .
        'python3 ' . escapeshellarg($script) .
        ' --book ' . escapeshellarg($gnucashPath) .
        ' --plan ' . escapeshellarg($planPath) .
        ' --out ' . escapeshellarg($outPath) .
        ($matchExisting ? ' --match-existing' : '') .
        ($noCreateMissing ? ' --no-create-missing' : '') .
        ' --match-date-window-before-days ' . escapeshellarg((string)max(0, $dateWindowBeforeDays)) .
        ' --match-date-window-after-days ' . escapeshellarg((string)max(0, $dateWindowDays)) .
        ($apply && $allowNonCopyName ? ' --allow-non-copy-name' : '') .
        ($apply ? ' --apply' : ' --dry-run');
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    @file_put_contents($logPath, $cmd . "\n\n" . $text . "\nexit_code=" . $code . "\n");
    return [$code, $text];
}
function payment_apply_result_status_counts(string $csvPath): array {
    $counts = ['rows'=>0, 'ready'=>0, 'errors'=>0, 'skips'=>0, 'applied_ok'=>0, 'statuses'=>[]];
    if (!is_file($csvPath) || !is_readable($csvPath)) return $counts;
    $fh = fopen($csvPath, 'r');
    if (!$fh) return $counts;
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return $counts; }
    $header = array_map('normalize_header_name', $header);
    $idx = array_flip($header);
    $statusIdx = $idx['apply_status'] ?? null;
    while (($row = fgetcsv($fh)) !== false) {
        if (!array_filter($row, fn($v)=>trim((string)$v)!=='')) continue;
        $counts['rows']++;
        $st = ($statusIdx !== null) ? trim((string)($row[$statusIdx] ?? '')) : '';
        if ($st === '') $st = 'UNKNOWN';
        $counts['statuses'][$st] = ($counts['statuses'][$st] ?? 0) + 1;
        if (str_starts_with($st, 'READY')) $counts['ready']++;
        elseif (str_starts_with($st, 'ERROR')) $counts['errors']++;
        elseif (str_starts_with($st, 'SKIP')) $counts['skips']++;
        elseif (str_starts_with($st, 'APPLY_OK')) $counts['applied_ok']++;
    }
    fclose($fh);
    ksort($counts['statuses']);
    return $counts;
}

function csv_spreadsheet_safe_id(string $id): string {
    $id = trim($id);
    if ($id === '') return '';
    // Diagnostic/report-only helper: prevents LibreOffice/Excel from silently
    // rounding Lowe's 18-digit order numbers when a CSV is opened as a sheet.
    return 'ID:' . $id;
}
function payment_apply_result_problem_rows(string $csvPath, int $limit = 25): array {
    $out = [];
    if (!is_file($csvPath) || !is_readable($csvPath)) return $out;
    $fh = fopen($csvPath, 'r');
    if (!$fh) return $out;
    $headers = fgetcsv($fh);
    if (!is_array($headers)) { fclose($fh); return $out; }
    while (($row = fgetcsv($fh)) !== false) {
        $r = [];
        foreach ($headers as $i => $hname) $r[(string)$hname] = (string)($row[$i] ?? '');
        $st = (string)($r['apply_status'] ?? '');
        if (!str_starts_with($st, 'ERROR') && !str_starts_with($st, 'SKIP')) continue;
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    fclose($fh);
    return $out;
}

function payment_result_matched_transaction_guids(string $csvPath): array {
    $out = [];
    if (!is_file($csvPath) || !is_readable($csvPath)) return $out;
    $fh = fopen($csvPath, 'r');
    if (!$fh) return $out;
    $headers = fgetcsv($fh);
    if (!is_array($headers)) { fclose($fh); return $out; }
    $idx = [];
    foreach ($headers as $i => $h) $idx[(string)$h] = $i;
    $notesIdx = $idx['notes'] ?? null;
    $statusIdx = $idx['apply_status'] ?? null;
    while (($row = fgetcsv($fh)) !== false) {
        $st = $statusIdx !== null ? (string)($row[$statusIdx] ?? '') : '';
        if (!str_starts_with($st, 'READY') && !str_starts_with($st, 'APPLY_OK') && !str_starts_with($st, 'ALREADY')) continue;
        $notes = $notesIdx !== null ? (string)($row[$notesIdx] ?? '') : '';
        if ($notes === '') continue;
        if (preg_match_all('/\b(?:transaction\s+|tx=)?([a-f0-9]{32})\b/i', $notes, $m)) {
            foreach ($m[1] as $guid) $out[strtolower($guid)] = true;
        }
    }
    fclose($fh);
    return $out;
}
function payment_target_invoice_date(SQLite3 $db, string $vendor, string $targetId): string {
    $vendor = strtolower(trim($vendor));
    $targetId = trim($targetId);
    if ($vendor === '' || $targetId === '') return '';
    $candidates = [$targetId];
    if (str_ends_with($targetId, '-CREDIT')) $candidates[] = substr($targetId, 0, -7);
    if (str_contains($targetId, '-')) $candidates[] = preg_replace('/-.*/', '', $targetId) ?: $targetId;
    foreach (array_unique(array_filter($candidates)) as $id) {
        $stmt = $db->prepare('SELECT order_date FROM orders WHERE vendor=:vendor AND order_id=:oid LIMIT 1');
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $stmt->bindValue(':oid', $id, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $d = normalize_import_date((string)($row['order_date'] ?? ''));
        if ($d !== '') return $d;
    }
    return '';
}

function vendor_register_scan_profile(string $vendor): array {
    $vendor = strtolower(trim($vendor));
    $profiles = [
        'amazon' => ['label'=>'Amazon', 'patterns'=>['AMAZON','AMZN'], 'default_accounts'=>['DEFAULT_PAYMENT_ACCOUNT_AMAZON','DEFAULT_REWARDS_ACCOUNT_AMAZON','DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON','DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON']],
        'costco' => ['label'=>'Costco', 'patterns'=>['COSTCO','COSTCO WHSE','COSTCO.COM'], 'default_accounts'=>['DEFAULT_STORED_VALUE_ACCOUNT_COSTCO']],
        'walmart' => ['label'=>'Walmart', 'patterns'=>['WALMART','WAL-MART','WAL MART'], 'default_accounts'=>['DEFAULT_STORED_VALUE_ACCOUNT_WALMART']],
        'lowes' => ['label'=>"Lowe's", 'patterns'=>['LOWES #','LOWE\'S #','MY LOWE','MYLOWE'], 'default_accounts'=>['DEFAULT_STORED_VALUE_ACCOUNT_LOWES']],
        'home_depot' => ['label'=>'Home Depot', 'patterns'=>['THE HOME DEPOT','HOME DEPOT','HOMEDEPOT','HMEDEPOT','HD #'], 'default_accounts'=>['DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT']],
        'tractor_supply' => ['label'=>'Tractor Supply', 'patterns'=>['TRACTOR SUPPLY','TRACTORSUPPLY','TSC VISA','TSC STORE','TSC #'], 'default_accounts'=>['DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY']],
    ];
    return $profiles[$vendor] ?? ['label'=>vendor_config($vendor)['label'] ?? ucfirst(str_replace('_',' ', $vendor)), 'patterns'=>[strtoupper(str_replace('_',' ', $vendor))], 'default_accounts'=>[]];
}
function vendor_mapped_payment_account_names(SQLite3 $db, string $vendor): array {
    $vendor = strtolower(trim($vendor));
    $out = [];
    $stmt = $db->prepare('SELECT DISTINCT account_fullname FROM payment_method_accounts WHERE vendor=:vendor AND COALESCE(excluded,0)=0 AND COALESCE(account_fullname,"")<>""');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $stmt ? $stmt->execute() : false;
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $name = trim((string)($r['account_fullname'] ?? ''));
        if ($name !== '') $out[$name] = true;
    }
    $profile = vendor_register_scan_profile($vendor);
    foreach ((array)($profile['default_accounts'] ?? []) as $key) {
        $name = trim(default_config_value((string)$key, $db));
        if ($name !== '') $out[$name] = true;
    }
    return array_keys($out);
}
function lowes_mapped_payment_account_names(SQLite3 $db): array { return vendor_mapped_payment_account_names($db, 'lowes'); }
function gnucash_transaction_has_ap_lot_split(SQLite3 $gdb, array $acctMap, string $txGuid): bool {
    $stmt = $gdb->prepare('SELECT account_guid, lot_guid FROM splits WHERE tx_guid=:tx');
    $stmt->bindValue(':tx', $txGuid, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $acct = (string)($acctMap[(string)($r['account_guid'] ?? '')]['name'] ?? '');
        $lot = trim((string)($r['lot_guid'] ?? ''));
        if ($lot !== '' && (str_starts_with($acct, 'Liabilities:Accounts Payable') || str_contains($acct, ':Accounts Payable'))) return true;
    }
    return false;
}
function vendor_register_transaction_ignore_map(SQLite3 $db, string $vendor): array {
    $vendor = strtolower(trim($vendor)); $out=[];
    try {
        $stmt=$db->prepare('SELECT tx_guid, reason, created_at FROM register_transaction_exclusions WHERE vendor=:vendor ORDER BY created_at DESC');
        $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $res=$stmt->execute();
        while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) $out[strtolower((string)$r['tx_guid'])]=['reason'=>(string)($r['reason']??''),'created_at'=>(string)($r['created_at']??'')];
    } catch (Throwable $e) {}
    return $out;
}

function vendor_split_set_scan_tolerance_days(SQLite3 $db, string $vendor): int {
    $vendor = strtolower(trim($vendor));
    if ($vendor === 'lowes') {
        $v = (int)default_config_value('DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS', $db);
        return max(0, min(90, $v ?: 14));
    }
    $generic = trim(get_config($db, 'payment_match_posting_lag_days', ''));
    if ($generic !== '' && is_numeric($generic)) return max(0, min(90, (int)$generic));
    return 14;
}
function vendor_unmatched_register_component_note(array $parts): string {
    $bits = [];
    foreach ($parts as $p) {
        $bits[] = (string)($p['date'] ?? '') . ' $' . fmt_money(abs((float)($p['amount'] ?? 0))) . ' ' . clean_text((string)($p['description'] ?? ''), 60);
    }
    return implode(' | ', $bits);
}
function vendor_find_amount_combos(array $parts, int $targetCents, int $maxParts = 6, int $maxResults = 3): array {
    $parts = array_values($parts);
    usort($parts, function($a,$b){
        $ad = (string)($a['date'] ?? ''); $bd = (string)($b['date'] ?? '');
        if ($ad !== $bd) return strcmp($ad, $bd);
        $aa = (int)($a['amount_cents'] ?? 0); $ba = (int)($b['amount_cents'] ?? 0);
        if ($aa !== $ba) return $ba <=> $aa;
        return strcmp((string)($a['tx_guid'] ?? ''), (string)($b['tx_guid'] ?? ''));
    });
    $out = [];
    $n = count($parts);
    $walk = function($start, $sum, $combo) use (&$walk, &$out, $parts, $n, $targetCents, $maxParts, $maxResults) {
        if (count($out) >= $maxResults) return;
        if ($sum === $targetCents && count($combo) >= 2) { $out[] = $combo; return; }
        if ($sum >= $targetCents || count($combo) >= $maxParts) return;
        for ($i=$start; $i<$n; $i++) {
            $c = (int)($parts[$i]['amount_cents'] ?? 0);
            if ($c <= 0) continue;
            if ($sum + $c > $targetCents) continue;
            $next = $combo; $next[] = $parts[$i];
            $walk($i + 1, $sum + $c, $next);
            if (count($out) >= $maxResults) return;
        }
    };
    $walk(0, 0, []);
    usort($out, function($a,$b){
        $amax = max(array_map(fn($p)=>(int)($p['date_distance'] ?? 0), $a));
        $bmax = max(array_map(fn($p)=>(int)($p['date_distance'] ?? 0), $b));
        if ($amax !== $bmax) return $amax <=> $bmax;
        if (count($a) !== count($b)) return count($a) <=> count($b);
        $adesc = count(array_unique(array_map(fn($p)=>(string)($p['description'] ?? ''), $a)));
        $bdesc = count(array_unique(array_map(fn($p)=>(string)($p['description'] ?? ''), $b)));
        return $adesc <=> $bdesc;
    });
    return $out;
}
function vendor_split_set_invoice_suggestions(SQLite3 $db, SQLite3 $gdb, array $acctMap, string $vendor, array $freeRows, int $limit = 25): array {
    $vendor = strtolower(trim($vendor));
    if (!$freeRows) return [];
    [$start, $end] = payment_match_window($db);
    $orders = [];
    try {
        $sql = 'SELECT order_id, order_date, total FROM orders WHERE vendor=:vendor AND COALESCE(order_id,"")<>""';
        if ($start !== '') $sql .= ' AND (COALESCE(order_date,"")="" OR substr(order_date,1,10)>=:start)';
        if ($end !== '') $sql .= ' AND (COALESCE(order_date,"")="" OR substr(order_date,1,10)<=:end)';
        $sql .= ' ORDER BY order_date, order_id';
        $stmt = $db->prepare($sql); $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        if ($start !== '') $stmt->bindValue(':start', $start, SQLITE3_TEXT);
        if ($end !== '') $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
            $oid = trim((string)($r['order_id'] ?? '')); if ($oid === '') continue;
            $orders[$oid] = ['order_id'=>$oid, 'order_date'=>normalize_import_date((string)($r['order_date'] ?? '')), 'total'=>(float)($r['total'] ?? 0)];
        }
    } catch (Throwable $e) { return []; }
    if (!$orders) return [];
    $ids = [];
    foreach ($orders as $oid=>$o) {
        $ids[$oid] = true;
        if (!str_ends_with(strtoupper((string)$oid), '-CREDIT')) $ids[(string)$oid . '-CREDIT'] = true;
    }
    $states = gnucash_sqlite_invoice_states_batch($gdb, $acctMap, array_keys($ids));
    $tol = vendor_split_set_scan_tolerance_days($db, $vendor);
    $suggestions = [];
    foreach ($states as $target=>$st) {
        if (!($st['found'] ?? false) || !($st['posted'] ?? false) || !empty($st['paid'])) continue;
        $lotBal = (float)($st['lot_balance'] ?? 0.0);
        $targetAbs = round(abs($lotBal), 2);
        if ($targetAbs < 0.005) continue;
        $base = str_ends_with(strtoupper((string)$target), '-CREDIT') ? substr((string)$target, 0, -7) : (string)$target;
        $orderDate = (string)($orders[$target]['order_date'] ?? $orders[$base]['order_date'] ?? '');
        if ($orderDate === '') continue;
        $needRefund = $lotBal > 0.0049;
        $parts = [];
        foreach ($freeRows as $r) {
            $tx = strtolower(trim((string)($r['tx_guid'] ?? ''))); if ($tx === '') continue;
            $d = normalize_import_date((string)($r['date'] ?? '')); if ($d === '') continue;
            $dd = date_diff_days($orderDate, $d);
            if ($dd === null || $dd < 0 || $dd > $tol) continue;
            $amt = (float)($r['amount'] ?? 0.0);
            if (abs($amt) < 0.005 || round(abs($amt), 2) > $targetAbs + 0.005) continue;
            // Normal bills usually need charge/decrease rows; credit memos usually need refund/increase rows.
            if ($needRefund && $amt < 0) continue;
            if (!$needRefund && $amt > 0) continue;
            $parts[] = [
                'tx_guid'=>$tx,
                'date'=>$d,
                'date_distance'=>$dd,
                'account'=>(string)($r['account'] ?? ''),
                'amount'=>$amt,
                'amount_cents'=>(int)round(abs($amt) * 100),
                'description'=>(string)($r['description'] ?? ''),
            ];
        }
        if (count($parts) < 2) continue;
        // Keep the combination search bounded, but prefer rows closest to this invoice date.
        usort($parts, function($a,$b){
            $ad=(int)($a['date_distance'] ?? 0); $bd=(int)($b['date_distance'] ?? 0);
            if ($ad !== $bd) return $ad <=> $bd;
            return ((int)($b['amount_cents'] ?? 0)) <=> ((int)($a['amount_cents'] ?? 0));
        });
        $parts = array_slice($parts, 0, 18);
        $combos = vendor_find_amount_combos($parts, (int)round($targetAbs * 100), 7, 3);
        foreach ($combos as $combo) {
            $suggestions[] = [
                'target_invoice_id'=>(string)$target,
                'order_id'=>$base,
                'invoice_date'=>$orderDate,
                'open_balance'=>$targetAbs,
                'lot_balance'=>$lotBal,
                'component_count'=>count($combo),
                'component_total'=>array_sum(array_map(fn($p)=>abs((float)($p['amount'] ?? 0)), $combo)),
                'max_date_distance'=>max(array_map(fn($p)=>(int)($p['date_distance'] ?? 0), $combo)),
                'accounts'=>implode('; ', array_values(array_unique(array_map(fn($p)=>(string)($p['account'] ?? ''), $combo)))),
                'tx_guids'=>implode('+', array_map(fn($p)=>(string)($p['tx_guid'] ?? ''), $combo)),
                'components'=>vendor_unmatched_register_component_note($combo),
                'note'=>'Potential split-set settlement: these free-floating register transactions sum to the open A/P lot balance for this bill/credit. Review against vendor scrape/payment evidence before applying manually or creating a payment allocation override.',
            ];
            if (count($suggestions) >= $limit) break 2;
        }
    }
    return $suggestions;
}
function vendor_unmatched_register_transactions(SQLite3 $db, string $vendor, string $gnucashPath, string $resultPath = '', int $limit = 100): array {
    $vendor = strtolower(trim($vendor));
    $profile = vendor_register_scan_profile($vendor);
    $label = (string)($profile['label'] ?? ucfirst($vendor));
    $resolved = resolve_local_path($gnucashPath);
    $report = ['rows'=>[], 'split_suggestions'=>[], 'message'=>'', 'candidate_count'=>0, 'matched_guid_count'=>0, 'ignored_count'=>0, 'vendor'=>$vendor, 'label'=>$label];
    if ($resolved === '' || !is_file($resolved) || !is_readable($resolved)) {
        $report['message'] = 'No readable GnuCash SQLite book is selected, so free-floating ' . $label . ' register transactions could not be scanned.';
        return $report;
    }
    $matchedGuids = payment_result_matched_transaction_guids($resultPath);
    $ignoreMap = vendor_register_transaction_ignore_map($db, $vendor);
    $report['matched_guid_count'] = count($matchedGuids);
    $accountNames = vendor_mapped_payment_account_names($db, $vendor);
    if (!$accountNames) {
        $report['message'] = 'No mapped ' . $label . ' payment accounts are configured. Save payment method mappings before scanning for free-floating vendor register transactions.';
        return $report;
    }
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY); $gdb->busyTimeout(30000);
        $acctMap = account_fullnames_by_guid($gdb);
        $guids = [];
        foreach ($accountNames as $acctName) foreach (account_guids_for_fullname($acctMap, $acctName) as $guid) $guids[$guid] = $acctName;
        if (!$guids) {
            $report['message'] = 'The mapped ' . $label . ' payment accounts were not found in the selected GnuCash book: ' . implode('; ', $accountNames);
            return $report;
        }
        [$ph, $params] = sqlite_in_clause_values(array_keys($guids), 'acct');
        [$start, $end] = payment_match_window($db);
        $amtExpr = gnc_amount_sql('s');
        $hay = 'upper(COALESCE(t.description,"") || " " || COALESCE(t.num,"") || " " || COALESCE(s.memo,""))';
        $likeParts = [];
        foreach ((array)($profile['patterns'] ?? []) as $idx=>$pat) $likeParts[] = $hay . ' LIKE :pat' . $idx;
        if (!$likeParts) $likeParts[] = '1=1';
        $sql = 'SELECT t.guid AS tx_guid, substr(t.post_date,1,10) AS post_date, COALESCE(t.description,"") AS description, COALESCE(t.num,"") AS num, COALESCE(s.memo,"") AS memo, s.account_guid AS account_guid, ' . $amtExpr . ' AS amount
                FROM splits s JOIN transactions t ON t.guid=s.tx_guid
                WHERE s.account_guid IN (' . implode(',', $ph) . ')
                  AND (' . implode(' OR ', $likeParts) . ')';
        if ($start !== '') $sql .= ' AND substr(t.post_date,1,10) >= :start';
        if ($end !== '') $sql .= ' AND substr(t.post_date,1,10) <= :end';
        $sql .= ' ORDER BY substr(t.post_date,1,10), t.description, ABS(' . $amtExpr . ') DESC';
        $stmt = $gdb->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v, SQLITE3_TEXT);
        foreach (array_values((array)($profile['patterns'] ?? [])) as $idx=>$pat) $stmt->bindValue(':pat'.$idx, '%' . strtoupper((string)$pat) . '%', SQLITE3_TEXT);
        if ($start !== '') $stmt->bindValue(':start', $start, SQLITE3_TEXT);
        if ($end !== '') $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $tx = strtolower(trim((string)($r['tx_guid'] ?? '')));
            if ($tx === '') continue;
            $report['candidate_count']++;
            if (isset($matchedGuids[$tx])) continue;
            if (isset($ignoreMap[$tx])) { $report['ignored_count']++; continue; }
            if (gnucash_transaction_has_ap_lot_split($gdb, $acctMap, $tx)) continue;
            $acct = (string)($acctMap[(string)($r['account_guid'] ?? '')]['name'] ?? ($guids[(string)($r['account_guid'] ?? '')] ?? ''));
            $acctType = (string)($acctMap[(string)($r['account_guid'] ?? '')]['type'] ?? '');
            $amount = (float)($r['amount'] ?? 0.0);
            $direction = $amount >= 0 ? 'Increase/refund' : 'Decrease/charge';
            if (strtoupper($acctType) === 'LIABILITY') $direction = $amount >= 0 ? 'Refund/credit to card' : 'Card charge';
            elseif (strtoupper($acctType) === 'ASSET') $direction = $amount >= 0 ? 'Asset increase' : 'Stored-value use';
            $report['rows'][] = [
                'date'=>(string)($r['post_date'] ?? ''), 'account'=>$acct, 'amount'=>$amount, 'direction'=>$direction,
                'description'=>clean_text(trim((string)($r['description'] ?? '') . ' ' . (string)($r['num'] ?? '') . ' ' . (string)($r['memo'] ?? '')), 220),
                'tx_guid'=>$tx,
                'note'=>'Vendor register transaction was not used by the current dry-run/apply result and is not already attached to an A/P lot. Review for missing bill/CREDIT memo, unresolved refund, out-of-scope entity, or duplicate/manual import.',
            ];
            if (count($report['rows']) >= $limit) break;
        }
        $report['split_suggestions'] = vendor_split_set_invoice_suggestions($db, $gdb, $acctMap, $vendor, $report['rows'], 25);
        $report['message'] = $report['rows'] ? 'Found free-floating ' . $label . ' register transactions that were not tied to this payment-matching result.' : 'No free-floating ' . $label . ' register transactions found in mapped payment accounts for the active match window.';
        if (!empty($report['split_suggestions'])) $report['message'] .= ' Potential split-set invoice matches were found; review the aggregation suggestions below.';
    } catch (Throwable $e) {
        $report['message'] = 'Unable to scan selected GnuCash book for free-floating ' . $label . ' register transactions: ' . $e->getMessage();
    }
    return $report;
}
function lowes_unmatched_register_transactions(SQLite3 $db, string $gnucashPath, string $resultPath = '', int $limit = 100): array { return vendor_unmatched_register_transactions($db, 'lowes', $gnucashPath, $resultPath, $limit); }
function render_vendor_unmatched_register_report_html(SQLite3 $db, string $vendor, string $gnucashPath, string $resultPath = '', int $limit = 100): string {
    $vendor = strtolower(trim($vendor));
    $rep = vendor_unmatched_register_transactions($db, $vendor, $gnucashPath, $resultPath, $limit);
    $rows = (array)($rep['rows'] ?? []); $label = (string)($rep['label'] ?? ucfirst($vendor));
    $cls = $rows ? 'warn' : 'msg';
    $html = '<details open class="' . $cls . '" style="margin-top:.75rem"><summary>' . h($label) . ' unmatched refund / free-floating register transaction report (' . count($rows) . ')</summary>';
    $profile = vendor_register_scan_profile($vendor);
    $html .= '<p class="small">' . h((string)($rep['message'] ?? '')) . ' This scan looks for vendor-like register descriptions matching: <code>' . h(implode('</code>, <code>', (array)($profile['patterns'] ?? []))) . '</code>. It subtracts transactions used by the current dry-run/apply result, transactions already attached to A/P lots, and rows you have manually ignored. Ignored rows: ' . h((string)($rep['ignored_count'] ?? 0)) . '.</p>';
    $splitSuggestions = (array)($rep['split_suggestions'] ?? []);
    $html .= '<div id="vendor-split-set-suggestions" class="card" style="background:#f8fbff;border-color:#8bb7e0;margin:.6rem 0">';
    $html .= '<h4 style="margin-top:0">Potential split-set invoice matches (' . count($splitSuggestions) . ')</h4>';
    $html .= '<p class="small">These are report-only suggestions. They find multiple free-floating vendor register transactions whose combined amount equals an open posted bill/CREDIT lot. This is common when online pickup/shipping orders settle in multiple card charges.</p>';
    if ($splitSuggestions) {
        $html .= '<table class="lowes-unmatched-register"><thead><tr><th>Target bill/credit</th><th>Invoice date</th><th class="amount-col">Open balance</th><th>Parts</th><th>Max lag</th><th>Accounts</th><th>Component transactions</th><th>Transaction GUIDs</th><th>Review note</th></tr></thead><tbody>';
        foreach ($splitSuggestions as $sg) {
            $html .= '<tr><td><code>' . h((string)($sg['target_invoice_id'] ?? '')) . '</code></td><td>' . h((string)($sg['invoice_date'] ?? '')) . '</td><td class="amount-col">$' . h(fmt_money(abs((float)($sg['open_balance'] ?? 0)))) . '</td><td>' . h((string)($sg['component_count'] ?? '')) . '</td><td>' . h((string)($sg['max_date_distance'] ?? '')) . ' day(s)</td><td class="small">' . h((string)($sg['accounts'] ?? '')) . '</td><td class="small">' . h((string)($sg['components'] ?? '')) . '</td><td><code>' . h((string)($sg['tx_guids'] ?? '')) . '</code></td><td class="small">' . h((string)($sg['note'] ?? '')) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p class="msg" style="padding:.5rem;border:1px solid #b7ebc6;border-radius:6px"><strong>No potential split-set matches found in this scan.</strong> If you expected one, check that the vendor bill/CREDIT is still open, the relevant register transactions are inside the Step 5 date range and posting-lag tolerance, and the transaction descriptions match this vendor scan profile.</p>';
    }
    $html .= '</div>';
    if ($rows) {
        $html .= '<form method="post"><input type="hidden" name="action" value="ignore_vendor_register_transactions"><input type="hidden" name="scan_vendor" value="' . h($vendor) . '"><input type="hidden" name="mode" value="' . h($vendor) . '"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="gnucash_path" value="' . h($gnucashPath) . '">';
        $html .= '<label class="small">Ignore reason<input type="text" name="ignore_reason" value="Reviewed; not part of this vendor bill/credit workflow or handled manually."></label>';
        $html .= '<table class="lowes-unmatched-register"><thead><tr><th>Ignore</th><th>Date</th><th>Account</th><th class="amount-col">Amount</th><th>Direction</th><th>Description</th><th>Transaction GUID</th><th>Review note</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $tx = (string)($r['tx_guid'] ?? '');
            $html .= '<tr><td><input type="checkbox" name="ignore_tx_guid[]" value="' . h($tx) . '"></td><td>' . h((string)($r['date'] ?? '')) . '</td><td>' . h((string)($r['account'] ?? '')) . '</td><td class="amount-col">$' . h(fmt_money(abs((float)($r['amount'] ?? 0)))) . '</td><td>' . h((string)($r['direction'] ?? '')) . '</td><td class="small">' . h((string)($r['description'] ?? '')) . '</td><td><code>' . h($tx) . '</code></td><td class="small">' . h((string)($r['note'] ?? '')) . '</td></tr>';
        }
        $html .= '</tbody></table><button type="submit" style="margin-top:.5rem">Ignore selected register transaction(s)</button></form>';
    }
    $ignores = vendor_register_transaction_ignore_map($db, $vendor);
    if ($ignores) {
        $html .= '<details><summary>Ignored ' . h($label) . ' register transactions (' . count($ignores) . ')</summary><table><thead><tr><th>Transaction GUID</th><th>Reason</th><th>Created</th><th>Action</th></tr></thead><tbody>';
        foreach ($ignores as $tx=>$ex) {
            $html .= '<tr><td><code>' . h($tx) . '</code></td><td class="small">' . h((string)($ex['reason'] ?? '')) . '</td><td>' . h((string)($ex['created_at'] ?? '')) . '</td><td><form method="post" class="mini-form"><input type="hidden" name="action" value="unignore_vendor_register_transaction"><input type="hidden" name="scan_vendor" value="' . h($vendor) . '"><input type="hidden" name="mode" value="' . h($vendor) . '"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="tx_guid" value="' . h($tx) . '"><button type="submit">show again</button></form></td></tr>';
        }
        $html .= '</tbody></table></details>';
    }
    $html .= '</details>';
    return $html;
}
function render_lowes_unmatched_register_report_html(SQLite3 $db, string $gnucashPath, string $resultPath, int $limit = 100): string { return render_vendor_unmatched_register_report_html($db, 'lowes', $gnucashPath, $resultPath, $limit); }
function render_lowes_dry_run_detail_html(string $resultPath, string $logPath, array $dryCounts, int $limit = 25, string $gnucashPath = '', ?SQLite3 $reviewDb = null): string {
    $problemRows = payment_apply_result_problem_rows($resultPath, $limit);
    $hasMissingImported = false;
    foreach ($problemRows as $r) {
        if (str_contains((string)($r['notes'] ?? ''), 'No existing imported payment transaction')) $hasMissingImported = true;
    }
    $html = '<div class="card" id="lowes-dry-run-status-window" style="background:#f8fbff;border-color:#8bb7e0">';
    $errors = (int)($dryCounts['errors'] ?? 0);
    $skips = (int)($dryCounts['skips'] ?? 0);
    $ready = (int)($dryCounts['ready'] ?? 0);
    $html .= '<h4>Lowe\'s Step 5a status window</h4>';
    $html .= '<p><strong>' . (($errors === 0 && $skips === 0) ? 'PASS / clean dry run' : 'Dry run needs review') . '</strong> — ready/matched rows: ' . h((string)$ready) . '; error rows: ' . h((string)$errors) . '; skipped rows: ' . h((string)$skips) . '.</p>';
    if ($hasMissingImported) {
        $html .= '<p class="warn"><strong>Missing imported transaction check:</strong> one or more rows could not find an existing bank/credit-card/My Lowe\'s Money transaction in the mapped account and date window (configured in Config → Lowe\'s module settings to allow online order ship/charge settlement delays). Verify all bank/card/stored-value register transactions are imported into the selected test book, then verify the payment method mapping account and rerun Step 5a.</p>';
    }
    if (!empty($dryCounts['statuses'])) {
        $html .= '<p class="small"><strong>Status counts:</strong> ';
        foreach (($dryCounts['statuses'] ?? []) as $st => $cnt) $html .= '<span class="status-pill">' . h((string)$st) . ': ' . h((string)$cnt) . '</span> ';
        $html .= '</p>';
    }
    if ($problemRows) {
        $html .= '<details open><summary>Problem rows / errors</summary>';
        $html .= '<form method="post" style="margin-top:.5rem"><input type="hidden" name="action" value="skip_lowes_payment_targets_and_rerun_dry_run"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_dry_run"><input type="hidden" name="gnucash_path" value="' . h($gnucashPath) . '">';
        $html .= '<p class="small">For rows that are intentionally unresolved, for example a vendor credit/refund that has not posted to the card account, select the target and click <strong>Skip selected and rerun</strong>. Skipped targets are excluded from the ready/apply plan but remain visible in the exclusion list for later investigation.</p>';
        $html .= '<label class="small">Skip reason<input type="text" name="lowes_skip_reason" value="Manually reviewed; payment/refund is not posted or needs vendor/card-account investigation; skip this target for automated matching."></label>';
        $html .= '<table class="lowes-dry-run-problems"><thead><tr><th class="select-col">Skip</th><th>Order</th><th>Target bill/credit</th><th>Date</th><th>Method</th><th class="amount-col">Amount</th><th>Scraper/mapped account</th><th>Status</th><th class="detail-col">Detail</th></tr></thead><tbody>';
        foreach ($problemRows as $r) {
            $target = trim((string)($r['target_invoice_id'] ?? $r['order_id'] ?? ''));
            $html .= '<tr><td><input type="checkbox" name="lowes_skip_target[]" value="' . h($target) . '"></td><td>' . h((string)($r['order_id'] ?? '')) . '<div class="small">' . h(csv_spreadsheet_safe_id((string)($r['order_id'] ?? ''))) . '</div></td>';
            $html .= '<td>' . h((string)($r['target_invoice_id'] ?? '')) . '<div class="small">' . h(csv_spreadsheet_safe_id((string)($r['target_invoice_id'] ?? ''))) . '</div></td>';
            $html .= '<td>' . h((string)($r['amazon_payment_date'] ?? '')) . '</td><td>' . h((string)($r['payment_method'] ?? '')) . '</td><td class="amount-col">$' . h((string)($r['amount_abs'] ?? '')) . '</td>';
            $html .= '<td>' . h((string)($r['mapped_account'] ?? '')) . '</td><td>' . h((string)($r['apply_status'] ?? '')) . '</td><td class="small detail-col">' . h(clean_text((string)($r['notes'] ?? ''), 360)) . '</td></tr>';
        }
        $html .= '</tbody></table><button type="submit" style="margin-top:.5rem;background:#fff3cd;border:1px solid #d39e00">Skip selected target(s), rebuild, and rerun dry run</button></form></details>';
    }
    if ($reviewDb instanceof SQLite3 && $gnucashPath !== '') {
        $html .= render_lowes_unmatched_register_report_html($reviewDb, $gnucashPath, $resultPath, 100);
    }
    if (is_file($logPath) && is_readable($logPath)) {
        $txt = (string)@file_get_contents($logPath);
        if ($txt !== '') {
            $tail = substr($txt, -12000);
            $html .= '<details><summary>Console log tail</summary><textarea readonly spellcheck="false" class="command-box small">' . h($tail) . '</textarea></details>';
        }
    }
    $html .= '</div>';
    return $html;
}


function render_lowes_apply_detail_html(string $resultPath, string $logPath, array $applyCounts, int $limit = 25): string {
    $problemRows = payment_apply_result_problem_rows($resultPath, $limit);
    $errors = (int)($applyCounts['errors'] ?? 0);
    $skips = (int)($applyCounts['skips'] ?? 0);
    $applied = (int)($applyCounts['applied_ok'] ?? 0);
    $rows = (int)($applyCounts['rows'] ?? 0);
    $html = '<div class="card" id="lowes-apply-status-window" style="background:#f8fff8;border-color:#7abf8a">';
    $html .= '<h4>Lowe\'s Step 5b apply status window</h4>';
    $html .= '<p><strong>' . (($errors === 0 && $skips === 0 && $applied > 0) ? 'APPLY PASS / completed' : 'Apply needs review') . '</strong> — applied/matched rows: ' . h((string)$applied) . '; error rows: ' . h((string)$errors) . '; skipped rows: ' . h((string)$skips) . '; total result rows: ' . h((string)$rows) . '.</p>';
    if (!empty($applyCounts['statuses'])) {
        $html .= '<p class="small"><strong>Status counts:</strong> ';
        foreach (($applyCounts['statuses'] ?? []) as $st => $cnt) $html .= '<span class="status-pill">' . h((string)$st) . ': ' . h((string)$cnt) . '</span> ';
        $html .= '</p>';
    }
    if ($problemRows) {
        $html .= '<details open><summary>Apply problem rows / errors</summary>';
        $html .= '<table class="lowes-dry-run-problems"><thead><tr><th>Order</th><th>Target bill/credit</th><th>Date</th><th>Method</th><th class="amount-col">Amount</th><th>Mapped account</th><th>Status</th><th class="detail-col">Detail</th></tr></thead><tbody>';
        foreach ($problemRows as $r) {
            $html .= '<tr><td>' . h((string)($r['order_id'] ?? '')) . '<div class="small">' . h(csv_spreadsheet_safe_id((string)($r['order_id'] ?? ''))) . '</div></td>';
            $html .= '<td>' . h((string)($r['target_invoice_id'] ?? '')) . '<div class="small">' . h(csv_spreadsheet_safe_id((string)($r['target_invoice_id'] ?? ''))) . '</div></td>';
            $html .= '<td>' . h((string)($r['amazon_payment_date'] ?? '')) . '</td><td>' . h((string)($r['payment_method'] ?? '')) . '</td><td class="amount-col">$' . h((string)($r['amount_abs'] ?? '')) . '</td>';
            $html .= '<td>' . h((string)($r['mapped_account'] ?? '')) . '</td><td>' . h((string)($r['apply_status'] ?? '')) . '</td><td class="small detail-col">' . h(clean_text((string)($r['notes'] ?? ''), 360)) . '</td></tr>';
        }
        $html .= '</tbody></table></details>';
    }
    if (is_file($logPath) && is_readable($logPath)) {
        $txt = (string)@file_get_contents($logPath);
        if ($txt !== '') {
            $tail = substr($txt, -12000);
            $html .= '<details open><summary>Console log tail</summary><textarea readonly spellcheck="false" class="command-box small">' . h($tail) . '</textarea></details>';
        }
    }
    $html .= '</div>';
    return $html;
}

function payment_plan_status_counts_from_csv(string $csvPath): array {
    $counts = ['rows'=>0, 'ready'=>0, 'review'=>0, 'already_ok'=>0, 'excluded'=>0, 'statuses'=>[]];
    if (!is_file($csvPath) || !is_readable($csvPath)) return $counts;
    $fh = fopen($csvPath, 'r');
    if (!$fh) return $counts;
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return $counts; }
    $header = array_map('normalize_header_name', $header);
    $idx = array_flip($header);
    $statusIdx = $idx['status'] ?? null;
    while (($row = fgetcsv($fh)) !== false) {
        if (!array_filter($row, fn($v)=>trim((string)$v)!=='')) continue;
        $counts['rows']++;
        $st = ($statusIdx !== null) ? trim((string)($row[$statusIdx] ?? '')) : '';
        if ($st === '') $st = 'UNKNOWN';
        $counts['statuses'][$st] = ($counts['statuses'][$st] ?? 0) + 1;
        if (in_array($st, ['ready_exact_payment','ready_split_payment_group'], true)) $counts['ready']++;
        elseif ($st === 'already_applied_ok') $counts['already_ok']++;
        elseif (in_array($st, ['excluded_payment_method','excluded_invoice_exists_review','excluded_invoice_unposted_ignored','target_excluded_from_matching'], true)) $counts['excluded']++;
        else $counts['review']++;
    }
    fclose($fh);
    ksort($counts['statuses']);
    return $counts;
}

function export_payment_match_report(SQLite3 $db, string $outPath, string $vendor='amazon'): int {
    $rows = payment_match_rows($db, $vendor, 100000, false); $fh=fopen($outPath,'w'); if(!$fh) return 0;
    fputcsv($fh, ['vendor','order_id','payment_date','payment_method','mapped_account','amount_signed','amount_abs','match_status','order_total','stored_value','refund_total','notes']);
    foreach($rows as $r) fputcsv($fh, [$r['vendor'],$r['order_id'],$r['payment_date'],$r['payment_method'],$r['account_fullname'],fmt_money((float)$r['amount']),fmt_money(abs((float)$r['amount'])),$r['match_status'],fmt_money((float)($r['order_total']??0)),fmt_money((float)($r['stored_value']??0)),fmt_money((float)($r['refund_total']??0)),$r['notes']]);
    fclose($fh); return count($rows);
}

function gnc_amount_sql(string $prefix = ''): string {
    $p = $prefix ? $prefix . '.' : '';
    return 'CASE WHEN ' . $p . 'value_denom IS NULL OR ' . $p . 'value_denom=0 THEN 0 ELSE CAST(' . $p . 'value_num AS REAL)/CAST(' . $p . 'value_denom AS REAL) END';
}
function gnucash_account_fullname_map_sqlite(SQLite3 $gdb): array {
    $rows = [];
    $res = $gdb->query('SELECT guid, name, account_type, parent_guid FROM accounts');
    while ($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) $rows[(string)$r['guid']]=$r;
    $memo=[]; $full=function($guid) use (&$full,&$rows,&$memo): string {
        if(isset($memo[$guid])) return $memo[$guid];
        if(!isset($rows[$guid])) return '';
        $name=(string)$rows[$guid]['name']; $parent=(string)($rows[$guid]['parent_guid'] ?? '');
        if($parent==='' || !isset($rows[$parent]) || strtolower((string)$rows[$parent]['name'])==='root account') return $memo[$guid]=$name;
        $p=$full($parent); return $memo[$guid]=($p?$p.':':'').$name;
    };
    $out=[]; foreach($rows as $guid=>$r) $out[$guid]=['name'=>$full($guid), 'type'=>(string)($r['account_type'] ?? '')];
    return $out;
}
function invoice_empty_state($invoiceId): array {
    // Numeric-looking invoice IDs become integer array keys in PHP.  Keep this
    // helper tolerant so GnuCash bill IDs such as Lowe's 202970... do not fatal
    // when array_keys() hands them back as ints.  The visible invoice ID remains
    // string-normalized for SQL binding, CSV output, and status messages.
    $invoiceId = trim((string)$invoiceId);
    return ['invoice_id'=>$invoiceId, 'found'=>false, 'posted'=>false, 'paid'=>false, 'invoice_total'=>0.0, 'lot_balance'=>0.0, 'post_lot'=>'', 'post_txn'=>'', 'post_acc'=>'', 'payment_accounts'=>[], 'payment_transactions'=>[], 'notes'=>'', 'invoice_guid'=>'', 'duplicate_invoice_count'=>0, 'duplicate_posted_count'=>0, 'duplicate_unposted_count'=>0];
}
function gnc_guid_is_real($v): bool {
    $s = strtolower(trim((string)$v));
    if ($s === '') return false;
    // GnuCash/SQLite can leave unposted business documents with empty or all-zero GUID-ish
    // values in post_txn/post_lot. Treat those as not posted. Otherwise an old unposted
    // credit note with the same visible ID can be preferred over the real posted bill.
    $hex = preg_replace('/[^0-9a-f]/', '', $s);
    if ($hex !== '' && preg_match('/^0+$/', $hex)) return false;
    return true;
}
function gnc_date_is_real_posted($v): bool {
    $s = trim((string)$v);
    if ($s === '') return false;
    // Unposted dates may display as 1969-12-31 / 1970-01-01 due to epoch/null handling.
    // Do not allow those sentinel dates to make an invoice look posted.
    if (preg_match('/^(1969-12-31|1970-01-01|0000-00-00)/', $s)) return false;
    return true;
}
function invoice_row_posted(array $inv): bool {
    return gnc_guid_is_real($inv['post_lot'] ?? '') || gnc_guid_is_real($inv['post_txn'] ?? '') || gnc_date_is_real_posted($inv['date_posted'] ?? '');
}
function choose_preferred_invoice_row(array $rows): ?array {
    if (!$rows) return null;
    // Prefer a posted invoice over an unposted duplicate. This avoids a legacy/unposted
    // credit note with the same visible bill ID making the real posted bill look unposted.
    usort($rows, function($a,$b){
        $ap = invoice_row_posted($a) ? 1 : 0; $bp = invoice_row_posted($b) ? 1 : 0;
        if ($ap !== $bp) return $bp <=> $ap;
        $ad = (string)($a['date_posted'] ?? ''); $bd = (string)($b['date_posted'] ?? '');
        if ($ad !== $bd) return strcmp($bd, $ad);
        return strcmp((string)($a['guid'] ?? ''), (string)($b['guid'] ?? ''));
    });
    return $rows[0];
}
function apply_invoice_row_to_state(array &$state, array $inv, array $allRows = []): void {
    $state['found']=true;
    $state['invoice_guid']=(string)($inv['guid'] ?? '');
    $state['post_lot']=gnc_guid_is_real($inv['post_lot'] ?? '') ? (string)($inv['post_lot'] ?? '') : '';
    $state['post_txn']=gnc_guid_is_real($inv['post_txn'] ?? '') ? (string)($inv['post_txn'] ?? '') : '';
    $state['post_acc']=gnc_guid_is_real($inv['post_acc'] ?? '') ? (string)($inv['post_acc'] ?? '') : '';
    $state['date_posted']=(string)($inv['date_posted'] ?? '');
    $state['posted']=invoice_row_posted($inv);
    if (count($allRows) > 1) {
        $postedCount = 0;
        foreach($allRows as $r) if(invoice_row_posted($r)) $postedCount++;
        $state['duplicate_invoice_count'] = count($allRows);
        $state['duplicate_posted_count'] = $postedCount;
        $state['duplicate_unposted_count'] = count($allRows) - $postedCount;
        $state['notes'] = trim(($state['notes'] ?? '') . ' Multiple GnuCash invoice records share this visible ID: total=' . count($allRows) . ', posted=' . $postedCount . ', unposted=' . (count($allRows)-$postedCount) . '. Tool selected GUID ' . ($state['invoice_guid'] ?: '(unknown)') . ' for status checks.');
        if ($postedCount > 1) $state['ambiguous_duplicate_posted'] = true;
    }
}
function gnucash_sqlite_invoice_state(SQLite3 $gdb, array $acctMap, string $invoiceId): array {
    $state = invoice_empty_state($invoiceId);
    $stmt = $gdb->prepare('SELECT guid, id, date_posted, post_txn, post_lot, post_acc FROM invoices WHERE id=:id');
    $stmt->bindValue(':id', $invoiceId, SQLITE3_TEXT);
    $res = $stmt->execute(); $rows=[];
    while($res && ($inv=$res->fetchArray(SQLITE3_ASSOC))) $rows[]=$inv;
    $inv = choose_preferred_invoice_row($rows);
    if (!$inv) return $state;
    apply_invoice_row_to_state($state, $inv, $rows);
    if ($state['post_txn'] !== '') {
        $amtExpr = gnc_amount_sql('s');
        $q = $gdb->prepare('SELECT SUM(' . $amtExpr . ') AS amt FROM splits s WHERE s.tx_guid=:tx AND (:acc="" OR s.account_guid=:acc)');
        $q->bindValue(':tx', $state['post_txn'], SQLITE3_TEXT); $q->bindValue(':acc', $state['post_acc'], SQLITE3_TEXT);
        $row = $q->execute()->fetchArray(SQLITE3_ASSOC); $state['invoice_total'] = abs((float)($row['amt'] ?? 0));
    }
    if ($state['post_lot'] !== '') {
        $amtExpr = gnc_amount_sql('s');
        $q = $gdb->prepare('SELECT SUM(' . $amtExpr . ') AS bal FROM splits s WHERE s.lot_guid=:lot');
        $q->bindValue(':lot', $state['post_lot'], SQLITE3_TEXT);
        $row = $q->execute()->fetchArray(SQLITE3_ASSOC); $state['lot_balance'] = (float)($row['bal'] ?? 0);
        // Do not trust lots.is_closed alone for business-bill paid detection.
        // Some copied/legacy books can show an is_closed flag even while the GnuCash UI
        // still reports the bill as UNPAID. The authoritative test for this tool is the
        // AP lot balance: paid only when the selected invoice's posting lot balances to zero.
        $state['paid'] = ($state['posted'] && $state['post_lot'] !== '' && abs($state['lot_balance']) < 0.005);
        $payQ = $gdb->prepare('SELECT DISTINCT s.tx_guid AS tx_guid FROM splits s WHERE s.lot_guid=:lot');
        $payQ->bindValue(':lot', $state['post_lot'], SQLITE3_TEXT);
        $txRes = $payQ->execute();
        while ($txRes && ($tr=$txRes->fetchArray(SQLITE3_ASSOC))) {
            $tx=(string)$tr['tx_guid']; if ($tx==='' || $tx===$state['post_txn']) continue;
            $otherQ = $gdb->prepare('SELECT t.post_date AS post_date, t.description AS description, s.account_guid AS account_guid, ' . gnc_amount_sql('s') . ' AS amount FROM splits s JOIN transactions t ON t.guid=s.tx_guid WHERE s.tx_guid=:tx');
            $otherQ->bindValue(':tx', $tx, SQLITE3_TEXT); $or = $otherQ->execute();
            while ($or && ($sr=$or->fetchArray(SQLITE3_ASSOC))) {
                $guid=(string)($sr['account_guid'] ?? ''); $acct=$acctMap[$guid]['name'] ?? '';
                if ($acct === '') continue;
                $amount=(float)($sr['amount'] ?? 0);
                $rec=['tx_guid'=>$tx,'date'=>(string)($sr['post_date'] ?? ''),'description'=>(string)($sr['description'] ?? ''),'account'=>$acct,'amount'=>$amount];
                $state['payment_transactions'][]=$rec;
                if ($guid !== $state['post_acc']) $state['payment_accounts'][]=$rec;
            }
        }
    }
    return $state;
}
function sqlite_placeholders(array $values, string $prefix='p'): array {
    $ph=[]; $params=[]; $i=0;
    foreach ($values as $v) {
        $key=':'.$prefix.$i++;
        $ph[]=$key;
        $params[$key]=(string)$v;
    }
    return [$ph,$params];
}
function sqlite_bind_all(SQLite3Stmt $stmt, array $params): void {
    foreach($params as $k=>$v) $stmt->bindValue($k, (string)$v, SQLITE3_TEXT);
}
function gnucash_sqlite_invoice_states_batch(SQLite3 $gdb, array $acctMap, array $invoiceIds): array {
    $states=[]; $ids=[];
    foreach($invoiceIds as $id){ $id=trim((string)$id); if($id!=='') $ids[$id]=true; }
    foreach(array_keys($ids) as $id) $states[$id] = invoice_empty_state($id);
    if(!$ids) return $states;
    [$ph,$params]=sqlite_placeholders(array_keys($ids),'inv');
    $stmt=$gdb->prepare('SELECT guid, id, date_posted, post_txn, post_lot, post_acc FROM invoices WHERE id IN ('.implode(',',$ph).')');
    sqlite_bind_all($stmt,$params);
    $res=$stmt->execute();
    $rowsById=[];
    while($res && ($inv=$res->fetchArray(SQLITE3_ASSOC))) {
        $id=(string)$inv['id'];
        if(!isset($states[$id])) continue;
        $rowsById[$id][]=$inv;
    }
    $postTxns=[]; $postLots=[]; $postAccByTxn=[]; $postTxnByLot=[];
    foreach($rowsById as $id=>$rowsForId) {
        $chosen = choose_preferred_invoice_row($rowsForId);
        if(!$chosen) continue;
        apply_invoice_row_to_state($states[$id], $chosen, $rowsForId);
        if($states[$id]['post_txn']!=='') { $postTxns[$states[$id]['post_txn']]=true; $postAccByTxn[$states[$id]['post_txn']]=$states[$id]['post_acc']; }
        if($states[$id]['post_lot']!=='') { $postLots[$states[$id]['post_lot']]=true; if($states[$id]['post_txn']!=='') $postTxnByLot[$states[$id]['post_lot']]=$states[$id]['post_txn']; }
    }
    // Posting transaction totals, batched by transaction/account.
    if($postTxns) {
        [$tph,$tparams]=sqlite_placeholders(array_keys($postTxns),'tx');
        $amtExpr=gnc_amount_sql('s');
        $q=$gdb->prepare('SELECT s.tx_guid AS tx_guid, s.account_guid AS account_guid, SUM('.$amtExpr.') AS amt FROM splits s WHERE s.tx_guid IN ('.implode(',',$tph).') GROUP BY s.tx_guid, s.account_guid');
        sqlite_bind_all($q,$tparams);
        $r=$q->execute(); $txnAcctAmt=[];
        while($r && ($row=$r->fetchArray(SQLITE3_ASSOC))) $txnAcctAmt[(string)$row['tx_guid'].'|'.(string)$row['account_guid']] = (float)($row['amt'] ?? 0);
        foreach($states as $id=>&$st) {
            $key=$st['post_txn'].'|'.$st['post_acc'];
            if($st['post_txn']!=='' && $st['post_acc']!=='' && isset($txnAcctAmt[$key])) $st['invoice_total']=abs((float)$txnAcctAmt[$key]);
        }
        unset($st);
    }
    if($postLots) {
        [$lph,$lparams]=sqlite_placeholders(array_keys($postLots),'lot');
        $amtExpr=gnc_amount_sql('s');
        $q=$gdb->prepare('SELECT s.lot_guid AS lot_guid, SUM('.$amtExpr.') AS bal FROM splits s WHERE s.lot_guid IN ('.implode(',',$lph).') GROUP BY s.lot_guid');
        sqlite_bind_all($q,$lparams);
        $r=$q->execute(); $lotBal=[];
        while($r && ($row=$r->fetchArray(SQLITE3_ASSOC))) $lotBal[(string)$row['lot_guid']] = (float)($row['bal'] ?? 0);
        foreach($states as $id=>&$st) {
            $lot=$st['post_lot']; if($lot==='') continue;
            $st['lot_balance']=(float)($lotBal[$lot] ?? 0);
            // Do not trust lots.is_closed alone. Match the GnuCash UI behavior more closely:
            // a posted bill is paid only when the selected AP lot balance is effectively zero.
            $st['paid']=(!empty($st['posted']) && abs((float)$st['lot_balance']) < 0.005);
        }
        unset($st);
        // Find all transactions in the posting lots, then fetch split details in one pass.
        $q=$gdb->prepare('SELECT s.lot_guid AS lot_guid, s.tx_guid AS tx_guid FROM splits s WHERE s.lot_guid IN ('.implode(',',$lph).') GROUP BY s.lot_guid, s.tx_guid');
        sqlite_bind_all($q,$lparams);
        $r=$q->execute(); $lotTxns=[]; $paymentTxns=[]; $txnToLots=[];
        while($r && ($row=$r->fetchArray(SQLITE3_ASSOC))) {
            $lot=(string)$row['lot_guid']; $tx=(string)$row['tx_guid']; if($lot===''||$tx==='') continue;
            $lotTxns[$lot][$tx]=true;
            if(isset($postTxnByLot[$lot]) && $tx===$postTxnByLot[$lot]) continue;
            $paymentTxns[$tx]=true; $txnToLots[$tx][$lot]=true;
        }
        if($paymentTxns) {
            [$pph,$pparams]=sqlite_placeholders(array_keys($paymentTxns),'ptx');
            $amtExpr=gnc_amount_sql('s');
            $q=$gdb->prepare('SELECT t.post_date AS post_date, t.description AS description, s.tx_guid AS tx_guid, s.account_guid AS account_guid, '.$amtExpr.' AS amount FROM splits s JOIN transactions t ON t.guid=s.tx_guid WHERE s.tx_guid IN ('.implode(',',$pph).')');
            sqlite_bind_all($q,$pparams);
            $r=$q->execute();
            while($r && ($sr=$r->fetchArray(SQLITE3_ASSOC))) {
                $tx=(string)($sr['tx_guid'] ?? ''); if($tx==='' || !isset($txnToLots[$tx])) continue;
                $guid=(string)($sr['account_guid'] ?? ''); $acct=$acctMap[$guid]['name'] ?? '';
                if($acct==='') continue;
                $amount=(float)($sr['amount'] ?? 0);
                $rec=['tx_guid'=>$tx,'date'=>(string)($sr['post_date'] ?? ''),'description'=>(string)($sr['description'] ?? ''),'account'=>$acct,'amount'=>$amount];
                foreach(array_keys($txnToLots[$tx]) as $lot) {
                    foreach($states as $id=>&$st) {
                        if($st['post_lot'] !== $lot) continue;
                        $st['payment_transactions'][]=$rec;
                        if($guid !== $st['post_acc']) $st['payment_accounts'][]=$rec;
                    }
                    unset($st);
                }
            }
        }
    }
    // Legacy cleanup aid: detect invoice IDs that are present in GnuCash but malformed,
    // usually due to leading/trailing spaces or other pasted text.  These are deliberately
    // review-only; the payment plan will not treat them as ready-to-apply.
    $missingIds = [];
    foreach($states as $id=>$st) if(!($st['found'] ?? false)) $missingIds[$id]=true;
    if($missingIds) {
        try {
            $allInv = $gdb->query('SELECT id FROM invoices');
            $candidateFor = [];
            while($allInv && ($ir=$allInv->fetchArray(SQLITE3_ASSOC))) {
                $raw = (string)($ir['id'] ?? '');
                if($raw === '') continue;
                $trimmed = trim($raw);
                foreach(array_keys($missingIds) as $wanted) {
                    if($raw === $wanted) continue;
                    // Critical Amazon CREDIT guardrail: ORDERID-CREDIT missing does not mean
                    // the normal ORDERID bill is a malformed match.  The base purchase bill and
                    // the refund credit memo are separate vendor documents and may both exist.
                    // Matching the base bill to the credit target caused prior repair passes to
                    // shrink the purchase bill to the refund total instead of importing a real
                    // ORDERID-CREDIT memo.
                    if (amazon_order_credit_base_pair($wanted, $trimmed)) continue;
                    if (amazon_order_credit_base_pair($wanted, $raw)) continue;
                    if($trimmed === $wanted || ($wanted !== '' && (str_contains($raw, $wanted) || (!str_ends_with($wanted, '-CREDIT') && str_contains($wanted, $trimmed))))) {
                        $candidateFor[$wanted] = $raw;
                        unset($missingIds[$wanted]);
                        break;
                    }
                }
                if(!$missingIds) break;
            }
            foreach($candidateFor as $wanted=>$actual) {
                $st = gnucash_sqlite_invoice_state($gdb, $acctMap, $actual);
                $st['invoice_id'] = $wanted;
                $st['found'] = true;
                $st['malformed_invoice_id'] = $actual;
                $st['notes'] = trim(($st['notes'] ?? '') . ' GnuCash invoice ID found by fuzzy/contains search, but exact ID differs. Actual GnuCash ID: [' . $actual . '].');
                $states[$wanted] = $st;
            }
        } catch(Throwable $e) {}
    }
    return $states;
}
function load_gnucash_invoice_states_for_payments(string $path, array $invoiceIds): array {
    static $cache = [];
    $cfg = gnucash_backend_config();
    $ids=[]; foreach($invoiceIds as $id){ $id=trim((string)$id); if($id!=='') $ids[$id]=true; }
    ksort($ids);
    if (!$ids) return [];
    $cacheKey = ($cfg['gnucash_backend'] ?? 'sqlite').'|'.$path.'|'.md5(implode('|', array_keys($ids)));
    if(isset($cache[$cacheKey])) return $cache[$cacheKey];
    $states=[];
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') {
        try {
            $pdo=pdo_for_gnucash($cfg); $placeholders=[]; $params=[]; $i=0;
            foreach(array_keys($ids) as $id){$ph=':id'.$i++; $placeholders[]=$ph; $params[$ph]=$id;}
            $stmt=$pdo->prepare('SELECT id, guid, post_txn, post_lot, post_acc FROM invoices WHERE id IN ('.implode(',',$placeholders).')'); foreach($params as $ph=>$v)$stmt->bindValue($ph,$v); $stmt->execute();
            foreach($stmt->fetchAll() as $r){$states[(string)$r['id']]=['invoice_id'=>(string)$r['id'],'found'=>true,'posted'=>trim((string)($r['post_lot']??''))!=='' || trim((string)($r['post_txn']??''))!=='','paid'=>false,'invoice_total'=>0.0,'lot_balance'=>0.0,'post_lot'=>(string)($r['post_lot']??''),'post_txn'=>(string)($r['post_txn']??''),'post_acc'=>(string)($r['post_acc']??''),'payment_accounts'=>[],'payment_transactions'=>[],'notes'=>'SQL backend: existence/posting check only; use SQLite copy/API validation before applying payments.'];}
        } catch(Throwable $e) {}
        foreach(array_keys($ids) as $id) if(!isset($states[$id])) $states[$id]=['invoice_id'=>$id,'found'=>false,'posted'=>false,'paid'=>false,'invoice_total'=>0.0,'lot_balance'=>0.0,'post_lot'=>'','post_txn'=>'','post_acc'=>'','payment_accounts'=>[],'payment_transactions'=>[],'notes'=>''];
        return $cache[$cacheKey]=$states;
    }
    $resolved = resolve_local_path($path);
    if ($resolved==='' || !file_exists($resolved) || !is_readable($resolved)) return [];
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $gdb->busyTimeout(30000);
        $acctMap = gnucash_account_fullname_map_sqlite($gdb);
        $states = gnucash_sqlite_invoice_states_batch($gdb, $acctMap, array_keys($ids));
    } catch(Throwable $e) {}
    return $cache[$cacheKey]=$states;
}


function payment_allocation_override_key(string $vendor, string $orderId, string $date, string $method): string {
    return strtolower(trim($vendor)) . '|' . trim($orderId) . '|' . normalize_import_date($date) . '|' . strtolower(trim($method));
}
function payment_allocation_override_map(SQLite3 $db, string $vendor='amazon'): array {
    $out = [];
    try {
        $stmt = $db->prepare('SELECT vendor, order_id, payment_date, payment_method, amount, note FROM payment_allocation_overrides WHERE vendor=:vendor');
        $stmt->bindValue(':vendor', strtolower(trim($vendor)), SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $out[payment_allocation_override_key((string)$r['vendor'], (string)$r['order_id'], (string)$r['payment_date'], (string)$r['payment_method'])] = $r;
        }
    } catch (Throwable $e) {}
    return $out;
}
function apply_payment_allocation_overrides(SQLite3 $db, string $vendor, array $rows): array {
    $overrides = payment_allocation_override_map($db, $vendor);
    if (!$overrides) return $rows;
    foreach ($rows as $i => $r) {
        $key = payment_allocation_override_key((string)($r['vendor'] ?? $vendor), (string)($r['order_id'] ?? ''), (string)($r['payment_date'] ?? ''), (string)($r['payment_method'] ?? ''));
        if (!isset($overrides[$key])) continue;
        $old = (float)($rows[$i]['amount'] ?? 0.0);
        $newAbs = round(abs((float)($overrides[$key]['amount'] ?? 0.0)), 2);
        if ($newAbs <= 0.005) continue;
        $rows[$i]['amount'] = $old < 0 ? -$newAbs : $newAbs;
        $rows[$i]['notes'] = trim((string)($rows[$i]['notes'] ?? '') . ' v147 manual allocation override: amount changed from $' . fmt_money(abs($old)) . ' to $' . fmt_money($newAbs) . '. ' . (string)($overrides[$key]['note'] ?? ''));
        $rows[$i]['payment_plan_adjusted'] = 'manual_allocation_override_v147';
        $rows[$i]['payment_plan_original_amount'] = $old;
    }
    return $rows;
}

function adjust_amazon_multi_order_stored_value_rows_against_staged_orders(SQLite3 $db, array $rows): array {
    // v145: Recalculate stale in-memory payment rows that were produced by an older
    // proportional allocation for a grouped Amazon stored-value transaction.  Prefer
    // explicit original-group notes when present, but also detect stale rows by
    // grouping same-date/same-method stored-value rows and comparing them against
    // each order's own Amazon order-summary stored-value amount.  This catches rows
    // that survived in the local review DB without the v143/v144 note text.
    $groups = [];
    foreach ($rows as $i => $r) {
        if (strtolower((string)($r['vendor'] ?? 'amazon')) !== 'amazon') continue;
        $method = (string)($r['payment_method'] ?? '');
        if (!is_stored_value_payment_method($method) || amazon_method_is_prime_young_cashback($method)) continue;
        $date = normalize_import_date((string)($r['payment_date'] ?? ''));
        $oid = trim((string)($r['order_id'] ?? ''));
        if ($date === '' || $oid === '') continue;
        $notes = (string)($r['notes'] ?? '');
        if (preg_match('/Original Amazon transaction row named multiple orders \[([^\]]+)\] for \$([0-9,]+(?:\.[0-9]{1,2})?)/', $notes, $m)) {
            $ids = preg_split('/\s*[,;]\s*/', trim($m[1])) ?: [];
            $origAbs = money_to_float($m[2]);
            if ($origAbs <= 0.005) continue;
            $key = 'noted|' . strtolower($method) . '|' . $date . '|' . implode(', ', array_map('trim', $ids)) . '|' . fmt_money($origAbs);
            if (!isset($groups[$key])) $groups[$key] = ['ids'=>$ids, 'method'=>$method, 'date'=>$date, 'amount'=>-abs($origAbs), 'idxs'=>[]];
            $groups[$key]['idxs'][$i] = $oid;
        } else {
            // Fallback detector for stale rows without original-group notes.  We only
            // consider rows sharing the same date and payment method; later we require
            // their current absolute total to equal the sum of the order-summary stored
            // value amounts, and at least one row must differ from that expected amount.
            $key = 'implicit|' . strtolower($method) . '|' . $date;
            if (!isset($groups[$key])) $groups[$key] = ['ids'=>[], 'method'=>$method, 'date'=>$date, 'amount'=>0.0, 'idxs'=>[], 'implicit'=>true];
            $groups[$key]['ids'][$oid] = true;
            $groups[$key]['idxs'][$i] = $oid;
            $groups[$key]['amount'] += (float)($r['amount'] ?? 0.0);
        }
    }
    foreach ($groups as $g) {
        $implicit = !empty($g['implicit']);
        $ids = $implicit ? array_keys($g['ids']) : array_values(array_filter(array_map('trim', (array)$g['ids'])));
        $ids = array_values(array_unique($ids));
        if (count($ids) <= 1) continue;
        $expected = [];
        $expectedSum = 0.0;
        foreach ($ids as $id) {
            $basis = round(amazon_staged_order_summary_stored_value_amount($db, $id, (string)$g['method']), 2);
            if ($basis <= 0.005) { $expected = []; break; }
            $expected[$id] = $basis;
            $expectedSum = round($expectedSum + $basis, 2);
        }
        if (!$expected || $expectedSum <= 0.005) continue;
        $groupAbs = round(abs((float)$g['amount']), 2);
        if (abs($groupAbs - $expectedSum) > 0.02) continue;
        $anyDiff = false;
        foreach ($g['idxs'] as $idx => $oid) {
            if (!isset($expected[$oid])) continue;
            $oldAbs = round(abs((float)($rows[$idx]['amount'] ?? 0.0)), 2);
            if (abs($oldAbs - $expected[$oid]) > 0.005) { $anyDiff = true; break; }
        }
        if (!$anyDiff) continue;
        foreach ($g['idxs'] as $idx => $oid) {
            if (!isset($expected[$oid])) continue;
            $oldAbs = round(abs((float)($rows[$idx]['amount'] ?? 0.0)), 2);
            $newAbs = round((float)$expected[$oid], 2);
            if (abs($oldAbs - $newAbs) <= 0.005) continue;
            $old = (float)($rows[$idx]['amount'] ?? 0.0);
            $rows[$idx]['amount'] = $old < 0 ? -$newAbs : $newAbs;
            $kind = $implicit ? 'implicit same-date grouped stored-value' : 'original-group noted stored-value';
            $rows[$idx]['notes'] = trim((string)($rows[$idx]['notes'] ?? '') . ' v145 payment-plan correction: stale ' . $kind . ' allocation $' . fmt_money($oldAbs) . ' recalculated from order-summary stored-value amount as $' . fmt_money($newAbs) . '. Re-import Amazon transaction history and re-export stored-value CSVs to persist this corrected split.');
            $rows[$idx]['payment_plan_adjusted'] = 'multi_order_stored_value_reallocated_v145';
            $rows[$idx]['payment_plan_original_amount'] = $old;
        }
    }
    return $rows;
}

function adjust_amazon_gross_card_rows_against_invoice_total(array $rows, array $states): array {
    // v142: Amazon can export/record explicit stored-value payment rows (Amazon Points,
    // Amazon Visa points, rewards/cash-back) while a separate card row still carries the
    // gross bill total.  The related-transactions page/source of truth in that case is:
    //     card residual + stored-value rows = invoice total
    // not:
    //     gross card row + stored-value rows
    // When a group has exactly one non-stored charge row whose amount already equals the
    // posted invoice total, and one or more stored-value charge rows, reduce the card row
    // in-memory to the residual.  This affects payment planning only; it does not mutate
    // the imported transaction-history rows.
    $groups = [];
    foreach ($rows as $i => $r) {
        if (strtolower((string)($r['vendor'] ?? 'amazon')) !== 'amazon') continue;
        $oid = trim((string)($r['order_id'] ?? ''));
        if ($oid === '') continue;
        $amt = (float)($r['amount'] ?? 0.0);
        if ($amt >= -0.00001) continue; // charge-side only; refunds/credits handled elsewhere
        $target = $oid;
        $groups[$target][] = $i;
    }
    foreach ($groups as $target => $idxs) {
        $state = $states[$target] ?? null;
        if (!$state || !($state['found'] ?? false)) continue;
        $expected = round(abs((float)($state['invoice_total'] ?? 0.0)), 2);
        if ($expected <= 0.005) continue;
        $storedIdxs = [];
        $cardIdxs = [];
        $storedSum = 0.0;
        $chargeSum = 0.0;
        foreach ($idxs as $idx) {
            $method = (string)($rows[$idx]['payment_method'] ?? '');
            $amtAbs = round(abs((float)($rows[$idx]['amount'] ?? 0.0)), 2);
            if ($amtAbs <= 0.005) continue;
            $chargeSum = round($chargeSum + $amtAbs, 2);
            if (is_stored_value_payment_method($method)) {
                $storedIdxs[] = $idx;
                $storedSum = round($storedSum + $amtAbs, 2);
            } else {
                $cardIdxs[] = $idx;
            }
        }
        if (count($cardIdxs) !== 1 || count($storedIdxs) < 1) continue;
        if ($storedSum <= 0.005) continue;
        $cardIdx = $cardIdxs[0];
        $cardAbs = round(abs((float)($rows[$cardIdx]['amount'] ?? 0.0)), 2);
        // Conservative guard: only adjust the common bad pattern where the card row
        // itself equals the invoice total.  Do not change ordinary split payments or
        // multi-order stored-value allocations.
        if (abs($cardAbs - $expected) > 0.02) continue;
        if ($chargeSum <= $expected + 0.02) continue;
        $residual = round($expected - $storedSum, 2);
        if ($residual <= 0.005) continue;
        if (abs($residual - $cardAbs) <= 0.02) continue;
        $orig = (float)($rows[$cardIdx]['amount'] ?? 0.0);
        $rows[$cardIdx]['amount'] = $orig < 0 ? -$residual : $residual;
        $note = 'v142 payment-plan adjustment: imported card row appeared to duplicate the gross invoice total ($' . fmt_money($cardAbs) . ') while explicit stored-value charge rows total $' . fmt_money($storedSum) . '. Planning uses residual card charge $' . fmt_money($residual) . ' so card + stored value equals posted invoice total $' . fmt_money($expected) . '.';
        $rows[$cardIdx]['notes'] = trim((string)($rows[$cardIdx]['notes'] ?? '') . ' ' . $note);
        $rows[$cardIdx]['payment_plan_adjusted'] = 'gross_card_minus_stored_value';
        $rows[$cardIdx]['payment_plan_original_amount'] = $orig;
    }
    return $rows;
}


function payment_target_invoice_id_for_row(string $vendor, string $orderId, float $amount): string {
    $orderId = trim($orderId);
    if ($orderId === '') return '';
    if ($amount > 0.005) {
        // Return/credit documents imported by vendor-specific importers may already
        // carry the tool convention ORDERID-CREDIT.  Do not append a second suffix.
        if (str_ends_with(strtoupper((string)$orderId), '-CREDIT')) return (string)$orderId;
        return $orderId . '-CREDIT';
    }
    return $orderId;
}
function payment_book_preflight(SQLite3 $db, string $vendor, string $gnucashPath): array {
    $vendor = strtolower(trim($vendor));
    $rows = payment_match_rows($db, $vendor, 100000, true);
    $ids = [];
    foreach ($rows as $r) {
        $oid = trim((string)($r['order_id'] ?? ''));
        if ($oid === '') continue;
        $amt = (float)($r['amount'] ?? 0.0);
        $target = payment_target_invoice_id_for_row($vendor, $oid, $amt);
        if ($target !== '') $ids[$target] = true;
        $ids[$oid] = true;
    }
    $resolved = resolve_local_path($gnucashPath);
    $out = [
        'vendor'=>$vendor,
        'configured_path'=>$gnucashPath,
        'resolved_path'=>$resolved,
        'payment_rows'=>count($rows),
        'candidate_ids'=>count($ids),
        'path_ok'=>false,
        'readable'=>false,
        'sqlite_ok'=>false,
        'matched_ids'=>0,
        'posted_ids'=>0,
        'unpaid_ids'=>0,
        'paid_ids'=>0,
        'message'=>'',
    ];
    $cfg = gnucash_backend_config();
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') {
        $out['path_ok'] = true;
        $out['readable'] = true;
        $out['sqlite_ok'] = false;
        $out['message'] = 'GnuCash SQL backend is configured; file-path preflight is skipped. For payment apply, validate against a SQLite copy/test book before applying.';
        return $out;
    }
    if (trim($gnucashPath) === '') { $out['message']='No GnuCash SQLite file path is configured. Use Step 1 / Config to save the current .gnucash/.apitest file path before Step 5a.'; return $out; }
    if ($resolved === '' || !file_exists($resolved)) { $out['message']='Selected GnuCash SQLite file was not found: ' . $resolved; return $out; }
    $out['path_ok'] = true;
    if (!is_readable($resolved)) { $out['message']='Selected GnuCash SQLite file is not readable by the web/PHP user: ' . $resolved; return $out; }
    $out['readable'] = true;
    if (!$ids) { $out['message']='No vendor payment rows are staged for ' . $vendor . '; import/refresh the vendor dataset first.'; return $out; }
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $tableCheck = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='invoices'");
        if ($tableCheck !== 'invoices') { $out['message']='Opened file, but it does not look like a GnuCash SQLite file with an invoices table.'; return $out; }
        $out['sqlite_ok'] = true;
        $states = gnucash_sqlite_invoice_states_batch($gdb, gnucash_account_fullname_map_sqlite($gdb), array_keys($ids));
        foreach($states as $st) {
            if (!($st['found'] ?? false)) continue;
            $out['matched_ids']++;
            if (!empty($st['posted'])) $out['posted_ids']++;
            if (!empty($st['paid'])) $out['paid_ids']++;
            elseif (!empty($st['posted'])) $out['unpaid_ids']++;
        }
        $out['message'] = 'Book preflight: ' . $out['matched_ids'] . ' of ' . $out['candidate_ids'] . ' candidate invoice/credit IDs found; posted=' . $out['posted_ids'] . '; unpaid=' . $out['unpaid_ids'] . '; already paid=' . $out['paid_ids'] . '.';
    } catch(Throwable $e) {
        $out['message']='Unable to open/read selected GnuCash SQLite file for invoice preflight: ' . $e->getMessage();
    }
    return $out;
}
function payment_book_preflight_ok_for_step5(array $preflight): bool {
    if (!($preflight['path_ok'] ?? false) || !($preflight['readable'] ?? false)) return false;
    if (($preflight['candidate_ids'] ?? 0) > 0 && ($preflight['matched_ids'] ?? 0) <= 0) return false;
    return true;
}

function build_payment_application_plan(SQLite3 $db, string $vendor='amazon', string $gnucashPath=''): array {
    static $planCache = [];
    $resolvedForKey = resolve_local_path($gnucashPath);
    $fileStampForKey = ($resolvedForKey !== '' && file_exists($resolvedForKey)) ? (string)@filemtime($resolvedForKey) . ':' . (string)@filesize($resolvedForKey) : '';
    $refreshToken = get_config($db, 'payment_scan_refresh_token', '');
    $cacheKey = $vendor . '|' . $gnucashPath . '|' . $fileStampForKey . '|' . $refreshToken . '|' . get_config($db, 'payment_match_start_date', '') . '|' . get_config($db, 'payment_match_end_date', '') . '|' . ((string)($db->querySingle('SELECT COALESCE(MAX(rowid),0) FROM vendor_payments'))) . '|' . ((string)($db->querySingle('SELECT COALESCE(MAX(rowid),0) FROM payment_target_exclusions')));
    if (isset($planCache[$cacheKey])) return $planCache[$cacheKey];
    $rows = payment_match_rows($db, $vendor, 100000, true);
    if (strtolower($vendor) === 'amazon') $rows = adjust_amazon_multi_order_stored_value_rows_against_staged_orders($db, $rows);
    $rows = apply_payment_allocation_overrides($db, $vendor, $rows);
    $targetExclusions = payment_target_exclusion_map($db, $vendor);
    $ids=[];
    foreach($rows as $r){
        $oid=(string)$r['order_id']; if($oid==='') continue;
        $amt=(float)$r['amount']; $target = payment_target_invoice_id_for_row($vendor, $oid, $amt);
        if($target!=='') $ids[$target]=true; $ids[$oid]=true;
    }
    $states = load_gnucash_invoice_states_for_payments($gnucashPath, array_keys($ids));
    if (strtolower($vendor) === 'amazon') $rows = adjust_amazon_gross_card_rows_against_invoice_total($rows, $states);
    $groups=[];
    foreach($rows as $r){
        $excludedRow = (($r['match_status'] ?? '')==='excluded_payment_method');
        $oid=(string)$r['order_id']; if($oid==='') continue;
        $amt=(float)$r['amount']; $target = payment_target_invoice_id_for_row($vendor, $oid, $amt);
        $gkey=$vendor.'|'.$target; if(!isset($groups[$gkey])) $groups[$gkey]=['target'=>$target,'order_id'=>$oid,'sum'=>0.0,'rows'=>0,'methods'=>[]];
        if(!$excludedRow){ $groups[$gkey]['sum'] += abs($amt); $groups[$gkey]['rows']++; $groups[$gkey]['methods'][]=(string)$r['payment_method']; }
    }
    $plan=[];
    foreach($rows as $r){
        $status=(string)($r['match_status'] ?? 'unmatched'); $notes=(string)($r['notes'] ?? '');
        $oid=(string)$r['order_id']; $amt=(float)$r['amount']; $acct=trim((string)($r['account_fullname'] ?? ''));
        $defaultTarget = payment_target_invoice_id_for_row($vendor, $oid, $amt);
        $target = $defaultTarget;
        $targetExcluded = payment_target_is_excluded($targetExclusions, $defaultTarget, $oid);
        $targetExcludeReason = $targetExcluded ? (string)(($targetExclusions[$defaultTarget]['reason'] ?? $targetExclusions[$oid]['reason'] ?? '') ?: 'Excluded from transaction matching by user.') : '';
        $legacyCreditSameId = false;
        $state = $states[$target] ?? ['found'=>false,'posted'=>false,'paid'=>false,'invoice_total'=>0.0,'lot_balance'=>0.0,'payment_accounts'=>[],'notes'=>''];
        // Legacy/manual books may have Amazon refund credit memos entered with the raw order ID
        // instead of the tool's ORDERID-CREDIT convention. Do not auto-apply these, but
        // detect them so they become a review item rather than a misleading missing invoice.
        if ($amt > 0.005 && !($state['found'] ?? false) && isset($states[$oid]) && ($states[$oid]['found'] ?? false)) {
            $state = $states[$oid];
            $target = $oid;
            $legacyCreditSameId = true;
            $notes .= ' Expected credit memo target '.$defaultTarget.' was not found, but a legacy same-ID GnuCash bill/credit exists for '.$oid.'. Best practice is a unique credit memo ID such as '.$defaultTarget.'; review before applying.';
        }
        $gkey=$vendor.'|'.$defaultTarget; $group=$groups[$gkey] ?? ['sum'=>abs($amt),'rows'=>1];
        $expected=(float)($state['invoice_total'] ?? 0); if($expected <= 0.005) $expected=(float)($r['order_total'] ?? 0);
        $orderDate = normalize_import_date((string)($r['order_date'] ?? ''));
        $paymentDate = normalize_import_date((string)($r['payment_date'] ?? ''));
        $mappedFound=false; $mappedExact=false; $amountExactAnyAccount=false; $gnucashPaymentDate=''; $appliedSummary=[];
        $mappedPaidTotal = 0.0; $anyPaidTotal = 0.0;
        foreach((array)($state['payment_accounts'] ?? []) as $pa){
            $paDate = normalize_import_date((string)($pa['date'] ?? ''));
            $paAccount = (string)($pa['account'] ?? '');
            $paAmount = abs((float)($pa['amount'] ?? 0));
            $anyPaidTotal += $paAmount;
            $appliedSummary[] = trim(($paDate !== '' ? $paDate : ($pa['date'] ?? '')) . ' ' . $paAccount . ' $' . fmt_money($paAmount));
            if(abs($paAmount-abs($amt)) <= 0.02) {
                $amountExactAnyAccount=true;
                if($gnucashPaymentDate==='') $gnucashPaymentDate=$paDate;
            }
            if($acct !== '' && account_names_equivalent($paAccount, $acct)){
                $mappedFound=true;
                $mappedPaidTotal += $paAmount;
                if($gnucashPaymentDate==='') $gnucashPaymentDate=$paDate;
                if(abs($paAmount-abs($amt)) <= 0.02) { $mappedExact=true; $gnucashPaymentDate=$paDate; }
            }
        }
        if($gnucashPaymentDate==='') $gnucashPaymentDate = first_payment_date_from_state($state, $acct);
        $dateWarnings=[];
        $diffPay = date_diff_days($paymentDate, $gnucashPaymentDate);
        if($diffPay !== null && abs($diffPay) > 3) $dateWarnings[]='GnuCash payment date differs from Amazon transaction date by '.abs($diffPay).' day(s).';
        $diffOrder = date_diff_days($orderDate, $paymentDate);
        if($diffOrder !== null && abs($diffOrder) > 3) $dateWarnings[]='Amazon payment date differs from order date by '.abs($diffOrder).' day(s).';
        if($targetExcluded) {
            $status='target_excluded_from_matching';
            $notes .= ' Target invoice/order is excluded from transaction matching by user. ' . $targetExcludeReason;
        } elseif($status==='excluded_payment_method') {
            if(($state['found'] ?? false) && !($state['posted'] ?? false)) {
                $status='excluded_invoice_unposted_ignored';
                $notes .= ' Excluded/out-of-book payment method and matching GnuCash bill exists but is unposted; treated as ignored/out-of-scope and omitted from exception/apply reports.';
            } elseif(($state['found'] ?? false)) {
                $status='excluded_invoice_exists_review';
                $notes .= ' Excluded/out-of-book payment method, but target bill/credit exists and is posted in GnuCash; review whether to unpost/delete or mark out-of-scope.';
            }
            else { $status='excluded_payment_method'; $notes .= ' Excluded method; no payment application planned and no target bill/credit found.'; }
        } elseif($legacyCreditSameId) {
            $status='legacy_credit_same_id_found_review';
            $notes .= ' Legacy same-ID credit memo/bill detected. Leave as review-only unless you intentionally accept this legacy ID, or rename/recreate it as '.$defaultTarget.' for unique bill/credit matching.';
        } elseif(!empty($state['malformed_invoice_id'])) {
            $status='malformed_invoice_id_found_review';
            $notes .= ' Exact invoice ID was not found, but GnuCash contains a malformed/contains match: [' . (string)$state['malformed_invoice_id'] . ']. Fix the bill ID in GnuCash before importing a duplicate or applying payment automation.';
        } elseif(!empty($state['ambiguous_duplicate_posted'])) {
            $status='duplicate_invoice_id_review';
            $notes .= ' Multiple posted GnuCash invoice records share this visible ID. The tool cannot safely choose which one to pay/apply without a unique ID cleanup.';
        } elseif(!($state['found'] ?? false)) {
            $status='invoice_missing'; $notes .= ' Target GnuCash bill/credit memo not found: '.$target.'.';
        } elseif(!($state['posted'] ?? false)) {
            $status='invoice_unposted'; $notes .= ' Target bill exists but is not posted; payment cannot be applied until posted.';
        } elseif($state['paid'] ?? false) {
            $groupSum = (float)($group['sum'] ?? abs($amt));
            if($mappedFound) {
                if($mappedExact || ($groupSum > 0.005 && abs($mappedPaidTotal - $groupSum) <= 0.02)) {
                    $status = 'already_applied_ok';
                    $notes .= $mappedExact ? ' Invoice/credit memo is already paid and uses the mapped account.' : ' Invoice/credit memo is already paid; grouped mapped-account payments total $'.fmt_money($mappedPaidTotal).' and match grouped Amazon payment rows $'.fmt_money($groupSum).'.';
                } else {
                    $status = 'already_paid_mapped_account_amount_diff';
                    $notes .= ' Invoice/credit memo is already paid and uses the mapped account, but individual/grouped amounts need review. Mapped-account paid total $'.fmt_money($mappedPaidTotal).'; grouped Amazon payment rows $'.fmt_money($groupSum).'.';
                }
            }
            else {
                $status='paid_wrong_or_unverified_account';
                $notes .= ' Invoice/credit memo is already paid, but no payment split using the mapped account was found; review before second pass.';
                if($groupSum > 0.005 && abs($anyPaidTotal - $groupSum) <= 0.02) $notes .= ' Total paid amount matches grouped Amazon payment rows, but through a different/unmapped account.';
            }
        } else {
            if($acct==='') { $status='missing_payment_account_mapping'; $notes .= ' Payment method needs a mapped GnuCash account.'; }
            elseif(abs((float)$group['sum'] - abs($expected)) <= 0.02) { $status = ((int)$group['rows'] > 1) ? 'ready_split_payment_group' : 'ready_exact_payment'; $notes .= ' Unpaid posted bill/credit memo; grouped payment amount matches target total.'; }
            elseif($expected > 0.005) { $status='amount_mismatch_review'; $notes .= ' Unpaid posted bill/credit memo, but grouped payments $'.fmt_money((float)$group['sum']).' differ from target $'.fmt_money(abs($expected)).'.'; }
            else { $status='ready_needs_amount_review'; $notes .= ' Unpaid posted bill/credit memo found, but target amount could not be calculated from the book.'; }
        }
        if($dateWarnings) $notes .= ' Date warning: '.implode(' ', $dateWarnings);
        $r['order_date']=$orderDate; $r['payment_date']=$paymentDate; $r['gnucash_payment_date']=$gnucashPaymentDate; $r['date_warning']=implode(' ', $dateWarnings);
        $r['target_invoice_id']=$target; $r['book_invoice_total']=$expected; $r['book_lot_balance']=(float)($state['lot_balance'] ?? 0); $r['applied_payment_summary']=implode('; ', array_slice($appliedSummary,0,4)); $r['match_status']=$status; $r['notes']=trim($notes);
        $plan[]=$r;
    }
    return $planCache[$cacheKey] = $plan;
}
function export_payment_application_plan(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath=''): int {
    $rows = build_payment_application_plan($db, $vendor, $gnucashPath); $fh=fopen($outPath,'w'); if(!$fh) return 0;
    fputcsv($fh, ['vendor','order_id','order_id_text','target_invoice_id','target_invoice_id_text','order_date','vendor_payment_date','gnucash_payment_date','payment_method','mapped_account','amount_signed','amount_abs','status','book_invoice_total','book_lot_balance','applied_payment_summary','date_warning','notes']);
    foreach($rows as $r){ if(in_array(($r['match_status'] ?? ''), ['excluded_payment_method','excluded_invoice_exists_review','excluded_invoice_unposted_ignored','target_excluded_from_matching'], true)) continue; fputcsv($fh, [$r['vendor'],$r['order_id'],csv_spreadsheet_safe_id((string)$r['order_id']),$r['target_invoice_id'],csv_spreadsheet_safe_id((string)$r['target_invoice_id']),$r['order_date'] ?? '',$r['payment_date'] ?? '',$r['gnucash_payment_date'] ?? '',$r['payment_method'],$r['account_fullname'],fmt_money((float)$r['amount']),fmt_money(abs((float)$r['amount'])),$r['match_status'],fmt_money((float)($r['book_invoice_total']??0)),fmt_money((float)($r['book_lot_balance']??0)),$r['applied_payment_summary'],$r['date_warning'] ?? '',$r['notes']]); }
    fclose($fh); return count($rows);
}
function payment_exception_rows(SQLite3 $db, string $vendor='amazon', string $gnucashPath=''): array {
    $rows = build_payment_application_plan($db, $vendor, $gnucashPath);
    $out=[]; $ok=['already_applied_ok','ready_exact_payment','ready_split_payment_group','excluded_payment_method','excluded_invoice_exists_review','excluded_invoice_unposted_ignored','target_excluded_from_matching'];
    $stagedStmt = $db->prepare('SELECT skip, warning FROM orders WHERE vendor=:vendor AND order_id=:oid LIMIT 1');
    foreach($rows as $r){
        $status=(string)($r['match_status'] ?? ''); if(in_array($status,$ok,true)) continue;
        $oid=(string)($r['order_id'] ?? ''); $targetForStage=(string)($r['target_invoice_id'] ?? $oid); $staged='not_staged'; $warn='';
        foreach (array_values(array_unique(array_filter([$targetForStage, $oid]))) as $stageOid) {
            $stagedStmt->bindValue(':vendor',$vendor,SQLITE3_TEXT);
            $stagedStmt->bindValue(':oid',$stageOid,SQLITE3_TEXT);
            $sr=$stagedStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if($sr){ $staged=((int)($sr['skip']??0)===1?'staged_skipped':'staged_exportable'); $warn=(string)($sr['warning']??''); break; }
        }
        $recommended='Manual review';
        if($status==='invoice_missing') $recommended = $staged==='staged_exportable' ? 'Import/export this staged invoice or rerun bill import batch.' : 'Find missing order in vendor export, import the missing bill/credit, or mark out-of-scope.';
        elseif($status==='excluded_invoice_exists_review') $recommended = 'Out-of-book/business invoice exists in GnuCash and is posted. Review manually; possible future automation is unpost/delete on a copied book only.';
        elseif($status==='excluded_invoice_unposted_ignored') $recommended = 'Out-of-book invoice is already unposted; ignored/out-of-scope. No action needed.';
        elseif($status==='target_excluded_from_matching') $recommended = 'Target is explicitly excluded from payment matching. No action needed unless you re-enable it.';
        elseif($status==='legacy_credit_same_id_found_review') $recommended = 'Legacy credit memo uses the raw order ID instead of ORDERID-CREDIT. Review/rename/recreate for unique matching, or explicitly accept legacy ID in a later pass.';
        elseif($status==='malformed_invoice_id_found_review') $recommended = 'GnuCash contains a non-exact/malformed invoice ID. Search by date/order, fix the ID in GnuCash, then re-run validation before importing duplicates.';
        elseif($status==='duplicate_invoice_id_review') $recommended = 'Multiple posted GnuCash invoices share this visible ID. Rename/recreate to unique bill and credit IDs before payment automation.';
        elseif($status==='paid_wrong_or_unverified_account') $recommended = 'Review actual paid account. Accept alternate mapping if intentional; repair manually if genuinely wrong.';
        elseif($status==='already_paid_mapped_account_amount_diff') $recommended = 'Review amount difference; likely split/partial or duplicate applied payment.';
        elseif($status==='amount_mismatch_review') $recommended = 'Compare grouped vendor payments to bill total and resolve missing/extra payment rows.';
        elseif($status==='invoice_unposted') $recommended = 'Post invoice before payment apply, or exclude if out-of-scope.';
        elseif($status==='missing_payment_account_mapping') $recommended = 'Map the payment method or exclude/out-of-book it.';
        $r['staged_status']=$staged; $r['staged_warning']=$warn; $r['recommended_action']=$recommended; $out[]=$r;
    }
    return $out;
}
function export_payment_exception_report(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath=''): int {
    $rows=payment_exception_rows($db,$vendor,$gnucashPath); $fh=fopen($outPath,'w'); if(!$fh) return 0;
    fputcsv($fh, ['vendor','order_id','order_id_text','target_invoice_id','target_invoice_id_text','order_date','vendor_payment_date','gnucash_payment_date','payment_method','mapped_account','amount_signed','amount_abs','status','staged_status','book_invoice_total','book_lot_balance','applied_payment_summary','recommended_action','date_warning','notes','staged_warning']);
    foreach($rows as $r) fputcsv($fh, [$r['vendor'],$r['order_id'],csv_spreadsheet_safe_id((string)$r['order_id']),$r['target_invoice_id'],csv_spreadsheet_safe_id((string)$r['target_invoice_id']),$r['order_date'] ?? '',$r['payment_date'] ?? '',$r['gnucash_payment_date'] ?? '',$r['payment_method'],$r['account_fullname'],fmt_money((float)$r['amount']),fmt_money(abs((float)$r['amount'])),$r['match_status'],$r['staged_status'],fmt_money((float)($r['book_invoice_total']??0)),fmt_money((float)($r['book_lot_balance']??0)),$r['applied_payment_summary'],$r['recommended_action'],$r['date_warning'] ?? '',$r['notes'],dedupe_warning_text((string)$r['staged_warning'])]);
    fclose($fh); return count($rows);
}
function prime_young_display_discrepancy_rows(SQLite3 $db, array $exceptionRows): array {
    $out = [];
    $seen = [];
    foreach ($exceptionRows as $r) {
        if ((string)($r['vendor'] ?? 'amazon') !== 'amazon') continue;
        $status = (string)($r['match_status'] ?? '');
        if ($status !== 'amount_mismatch_review') continue;
        $oid = trim((string)($r['order_id'] ?? $r['target_invoice_id'] ?? ''));
        if ($oid === '' || isset($seen[$oid])) continue;
        $stmt = $db->prepare('SELECT * FROM orders WHERE vendor="amazon" AND order_id=:oid LIMIT 1');
        $stmt->bindValue(':oid', $oid, SQLITE3_TEXT);
        $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$o) continue;
        $hay = (string)($o['payments'] ?? '') . ' ' . (string)($o['warning'] ?? '') . ' ' . (string)($o['notes'] ?? '');
        if (!amazon_text_mentions_prime_young_adults_cashback($hay)) continue;
        $gross = round(order_bill_total_for_validation($o), 2);
        $displayed = round((float)($o['total'] ?? 0.0), 2);
        $cashback = round(max(0.0, $gross - $displayed), 2);
        if ($cashback <= 0.005) continue;

        $pay = $db->prepare('SELECT payment_id, payment_date, payment_method, amount, notes
                             FROM vendor_payments WHERE vendor="amazon" AND order_id=:oid
                             ORDER BY payment_date, ABS(amount) DESC');
        $pay->bindValue(':oid', $oid, SQLITE3_TEXT);
        $pres = $pay->execute();
        $rows = [];
        $synthetic = 0.0;
        $card = 0.0;
        while ($p = $pres->fetchArray(SQLITE3_ASSOC)) {
            $method = (string)($p['payment_method'] ?? '');
            $amt = round(abs((float)($p['amount'] ?? 0.0)), 2);
            if ($amt <= 0.005) continue;
            if (amazon_text_mentions_prime_young_adults_cashback($method) || str_contains((string)($p['notes'] ?? ''), 'Synthetic Amazon stored-value row inferred')) $synthetic += $amt;
            elseif (!is_stored_value_payment_method($method)) $card += $amt;
            $rows[] = $p;
        }
        $out[] = [
            'order_id'=>$oid,
            'order_date'=>(string)($o['order_date'] ?? ''),
            'order_url'=>(string)($o['order_url'] ?? ''),
            'displayed_total'=>$displayed,
            'gross_total'=>$gross,
            'cashback'=>$cashback,
            'card_payment_total'=>round($card,2),
            'synthetic_prime_total'=>round($synthetic,2),
            'status'=>$status,
            'rows'=>$rows,
        ];
        $seen[$oid] = true;
    }
    return $out;
}

function payment_excluded_invoice_rows(SQLite3 $db, string $vendor='amazon', string $gnucashPath=''): array {
    $rows = build_payment_application_plan($db, $vendor, $gnucashPath);
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $status = (string)($r['match_status'] ?? '');
        if (!in_array($status, ['excluded_payment_method','excluded_invoice_exists_review','excluded_invoice_unposted_ignored','target_excluded_from_matching'], true)) continue;
        $key = implode('|', [
            (string)($r['vendor'] ?? $vendor),
            (string)($r['order_id'] ?? ''),
            (string)($r['target_invoice_id'] ?? ''),
            (string)($r['payment_method'] ?? ''),
            (string)($r['amount'] ?? '')
        ]);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $recommended = 'Excluded/out-of-book method; no payment application planned.';
        if ($status === 'excluded_invoice_exists_review') {
            $recommended = 'Excluded/out-of-book payment method, but the matching GnuCash invoice is POSTED. Review in GnuCash and unpost/remove from this book if it belongs to a business/out-of-book account.';
        } elseif ($status === 'excluded_invoice_unposted_ignored') {
            $recommended = 'Excluded/out-of-book payment method and matching GnuCash invoice is unposted. Ignored/out-of-scope; no action needed.';
        } elseif ($status === 'target_excluded_from_matching') {
            $recommended = 'Target was manually excluded from transaction matching. Re-enable it only if you want the wizard to consider this invoice/payment again.';
        }
        $r['posted_flag'] = ($status === 'excluded_invoice_exists_review') ? 'POSTED' : (($status === 'excluded_invoice_unposted_ignored') ? 'not posted' : (($status === 'target_excluded_from_matching') ? 'target excluded' : 'not in book'));
        $r['recommended_action'] = $recommended;
        $out[] = $r;
    }
    return $out;
}
function export_payment_excluded_invoice_report(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath=''): int {
    $rows = payment_excluded_invoice_rows($db, $vendor, $gnucashPath);
    $fh = fopen($outPath, 'w'); if (!$fh) return 0;
    fputcsv($fh, ['vendor','order_id','target_invoice_id','order_date','amazon_payment_date','gnucash_payment_date','payment_method','mapped_account','amount_signed','amount_abs','status','posted_flag','book_invoice_total','applied_payment_summary','recommended_action','notes']);
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['vendor'] ?? $vendor,
            $r['order_id'] ?? '',
            $r['target_invoice_id'] ?? '',
            $r['order_date'] ?? '',
            $r['payment_date'] ?? '',
            $r['gnucash_payment_date'] ?? '',
            $r['payment_method'] ?? '',
            $r['account_fullname'] ?? '',
            fmt_money((float)($r['amount'] ?? 0)),
            fmt_money(abs((float)($r['amount'] ?? 0))),
            $r['match_status'] ?? '',
            $r['posted_flag'] ?? '',
            fmt_money((float)($r['book_invoice_total'] ?? 0)),
            $r['applied_payment_summary'] ?? '',
            $r['recommended_action'] ?? '',
            $r['notes'] ?? ''
        ]);
    }
    fclose($fh); return count($rows);
}
function is_stored_value_payment_method(string $method): bool {
    $method = strtolower($method);
    return str_contains($method,'gift') || str_contains($method,'point') || str_contains($method,'reward') || str_contains($method,'cash back') || str_contains($method,'stored value') || str_contains($method, 'my lowe') || str_contains($method, 'mylowe');
}
function stored_value_payment_kind(string $method): string {
    $m = strtolower(trim($method));
    // GnuCash imports account-register CSVs one account at a time. Keep vendor-specific
    // stored-value streams separate where practical.
    if (str_contains($m, 'my lowe') || str_contains($m, 'mylowe')) return 'mylowes_money';
    if (amazon_text_mentions_prime_young_adults_cashback($m)) return 'prime_young_cashback';
    if (str_contains($m, 'gift')) return 'gift_card';
    if (str_contains($m, 'point') || str_contains($m, 'reward') || str_contains($m, 'cash back')) return 'rewards';
    if (str_contains($m, 'stored value')) return 'stored_value_other';
    return '';
}
function stored_value_kind_label(string $kind): string {
    return match($kind) {
        'gift_card' => 'gift card',
        'mylowes_money' => "My Lowe's Money",
        'prime_young_cashback' => 'Prime for Young Adults cash back',
        'rewards' => 'rewards/cash-back',
        'stored_value_other' => 'stored value',
        default => 'stored value',
    };
}
function stored_value_vendor_label(string $vendor): string {
    $label = vendor_config($vendor)['label'] ?? ucfirst($vendor);
    return (string)$label;
}
function stored_value_default_account_for_vendor(string $vendor): string {
    if ($vendor === 'lowes') return default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES');
    return default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON');
}
function export_stored_value_account_activity(SQLite3 $db, string $outPath, string $vendor='amazon'): int {
    // Stored-value exports should reflect the currently staged vendor evidence.
    // Do not silently suppress prior-year My Lowe's Money rows because the Step 5
    // payment-matching date window is set to a different year.
    $rows = payment_match_rows($db, $vendor, 100000, false, false); $fh=fopen($outPath,'w'); if(!$fh) return 0;
    $label = stored_value_vendor_label($vendor);
    fputcsv($fh, ['date','description','account','amount','order_id','payment_method','notes']); $n=0;
    foreach($rows as $r){ $method=(string)($r['payment_method'] ?? ''); $acct=(string)($r['account_fullname'] ?? ''); if(!is_stored_value_payment_method($method)) continue; fputcsv($fh, [$r['payment_date'], $label.' stored-value activity for order '.$r['order_id'], $acct, fmt_money((float)$r['amount']), $r['order_id'], $r['payment_method'], $r['notes']]); $n++; }
    fclose($fh); return $n;
}
function stored_value_payment_export_ref(array $r): string {
    $notes = (string)($r['notes'] ?? '');
    $pid = preg_replace('/[^a-fA-F0-9]/', '', (string)($r['payment_id'] ?? ''));
    $identifier = '';
    if (preg_match('/identifier=([^;\s]+)/', $notes, $m)) $identifier = trim((string)$m[1]);
    if ($identifier === '') {
        $method = (string)($r['payment_method'] ?? '');
        if (preg_match('/(?:^|[^0-9])(\d{4})(?:[^0-9]|$)/', $method, $m)) $identifier = (string)$m[1];
    }
    $row = '';
    if (preg_match('/row_key=([^;]+)/', $notes, $m)) {
        $rk = trim((string)$m[1]);
        if (preg_match('/^(row\d{3})/i', $rk, $rm)) $row = strtoupper((string)$rm[1]);
    }
    $parts = [];
    if ($identifier !== '') {
        $digits = preg_replace('/\D+/', '', $identifier);
        $parts[] = 'GC' . (($digits !== '' && strlen($digits) >= 4) ? substr($digits, -4) : preg_replace('/[^A-Za-z0-9]+/', '', $identifier));
    }
    if ($row !== '') $parts[] = $row;
    if (!$parts && $pid !== '') $parts[] = 'PID' . substr($pid, 0, 8);
    return $parts ? implode('-', array_unique($parts)) : 'SV' . substr(sha1(json_encode($r)), 0, 8);
}

function export_stored_value_gnucash_transaction_csv(SQLite3 $db, string $outPath, string $vendor='amazon', string $offsetAccount='', string $kind='all'): int {
    $offsetAccount = trim($offsetAccount) ?: default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT');
    // See export_stored_value_account_activity(): use all staged stored-value
    // evidence for the vendor, independent of the payment-matching date window.
    $rows = payment_match_rows($db, $vendor, 100000, false, false);
    $fh = fopen($outPath, 'w'); if(!$fh) return 0;
    // Generic GnuCash transaction-import-friendly CSV.  Import into the stored-value
    // account or map Account/Transfer Account manually.  Amount signs are preserved;
    // separate deposit/withdrawal columns are included for users who prefer that importer mode.
    fputcsv($fh, ['Date','Num','Description','Account','Transfer Account','Amount Signed','Withdrawal','Deposit','Order ID','Payment Method','Unique Payment Ref','Notes']);
    $n=0;
    foreach($rows as $r){
        $method=(string)($r['payment_method'] ?? '');
        if(!is_stored_value_payment_method($method)) continue;
        $methodKind = stored_value_payment_kind($method);
        if($kind !== 'all' && $methodKind !== $kind) continue;
        $amount=(float)($r['amount'] ?? 0);
        if(abs($amount) < 0.0001) continue;
        $acct=trim((string)($r['account_fullname'] ?? '')) ?: stored_value_default_account_for_vendor($vendor);
        $oid=(string)($r['order_id'] ?? '');
        $direction = $amount < 0 ? 'used for order' : 'refund/credit from order';
        $label = stored_value_kind_label($methodKind ?: $kind);
        $uniq = stored_value_payment_export_ref($r);
        // v172: include a unique per-tender reference in Num/Description so GnuCash
        // transaction import and duplicate matching do not collapse two identical
        // $20/$5 My Lowe's Money rows from the same order/date into one register row.
        $num = $oid . '-' . ($methodKind === 'mylowes_money' ? 'MLM' : 'SV') . '-' . $uniq;
        $desc = stored_value_vendor_label($vendor).' '.$label.' '.$direction.' '.$oid.' ['.$uniq.']';
        $withdrawal = $amount < 0 ? fmt_money(abs($amount)) : '';
        $deposit = $amount > 0 ? fmt_money(abs($amount)) : '';
        fputcsv($fh, [
            $r['payment_date'] ?? '',
            $num,
            $desc,
            $acct,
            $offsetAccount,
            fmt_money($amount),
            $withdrawal,
            $deposit,
            csv_spreadsheet_safe_id($oid),
            $method,
            $uniq,
            trim((string)($r['notes'] ?? '') . ' payment_id=' . (string)($r['payment_id'] ?? '') . ' Generated from '.stored_value_vendor_label($vendor).' stored-value/payment evidence; unique tender ref is included to prevent duplicate same-denomination stored-value rows from being collapsed during import. Verify sign/import direction for liability vs asset account before posting.')
        ]);
        $n++;
    }
    fclose($fh); return $n;
}


function export_payment_method_gnucash_transaction_csv(SQLite3 $db, string $outPath, string $vendor, string $methodKey, string $accountFullname = '', string $offsetAccount = ''): int {
    $vendor = strtolower(trim($vendor));
    $methodKey = trim($methodKey);
    $accountFullname = trim($accountFullname);
    $offsetAccount = trim($offsetAccount) ?: default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT');

    if ($vendor === '' || $methodKey === '') return 0;

    $stmt = $db->prepare('SELECT p.*, COALESCE(NULLIF(p.account_fullname,""), NULLIF(m.account_fullname,""), :fallback_account) AS export_account,
                                 COALESCE(NULLIF(m.display_name,""), NULLIF(p.payment_method,""), p.method_key) AS export_method
                          FROM vendor_payments p
                          LEFT JOIN payment_method_accounts m ON m.vendor=p.vendor AND m.method_key=p.method_key
                          WHERE p.vendor=:vendor AND p.method_key=:method_key
                          ORDER BY p.payment_date, p.order_id, p.payment_id');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':method_key', $methodKey, SQLITE3_TEXT);
    $stmt->bindValue(':fallback_account', $accountFullname, SQLITE3_TEXT);
    $rs = $stmt->execute();

    $fh = fopen($outPath, 'w');
    if (!$fh) return 0;

    fputcsv($fh, ['Date','Num','Description','Account','Transfer Account','Amount Signed','Withdrawal','Deposit','Order ID','Payment Method','Unique Payment Ref','Notes']);

    $n = 0;
    while ($r = $rs->fetchArray(SQLITE3_ASSOC)) {
        $amount = (float)($r['amount'] ?? 0);
        if (abs($amount) < 0.0001) continue;

        $method = trim((string)($r['export_method'] ?? $r['payment_method'] ?? $methodKey));
        $acct = trim($accountFullname !== '' ? $accountFullname : (string)($r['export_account'] ?? ''));
        if ($acct === '') continue;

        $oid = (string)($r['order_id'] ?? '');
        $uniq = stored_value_payment_export_ref($r);
        $safeMethod = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $methodKey), 0, 16));
        if ($safeMethod === '') $safeMethod = 'PM';
        $num = trim($oid . '-' . $safeMethod . '-' . $uniq, '-');
        $direction = $amount < 0 ? 'charge/use for order' : 'refund/credit from order';
        $desc = stored_value_vendor_label($vendor) . ' ' . $method . ' ' . $direction . ' ' . $oid . ' [' . $uniq . ']';
        $withdrawal = $amount < 0 ? fmt_money(abs($amount)) : '';
        $deposit = $amount > 0 ? fmt_money(abs($amount)) : '';
        $transfer = is_stored_value_payment_method($method) ? $offsetAccount : '';

        fputcsv($fh, [
            $r['payment_date'] ?? '',
            $num,
            $desc,
            $acct,
            $transfer,
            fmt_money($amount),
            $withdrawal,
            $deposit,
            csv_spreadsheet_safe_id($oid),
            $method,
            $uniq,
            trim((string)($r['notes'] ?? '') . ' payment_id=' . (string)($r['payment_id'] ?? '') . ' Generated from ' . stored_value_vendor_label($vendor) . ' payment-method evidence for one mapped GnuCash account/register. GnuCash imports one account/register file at a time; verify signs and transfer account before posting.')
        ]);
        $n++;
    }

    fclose($fh);
    if ($n === 0) @unlink($outPath);
    return $n;
}

function payment_plan_status_counts(array $rows): array {
    $counts = [];
    foreach ($rows as $r) {
        $s = (string)($r['match_status'] ?? 'unknown');
        $counts[$s] = ($counts[$s] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}
function payment_wizard_step_number(string $step): int {
    $map = ['start'=>1,'missing'=>2,'exceptions'=>3,'ready'=>4,'apply'=>5];
    return $map[$step] ?? 1;
}
function payment_wizard_next_recommended_step(array $planRows, array $exceptionRows): string {
    $missing = 0; $exceptions = 0; $ready = 0;
    foreach ($planRows as $r) {
        $s = (string)($r['match_status'] ?? '');
        if ($s === 'invoice_missing') $missing++;
        elseif (in_array($s, ['ready_exact_payment','ready_split_payment_group'], true)) $ready++;
        elseif (!in_array($s, ['already_applied_ok','excluded_payment_method','excluded_invoice_unposted_ignored','target_excluded_from_matching'], true)) $exceptions++;
    }
    if ($missing > 0) return 'missing';
    if ($exceptions > 0) return 'exceptions';
    if ($ready > 0) return 'ready';
    return 'apply';
}
function missing_invoice_rows(SQLite3 $db, string $vendor, string $gnucashPath): array {
    $rows = [];
    foreach (payment_exception_rows($db, $vendor, $gnucashPath) as $r) {
        if ((string)($r['match_status'] ?? '') === 'invoice_missing') $rows[] = $r;
    }
    $seen = []; $dedup = [];
    foreach ($rows as $r) {
        $key = (string)($r['vendor'] ?? $vendor) . '|' . (string)($r['target_invoice_id'] ?? $r['order_id'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $dedup[] = $r;
    }
    return $dedup;
}
function staged_status_for_target(SQLite3 $db, string $vendor, string $target, string $orderId = ''): array {
    $stmt = $db->prepare('SELECT vendor, order_id, order_date, skip, warning, total FROM orders WHERE vendor=:vendor AND order_id=:oid LIMIT 1');
    foreach (array_values(array_unique(array_filter([$target, $orderId]))) as $oid) {
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $stmt->bindValue(':oid', $oid, SQLITE3_TEXT);
        $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($r) return ['found'=>true, 'exportable'=>((int)($r['skip'] ?? 0)===0), 'row'=>$r];
    }
    return ['found'=>false, 'exportable'=>false, 'row'=>null];
}
function missing_invoice_wizard_rows(SQLite3 $db, string $vendor, string $gnucashPath): array {
    $out = [];
    foreach (missing_invoice_rows($db, $vendor, $gnucashPath) as $r) {
        $target = (string)($r['target_invoice_id'] ?? $r['order_id'] ?? '');
        $oid = (string)($r['order_id'] ?? '');
        $stage = staged_status_for_target($db, (string)($r['vendor'] ?? $vendor), $target, $oid);
        $r['can_export_missing_invoice'] = ($stage['found'] && $stage['exportable']) ? 1 : 0;
        $r['staged_status'] = $stage['found'] ? ($stage['exportable'] ? 'staged_exportable' : 'staged_skipped') : 'not_staged';
        $r['staged_warning'] = $stage['found'] ? (string)(($stage['row']['warning'] ?? '')) : '';
        $out[] = $r;
    }
    return $out;
}

function missing_credit_memo_wizard_rows(SQLite3 $db, string $vendor, string $gnucashPath): array {
    // Invoice-repair view helper: show expected Amazon ORDERID-CREDIT memos that are not
    // present in GnuCash yet, even before payment/refund matching.  These must be imported
    // as separate negative credit memos before Step 6 can apply refund transactions.
    $rows = [];
    foreach (vendor_ledger_diff_rows($db, $vendor, $gnucashPath, normalize_date_sort((string)($_GET['date_sort'] ?? $_POST['date_sort'] ?? 'asc'))) as $r) {
        $target = (string)($r['target_id'] ?? '');
        if (!str_ends_with($target, '-CREDIT')) continue;
        if ((string)($r['status'] ?? '') !== 'missing_in_book') continue;
        $stage = staged_status_for_target($db, (string)($r['vendor'] ?? $vendor), $target, '');
        $r['target_invoice_id'] = $target;
        $r['can_export_missing_invoice'] = ($stage['found'] && $stage['exportable']) ? 1 : 0;
        $r['staged_status'] = $stage['found'] ? ($stage['exportable'] ? 'staged_exportable' : 'staged_skipped') : 'not_staged';
        $r['staged_warning'] = $stage['found'] ? (string)(($stage['row']['warning'] ?? '')) : '';
        $rows[] = $r;
    }
    return $rows;
}

function account_review_rows_for_stage_targets(SQLite3 $db, array $targets): array {
    // Generic category-review helper for staged invoice/credit exports.  Used by
    // both the payment wizard's missing-invoice CSV and the invoice repair
    // wizard's missing CREDIT memo CSV.  The export CSV must never contain
    // placeholder categories such as Expenses:Uncategorized, because GnuCash will
    // skip the entire bill/credit memo if that account does not exist.
    $out = [];
    foreach ($targets as $t) {
        $v = strtolower(trim((string)($t['vendor'] ?? '')));
        $oid = trim((string)($t['order_id'] ?? ''));
        $target = trim((string)($t['target_invoice_id'] ?? $oid));
        if ($v === '' || $oid === '') continue;
        $itemRes = $db->query("SELECT vendor, order_id, item_key, order_url, description, quantity, unit_price, source_amount, expense_account, notes FROM order_items WHERE vendor='" . SQLite3::escapeString($v) . "' AND order_id='" . SQLite3::escapeString($oid) . "' AND skip=0 ORDER BY description");
        $foundItem = false;
        while ($it = $itemRes->fetchArray(SQLITE3_ASSOC)) {
            $foundItem = true;
            $out[] = [
                'kind'=>'item',
                'vendor'=>$v,
                'order_id'=>$oid,
                'target_invoice_id'=>$target,
                'item_key'=>(string)$it['item_key'],
                'order_url'=>(string)($it['order_url'] ?? ''),
                'description'=>(string)($it['description'] ?? ''),
                'amount'=>(float)($it['source_amount'] ?? ((float)$it['quantity'] * (float)$it['unit_price'])),
                'expense_account'=>(string)($it['expense_account'] ?? ''),
                'notes'=>(string)($it['notes'] ?? ''),
            ];
        }
        if (!$foundItem) {
            $stmt=$db->prepare('SELECT vendor, order_id, order_url, items, item_amount, expense_account, warning FROM orders WHERE vendor=:vendor AND order_id=:oid LIMIT 1');
            $stmt->bindValue(':vendor',$v,SQLITE3_TEXT); $stmt->bindValue(':oid',$oid,SQLITE3_TEXT);
            $or=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($or) {
                $desc = trim((string)($or['items'] ?? ''));
                $warn = trim((string)($or['warning'] ?? ''));
                if ($desc === '') {
                    $desc = 'Fallback item amount for order ' . $oid . ' (item-level rows missing from imported item export; open vendor order detail to identify the item/category)';
                    $warn = trim($warn . ' Item-level rows missing; fallback line will be exported unless the missing item export is re-imported.');
                }
                $out[] = [
                    'kind'=>'order',
                    'vendor'=>$v,
                    'order_id'=>$oid,
                    'target_invoice_id'=>$target,
                    'item_key'=>'__ORDER__',
                    'order_url'=>(string)($or['order_url'] ?? ''),
                    'description'=>$desc,
                    'amount'=>(float)($or['item_amount'] ?? 0),
                    'expense_account'=>(string)($or['expense_account'] ?? ''),
                    'notes'=>$warn,
                ];
            }
        }
    }
    return $out;
}

function export_account_review_issue(?string $acct, array $validAccounts): string {
    $acct = trim((string)$acct);
    if ($acct === '') return 'blank account';
    if (is_placeholder_account($acct)) return 'unreviewed placeholder account';
    // If no GnuCash account list is available, only placeholders can be blocked
    // reliably.  When an account list is loaded, require the account to exist.
    if ($validAccounts && !isset($validAccounts[$acct])) return 'account not found in selected GnuCash book';
    return '';
}

function invalid_account_review_rows(array $rows, array $validAccounts): array {
    $bad = [];
    foreach ($rows as $r) {
        $acct = trim((string)($r['expense_account'] ?? ''));
        $issue = export_account_review_issue($acct, $validAccounts);
        if ($issue !== '') $bad[] = $r + ['invalid_account'=>$acct === '' ? '(blank)' : $acct, 'invalid_reason'=>$issue];
    }
    return $bad;
}

function missing_credit_memo_stage_targets(SQLite3 $db, string $vendor, string $gnucashPath, array $sourceRows = []): array {
    $targets = [];
    $rows = $sourceRows ?: missing_credit_memo_wizard_rows($db, $vendor, $gnucashPath);
    foreach ($rows as $r) {
        $target = (string)($r['target_invoice_id'] ?? $r['target_id'] ?? '');
        if ($target === '') continue;
        $stage = staged_status_for_target($db, (string)($r['vendor'] ?? $vendor), $target, '');
        if ($stage['found'] && $stage['exportable']) {
            $targets[] = [
                'vendor'=>(string)($r['vendor'] ?? $vendor),
                'order_id'=>(string)$stage['row']['order_id'],
                'target_invoice_id'=>$target,
                'stage'=>$stage,
            ];
        }
    }
    return $targets;
}

function missing_credit_memo_account_review_rows(SQLite3 $db, string $vendor, string $gnucashPath): array {
    return account_review_rows_for_stage_targets($db, missing_credit_memo_stage_targets($db, $vendor, $gnucashPath));
}

function invalid_accounts_for_missing_credit_memo_targets(SQLite3 $db, string $vendor, string $gnucashPath, array $validAccounts): array {
    return invalid_account_review_rows(missing_credit_memo_account_review_rows($db, $vendor, $gnucashPath), $validAccounts);
}

function export_gnucash_bill_csv_for_targets(SQLite3 $db, string $outPath, array $targets, bool $withHeader = false, string $accountPrefix = '', bool $postInvoices = false, string $postingAccount = '', string $postDateFormat = 'mdy_slash', bool $includeSkipped = false): int {
    if (trim($postingAccount) === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT', $db);
    $want = [];
    foreach ($targets as $t) {
        if (is_array($t)) { $vendor = strtolower(trim((string)($t['vendor'] ?? ''))); $orderId = trim((string)($t['order_id'] ?? '')); }
        else { $parts = explode('|', (string)$t, 2); $vendor = strtolower(trim($parts[0] ?? '')); $orderId = trim($parts[1] ?? ''); }
        if ($vendor !== '' && $orderId !== '') $want[$vendor.'|'.$orderId] = true;
    }
    $fh = fopen($outPath, 'wb');
    if (!$fh) return 0;
    $cols = ['id','date_opened','owner_id','billingid','notes','date','desc','action','account','quantity','price','disc_type','disc_how','discount','taxable','taxincluded','tax_table','date_posted','due_date','account_posted','memo_posted','accu_splits'];
    if ($withHeader) fputcsv($fh, $cols);
    $whereSql = $includeSkipped ? '1=1' : 'skip=0';
    $res = $db->query('SELECT * FROM orders WHERE ' . $whereSql . ' ORDER BY ' . date_sort_sql() . ', CASE WHEN order_id LIKE "%-CREDIT" THEN 1 ELSE 0 END ASC, order_id');
    $n = 0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $vendor = (string)$r['vendor']; $orderIdRaw = (string)$r['order_id'];
         $itemRes = $db->query("SELECT * FROM order_items WHERE vendor='" . SQLite3::escapeString($vendor) . "' AND order_id='" . SQLite3::escapeString($orderIdRaw) . "' AND skip=0 ORDER BY description");
        $itemCount = 0;
        while ($it = $itemRes->fetchArray(SQLITE3_ASSOC)) {
            $qty = (float)$it['quantity']; if (abs($qty) < 0.0001) $qty = 1.0; $price = (float)$it['unit_price'];
            $acct = export_account_name($it['expense_account'] ?: 'Expenses:Uncategorized', $accountPrefix);
            fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,clean_text($it['description'] ?? '', 400),'',$acct,fmt_quantity($qty),fmt_unit_price($price, $qty),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']);
            $itemCount++; $n++;
        }
        if ($itemCount === 0) {
            $item_amount = (float)$r['item_amount'];
            $acct = export_account_name($r['expense_account'] ?: 'Expenses:Uncategorized', $accountPrefix);
            fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,clean_text($r['items'] ?? '', 400),'',$acct,'1',fmt_money($item_amount),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++;
        }
        $shippingCharge = (float)$r['shipping'];
        $shippingRefund = (float)$r['shipping_refund'];
        $shipAcct = export_account_name($r['shipping_account'] ?: default_config_value('DEFAULT_SHIPPING_ACCOUNT'), $accountPrefix);
        if (abs($shippingCharge) > 0.0001) { fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Shipping','',$shipAcct,'1',fmt_money($shippingCharge),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
        if (abs($shippingRefund) > 0.0001) { fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Free Shipping / Shipping promotion','',$shipAcct,'1',fmt_money(-abs($shippingRefund)),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
        $tax = (float)$r['tax'];
        if (abs($tax) > 0.0001) { $acct = export_account_name($r['tax_account'] ?: default_config_value('DEFAULT_TAX_ACCOUNT'), $accountPrefix); fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Sales tax','',$acct,'1',fmt_money($tax),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
    }
    fclose($fh); return $n;
}
function export_missing_invoice_bill_csv(SQLite3 $db, string $outPath, string $vendor, string $gnucashPath, string $accountPrefix = '', bool $postInvoices = false, string $postingAccount = '', string $postDateFormat = 'mdy_slash'): array {
    if (trim($postingAccount) === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT', $db);
    $missing = missing_invoice_wizard_rows($db, $vendor, $gnucashPath);
    $targets = [];
    $blocked = [];
    foreach ($missing as $r) {
        $target = (string)($r['target_invoice_id'] ?? $r['order_id'] ?? '');
        $oid = (string)($r['order_id'] ?? '');
        $stage = staged_status_for_target($db, (string)($r['vendor'] ?? $vendor), $target, $oid);
        if ($stage['found'] && $stage['exportable']) $targets[] = ['vendor'=>(string)($r['vendor'] ?? $vendor), 'order_id'=>(string)$stage['row']['order_id']];
        else $blocked[] = $r;
    }
    $validAccounts = load_valid_account_set($gnucashPath);
    $badAccounts = $targets ? invalid_accounts_for_missing_invoice_targets($db, $vendor, $gnucashPath, $validAccounts) : [];
    if ($badAccounts) return [0, count($targets), $blocked, $badAccounts];
    $rows = $targets ? export_gnucash_bill_csv_for_targets($db, $outPath, $targets, false, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat) : 0;
    return [$rows, count($targets), $blocked, []];
}

function missing_invoice_stage_targets(SQLite3 $db, string $vendor, string $gnucashPath): array {
    $targets = [];
    foreach (missing_invoice_wizard_rows($db, $vendor, $gnucashPath) as $r) {
        $target = (string)($r['target_invoice_id'] ?? $r['order_id'] ?? '');
        $oid = (string)($r['order_id'] ?? '');
        $stage = staged_status_for_target($db, (string)($r['vendor'] ?? $vendor), $target, $oid);
        if ($stage['found']) $targets[] = ['vendor'=>(string)($r['vendor'] ?? $vendor), 'order_id'=>(string)$stage['row']['order_id'], 'target_invoice_id'=>$target, 'stage'=>$stage];
    }
    return $targets;
}
function missing_invoice_account_review_rows(SQLite3 $db, string $vendor, string $gnucashPath): array {
    return account_review_rows_for_stage_targets($db, missing_invoice_stage_targets($db, $vendor, $gnucashPath));
}
function save_missing_invoice_account_edits(SQLite3 $db, array $request): int {
    $changed = 0; $accounts = (array)($request['missing_acct'] ?? []);
    $itemStmt = $db->prepare('UPDATE order_items SET expense_account=:acct, account_change_source="user_review", account_last_changed_at=CURRENT_TIMESTAMP WHERE vendor=:vendor AND order_id=:oid AND item_key=:ikey');
    $orderStmt = $db->prepare('UPDATE orders SET expense_account=:acct WHERE vendor=:vendor AND order_id=:oid');
    foreach ($accounts as $key => $acct) {
        $acct = trim((string)$acct); if ($acct === '') continue;
        $parts = explode('|', (string)$key, 3); if (count($parts) < 3) continue;
        [$vendor,$oid,$ikey] = $parts; $vendor=strtolower(trim($vendor)); $oid=trim($oid); $ikey=trim($ikey);
        if ($vendor==='' || $oid==='' || $ikey==='') continue;
        if ($ikey === '__ORDER__') { $orderStmt->bindValue(':acct',$acct,SQLITE3_TEXT); $orderStmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $orderStmt->bindValue(':oid',$oid,SQLITE3_TEXT); $orderStmt->execute(); }
        else { $itemStmt->bindValue(':acct',$acct,SQLITE3_TEXT); $itemStmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $itemStmt->bindValue(':oid',$oid,SQLITE3_TEXT); $itemStmt->bindValue(':ikey',$ikey,SQLITE3_TEXT); $itemStmt->execute(); }
        $changed++;
    }
    return $changed;
}
function original_order_id_for_credit(string $orderId): string {
    return preg_replace('/-CREDIT$/', '', $orderId);
}
function is_credit_memo_row(array $r): bool {
    return str_ends_with((string)($r['order_id'] ?? ''), '-CREDIT') || str_starts_with((string)($r['description'] ?? ''), '[CREDIT:');
}
function credit_return_item_options(SQLite3 $db, string $vendor, string $creditOrderId): array {
    $base = original_order_id_for_credit($creditOrderId);
    if ($base === $creditOrderId || $base === '') return [];
    $stmt = $db->prepare('SELECT item_key, description, quantity, unit_price, source_amount, expense_account, asin FROM order_items WHERE vendor=:vendor AND order_id=:base AND skip=0 AND description NOT LIKE "[CREDIT:%" AND description NOT LIKE "[DISCOUNT:%" ORDER BY description');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':base', $base, SQLITE3_TEXT);
    $res = $stmt->execute();
    $out = [];
    while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
        $amount = isset($r['source_amount']) && $r['source_amount'] !== null ? (float)$r['source_amount'] : ((float)$r['quantity'] * (float)$r['unit_price']);
        $out[] = ['item_key'=>(string)$r['item_key'], 'description'=>(string)$r['description'], 'amount'=>$amount, 'expense_account'=>(string)($r['expense_account'] ?? ''), 'asin'=>(string)($r['asin'] ?? '')];
    }
    return $out;
}
function credit_selected_item_keys(array $creditItem): array {
    $notes = (string)($creditItem['notes'] ?? '');
    if (preg_match('/Selected original item keys:\s*([^\.\n\r]+)/', $notes, $m)) {
        return array_values(array_filter(array_map('trim', explode(',', $m[1])), fn($x) => $x !== ''));
    }
    return [];
}
function apply_credit_return_item_selections(SQLite3 $db, array $post): int {
    $n = 0;
    foreach ((array)($post['credit_return_items'] ?? []) as $key=>$selected) {
        $parts = explode('|', (string)$key, 3);
        if (count($parts) !== 3) continue;
        [$vendor,$creditId,$creditItemKey] = $parts;
        $base = original_order_id_for_credit($creditId);
        $selected = array_values(array_filter(array_map('strval', (array)$selected), fn($x) => $x !== ''));
        if ($base === '' || !$selected) continue;
        $opts = credit_return_item_options($db, $vendor, $creditId);
        $byKey = [];
        foreach($opts as $o) $byKey[(string)$o['item_key']] = $o;
        $picked=[]; $sum=0.0; $accounts=[]; $asins=[];
        foreach($selected as $ik) {
            if(!isset($byKey[$ik])) continue;
            $o=$byKey[$ik]; $picked[]=$o; $sum += abs((float)$o['amount']);
            $acct=trim((string)$o['expense_account']); if($acct !== '') $accounts[$acct]=true;
            $asin=trim((string)$o['asin']); if($asin !== '') $asins[$asin]=true;
        }
        if(!$picked) continue;
        $cur = $db->prepare('SELECT unit_price, source_amount FROM order_items WHERE vendor=:vendor AND order_id=:credit AND item_key=:item_key');
        $cur->bindValue(':vendor',$vendor,SQLITE3_TEXT); $cur->bindValue(':credit',$creditId,SQLITE3_TEXT); $cur->bindValue(':item_key',$creditItemKey,SQLITE3_TEXT);
        $cr=$cur->execute()->fetchArray(SQLITE3_ASSOC);
        $existingAmount = (float)($cr['source_amount'] ?? $cr['unit_price'] ?? 0);
        $amount = abs($existingAmount) > 0.005 ? -abs($existingAmount) : -abs($sum);
        $descList = array_map(fn($o) => clean_text((string)$o['description'], 140) . ' ($' . fmt_money((float)$o['amount']) . ')', $picked);
        $desc = '[CREDIT:AMAZON] Refund / credit for order ' . $base . ' - returned item(s): ' . implode('; ', $descList);
        $acct = count($accounts) === 1 ? array_key_first($accounts) : 'Expenses:Uncategorized';
        $note = 'Credit memo returned item(s) selected manually from original order. Selected original item keys: ' . implode(',', $selected) . '. Original item subtotal selection: $' . fmt_money($sum) . '. ' . (count($accounts) > 1 ? 'Multiple categories selected; review account manually.' : '');
        $upd = $db->prepare('UPDATE order_items SET description=:desc, asin=:asin, unit_price=:amt, source_amount=:amt, expense_account=:acct, notes=:notes, match_reason="credit memo manually selected returned item(s) from original order" WHERE vendor=:vendor AND order_id=:credit AND item_key=:item_key');
        $upd->bindValue(':desc',$desc,SQLITE3_TEXT); $upd->bindValue(':asin',implode(',',array_keys($asins)),SQLITE3_TEXT); $upd->bindValue(':amt',$amount,SQLITE3_FLOAT); $upd->bindValue(':acct',$acct,SQLITE3_TEXT); $upd->bindValue(':notes',$note,SQLITE3_TEXT); $upd->bindValue(':vendor',$vendor,SQLITE3_TEXT); $upd->bindValue(':credit',$creditId,SQLITE3_TEXT); $upd->bindValue(':item_key',$creditItemKey,SQLITE3_TEXT); $upd->execute();
        $n++;
    }
    return $n;
}
function restore_skipped_missing_invoice_targets(SQLite3 $db, string $vendor, string $gnucashPath): int {
    $n = 0;
    $stmt = $db->prepare('UPDATE orders SET skip=0, warning=trim(replace(COALESCE(warning,""), "Existing bill/invoice ID found in GnuCash; marked skip to avoid duplicate import", "Restored by payment wizard missing-invoice recovery")) WHERE vendor=:vendor AND order_id=:oid AND skip=1');
    foreach (missing_invoice_stage_targets($db, $vendor, $gnucashPath) as $t) {
        $stage = $t['stage'];
        if (!($stage['found'] ?? false) || (int)($stage['row']['skip'] ?? 0) !== 1) continue;
        $warn = (string)($stage['row']['warning'] ?? '');
        if (stripos($warn, 'Existing bill/invoice ID found') === false) continue;
        $stmt->bindValue(':vendor',(string)$t['vendor'],SQLITE3_TEXT); $stmt->bindValue(':oid',(string)$t['order_id'],SQLITE3_TEXT); $stmt->execute(); $n++;
    }
    return $n;
}
function invalid_accounts_for_missing_invoice_targets(SQLite3 $db, string $vendor, string $gnucashPath, array $validAccounts): array {
    return invalid_account_review_rows(missing_invoice_account_review_rows($db, $vendor, $gnucashPath), $validAccounts);
}

function export_ready_payment_application_plan(SQLite3 $db, string $outPath, string $vendor='amazon', string $gnucashPath=''): int {
    $rows = build_payment_application_plan($db, $vendor, $gnucashPath);
    $fh=fopen($outPath,'w'); if(!$fh) return 0;
    // v173: include payment_id/row identity columns so duplicate same-order/same-amount
    // stored-value rows can be paired deterministically with existing GnuCash imports.
    fputcsv($fh, ['vendor','order_id','target_invoice_id','order_date','amazon_payment_date','gnucash_payment_date','payment_method','payment_id','unique_payment_ref','mapped_account','amount_signed','amount_abs','status','book_invoice_total','book_lot_balance','date_warning','notes']);
    $n=0;
    foreach($rows as $r){
        if(!in_array((string)($r['match_status'] ?? ''), ['ready_exact_payment','ready_split_payment_group'], true)) continue;
        $pid = (string)($r['payment_id'] ?? '');
        $uniqueRef = $pid !== '' ? $pid : trim((string)($r['vendor'] ?? $vendor)) . '|' . trim((string)($r['order_id'] ?? '')) . '|' . trim((string)($r['payment_date'] ?? '')) . '|' . trim((string)($r['payment_method'] ?? '')) . '|' . fmt_money(abs((float)($r['amount'] ?? 0)));
        fputcsv($fh, [$r['vendor'],$r['order_id'],$r['target_invoice_id'],$r['order_date'] ?? '',$r['payment_date'] ?? '',$r['gnucash_payment_date'] ?? '',$r['payment_method'],$pid,$uniqueRef,$r['account_fullname'],fmt_money((float)$r['amount']),fmt_money(abs((float)$r['amount'])),$r['match_status'],fmt_money((float)($r['book_invoice_total']??0)),fmt_money((float)($r['book_lot_balance']??0)),$r['date_warning'] ?? '',$r['notes']]);
        $n++;
    }
    fclose($fh); return $n;
}

function upsert_minimal_order(SQLite3 $db, string $vendor, string $order_id, string $date, string $url, string $items, array $validAccounts = []): void {
    $stmt = $db->prepare('INSERT INTO orders (vendor, order_id, order_url, order_date, items, expense_account, tax_account, shipping_account, warning, notes)
        VALUES (:vendor,:order_id,:order_url,:order_date,:items,:expense_account,:tax_account,:shipping_account,:warning,:notes)
        ON CONFLICT(vendor, order_id) DO UPDATE SET
          order_url=COALESCE(NULLIF(orders.order_url,""), excluded.order_url),
          order_date=COALESCE(NULLIF(orders.order_date,""), excluded.order_date),
          items=CASE WHEN orders.items IS NULL OR orders.items="" THEN excluded.items ELSE orders.items END');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
    $stmt->bindValue(':order_url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':order_date', $date, SQLITE3_TEXT);
    $stmt->bindValue(':items', $items, SQLITE3_TEXT);
    $stmt->bindValue(':expense_account', default_account_for_items($items, $validAccounts), SQLITE3_TEXT);
    $stmt->bindValue(':tax_account', default_config_value('DEFAULT_TAX_ACCOUNT'), SQLITE3_TEXT);
    $stmt->bindValue(':shipping_account', default_config_value('DEFAULT_SHIPPING_ACCOUNT'), SQLITE3_TEXT);
    $stmt->bindValue(':warning', 'Imported from item-level file only. Import the order-level file too if you want tax, shipping, refund, gift-card, and payment warnings included.', SQLITE3_TEXT);
    $stmt->bindValue(':notes', 'Amazon URL: ' . $url, SQLITE3_TEXT);
    $stmt->execute();
}

function upsert_account_rule(SQLite3 $db, string $vendor, string $matchType, string $matchKey, string $account, string $source='user_review', int $confidence=100): void {
    $vendor = strtolower(trim($vendor)); $matchType = strtolower(trim($matchType)); $matchKey = trim($matchKey); $account = trim($account);
    if ($vendor === '' || $matchType === '' || $matchKey === '' || $account === '' || !str_starts_with($account, 'Expenses:')) return;
    $stmt = $db->prepare('INSERT INTO account_rules (vendor, match_type, match_key, account_fullname, source, confidence, usage_count, last_used_at)
        VALUES (:vendor,:match_type,:match_key,:account,:source,:confidence,1,CURRENT_TIMESTAMP)
        ON CONFLICT(vendor, match_type, match_key) DO UPDATE SET
          account_fullname=excluded.account_fullname,
          source=excluded.source,
          confidence=max(account_rules.confidence, excluded.confidence),
          usage_count=account_rules.usage_count + 1,
          last_used_at=CURRENT_TIMESTAMP');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $stmt->bindValue(':match_type', $matchType, SQLITE3_TEXT);
    $stmt->bindValue(':match_key', $matchKey, SQLITE3_TEXT); $stmt->bindValue(':account', $account, SQLITE3_TEXT);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT); $stmt->bindValue(':confidence', $confidence, SQLITE3_INTEGER); $stmt->execute();
}
function local_account_rules(SQLite3 $db): array {
    $rules = [];
    $res = $db->query('SELECT vendor, match_type, match_key, account_fullname, source, confidence FROM account_rules ORDER BY confidence DESC, usage_count DESC');
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rules[$r['vendor'].'|'.$r['match_type'].'|'.$r['match_key']] = $r;
    return $rules;
}
function account_fullnames_by_guid(SQLite3 $gdb): array {
    $tableCheck = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'");
    if ($tableCheck !== 'accounts') return [];
    $rows=[]; $res=$gdb->query('SELECT guid, name, account_type, parent_guid FROM accounts');
    while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) $rows[$r['guid']]=$r;
    $memo=[]; $full=function($guid) use (&$full,&$rows,&$memo): string {
        if(isset($memo[$guid])) return $memo[$guid]; if(!isset($rows[$guid])) return '';
        $name=$rows[$guid]['name']; $parent=$rows[$guid]['parent_guid'];
        if(!$parent || !isset($rows[$parent]) || strtolower($rows[$parent]['name'])==='root account') return $memo[$guid]=$name;
        $p=$full($parent); return $memo[$guid]=($p?$p.':':'').$name;
    };
    $out=[]; foreach($rows as $guid=>$r) $out[$guid]=['name'=>$full($guid), 'type'=>$r['account_type']]; return $out;
}
function table_columns(SQLite3 $db, string $table): array {
    $cols=[]; $res=$db->query('PRAGMA table_info(' . SQLite3::escapeString($table) . ')');
    while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) $cols[]=$r['name']; return $cols;
}
function load_existing_bill_ids(string $path): array {
    $cfg = gnucash_backend_config(); $out=[];
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') {
        try { $pdo = pdo_for_gnucash($cfg); if (!$pdo) return $out; $res=$pdo->query('SELECT id FROM invoices'); foreach($res as $r){ $id=trim((string)($r['id']??'')); if($id!=='') $out[$id]=true; } } catch(Throwable $e) {}
        return $out;
    }
    $resolved = resolve_local_path($path); $out=[];
    if ($resolved==='' || !file_exists($resolved) || !is_readable($resolved)) return $out;
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $hasInvoices = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='invoices'");
        if ($hasInvoices === 'invoices') {
            $res = $gdb->query('SELECT id FROM invoices');
            while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) if(trim((string)$r['id'])!=='') $out[trim((string)$r['id'])]=true;
        }
    } catch(Throwable $e) {}
    return $out;
}
function load_gnucash_history_rules(string $path): array {
    static $cache = [];
    $cfg = gnucash_backend_config();
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') {
        $rules = [];
        $cacheKey = 'db|' . implode('|', [(string)$cfg['gnucash_backend'], (string)$cfg['gnucash_db_host'], (string)$cfg['gnucash_db_name'], (string)$cfg['gnucash_db_user']]);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdo = pdo_for_gnucash($cfg); if (!$pdo) return [];
            $acctRows = $pdo->query('SELECT guid, name, account_type, parent_guid FROM accounts')->fetchAll();
            $acctByGuid = account_fullnames_by_rows($acctRows);
            $cols = table_columns_pdo($pdo, 'entries');
            if ($cols) {
                $descCol = in_array('description',$cols,true) ? 'description' : (in_array('notes',$cols,true) ? 'notes' : '');
                $acctCol = in_array('account',$cols,true) ? 'account' : (in_array('account_guid',$cols,true) ? 'account_guid' : '');
                if ($descCol && $acctCol) {
                    foreach($pdo->query('SELECT ' . $descCol . ' AS descr, ' . $acctCol . ' AS account_guid FROM entries') as $r) {
                        $descr=(string)($r['descr']??''); $acct=$acctByGuid[(string)($r['account_guid']??'')]['name']??'';
                        if(!str_starts_with($acct,'Expenses:')) continue;
                        if(preg_match_all('/\[SKU:([A-Za-z0-9\-]+)\]|\bSKU[:# ]+([A-Za-z0-9\-]+)\b/i',$descr,$m,PREG_SET_ORDER)) foreach($m as $mm){$sku=$mm[1]?:$mm[2]; $rules['costco|sku|'.$sku]=['account'=>$acct,'reason'=>'matched GnuCash SQL history SKU '.$sku];}
                        if(preg_match_all('/\[ASIN:([A-Z0-9]{10})\]|\bASIN[:# ]+([A-Z0-9]{10})\b/i',$descr,$m,PREG_SET_ORDER)) foreach($m as $mm){$asin=$mm[1]?:$mm[2]; $rules['amazon|asin|'.$asin]=['account'=>$acct,'reason'=>'matched GnuCash SQL history ASIN '.$asin];}
                        if(preg_match_all('/(?<!\d)(\d{4,8})(?!\d)/',$descr,$m)) foreach($m[1] as $sku) $rules['costco|sku|'.$sku]=['account'=>$acct,'reason'=>'matched GnuCash SQL item number '.$sku];
                    }
                }
            }
        } catch(Throwable $e) {}
        $cache[$cacheKey] = $rules;
        return $rules;
    }
    $resolved = resolve_local_path($path);
    $rules=[];
    if ($resolved==='' || !file_exists($resolved) || !is_readable($resolved)) return $rules;
    $cacheKey = $resolved . '|' . (string)@filemtime($resolved) . '|' . (string)@filesize($resolved);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $acctByGuid = account_fullnames_by_guid($gdb);
        if (!$acctByGuid) return $rules;
        // Business bill entries: scan descriptions/notes for stable keys and map to the entry account.
        $hasEntries = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='entries'");
        if ($hasEntries === 'entries') {
            $cols = table_columns($gdb, 'entries');
            $descCol = in_array('description',$cols,true) ? 'description' : (in_array('notes',$cols,true) ? 'notes' : '');
            $acctCol = in_array('account',$cols,true) ? 'account' : (in_array('account_guid',$cols,true) ? 'account_guid' : '');
            if ($descCol && $acctCol) {
                $res = $gdb->query('SELECT ' . $descCol . ' AS descr, ' . $acctCol . ' AS account_guid FROM entries');
                while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
                    $descr=(string)($r['descr']??''); $ag=(string)($r['account_guid']??''); $acct=$acctByGuid[$ag]['name']??'';
                    if(!str_starts_with($acct,'Expenses:')) continue;
                    if(preg_match_all('/\[SKU:([A-Za-z0-9\-]+)\]|\bSKU[:# ]+([A-Za-z0-9\-]+)\b/i',$descr,$m,PREG_SET_ORDER)) foreach($m as $mm){$sku=$mm[1]?:$mm[2]; $rules['costco|sku|'.$sku]=['account'=>$acct,'reason'=>'matched GnuCash history SKU '.$sku];}
                    if(preg_match_all('/\[ASIN:([A-Z0-9]{10})\]|\bASIN[:# ]+([A-Z0-9]{10})\b/i',$descr,$m,PREG_SET_ORDER)) foreach($m as $mm){$asin=$mm[1]?:$mm[2]; $rules['amazon|asin|'.$asin]=['account'=>$acct,'reason'=>'matched GnuCash history ASIN '.$asin];}
                    // Costco manual shorthand: a six/seven digit item number in the entry text.
                    if(preg_match_all('/(?<!\d)(\d{4,8})(?!\d)/',$descr,$m)) foreach($m[1] as $sku) $rules['costco|sku|'.$sku]=['account'=>$acct,'reason'=>'matched GnuCash history item number '.$sku];
                }
            }
        }
        // Generic transaction/split scan for users who entered receipts directly as transactions.
        $hasSplits = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='splits'");
        $hasTx = $gdb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='transactions'");
        if ($hasSplits === 'splits') {
            $splitCols = table_columns($gdb,'splits'); $memoCol = in_array('memo',$splitCols,true)?'memo':''; $acctCol=in_array('account_guid',$splitCols,true)?'account_guid':''; $txCol=in_array('tx_guid',$splitCols,true)?'tx_guid':'';
            if($memoCol && $acctCol){
                $sql='SELECT s.'.$memoCol.' AS descr, s.'.$acctCol.' AS account_guid';
                if($hasTx==='transactions' && $txCol) $sql.=', t.description AS txdescr FROM splits s LEFT JOIN transactions t ON t.guid=s.'.$txCol; else $sql.=', "" AS txdescr FROM splits s';
                $res=$gdb->query($sql);
                while($res && ($r=$res->fetchArray(SQLITE3_ASSOC))){
                    $descr=trim((string)($r['descr']??'').' '.(string)($r['txdescr']??'')); $ag=(string)($r['account_guid']??''); $acct=$acctByGuid[$ag]['name']??'';
                    if(!str_starts_with($acct,'Expenses:')) continue;
                    if(preg_match_all('/(?<!\d)(\d{4,8})(?!\d)/',$descr,$m)) foreach($m[1] as $sku) $rules['costco|sku|'.$sku]=['account'=>$acct,'reason'=>'matched GnuCash transaction item number '.$sku];
                }
            }
        }
    } catch(Throwable $e) {}
    $cache[$cacheKey] = $rules;
    return $rules;
}
function suggest_account(SQLite3 $db, string $vendor, string $keyType, string $key, string $description, array $gnucashRules = [], array $validAccounts = []): array {
    $vendor=strtolower($vendor); $key=trim($key); $keyType=strtolower($keyType);
    $local = local_account_rules($db);
    foreach ([[$keyType,$key], ['title', normalize_key_text($description)]] as $try) {
        if ($try[1] === '') continue;
        $ruleKey = $vendor.'|'.$try[0].'|'.$try[1];
        if (isset($local[$ruleKey])) {
            $acct = (string)$local[$ruleKey]['account_fullname'];
            if (is_valid_suggestion_account($acct, $validAccounts)) return [$acct, 'matched saved rule '.$try[0].' '.$try[1]];
        }
        if (isset($gnucashRules[$ruleKey])) {
            $acct = (string)$gnucashRules[$ruleKey]['account'];
            if (is_valid_suggestion_account($acct, $validAccounts)) return [$acct, (string)$gnucashRules[$ruleKey]['reason']];
        }
    }
    return ['', ''];
}
function apply_account_suggestions(SQLite3 $db, string $gnucashPath): int {
    $gnucashRules = load_gnucash_history_rules($gnucashPath); $validAccounts = load_valid_account_set($gnucashPath); $changed=0;
    $stmtSel = $db->query('SELECT vendor, order_id, item_key, asin, description, expense_account, locked FROM order_items');
    $stmtUpd = $db->prepare('UPDATE order_items SET expense_account=:account, match_reason=:reason WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key');
    while($r=$stmtSel->fetchArray(SQLITE3_ASSOC)){
        if (is_reconcile_or_discount_item($r)) continue;
        $vendor=(string)$r['vendor']; $keyType=$vendor==='costco'?'sku':'asin';
        [$acct,$reason]=suggest_account($db,$vendor,$keyType,(string)$r['asin'],(string)$r['description'],$gnucashRules,$validAccounts);
        if($acct!=='' && !(int)($r['locked'] ?? 0) && ($r['expense_account']==='' || str_contains((string)$r['expense_account'],'Uncategorized') || $reason!=='')){
            $stmtUpd->bindValue(':account',$acct,SQLITE3_TEXT); $stmtUpd->bindValue(':reason',$reason,SQLITE3_TEXT);
            $stmtUpd->bindValue(':vendor',$vendor,SQLITE3_TEXT); $stmtUpd->bindValue(':order_id',$r['order_id'],SQLITE3_TEXT); $stmtUpd->bindValue(':item_key',$r['item_key'],SQLITE3_TEXT); $stmtUpd->execute(); $changed++;
        }
    }
    return $changed;
}

function is_placeholder_account(?string $acct): bool {
    $acct = trim((string)$acct);
    return $acct === '' || stripos($acct, 'Uncategorized') !== false;
}

function is_reconcile_or_discount_item(array $r): bool {
    $itemKey = strtoupper(trim((string)($r['item_key'] ?? '')));
    $asin = strtoupper(trim((string)($r['asin'] ?? '')));
    $desc = strtoupper(trim((string)($r['description'] ?? '')));
    if ($itemKey === 'AMAZON-RECONCILE' || $asin === 'AMAZON-RECONCILE') return true;
    if (str_starts_with($desc, '[DISCOUNT:') || str_starts_with($desc, '[ADJUSTMENT:') || str_starts_with($desc, '[GIFTWRAP:') || str_starts_with($desc, '[FEE:') || str_starts_with($desc, '[CREDIT:')) return true;
    if (str_contains($desc, 'RECONCILIATION ADJUSTMENT')) return true;
    return false;
}
function account_starts_expenses(string $acct): bool {
    return stripos(trim($acct), 'Expenses:') === 0;
}
function amazon_best_reference_account_for_discount(SQLite3 $db, string $orderId, array $validAccounts = []): array {
    // Amazon discount/reconciliation lines are invoice-local, not product-global.
    // They should reduce an item category in the same order. Prefer a categorized,
    // non-placeholder item row in the same invoice. If several are categorized,
    // use the largest absolute item amount, because that is the safest fallback for
    // order-level coupons whose target item is not explicit in the browser export.
    $stmt = $db->prepare("SELECT item_key, asin, description, quantity, unit_price, source_amount, expense_account
        FROM order_items
        WHERE vendor='amazon' AND order_id=:order_id AND COALESCE(skip,0)=0
          AND COALESCE(locked,0)=0
          AND item_key<>'AMAZON-RECONCILE'
          AND COALESCE(asin,'')<>'AMAZON-RECONCILE'
          AND description NOT LIKE '[DISCOUNT:%'
          AND description NOT LIKE '[ADJUSTMENT:%'
        ORDER BY ABS(COALESCE(source_amount, quantity*unit_price)) DESC, item_key
        LIMIT 100");
    $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $bestAnyDesc = '';
    $bestAnyAmt = 0.0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $amt = abs((float)($r['source_amount'] ?? 0.0));
        if ($amt == 0.0) $amt = abs((float)($r['quantity'] ?? 1) * (float)($r['unit_price'] ?? 0));
        if ($bestAnyDesc === '') { $bestAnyDesc = (string)($r['description'] ?? ''); $bestAnyAmt = $amt; }
        $acct = trim((string)($r['expense_account'] ?? ''));
        if ($acct !== '' && account_starts_expenses($acct) && !is_placeholder_account($acct)) {
            // If a current-account set was loaded, use exact matches when available.
            // If the set was not loaded or the row already has a user-entered account, still
            // allow it; export validation will catch truly invalid account names later.
            if (!$validAccounts || isset($validAccounts[$acct])) return [$acct, (string)($r['description'] ?? ''), $amt];
            if (!empty($validAccounts)) return [$acct, (string)($r['description'] ?? ''), $amt];
        }
    }
    return ['', $bestAnyDesc, $bestAnyAmt];
}

function amazon_best_reference_account_for_refund(SQLite3 $db, string $orderId, float $refundAmount, array $validAccounts = []): array {
    // Refund credit memos should point to the item(s) actually refunded where possible.
    // Amazon often gives only an order-level Refund Total that includes item refund + tax refund
    // and sometimes a small rewards/points fee.  Match in this order:
    //   1. one item exactly/near-exactly equals the refund total or unit price,
    //   2. a subset of item lines adds to the item-refund subtotal below the refund total,
    //   3. a close single item match,
    //   4. best same-order categorized fallback.
    $stmt = $db->prepare("SELECT item_key, asin, description, quantity, unit_price, source_amount, expense_account
        FROM order_items
        WHERE vendor='amazon' AND order_id=:order_id AND COALESCE(skip,0)=0
          AND item_key<>'AMAZON-RECONCILE'
          AND COALESCE(asin,'')<>'AMAZON-RECONCILE'
          AND description NOT LIKE '[DISCOUNT:%'
          AND description NOT LIKE '[ADJUSTMENT:%'
          AND description NOT LIKE '[GIFTWRAP:%'
          AND description NOT LIKE '[FEE:%'
          AND description NOT LIKE '[CREDIT:%'
        ORDER BY item_key");
    $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $target = abs($refundAmount);
    $items = [];
    $bestExact = null;
    $bestClose = null;
    $bestCategorized = null;
    $bestAny = null;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $qty = (float)($r['quantity'] ?? 1);
        $unit = (float)($r['unit_price'] ?? 0);
        $src = (float)($r['source_amount'] ?? 0);
        $lineAmt = abs($src ?: ($qty * $unit));
        $unitAmt = abs($unit);
        if ($lineAmt <= 0.005 && $unitAmt <= 0.005) continue;
        $acct = trim((string)($r['expense_account'] ?? ''));
        $validAcct = ($acct !== '' && account_starts_expenses($acct) && !is_placeholder_account($acct) && (!$validAccounts || isset($validAccounts[$acct]) || !empty($validAccounts)));
        $row = [$acct, (string)($r['description'] ?? ''), $lineAmt, (string)($r['asin'] ?? '')];
        $items[] = [
            'acct' => $acct,
            'valid' => $validAcct,
            'desc' => (string)($r['description'] ?? ''),
            'asin' => (string)($r['asin'] ?? ''),
            'line' => $lineAmt,
            'unit' => $unitAmt,
        ];
        if ($bestAny === null) $bestAny = $row;
        if ($validAcct && $bestCategorized === null) $bestCategorized = $row;
        $diffLine = abs($lineAmt - $target);
        $diffUnit = abs($unitAmt - $target);
        $diff = min($diffLine, $diffUnit);
        if ($diff <= 0.011) {
            if ($validAcct) return $row;
            if ($bestExact === null) $bestExact = $row;
        }
        $threshold = max(0.05, min(2.00, $target * 0.08));
        if ($diff <= $threshold) {
            if ($bestClose === null || $diff < $bestClose[4]) $bestClose = [$acct, (string)($r['description'] ?? ''), $lineAmt, (string)($r['asin'] ?? ''), $diff, $validAcct];
        }
    }
    if ($bestExact !== null) return $bestExact;

    // Subset match: refund total may include item subtotal + tax refund - points fee.
    // Example: item refund 83.99 + tax refund 6.30 - points fee 0.42 = 89.87.
    // We therefore accept the best subset whose item subtotal is below the refund total
    // with a residual that is plausibly tax/fee/rounding.
    $n = count($items);
    if ($n >= 2 && $n <= 24 && $target > 0.01) {
        $maxResidual = max(1.00, min(15.00, $target * 0.15));
        $bestCombo = null;
        $bestScore = null;
        // Meet-in-the-middle would be overkill here; cap at 24 and split if large.
        // For larger same-order item counts, use a bounded dynamic-programming map by cents.
        $dp = [0 => []];
        foreach ($items as $idx => $it) {
            $cents = (int)round($it['line'] * 100);
            if ($cents <= 0) continue;
            $snapshot = $dp;
            foreach ($snapshot as $sum => $combo) {
                $newSum = $sum + $cents;
                if ($newSum > (int)round(($target + 0.02) * 100)) continue;
                if (!isset($dp[$newSum]) || count($combo) + 1 < count($dp[$newSum])) {
                    $dp[$newSum] = array_merge($combo, [$idx]);
                }
            }
        }
        foreach ($dp as $sumCents => $combo) {
            if (count($combo) < 2) continue;
            $sum = $sumCents / 100.0;
            $residual = $target - $sum;
            if ($residual < -0.02 || $residual > $maxResidual) continue;
            // Prefer smallest plausible residual; for ties prefer more refunded subtotal.
            $score = abs($residual) + (0.0001 * (24 - count($combo)));
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestCombo = $combo;
            }
        }
        if ($bestCombo !== null) {
            $sum = 0.0;
            $descs = [];
            $asins = [];
            $accounts = [];
            foreach ($bestCombo as $idx) {
                $it = $items[$idx];
                $sum += (float)$it['line'];
                $descs[] = clean_text((string)$it['desc'], 70);
                if ((string)$it['asin'] !== '') $asins[] = (string)$it['asin'];
                if ($it['valid']) $accounts[$it['acct']] = true;
            }
            $acct = '';
            if (count($accounts) === 1) $acct = array_key_first($accounts);
            elseif (count($accounts) > 1) $acct = 'Expenses:Uncategorized';
            $residual = $target - $sum;
            $desc = 'Multiple refunded items: ' . implode('; ', $descs);
            if (abs($residual) > 0.005) $desc .= ' (item subtotal $' . fmt_money($sum) . '; refund remainder $' . fmt_money($residual) . ' likely tax/points/fees)';
            return [$acct, $desc, $sum, implode(',', array_unique($asins))];
        }
    }

    if ($bestClose !== null) return [$bestClose[0], $bestClose[1], $bestClose[2], $bestClose[3]];
    if ($bestCategorized !== null) return $bestCategorized;
    if ($bestAny !== null) return $bestAny;
    return ['', '', 0.0, ''];
}


function amazon_reference_for_exact_amount(SQLite3 $db, string $orderId, float $amount, array $validAccounts = []): array {
    // Strict helper for positive Amazon subtotal deltas: if item-level export omitted a
    // duplicate/line item, the missing amount often exactly equals another same-order item.
    // Do not use subset matching here; subset matching is for refunds and can choose unrelated
    // combinations when a refund is also present.
    $target = abs($amount);
    if ($target <= 0.005) return ['', '', 0.0, ''];
    $stmt = $db->prepare("SELECT item_key, asin, description, quantity, unit_price, source_amount, expense_account
        FROM order_items
        WHERE vendor='amazon' AND order_id=:order_id AND COALESCE(skip,0)=0
          AND item_key<>'AMAZON-RECONCILE'
          AND item_key NOT LIKE 'AMAZON-INFERRED-%'
          AND item_key<>'AMAZON-FEE'
          AND COALESCE(asin,'')<>'AMAZON-RECONCILE'
          AND description NOT LIKE '[DISCOUNT:%'
          AND description NOT LIKE '[ADJUSTMENT:%'
          AND description NOT LIKE '[GIFTWRAP:%'
          AND description NOT LIKE '[FEE:%'
          AND description NOT LIKE '[CREDIT:%'
          AND description NOT LIKE '[MISSING:%'
        ORDER BY item_key");
    $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $fallback = null;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $qty = (float)($r['quantity'] ?? 1);
        $unit = (float)($r['unit_price'] ?? 0);
        $src = (float)($r['source_amount'] ?? 0);
        $lineAmt = abs($src ?: ($qty * $unit));
        $unitAmt = abs($unit);
        $match = (abs($lineAmt - $target) <= 0.011) || (abs($unitAmt - $target) <= 0.011);
        if (!$match) continue;
        $acct = trim((string)($r['expense_account'] ?? ''));
        $row = [$acct, (string)($r['description'] ?? ''), $lineAmt > 0 ? $lineAmt : $unitAmt, (string)($r['asin'] ?? '')];
        $validAcct = ($acct !== '' && account_starts_expenses($acct) && !is_placeholder_account($acct) && (!$validAccounts || isset($validAccounts[$acct]) || !empty($validAccounts)));
        if ($validAcct) return $row;
        if ($fallback === null) $fallback = $row;
    }
    return $fallback ?? ['', '', 0.0, ''];
}

function apply_amazon_discount_categories(SQLite3 $db, array $validAccounts = []): int {
    // Keep Amazon promotion/discount rows tied to a category within their own order only.
    // They are deliberately excluded from saved ASIN/title rule propagation. This function
    // should override stale categories such as Expenses:Groceries or Expenses:Uncategorized
    // if a categorized item exists in the same order.
    $changed = 0;
    $rows = $db->query("SELECT vendor, order_id, item_key, description, expense_account, locked
        FROM order_items
        WHERE vendor='amazon'
          AND COALESCE(skip,0)=0
          AND description LIKE '[DISCOUNT:%'");
    $upd = $db->prepare('UPDATE order_items SET expense_account=:account, match_reason=:reason WHERE vendor="amazon" AND order_id=:order_id AND item_key=:item_key AND COALESCE(locked,0)=0');
    while ($r = $rows->fetchArray(SQLITE3_ASSOC)) {
        if ((int)($r['locked'] ?? 0)) continue;
        [$acct, $desc, $amt] = amazon_best_reference_account_for_discount($db, (string)$r['order_id'], $validAccounts);
        if ($acct === '') continue;
        if (trim((string)$r['expense_account']) === $acct) continue;
        $upd->bindValue(':account', $acct, SQLITE3_TEXT);
        $upd->bindValue(':reason', 'Amazon discount category matched same-order item' . ($desc ? ': ' . substr((string)$desc, 0, 80) : ''), SQLITE3_TEXT);
        $upd->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
        $upd->bindValue(':item_key', (string)$r['item_key'], SQLITE3_TEXT);
        $upd->execute();
        $changed += $db->changes();
    }
    return $changed;
}
function extract_costco_target_sku(string $text): string {
    if (preg_match('/Target\s+SKU:\s*(\d{3,10})/i', $text, $m)) return $m[1];
    if (preg_match('/for\s+SKU\s+(\d{3,10})/i', $text, $m)) return $m[1];
    return '';
}
function apply_account_to_matching_items(SQLite3 $db, string $vendor, string $keyType, string $key, string $account, bool $overwriteExisting=false): int {
    $vendor = strtolower(trim($vendor));
    $keyType = strtolower(trim($keyType));
    $key = trim($key);
    $account = trim($account);
    if ($vendor === '' || $key === '' || $account === '' || !str_starts_with($account, 'Expenses:')) return 0;

    if (vendor_uses_sku_product_rules($vendor)) {
        $key = canonical_sku_rule_key_from_values($vendor, $key, '', '');
        $keyType = 'sku';
    }

    $label = product_rule_label_for_vendor($vendor, $keyType);
    $reason = ($overwriteExisting ? 'manually propagated from reviewed ' : 'auto-applied from reviewed ') . $label . ' ' . $key;
    $pendingClause = $overwriteExisting ? '' : ' AND (expense_account IS NULL OR trim(expense_account)="" OR expense_account LIKE "%Uncategorized%")';
    $sql = 'UPDATE order_items SET expense_account=:account, match_reason=:reason WHERE ' . product_rule_where_sql($vendor, $pendingClause);
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':account', $account, SQLITE3_TEXT);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    bind_product_rule_params($stmt, $vendor, $key);
    $stmt->execute();
    return $db->changes();
}
function normalize_product_description(string $text): string {
    $text = preg_replace('/\[(SKU|ASIN|COUPON):[^\]]+\]/i', ' ', $text);
    $text = preg_replace('/Costco instant savings\s*\/\s*coupon.*$/i', ' ', $text);
    $text = preg_replace('/\([^)]{1,40}\)/', ' ', $text);
    return normalize_key_text((string)$text);
}
function apply_account_to_similar_titles(SQLite3 $db, string $vendor, string $referenceDescription, string $account, bool $overwriteExisting=false): int {
    $vendor = strtolower(trim($vendor));
    $account = trim($account);
    $refKey = normalize_product_description($referenceDescription);
    if ($vendor === '' || $account === '' || !str_starts_with($account, 'Expenses:') || strlen($refKey) < 8) return 0;
    $rows = $db->prepare('SELECT vendor, order_id, item_key, asin, description, expense_account, locked, skip FROM order_items WHERE vendor=:vendor AND skip=0 AND COALESCE(locked,0)=0 AND item_key<>"AMAZON-RECONCILE" AND COALESCE(asin,"")<>"AMAZON-RECONCILE" AND description NOT LIKE "[DISCOUNT:%" AND description NOT LIKE "[ADJUSTMENT:%"');
    $rows->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $res = $rows->execute();
    $upd = $db->prepare('UPDATE order_items SET expense_account=:account, match_reason=:reason WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key AND skip=0 AND COALESCE(locked,0)=0');
    $changed = 0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $current = trim((string)($r['expense_account'] ?? ''));
        if (!$overwriteExisting && !is_placeholder_account($current)) continue;
        if (normalize_product_description((string)$r['description']) !== $refKey) continue;
        if ($current === $account) continue;
        $upd->bindValue(':account', $account, SQLITE3_TEXT);
        $upd->bindValue(':reason', 'manually propagated from matching product title', SQLITE3_TEXT);
        $upd->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $upd->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
        $upd->bindValue(':item_key', (string)$r['item_key'], SQLITE3_TEXT);
        $upd->execute();
        $changed += $db->changes();
    }
    return $changed;
}
function apply_costco_coupon_categories(SQLite3 $db): int {
    $changed = 0;
    $couponRows = $db->query("SELECT vendor, order_id, item_key, description, notes, expense_account, locked FROM order_items WHERE vendor='costco' AND (notes LIKE '%Target SKU:%' OR description LIKE '%for SKU %')");
    $ruleStmt = $db->prepare("SELECT account_fullname FROM account_rules WHERE vendor='costco' AND match_type='sku' AND match_key=:sku ORDER BY confidence DESC, usage_count DESC LIMIT 1");
    $sameOrderStmt = $db->prepare("SELECT expense_account FROM order_items WHERE vendor='costco' AND order_id=:order_id AND asin=:sku AND item_key<>:item_key AND expense_account IS NOT NULL AND trim(expense_account)<>'' AND expense_account NOT LIKE '%Uncategorized%' LIMIT 1");
    $upd = $db->prepare('UPDATE order_items SET expense_account=:account, match_reason=:reason WHERE vendor="costco" AND order_id=:order_id AND item_key=:item_key AND COALESCE(locked,0)=0');
    while ($r = $couponRows->fetchArray(SQLITE3_ASSOC)) {
        $target = extract_costco_target_sku((string)$r['notes'] . ' ' . (string)$r['description']);
        if ($target === '') continue;
        $acct = '';
        $sameOrderStmt->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
        $sameOrderStmt->bindValue(':sku', $target, SQLITE3_TEXT);
        $sameOrderStmt->bindValue(':item_key', (string)$r['item_key'], SQLITE3_TEXT);
        $sr = $sameOrderStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($sr && trim((string)$sr['expense_account']) !== '') $acct = trim((string)$sr['expense_account']);
        if ($acct === '') {
            $ruleStmt->bindValue(':sku', $target, SQLITE3_TEXT);
            $rr = $ruleStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($rr && trim((string)$rr['account_fullname']) !== '') $acct = trim((string)$rr['account_fullname']);
        }
        if ($acct !== '' && trim((string)$r['expense_account']) !== $acct) {
            $upd->bindValue(':account', $acct, SQLITE3_TEXT);
            $upd->bindValue(':reason', 'coupon category matched target SKU '.$target, SQLITE3_TEXT);
            $upd->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
            $upd->bindValue(':item_key', (string)$r['item_key'], SQLITE3_TEXT);
            $upd->execute();
            $changed += $db->changes();
        }
    }
    return $changed;
}
function item_reference_key_for_rule(array $r): array {
    if (is_reconcile_or_discount_item($r)) return ['', ''];
    $vendor = strtolower((string)($r['vendor'] ?? ''));
    $notesDesc = (string)($r['notes'] ?? '') . ' ' . (string)($r['description'] ?? '');
    if ($vendor === 'costco') {
        $target = extract_costco_target_sku($notesDesc);
        $sku = $target !== '' ? $target : canonical_sku_rule_key_from_values($vendor, (string)($r['asin'] ?? ''), (string)($r['item_key'] ?? ''), (string)($r['description'] ?? ''));
        return ['sku', $sku];
    }
    if (vendor_uses_sku_product_rules($vendor)) {
        $sku = canonical_sku_rule_key_from_values($vendor, (string)($r['asin'] ?? ''), (string)($r['item_key'] ?? ''), (string)($r['description'] ?? ''));
        return ['sku', $sku];
    }
    if ($vendor === 'walmart') {
        $key = trim((string)($r['asin'] ?? ''));
        if ($key === '') $key = normalize_key_text((string)($r['description'] ?? ''));
        return ['title', $key];
    }
    return ['asin', trim((string)($r['asin'] ?? ''))];
}
function recategorize_from_item(SQLite3 $db, string $vendor, string $order_id, string $item_key): array {
    $stmt = $db->prepare('SELECT vendor, order_id, item_key, asin, description, notes, expense_account, locked FROM order_items WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
    $stmt->bindValue(':item_key', $item_key, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$r) {
        set_action_debug(['action'=>'recat_item','vendor'=>$vendor,'order_id'=>$order_id,'item_key'=>$item_key,'found'=>false]);
        return [0, 'Item not found for recategorization.'];
    }
    if (is_reconcile_or_discount_item($r)) {
        set_action_debug(['action'=>'recat_item','vendor'=>$vendor,'order_id'=>$order_id,'item_key'=>$item_key,'found'=>true,'blocked'=>'discount_or_reconciliation']);
        return [0, 'Discount/reconciliation rows do not create global product rules. They inherit a same-invoice item category instead.'];
    }
    $acct = trim((string)($r['expense_account'] ?? ''));
    if ($acct === '' || !str_starts_with($acct, 'Expenses:') || is_placeholder_account($acct)) {
        set_action_debug(['action'=>'recat_item','vendor'=>$vendor,'order_id'=>$order_id,'item_key'=>$item_key,'found'=>true,'account'=>$acct,'blocked'=>'no_usable_expense_account']);
        return [0, 'Reference item does not have a usable Expenses account yet.'];
    }
    [$type, $key] = item_reference_key_for_rule($r);
    if ($key === '') {
        set_action_debug(['action'=>'recat_item','vendor'=>$vendor,'order_id'=>$order_id,'item_key'=>$item_key,'found'=>true,'account'=>$acct,'blocked'=>'no_product_key']);
        return [0, 'Reference item does not have a usable ASIN/SKU key.'];
    }

    $vendorNorm = strtolower($vendor);
    $eligibleBefore = same_product_candidate_count($db, $vendorNorm, $key, false);
    $pendingBefore = same_product_candidate_count($db, $vendorNorm, $key, true);
    $samplesBefore = same_product_sample_rows($db, $vendorNorm, $key, 12);

    upsert_account_rule($db, $vendorNorm, $type, $key, $acct, 'manual_reference', 110);
    $exactChanged = apply_account_to_matching_items($db, $vendorNorm, $type, $key, $acct, true);
    $titleChanged = apply_account_to_similar_titles($db, $vendorNorm, (string)$r['description'], $acct, false);
    $couponChanged = apply_costco_coupon_categories($db);
    $discountChanged = apply_amazon_discount_categories($db);
    $changed = $exactChanged + $titleChanged + $couponChanged + $discountChanged;

    $debug = [
        'action' => 'recat_item',
        'vendor' => $vendorNorm,
        'order_id' => $order_id,
        'item_key' => $item_key,
        'source_asin_or_sku' => (string)($r['asin'] ?? ''),
        'description' => (string)($r['description'] ?? ''),
        'account' => $acct,
        'rule_type' => $type,
        'rule_key' => $key,
        'rule_label' => product_rule_label_for_vendor($vendorNorm, $type),
        'eligible_same_product_before' => $eligibleBefore,
        'pending_same_product_before' => $pendingBefore,
        'sample_same_product_rows_before' => $samplesBefore,
        'exact_changed' => $exactChanged,
        'title_changed' => $titleChanged,
        'coupon_changed' => $couponChanged,
        'discount_changed' => $discountChanged,
        'total_changed' => $changed,
    ];
    set_action_debug($debug);

    return [$changed, 'Applied ' . $acct . ' to ' . $changed . ' matching line(s) using ' . product_rule_label_for_vendor($vendorNorm, $type) . ' ' . $key . ($titleChanged ? ' plus matching product title' : '') . '. Eligible same-product rows before apply: ' . $eligibleBefore . '; pending/Uncategorized before apply: ' . $pendingBefore . '. Exact-key changes: ' . $exactChanged . '; title changes: ' . $titleChanged . '. Locked rows were not changed.'];
}
function recategorize_from_order(SQLite3 $db, string $vendor, string $order_id): array {
    $stmt = $db->prepare('SELECT vendor, order_id, item_key, asin, description, notes, expense_account, locked FROM order_items WHERE vendor=:vendor AND order_id=:order_id ORDER BY item_key');
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
    $res = $stmt->execute();
    $rules = 0; $changed = 0; $messages = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        if (is_reconcile_or_discount_item($r)) continue;
        $acct = trim((string)($r['expense_account'] ?? ''));
        if ($acct === '' || !str_starts_with($acct, 'Expenses:') || is_placeholder_account($acct)) continue;
        [$type, $key] = item_reference_key_for_rule($r);
        if ($key === '') continue;
        upsert_account_rule($db, strtolower($vendor), $type, $key, $acct, 'manual_invoice_reference', 105);
        $c = apply_account_to_matching_items($db, strtolower($vendor), $type, $key, $acct, true);
        $c += apply_account_to_similar_titles($db, strtolower($vendor), (string)$r['description'], $acct, false);
        $changed += $c; $rules++;
        if (count($messages) < 5) $messages[] = strtoupper($type) . ' ' . $key . ' → ' . $acct . ' (' . $c . ')';
    }
    $changed += apply_costco_coupon_categories($db);
    $changed += apply_amazon_discount_categories($db);
    if ($rules === 0) return [0, 'No usable categorized item lines found on that invoice to use as references.'];
    return [$changed, 'Created/updated ' . $rules . ' reference rule(s) from invoice ' . $order_id . ' and applied them to ' . $changed . ' pending matching line(s). ' . implode('; ', $messages) . '. Locked rows were not changed.'];
}
function recategorize_recent_manual_changes(SQLite3 $db): array {
    $last = get_config($db, 'last_bulk_manual_apply_at', '1970-01-01 00:00:00');
    $stmt = $db->prepare("SELECT vendor, order_id, item_key, asin, description, notes, expense_account
        FROM order_items
        WHERE skip=0 AND COALESCE(locked,0)=0
          AND account_change_source='manual'
          AND COALESCE(account_last_changed_at,'') > :last
          AND expense_account IS NOT NULL AND trim(expense_account)<>''
          AND expense_account LIKE 'Expenses:%'
          AND expense_account NOT LIKE '%Uncategorized%'
        ORDER BY account_last_changed_at, vendor, order_id, item_key");
    $stmt->bindValue(':last', $last, SQLITE3_TEXT);
    $res = $stmt->execute();
    $rules = 0; $changed = 0; $messages = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        if (is_reconcile_or_discount_item($r)) continue;
        $vendor = strtolower((string)$r['vendor']);
        $acct = trim((string)$r['expense_account']);
        [$type, $key] = item_reference_key_for_rule($r);
        if ($key === '') continue;
        upsert_account_rule($db, $vendor, $type, $key, $acct, 'manual_bulk_since_last_apply', 115);
        // Bulk apply is intentionally conservative: only blank/Uncategorized matching rows are changed.
        $c = apply_account_to_matching_items($db, $vendor, $type, $key, $acct, false);
        $c += apply_account_to_similar_titles($db, $vendor, (string)$r['description'], $acct, false);
        $changed += $c; $rules++;
        if (count($messages) < 8) $messages[] = strtoupper($type) . ' ' . $key . ' → ' . $acct . ' (' . $c . ')';
    }
    $changed += apply_costco_coupon_categories($db);
    $changed += apply_amazon_discount_categories($db);
    set_config($db, 'last_bulk_manual_apply_at', now_sql());
    if ($rules === 0) return [0, 'No manually changed categorized rows were found since the last bulk apply. Change an account field, save, then use this again.'];
    return [$changed, 'Applied ' . $rules . ' manually changed category reference(s) since the last bulk apply to ' . $changed . ' blank/Uncategorized matching row(s). ' . implode('; ', $messages) . '. Existing non-Uncategorized rows and locked rows were not changed.'];
}

function staged_order_invoice_state_map(SQLite3 $db, string $gnucashPath): array {
    $targets = [];
    $res = $db->query('SELECT vendor, order_id, skip, warning FROM orders ORDER BY vendor, order_date, order_id');

    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $vendor = (string)($r['vendor'] ?? '');
        $orderId = (string)($r['order_id'] ?? '');
        if ($vendor === '' || $orderId === '') {
            continue;
        }

        $canonical = canonical_order_id_for_export($vendor, $orderId);
        if ($canonical === '') {
            continue;
        }

        $targets[$canonical][] = [
            'vendor' => $vendor,
            'order_id' => $orderId,
            'skip' => (int)($r['skip'] ?? 0),
            'warning' => (string)($r['warning'] ?? ''),
        ];
    }

    if (!$targets) {
        return [];
    }

    $states = load_gnucash_invoice_states_for_payments($gnucashPath, array_keys($targets));
    $out = [];

    foreach ($targets as $invoiceId => $rows) {
        $state = $states[$invoiceId] ?? invoice_empty_state($invoiceId);
        foreach ($rows as $row) {
            $out[$row['vendor'] . '|' . $row['order_id']] = [
                'invoice_id' => $invoiceId,
                'state' => $state,
                'row' => $row,
            ];
        }
    }

    return $out;
}

function mark_existing_bills_to_skip(SQLite3 $db, string $gnucashPath): int {
    $stateMap = staged_order_invoice_state_map($db, $gnucashPath);
    if (!$stateMap) {
        return 0;
    }

    $markPosted = $db->prepare('UPDATE orders SET skip=1, warning=trim(COALESCE(warning,"") || " Existing posted bill/invoice ID found in GnuCash; marked skip to avoid duplicate import.") WHERE vendor=:vendor AND order_id=:order_id AND COALESCE(skip,0)=0');
    $warnUnposted = $db->prepare('UPDATE orders SET warning=trim(COALESCE(warning,"") || " Existing GnuCash bill/invoice ID found but it is not posted; not auto-skipped. Re-exporting as posted is allowed, or post/delete it manually before payment automation.") WHERE vendor=:vendor AND order_id=:order_id AND COALESCE(skip,0)=0 AND COALESCE(warning,"") NOT LIKE "%Existing GnuCash bill/invoice ID found but it is not posted%"');

    $n = 0;

    foreach ($stateMap as $key => $info) {
        $row = $info['row'] ?? [];
        $state = $info['state'] ?? [];
        $vendor = (string)($row['vendor'] ?? '');
        $orderId = (string)($row['order_id'] ?? '');
        $skip = (int)($row['skip'] ?? 0);

        if ($vendor === '' || $orderId === '' || empty($state['found'])) {
            continue;
        }

        if (!empty($state['posted'])) {
            if (!$skip) {
                $markPosted->bindValue(':vendor', $vendor, SQLITE3_TEXT);
                $markPosted->bindValue(':order_id', $orderId, SQLITE3_TEXT);
                retry_sqlite_write(fn() => $markPosted->execute());
                $n++;
            }
        } else {
            // Important: an existing unposted GnuCash bill should not be auto-skipped.
            // This lets the user restore/re-export a posted bill CSV to update/post it,
            // while still making the unposted-book state visible on the Review Bills page.
            if (!$skip) {
                $warnUnposted->bindValue(':vendor', $vendor, SQLITE3_TEXT);
                $warnUnposted->bindValue(':order_id', $orderId, SQLITE3_TEXT);
                retry_sqlite_write(fn() => $warnUnposted->execute());
            }
        }
    }

    return $n;
}

function rebuild_bill_import_against_gnucash(SQLite3 $db, string $gnucashPath): array {
    $ids = load_existing_bill_ids($gnucashPath);
    if (!$ids) return ['marked'=>0, 'restored'=>0, 'checked'=>0, 'message'=>'No existing posted bill/credit IDs could be read from the selected GnuCash file. Check the GnuCash path/backend before rebuilding.'];
    $checked = 0; $marked = 0; $restored = 0;
    $mark = $db->prepare('UPDATE orders SET skip=1, warning=trim(COALESCE(warning,"") || " Existing bill/invoice ID found in GnuCash; marked skip to avoid duplicate import.") WHERE vendor=:vendor AND order_id=:order_id AND COALESCE(skip,0)=0');
    $restore = $db->prepare('UPDATE orders SET skip=0, warning=trim(replace(COALESCE(warning,""), "Existing bill/invoice ID found in GnuCash; marked skip to avoid duplicate import.", "Rebuild found no matching bill/invoice ID in the current GnuCash file; restored to exportable.")) WHERE vendor=:vendor AND order_id=:order_id AND COALESCE(skip,0)=1');
    $res = $db->query('SELECT vendor, order_id, skip, warning FROM orders ORDER BY vendor, order_date, order_id');
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $checked++;
        $vendor = (string)$r['vendor'];
        $oid = (string)$r['order_id'];
        $canonical = canonical_order_id_for_export($vendor, $oid);
        $exists = isset($ids[$canonical]);
        $skip = (int)($r['skip'] ?? 0);
        $warn = (string)($r['warning'] ?? '');
        if ($exists && !$skip) {
            $mark->bindValue(':vendor', $vendor, SQLITE3_TEXT);
            $mark->bindValue(':order_id', $oid, SQLITE3_TEXT);
            $mark->execute();
            $marked++;
        } elseif (!$exists && $skip && stripos($warn, 'Existing bill/invoice ID found in GnuCash') !== false) {
            // Only undo our own automatic duplicate-import skip.  Manual skip flags and
            // explicit out-of-scope/ignored CREDIT targets are left alone.
            $restore->bindValue(':vendor', $vendor, SQLITE3_TEXT);
            $restore->bindValue(':order_id', $oid, SQLITE3_TEXT);
            $restore->execute();
            $restored++;
        }
    }
    set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
    set_config($db, 'bill_import_rebuild_last_at', date('Y-m-d H:i:s'));
    set_config($db, 'bill_import_rebuild_last_marked', (string)$marked);
    set_config($db, 'bill_import_rebuild_last_restored', (string)$restored);
    set_config($db, 'bill_import_rebuild_last_checked', (string)$checked);
    return ['marked'=>$marked, 'restored'=>$restored, 'checked'=>$checked, 'message'=>'Rebuilt staged bill/import skip status against the selected GnuCash file: checked '.$checked.' staged order(s), marked '.$marked.' existing GnuCash bill(s) as skipped, restored '.$restored.' auto-skipped order(s) that are no longer present in the current GnuCash file.'];
}
function import_amazon_json(SQLite3 $db, string $path, string $gnucashPath = ''): array {
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [0, 'Could not read uploaded JSON.'];
    $orders = json_decode($raw, true);
    if (!is_array($orders)) return [0, 'Unsupported JSON format. Expected a top-level Amazon orders array.'];
    $orderCount = 0; $itemCount = 0;
    $validAccounts = load_valid_account_set($gnucashPath);
    foreach ($orders as $o) {
        if (!is_array($o)) continue;
        $order_id = trim((string)($o['orderId'] ?? ''));
        $date = normalize_import_date((string)($o['orderDate'] ?? ''));
        if ($order_id === '' || !preg_match('/^\d{3}-\d{7}-\d{7}$/', $order_id) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $currency = strtoupper(trim((string)($o['currency'] ?? '')));
        $items = is_array($o['items'] ?? null) ? $o['items'] : [];
        $titles = [];
        foreach ($items as $it) if (is_array($it) && trim((string)($it['title'] ?? '')) !== '') $titles[] = trim((string)$it['title']);
        $itemsText = implode('; ', $titles);
        $total = (float)($o['totalAmount'] ?? 0);
        $warning = [];
        if ($currency !== '' && $currency !== 'USD') $warning[] = 'JSON export currency is ' . $currency . '; verify this row before import.';
        if (count($items) > 1 && $total > 0) $warning[] = 'JSON has multiple items but item prices are zero/missing; edit unit prices before exporting to GnuCash or merge with a priced item CSV.';
        if (count($items) === 1 && $total > 0) $warning[] = 'JSON item price was missing; unit price estimated from order total.';
        if ($total <= 0) $warning[] = 'JSON total is zero; verify whether this is an export/currency issue, reward/gift-card order, or canceled/no-charge order.';
        $stmt = $db->prepare('INSERT INTO orders
            (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes)
            VALUES (:vendor,:order_id,:order_url,:order_date,:recipient,:items,:total,0,0,0,0,0,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)
            ON CONFLICT(vendor, order_id) DO UPDATE SET
              order_url=COALESCE(NULLIF(excluded.order_url,""), orders.order_url),
              order_date=COALESCE(NULLIF(excluded.order_date,""), orders.order_date),
              items=CASE WHEN orders.items IS NULL OR orders.items="" THEN excluded.items ELSE orders.items END,
              total=CASE WHEN orders.total IS NULL OR orders.total=0 THEN excluded.total ELSE orders.total END,
              item_amount=CASE WHEN orders.item_amount IS NULL OR orders.item_amount=0 THEN excluded.item_amount ELSE orders.item_amount END,
              warning=trim(COALESCE(orders.warning,"") || " " || excluded.warning),
              notes=trim(COALESCE(orders.notes,"") || " " || excluded.notes)');
        $stmt->bindValue(':vendor', 'amazon', SQLITE3_TEXT);
        $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
        $stmt->bindValue(':order_url', (string)($o['detailsUrl'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':order_date', $date, SQLITE3_TEXT);
        $stmt->bindValue(':recipient', '', SQLITE3_TEXT);
        $stmt->bindValue(':items', $itemsText, SQLITE3_TEXT);
        $stmt->bindValue(':total', $total, SQLITE3_FLOAT);
        $stmt->bindValue(':payments', 'JSON export; payment details not included.', SQLITE3_TEXT);
        $stmt->bindValue(':expense_account', default_account_for_items($itemsText, $validAccounts), SQLITE3_TEXT);
        $stmt->bindValue(':item_amount', max(0.0, $total), SQLITE3_FLOAT);
        $stmt->bindValue(':tax_account', default_config_value('DEFAULT_TAX_ACCOUNT'), SQLITE3_TEXT);
        $stmt->bindValue(':shipping_account', default_config_value('DEFAULT_SHIPPING_ACCOUNT'), SQLITE3_TEXT);
        $stmt->bindValue(':warning', implode(' ', $warning), SQLITE3_TEXT);
        $stmt->bindValue(':notes', 'Amazon JSON URL: ' . (string)($o['detailsUrl'] ?? '') . ' Status: ' . (string)($o['orderStatus'] ?? ''), SQLITE3_TEXT);
        $stmt->execute();
        $orderCount++;
        $insertItem = $db->prepare('INSERT INTO order_items
            (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, subscribe_save, expense_account, notes)
            VALUES (:vendor,:order_id,:item_key,:order_url,:order_date,:quantity,:description,:item_url,:asin,:unit_price,0,:expense_account,:notes)
            ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
              order_url=excluded.order_url, order_date=excluded.order_date, quantity=excluded.quantity,
              description=excluded.description, item_url=excluded.item_url, asin=excluded.asin,
              unit_price=CASE WHEN order_items.unit_price IS NULL OR order_items.unit_price=0 THEN excluded.unit_price ELSE order_items.unit_price END,
              notes=trim(COALESCE(order_items.notes,"") || " " || excluded.notes)');
        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;
            $desc = trim((string)($it['title'] ?? ''));
            if ($desc === '') continue;
            $asin = trim((string)($it['asin'] ?? ''));
            $qty = max(1.0, (float)($it['quantity'] ?? 1));
            $price = (float)($it['price'] ?? 0);
            // This JSON exporter commonly emits item price = 0. For single-item orders, estimating from total is useful.
            // For multi-item orders, leave price at 0 so review forces a manual allocation or merge with a priced CSV.
            if ($price == 0.0 && count($items) === 1 && $total > 0) $price = round($total / $qty, 2);
            $key = item_key($order_id, $asin, $desc, 'json-' . $idx, (string)$qty);
            $insertItem->bindValue(':vendor', 'amazon', SQLITE3_TEXT);
            $insertItem->bindValue(':order_id', $order_id, SQLITE3_TEXT);
            $insertItem->bindValue(':item_key', $key, SQLITE3_TEXT);
            $insertItem->bindValue(':order_url', (string)($o['detailsUrl'] ?? ''), SQLITE3_TEXT);
            $insertItem->bindValue(':order_date', $date, SQLITE3_TEXT);
            $insertItem->bindValue(':quantity', $qty, SQLITE3_FLOAT);
            $insertItem->bindValue(':description', $desc, SQLITE3_TEXT);
            $insertItem->bindValue(':item_url', (string)($it['itemUrl'] ?? ''), SQLITE3_TEXT);
            $insertItem->bindValue(':asin', $asin, SQLITE3_TEXT);
            $insertItem->bindValue(':unit_price', $price, SQLITE3_FLOAT);
            $insertItem->bindValue(':expense_account', default_account_for_items($desc, $validAccounts), SQLITE3_TEXT);
            $insertItem->bindValue(':notes', 'ASIN: ' . $asin . ' JSON item URL: ' . (string)($it['itemUrl'] ?? ''), SQLITE3_TEXT);
            $insertItem->execute(); $itemCount++;
        }
    }
    return [$orderCount, null, $itemCount];
}


function costco_is_return_receipt(array $rec): bool {
    $txnType = strtolower(trim((string)($rec['transactionType'] ?? '')));
    $receiptType = strtolower(trim((string)($rec['receiptType'] ?? '')));
    $docType = strtolower(trim((string)($rec['documentType'] ?? '')));
    $total = (float)($rec['total'] ?? 0.0);
    $itemCount = (float)($rec['totalItemCount'] ?? 0.0);
    if (str_contains($txnType, 'refund') || str_contains($txnType, 'return')) return true;
    if (str_contains($receiptType, 'refund') || str_contains($receiptType, 'return')) return true;
    if (str_contains($docType, 'refund') || str_contains($docType, 'return')) return true;
    if ($total < -0.0001 || $itemCount < -0.0001) return true;
    foreach (($rec['tenderArray'] ?? []) as $t) {
        if (is_array($t) && (float)($t['amountTender'] ?? 0.0) < -0.0001) return true;
    }
    return false;
}

function costco_item_is_coupon_adjustment(array $it): bool {
    $sku = trim((string)($it['itemNumber'] ?? ''));
    $actual = trim((string)($it['itemActualName'] ?? ''));
    $short = trim((string)($it['itemDescription01'] ?? ''));
    $french1 = trim((string)($it['frenchItemDescription1'] ?? ''));
    $catEntryId = trim((string)($it['catEntryId'] ?? ''));
    $unitPrice = (float)($it['itemUnitPriceAmount'] ?? 0.0);
    // Costco encodes coupon/instant-savings rows as slash-reference rows such as
    // itemDescription01="/1410571" or itemActualName="/1802463".  Those may be
    // negative on sales receipts or positive when reversing a coupon on a refund.
    if (str_starts_with($short, '/') || str_starts_with($actual, '/') || str_starts_with($french1, '/')) return true;
    if ($catEntryId === '' && abs($unitPrice) < 0.0001 && preg_match('/coupon|instant\s+savings|promo|discount/i', $actual.' '.$short)) return true;
    // Older scraper rows sometimes use six-digit 3xxxxx item numbers for coupon rows.
    // Do not rely on that alone because normal SKUs can also be six digits.
    if (preg_match('/coupon|instant\s+savings|promo|discount/i', $actual.' '.$short)) return true;
    return false;
}

function import_costco_json(SQLite3 $db, string $path, string $gnucashPath=''): array {
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [0, 'Could not read uploaded Costco JSON.'];
    $receipts = json_decode($raw, true);
    if (!is_array($receipts)) return [0, 'Unsupported Costco JSON format. Expected a top-level receipt array.'];
    $gnucashRules = load_gnucash_history_rules($gnucashPath);
    $validAccounts = load_valid_account_set($gnucashPath);
    $orderCount=0; $itemCount=0;
    foreach($receipts as $rec){
        if(!is_array($rec) || !is_array($rec['itemArray'] ?? null)) continue;
        $order_id = receipt_id_for_costco($rec);
        $date = trim((string)($rec['transactionDate'] ?? substr((string)($rec['transactionDateTime'] ?? ''),0,10)));
        if($date==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) continue;
        $itemsArr = $rec['itemArray'];
        $isReturnReceipt = costco_is_return_receipt($rec);
        $titles=[]; foreach($itemsArr as $it) if(is_array($it)) $titles[] = trim((string)($it['itemDescription01'] ?? $it['itemActualName'] ?? ''));
        $itemsText = implode('; ', array_filter($titles));
        $tenders=[]; foreach(($rec['tenderArray'] ?? []) as $t) if(is_array($t)) $tenders[] = trim((string)($t['tenderDescription'] ?? '').' '.(string)($t['displayAccountNumber'] ?? '').' $'.(string)($t['amountTender'] ?? ''));
        $notes = 'Costco ' . (string)($rec['receiptType'] ?? '') . ' warehouse ' . (string)($rec['warehouseNumber'] ?? '') . ' ' . (string)($rec['warehouseShortName'] ?? '') . '; barcode ' . (string)($rec['transactionBarcode'] ?? '') . '; transaction ' . (string)($rec['transactionNumber'] ?? '') . '; tender ' . implode('; ', $tenders);
        $storedPayment = costco_stored_value_payment_from_tenders($rec);
        $warning=[];
        if($isReturnReceipt) $warning[]='Costco return/refund receipt detected; negative item rows are imported as returned items, not coupons.';
        if($storedPayment > 0.005) $warning[]='Costco Cash / Shop Card / reward payment present; treated as a payment source, not as a bill discount.';
        if((float)($rec['instantSavings'] ?? 0) > 0) $warning[]='Instant savings/coupons present; discount lines are imported as negative bill lines where present.';
        if((float)($rec['orderDiscountAmount'] ?? 0) > 0) $warning[]='Online order discount/coupon tender present; imported as a negative discount line so the bill total reconciles to Costco total.';
        $stmt=$db->prepare('INSERT INTO orders
            (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes)
            VALUES ("costco",:order_id,"",:order_date,:recipient,:items,:total,0,0,:gift,:tax,0,:payments,:expense_account,:item_amount,:tax_account,"",:warning,:notes)
            ON CONFLICT(vendor, order_id) DO UPDATE SET
              order_date=excluded.order_date, recipient=excluded.recipient, items=excluded.items, total=excluded.total, tax=excluded.tax, payments=excluded.payments,
              item_amount=excluded.item_amount, tax_account=excluded.tax_account, warning=excluded.warning, notes=excluded.notes');
        $stmt->bindValue(':order_id',$order_id,SQLITE3_TEXT); $stmt->bindValue(':order_date',$date,SQLITE3_TEXT);
        $stmt->bindValue(':recipient',(string)($rec['warehouseName'] ?? ''),SQLITE3_TEXT); $stmt->bindValue(':items',$itemsText,SQLITE3_TEXT);
        $stmt->bindValue(':total',(float)($rec['total'] ?? 0),SQLITE3_FLOAT); $stmt->bindValue(':gift',$storedPayment,SQLITE3_FLOAT); $stmt->bindValue(':tax',(float)($rec['taxes'] ?? 0),SQLITE3_FLOAT);
        $stmt->bindValue(':payments',implode('; ',$tenders),SQLITE3_TEXT); $stmt->bindValue(':expense_account',default_account_for_items($itemsText, $validAccounts),SQLITE3_TEXT);
        $stmt->bindValue(':item_amount',(float)($rec['subTotal'] ?? 0),SQLITE3_FLOAT); $stmt->bindValue(':tax_account',default_config_value('DEFAULT_TAX_ACCOUNT'),SQLITE3_TEXT);
        $stmt->bindValue(':warning',implode(' ',$warning),SQLITE3_TEXT); $stmt->bindValue(':notes',$notes,SQLITE3_TEXT); $stmt->execute(); $orderCount++;
        // Important: Costco variable-weight parsing changed over time, and item_key includes amount/quantity.
        // Delete existing staged item rows for this receipt before reinserting, otherwise a re-import
        // can leave stale low-value meat rows alongside corrected rows and make totals appear wrong.
        $delItems = $db->prepare('DELETE FROM order_items WHERE vendor="costco" AND order_id=:order_id');
        $delItems->bindValue(':order_id', $order_id, SQLITE3_TEXT);
        $delItems->execute();
        $insert=$db->prepare('INSERT INTO order_items
            (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, source_amount, subscribe_save, expense_account, notes, match_reason)
            VALUES ("costco",:order_id,:item_key,"",:order_date,:quantity,:description,:item_url,:sku,:unit_price,:source_amount,0,:expense_account,:notes,:match_reason)
            ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
              order_date=excluded.order_date, quantity=excluded.quantity, description=excluded.description, item_url=excluded.item_url, asin=excluded.asin,
              unit_price=excluded.unit_price, source_amount=excluded.source_amount, notes=excluded.notes,
              expense_account=CASE WHEN order_items.expense_account IS NULL OR order_items.expense_account="" OR order_items.expense_account LIKE "%Uncategorized%" THEN excluded.expense_account ELSE order_items.expense_account END,
              match_reason=CASE WHEN order_items.match_reason IS NULL OR order_items.match_reason="" THEN excluded.match_reason ELSE order_items.match_reason END');
        foreach($itemsArr as $idx=>$it){
            if(!is_array($it)) continue;
            $sku=trim((string)($it['itemNumber'] ?? ''));
            $actual=trim((string)($it['itemActualName'] ?? ''));
            $short=trim((string)($it['itemDescription01'] ?? ''));
            $desc2=trim((string)($it['itemDescription02'] ?? ''));
            $amount=(float)($it['amount'] ?? 0);
            $unit=(float)($it['unit'] ?? 1); if(abs($unit) < 0.0001) $unit = 1.0;
            $fuelQty=(float)($it['fuelUnitQuantity'] ?? 0);
            $unitPrice=(float)($it['itemUnitPriceAmount'] ?? 0);
            // Costco's JSON frequently includes fuelUnitQuantity=10 on ordinary warehouse
            // merchandise rows.  That is not a purchased quantity/gallons value.  Treat a row
            // as fuel only when Costco also supplies actual fuel metadata (GAL/uom/grade), or
            // the item text clearly identifies a fuel product.  Otherwise use the normal
            // merchandise `unit` field as quantity and derive price from authoritative amount.
            $fuelMetaText = trim((string)($it['fuelUomCode'] ?? '') . ' ' . (string)($it['fuelUomDescription'] ?? '') . ' ' . (string)($it['fuelGradeCode'] ?? '') . ' ' . (string)($it['fuelGradeDescription'] ?? '') . ' ' . (string)($it['fuelGradeDescriptionFr'] ?? ''));
            $fuelNameText = strtolower($actual . ' ' . $short . ' ' . $desc2);
            $isFuel = ($fuelQty > 0.0001 && $fuelMetaText !== '') || preg_match('/\b(fuel|gasoline|diesel|unleaded|premium\s+unleaded|regular\s+unleaded|propane)\b/i', $fuelNameText);
            $isCoupon = costco_item_is_coupon_adjustment($it);
            $isReturnItem = !$isCoupon && ($isReturnReceipt || $amount < -0.0001);
            $targetSku = '';
            foreach(['frenchItemDescription1','itemDescription01','itemActualName'] as $f) if(isset($it[$f]) && preg_match('/\/?\s*(\d{4,8})/', (string)$it[$f], $m)) { $targetSku=$m[1]; break; }
            if($isCoupon){
                $qty=1.0; $unitPrice=$amount; $desc='[COUPON:'.$sku.'] Costco instant savings / coupon' . ($targetSku ? ' for SKU '.$targetSku : '');
            } else {
                // Costco's amount is authoritative. For fuel, keep gallons as quantity,
                // but calculate unit price from amount/gallons so quantity*price matches
                // the receipt exactly. Costco's displayed pump price can round differently.
                if ($isFuel && $fuelQty > 0.0001) {
                    $qty = round(abs($fuelQty), 6);
                    if ($qty < 0.0001) $qty = 1.0;
                    $unitPrice = $amount / $qty;
                } else {
                    // Costco warehouse JSON exposes `amount` as the authoritative extended line
                    // total.  Several non-fuel receipts also use a scaled `unit` value such as 10
                    // and a rounded two-decimal display unit price.  If we import quantity=10 and
                    // unit_price=0.60 for a $5.99 line, GnuCash calculates $6.00 and the whole
                    // receipt drifts.  Preserve the Costco amount exactly by deriving the import
                    // unit price from amount/quantity and allowing 3-6 decimal prices in export.
                    // For returns, keep a positive quantity and negative unit price; this presents
                    // as a returned item, not as a coupon/discount, while preserving the total.
                    // This rule is intentionally generic for Costco/Walmart-style vendor plugins:
                    // source extended amount wins; displayed unit price is only annotation.
                    $qty = abs($unit) > 0.0001 ? round(abs($unit), 6) : 1.0;
                    $unitPrice = $amount / $qty;
                }
                $desc=($isReturnItem ? '[RETURN:' : '[SKU:') . $sku . '] ' . ($actual ?: $short);
                if($short && $actual && stripos($actual,$short)===false) $desc .= ' (' . $short . ')';
                if($desc2) $desc .= ' - ' . $desc2;
            }
            [$suggested,$reason]=suggest_account($db,'costco','sku',$isCoupon && $targetSku ? $targetSku : $sku,$desc,$gnucashRules,$validAccounts);
            if($suggested==='') $suggested = default_account_for_items($desc, $validAccounts);
            $key=item_key($order_id,$sku,$desc,(string)$amount,(string)$qty);
            $insert->bindValue(':order_id',$order_id,SQLITE3_TEXT); $insert->bindValue(':item_key',$key,SQLITE3_TEXT); $insert->bindValue(':order_date',$date,SQLITE3_TEXT);
            $insert->bindValue(':quantity',$qty,SQLITE3_FLOAT); $insert->bindValue(':description',$desc,SQLITE3_TEXT); $insert->bindValue(':item_url',(string)($it['fullItemImage'] ?? ''),SQLITE3_TEXT);
            $insert->bindValue(':sku',($isCoupon && $targetSku ? $targetSku : $sku),SQLITE3_TEXT); $insert->bindValue(':unit_price',$unitPrice,SQLITE3_FLOAT); $insert->bindValue(':source_amount',$amount,SQLITE3_FLOAT); $insert->bindValue(':expense_account',$suggested,SQLITE3_TEXT);
            $insert->bindValue(':notes','Costco SKU: '.$sku.' Dept: '.(string)($it['itemDepartmentNumber'] ?? '').' Tax: '.(string)($it['taxFlag'] ?? '').' Costco amount: '.fmt_money($amount).' Source unit: '.(string)($it['unit'] ?? '').' Source unit price: '.(string)($it['itemUnitPriceAmount'] ?? '').($fuelQty > 0.0001 ? ' Fuel qty: '.(string)$fuelQty : '').($isReturnItem ? ' Return item: yes' : '').($isReturnReceipt ? ' Return receipt: yes' : '').($targetSku ? ' Target SKU: '.$targetSku : ''),SQLITE3_TEXT);
            $insert->bindValue(':match_reason',$reason,SQLITE3_TEXT); $insert->execute(); $itemCount++;
        }
        // Costco online orders may report coupons/order discounts outside itemArray.
        // Example: transactionBarcode 1173616696 has subTotal 2000.00, taxes 122.46,
        // orderDiscountAmount 400.02, total 1755.24.  The discount appears as tender
        // coupon lines, not itemArray rows, so import a single negative line to reconcile.
        $onlineDiscount = (float)($rec['orderDiscountAmount'] ?? 0);
        $channel = strtolower(trim((string)($rec['channel'] ?? '')));
        $documentType = strtoupper(trim((string)($rec['documentType'] ?? '')));
        if ($onlineDiscount > 0.0001 && ($channel === 'online' || $documentType === 'ONLINE')) {
            $discountAmount = -1.0 * $onlineDiscount;
            $discountDesc = '[DISCOUNT:ONLINE] Costco online order discount / coupon tender';
            [$suggested,$reason]=suggest_account($db,'costco','discount','online-order-discount',$discountDesc,$gnucashRules,$validAccounts);
            if($suggested==='') $suggested = default_account_for_items($itemsText, $validAccounts);
            $discountKey = item_key($order_id,'ONLINE-DISCOUNT',$discountDesc,(string)$discountAmount,'1');
            $insert->bindValue(':order_id',$order_id,SQLITE3_TEXT); $insert->bindValue(':item_key',$discountKey,SQLITE3_TEXT); $insert->bindValue(':order_date',$date,SQLITE3_TEXT);
            $insert->bindValue(':quantity',1.0,SQLITE3_FLOAT); $insert->bindValue(':description',$discountDesc,SQLITE3_TEXT); $insert->bindValue(':item_url','',SQLITE3_TEXT);
            $insert->bindValue(':sku','ONLINE-DISCOUNT',SQLITE3_TEXT); $insert->bindValue(':unit_price',$discountAmount,SQLITE3_FLOAT); $insert->bindValue(':source_amount',$discountAmount,SQLITE3_FLOAT); $insert->bindValue(':expense_account',$suggested,SQLITE3_TEXT);
            $insert->bindValue(':notes','Costco online orderDiscountAmount: '.fmt_money($onlineDiscount).'; imported as negative line. Tender coupons: '.implode('; ',$tenders),SQLITE3_TEXT);
            $insert->bindValue(':match_reason',$reason ?: 'online order discount line',SQLITE3_TEXT); $insert->execute(); $itemCount++;
        }
        // Some Costco online receipts expose an orderDiscountAmount/tender coupon total that
        // still does not reconcile the bill to the reported Visa/vendor total. Example
        // 1173616696 has items 2000.00, tax 122.46, orderDiscountAmount 400.02, but
        // total 1755.24; this implies a hidden positive 32.80 online adjustment/fee.
        // Add a single explicit adjustment line so the staged bill matches Costco exactly.
        if ($channel === 'online' || $documentType === 'ONLINE') {
            $itemSourceTotal = 0.0;
            foreach ($itemsArr as $ait) if (is_array($ait)) $itemSourceTotal += (float)($ait['amount'] ?? 0);
            $taxSource = (float)($rec['taxes'] ?? 0);
            $reportedTotal = (float)($rec['total'] ?? 0);
            $currentCalc = round($itemSourceTotal - $onlineDiscount + $taxSource, 2);
            $onlineAdjustment = round($reportedTotal - $currentCalc, 2);
            if (abs($onlineAdjustment) > 0.009) {
                $adjustDesc = '[ADJUSTMENT:ONLINE] Costco online total reconciliation adjustment';
                [$suggested,$reason]=suggest_account($db,'costco','adjustment','online-total-adjustment',$adjustDesc,$gnucashRules,$validAccounts);
                if($suggested==='') $suggested = isset($validAccounts[default_config_value('DEFAULT_SHIPPING_ACCOUNT')]) ? default_config_value('DEFAULT_SHIPPING_ACCOUNT') : default_account_for_items($itemsText, $validAccounts);
                $adjustKey = item_key($order_id,'ONLINE-ADJUSTMENT',$adjustDesc,(string)$onlineAdjustment,'1');
                $insert->bindValue(':order_id',$order_id,SQLITE3_TEXT); $insert->bindValue(':item_key',$adjustKey,SQLITE3_TEXT); $insert->bindValue(':order_date',$date,SQLITE3_TEXT);
                $insert->bindValue(':quantity',1.0,SQLITE3_FLOAT); $insert->bindValue(':description',$adjustDesc,SQLITE3_TEXT); $insert->bindValue(':item_url','',SQLITE3_TEXT);
                $insert->bindValue(':sku','ONLINE-ADJUSTMENT',SQLITE3_TEXT); $insert->bindValue(':unit_price',$onlineAdjustment,SQLITE3_FLOAT); $insert->bindValue(':source_amount',$onlineAdjustment,SQLITE3_FLOAT); $insert->bindValue(':expense_account',$suggested,SQLITE3_TEXT);
                $insert->bindValue(':notes','Costco online hidden adjustment needed to reconcile source total. Items '.fmt_money($itemSourceTotal).' - discount '.fmt_money($onlineDiscount).' + tax '.fmt_money($taxSource).' = '.fmt_money($currentCalc).'; vendor total '.fmt_money($reportedTotal).'; adjustment '.fmt_money($onlineAdjustment).'.',SQLITE3_TEXT);
                $insert->bindValue(':match_reason',$reason ?: 'online total reconciliation adjustment',SQLITE3_TEXT); $insert->execute(); $itemCount++;
                $warning[] = 'Online order required hidden reconciliation adjustment of $'.fmt_money($onlineAdjustment).' to match Costco total.';
                $warnStmt = $db->prepare('UPDATE orders SET warning = trim(COALESCE(warning,"") || " " || :warning) WHERE vendor="costco" AND order_id=:order_id');
                $warnStmt->bindValue(':warning','Online order required hidden reconciliation adjustment of $'.fmt_money($onlineAdjustment).' to match Costco total.',SQLITE3_TEXT);
                $warnStmt->bindValue(':order_id',$order_id,SQLITE3_TEXT);
                $warnStmt->execute();
            }
        }
    }
    apply_costco_coupon_categories($db);
    return [$orderCount, null, $itemCount];
}

function parse_walmart_date(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+purchase\s*$/i', '', $s);
    $ts = strtotime($s);
    return $ts === false ? '' : date('Y-m-d', $ts);
}
function walmart_product_key(string $name, string $link = ''): string {
    $candidate = trim($link);
    if ($candidate !== '') {
        // Walmart exporter sometimes places a product URL or a truncated title in Product Link.
        // If a URL/item id is present, preserve a stable id; otherwise fall back to normalized title.
        if (preg_match('/(?:\/ip\/[^\/]+\/|[?&]itemId=)(\d{4,})/i', $candidate, $m)) return $m[1];
        if (preg_match('/\b(\d{6,})\b/', $candidate, $m)) return $m[1];
    }
    return normalize_key_text($name);
}
function import_walmart_csv(SQLite3 $db, string $path, string $gnucashPath=''): array {
    $fh = fopen($path, 'rb'); if (!$fh) return [0, 'Could not open uploaded Walmart CSV.'];
    $header = fgetcsv($fh); if (!$header) return [0, 'No header row found.'];
    $header = array_map(fn($v) => normalize_header_name((string)$v), $header);
    $validAccounts = load_valid_account_set($gnucashPath);
    // Load GnuCash history rules once per import. Re-scanning the whole GnuCash book for every Walmart row can exceed PHP max_execution_time.
    $gnucashRules = load_gnucash_history_rules($gnucashPath);
    $orders = [];
    while (($row = fgetcsv($fh)) !== false) {
        $r=[]; foreach($header as $i=>$key) $r[$key] = $row[$i] ?? '';
        $oid = trim((string)($r['order number'] ?? ''));
        if ($oid === '' || strtolower($oid) === 'order number') continue;
        $orders[$oid][] = $r;
    }
    fclose($fh);
    $orderStmt = $db->prepare('INSERT INTO orders
        (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes)
        VALUES ("walmart",:order_id,:order_url,:order_date,:recipient,:items,:total,:shipping,0,:gift,:tax,0,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)
        ON CONFLICT(vendor, order_id) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, recipient=excluded.recipient, items=excluded.items,
          total=excluded.total, shipping=excluded.shipping, tax=excluded.tax, payments=excluded.payments,
          item_amount=excluded.item_amount, tax_account=excluded.tax_account, shipping_account=excluded.shipping_account,
          warning=excluded.warning, notes=excluded.notes');
    $delItems = $db->prepare('DELETE FROM order_items WHERE vendor="walmart" AND order_id=:order_id');
    $itemStmt = $db->prepare('INSERT INTO order_items
        (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, source_amount, subscribe_save, expense_account, notes, match_reason)
        VALUES ("walmart",:order_id,:item_key,"",:order_date,:quantity,:description,:item_url,:product_key,:unit_price,:source_amount,0,:expense_account,:notes,:match_reason)
        ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
          order_date=excluded.order_date, quantity=excluded.quantity, description=excluded.description,
          item_url=excluded.item_url, asin=excluded.asin, unit_price=excluded.unit_price, source_amount=excluded.source_amount,
          notes=excluded.notes, match_reason=excluded.match_reason');
    $orderCount=0; $itemCount=0;
    foreach ($orders as $oid=>$rows) {
        $oid = (string)$oid;
        $first=$rows[0];
        $date=parse_walmart_date((string)($first['order date'] ?? ''));
        if ($date === '') $date=date('Y-m-d');
        $subtotalBefore = money_to_float($first['subtotal (before savings)'] ?? '0');
        $savings = money_to_float($first['savings'] ?? '0');
        $subtotal = money_to_float($first['subtotal'] ?? '0');
        $tax = money_to_float($first['tax'] ?? '0');
        $shipping = money_to_float($first['delivery charges'] ?? '0') + money_to_float($first['bag fee'] ?? '0') + money_to_float($first['tip'] ?? '0');
        $total = money_to_float($first['order total'] ?? '0');
        $targetItemTotal = $subtotalBefore > 0.0001 ? $subtotalBefore : $subtotal;
        $payments = trim((string)($first['payment method'] ?? '') . ' ' . (string)($first['payment messages'] ?? ''));
        $storedPayment = stored_value_payment_from_text($payments, 'walmart');
        $warning=[];
        if($storedPayment > 0.005) $warning[]='Walmart Cash / Gift Card payment present; treated as a payment source, not as a bill discount.';
        if (abs($savings) > 0.0001) $warning[]='Walmart savings/discount present; imported as a negative discount line.';
        if (abs($shipping) > 0.0001) $warning[]='Walmart delivery/bag/tip charge present; imported as shipping/fees line.';
        $orderStmt->bindValue(':order_id',$oid,SQLITE3_TEXT);
        $orderStmt->bindValue(':order_url',walmart_order_url($oid),SQLITE3_TEXT);
        $orderStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
        $orderStmt->bindValue(':recipient',(string)($first['address recipient'] ?? ''),SQLITE3_TEXT);
        $orderStmt->bindValue(':items',implode('; ', array_slice(array_map(fn($rr)=>(string)($rr['product name'] ?? ''), $rows),0,8)),SQLITE3_TEXT);
        $orderStmt->bindValue(':total',$total,SQLITE3_FLOAT);
        $orderStmt->bindValue(':shipping',$shipping,SQLITE3_FLOAT);
        $orderStmt->bindValue(':tax',$tax,SQLITE3_FLOAT);
        $orderStmt->bindValue(':gift',$storedPayment,SQLITE3_FLOAT);
        $orderStmt->bindValue(':payments',$payments,SQLITE3_TEXT);
        $orderStmt->bindValue(':expense_account',default_account_for_items((string)($first['product name'] ?? ''),$validAccounts),SQLITE3_TEXT);
        $orderStmt->bindValue(':item_amount',$targetItemTotal,SQLITE3_FLOAT);
        $orderStmt->bindValue(':tax_account',default_config_value('DEFAULT_TAX_ACCOUNT'),SQLITE3_TEXT);
        $orderStmt->bindValue(':shipping_account',default_config_value('DEFAULT_SHIPPING_ACCOUNT'),SQLITE3_TEXT);
        $orderStmt->bindValue(':warning',implode(' ',$warning),SQLITE3_TEXT);
        $orderStmt->bindValue(':notes','Walmart order. Payment: '.$payments.' Status: '.(string)($first['delivery status'] ?? ''),SQLITE3_TEXT);
        $orderStmt->execute(); $orderCount++;
        $delItems->bindValue(':order_id',$oid,SQLITE3_TEXT); $delItems->execute();
        // The exporter can repeat the same order rows. Keep rows in file order until they reconcile to
        // Walmart subtotal/subtotal-before-savings; later repeated rows are ignored.
        $running=0.0; $lineNo=0;
        foreach ($rows as $idx=>$r) {
            $desc=trim((string)($r['product name'] ?? ''));
            if ($desc==='') continue;
            $lineAmount=money_to_float($r['price'] ?? '0');
            if (abs($lineAmount) < 0.0001) continue;
            if ($targetItemTotal > 0.0001 && $running >= $targetItemTotal - 0.004) break;
            if ($targetItemTotal > 0.0001 && $running + $lineAmount > $targetItemTotal + 0.011) {
                // Likely start of a repeated block; stop rather than overstate the bill.
                break;
            }
            $lineNo++;
            $link=(string)($r['product link'] ?? '');
            $productKey=walmart_product_key($desc,$link);
            [$acct,$reason]=suggest_account($db,'walmart','title',$productKey,$desc,$gnucashRules,$validAccounts);
            $key=item_key($oid,$productKey,$desc,(string)$lineAmount,(string)$lineNo);
            $itemStmt->bindValue(':order_id',$oid,SQLITE3_TEXT);
            $itemStmt->bindValue(':item_key',$key,SQLITE3_TEXT);
            $itemStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
            $itemStmt->bindValue(':quantity',1,SQLITE3_FLOAT);
            $itemStmt->bindValue(':description','[WALMART:'.$productKey.'] '.$desc,SQLITE3_TEXT);
            $itemStmt->bindValue(':item_url',item_display_url('walmart',$link,$desc,$productKey),SQLITE3_TEXT);
            $itemStmt->bindValue(':product_key',$productKey,SQLITE3_TEXT);
            $itemStmt->bindValue(':unit_price',$lineAmount,SQLITE3_FLOAT);
            $itemStmt->bindValue(':source_amount',$lineAmount,SQLITE3_FLOAT);
            $itemStmt->bindValue(':expense_account',$acct,SQLITE3_TEXT);
            $itemStmt->bindValue(':notes','Walmart product key: '.$productKey.' Qty source: '.(string)($r['quantity'] ?? '').' Status: '.(string)($r['delivery status'] ?? ''),SQLITE3_TEXT);
            $itemStmt->bindValue(':match_reason',$reason,SQLITE3_TEXT);
            $itemStmt->execute(); $itemCount++; $running += $lineAmount;
        }
        if (abs($savings) > 0.0001) {
            $desc='[DISCOUNT:WALMART] Walmart savings / discount';
            $amount=$savings; // CSV generally stores savings as negative dollars.
            if ($amount > 0) $amount = -$amount;
            $key=item_key($oid,'WALMART-DISCOUNT',$desc,(string)$amount,'1');
            [$acct,$reason]=suggest_account($db,'walmart','title','walmart discount',$desc,$gnucashRules,$validAccounts);
            $itemStmt->bindValue(':order_id',$oid,SQLITE3_TEXT); $itemStmt->bindValue(':item_key',$key,SQLITE3_TEXT); $itemStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
            $itemStmt->bindValue(':quantity',1,SQLITE3_FLOAT); $itemStmt->bindValue(':description',$desc,SQLITE3_TEXT); $itemStmt->bindValue(':item_url','',SQLITE3_TEXT); $itemStmt->bindValue(':product_key','WALMART-DISCOUNT',SQLITE3_TEXT);
            $itemStmt->bindValue(':unit_price',$amount,SQLITE3_FLOAT); $itemStmt->bindValue(':source_amount',$amount,SQLITE3_FLOAT); $itemStmt->bindValue(':expense_account',$acct,SQLITE3_TEXT); $itemStmt->bindValue(':notes','Walmart savings column: '.(string)($first['savings'] ?? ''),SQLITE3_TEXT); $itemStmt->bindValue(':match_reason',$reason,SQLITE3_TEXT); $itemStmt->execute(); $itemCount++;
        }
        $expected = round($running + $savings + $tax + $shipping, 2);
        $billTarget = round($total + $storedPayment, 2);
        if (abs($expected - $billTarget) > 0.01) {
            $adj = round($billTarget - $expected, 2);
            if (abs($adj) > 0.0001) {
                $desc='[ADJUSTMENT:WALMART] Walmart order reconciliation adjustment';
                $key=item_key($oid,'WALMART-ADJUST',$desc,(string)$adj,'1');
                $acct=is_valid_suggestion_account(default_config_value('DEFAULT_SHIPPING_ACCOUNT'),$validAccounts)?default_config_value('DEFAULT_SHIPPING_ACCOUNT'):'Expenses:Uncategorized';
                $itemStmt->bindValue(':order_id',$oid,SQLITE3_TEXT); $itemStmt->bindValue(':item_key',$key,SQLITE3_TEXT); $itemStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
                $itemStmt->bindValue(':quantity',1,SQLITE3_FLOAT); $itemStmt->bindValue(':description',$desc,SQLITE3_TEXT); $itemStmt->bindValue(':item_url','',SQLITE3_TEXT); $itemStmt->bindValue(':product_key','WALMART-ADJUST',SQLITE3_TEXT);
                $itemStmt->bindValue(':unit_price',$adj,SQLITE3_FLOAT); $itemStmt->bindValue(':source_amount',$adj,SQLITE3_FLOAT); $itemStmt->bindValue(':expense_account',$acct,SQLITE3_TEXT); $itemStmt->bindValue(':notes','Adjustment so Walmart item lines + savings + tax + charges match order total. Running items '.fmt_money($running).', savings '.fmt_money($savings).', tax '.fmt_money($tax).', charges '.fmt_money($shipping).', bill target '.fmt_money($billTarget).'.',SQLITE3_TEXT); $itemStmt->bindValue(':match_reason','Walmart reconciliation adjustment',SQLITE3_TEXT); $itemStmt->execute(); $itemCount++;
                $warnStmt=$db->prepare('UPDATE orders SET warning=trim(COALESCE(warning,"") || " " || :warning) WHERE vendor="walmart" AND order_id=:order_id');
                $warnStmt->bindValue(':warning','Walmart order required reconciliation adjustment of $'.fmt_money($adj).'.',SQLITE3_TEXT); $warnStmt->bindValue(':order_id',$oid,SQLITE3_TEXT); $warnStmt->execute();
            }
        }
    }
    return [$orderCount,null,$itemCount];
}

function lowes_extract_normalized_csvs(string $path): array {
    $tmpDir = '';
    $root = $path;
    if (preg_match('/\.zip$/i', $path) || file_looks_like_zip($path)) {
        $tmpDir = sys_get_temp_dir() . '/lowes-normalized-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true)) return [[], 'Could not create temporary extraction directory for Lowe\'s ZIP.'];
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) return [[], 'Could not open Lowe\'s normalized ZIP.'];
            $zip->extractTo($tmpDir); $zip->close();
        } else {
            $cmd = 'unzip -qq ' . escapeshellarg($path) . ' -d ' . escapeshellarg($tmpDir) . ' 2>&1';
            $out = []; $rc = 0; @exec($cmd, $out, $rc);
            if ($rc !== 0) return [[], 'Could not extract Lowe\'s normalized ZIP. Install php-zip or unzip. unzip output: ' . implode(' ', array_slice($out, 0, 3))];
        }
        $root = $tmpDir;
    }
    $wanted = [
        'orders' => 'lowes_orders.csv',
        'items' => 'lowes_order_items.csv',
        'payments' => 'lowes_order_payments.csv',
        'stored' => 'lowes_order_stored_value_payments.csv',
        'excluded_items' => 'lowes_order_excluded_items.csv',
        'diagnostics' => 'lowes_parse_diagnostics.csv',
    ];
    $found = [];
    if (is_file($root)) {
        $base = basename($root);
        foreach ($wanted as $k=>$name) if ($base === $name) $found[$k] = $root;
    } elseif (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $base = $file->getBasename();
            foreach ($wanted as $k=>$name) if ($base === $name && !isset($found[$k])) $found[$k] = $file->getPathname();
        }
    }
    return [$found, ''];
}

function lowes_csv_assoc_rows(string $path): array {
    if ($path === '' || !is_readable($path)) return [];
    $fh = fopen($path, 'rb'); if (!$fh) return [];
    $header = fgetcsv($fh); if (!$header) { fclose($fh); return []; }
    $header = array_map(fn($v) => normalize_header_name((string)$v), $header);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!array_filter($row, fn($v)=>trim((string)$v)!=='')) continue;
        $r = [];
        foreach ($header as $i=>$key) $r[$key] = $row[$i] ?? '';
        $rows[] = $r;
    }
    fclose($fh); return $rows;
}

function lowes_is_return_doc(array $r): bool {
    $type = strtolower(trim((string)($r['type'] ?? '')));
    $doc = strtolower(trim((string)($r['document_type'] ?? '')));
    $status = strtolower(trim((string)($r['status'] ?? '')));
    return $type === 'return' || str_contains($doc, 'return') || str_contains($status, 'return');
}
function lowes_stage_order_id(array $orderRow): string {
    $oid = trim((string)($orderRow['order_number'] ?? ''));
    if ($oid === '') return '';
    if (lowes_is_return_doc($orderRow) && !str_ends_with($oid, '-CREDIT')) return $oid . '-CREDIT';
    return $oid;
}
function lowes_payment_label(array $p): string {
    $norm = strtoupper(trim((string)($p['method_normalized'] ?? '')));
    $label = trim((string)($p['payment_label'] ?? ''));
    $last4 = trim((string)($p['display_last4'] ?? $p['last4'] ?? ''));
    if ($norm === 'MYLOWES_MONEY' || strtolower((string)($p['payment_class'] ?? '')) === 'stored_value') return "My Lowe's Money";
    if ($label === '') $label = trim((string)($p['method'] ?? 'Card'));
    if ($last4 !== '' && stripos($label, $last4) === false) $label .= ' ' . $last4;
    return trim($label);
}
function lowes_payment_is_stored_value(array $p): bool {
    $norm = strtoupper(trim((string)($p['method_normalized'] ?? '')));
    $class = strtolower(trim((string)($p['payment_class'] ?? '')));
    $method = strtolower(trim((string)($p['method'] ?? $p['payment_label'] ?? '')));
    return $norm === 'MYLOWES_MONEY' || $class === 'stored_value' || $method === 'gc' || str_contains($method, 'my lowe') || str_contains($method, 'gift');
}

function lowes_payment_method_key(array $p): string {
    $norm = strtoupper(trim((string)($p['method_normalized'] ?? '')));
    $class = strtolower(trim((string)($p['payment_class'] ?? '')));
    if ($norm === 'MYLOWES_MONEY' || $class === 'stored_value') return 'mylowes-money';
    $brand = strtolower($norm !== '' ? $norm : (string)($p['payment_label'] ?? $p['method'] ?? 'card'));
    $brand = str_replace(['master card','master-card'], 'mastercard', $brand);
    if ($brand === 'mc') $brand = 'mastercard';
    $last4 = trim((string)($p['display_last4'] ?? $p['last4'] ?? ''));
    return normalize_payment_method_key(trim($brand . ($last4 !== '' ? ' ' . $last4 : '')));
}
function lowes_guess_account_for_last4(string $gnucashPath, string $last4, string $fallback = ''): string {
    $last4 = preg_replace('/\D+/', '', $last4);
    if ($last4 === '') return $fallback;
    [$accounts, $status] = load_gnucash_accounts_with_status($gnucashPath, ['Liabilities:', 'Assets:'], 'payment');
    $best = '';
    $bestScore = -999;
    foreach ($accounts as $a) {
        $name = (string)($a['name'] ?? '');
        if ($name === '' || !str_contains($name, $last4)) continue;
        $lc = strtolower($name); $score = 0;
        if (str_starts_with($name, 'Liabilities:Credit Cards:')) $score += 100;
        elseif (str_starts_with($name, 'Liabilities:')) $score += 60;
        elseif (str_starts_with($name, 'Assets:')) $score += 30;
        if (preg_match('/(?:^|[^0-9])' . preg_quote($last4, '/') . '(?:[^0-9]|$)/', $name)) $score += 20;
        if (str_contains($lc, 'credit') || str_contains($lc, 'card') || str_contains($lc, 'visa') || str_contains($lc, 'master')) $score += 10;
        $score -= min(strlen($name), 200) / 1000;
        if ($score > $bestScore) { $bestScore = $score; $best = $name; }
    }
    return $best !== '' ? $best : $fallback;
}
function lowes_default_payment_account(SQLite3 $db, string $gnucashPath, array $p): string {
    $class = strtolower(trim((string)($p['payment_class'] ?? '')));
    $norm = strtoupper(trim((string)($p['method_normalized'] ?? '')));
    if ($class === 'stored_value' || $norm === 'MYLOWES_MONEY') return default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES');
    $hint = trim((string)($p['account_hint'] ?? ''));
    $last4 = trim((string)($p['display_last4'] ?? $p['last4'] ?? ''));
    return lowes_guess_account_for_last4($gnucashPath, $last4, $hint);
}
function lowes_upsert_payment_method(SQLite3 $db, string $methodKey, string $display, string $account): void {
    if ($methodKey === '') return;
    $stmt = $db->prepare('INSERT INTO payment_method_accounts (vendor, method_key, display_name, account_fullname, updated_at) VALUES ("lowes",:key,:display,:acct,CURRENT_TIMESTAMP)
        ON CONFLICT(vendor, method_key) DO UPDATE SET display_name=excluded.display_name, account_fullname=CASE WHEN payment_method_accounts.account_fullname="" THEN excluded.account_fullname ELSE payment_method_accounts.account_fullname END, updated_at=CURRENT_TIMESTAMP');
    $stmt->bindValue(':key', $methodKey, SQLITE3_TEXT); $stmt->bindValue(':display', $display, SQLITE3_TEXT); $stmt->bindValue(':acct', $account, SQLITE3_TEXT); $stmt->execute();
}

function lowes_payment_row_unique_key(array $p, int $pidx, string $orderId): string {
    // Lowe's normalized v10 sometimes leaves payment_key blank for each tender.
    // Do not use a blank payment_key as the row identity: duplicate My Lowe's
    // Money denominations (e.g. $20 + $20, $5 + $5) would collapse into one
    // vendor_payments row through ON CONFLICT(payment_id).  Prefer identifiers
    // that distinguish stored-value cards, then fall back to an explicit row
    // sequence so every source payment row survives re-import.
    $rawKey = trim((string)($p['payment_key'] ?? ''));
    if ($rawKey !== '') return $rawKey;
    $identifier = trim((string)($p['payment_identifier'] ?? ''));
    if ($identifier === '') $identifier = trim((string)($p['last4'] ?? ''));
    $method = trim((string)($p['method_normalized'] ?? $p['method'] ?? $p['payment_label'] ?? ''));
    $amount = fmt_money(abs(money_to_float((string)($p['amount'] ?? '0'))));
    $refund = fmt_money(abs(money_to_float((string)($p['refund_amount'] ?? '0'))));
    return 'row' . str_pad((string)($pidx + 1), 3, '0', STR_PAD_LEFT) . '|' . $method . '|' . $identifier . '|' . $amount . '|refund:' . $refund;
}
function lowes_payment_reconciliation_summary(float $sourceTotal, float $stored, float $cardTotal, int $storedRows, int $cardRows, bool $isReturn): array {
    // For Lowe's sales, card charge should generally equal invoice total minus
    // My Lowe's Money / gift-card stored value.  Returns are handled through
    // separate credit documents/refund evidence and are not evaluated here.
    if ($isReturn) return ['ok'=>true, 'message'=>''];
    $expectedCard = round(abs($sourceTotal) - abs($stored), 2);
    if ($expectedCard < 0 && $expectedCard > -0.01) $expectedCard = 0.0;
    $delta = round(abs($cardTotal) - $expectedCard, 2);
    $ok = abs($delta) <= 0.01;
    $msg = 'Payment reconciliation: invoice total $' . fmt_money(abs($sourceTotal)) .
        ' - My Lowe\'s Money/stored value $' . fmt_money(abs($stored)) .
        ' = expected card/bank charge $' . fmt_money($expectedCard) .
        '; staged card/bank payments total $' . fmt_money(abs($cardTotal)) .
        ' across ' . $cardRows . ' row(s); stored-value rows=' . $storedRows . '.';
    if (!$ok) $msg .= ' Review Lowe\'s normalized payment rows before Step 4/5; duplicate stored-value denominations or missing card rows may cause payment matching errors.';
    return ['ok'=>$ok, 'message'=>$msg, 'expected_card'=>$expectedCard, 'card_total'=>round(abs($cardTotal), 2), 'stored_total'=>round(abs($stored), 2), 'delta'=>$delta];
}

function lowes_item_is_return_line(array $it): bool {
    $status = strtolower(trim((string)($it['status'] ?? '')));
    $retStatus = strtolower(trim((string)($it['source_return_release_status'] ?? '')));
    return str_contains($status, 'return') || str_contains($retStatus, 'return');
}
function lowes_item_total_with_tax(array $it): float {
    $lineTotal = money_to_float((string)($it['line_total_with_tax'] ?? '0'));
    if (abs($lineTotal) > 0.0001) return abs($lineTotal);
    $amount = abs(money_to_float((string)($it['extended_amount'] ?? '0')));
    $tax = abs(money_to_float((string)($it['tax'] ?? '0')));
    return round($amount + $tax, 2);
}
function lowes_credit_suffix_for_item(array $it, int $idx): string {
    $sku = preg_replace('/[^A-Za-z0-9]+/', '', (string)($it['sku'] ?? ''));
    if ($sku === '') $sku = preg_replace('/[^A-Za-z0-9]+/', '', (string)($it['omni_item_id'] ?? ''));
    if ($sku === '') $sku = 'LINE' . str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
    return substr($sku, 0, 24);
}
function lowes_card_last4s_from_payments(array $payments): array {
    $out = [];
    foreach ($payments as $p) {
        if (lowes_payment_is_stored_value($p)) continue;
        $last4 = preg_replace('/\D+/', '', (string)($p['display_last4'] ?? $p['last4'] ?? $p['payment_identifier'] ?? ''));
        if ($last4 !== '') $out[substr($last4, -4)] = true;
    }
    return array_keys($out);
}
function lowes_find_gnucash_refund_match(string $gnucashPath, string $date, float $amount, array $last4s, int $windowDays = 14): array {
    $resolved = resolve_local_path($gnucashPath);
    $amount = round(abs($amount), 2);
    if ($resolved === '' || !is_file($resolved) || !is_readable($resolved) || $amount < 0.005) return [];
    $date = normalize_import_date($date);
    if ($date === '') return [];
    $start = new DateTimeImmutable($date);
    $end = $start->modify('+' . max(0, min(120, $windowDays)) . ' days');
    try {
        $gdb = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $gdb->busyTimeout(30000);
        $acctMap = gnucash_account_fullname_map_sqlite($gdb);
        $acctGuids = [];
        foreach ($acctMap as $guid=>$a) {
            $name = (string)($a['name'] ?? '');
            if ($name === '') continue;
            $lc = strtolower($name);
            if (!str_starts_with($name, 'Liabilities:Credit Cards:') && !str_contains($lc, 'credit card')) continue;
            if ($last4s) {
                $ok = false;
                foreach ($last4s as $l4) if ($l4 !== '' && str_contains($name, $l4)) { $ok = true; break; }
                if (!$ok) continue;
            }
            $acctGuids[$guid] = $name;
        }
        if (!$acctGuids) return [];
        [$aph, $aparams] = sqlite_placeholders(array_keys($acctGuids), 'acct');
        $amtExpr = gnc_amount_sql('s');
        $q = $gdb->prepare('SELECT t.guid AS tx_guid, t.post_date AS post_date, t.description AS description, s.account_guid AS account_guid, ' . $amtExpr . ' AS amount FROM splits s JOIN transactions t ON t.guid=s.tx_guid WHERE s.account_guid IN (' . implode(',', $aph) . ') AND t.post_date >= :start AND t.post_date < :end ORDER BY t.post_date, t.description');
        sqlite_bind_all($q, $aparams);
        $q->bindValue(':start', $start->format('Y-m-d') . ' 00:00:00', SQLITE3_TEXT);
        $q->bindValue(':end', $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00', SQLITE3_TEXT);
        $res = $q->execute();
        $candidates = [];
        while ($res && ($r=$res->fetchArray(SQLITE3_ASSOC))) {
            $val = round((float)($r['amount'] ?? 0), 2);
            // For credit-card liability accounts, a refund/credit normally appears as a positive value.
            if ($val <= 0.004) continue;
            if (abs(abs($val) - $amount) > 0.01) continue;
            $desc = (string)($r['description'] ?? '');
            $score = 0;
            if (stripos($desc, 'LOWES') !== false || stripos($desc, "LOWE'S") !== false) $score += 100;
            $postDate = substr((string)($r['post_date'] ?? ''), 0, 10);
            $days = 999;
            try { $days = abs((new DateTimeImmutable($postDate))->diff($start)->days); } catch (Throwable $e) {}
            $score -= min($days, 365);
            $candidates[] = [
                'tx_guid'=>(string)($r['tx_guid'] ?? ''),
                'post_date'=>$postDate,
                'description'=>$desc,
                'account'=>$acctGuids[(string)($r['account_guid'] ?? '')] ?? '',
                'amount'=>$val,
                'date_distance'=>$days,
                'score'=>$score,
            ];
        }
        usort($candidates, fn($a,$b)=>($b['score'] <=> $a['score']) ?: ($a['date_distance'] <=> $b['date_distance']));
        return $candidates[0] ?? [];
    } catch (Throwable $e) { return []; }
}
function lowes_partial_return_credit_candidates(array $orderRow, array $orderItems, array $orderPayments, string $gnucashPath): array {
    // Lowe's can embed returned lines inside an otherwise normal SalesOrder detail page.
    // The order header remains a sale and the payment rows only describe the original charge,
    // so older versions missed the separate vendor credit/refund workflow.  Create synthetic
    // credit candidates only when a returned line's total-with-tax matches an imported card
    // refund in the selected GnuCash copy; this avoids producing credits for every line that
    // Lowe's marks "Return Completed" on the page when no corresponding card refund exists.
    if (lowes_is_return_doc($orderRow)) return [];
    $rawOid = trim((string)($orderRow['order_number'] ?? ''));
    if ($rawOid === '') return [];
    $date = normalize_import_date((string)($orderRow['purchase_date'] ?? ''));
    $last4s = lowes_card_last4s_from_payments($orderPayments);
    $window = 14;
    try { $window = max($window, lowes_payment_match_date_window_days($GLOBALS['db'] ?? null)); } catch (Throwable $e) {}
    $manualStageMin = 100.00;
    try { $manualStageMin = lowes_partial_return_manual_stage_min_amount($GLOBALS['db'] ?? null); } catch (Throwable $e) {}
    $out = [];
    foreach ($orderItems as $idx=>$it) {
        if (!lowes_item_is_return_line($it)) continue;
        $creditTotal = lowes_item_total_with_tax($it);
        if ($creditTotal < 0.005) continue;
        $match = lowes_find_gnucash_refund_match($gnucashPath, $date, $creditTotal, $last4s, $window);
        $hasRefundEvidence = !empty($match);
        // If the selected GnuCash copy was stale/not selected at import time, exact
        // refund evidence may not be visible even though Lowe's clearly marks a
        // large in-sale return line.  Stage high-value returned lines as reviewable
        // CREDIT memo targets instead of silently dropping them.  Smaller returned
        // plumbing/parts lines remain evidence-gated to avoid noisy false positives.
        if (!$hasRefundEvidence && $creditTotal + 0.0001 < $manualStageMin) continue;
        $suffix = lowes_credit_suffix_for_item($it, $idx);
        $creditId = $rawOid . '-' . $suffix . '-CREDIT';
        $out[] = [
            'order_id'=>$creditId,
            'raw_order_id'=>$rawOid,
            'date'=>$date,
            'item'=>$it,
            'idx'=>$idx,
            'credit_total'=>$creditTotal,
            'refund_match'=>$match,
            'last4s'=>$last4s,
            'has_refund_evidence'=>$hasRefundEvidence,
            'manual_stage_min'=>$manualStageMin,
        ];
    }
    return $out;
}

function import_lowes_normalized_zip(SQLite3 $db, string $path, string $gnucashPath = ''): array {
    [$files, $err] = lowes_extract_normalized_csvs($path);
    if ($err !== '') return [0, $err, 0];
    if (empty($files['orders']) || empty($files['items']) || empty($files['payments'])) return [0, 'Lowe\'s normalized import requires lowes_orders.csv, lowes_order_items.csv, and lowes_order_payments.csv in the uploaded ZIP/folder.', 0];
    $orders = lowes_csv_assoc_rows($files['orders']);
    $items = lowes_csv_assoc_rows($files['items']);
    $payments = lowes_csv_assoc_rows($files['payments']);
    $storedPayments = !empty($files['stored']) ? lowes_csv_assoc_rows($files['stored']) : [];
    $itemsByOrder = [];
    foreach ($items as $it) { $oid = trim((string)($it['order_number'] ?? '')); if ($oid !== '') $itemsByOrder[$oid][] = $it; }
    $storedByOrder = [];
    foreach ($storedPayments as $sp) { $oid = trim((string)($sp['order_number'] ?? '')); if ($oid !== '') $storedByOrder[$oid][] = $sp; }
    $paymentsByOrder = [];
    foreach ($payments as $p) {
        $oid = trim((string)($p['order_number'] ?? '')); if ($oid === '') continue;
        // v172: if the normalized package includes the dedicated stored-value CSV,
        // treat that file as authoritative for My Lowe's Money rows.  Older scraper
        // or export paths could collapse duplicate same-denomination tenders in
        // lowes_order_payments.csv; lowes_order_stored_value_payments.csv is the
        // safest place to recover all GC/My Lowe's Money rows.  Keep non-stored
        // card/bank rows from the main payments CSV, then append the stored rows
        // below.
        if (isset($storedByOrder[$oid]) && lowes_payment_is_stored_value($p)) continue;
        $paymentsByOrder[$oid][] = $p;
    }
    foreach ($storedByOrder as $oid=>$srows) {
        foreach ($srows as $sp) $paymentsByOrder[$oid][] = $sp;
    }
    $validAccounts = load_valid_account_set($gnucashPath);
    $gnucashRules = load_gnucash_history_rules($gnucashPath);
    $orderStmt = $db->prepare('INSERT INTO orders
        (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes)
        VALUES ("lowes",:order_id,:order_url,:order_date,:recipient,:items,:total,:shipping,0,:gift,:tax,:refund,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)
        ON CONFLICT(vendor, order_id) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, recipient=excluded.recipient, items=excluded.items,
          total=excluded.total, shipping=excluded.shipping, gift=excluded.gift, tax=excluded.tax, refund=excluded.refund, payments=excluded.payments,
          item_amount=excluded.item_amount, tax_account=excluded.tax_account, shipping_account=excluded.shipping_account,
          warning=excluded.warning, notes=excluded.notes');
    $delItems = $db->prepare('DELETE FROM order_items WHERE vendor="lowes" AND order_id=:order_id');
    $itemStmt = $db->prepare('INSERT INTO order_items
        (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, source_amount, subscribe_save, expense_account, notes, match_reason)
        VALUES ("lowes",:order_id,:item_key,:order_url,:order_date,:quantity,:description,:item_url,:sku,:unit_price,:source_amount,0,:expense_account,:notes,:match_reason)
        ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, quantity=excluded.quantity, description=excluded.description,
          item_url=excluded.item_url, asin=excluded.asin, unit_price=excluded.unit_price, source_amount=excluded.source_amount,
          notes=excluded.notes, match_reason=excluded.match_reason');
    $payStmt = $db->prepare('INSERT INTO vendor_payments (vendor,payment_id,order_id,order_url,payment_date,payee,payment_method,method_key,amount,account_fullname,match_status,notes)
        VALUES ("lowes",:pid,:oid,:url,:pdate,"Lowe\'s",:method,:mkey,:amount,:acct,:status,:notes)
        ON CONFLICT(vendor,payment_id) DO UPDATE SET order_id=excluded.order_id, order_url=excluded.order_url, payment_date=excluded.payment_date, payment_method=excluded.payment_method, method_key=excluded.method_key, amount=excluded.amount, account_fullname=excluded.account_fullname, match_status=excluded.match_status, notes=excluded.notes');
    $delPayments = $db->prepare('DELETE FROM vendor_payments WHERE vendor="lowes" AND order_id=:order_id');
    $delPartialItems = $db->prepare('DELETE FROM order_items WHERE vendor="lowes" AND order_id LIKE :partial_prefix');
    $delPartialPayments = $db->prepare('DELETE FROM vendor_payments WHERE vendor="lowes" AND order_id LIKE :partial_prefix');
    $delPartialOrders = $db->prepare('DELETE FROM orders WHERE vendor="lowes" AND order_id LIKE :partial_prefix');
    $orderCount = 0; $itemCount = 0; $paymentCount = 0; $storedTotal = 0.0; $ccAutoMatched = 0;
    foreach ($orders as $o) {
        $rawOid = trim((string)($o['order_number'] ?? '')); if ($rawOid === '') continue;
        $date = normalize_import_date((string)($o['purchase_date'] ?? '')); if ($date === '') $date = date('Y-m-d');
        $isReturn = lowes_is_return_doc($o); $sign = $isReturn ? -1.0 : 1.0; $orderId = lowes_stage_order_id($o);
        $orderItems = $itemsByOrder[$rawOid] ?? [];
        $orderPayments = $paymentsByOrder[$rawOid] ?? [];
        $partialReturnCredits = lowes_partial_return_credit_candidates($o, $orderItems, $orderPayments, $gnucashPath);
        // Remove previously synthesized in-sale partial return targets for this raw order before recreating the current set.
        // This prevents stale synthetic CREDIT rows from surviving when the selected GnuCash copy/refund evidence changes.
        $partialPrefix = $rawOid . "-%-CREDIT";
        $delPartialItems->bindValue(':partial_prefix',$partialPrefix,SQLITE3_TEXT); $delPartialItems->execute();
        $delPartialPayments->bindValue(':partial_prefix',$partialPrefix,SQLITE3_TEXT); $delPartialPayments->execute();
        $delPartialOrders->bindValue(':partial_prefix',$partialPrefix,SQLITE3_TEXT); $delPartialOrders->execute();
        $titles = [];
        foreach ($orderItems as $it) { $d = trim((string)($it['description'] ?? '')); if ($d !== '') $titles[] = $d; }
        $itemsText = implode('; ', array_slice($titles, 0, 8));
        $stored = 0.0; $cardTenderTotal = 0.0; $storedTenderRows = 0; $cardTenderRows = 0; $paymentLabels = [];
        foreach ($orderPayments as $p) {
            $amt = abs(money_to_float((string)($p['amount'] ?? '0')));
            $isStoredTender = (strtolower((string)($p['payment_class'] ?? '')) === 'stored_value' || strtoupper((string)($p['method_normalized'] ?? '')) === 'MYLOWES_MONEY');
            if ($isStoredTender) { $stored += $amt; $storedTenderRows++; }
            elseif ($amt > 0.0001) { $cardTenderTotal += $amt; $cardTenderRows++; }
            $paymentLabels[] = lowes_payment_label($p) . ' $' . fmt_money($amt);
        }
        $sourceTotal = abs(money_to_float((string)($o['total'] ?? '0')));
        $tax = $sign * abs(money_to_float((string)($o['tax'] ?? '0')));
        $shipSource = abs(money_to_float((string)($o['shipping'] ?? '0')));
        $shipping = (strtolower((string)($o['shipping_used_in_validation'] ?? '')) === 'yes') ? $sign * $shipSource : 0.0;
        $itemAmount = $sign * abs(money_to_float((string)($o['calculated_item_subtotal'] ?? ($o['subtotal'] ?? '0'))));
        $orderTotalForCard = $isReturn ? -$sourceTotal : round($sourceTotal - $stored, 2);
        $warning = [];
        if ($isReturn) $warning[] = "Lowe's return/credit document; imported as negative credit memo lines.";
        if ($stored > 0.005) $warning[] = "My Lowe's Money / GC stored-value payment present; treated as payment source, not bill discount.";
        if (abs(money_to_float((string)($o['adjustments'] ?? '0'))) > 0.005) $warning[] = "Lowe's adjustment/discount present (often military discount); item extended amounts are already net and source header tax is used.";
        if (abs(money_to_float((string)($o['validation_delta_total_minus_item_lines'] ?? '0'))) > 0.01) $warning[] = "Lowe's normalized validation delta is non-zero; review before export.";
        if (!empty($partialReturnCredits)) $warning[] = "Lowe's partial in-sale return detected: " . count($partialReturnCredits) . " matching card refund line(s) will be staged as separate synthetic CREDIT memo(s).";
        $recon = lowes_payment_reconciliation_summary($sourceTotal, $stored, $cardTenderTotal, $storedTenderRows, $cardTenderRows, $isReturn);
        if (!($recon['ok'] ?? true) || $storedTenderRows > 1) $warning[] = (string)($recon['message'] ?? '');
        $orderStmt->bindValue(':order_id',$orderId,SQLITE3_TEXT);
        $orderStmt->bindValue(':order_url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT);
        $orderStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
        $orderStmt->bindValue(':recipient','',SQLITE3_TEXT);
        $orderStmt->bindValue(':items',$itemsText,SQLITE3_TEXT);
        $orderStmt->bindValue(':total',$orderTotalForCard,SQLITE3_FLOAT);
        $orderStmt->bindValue(':shipping',$shipping,SQLITE3_FLOAT);
        $orderStmt->bindValue(':gift',$isReturn ? 0.0 : $stored,SQLITE3_FLOAT);
        $orderStmt->bindValue(':tax',$tax,SQLITE3_FLOAT);
        $orderStmt->bindValue(':refund',$isReturn ? $sourceTotal : 0.0,SQLITE3_FLOAT);
        $orderStmt->bindValue(':payments',implode('; ', $paymentLabels),SQLITE3_TEXT);
        $orderStmt->bindValue(':expense_account','Expenses:Uncategorized',SQLITE3_TEXT);
        $orderStmt->bindValue(':item_amount',$itemAmount,SQLITE3_FLOAT);
        $orderStmt->bindValue(':tax_account',default_config_value('DEFAULT_TAX_ACCOUNT'),SQLITE3_TEXT);
        $orderStmt->bindValue(':shipping_account',default_config_value('DEFAULT_SHIPPING_ACCOUNT'),SQLITE3_TEXT);
        $orderStmt->bindValue(':warning',implode(' ', $warning),SQLITE3_TEXT);
        $orderStmt->bindValue(':notes',"Lowe's normalized import. Source type: " . (string)($o['type'] ?? '') . '; status: ' . (string)($o['status'] ?? '') . '; channel: ' . (string)($o['channel'] ?? '') . '; store: ' . (string)($o['store_number'] ?? '') . '. Header tax is source of truth.');
        $orderStmt->execute(); $orderCount++;
        $delItems->bindValue(':order_id',$orderId,SQLITE3_TEXT); $delItems->execute();
        foreach ($orderItems as $idx=>$it) {
            $desc = trim((string)($it['description'] ?? '')); if ($desc === '') continue;
            $qty = abs((float)($it['quantity'] ?? 1)); if ($qty < 0.0001) $qty = 1.0;
            $amount = $sign * abs(money_to_float((string)($it['extended_amount'] ?? '0'))); if (abs($amount) < 0.0001) continue;
            $unit = $amount / $qty;
            $sku = trim((string)($it['sku'] ?? ($it['omni_item_id'] ?? '')));
            $lineKey = trim((string)($it['line_key'] ?? '')) ?: (string)($idx+1);
            $itemKey = item_key($orderId, $sku !== '' ? $sku : $lineKey, $desc, (string)$amount, (string)$qty, $idx+1);
            [$acct,$reason] = suggest_account($db, 'lowes', 'sku', $sku, $desc, $gnucashRules, $validAccounts);
            if ($acct === '') { $acct = 'Expenses:Uncategorized'; $reason = 'Lowe\'s invoice-specific category review required; SKU rules are not auto-overwritten.'; }
            $prefix = $isReturn ? '[LOWES-RETURN:' : '[LOWES:';
            $fullDesc = $prefix . ($sku !== '' ? $sku : 'ITEM') . '] ' . trim(((string)($it['brand'] ?? '') !== '' ? (string)$it['brand'] . ' ' : '') . $desc);
            $notes = 'Lowe\'s line key: ' . $lineKey . '; model: ' . (string)($it['model_number'] ?? '') . '; discount_total: ' . (string)($it['discount_total'] ?? '') . '; status: ' . (string)($it['status'] ?? '') . '; tax evidence: ' . (string)($it['tax'] ?? '') . '.';
            $itemStmt->bindValue(':order_id',$orderId,SQLITE3_TEXT); $itemStmt->bindValue(':item_key',$itemKey,SQLITE3_TEXT); $itemStmt->bindValue(':order_url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT); $itemStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
            $itemStmt->bindValue(':quantity',$qty,SQLITE3_FLOAT); $itemStmt->bindValue(':description',$fullDesc,SQLITE3_TEXT); $itemStmt->bindValue(':item_url',(string)($it['product_url'] ?? ''),SQLITE3_TEXT); $itemStmt->bindValue(':sku',$sku,SQLITE3_TEXT);
            $itemStmt->bindValue(':unit_price',$unit,SQLITE3_FLOAT); $itemStmt->bindValue(':source_amount',$amount,SQLITE3_FLOAT); $itemStmt->bindValue(':expense_account',$acct,SQLITE3_TEXT); $itemStmt->bindValue(':notes',$notes,SQLITE3_TEXT); $itemStmt->bindValue(':match_reason',$reason,SQLITE3_TEXT); $itemStmt->execute(); $itemCount++;
        }
        // Re-importing Lowe's normalized data should make vendor_payments reflect the
        // current source CSV exactly.  Delete old rows for this order before inserting
        // the freshly parsed rows so a v171 row-identity change cannot leave stale
        // collapsed/duplicate payment rows behind.
        $delPayments->bindValue(':order_id',$orderId,SQLITE3_TEXT); $delPayments->execute();
        foreach ($orderPayments as $pidx=>$p) {
            $amt = abs(money_to_float((string)($p['amount'] ?? '0'))); if ($amt < 0.0001) continue;
            $method = lowes_payment_label($p); $mkey = lowes_payment_method_key($p); $acct = lowes_default_payment_account($db, $gnucashPath, $p);
            if ($acct !== '' && preg_match('/\d{4}$/', (string)($p['display_last4'] ?? $p['last4'] ?? '')) && !str_contains((string)($p['account_hint'] ?? ''), $acct)) $ccAutoMatched++;
            lowes_upsert_payment_method($db, $mkey, $method, $acct);
            $map = load_payment_account_map($db, 'lowes'); $pm = $map['lowes|'.$mkey] ?? [];
            $excluded = (int)($pm['excluded'] ?? 0) === 1; $acctFinal = (string)($pm['account_fullname'] ?? $acct);
            $signedAmt = $isReturn ? $amt : -$amt;
            $status = $excluded ? 'excluded_payment_method' : 'matched_order_id';
            $rowKey = lowes_payment_row_unique_key($p, (int)$pidx, $orderId);
            $pid = sha1('lowes|' . $orderId . '|' . $rowKey . '|' . $method . '|' . fmt_money($signedAmt));
            $note = "Lowe's normalized payment row. importer_treatment=" . (string)($p['importer_treatment'] ?? '') . '; identifier=' . (string)($p['payment_identifier'] ?? '') . '; row_key=' . $rowKey . '; refund_amount evidence=' . (string)($p['refund_amount'] ?? '0') . '. ';
            if ((float)($p['refund_amount'] ?? 0) > 0.005) $note .= 'Refund amount is evidence only; import the separate Lowe\'s return/credit document and match the actual card refund in GnuCash to avoid double-counting.';
            $payStmt->bindValue(':pid',$pid,SQLITE3_TEXT); $payStmt->bindValue(':oid',$orderId,SQLITE3_TEXT); $payStmt->bindValue(':url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT); $payStmt->bindValue(':pdate',$date,SQLITE3_TEXT); $payStmt->bindValue(':method',$method,SQLITE3_TEXT); $payStmt->bindValue(':mkey',$mkey,SQLITE3_TEXT); $payStmt->bindValue(':amount',$signedAmt,SQLITE3_FLOAT); $payStmt->bindValue(':acct',$acctFinal,SQLITE3_TEXT); $payStmt->bindValue(':status',$status,SQLITE3_TEXT); $payStmt->bindValue(':notes',$note,SQLITE3_TEXT); $payStmt->execute(); $paymentCount++; if (strtolower((string)($p['payment_class'] ?? '')) === 'stored_value') $storedTotal += $amt;
        }
        foreach ($partialReturnCredits as $credit) {
            $cit = $credit['item'];
            $creditOrderId = (string)$credit['order_id'];
            $creditTotal = (float)$credit['credit_total'];
            $creditTax = abs(money_to_float((string)($cit['tax'] ?? '0')));
            $creditItemAmount = round($creditTotal - $creditTax, 2);
            if ($creditItemAmount < 0.005) $creditItemAmount = abs(money_to_float((string)($cit['extended_amount'] ?? '0')));
            $refundMatch = (array)($credit['refund_match'] ?? []);
            $hasRefundEvidence = !empty($credit['has_refund_evidence']);
            if ($hasRefundEvidence) {
                $creditWarn = "Synthetic Lowe's CREDIT memo staged from a returned line embedded inside sale order {$rawOid}; matched imported card refund $" . fmt_money($creditTotal) . " in " . (string)($refundMatch['account'] ?? '') . " on " . (string)($refundMatch['post_date'] ?? '') . ".";
            } else {
                $creditWarn = "Synthetic Lowe's CREDIT memo staged for manual review from a high-value returned line embedded inside sale order {$rawOid}; no exact imported card refund was visible during import. Verify the card refund/payment row before Step 5. Manual-stage threshold $" . fmt_money((float)($credit['manual_stage_min'] ?? 100.0)) . ".";
            }
            $desc = trim((string)($cit['description'] ?? 'Returned item'));
            $sku = trim((string)($cit['sku'] ?? ($cit['omni_item_id'] ?? 'RETURN')));
            $creditItemsText = $desc;
            $orderStmt->bindValue(':order_id',$creditOrderId,SQLITE3_TEXT);
            $orderStmt->bindValue(':order_url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT);
            $orderStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
            $orderStmt->bindValue(':recipient','',SQLITE3_TEXT);
            $orderStmt->bindValue(':items',$creditItemsText,SQLITE3_TEXT);
            $orderStmt->bindValue(':total',-$creditTotal,SQLITE3_FLOAT);
            $orderStmt->bindValue(':shipping',0.0,SQLITE3_FLOAT);
            $orderStmt->bindValue(':gift',0.0,SQLITE3_FLOAT);
            $orderStmt->bindValue(':tax',-$creditTax,SQLITE3_FLOAT);
            $orderStmt->bindValue(':refund',$creditTotal,SQLITE3_FLOAT);
            $orderStmt->bindValue(':payments',($hasRefundEvidence ? 'Card refund $' : 'Card refund expected $') . fmt_money($creditTotal),SQLITE3_TEXT);
            $orderStmt->bindValue(':expense_account','Expenses:Uncategorized',SQLITE3_TEXT);
            $orderStmt->bindValue(':item_amount',-$creditItemAmount,SQLITE3_FLOAT);
            $orderStmt->bindValue(':tax_account',default_config_value('DEFAULT_TAX_ACCOUNT'),SQLITE3_TEXT);
            $orderStmt->bindValue(':shipping_account',default_config_value('DEFAULT_SHIPPING_ACCOUNT'),SQLITE3_TEXT);
            $orderStmt->bindValue(':warning',$creditWarn,SQLITE3_TEXT);
            $orderStmt->bindValue(':notes',"Lowe's synthetic partial-return credit. Source sale order: {$rawOid}; source item status: " . (string)($cit['status'] ?? '') . '; refund evidence: ' . ($hasRefundEvidence ? 'matched tx ' . (string)($refundMatch['tx_guid'] ?? '') : 'not visible at import time / staged by high-value returned-line threshold') . '; refund description: ' . (string)($refundMatch['description'] ?? '') . '.');
            $orderStmt->execute(); $orderCount++;
            $delItems->bindValue(':order_id',$creditOrderId,SQLITE3_TEXT); $delItems->execute();
            [$acct,$reason] = suggest_account($db, 'lowes', 'sku', $sku, $desc, $gnucashRules, $validAccounts);
            if ($acct === '') { $acct = 'Expenses:Uncategorized'; $reason = 'Lowe\'s synthetic partial-return CREDIT category review required.'; }
            $lineKey = trim((string)($cit['line_key'] ?? 'partial-return'));
            $itemKey = item_key($creditOrderId, $sku !== '' ? $sku : $lineKey, $desc, (string)(-$creditItemAmount), '1', 1);
            $fullDesc = '[LOWES-PARTIAL-RETURN:' . ($sku !== '' ? $sku : 'ITEM') . '] ' . trim(((string)($cit['brand'] ?? '') !== '' ? (string)$cit['brand'] . ' ' : '') . $desc);
            $itemStmt->bindValue(':order_id',$creditOrderId,SQLITE3_TEXT); $itemStmt->bindValue(':item_key',$itemKey,SQLITE3_TEXT); $itemStmt->bindValue(':order_url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT); $itemStmt->bindValue(':order_date',$date,SQLITE3_TEXT);
            $itemStmt->bindValue(':quantity',1,SQLITE3_FLOAT); $itemStmt->bindValue(':description',$fullDesc,SQLITE3_TEXT); $itemStmt->bindValue(':item_url',(string)($cit['product_url'] ?? ''),SQLITE3_TEXT); $itemStmt->bindValue(':sku',$sku,SQLITE3_TEXT);
            $itemStmt->bindValue(':unit_price',-$creditItemAmount,SQLITE3_FLOAT); $itemStmt->bindValue(':source_amount',-$creditItemAmount,SQLITE3_FLOAT); $itemStmt->bindValue(':expense_account',$acct,SQLITE3_TEXT); $itemStmt->bindValue(':notes','Synthetic partial-return credit line from sale order ' . $rawOid . '; line tax $' . fmt_money($creditTax) . '; total with tax $' . fmt_money($creditTotal) . '; refund tx ' . (string)($refundMatch['tx_guid'] ?? '') . '.',SQLITE3_TEXT); $itemStmt->bindValue(':match_reason',$reason,SQLITE3_TEXT); $itemStmt->execute(); $itemCount++;
            $delPayments->bindValue(':order_id',$creditOrderId,SQLITE3_TEXT); $delPayments->execute();
            $last4 = '';
            $last4s = (array)($credit['last4s'] ?? []); if ($last4s) $last4 = (string)$last4s[0];
            $refundAcct = trim((string)($refundMatch['account'] ?? ''));
            $method = 'Mastercard' . ($last4 !== '' ? ' ' . $last4 : '');
            $mkey = normalize_payment_method_key(trim('mastercard ' . $last4));
            if ($refundAcct !== '') lowes_upsert_payment_method($db, $mkey, $method, $refundAcct);
            $pid = sha1('lowes|' . $creditOrderId . '|partial-return-refund|' . fmt_money($creditTotal) . '|' . (string)($refundMatch['tx_guid'] ?? ''));
            $note = "Lowe's synthetic partial-return card refund row. Source sale order={$rawOid}; source line_key={$lineKey}; refund evidence=" . ($hasRefundEvidence ? 'matched tx ' . (string)($refundMatch['tx_guid'] ?? '') : 'not visible at import time; staged for manual review') . '; refund description=' . (string)($refundMatch['description'] ?? '') . '; row_key=partial-return-' . lowes_credit_suffix_for_item($cit, (int)($credit['idx'] ?? 0)) . '.';
            $payStmt->bindValue(':pid',$pid,SQLITE3_TEXT); $payStmt->bindValue(':oid',$creditOrderId,SQLITE3_TEXT); $payStmt->bindValue(':url',(string)($o['detail_url'] ?? ''),SQLITE3_TEXT); $payStmt->bindValue(':pdate',$date,SQLITE3_TEXT); $payStmt->bindValue(':method',$method,SQLITE3_TEXT); $payStmt->bindValue(':mkey',$mkey,SQLITE3_TEXT); $payStmt->bindValue(':amount',$creditTotal,SQLITE3_FLOAT); $payStmt->bindValue(':acct',$refundAcct,SQLITE3_TEXT); $payStmt->bindValue(':status','matched_order_id',SQLITE3_TEXT); $payStmt->bindValue(':notes',$note,SQLITE3_TEXT); $payStmt->execute(); $paymentCount++;
        }
    }
    apply_payment_method_invoice_handling($db, 'lowes');
    set_config($db, 'last_lowes_import_summary', "Imported/updated {$orderCount} Lowe's documents, {$itemCount} accounting item rows, and {$paymentCount} payment rows. My Lowe's Money rows total $" . fmt_money($storedTotal) . '. Payment rows preserve duplicate same-denomination stored-value tenders by row/identifier; dedicated stored-value CSV rows are authoritative when present; in-sale return lines with matching imported card refunds are staged as synthetic CREDIT memo targets; high-value returned lines can also be staged for manual CREDIT review when refund evidence was not visible at import time. Credit-card payment methods were seeded from matching GnuCash account names by last4 where possible.');
    return [$orderCount, null, $itemCount];
}

function out_of_book_stage_targets(SQLite3 $db, string $vendor): array {
    $vendor = strtolower(trim($vendor)); if ($vendor === '') $vendor = 'lowes';
    $sql = 'SELECT DISTINCT o.vendor, o.order_id
            FROM orders o
            JOIN vendor_payments p ON p.vendor=o.vendor AND p.order_id=o.order_id
            JOIN payment_method_accounts m ON m.vendor=p.vendor AND m.method_key=p.method_key
            WHERE o.vendor=:vendor AND (COALESCE(m.excluded,0)=1 OR COALESCE(m.invoice_handling,"")="exclude_invoice")
            ORDER BY o.order_date, o.order_id';
    $stmt = $db->prepare($sql); $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $rs = $stmt->execute(); $out=[];
    while($rs && ($r=$rs->fetchArray(SQLITE3_ASSOC))) $out[] = ['vendor'=>(string)$r['vendor'], 'order_id'=>(string)$r['order_id']];
    return $out;
}
function export_out_of_book_bill_csv(SQLite3 $db, string $outPath, string $vendor, string $accountPrefix = '', bool $postInvoices = false, string $postingAccount = '', string $postDateFormat = 'mdy_slash'): array {
    if (trim($postingAccount) === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT', $db);
    $targets = out_of_book_stage_targets($db, $vendor);
    $rows = $targets ? export_gnucash_bill_csv_for_targets($db, $outPath, $targets, false, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat, true) : 0;
    return [$rows, count($targets)];
}




function amazon_stored_value_transaction_sum_for_order(SQLite3 $db, string $orderId): float {
    // Amazon transaction-history rows are often the most reliable source for
    // rewards/gift-card amounts.  Order-level exports can show Grand Total $0
    // and payment text mentioning rewards, but omit the exact stored-value amount.
    // Sum only charge-side stored-value rows; refunds are handled separately.
    $stmt = $db->prepare("SELECT payment_method, amount FROM vendor_payments WHERE vendor='amazon' AND order_id=:order_id AND amount < -0.00001");
    $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $sum = 0.0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $method = (string)($r['payment_method'] ?? '');
        if (is_stored_value_payment_method($method)) $sum += abs((float)($r['amount'] ?? 0.0));
    }
    return round($sum, 2);
}
function amazon_nonstored_charge_transaction_sum_for_order(SQLite3 $db, string $orderId): float {
    // Sum actual card/bank charge-side rows from Amazon transaction history.
    // This lets us distinguish two different Amazon exports:
    //   1) order total is already the residual card charge, or
    //   2) order total is the gross grand total while transaction history splits
    //      card charge + Amazon Points/Gift Card/rewards.
    $stmt = $db->prepare("SELECT payment_method, amount FROM vendor_payments WHERE vendor='amazon' AND order_id=:order_id AND amount < -0.00001");
    $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $sum = 0.0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $method = (string)($r['payment_method'] ?? '');
        if (!is_stored_value_payment_method($method)) {
            $sum += abs((float)($r['amount'] ?? 0.0));
        }
    }
    return round($sum, 2);
}


function restore_amazon_prime_young_card_only_for_order(SQLite3 $db, string $orderId): array {
    // v141: Amazon sometimes displays "Prime for Young Adults cash back" on the
    // order summary, but the transaction page and credit-card import show the
    // full gross card charge.  In that case, payment truth is the Amazon
    // transactions page/imported card register, not the order-summary display.
    $orderId = trim($orderId);
    if ($orderId === '') return [0, 'No order ID supplied.'];

    $stmt = $db->prepare('SELECT * FROM orders WHERE vendor="amazon" AND order_id=:oid LIMIT 1');
    $stmt->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $o = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$o) return [0, 'Order not found in staged Amazon data: ' . $orderId];

    $gross = round(order_bill_total_for_validation($o), 2);
    if ($gross <= 0.005) return [0, 'Could not determine a positive gross bill total for ' . $orderId . '.'];

    $methodKey = normalize_payment_method_key(amazon_prime_young_adults_cashback_method());
    $del = $db->prepare('DELETE FROM vendor_payments
                         WHERE vendor="amazon" AND order_id=:oid AND method_key=:mkey
                           AND (notes LIKE "%Synthetic Amazon stored-value row inferred%"
                                OR notes LIKE "%Generated by v139%"
                                OR notes LIKE "%Generated by v140%"
                                OR notes LIKE "%Prime for Young Adults cash back split%")');
    $del->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $del->bindValue(':mkey', $methodKey, SQLITE3_TEXT);
    $del->execute();
    $deleted = $db->changes();

    $sel = $db->prepare('SELECT payment_id, amount, payment_method, notes
                         FROM vendor_payments
                         WHERE vendor="amazon" AND order_id=:oid AND amount < -0.00001
                         ORDER BY ABS(amount) DESC, payment_date ASC');
    $sel->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $res = $sel->execute();
    $cardRows = [];
    while ($p = $res->fetchArray(SQLITE3_ASSOC)) {
        $method = (string)($p['payment_method'] ?? '');
        if (is_stored_value_payment_method($method)) continue;
        $cardRows[] = $p;
    }

    $restored = 0;
    if (count($cardRows) === 1) {
        $curAbs = round(abs((float)($cardRows[0]['amount'] ?? 0.0)), 2);
        if (abs($curAbs - $gross) > 0.005) {
            $upd = $db->prepare('UPDATE vendor_payments
                                 SET amount=:amount,
                                     notes=trim(COALESCE(notes,"") || " " || :note)
                                 WHERE vendor="amazon" AND payment_id=:pid');
            $upd->bindValue(':amount', -$gross, SQLITE3_FLOAT);
            $upd->bindValue(':note', 'v141 manual resolution: Amazon displayed Prime for Young Adults cash back, but transaction page/register charged full gross amount $' . fmt_money($gross) . '; use full card charge and ignore the displayed cashback until an actual refund/credit appears.', SQLITE3_TEXT);
            $upd->bindValue(':pid', (string)$cardRows[0]['payment_id'], SQLITE3_TEXT);
            $upd->execute();
            $restored = $db->changes();
        }
    }

    $warn = trim((string)($o['warning'] ?? ''));
    $add = 'Prime for Young Adults cash back displayed by Amazon but not applied in the transaction page/register; using full card charge for payment matching and leaving any later correction to refund/credit workflow.';
    if (!str_contains($warn, 'Prime for Young Adults cash back displayed by Amazon but not applied')) $warn = trim($warn . ' ' . $add);
    $notes = trim((string)($o['notes'] ?? ''));
    $noteAdd = 'Prime Young discrepancy: Amazon order summary displayed cash-back, but actual Amazon transaction/card charge used for payment matching was full gross bill total $' . fmt_money($gross) . '. If Amazon later corrects this, handle it as a separate refund/credit.';
    if (!str_contains($notes, 'Prime Young discrepancy:')) $notes = trim($notes . ' ' . $noteAdd);
    $updO = $db->prepare('UPDATE orders SET warning=:warning, notes=:notes WHERE vendor="amazon" AND order_id=:oid');
    $updO->bindValue(':warning', $warn, SQLITE3_TEXT);
    $updO->bindValue(':notes', $notes, SQLITE3_TEXT);
    $updO->bindValue(':oid', $orderId, SQLITE3_TEXT);
    $updO->execute();

    return [$deleted + $restored, 'Resolved Prime Young display/payment discrepancy for ' . $orderId . ': removed ' . $deleted . ' synthetic Prime Young row(s)' . ($restored ? ' and restored the card row to $' . fmt_money($gross) : '') . '. Rebuild/refresh the payment scan, then regenerate the ready plan.'];
}

function split_amazon_prime_young_adults_cashback_payments(SQLite3 $db): int {
    // v141: Do not synthesize/split Prime for Young Adults cash-back payments from
    // the order-summary display alone.  Amazon has been observed to display this
    // cash-back line while still charging the full amount on the transaction page.
    // The transaction page/imported register is the payment source of truth.
    // This function now only cleans up older v139/v140 synthetic rows and restores
    // full-card rows when it can do so safely.
    upsert_payment_method_account($db, 'amazon', amazon_prime_young_adults_cashback_method(), default_config_value('DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON'));
    $orders = $db->query("SELECT order_id, payments, notes FROM orders WHERE vendor='amazon' AND order_id NOT LIKE '%-CREDIT' AND COALESCE(skip,0)=0");
    $changed = 0;
    while ($o = $orders->fetchArray(SQLITE3_ASSOC)) {
        $oid = (string)($o['order_id'] ?? '');
        $hay = (string)($o['payments'] ?? '') . ' ' . (string)($o['notes'] ?? '');
        if ($oid === '' || !amazon_text_mentions_prime_young_adults_cashback($hay)) continue;
        [$n, $_msg] = restore_amazon_prime_young_card_only_for_order($db, $oid);
        $changed += $n;
    }
    return $changed;
}

function infer_amazon_missing_stored_value_payments(SQLite3 $db): int {
    // Some Amazon exports list a card charge and rewards/gift-card/points rows
    // separately. The stored-value amount is a payment source, not a merchant
    // discount or gift-wrap charge.
    //
    // v207c: Amazon order-history CSV totals can be either:
    //   A) residual card charge, with stored value shown separately elsewhere; or
    //   B) gross grand total, while transaction history has card + points split.
    // When transaction rows prove case B, normalize orders.total to the actual
    // card charge so order total + gift/stored-value still equals the vendor bill.
    $sel = $db->prepare("SELECT o.order_id, o.total, o.tax, o.shipping, o.shipping_refund, o.gift, o.payments, o.warning, o.notes, SUM(CASE WHEN i.skip=0 AND i.item_key<>'AMAZON-RECONCILE' THEN COALESCE(i.source_amount, i.quantity*i.unit_price) ELSE 0 END) AS item_sum, COUNT(i.item_key) AS item_count FROM orders o LEFT JOIN order_items i ON i.vendor=o.vendor AND i.order_id=o.order_id WHERE o.vendor='amazon' GROUP BY o.order_id");
    $upd = $db->prepare('UPDATE orders SET total=:total, gift=:gift, item_amount=:item_amount, shipping_refund=CASE WHEN :shipping_refund > 0.005 THEN :shipping_refund ELSE shipping_refund END, warning=:warning, notes=:notes WHERE vendor="amazon" AND order_id=:order_id');
    $n = 0;
    $res = $sel->execute();

    while ($o = $res->fetchArray(SQLITE3_ASSOC)) {
        $oid = (string)($o['order_id'] ?? '');
        if ($oid === '' || (int)($o['item_count'] ?? 0) < 1) continue;

        $payments = strtolower((string)($o['payments'] ?? ''));
        $hasStoredText = (
            str_contains($payments, 'gift card')
            || str_contains($payments, 'rewards point')
            || str_contains($payments, 'reward points')
            || str_contains($payments, 'amazon points')
            || str_contains($payments, 'visa points')
            || str_contains($payments, 'cash back')
            || str_contains($payments, 'points')
        );

        $itemSum = round((float)($o['item_sum'] ?? 0.0), 2);
        $tax = round((float)($o['tax'] ?? 0.0), 2);
        $netShipping = round((float)($o['shipping'] ?? 0.0) - (float)($o['shipping_refund'] ?? 0.0), 2);
        $reportedTotal = round((float)($o['total'] ?? 0.0), 2);
        $normalizedTotal = $reportedTotal;
        $txStored = amazon_stored_value_transaction_sum_for_order($db, $oid);
        $txCard = amazon_nonstored_charge_transaction_sum_for_order($db, $oid);
        $inferred = 0.0;
        $targetItemAmount = 0.0;
        $inferredShippingRefund = 0.0;
        $normalizationNote = '';

        if ($txStored > 0.005) {
            $inferred = $txStored;
            $txGrand = round($txCard + $txStored, 2);

            if ($txCard > 0.005 && abs($reportedTotal - $txGrand) <= 0.02) {
                // Order CSV total was the gross grand total. Convert it to the
                // residual card charge so bill target = total + stored value.
                $normalizedTotal = $txCard;
                $normalizationNote = ' Transaction-history split detected: Amazon order export total $' . fmt_money($reportedTotal) . ' matched card $' . fmt_money($txCard) . ' + stored value $' . fmt_money($txStored) . '; staged card total normalized to $' . fmt_money($normalizedTotal) . '.';
            } elseif ($txCard > 0.005 && abs($reportedTotal - $txCard) <= 0.02) {
                // Order CSV total was already the residual card charge.
                $normalizedTotal = $reportedTotal;
            } elseif ($txCard <= 0.005 && abs($reportedTotal - $txStored) <= 0.02) {
                // All-points/gift-card order where order export total is gross.
                $normalizedTotal = 0.0;
                $normalizationNote = ' Transaction-history stored-value-only payment detected: Amazon order export total $' . fmt_money($reportedTotal) . ' matched stored value $' . fmt_money($txStored) . '; staged card total normalized to $0.00.';
            } else {
                // Ambiguous. Keep the existing total, but still record the stored
                // value amount for review/payment exports.
                $normalizationNote = ' Amazon transaction-history stored value $' . fmt_money($txStored) . ' did not reconcile cleanly with staged total $' . fmt_money($reportedTotal) . ($txCard > 0.005 ? ' and card rows $' . fmt_money($txCard) : '') . '; staged total was not normalized.';
            }

            $targetItemAmount = round($normalizedTotal + $inferred - $tax - $netShipping, 2);
            if ($targetItemAmount < 0) $targetItemAmount = 0.0;
        } else {
            // Fallback only for all-stored-value/no-charge orders whose exact stored amount is
            // not present in the transaction export but the order text indicates gift/rewards.
            if (!$hasStoredText) continue;
            if (abs($reportedTotal) >= 0.005) continue;
            $inferred = round($itemSum + $tax + $netShipping, 2);
            if ($inferred <= 0.005) continue;
            $targetItemAmount = round($inferred - $tax - $netShipping, 2);
            if ($targetItemAmount < 0) $targetItemAmount = 0.0;
        }

        if ($hasStoredText && abs($normalizedTotal) < 0.005 && $netShipping > 0.005 && abs($inferred - ($itemSum + $tax)) <= 0.02) {
            // Amazon sometimes exports Shipping but omits the matching Free Shipping
            // offset on all-rewards/points orders. Preserve both as visible lines.
            $inferredShippingRefund = $netShipping;
            $netShipping = 0.0;
            $targetItemAmount = round($inferred - $tax, 2);
            if ($targetItemAmount < 0) $targetItemAmount = 0.0;
        }

        $currentTotal = round((float)($o['total'] ?? 0.0), 2);
        $currentGift = abs((float)($o['gift'] ?? 0.0));
        $currentItemAmount = round((float)($o['item_amount'] ?? 0.0), 2);
        $currentShippingRefund = round((float)($o['shipping_refund'] ?? 0.0), 2);

        if (
            abs($currentTotal - $normalizedTotal) < 0.01
            && $currentGift > 0.005
            && abs($currentGift - $inferred) < 0.01
            && abs($currentItemAmount - $targetItemAmount) < 0.01
            && ($inferredShippingRefund <= 0.005 || abs($currentShippingRefund - $inferredShippingRefund) < 0.01)
        ) {
            continue;
        }

        $warning = trim((string)($o['warning'] ?? ''));
        if ($txStored > 0.005) {
            $add = 'Amazon gift-card/rewards payment amount set from transaction history: $' . fmt_money($inferred) . '. Merchant coupons/discounts remain bill discount lines; stored value remains a payment source.';
        } else {
            $add = 'Amazon gift-card/rewards payment amount was missing from export; inferred $' . fmt_money($inferred) . ' from item lines + tax + shipping.';
        }
        if ($inferredShippingRefund > 0.005) $add .= ' Free Shipping offset inferred from rewards transaction amount: -$' . fmt_money($inferredShippingRefund) . '.';
        if ($normalizationNote !== '') $add .= $normalizationNote;
        if (!str_contains($warning, 'gift-card/rewards payment amount')) $warning = trim($warning . ' ' . $add);
        elseif ($normalizationNote !== '' && !str_contains($warning, 'Transaction-history split detected')) $warning = trim($warning . ' ' . $normalizationNote);

        $notes = trim((string)($o['notes'] ?? ''));
        $acctHint = amazon_text_mentions_prime_young_adults_cashback($payments)
            ? default_config_value('DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON')
            : default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON');
        $hint = 'Inferred Amazon stored-value payment: $' . fmt_money($inferred) . ' -> ' . $acctHint;
        if ($normalizationNote !== '') $hint .= $normalizationNote;
        if (!str_contains($notes, 'Inferred Amazon stored-value payment')) $notes = trim($notes . ' ' . $hint);
        elseif ($normalizationNote !== '' && !str_contains($notes, 'Transaction-history split detected')) $notes = trim($notes . ' ' . $normalizationNote);

        $upd->bindValue(':total', $normalizedTotal, SQLITE3_FLOAT);
        $upd->bindValue(':gift', $inferred, SQLITE3_FLOAT);
        $upd->bindValue(':item_amount', $targetItemAmount, SQLITE3_FLOAT);
        $upd->bindValue(':shipping_refund', $inferredShippingRefund, SQLITE3_FLOAT);
        $upd->bindValue(':warning', $warning, SQLITE3_TEXT);
        $upd->bindValue(':notes', $notes, SQLITE3_TEXT);
        $upd->bindValue(':order_id', $oid, SQLITE3_TEXT);
        $upd->execute();
        $n++;
    }

    return $n;
}



function create_amazon_refund_credit_memos(SQLite3 $db, array $validAccounts = []): int {
    // Amazon order-level exports can contain a Refund Total.  That refund should not
    // reduce the original vendor bill.  Stage it as a separate negative vendor bill / credit
    // memo so it can later be matched against the refund payment to the Amazon rewards/cashback
    // account or to the original card account.
    $sel = $db->query("SELECT * FROM orders WHERE vendor='amazon' AND COALESCE(skip,0)=0 AND ABS(COALESCE(refund,0)) > 0.005 AND order_id NOT LIKE '%-CREDIT'");
    $orderStmt = $db->prepare('INSERT INTO orders
        (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes, skip)
        VALUES ("amazon",:credit_id,:order_url,:order_date,:recipient,:items,:total,0,0,0,0,0,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes,0)
        ON CONFLICT(vendor, order_id) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, recipient=excluded.recipient,
          items=excluded.items, total=excluded.total, shipping=0, shipping_refund=0, gift=0, tax=0, refund=0,
          payments=excluded.payments, expense_account=excluded.expense_account, item_amount=excluded.item_amount,
          tax_account=excluded.tax_account, shipping_account=excluded.shipping_account,
          warning=excluded.warning, notes=excluded.notes, skip=0');
    $delItem = $db->prepare('DELETE FROM order_items WHERE vendor="amazon" AND order_id=:credit_id AND item_key="AMAZON-REFUND-CREDIT"');
    $itemStmt = $db->prepare('INSERT INTO order_items
        (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, source_amount, subscribe_save, expense_account, notes, match_reason, skip)
        VALUES ("amazon",:credit_id,"AMAZON-REFUND-CREDIT",:order_url,:order_date,1,:description,"","",:unit_price,:source_amount,0,:expense_account,:notes,:match_reason,0)');
    $changed = 0;
    while ($o = $sel->fetchArray(SQLITE3_ASSOC)) {
        $oid = (string)($o['order_id'] ?? '');
        if ($oid === '') continue;
        $refund = abs((float)($o['refund'] ?? 0.0));
        if ($refund <= 0.005) continue;
        $creditId = $oid . '-CREDIT';

        [$acct, $refDesc, $refAmt, $refAsin] = amazon_best_reference_account_for_refund($db, $oid, $refund, $validAccounts);
        if ($acct === '') {
            $acct = valid_or_uncategorized((string)($o['expense_account'] ?? ''), $validAccounts);
            if ($acct === 'Expenses:Uncategorized') $acct = default_account_for_items((string)($o['items'] ?? ''), $validAccounts);
        }

        $items = 'Amazon refund / credit memo for order ' . $oid;
        if ($refDesc !== '') $items .= ' - reference item: ' . clean_text($refDesc, 120);
        $notes = 'Credit memo for Amazon refund total $' . fmt_money($refund) . ' from order ' . $oid . '. ' .
                 'Refund/payment hint: match this credit against the Amazon Cash Back / Gift Card account (' . default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON') . ') or the original card if Amazon refunded the card.';
        $warning = 'Amazon refund total imported as a separate negative vendor bill / credit memo. Review and match payment/refund manually.';

        $orderStmt->bindValue(':credit_id', $creditId, SQLITE3_TEXT);
        $orderStmt->bindValue(':order_url', (string)($o['order_url'] ?? ''), SQLITE3_TEXT);
        $orderStmt->bindValue(':order_date', (string)($o['order_date'] ?? ''), SQLITE3_TEXT);
        $orderStmt->bindValue(':recipient', (string)($o['recipient'] ?? ''), SQLITE3_TEXT);
        $orderStmt->bindValue(':items', $items, SQLITE3_TEXT);
        $orderStmt->bindValue(':total', -$refund, SQLITE3_FLOAT);
        $orderStmt->bindValue(':payments', (string)($o['payments'] ?? ''), SQLITE3_TEXT);
        $orderStmt->bindValue(':expense_account', $acct, SQLITE3_TEXT);
        $orderStmt->bindValue(':item_amount', -$refund, SQLITE3_FLOAT);
        $orderStmt->bindValue(':tax_account', (string)($o['tax_account'] ?? default_config_value('DEFAULT_TAX_ACCOUNT')), SQLITE3_TEXT);
        $orderStmt->bindValue(':shipping_account', (string)($o['shipping_account'] ?? default_config_value('DEFAULT_SHIPPING_ACCOUNT')), SQLITE3_TEXT);
        $orderStmt->bindValue(':warning', $warning, SQLITE3_TEXT);
        $orderStmt->bindValue(':notes', $notes, SQLITE3_TEXT);
        $orderStmt->execute();

        $delItem->bindValue(':credit_id', $creditId, SQLITE3_TEXT);
        $delItem->execute();

        $desc = '[CREDIT:AMAZON] Refund / credit for order ' . $oid;
        if ($refDesc !== '') $desc .= ' - ' . clean_text($refDesc, 160);
        $itemStmt->bindValue(':credit_id', $creditId, SQLITE3_TEXT);
        $itemStmt->bindValue(':order_url', (string)($o['order_url'] ?? ''), SQLITE3_TEXT);
        $itemStmt->bindValue(':order_date', (string)($o['order_date'] ?? ''), SQLITE3_TEXT);
        $itemStmt->bindValue(':description', $desc, SQLITE3_TEXT);
        $itemStmt->bindValue(':unit_price', -$refund, SQLITE3_FLOAT);
        $itemStmt->bindValue(':source_amount', -$refund, SQLITE3_FLOAT);
        $itemStmt->bindValue(':expense_account', $acct, SQLITE3_TEXT);
        $itemStmt->bindValue(':notes', $notes . ($refAsin ? ' Reference ASIN: ' . $refAsin : ''), SQLITE3_TEXT);
        $itemStmt->bindValue(':match_reason', 'refund credit memo matched same-order item by refund amount when possible; otherwise best same-order reference item', SQLITE3_TEXT);
        $itemStmt->execute();
        $changed++;
    }
    return $changed;
}

function reconcile_amazon_order_promotions(SQLite3 $db, array $validAccounts = []): int {
    // Amazon order-level exports include the paid order total, tax, shipping and gift-card fields.
    // Amazon item-level exports can contain pre-promotion item prices.  When the item subtotal
    // differs from the order-level target subtotal, add a visible discount/adjustment line so the
    // bill can reconcile rather than failing validation or requiring manual arithmetic.
    $delete = $db->prepare("DELETE FROM order_items WHERE vendor='amazon' AND order_id=:order_id AND (item_key='AMAZON-RECONCILE' OR item_key LIKE 'AMAZON-INFERRED-%' OR item_key='AMAZON-FEE')");
    $insert = $db->prepare('INSERT INTO order_items
        (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, source_amount, subscribe_save, expense_account, notes, match_reason)
        VALUES ("amazon",:order_id,:item_key,:order_url,:order_date,1,:description,"",:asin,:unit_price,:source_amount,0,:expense_account,:notes,:match_reason)
        ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, quantity=excluded.quantity,
          description=excluded.description, asin=excluded.asin, unit_price=excluded.unit_price, source_amount=excluded.source_amount,
          expense_account=excluded.expense_account, notes=excluded.notes, match_reason=excluded.match_reason');
    $warn = $db->prepare('UPDATE orders SET warning=CASE WHEN instr(COALESCE(warning,""), :warning) > 0 THEN warning ELSE trim(COALESCE(warning,"") || " " || :warning) END WHERE vendor="amazon" AND order_id=:order_id');

    $changed = 0;
    $res = $db->query("SELECT order_id, order_url, order_date, items, item_amount, expense_account, total, tax, shipping, shipping_refund, gift, refund FROM orders WHERE vendor='amazon' AND COALESCE(skip,0)=0");
    while ($o = $res->fetchArray(SQLITE3_ASSOC)) {
        $oid = (string)$o['order_id'];
        $delete->bindValue(':order_id', $oid, SQLITE3_TEXT);
        $delete->execute();

        $sumStmt = $db->prepare("SELECT COUNT(*) c, SUM(quantity*unit_price) s FROM order_items WHERE vendor='amazon' AND order_id=:order_id AND skip=0 AND item_key<>'AMAZON-RECONCILE' AND item_key NOT LIKE 'AMAZON-INFERRED-%' AND item_key<>'AMAZON-FEE'");
        $sumStmt->bindValue(':order_id', $oid, SQLITE3_TEXT);
        $sr = $sumStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $cnt = (int)($sr['c'] ?? 0);
        if ($cnt < 1) continue;

        $itemSum = round((float)($sr['s'] ?? 0.0), 2);
        $target = round((float)($o['item_amount'] ?? 0.0), 2);
        if ($target <= 0.0) {
            $netShipping = (float)($o['shipping'] ?? 0.0) - (float)($o['shipping_refund'] ?? 0.0);
            $target = round((float)($o['total'] ?? 0.0) + abs((float)($o['gift'] ?? 0.0)) - (float)($o['tax'] ?? 0.0) - $netShipping, 2);
        }
        $delta = round($target - $itemSum, 2);
        if (abs($delta) < 0.005) continue;

        $isDiscount = $delta < 0;
        $refundAmount = abs((float)($o['refund'] ?? 0.0));
        // Negative Amazon deltas are merchant discounts/coupons and should reduce the same
        // category as an item in that invoice. Positive deltas are handled carefully:
        // first try to infer a missing/duplicate item line by exact same-order amount match.
        // Only non-refund orders may fall back to gift-wrap. Refund orders with unexplained
        // positive deltas are left for manual review rather than creating fake gift-wrap charges.
        $desc = $isDiscount
            ? '[DISCOUNT:AMAZON] Amazon promotion / discount'
            : '[GIFTWRAP:AMAZON] Amazon gift wrap / gift service charge';
        $itemKeyForInsert = 'AMAZON-RECONCILE';
        $asinForInsert = '';
        $matchReason = $isDiscount ? 'Amazon promotion/discount matched within this invoice' : 'Amazon gift wrap / gift service charge';
        $discountNoteExtra = '';
        if ($isDiscount) {
            [$acct, $refDesc, $refAmt, $refAsin] = amazon_best_reference_account_for_refund($db, $oid, abs($delta), $validAccounts);
            if ($acct === '') {
                $acct = valid_or_uncategorized((string)($o['expense_account'] ?? ''), $validAccounts);
                if ($acct === 'Expenses:Uncategorized') $acct = default_account_for_items((string)($o['items'] ?? ''), $validAccounts);
            }
            if (isset($refDesc) && (string)$refDesc !== '') $discountNoteExtra = ' Category follows same-order item: ' . substr((string)$refDesc, 0, 120) . '.';
        } else {
            // A positive Amazon subtotal delta is not always gift wrap.  Sometimes the item-level
            // export omits a duplicate item line while the order-level subtotal is correct.  If
            // the delta matches an existing same-order item amount, infer a missing/duplicate item
            // line using that item category instead of mislabeling it as gift wrap.
            [$acct, $refDesc, $refAmt, $refAsin] = amazon_reference_for_exact_amount($db, $oid, abs($delta), $validAccounts);
            if (isset($refAmt) && abs((float)$refAmt - abs($delta)) <= 0.011 && isset($refDesc) && (string)$refDesc !== '') {
                $itemKeyForInsert = 'AMAZON-INFERRED-MISSING-' . substr(md5($oid . '|' . $delta . '|' . (string)$refDesc), 0, 12);
                $asinForInsert = (string)($refAsin ?? '');
                $desc = '[MISSING:AMAZON] Inferred missing/duplicate item line - ' . clean_text((string)$refDesc, 180);
                if ($acct === '') $acct = valid_or_uncategorized((string)($o['expense_account'] ?? ''), $validAccounts);
                $matchReason = 'Amazon inferred missing/duplicate item from same-order amount match';
                $discountNoteExtra = ' Positive delta matched same-order item amount; treated as a missing/duplicate item line, not gift wrap.';
            } else {
                if ($refundAmount > 0.005) {
                    $warn->bindValue(':warning', 'Amazon positive item subtotal delta of $' . fmt_money($delta) . ' detected on a refund order; no exact missing-item match was found and no gift-wrap adjustment was created. Review refund/credit manually.', SQLITE3_TEXT);
                    $warn->bindValue(':order_id', $oid, SQLITE3_TEXT);
                    $warn->execute();
                    continue;
                }
                // Prefer the dedicated Gifts Given account for Gift Wrap if it exists in the loaded
                // GnuCash account list. If the account list is not loaded, still use the configured
                // default so validation can tell the user if it needs to be created.
                if (!$validAccounts || isset($validAccounts[default_config_value('DEFAULT_GIFT_WRAP_ACCOUNT')])) {
                    $acct = default_config_value('DEFAULT_GIFT_WRAP_ACCOUNT');
                } else {
                    $acct = 'Expenses:Uncategorized';
                }
                $discountNoteExtra = ' Positive Amazon order-level delta treated as Gift Wrap / gift service charge.';
            }
        }

        $insert->bindValue(':order_id', $oid, SQLITE3_TEXT);
        $insert->bindValue(':item_key', $itemKeyForInsert, SQLITE3_TEXT);
        $insert->bindValue(':order_url', (string)($o['order_url'] ?? ''), SQLITE3_TEXT);
        $insert->bindValue(':order_date', (string)($o['order_date'] ?? ''), SQLITE3_TEXT);
        $insert->bindValue(':description', $desc, SQLITE3_TEXT);
        $insert->bindValue(':asin', $asinForInsert, SQLITE3_TEXT);
        $insert->bindValue(':unit_price', $delta, SQLITE3_FLOAT);
        $insert->bindValue(':source_amount', $delta, SQLITE3_FLOAT);
        $insert->bindValue(':expense_account', $acct, SQLITE3_TEXT);
        $insert->bindValue(':notes', 'Amazon reconciliation line. Target item subtotal '.fmt_money($target).'; imported item subtotal '.fmt_money($itemSum).'; delta '.fmt_money($delta).'.'.$discountNoteExtra, SQLITE3_TEXT);
        $insert->bindValue(':match_reason', $matchReason, SQLITE3_TEXT);
        $insert->execute();

        $warnText = $isDiscount ? 'Amazon promotion/discount imported as a negative line of $' : ($itemKeyForInsert !== 'AMAZON-RECONCILE' ? 'Amazon inferred missing/duplicate item line imported as $' : 'Amazon gift wrap/gift service charge imported as $');
        $warn->bindValue(':warning', $warnText . fmt_money(abs($delta)) . '.', SQLITE3_TEXT);
        $warn->bindValue(':order_id', $oid, SQLITE3_TEXT);
        $warn->execute();
        $changed++;

        // After item-subtotal reconciliation, there can still be an order-level fee outside
        // Item(s) Subtotal and tax, e.g. FL State Battery Fee.  Add a small positive fee line
        // so the bill total reconciles without disguising the missing item as gift wrap.
        $netShippingForFee = (float)($o['shipping'] ?? 0.0) - (float)($o['shipping_refund'] ?? 0.0);
        $billTargetForFee = round((float)($o['total'] ?? 0.0) + abs((float)($o['gift'] ?? 0.0)), 2);
        $feeDelta = round($billTargetForFee - ($target + $netShippingForFee + (float)($o['tax'] ?? 0.0)), 2);
        if (!$isDiscount && abs($feeDelta) >= 0.005 && abs($feeDelta) <= 25.00 && $refundAmount <= 0.005) {
            [$feeAcct, $feeRefDesc, $feeRefAmt, $feeRefAsin] = amazon_best_reference_account_for_refund($db, $oid, abs($feeDelta), $validAccounts);
            if ($feeAcct === '') [$feeAcct, $feeRefDesc, $feeRefAmt] = amazon_best_reference_account_for_discount($db, $oid, $validAccounts);
            if ($feeAcct === '') $feeAcct = valid_or_uncategorized((string)($o['expense_account'] ?? ''), $validAccounts);
            $insert->bindValue(':order_id', $oid, SQLITE3_TEXT);
            $insert->bindValue(':item_key', 'AMAZON-FEE', SQLITE3_TEXT);
            $insert->bindValue(':order_url', (string)($o['order_url'] ?? ''), SQLITE3_TEXT);
            $insert->bindValue(':order_date', (string)($o['order_date'] ?? ''), SQLITE3_TEXT);
            $insert->bindValue(':description', '[FEE:AMAZON] Amazon order fee / battery/environmental/service fee', SQLITE3_TEXT);
            $insert->bindValue(':asin', '', SQLITE3_TEXT);
            $insert->bindValue(':unit_price', $feeDelta, SQLITE3_FLOAT);
            $insert->bindValue(':source_amount', $feeDelta, SQLITE3_FLOAT);
            $insert->bindValue(':expense_account', $feeAcct, SQLITE3_TEXT);
            $insert->bindValue(':notes', 'Amazon order-level fee inferred from bill total. Expected bill total '.fmt_money($billTargetForFee).'; subtotal+shipping+tax '.fmt_money($target + $netShippingForFee + (float)($o['tax'] ?? 0.0)).'; fee '.fmt_money($feeDelta).'.' . ((string)($feeRefDesc ?? '') !== '' ? ' Category follows same-order item: ' . substr((string)$feeRefDesc, 0, 100) . '.' : ''), SQLITE3_TEXT);
            $insert->bindValue(':match_reason', 'Amazon order-level fee inferred from total reconciliation', SQLITE3_TEXT);
            $insert->execute();
            $warn->bindValue(':warning', 'Amazon order-level fee imported as $' . fmt_money(abs($feeDelta)) . '.', SQLITE3_TEXT);
            $warn->bindValue(':order_id', $oid, SQLITE3_TEXT);
            $warn->execute();
            $changed++;
        }
    }
    return $changed;
}

function mark_cancelled_no_item_orders(SQLite3 $db): int {
    // Amazon cancelled/no-charge orders commonly appear in the order export with a 0.00 balance
    // and no corresponding item lines. Mark them skipped so they do not clutter export batches.
    $stmt = $db->prepare("SELECT o.order_id, COALESCE(o.warning,'') warning
        FROM orders o
        LEFT JOIN order_items i ON i.vendor=o.vendor AND i.order_id=o.order_id
        WHERE o.vendor='amazon'
        GROUP BY o.order_id
        HAVING COUNT(i.item_key)=0
           AND ABS(COALESCE(o.total,0)) < 0.005
           AND ABS(COALESCE(o.tax,0)) < 0.005
           AND ABS(COALESCE(o.shipping,0)) < 0.005
           AND ABS(COALESCE(o.shipping_refund,0)) < 0.005
           AND ABS(COALESCE(o.gift,0)) < 0.005
           AND ABS(COALESCE(o.item_amount,0)) < 0.005");
    $upd = $db->prepare('UPDATE orders SET skip=1, warning=:warning WHERE vendor="amazon" AND order_id=:order_id');
    $n = 0;
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $w = trim((string)($r['warning'] ?? ''));
        if (!str_contains(strtolower($w), 'likely cancelled')) $w = trim($w . ' Likely cancelled/no-charge Amazon order: no item rows and zero balance.');
        $upd->bindValue(':warning', $w, SQLITE3_TEXT);
        $upd->bindValue(':order_id', (string)$r['order_id'], SQLITE3_TEXT);
        $upd->execute();
        $n++;
    }
    return $n;
}



function hd_find_purchase_history_header(string $path): array {
    $encodings = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];
    foreach ($encodings as $enc) {
        $fh = @fopen($path, 'rb');
        if (!$fh) continue;
        $lineNo = 0;
        while (($raw = fgets($fh)) !== false) {
            $lineNo++;
            $line = $enc === 'UTF-8' ? $raw : (function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', $enc) : (iconv($enc, 'UTF-8//IGNORE', $raw) ?: $raw));
            $cells = str_getcsv($line);
            $norm = array_map('normalize_header_name', $cells);
            $idx = array_flip($norm);
            if (isset($idx['date'],$idx['store number'],$idx['transaction id'],$idx['sku number'],$idx['sku description'],$idx['extended retail (before discount)'])) {
                fclose($fh);
                return [$lineNo, $norm, $enc, ''];
            }
        }
        fclose($fh);
    }
    return [0, [], '', 'Could not find the Home Depot purchase-history header row. Expected columns include Date, Store Number, Transaction ID, SKU Number, SKU Description, Extended Retail (before discount).'];
}
function hd_purchase_history_rows(string $path): array {
    [$headerLine, $header, $enc, $err] = hd_find_purchase_history_header($path);
    if ($err) return [[], $err];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [[], 'Could not open Home Depot CSV.'];
    $rows = [];
    $lineNo = 0;
    while (($raw = fgets($fh)) !== false) {
        $lineNo++;
        if ($lineNo <= $headerLine) continue;
        $line = $enc === 'UTF-8' ? $raw : (function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', $enc) : (iconv($enc, 'UTF-8//IGNORE', $raw) ?: $raw));
        $cells = str_getcsv($line);
        if (!array_filter($cells, fn($v)=>trim((string)$v)!=='')) continue;
        $r = [];
        foreach ($header as $i=>$hname) $r[$hname] = (string)($cells[$i] ?? '');
        $rows[] = $r;
    }
    fclose($fh);
    return [$rows, ''];
}
function hd_order_id_from_row(array $r): string {
    $date = normalize_import_date((string)($r['date'] ?? ''));
    $store = preg_replace('/[^0-9A-Za-z]+/', '', (string)($r['store number'] ?? ''));
    $tx = preg_replace('/[^0-9A-Za-z]+/', '', (string)($r['transaction id'] ?? ''));
    $reg = preg_replace('/[^0-9A-Za-z]+/', '', (string)($r['register number'] ?? ''));
    $order = preg_replace('/[^0-9A-Za-z]+/', '', (string)($r['order number'] ?? ''));
    $inv = preg_replace('/[^0-9A-Za-z]+/', '', (string)($r['invoice number'] ?? ''));
    if ($order !== '') return 'HD-ORDER-' . $order;
    if ($inv !== '') return 'HD-INV-' . $inv;
    return 'HD-' . str_replace('-', '', $date) . '-' . ($store ?: 'STORE') . '-' . ($tx ?: 'TX') . '-' . ($reg ?: 'REG');
}
function hd_payment_method_key_from_order(array $first): string {
    $store = preg_replace('/[^0-9A-Za-z]+/', '', (string)($first['store number'] ?? ''));
    return $store !== '' ? ('home-depot-store-' . strtolower($store)) : 'home-depot-register';
}
function import_home_depot_csv(SQLite3 $db, string $path, string $gnucashPath=''): array {
    [$rows, $err] = hd_purchase_history_rows($path);
    if ($err) return [0, $err, 0];
    if (!$rows) return [0, 'Home Depot purchase-history CSV did not contain any item rows.', 0];
    $validAccounts = load_valid_account_set($gnucashPath);
    $groups = [];
    foreach ($rows as $r) {
        $oid = hd_order_id_from_row($r);
        if ($oid !== '') $groups[$oid][] = $r;
    }
    $insOrder = $db->prepare('INSERT OR REPLACE INTO orders (vendor,order_id,order_url,order_date,recipient,items,total,shipping,shipping_refund,gift,tax,refund,payments,expense_account,item_amount,tax_account,shipping_account,warning,notes) VALUES ("home_depot",:order_id,:order_url,:order_date,:recipient,:items,:total,0,0,0,0,0,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)');
    $delItems = $db->prepare('DELETE FROM order_items WHERE vendor="home_depot" AND order_id=:order_id');
    $insItem = $db->prepare('INSERT OR REPLACE INTO order_items (vendor,order_id,item_key,order_url,order_date,quantity,description,item_url,asin,unit_price,source_amount,expense_account,notes,match_reason) VALUES ("home_depot",:order_id,:item_key,:order_url,:order_date,:quantity,:description,:item_url,:sku,:unit_price,:source_amount,:expense_account,:notes,:match_reason)');
    $delPayments = $db->prepare('DELETE FROM vendor_payments WHERE vendor="home_depot" AND order_id=:order_id');
    $insPay = $db->prepare('INSERT OR REPLACE INTO vendor_payments (vendor,payment_id,order_id,order_url,payment_date,payee,payment_method,method_key,amount,account_fullname,match_status,notes) VALUES ("home_depot",:pid,:oid,"",:pdate,"Home Depot",:method,:mkey,:amount,:acct,"unmatched",:notes)');
    $orderCount=0; $itemCount=0; $paymentCount=0;
    foreach ($groups as $oid=>$group) {
        $first = $group[0];
        $date = normalize_import_date((string)($first['date'] ?? ''));
        $store = trim((string)($first['store number'] ?? ''));
        $tx = trim((string)($first['transaction id'] ?? ''));
        $inv = trim((string)($first['invoice number'] ?? ''));
        $orderNum = trim((string)($first['order number'] ?? ''));
        $purchaser = trim((string)($first['purchaser'] ?? ''));
        $sum = 0.0; $descs = [];
        foreach ($group as $r) {
            $sum += money_to_float((string)($r['extended retail (before discount)'] ?? $r['net unit price'] ?? '0'));
            $desc = trim((string)($r['sku description'] ?? ''));
            if ($desc !== '') $descs[] = $desc;
        }
        $sum = round($sum, 2);
        $credit = $sum < -0.005;
        $stagedOid = $oid . ($credit && !str_ends_with($oid, '-CREDIT') ? '-CREDIT' : '');
        $warn = 'Home Depot purchase-history CSV does not include sales tax or tender detail; reconcile against the Home Depot register/free-floating scan before applying payments.';
        if ($credit) $warn .= ' Negative total staged as a Home Depot credit memo / return.';
        $payments = 'Home Depot purchase-history CSV; register/tender details not included. Use payment mapping/free-floating scan for card matching.';
        $insOrder->bindValue(':order_id', $stagedOid, SQLITE3_TEXT);
        $insOrder->bindValue(':order_url', '', SQLITE3_TEXT);
        $insOrder->bindValue(':order_date', $date, SQLITE3_TEXT);
        $insOrder->bindValue(':recipient', $purchaser, SQLITE3_TEXT);
        $insOrder->bindValue(':items', implode('; ', array_slice($descs, 0, 6)), SQLITE3_TEXT);
        $insOrder->bindValue(':total', $sum, SQLITE3_FLOAT);
        $insOrder->bindValue(':payments', $payments, SQLITE3_TEXT);
        $insOrder->bindValue(':expense_account', 'Expenses:Uncategorized', SQLITE3_TEXT);
        $insOrder->bindValue(':item_amount', $sum, SQLITE3_FLOAT);
        $insOrder->bindValue(':tax_account', default_config_value('DEFAULT_TAX_ACCOUNT',$db), SQLITE3_TEXT);
        $insOrder->bindValue(':shipping_account', default_config_value('DEFAULT_SHIPPING_ACCOUNT',$db), SQLITE3_TEXT);
        $insOrder->bindValue(':warning', $warn, SQLITE3_TEXT);
        $insOrder->bindValue(':notes', 'Imported from Home Depot purchase-history CSV. Store ' . $store . ', transaction ' . $tx . ', invoice ' . $inv . ', order ' . $orderNum . '.', SQLITE3_TEXT);
        $insOrder->execute();
        $delItems->bindValue(':order_id', $stagedOid, SQLITE3_TEXT); $delItems->execute();
        $lineNo = 0;
        foreach ($group as $r) {
            $lineNo++;
            $sku = trim((string)($r['sku number'] ?? ''));
            $internetSku = trim((string)($r['internet sku'] ?? ''));
            $desc = clean_text((string)($r['sku description'] ?? 'Home Depot item'), 220);
            $qty = (float)($r['quantity'] ?? 1); if (abs($qty) < 0.0001) $qty = 1;
            $line = money_to_float((string)($r['extended retail (before discount)'] ?? $r['net unit price'] ?? '0'));
            $unit = $qty != 0 ? round($line / $qty, 6) : $line;
            $dept = trim((string)($r['department name'] ?? ''));
            $class = trim((string)($r['class name'] ?? ''));
            $subclass = trim((string)($r['subclass name'] ?? ''));
            $key = 'HD-LINE-' . str_pad((string)$lineNo, 4, '0', STR_PAD_LEFT) . ($sku !== '' ? '-' . preg_replace('/[^0-9A-Za-z]+/', '', $sku) : '');
            [$acct,$reason] = suggest_account($db, 'home_depot', 'sku', $sku ?: $internetSku, $desc, [], $validAccounts);
            $notes = 'Home Depot CSV row. Department=' . $dept . '; Class=' . $class . '; Subclass=' . $subclass . '; Internet SKU=' . $internetSku . '; Program discount=' . (string)($r['program discount amount'] ?? '') . '; Other discount=' . (string)($r['other discount amount'] ?? '') . '.';
            $insItem->bindValue(':order_id', $stagedOid, SQLITE3_TEXT);
            $insItem->bindValue(':item_key', $key, SQLITE3_TEXT);
            $insItem->bindValue(':order_url', '', SQLITE3_TEXT);
            $insItem->bindValue(':order_date', $date, SQLITE3_TEXT);
            $insItem->bindValue(':quantity', $qty, SQLITE3_FLOAT);
            $insItem->bindValue(':description', '[HD:' . ($sku ?: $internetSku ?: 'ITEM') . '] ' . $desc, SQLITE3_TEXT);
            $insItem->bindValue(':item_url', '', SQLITE3_TEXT);
            $insItem->bindValue(':sku', $sku ?: $internetSku, SQLITE3_TEXT);
            $insItem->bindValue(':unit_price', $unit, SQLITE3_FLOAT);
            $insItem->bindValue(':source_amount', $line, SQLITE3_FLOAT);
            $insItem->bindValue(':expense_account', $acct ?: 'Expenses:Uncategorized', SQLITE3_TEXT);
            $insItem->bindValue(':notes', $notes, SQLITE3_TEXT);
            $insItem->bindValue(':match_reason', $reason ?: 'Home Depot SKU/title suggestion', SQLITE3_TEXT);
            $insItem->execute(); $itemCount++;
        }
        $delPayments->bindValue(':order_id', $stagedOid, SQLITE3_TEXT); $delPayments->execute();
        $method = 'Home Depot register/store ' . ($store ?: 'unknown');
        $mkey = hd_payment_method_key_from_order($first);
        $acct = default_config_value('DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT', $db);
        $pmStmt = $db->prepare('INSERT INTO payment_method_accounts (vendor, method_key, display_name, account_fullname, updated_at) VALUES ("home_depot",:key,:display,:acct,CURRENT_TIMESTAMP) ON CONFLICT(vendor,method_key) DO UPDATE SET display_name=excluded.display_name, account_fullname=CASE WHEN payment_method_accounts.account_fullname="" THEN excluded.account_fullname ELSE payment_method_accounts.account_fullname END, updated_at=excluded.updated_at');
        $pmStmt->bindValue(':key', $mkey, SQLITE3_TEXT); $pmStmt->bindValue(':display', $method, SQLITE3_TEXT); $pmStmt->bindValue(':acct', $acct, SQLITE3_TEXT); $pmStmt->execute();
        if (abs($sum) > 0.005) {
            $pid = sha1('home_depot|' . $stagedOid . '|' . $date . '|' . fmt_money($sum));
            $insPay->bindValue(':pid', $pid, SQLITE3_TEXT); $insPay->bindValue(':oid', $stagedOid, SQLITE3_TEXT); $insPay->bindValue(':pdate', $date, SQLITE3_TEXT); $insPay->bindValue(':method', $method, SQLITE3_TEXT); $insPay->bindValue(':mkey', $mkey, SQLITE3_TEXT); $insPay->bindValue(':amount', -$sum, SQLITE3_FLOAT); $insPay->bindValue(':acct', $acct, SQLITE3_TEXT); $insPay->bindValue(':notes', 'Expected Home Depot register/card transaction from purchase-history CSV; tax/tender not included in source CSV.', SQLITE3_TEXT); $insPay->execute(); $paymentCount++;
        }
        $orderCount++;
    }
    apply_payment_method_invoice_handling($db, 'home_depot');
    set_config($db, 'last_home_depot_import_summary', "Imported/updated {$orderCount} Home Depot purchase-history transaction group(s), {$itemCount} item rows, and {$paymentCount} expected register payment rows.");
    return [$orderCount, '', $itemCount];
}

function tsc_extract_normalized_csvs(string $path): array {
    $tmpDir = '';
    if (is_file($path) && file_looks_like_zip($path, (string)@file_get_contents($path, false, null, 0, 4))) {
        $tmpDir = sys_get_temp_dir() . '/tsc-normalized-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0770, true)) return [[], 'Could not create temporary extraction directory for Tractor Supply ZIP.'];
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) return [[], 'Could not open Tractor Supply normalized ZIP.'];
            $zip->extractTo($tmpDir); $zip->close();
        } else {
            $cmd = 'unzip -qq ' . escapeshellarg($path) . ' -d ' . escapeshellarg($tmpDir) . ' 2>&1';
            $out = []; $rc = 0; @exec($cmd, $out, $rc);
            if ($rc !== 0) {
                return [[], 'Could not extract Tractor Supply normalized ZIP because PHP ZipArchive is unavailable and the CLI unzip fallback failed. Install php-zip for PHP-FPM, or install unzip, or import the extracted normalized folder directly. unzip output: ' . implode(' ', array_slice($out, 0, 3))];
            }
        }
        $path = $tmpDir;
    }
    if (!is_dir($path)) return [[], 'Tractor Supply import path must be a normalized folder or ZIP.'];
    $wanted = ['orders'=>'tsc_orders.csv','items'=>'tsc_order_items.csv','payments'=>'tsc_order_payments.csv'];
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $base = strtolower($file->getFilename());
        foreach ($wanted as $k=>$name) if ($base === strtolower($name) && !isset($found[$k])) $found[$k] = $file->getPathname();
    }
    return [$found, ''];
}
function tsc_csv_assoc_rows(string $path): array { return lowes_csv_assoc_rows($path); }
function tsc_payment_method_key(array $p): string {
    $method = trim((string)($p['payment_method'] ?? $p['method'] ?? ''));
    $last4 = trim((string)($p['last4'] ?? ''));
    $key = strtolower(preg_replace('/[^a-z0-9]+/', '-', $method . ($last4 !== '' ? '-' . $last4 : '')));
    return trim($key, '-') ?: 'unknown';
}
function tsc_payment_label(array $p): string {
    $method = trim((string)($p['payment_method'] ?? $p['method'] ?? 'Tractor Supply Payment'));
    $last4 = trim((string)($p['last4'] ?? ''));
    return $last4 !== '' && !str_contains($method, $last4) ? ($method . ' ' . $last4) : $method;
}
function tsc_default_payment_account(SQLite3 $db, string $gnucashPath, array $p): string {
    $last4 = trim((string)($p['last4'] ?? ''));
    if ($last4 !== '') return lowes_guess_account_for_last4($gnucashPath, $last4, '');
    return '';
}
function import_tsc_normalized_zip(SQLite3 $db, string $path, string $gnucashPath = ''): array {
    [$files, $err] = tsc_extract_normalized_csvs($path);
    if ($err) return [0, $err, 0];
    if (empty($files['orders']) || empty($files['items'])) return [0, 'Tractor Supply normalized import requires tsc_orders.csv and tsc_order_items.csv in the uploaded ZIP/folder.', 0];
    $orders = tsc_csv_assoc_rows($files['orders']);
    $items = tsc_csv_assoc_rows($files['items']);
    $payments = !empty($files['payments']) ? tsc_csv_assoc_rows($files['payments']) : [];
    $itemsByOrder=[]; foreach($items as $it){ $oid=trim((string)($it['order_id'] ?? $it['order_number'] ?? '')); if($oid!=='') $itemsByOrder[$oid][]=$it; }
    $paymentsByOrder=[]; foreach($payments as $pmt){ $oid=trim((string)($pmt['order_id'] ?? $pmt['order_number'] ?? '')); if($oid!=='') $paymentsByOrder[$oid][]=$pmt; }
    $insOrder = $db->prepare('INSERT OR REPLACE INTO orders (vendor,order_id,order_url,order_date,recipient,items,total,shipping,shipping_refund,gift,tax,refund,payments,expense_account,item_amount,tax_account,shipping_account,warning,notes) VALUES ("tractor_supply",:order_id,:order_url,:order_date,:recipient,:items,:total,:shipping,0,0,:tax,0,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)');
    $delItems = $db->prepare('DELETE FROM order_items WHERE vendor="tractor_supply" AND order_id=:order_id');
    $insItem = $db->prepare('INSERT OR REPLACE INTO order_items (vendor,order_id,item_key,order_url,order_date,quantity,description,item_url,asin,unit_price,source_amount,expense_account,notes,match_reason) VALUES ("tractor_supply",:order_id,:item_key,:order_url,:order_date,:quantity,:description,:item_url,:sku,:unit_price,:source_amount,:expense_account,:notes,:match_reason)');
    $delPayments = $db->prepare('DELETE FROM vendor_payments WHERE vendor="tractor_supply" AND order_id=:order_id');
    $insPay = $db->prepare('INSERT OR REPLACE INTO vendor_payments (vendor,payment_id,order_id,order_url,payment_date,payee,payment_method,method_key,amount,account_fullname,match_status,notes) VALUES ("tractor_supply",:pid,:oid,:url,:pdate,"Tractor Supply",:method,:mkey,:amount,:acct,"unmatched",:notes)');
    $orderCount=0; $itemCount=0; $paymentCount=0;
    foreach($orders as $o){
        $oid=trim((string)($o['order_id'] ?? $o['order_number'] ?? '')); if($oid==='') continue;
        $orderItems=$itemsByOrder[$oid] ?? [];
        $orderPays=$paymentsByOrder[$oid] ?? [];
        $date=normalize_import_date((string)($o['order_date'] ?? $o['date'] ?? ''));
        $url=(string)($o['order_url'] ?? $o['url'] ?? '');
        $total=money_to_float((string)($o['total'] ?? '0'));
        $shipping=money_to_float((string)($o['shipping'] ?? $o['delivery'] ?? '0'));
        $tax=money_to_float((string)($o['tax'] ?? $o['sales_tax'] ?? '0'));
        $discount=money_to_float((string)($o['discount'] ?? '0'));
        $summarySubtotal=money_to_float((string)($o['subtotal'] ?? $o['item_amount'] ?? '0'));
        $itemGrossAmount=0.0; $itemAmount=0.0; $descs=[];
        foreach($orderItems as $it){ $itemGrossAmount += money_to_float((string)($it['line_total'] ?? $it['subtotal'] ?? $it['source_amount'] ?? '0')); $d=trim((string)($it['description'] ?? '')); if($d!=='') $descs[]=$d; }
        $inferredDiscount=0.0;
        if (abs($discount) <= 0.005 && $summarySubtotal > 0.005 && $itemGrossAmount > $summarySubtotal + 0.02 && abs(round($summarySubtotal + $shipping + $tax - $total, 2)) <= 0.02) {
            // TSC in-store pages can show per-item subtotals before store discounts,
            // while the order-summary Subtotal is already net of those discounts and
            // no visible Discount row is rendered. Preserve product lines, then add
            // a reviewable discount/reconciliation line for the difference.
            $inferredDiscount = round($itemGrossAmount - $summarySubtotal, 2);
            $discount = $inferredDiscount;
        }
        $itemAmount=$itemGrossAmount;
        if (abs($discount) > 0.005) $itemAmount -= abs($discount);
        $paymentLabels=[]; foreach($orderPays as $pmt){ $paymentLabels[] = tsc_payment_label($pmt) . ' $' . fmt_money(abs(money_to_float((string)($pmt['amount'] ?? $total)))); }
        $warning=''; if(abs(round($itemAmount + $shipping + $tax - $total, 2)) > 0.02) $warning='Tractor Supply source totals do not reconcile exactly; review lines/discount/shipping/tax before export.';
        $orderNotes='Imported from Tractor Supply normalized scraper output.' . ($inferredDiscount > 0.005 ? ' Inferred Tractor Supply discount/reconciliation amount $' . fmt_money($inferredDiscount) . ' because item-line subtotals exceeded the order-summary subtotal.' : '');
        $insOrder->bindValue(':order_id',$oid,SQLITE3_TEXT); $insOrder->bindValue(':order_url',$url,SQLITE3_TEXT); $insOrder->bindValue(':order_date',$date,SQLITE3_TEXT); $insOrder->bindValue(':recipient',(string)($o['recipient'] ?? ''),SQLITE3_TEXT); $insOrder->bindValue(':items',implode('; ', array_slice($descs,0,6)),SQLITE3_TEXT); $insOrder->bindValue(':total',$total,SQLITE3_FLOAT); $insOrder->bindValue(':shipping',$shipping,SQLITE3_FLOAT); $insOrder->bindValue(':tax',$tax,SQLITE3_FLOAT); $insOrder->bindValue(':payments',implode('; ', $paymentLabels),SQLITE3_TEXT); $insOrder->bindValue(':expense_account','Expenses:Uncategorized',SQLITE3_TEXT); $insOrder->bindValue(':item_amount',$itemAmount,SQLITE3_FLOAT); $insOrder->bindValue(':tax_account',default_config_value('DEFAULT_TAX_ACCOUNT',$db),SQLITE3_TEXT); $insOrder->bindValue(':shipping_account',default_config_value('DEFAULT_SHIPPING_ACCOUNT',$db),SQLITE3_TEXT); $insOrder->bindValue(':warning',$warning,SQLITE3_TEXT); $insOrder->bindValue(':notes',$orderNotes,SQLITE3_TEXT); $insOrder->execute();
        $delItems->bindValue(':order_id',$oid,SQLITE3_TEXT); $delItems->execute();
        foreach($orderItems as $idx=>$it){
            $sku=trim((string)($it['sku'] ?? $it['item_key'] ?? ''));
            $lineNo=trim((string)($it['line_index'] ?? $it['line_number'] ?? $it['row'] ?? ''));
            if($lineNo==='' || !preg_match('/^\d+$/',$lineNo)) $lineNo=(string)($idx+1);
            $baseKey=$sku!==''?$sku:('TSC-LINE');
            // TSC in-store receipts can list the same SKU multiple times as separate
            // one-quantity rows.  order_items is keyed by vendor/order_id/item_key,
            // so using only the SKU here caused later repeated SKU rows to replace
            // earlier ones during INSERT OR REPLACE.  Keep the actual product SKU in
            // asin for account-rule matching, but make item_key line-specific.
            $key='TSC-LINE-'.str_pad($lineNo,4,'0',STR_PAD_LEFT).'-'.preg_replace('/[^A-Za-z0-9]+/','',$baseKey);
            $qty=(float)($it['quantity'] ?? 1); if($qty==0) $qty=1;
            $line=money_to_float((string)($it['line_total'] ?? $it['subtotal'] ?? $it['source_amount'] ?? '0'));
            $unit = $qty != 0 ? round($line/$qty, 6) : $line;
            $desc=clean_text((string)($it['description'] ?? 'Tractor Supply item'), 220);
            [$acct,$reason] = suggest_account($db, 'tractor_supply', 'sku', $sku, $desc, [], load_valid_account_set($gnucashPath));
            $notes=(string)($it['notes'] ?? '');
            if(strpos($notes,'source_line=')===false) $notes=trim($notes . ' source_line=' . $lineNo);
            $insItem->bindValue(':order_id',$oid,SQLITE3_TEXT); $insItem->bindValue(':item_key',$key,SQLITE3_TEXT); $insItem->bindValue(':order_url',$url,SQLITE3_TEXT); $insItem->bindValue(':order_date',$date,SQLITE3_TEXT); $insItem->bindValue(':quantity',$qty,SQLITE3_FLOAT); $insItem->bindValue(':description',$desc,SQLITE3_TEXT); $insItem->bindValue(':item_url',(string)($it['item_url'] ?? ''),SQLITE3_TEXT); $insItem->bindValue(':sku',$sku,SQLITE3_TEXT); $insItem->bindValue(':unit_price',$unit,SQLITE3_FLOAT); $insItem->bindValue(':source_amount',$line,SQLITE3_FLOAT); $insItem->bindValue(':expense_account',$acct ?: 'Expenses:Uncategorized',SQLITE3_TEXT); $insItem->bindValue(':notes',$notes,SQLITE3_TEXT); $insItem->bindValue(':match_reason',$reason ?: 'Tractor Supply SKU/title suggestion',SQLITE3_TEXT); $insItem->execute(); $itemCount++;
        }
        if(abs($discount) > 0.005){
            $key='TSC-DISCOUNT'; $line=-abs($discount); $insItem->bindValue(':order_id',$oid,SQLITE3_TEXT); $insItem->bindValue(':item_key',$key,SQLITE3_TEXT); $insItem->bindValue(':order_url',$url,SQLITE3_TEXT); $insItem->bindValue(':order_date',$date,SQLITE3_TEXT); $insItem->bindValue(':quantity',1,SQLITE3_FLOAT); $insItem->bindValue(':description','[DISCOUNT:TSC] Tractor Supply order discount',SQLITE3_TEXT); $insItem->bindValue(':item_url','',SQLITE3_TEXT); $insItem->bindValue(':sku',$key,SQLITE3_TEXT); $insItem->bindValue(':unit_price',$line,SQLITE3_FLOAT); $insItem->bindValue(':source_amount',$line,SQLITE3_FLOAT); $insItem->bindValue(':expense_account','Expenses:Uncategorized',SQLITE3_TEXT); $insItem->bindValue(':notes',($inferredDiscount > 0.005 ? 'Inferred discount/reconciliation from item-line subtotal vs order-summary subtotal.' : 'Order-level discount from Tractor Supply summary.'),SQLITE3_TEXT); $insItem->bindValue(':match_reason','Tractor Supply discount line',SQLITE3_TEXT); $insItem->execute(); $itemCount++;
        }
        $delPayments->bindValue(':order_id',$oid,SQLITE3_TEXT); $delPayments->execute();
        foreach($orderPays as $pidx=>$pmt){
            $amt=abs(money_to_float((string)($pmt['amount'] ?? $total))); if($amt<=0.005) continue;
            $method=tsc_payment_label($pmt); $mkey=tsc_payment_method_key($pmt); $acct=tsc_default_payment_account($db,$gnucashPath,$pmt);
            $pmStmt = $db->prepare('INSERT INTO payment_method_accounts (vendor, method_key, display_name, account_fullname, updated_at) VALUES ("tractor_supply",:key,:display,:acct,CURRENT_TIMESTAMP) ON CONFLICT(vendor,method_key) DO UPDATE SET display_name=excluded.display_name, account_fullname=CASE WHEN payment_method_accounts.account_fullname="" THEN excluded.account_fullname ELSE payment_method_accounts.account_fullname END, updated_at=CURRENT_TIMESTAMP');
            $pmStmt->bindValue(':key', $mkey, SQLITE3_TEXT); $pmStmt->bindValue(':display', $method, SQLITE3_TEXT); $pmStmt->bindValue(':acct', $acct, SQLITE3_TEXT); $pmStmt->execute();
            $pid=sha1('tractor_supply|'.$oid.'|'.$pidx.'|'.$method.'|'.fmt_money($amt));
            $insPay->bindValue(':pid',$pid,SQLITE3_TEXT); $insPay->bindValue(':oid',$oid,SQLITE3_TEXT); $insPay->bindValue(':url',$url,SQLITE3_TEXT); $insPay->bindValue(':pdate',$date,SQLITE3_TEXT); $insPay->bindValue(':method',$method,SQLITE3_TEXT); $insPay->bindValue(':mkey',$mkey,SQLITE3_TEXT); $insPay->bindValue(':amount',-$amt,SQLITE3_FLOAT); $insPay->bindValue(':acct',$acct,SQLITE3_TEXT); $insPay->bindValue(':notes','Tractor Supply payment row from normalized scraper output.',SQLITE3_TEXT); $insPay->execute(); $paymentCount++;
        }
        $orderCount++;
    }
    apply_payment_method_invoice_handling($db, 'tractor_supply');
    set_config($db, 'last_tsc_import_summary', "Imported/updated {$orderCount} Tractor Supply order(s), {$itemCount} accounting item rows, and {$paymentCount} payment rows.");
    return [$orderCount, '', $itemCount];
}
function import_vendor_file(SQLite3 $db, string $path, string $preferredVendor, string $gnucashPath=''): array {
    if (is_dir($path)) {
        if ($preferredVendor === 'tractor_supply') return import_tsc_normalized_zip($db, $path, $gnucashPath);
        if ($preferredVendor === 'lowes') return import_lowes_normalized_zip($db, $path, $gnucashPath);
        if ($preferredVendor === 'auto') {
            $base = rtrim($path, '/');
            if (is_file($base . '/tsc_orders.csv') || is_file($base . '/tsc_order_items.csv')) return import_tsc_normalized_zip($db, $path, $gnucashPath);
            if (is_file($base . '/lowes_orders.csv') || is_file($base . '/lowes_order_items.csv')) return import_lowes_normalized_zip($db, $path, $gnucashPath);
        }
        return [0, 'Local folder import supports Lowe\'s or Tractor Supply normalized scraper output. Choose the matching vendor tab/form, or Auto for a folder containing lowes_*.csv or tsc_*.csv normalized files.', 0];
    }
    $prefix = file_get_contents($path, false, null, 0, 4096);
    if ($prefix !== false && file_looks_like_zip($path, (string)$prefix)) {
        if ($preferredVendor === 'tractor_supply') return import_tsc_normalized_zip($db, $path, $gnucashPath);
        if ($preferredVendor === 'auto' || $preferredVendor === 'lowes') return import_lowes_normalized_zip($db, $path, $gnucashPath);
        return [0, 'Uploaded ZIP detected. ZIP import is currently supported for Lowe\'s and Tractor Supply normalized exports; choose the matching vendor tab/form.', 0];
    }
    if ($prefix !== false && preg_match('/^\s*[\[{]/', $prefix)) {
        $json = json_decode((string)file_get_contents($path), true);
        $first = is_array($json) ? (array_values($json)[0] ?? null) : null;
        if ($preferredVendor === 'costco' || (is_array($first) && isset($first['documentType'], $first['itemArray']))) return import_costco_json($db, $path, $gnucashPath);
        return import_amazon_json($db, $path, $gnucashPath);
    }
    if ($preferredVendor === 'lowes') return import_lowes_normalized_zip($db, $path, $gnucashPath);
    if ($preferredVendor === 'tractor_supply') return import_tsc_normalized_zip($db, $path, $gnucashPath);
    if ($preferredVendor === 'home_depot') return import_home_depot_csv($db, $path, $gnucashPath);
    if ($preferredVendor === 'walmart') return import_walmart_csv($db, $path, $gnucashPath);
    // CSV auto-detect: read the header before falling back to Amazon.  Binary/non-CSV
    // uploads should not fatal even if fgetcsv produces odd/null header cells.
    $fh = fopen($path, 'rb');
    if ($fh) {
        $hdr = fgetcsv($fh);
        fclose($fh);
        if ($hdr) {
            $fmt = detect_format(array_map('normalize_header_name', $hdr));
            if ($fmt === 'amazon_transactions' && in_array($preferredVendor, ['auto','amazon'], true)) {
                [$n, $msg] = import_amazon_transaction_history_csv($db, $path);
                if (payment_import_message_is_success((string)$msg)) {
                    $skippedByMethod = apply_payment_method_invoice_handling($db, 'amazon');
                    $validForAdjust = load_valid_account_set((string)$gnucashPath);
                    $primeYoungSplitAfterPayments = split_amazon_prime_young_adults_cashback_payments($db);
                    $inferredAfterPayments = infer_amazon_missing_stored_value_payments($db);
                    $adjustedAfterPayments = reconcile_amazon_order_promotions($db, $validForAdjust);
                    apply_amazon_discount_categories($db, $validForAdjust);
                    set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
                    $msg .= ($primeYoungSplitAfterPayments ? ' Cleaned/restored '.$primeYoungSplitAfterPayments.' Prime for Young Adults display-derived payment row(s).' : '')
                        . ($inferredAfterPayments ? ' Updated '.$inferredAfterPayments.' Amazon gift-card/rewards amount(s) from transaction history.' : '')
                        . ($adjustedAfterPayments ? ' Rebuilt '.$adjustedAfterPayments.' Amazon discount/reconciliation line(s).' : '')
                        . ($skippedByMethod ? ' Marked '.$skippedByMethod.' staged invoice(s) skipped because payment methods are excluded/out-of-book.' : '');
                    return [$n, '', 0, $msg];
                }
                return [0, $msg, 0];
            }
            if ($fmt === 'walmart_orders') return import_walmart_csv($db, $path, $gnucashPath);
            if ($fmt === 'home_depot_purchase_history') return import_home_depot_csv($db, $path, $gnucashPath);
        }
    }
    [$hdHeaderLine, $hdHeader, $hdEnc, $hdErr] = hd_find_purchase_history_header($path);
    if ($hdHeaderLine > 0) return import_home_depot_csv($db, $path, $gnucashPath);
    return import_amazon_order_csv($db, $path, $gnucashPath);
}
function vendor_import_postprocess_message(SQLite3 $db, string $chosenVendor, int $count, $itemCount, string $gnucashPath): string {
    $validForAdjust = load_valid_account_set((string)$gnucashPath);
    $amazonPrimeYoungSplit = split_amazon_prime_young_adults_cashback_payments($db);
    $amazonInferredStored = infer_amazon_missing_stored_value_payments($db);
    $amazonAudibleSkipped = mark_audible_credit_no_charge_orders($db);
    // Apply learned account suggestions before creating Amazon refund credit memos so
    // same-order refund matching can inherit the remembered ASIN/title category from
    // the parent item line instead of falling back to the order/default category.
    $suggested = apply_account_suggestions($db, (string)$gnucashPath);
    $amazonAdjustments = reconcile_amazon_order_promotions($db, $validForAdjust);
    // Re-apply suggestions after reconciliation lines are added; special Amazon
    // discount/credit rows are protected from global propagation by is_special_item_row().
    $suggested += apply_account_suggestions($db, (string)$gnucashPath);
    $amazonRefundCredits = create_amazon_refund_credit_memos($db, $validForAdjust);
    $amazonDiscountCats = apply_amazon_discount_categories($db, $validForAdjust);
    $cancelled = mark_cancelled_no_item_orders($db);
    $dupes = mark_existing_bills_to_skip($db, (string)$gnucashPath);
    $methodSkips = apply_payment_method_invoice_handling($db, 'amazon') + apply_payment_method_invoice_handling($db, 'lowes') + apply_payment_method_invoice_handling($db, 'home_depot') + apply_payment_method_invoice_handling($db, 'tractor_supply');
    $lowesSummary = get_config($db, 'last_lowes_import_summary', '');
    $tscSummary = get_config($db, 'last_tsc_import_summary', '');
    $hdSummary = get_config($db, 'last_home_depot_import_summary', '');
    if ($chosenVendor === 'lowes' && $lowesSummary !== '') $baseImportMessage = $lowesSummary . ' ';
    elseif ($chosenVendor === 'tractor_supply' && $tscSummary !== '') $baseImportMessage = $tscSummary . ' ';
    elseif ($chosenVendor === 'home_depot' && $hdSummary !== '') $baseImportMessage = $hdSummary . ' ';
    else $baseImportMessage = "Imported or updated $count vendor orders/rows" . ($itemCount !== null ? " and $itemCount item lines" : "") . ". ";
    return $baseImportMessage . "For Costco, existing staged item lines for each imported receipt were fully replaced before reinserting. For Lowe's, v10 normalized ZIPs or folders import sales as bills, returns as *-CREDIT documents, My Lowe's Money as stored-value payment rows, and credit-card mappings are seeded by last4 when a matching GnuCash account is found. Home Depot purchase-history CSVs import transaction groups as reviewable bills/credit memos, but the source CSV does not include tax/tender detail, so use the free-floating register scan to reconcile payments. Cleaned/restored $amazonPrimeYoungSplit Prime for Young Adults display-derived payment row(s). Inferred $amazonInferredStored missing Amazon gift-card/rewards payment amount(s). Added/updated $amazonAdjustments Amazon promotion/discount adjustment line(s). Added/updated $amazonRefundCredits Amazon refund credit memo(s). Marked $cancelled likely cancelled/no-charge Amazon order(s) and $amazonAudibleSkipped Audible-credit/no-current-charge order(s) as skipped. Applied $suggested account suggestions using only accounts found in the loaded GnuCash file. Marked $dupes existing GnuCash bill IDs and $methodSkips payment-method-excluded invoice(s) as skip.";
}

function import_amazon_file(SQLite3 $db, string $path, string $gnucashPath = ''): array {
    $prefix = file_get_contents($path, false, null, 0, 2048);
    if ($prefix !== false && preg_match('/^\s*[\[{]/', $prefix)) return import_amazon_json($db, $path, $gnucashPath);
    return import_amazon_order_csv($db, $path, $gnucashPath);
}
function import_amazon_order_csv(SQLite3 $db, string $path, string $gnucashPath = ''): array {
    $fh = fopen($path, 'rb'); if (!$fh) return [0, 'Could not open uploaded CSV.'];
    $header = fgetcsv($fh); if (!$header) return [0, 'No header row found.'];
    $header = array_map(fn($v) => normalize_header_name((string)$v), $header);
    $format = detect_format($header);
    $validAccounts = load_valid_account_set($gnucashPath);
    $count = 0;
    if ($format === 'amazon_items') {
        $stmt = $db->prepare('INSERT INTO order_items
            (vendor, order_id, item_key, order_url, order_date, quantity, description, item_url, asin, unit_price, subscribe_save, expense_account, notes)
            VALUES (:vendor,:order_id,:item_key,:order_url,:order_date,:quantity,:description,:item_url,:asin,:unit_price,:subscribe_save,:expense_account,:notes)
            ON CONFLICT(vendor, order_id, item_key) DO UPDATE SET
              order_url=excluded.order_url, order_date=excluded.order_date, quantity=excluded.quantity,
              description=excluded.description, item_url=excluded.item_url, asin=excluded.asin,
              unit_price=excluded.unit_price, subscribe_save=excluded.subscribe_save, notes=excluded.notes');
        $amazonItemOccurrence = [];
        while (($row = fgetcsv($fh)) !== false) {
            $r = []; foreach ($header as $i => $key) $r[$key] = $row[$i] ?? '';
            $order_id = trim($r['order id'] ?? ''); $date = amazon_csv_order_date($r);
            if ($order_id === '' || $order_id === 'order id' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $desc = (string)($r['description'] ?? ''); $asin = trim((string)($r['asin'] ?? ''));
            $qtyRaw = (string)($r['quantity'] ?? '');
            $priceRaw = (string)($r['price'] ?? '');
            $qty = (float)($qtyRaw ?: 1); $price = money_to_float($priceRaw);
            $dupSig = strtolower($order_id) . "\n" . strtolower($asin) . "\n" . trim($desc) . "\n" . trim($priceRaw) . "\n" . trim($qtyRaw);
            $amazonItemOccurrence[$dupSig] = ($amazonItemOccurrence[$dupSig] ?? 0) + 1;
            $occurrence = (int)$amazonItemOccurrence[$dupSig];
            $key = item_key($order_id, $asin, $desc, $priceRaw, $qtyRaw, $occurrence);
            upsert_minimal_order($db, 'amazon', $order_id, $date, (string)($r['order url'] ?? ''), $desc, $validAccounts);
            $stmt->bindValue(':vendor', 'amazon', SQLITE3_TEXT);
            $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
            $stmt->bindValue(':item_key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':order_url', $r['order url'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':order_date', $date, SQLITE3_TEXT);
            $stmt->bindValue(':quantity', $qty, SQLITE3_FLOAT);
            $stmt->bindValue(':description', $desc, SQLITE3_TEXT);
            $stmt->bindValue(':item_url', $r['item url'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':asin', $asin, SQLITE3_TEXT);
            $stmt->bindValue(':unit_price', $price, SQLITE3_FLOAT);
            $stmt->bindValue(':subscribe_save', money_to_float($r['subscribe & save'] ?? '0'), SQLITE3_FLOAT);
            $stmt->bindValue(':expense_account', default_account_for_items($desc, $validAccounts), SQLITE3_TEXT);
            $stmt->bindValue(':notes', 'ASIN: ' . $asin . ' Item URL: ' . ($r['item url'] ?? '') . ($occurrence > 1 ? ' Duplicate item occurrence #' . $occurrence . ' preserved from Amazon item export.' : ''), SQLITE3_TEXT);
            $stmt->execute(); $count++;
        }
        fclose($fh); return [$count, null];
    }
    if ($format !== 'amazon_orders') { fclose($fh); return [0, 'Unsupported CSV format. Expected Amazon order-level or item-level export.']; }
    $stmt = $db->prepare('INSERT INTO orders
        (vendor, order_id, order_url, order_date, recipient, items, total, shipping, shipping_refund, gift, tax, refund, payments, expense_account, item_amount, tax_account, shipping_account, warning, notes)
        VALUES (:vendor,:order_id,:order_url,:order_date,:recipient,:items,:total,:shipping,:shipping_refund,:gift,:tax,:refund,:payments,:expense_account,:item_amount,:tax_account,:shipping_account,:warning,:notes)
        ON CONFLICT(vendor, order_id) DO UPDATE SET
          order_url=excluded.order_url, order_date=excluded.order_date, recipient=excluded.recipient, items=excluded.items,
          total=excluded.total, shipping=excluded.shipping, shipping_refund=excluded.shipping_refund, gift=excluded.gift,
          tax=excluded.tax, refund=excluded.refund, payments=excluded.payments,
          tax_account=excluded.tax_account, shipping_account=excluded.shipping_account,
          warning=excluded.warning, notes=excluded.notes');
    while (($row = fgetcsv($fh)) !== false) {
        $r = []; foreach ($header as $i => $key) $r[$key] = $row[$i] ?? '';
        $order_id = trim($r['order id'] ?? ''); $date = amazon_csv_order_date($r);
        if ($order_id === '' || $order_id === 'order id' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $total = money_to_float($r['total'] ?? '0'); $shipping = money_to_float($r['shipping'] ?? '0');
        $shipping_refund = money_to_float($r['shipping_refund'] ?? '0'); $gift = money_to_float($r['gift'] ?? '0');
        $tax = money_to_float($r['tax'] ?? '0'); $refund = money_to_float($r['refund'] ?? '0');
        $net_shipping = $shipping - $shipping_refund;
        $gift_payment = abs($gift);
        // Amazon Gift Card / Rewards reduces the cash/card charge, but it should not reduce the vendor bill.
        // Build the bill from merchant-side subtotal/discounts/tax, then record gift balance as a payment hint.
        $estimated_bill_total = $total + $gift_payment;
        $item_amount = max(0.0, $estimated_bill_total - $tax - $net_shipping);
        $warning = [];
        $payments = (string)($r['payments'] ?? '');
        if (amazon_order_mentions_audible_credit((string)($r['items'] ?? ''), $payments) && abs($total) <= 1.01 && abs($tax) < 0.005 && abs($net_shipping) < 0.005 && abs($gift) < 0.005 && abs($refund) < 0.005) {
            $total = 0.0;
            $estimated_bill_total = 0.0;
            $item_amount = 0.0;
            $warning[] = 'Audible-credit/no-current-charge order; treated as a $0 no-charge order and skipped.';
        }
        if (str_contains($payments, 'Rewards Points') || str_contains($payments, 'cash back')) $warning[] = 'Rewards/cash-back payment present; review payment handling manually.';
        if (abs($gift) > 0.005) $warning[] = 'Gift-card/rewards payment present; treated as a payment source, not as a bill discount.';
        if ($refund > 0) $warning[] = 'Refund present. Handle refund/credit separately or adjust manually.';
        $stmt->bindValue(':vendor', 'amazon', SQLITE3_TEXT); $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
        $stmt->bindValue(':order_url', $r['order url'] ?? '', SQLITE3_TEXT); $stmt->bindValue(':order_date', $date, SQLITE3_TEXT);
        $stmt->bindValue(':recipient', $r['to'] ?? '', SQLITE3_TEXT); $stmt->bindValue(':items', $r['items'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':total', $total, SQLITE3_FLOAT); $stmt->bindValue(':shipping', $shipping, SQLITE3_FLOAT);
        $stmt->bindValue(':shipping_refund', $shipping_refund, SQLITE3_FLOAT); $stmt->bindValue(':gift', $gift, SQLITE3_FLOAT);
        $stmt->bindValue(':tax', $tax, SQLITE3_FLOAT); $stmt->bindValue(':refund', $refund, SQLITE3_FLOAT);
        $stmt->bindValue(':payments', $payments, SQLITE3_TEXT); $stmt->bindValue(':expense_account', default_account_for_items($r['items'] ?? '', $validAccounts), SQLITE3_TEXT);
        $stmt->bindValue(':item_amount', $item_amount, SQLITE3_FLOAT); $stmt->bindValue(':tax_account', default_config_value('DEFAULT_TAX_ACCOUNT'), SQLITE3_TEXT);
        $stmt->bindValue(':shipping_account', default_config_value('DEFAULT_SHIPPING_ACCOUNT'), SQLITE3_TEXT); $stmt->bindValue(':warning', implode(' ', $warning), SQLITE3_TEXT);
        $stmt->bindValue(':notes', trim('Amazon URL: ' . ($r['order url'] ?? '') . ' Payments: ' . $payments . ' Payment hint: ' . amazon_payment_hint(['vendor'=>'amazon','total'=>$total,'gift'=>$gift])), SQLITE3_TEXT);
        $stmt->execute(); $count++;
    }
    fclose($fh); return [$count, null];
}
function resolve_local_path(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if ($path[0] === '/') return $path;
    $candidate = __DIR__ . '/' . $path;
    if (file_exists($candidate)) return $candidate;
    $candidate = __DIR__ . '/data/' . $path;
    if (file_exists($candidate)) return $candidate;
    return $path;
}
function account_matches_prefixes(string $fullname, array $prefixes): bool {
    foreach ($prefixes as $p) { if ($p !== '' && str_starts_with($fullname, $p)) return true; }
    return false;
}
function load_gnucash_accounts_with_status(string $path, array $prefixes = ['Expenses:', 'Liabilities:'], string $label = 'Expense/Liability'): array {
    $cfg = gnucash_backend_config();
    if (($cfg['gnucash_backend'] ?? 'sqlite') !== 'sqlite') {
        try {
            $pdo = pdo_for_gnucash($cfg);
            $acctRows = $pdo->query('SELECT guid, name, account_type, parent_guid FROM accounts')->fetchAll();
            $byGuid = account_fullnames_by_rows($acctRows);
            $accounts=[]; foreach($byGuid as $a){ $fullname=$a['name']; if(account_matches_prefixes($fullname, $prefixes)) $accounts[]=['name'=>$fullname,'type'=>$a['type']]; }
            usort($accounts, fn($a,$b)=>strcmp($a['name'],$b['name']));
            return [$accounts, 'Loaded ' . count($accounts) . ' ' . $label . ' accounts from GnuCash ' . strtoupper($cfg['gnucash_backend']) . ' database ' . ($cfg['gnucash_db_name'] ?? '') . ' on ' . ($cfg['gnucash_db_host'] ?? 'localhost') . ' (read-only queries).'];
        } catch(Throwable $e) { return [[], 'Unable to read GnuCash SQL backend: ' . $e->getMessage()]; }
    }
    $resolved = resolve_local_path($path);
    $status = $path === '' ? 'No GnuCash file path supplied.' : 'Using path: ' . $resolved;
    if ($resolved === '') return [[], $status];
    if (!file_exists($resolved)) return [[], 'GnuCash file not found: ' . $resolved];
    if (!is_readable($resolved)) return [[], 'GnuCash file exists but is not readable by the PHP-FPM user: ' . $resolved];
    try {
        $db = new SQLite3($resolved, SQLITE3_OPEN_READONLY); $rows = [];
        $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'");
        if ($tableCheck !== 'accounts') return [[], 'Opened file, but it does not look like a SQLite GnuCash file with an accounts table: ' . $resolved];
        $res = $db->query('SELECT guid, name, account_type, parent_guid FROM accounts');
        if (!$res) return [[], 'Opened SQLite file, but account query failed: ' . $db->lastErrorMsg()];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[$r['guid']] = $r;
        $memo = [];
        $full = function($guid) use (&$full, &$rows, &$memo): string {
            if (isset($memo[$guid])) return $memo[$guid]; if (!isset($rows[$guid])) return '';
            $name = $rows[$guid]['name']; $parent = $rows[$guid]['parent_guid'];
            if (!$parent || !isset($rows[$parent]) || strtolower($rows[$parent]['name']) === 'root account') return $memo[$guid] = $name;
            $p = $full($parent); return $memo[$guid] = ($p ? $p . ':' : '') . $name;
        };
        $accounts = [];
        foreach ($rows as $guid => $r) {
            $fullname = $full($guid);
            if (account_matches_prefixes($fullname, $prefixes)) $accounts[] = ['name'=>$fullname,'type'=>$r['account_type']];
        }
        usort($accounts, fn($a,$b) => strcmp($a['name'], $b['name']));
        return [$accounts, 'Loaded ' . count($accounts) . ' ' . $label . ' accounts from: ' . $resolved];
    } catch (Throwable $e) { return [[], 'Unable to open/read GnuCash SQLite file: ' . $e->getMessage()]; }
}
function load_gnucash_accounts(string $path): array {
    [$accounts, $status] = load_gnucash_accounts_with_status($path);
    return $accounts;
}
function load_extra_accounts(SQLite3 $db): array {
    $out = [];
    $res = $db->query('SELECT name, account_type FROM extra_accounts ORDER BY name');
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = ['name'=>(string)$r['name'], 'type'=>(string)$r['account_type'], 'source'=>'extra'];
    return $out;
}
function add_extra_account(SQLite3 $db, string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return [false, 'Account name is blank.'];
    if (!preg_match('/^(Expenses|Liabilities):[^:]+/', $name)) return [false, 'For this tool, new accounts must start with Expenses: or Liabilities:.'];
    if (str_contains($name, "
") || str_contains($name, "
")) return [false, 'Account name contains invalid line breaks.'];
    $type = str_starts_with($name, 'Liabilities:') ? 'LIABILITY' : 'EXPENSE';
    $stmt = $db->prepare('INSERT INTO extra_accounts (name, account_type) VALUES (:name, :type) ON CONFLICT(name) DO NOTHING');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->execute();
    return [true, 'Added local account option: ' . $name];
}

function set_invoice_items_category(SQLite3 $db, string $vendor, string $order_id, string $account): array {
    $account = trim((string)$account);
    if ($account === '') return [0, 'No category was entered for the invoice-wide category update.'];
    $vendor = strtolower(trim($vendor));
    $order_id = trim($order_id);
    if ($vendor === '' || $order_id === '') return [0, 'Invalid invoice key for invoice-wide category update.'];

    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM order_items WHERE vendor=:vendor AND order_id=:order_id');
    $countStmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $countStmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
    $countRow = $countStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $itemCount = (int)($countRow['c'] ?? 0);

    if ($itemCount > 0) {
        $stmt = $db->prepare('UPDATE order_items SET expense_account=:expense, account_change_source="manual_invoice_bulk", account_last_changed_at=:changed_at WHERE vendor=:vendor AND order_id=:order_id');
        $stmt->bindValue(':expense', $account, SQLITE3_TEXT);
        $stmt->bindValue(':changed_at', now_sql(), SQLITE3_TEXT);
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
        $stmt->execute();
        $changed = $db->changes();
        return [$changed, 'Set all ' . $changed . ' item line category field(s) on invoice ' . $order_id . ' to ' . $account . '. This is invoice-local only; use "Use invoice as category reference" when you want to propagate SKU/ASIN rules to other invoices.'];
    }

    $stmt = $db->prepare('UPDATE orders SET expense_account=:expense WHERE vendor=:vendor AND order_id=:order_id');
    $stmt->bindValue(':expense', $account, SQLITE3_TEXT);
    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
    $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT);
    $stmt->execute();
    $changed = $db->changes();
    return [$changed, 'Set the fallback expense category on no-item invoice ' . $order_id . ' to ' . $account . '.'];
}

function save_review_edits(SQLite3 $db, array $post): void {
    $last = null;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        try {
            $db->busyTimeout(30000);
            $db->exec('BEGIN IMMEDIATE');
            foreach ($post['order'] ?? [] as $key => $data) {
                    [$vendor, $order_id] = explode('|', $key, 2);
                    $stmt = $db->prepare('UPDATE orders SET expense_account=:expense, item_amount=:item_amount, tax_account=:tax_account, shipping_account=:shipping_account, skip=:skip, locked=:locked, notes=:notes WHERE vendor=:vendor AND order_id=:order_id');
                    $stmt->bindValue(':expense', $data['expense_account'] ?? '', SQLITE3_TEXT); $stmt->bindValue(':item_amount', money_to_float($data['item_amount'] ?? '0'), SQLITE3_FLOAT);
                    $stmt->bindValue(':tax_account', $data['tax_account'] ?? default_config_value('DEFAULT_TAX_ACCOUNT'), SQLITE3_TEXT); $stmt->bindValue(':shipping_account', $data['shipping_account'] ?? default_config_value('DEFAULT_SHIPPING_ACCOUNT'), SQLITE3_TEXT);
                    $stmt->bindValue(':skip', isset($data['skip']) ? 1 : 0, SQLITE3_INTEGER); $stmt->bindValue(':locked', isset($data['locked']) ? 1 : 0, SQLITE3_INTEGER); $stmt->bindValue(':notes', $data['notes'] ?? '', SQLITE3_TEXT);                    $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT); $stmt->execute();
                }
                foreach ($post['item'] ?? [] as $key => $data) {
                    [$vendor, $order_id, $item_key] = explode('|', $key, 3);
                    $lookupOld = $db->prepare('SELECT expense_account FROM order_items WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key');
                    $lookupOld->bindValue(':vendor', $vendor, SQLITE3_TEXT); $lookupOld->bindValue(':order_id', $order_id, SQLITE3_TEXT); $lookupOld->bindValue(':item_key', $item_key, SQLITE3_TEXT);
                    $oldRow = $lookupOld->execute()->fetchArray(SQLITE3_ASSOC);
                    $oldAcct = trim((string)($oldRow['expense_account'] ?? ''));
                    $newAcct = trim((string)($data['expense_account'] ?? ''));
                    $manualChanged = ($newAcct !== $oldAcct);
                    $stmt = $db->prepare('UPDATE order_items SET expense_account=:expense, unit_price=:unit_price, skip=:skip, locked=:locked, notes=:notes, account_change_source=CASE WHEN :manual_changed=1 THEN "manual" ELSE account_change_source END, account_last_changed_at=CASE WHEN :manual_changed=1 THEN :changed_at ELSE account_last_changed_at END WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key');
                    $stmt->bindValue(':expense', $newAcct, SQLITE3_TEXT); $stmt->bindValue(':unit_price', money_to_float($data['unit_price'] ?? '0'), SQLITE3_FLOAT); $stmt->bindValue(':skip', isset($data['skip']) ? 1 : 0, SQLITE3_INTEGER);
                    $stmt->bindValue(':locked', isset($data['locked']) ? 1 : 0, SQLITE3_INTEGER); $stmt->bindValue(':notes', $data['notes'] ?? '', SQLITE3_TEXT); $stmt->bindValue(':manual_changed', $manualChanged ? 1 : 0, SQLITE3_INTEGER); $stmt->bindValue(':changed_at', now_sql(), SQLITE3_TEXT); $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT); $stmt->bindValue(':order_id', $order_id, SQLITE3_TEXT); $stmt->bindValue(':item_key', $item_key, SQLITE3_TEXT); $stmt->execute();
                    $acct = $newAcct;
                    if ($manualChanged && $vendor !== 'lowes' && $acct !== '' && str_starts_with($acct, 'Expenses:') && !is_placeholder_account($acct)) {
                        $lookup = $db->prepare('SELECT asin, description, notes FROM order_items WHERE vendor=:vendor AND order_id=:order_id AND item_key=:item_key');
                        $lookup->bindValue(':vendor', $vendor, SQLITE3_TEXT); $lookup->bindValue(':order_id', $order_id, SQLITE3_TEXT); $lookup->bindValue(':item_key', $item_key, SQLITE3_TEXT);
                        $lr = $lookup->execute()->fetchArray(SQLITE3_ASSOC);
                        if ($lr && !is_reconcile_or_discount_item(['vendor'=>$vendor,'order_id'=>$order_id,'item_key'=>$item_key,'asin'=>$lr['asin'] ?? '', 'description'=>$lr['description'] ?? ''])) {
                            $type = vendor_uses_sku_product_rules($vendor) ? 'sku' : ($vendor === 'walmart' ? 'title' : 'asin');
                            $primaryKey = trim((string)$lr['asin']);
                            $targetSku = $vendor === 'costco' ? extract_costco_target_sku((string)$lr['notes'] . ' ' . (string)$lr['description']) : '';
                            if ($targetSku !== '') $primaryKey = $targetSku;
                            if ($type === 'title') $primaryKey = normalize_key_text((string)$lr['description']);
                            if ($primaryKey !== '') upsert_account_rule($db, $vendor, $type, $primaryKey, $acct, 'user_review_manual_changed', 105);
                            $norm = normalize_key_text((string)$lr['description']);
                            if ($norm !== '') upsert_account_rule($db, $vendor, 'title', $norm, $acct, 'user_review_manual_changed', 80);
                        }
                    }
                }
                apply_credit_return_item_selections($db, $post);
                apply_costco_coupon_categories($db);
                apply_amazon_discount_categories($db);
            $db->exec('COMMIT');
            return;
        } catch (Throwable $e) {
            $last = $e;
            try { $db->exec('ROLLBACK'); } catch (Throwable $ignore) {}
            if (stripos($e->getMessage(), 'locked') === false && stripos($e->getMessage(), 'busy') === false) throw $e;
            usleep(200000 * $attempt);
        }
    }
    throw $last ?: new RuntimeException('Save failed after retries.');
}



function export_batch_orders(SQLite3 $db, int $limitOrders, int $offsetOrders): array {
    $limitOrders = max(1, $limitOrders);
    $offsetOrders = max(0, $offsetOrders);
    $out = [];
    $sql = 'SELECT vendor, order_id, order_date FROM orders WHERE skip=0 ORDER BY ' . date_sort_sql() . ', order_id LIMIT ' . (int)$limitOrders . ' OFFSET ' . (int)$offsetOrders;
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}
function export_batch_summary_html(array $orders, int $batchNumber, int $batchSize, int $totalOrders): string {
    if (!$orders) return '<p class="small"><strong>Selected export batch:</strong> no non-skipped/exportable invoices in this batch.</p>';
    $start = (($batchNumber - 1) * $batchSize) + 1;
    $end = $start + count($orders) - 1;
    $first = $orders[0]; $last = $orders[count($orders)-1];
    $html = '<p class="small"><strong>Selected export batch ' . h((string)$batchNumber) . ':</strong> exportable/non-skipped invoices ' . h((string)$start) . '-' . h((string)$end) . ' of ' . h((string)$totalOrders) . '. First included: <code>' . h((string)$first['order_id']) . '</code> (' . h((string)$first['order_date']) . '); last included: <code>' . h((string)$last['order_id']) . '</code> (' . h((string)$last['order_date']) . ').</p>';
    $html .= '<details open><summary class="small">Show invoice IDs included in this selected batch</summary><div class="small" style="columns:3; margin-top:.4rem">';
    foreach ($orders as $i => $r) {
        $seq = $start + $i;
        $html .= '<div><strong>' . h((string)$seq) . '.</strong> <code>' . h((string)$r['order_id']) . '</code> ' . h((string)$r['order_date']) . ' ' . h((string)$r['vendor']) . '</div>';
    }
    $html .= '</div></details>';
    return $html;
}
function make_export_link(string $filename): string {
    return 'exports/' . rawurlencode($filename);
}

function gnucash_download_basename(string $prefix, string $stamp): string {
    $prefix = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($prefix));
    if ($prefix === '') $prefix = 'gnucash-book';
    return $prefix . '-' . $stamp . '-' . APP_VERSION . '.gnucash';
}

function copy_gnucash_book_for_download(string $sourcePath, string $filename): array {
    $sourcePath = trim($sourcePath);
    $filename = basename($filename);
    if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) return [false, 'Selected GnuCash book is not readable: ' . $sourcePath];
    $dest = __DIR__ . '/exports/' . $filename;
    if (!@copy($sourcePath, $dest)) return [false, 'Could not copy GnuCash book to export/download path: ' . $dest];
    @chmod($dest, 0660);
    return [true, $dest];
}

function render_changed_gnucash_download_section(SQLite3 $db, string $context, string $modeValue = 'transactions', string $paymentStep = 'apply'): string {
    $context = strtolower(trim($context));
    if ($context === '') $context = 'changed-book';
    $contextKey = preg_replace('/[^a-z0-9_]+/', '_', $context);
    $lastName = get_config($db, 'last_changed_book_download_' . $contextKey, '');
    $safeMode = h($modeValue);
    $safeStep = h($paymentStep);
    $safeContext = h($context);
    $out = '<div class="card" style="background:#f7fff7;border-color:#9fca9f;margin:.75rem 0" id="download-changed-file-' . h($contextKey) . '">';
    $out .= '<h4>Download changed file</h4>';
    $out .= '<p class="small">After changes applied, click this link to download and save your modified file. This creates a downloadable <code>.gnucash</code> copy from the current uploaded working file in <code>data/</code>.</p>';
    $out .= '<form method="post" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">';
    $out .= '<input type="hidden" name="action" value="create_current_gnucash_download_copy">';
    $out .= '<input type="hidden" name="mode" value="' . $safeMode . '">';
    $out .= '<input type="hidden" name="payment_wizard_step" value="' . $safeStep . '">';
    $out .= '<input type="hidden" name="download_context" value="' . $safeContext . '">';
    $out .= '<button type="submit">Create download link for changed file</button>';
    if ($lastName !== '' && is_file(__DIR__ . '/exports/' . basename($lastName))) {
        $out .= '<a class="wizard-step ok" href="' . h(make_export_link(basename($lastName))) . '" download>Download last changed file: ' . h(basename($lastName)) . '</a>';
    }
    $out .= '</form>';
    $out .= '</div>';
    return $out;
}

function is_export_or_validation_action(string $action): bool {
    $action = strtolower(trim($action));
    return $action === 'export' || $action === 'export_range' || $action === 'validate_export' || str_starts_with($action, 'export_');
}

function render_action_report_html(?string $message, ?string $error, array $exportLinks = [], string $title = 'Action report', string $id = 'action-report'): string {
    if (($message === null || $message === '') && ($error === null || $error === '') && empty($exportLinks)) return '';

    $html = '<div class="card action-report" id="' . h($id) . '">';
    $html .= '<h3>' . h($title) . '</h3>';

    if ($message !== null && $message !== '') {
        $html .= '<p class="ok">' . h($message) . '</p>';
    }

    if ($error !== null && $error !== '') {
        $html .= '<p class="error">' . h($error) . '</p>';

        if (str_contains($error, 'Default-variable configuration warning')) {
            $html .= '<p><a class="button" href="?mode=config#default-variables">Open Default variables configuration</a></p>';
        }
    }

    if (!empty($exportLinks)) {
        $html .= '<p><strong>Created export/download file(s):</strong></p><ul>';
        foreach ($exportLinks as $l) {
            $name = (string)($l['name'] ?? '');
            if ($name === '') continue;
            $batch = (string)($l['batch'] ?? 'export');
            $count = (string)($l['count'] ?? '');
            $rows = (string)($l['rows'] ?? '');
            $first = (string)($l['first'] ?? '');
            $last = (string)($l['last'] ?? '');

            $html .= '<li><a href="' . h(make_export_link($name)) . '" download>' . h($name) . '</a> — ' . h($batch);
            if ($count !== '') $html .= ', ' . h($count) . ' document/report item(s)';
            if ($rows !== '') $html .= ', ' . h($rows) . ' CSV row(s)';
            if ($first !== '') $html .= ', first included ' . h($first) . ' through last included ' . h($last);
            $html .= '</li>';
        }
        $html .= '</ul>';
        $html .= '<p class="small">Use these links for the next import/apply step. Right-click/open links or download one file at a time if your browser blocks multiple downloads.</p>';
    }

    $html .= '</div>';
    return $html;
}



function format_gnucash_post_date($date, string $format): string {
    $date = trim((string)$date);
    if ($date === '') return '';
    $ts = strtotime($date);
    if ($ts === false) return $date;
    switch ($format) {
        case 'mdy_slash': return date('m/d/Y', $ts);
        case 'dmy_slash': return date('d/m/Y', $ts);
        case 'iso':
        default: return date('Y-m-d', $ts);
    }
}
function post_date_format_label(string $format): string {
    switch ($format) {
        case 'mdy_slash': return 'MM/DD/YYYY';
        case 'dmy_slash': return 'DD/MM/YYYY';
        case 'iso':
        default: return 'YYYY-MM-DD';
    }
}
function normalize_date_sort($v): string {
    $v = strtolower(trim((string)$v));
    return in_array($v, ['asc','desc'], true) ? $v : 'desc';
}
function date_sort_sql(string $alias = ''): string {
    global $dateSort;
    $prefix = $alias !== '' ? $alias . '.' : '';
    $dir = normalize_date_sort($dateSort ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    return $prefix . 'order_date ' . $dir;
}
function sort_rows_by_date_pref(array $rows, string $dateKey = 'payment_date'): array {
    global $dateSort;
    $dir = normalize_date_sort($dateSort ?? 'desc');
    usort($rows, function($a, $b) use ($dateKey, $dir) {
        $ad = (string)($a[$dateKey] ?? $a['order_date'] ?? '');
        $bd = (string)($b[$dateKey] ?? $b['order_date'] ?? '');
        $cmp = strcmp($ad, $bd);
        if ($cmp === 0) $cmp = strcmp((string)($a['order_id'] ?? ''), (string)($b['order_id'] ?? ''));
        return $dir === 'asc' ? $cmp : -$cmp;
    });
    return $rows;
}
function date_sort_query_part(): string {
    global $dateSort;
    return '&date_sort=' . rawurlencode(normalize_date_sort($dateSort ?? 'desc'));
}

function default_configuration_validation_issues_for_vendors(array $vendors): array {
    $vendors = array_values(array_unique(array_filter(array_map(
        fn($v) => strtolower(trim((string)$v)),
        $vendors
    ))));

    if (!$vendors) return [];

    $builtins = default_variable_config_builtin_defaults();
    $active = default_variable_config_defaults();
    $overrides = load_user_default_config_overrides();

    $requiredByVendor = [
        'amazon' => [
            'DEFAULT_VENDOR_AMAZON',
            'DEFAULT_PAYMENT_ACCOUNT_AMAZON',
            'DEFAULT_REWARDS_ACCOUNT_AMAZON',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
        'costco' => [
            'DEFAULT_VENDOR_COSTCO',
            'DEFAULT_STORED_VALUE_ACCOUNT_COSTCO',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
        'walmart' => [
            'DEFAULT_VENDOR_WALMART',
            'DEFAULT_STORED_VALUE_ACCOUNT_WALMART',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
        'lowes' => [
            'DEFAULT_VENDOR_LOWES',
            'DEFAULT_STORED_VALUE_ACCOUNT_LOWES',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
        'tractor_supply' => [
            'DEFAULT_VENDOR_TRACTOR_SUPPLY',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
        'home_depot' => [
            'DEFAULT_VENDOR_HOME_DEPOT',
            'DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT',
            'DEFAULT_AP_ACCOUNT',
            'DEFAULT_TAX_ACCOUNT',
            'DEFAULT_SHIPPING_ACCOUNT',
        ],
    ];

    $keys = [];
    foreach ($vendors as $vendor) {
        foreach (($requiredByVendor[$vendor] ?? []) as $key) {
            $keys[$key] = true;
        }
    }

    if (!$keys) return [];

    $issues = [];
    foreach (array_keys($keys) as $key) {
        $activeValue = trim((string)($active[$key] ?? ''));
        $builtinValue = trim((string)($builtins[$key] ?? ''));
        $hasOverride = array_key_exists($key, $overrides);

        if ($activeValue === '') {
            $issues[] = $key . ' is blank';
            continue;
        }

        // The local user_defaults.php file is the user's acknowledgement that the value
        // was reviewed. If a selected-batch vendor still uses the built-in value without
        // a user override, warn before export so new installs do not silently use project
        // defaults or placeholder accounts/vendor IDs.
        if (!$hasOverride && $builtinValue !== '' && $activeValue === $builtinValue) {
            $issues[] = $key . ' still uses the built-in default value (' . $activeValue . ')';
        }
    }

    return $issues;
}
function validate_export_ready(SQLite3 $db, array $accounts, string $gnucashPath, int $limitOrders = 0, int $offsetOrders = 0, bool $postInvoices = false, string $postingAccount = ''): array {
    if (trim($postingAccount) === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT', $db);
    $valid=[]; foreach($accounts as $a) $valid[(string)$a['name']] = true;
    $errors=[]; $invalid=[];
    $requiresPostedAccount = $postInvoices;
    if (trim($gnucashPath) === '' || !$valid) $errors[] = 'No readable GnuCash SQLite account file is loaded; account validation cannot be performed.';
    $check = function(string $acct, array $where) use (&$invalid, $valid) {
        $acct = trim($acct);
        if ($acct === '') { $invalid['(blank account)'][] = $where; return; }
        // Validate against the GnuCash account names as selected in the UI. The export step may optionally
        // add a Root Account: prefix for GnuCash importer compatibility, but the UI stores canonical names.
        if (!isset($valid[$acct])) $invalid[$acct][] = $where;
    };
    $sql = 'SELECT * FROM orders WHERE skip=0 ORDER BY ' . date_sort_sql() . ', order_id';
    if ($limitOrders > 0) $sql .= ' LIMIT ' . (int)$limitOrders . ' OFFSET ' . max(0, (int)$offsetOrders);
    $res=$db->query($sql);
    while($r=$res->fetchArray(SQLITE3_ASSOC)){
        $id=(string)$r['order_id']; $vendor=(string)$r['vendor']; $selectedValidationVendors[strtolower(trim($vendor))]=true;
                $itemRes=$db->query("SELECT * FROM order_items WHERE vendor='".SQLite3::escapeString($vendor)."' AND order_id='".SQLite3::escapeString($id)."' AND skip=0");
        $itemCount=0;
        while($it=$itemRes->fetchArray(SQLITE3_ASSOC)){
            $itemCount++;
            $check((string)$it['expense_account'], [
                'vendor'=>$vendor,
                'order_id'=>$id,
                'item_key'=>(string)$it['item_key'],
                'type'=>'item',
                'label'=>$vendor.' '.$id.' item: '.clean_text((string)$it['description'],90),
            ]);
        }
        if($itemCount===0) $check((string)$r['expense_account'], ['vendor'=>$vendor,'order_id'=>$id,'type'=>'fallback','label'=>$vendor.' '.$id.' fallback item line']);
        $ship=(float)$r['shipping']-(float)$r['shipping_refund'];
        if($ship>0.0001) $check((string)$r['shipping_account'], ['vendor'=>$vendor,'order_id'=>$id,'type'=>'shipping','label'=>$vendor.' '.$id.' shipping']);
        $tax=(float)$r['tax'];
        if(abs($tax)>0.0001) $check((string)$r['tax_account'], ['vendor'=>$vendor,'order_id'=>$id,'type'=>'tax','label'=>$vendor.' '.$id.' sales tax']);
        $itemSum = (float)$db->querySingle("SELECT COALESCE(SUM(CASE WHEN vendor IN ('costco','walmart') AND source_amount IS NOT NULL THEN source_amount ELSE quantity*unit_price END),0) FROM order_items WHERE vendor='".SQLite3::escapeString($vendor)."' AND order_id='".SQLite3::escapeString($id)."' AND skip=0");
        if ($itemCount === 0) $itemSum = (float)$r['item_amount'];
        $calcTotal = round($itemSum + $ship + $tax, 2);
        $reportedTotal = order_bill_total_for_validation($r);
        if (abs($calcTotal - $reportedTotal) > 0.01) {
            $msg = $vendor.' '.$id.' total mismatch: item lines $'.fmt_money($itemSum).' + shipping $'.fmt_money($ship).' + tax $'.fmt_money($tax).' = $'.fmt_money($calcTotal).', but expected bill/vendor total is $'.fmt_money($reportedTotal).'.';
            $errors[] = $msg;
            $invalid['(total mismatch)'][] = [
                'vendor'=>$vendor,
                'order_id'=>$id,
                'type'=>'total_mismatch',
                'label'=>$msg,
            ];
        }
    }
    if (!isset($selectedValidationVendors) || !is_array($selectedValidationVendors)) {
        $selectedValidationVendors = [];
    }
    $defaultConfigIssues = default_configuration_validation_issues_for_vendors(array_keys($selectedValidationVendors));
    if ($defaultConfigIssues) {
        $msg = 'Default-variable configuration warning: selected batch uses vendor/account defaults that need review before export. ' . implode('; ', $defaultConfigIssues) . '. Open the Default variables configuration before importing or exporting bills, then re-run validation.';
        $errors[] = $msg;
        $invalid['(default configuration)'][] = [
            'vendor'=>'system',
            'order_id'=>'',
            'type'=>'default_configuration',
            'label'=>$msg,
        ];
    }

    if ($requiresPostedAccount) {
        $check($postingAccount, ['vendor'=>'system','order_id'=>'','type'=>'posted_account','label'=>'posted bill Accounts Payable account: ' . $postingAccount]);
    }
    if ($invalid) $errors[] = 'Invalid or missing accounts were found in the selected export batch. Create them in GnuCash or change the review rows before exporting. Click an item below to load the matching order in the review section.';
    return [$errors, $invalid];
}
function export_gnucash_bill_csv(SQLite3 $db, string $outPath, bool $withHeader = false, int $limitOrders = 0, int $offsetOrders = 0, string $accountPrefix = '', bool $postInvoices = false, string $postingAccount = '', string $postDateFormat = 'mdy_slash'): int {
    if (trim($postingAccount) === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT', $db);
    $fh = fopen($outPath, 'wb');
    $cols = ['id','date_opened','owner_id','billingid','notes','date','desc','action','account','quantity','price','disc_type','disc_how','discount','taxable','taxincluded','tax_table','date_posted','due_date','account_posted','memo_posted','accu_splits'];
    if ($withHeader) fputcsv($fh, $cols);
    $sql = 'SELECT * FROM orders WHERE skip=0 ORDER BY ' . date_sort_sql() . ', order_id';
    if ($limitOrders > 0) $sql .= ' LIMIT ' . (int)$limitOrders . ' OFFSET ' . max(0, (int)$offsetOrders);
    $res = $db->query($sql); $n = 0;
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $vcfg = vendor_config((string)$r['vendor']); $vendor_id = $vcfg['vendor_id']; $date = $r['order_date']; $id = canonical_order_id_for_export((string)$r['vendor'], (string)$r['order_id']);
        // GnuCash's bill import is sensitive to date format. Use the selected date
        // format consistently for the invoice open date, each line item date, and
        // optional posted/due dates so line items do not default to today's date.
        $exportDate = format_gnucash_post_date($date, $postDateFormat);
        $billing = strtoupper((string)$r['vendor']) . ' ' . $id; $notes = trim((string)$r['notes']);
        $rowVendor = (string)$r['vendor'];
        $rowPostInvoices = $postInvoices || vendor_requires_posted_bill_export($rowVendor);
        $datePosted = $rowPostInvoices ? $exportDate : '';
        $dueDate = $rowPostInvoices ? $exportDate : '';
        $acctPosted = $rowPostInvoices ? export_account_name($postingAccount, $accountPrefix) : '';
        $memoPosted = $rowPostInvoices ? ('Imported vendor bill ' . $billing) : '';
         $itemRes = $db->query("SELECT * FROM order_items WHERE vendor='" . SQLite3::escapeString($r['vendor']) . "' AND order_id='" . SQLite3::escapeString($id) . "' AND skip=0 ORDER BY description");
        $itemCount = 0;
        while ($it = $itemRes->fetchArray(SQLITE3_ASSOC)) {
            $qty = (float)$it['quantity']; if (abs($qty) < 0.0001) $qty = 1.0; $price = (float)$it['unit_price'];
            $acct = export_account_name($it['expense_account'] ?: 'Expenses:Uncategorized', $accountPrefix);
            fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,clean_text($it['description'] ?? '', 400),'',$acct,fmt_quantity($qty),fmt_unit_price($price, $qty),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']);
            $itemCount++; $n++;
        }
        if ($itemCount === 0) {
            $item_amount = (float)$r['item_amount'];
            $acct = export_account_name($r['expense_account'] ?: 'Expenses:Uncategorized', $accountPrefix);
            fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,clean_text($r['items'] ?? '', 400),'',$acct,'1',fmt_money($item_amount),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++;
        }
        $shippingCharge = (float)$r['shipping'];
        $shippingRefund = (float)$r['shipping_refund'];
        $shipAcct = export_account_name($r['shipping_account'] ?: default_config_value('DEFAULT_SHIPPING_ACCOUNT'), $accountPrefix);
        if (abs($shippingCharge) > 0.0001) { fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Shipping','',$shipAcct,'1',fmt_money($shippingCharge),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
        if (abs($shippingRefund) > 0.0001) { fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Free Shipping / Shipping promotion','',$shipAcct,'1',fmt_money(-abs($shippingRefund)),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
        $tax = (float)$r['tax'];
        if (abs($tax) > 0.0001) { $acct = export_account_name($r['tax_account'] ?: default_config_value('DEFAULT_TAX_ACCOUNT'), $accountPrefix); fputcsv($fh, [$id,$exportDate,$vendor_id,$billing,$notes,$exportDate,'Sales tax','',$acct,'1',fmt_money($tax),'','','','N','N','',$datePosted,$dueDate,$acctPosted,$memoPosted,'N']); $n++; }
    }
    fclose($fh); return $n;
}

function skipped_orders_preview(SQLite3 $db, int $limit = 75): array {
    $out = [];
    $res = $db->query('SELECT vendor, order_id, order_date, total, warning, notes FROM orders WHERE skip<>0 ORDER BY ' . date_sort_sql() . ', order_id LIMIT ' . max(1, (int)$limit));
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $reason = trim((string)($r['warning'] ?? ''));
        if ($reason === '') $reason = 'Skipped manually or by prior import state; no explicit reason stored.';
        $out[] = [
            'vendor'=>(string)$r['vendor'],
            'order_id'=>(string)$r['order_id'],
            'order_date'=>(string)$r['order_date'],
            'total'=>(float)$r['total'],
            'reason'=>$reason,
        ];
    }
    return $out;
}

function unskip_selected_orders(SQLite3 $db, array $orders): int {
    $stmt = $db->prepare('UPDATE orders SET skip=0, warning=trim(REPLACE(REPLACE(COALESCE(warning,""), "Existing bill/invoice ID found in GnuCash; marked skip to avoid duplicate import.", ""), "Existing posted bill/invoice ID found in GnuCash; marked skip to avoid duplicate import.", "")) WHERE vendor=:vendor AND order_id=:order_id');
    $n = 0;
    foreach ($orders as $key) {
        $parts = explode('|', (string)$key, 2);
        if (count($parts) !== 2) continue;
        [$vendor, $orderId] = $parts;
        if ($vendor === '' || $orderId === '') continue;
        $stmt->bindValue(':vendor', $vendor, SQLITE3_TEXT);
        $stmt->bindValue(':order_id', $orderId, SQLITE3_TEXT);
        retry_sqlite_write(fn() => $stmt->execute());
        $n++;
    }
    return $n;
}

function order_item_totals(SQLite3 $db): array {
    $out=[]; $res=$db->query("SELECT vendor, order_id, COUNT(*) c, SUM(CASE WHEN vendor IN ('costco','walmart') AND source_amount IS NOT NULL THEN source_amount ELSE quantity*unit_price END) s FROM order_items WHERE skip=0 GROUP BY vendor, order_id");
    while ($r=$res->fetchArray(SQLITE3_ASSOC)) $out[$r['vendor'].'|'.$r['order_id']]=['count'=>(int)$r['c'],'sum'=>(float)$r['s']]; return $out;
}

$db = connect_app_db(); $message = null; $error = null; $exportLinks = [];
$isJsonPost = false;
$request = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) {
        $isJsonPost = true;
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (is_array($decoded)) $request = $decoded;
    }
}
$storedGnuCashPath = get_config($db, 'gnucash_path', '');
// Stored path is authoritative.  Ignore gnucash_path in GET URLs to prevent stale
// bookmarked/query-string values from reverting the selected GnuCash book.
$gnucashPath = $storedGnuCashPath;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('gnucash_path', $request)) {
    $postedPath = trim((string)($request['gnucash_path'] ?? ''));
    if ($postedPath !== '') $gnucashPath = $postedPath;
}
$vendorHint = strtolower((string)($_GET['vendor_hint'] ?? $request['vendor_hint'] ?? get_config($db, 'next_vendor_hint', 'auto')));
if (!in_array($vendorHint, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $vendorHint = 'auto';
$mode = strtolower((string)($_GET['mode'] ?? $request['mode'] ?? get_config($db, 'ui_mode', 'bills')));
if (!in_array($mode, ['instructions','config','import_data','review_bills','matching','amazon','costco','walmart','lowes','home_depot','tractor_supply','utility','bills','transactions','sanity','repair','ledger'], true)) $mode = 'instructions';
// v192 workflow aliases. Keep the mature internal engines, but present the UI as a normalized pipeline.
if ($mode === 'review_bills') $mode = 'bills';
set_config($db, 'ui_mode', $mode);
$vendorTabModes = ['amazon','costco','walmart','lowes','home_depot','tractor_supply'];
$currentVendorTab = in_array($mode, $vendorTabModes, true) ? $mode : '';
$vendorStep = strtolower((string)($_GET['vendor_step'] ?? $request['vendor_step'] ?? ''));
if ($vendorStep === '') {
    if ($currentVendorTab === 'lowes') $vendorStep = 'scrape';
    elseif ($currentVendorTab === 'amazon') $vendorStep = 'import';
    else $vendorStep = 'overview';
}
if (!in_array($vendorStep, ['overview','scrape','import','categorize','review','stored_value','payments','match_dry_run','match_apply','diagnostics'], true)) $vendorStep = 'overview';
$utilityStep = strtolower((string)($_GET['utility_step'] ?? $request['utility_step'] ?? 'overview'));
if (!in_array($utilityStep, ['overview','repair','ledger','sanity'], true)) $utilityStep = 'overview';
if (in_array($mode, ['repair','ledger','sanity'], true)) $utilityStep = $mode;
if (in_array($mode, ['bills','transactions'], true)) {
    $returnVendor = strtolower((string)($_GET['vendor_hint'] ?? $request['vendor_hint'] ?? get_config($db, 'next_vendor_hint', 'auto')));
    if (in_array($returnVendor, ['amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $currentVendorTab = $returnVendor;
}
if (isset($_GET['new_dataset_ready'])) {
    $remaining = count_staged_orders($db);
    if ($remaining === 0) {
        $message = 'Working dataset is clear. Choose the vendor/export type below and import the new export file.';
    }
    // If rows exist, do not show the old reset-failed diagnostic on normal import/review pages.
    // That URL flag can linger after a successful reset and becomes stale once new data is imported.
}

function respond_json(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (isset($_GET['path_saved'])) $message = 'Saved GnuCash path and removed stale URL parameters.';
$page = max(1, (int)($_GET['page'] ?? $request['page'] ?? 1));
$perPage = max(5, min(100, (int)($_GET['per_page'] ?? $request['per_page'] ?? 25)));
$filter = (string)($_GET['filter'] ?? $request['filter'] ?? 'all');
$search = trim((string)($_GET['search'] ?? $request['search'] ?? ''));
$dateSort = normalize_date_sort($_GET['date_sort'] ?? $request['date_sort'] ?? get_config($db, 'date_sort', 'desc'));
if (isset($_GET['date_sort']) || isset($request['date_sort'])) set_config($db, 'date_sort', $dateSort);
$showSkippedInAll = (string)($_GET['show_skipped'] ?? $request['show_skipped'] ?? get_config($db, 'show_skipped_in_all', '0')) === '1';
if (isset($_GET['show_skipped']) || isset($request['show_skipped'])) set_config($db, 'show_skipped_in_all', $showSkippedInAll ? '1' : '0');
$lastPostAction = ($_SERVER['REQUEST_METHOD'] === 'POST') ? strtolower((string)($request['action'] ?? '')) : '';
if (($request['action'] ?? '') === 'create_current_gnucash_download_copy') {
    $context = strtolower(trim((string)($request['download_context'] ?? 'changed-book')));
    if ($context === '') $context = 'changed-book';
    $contextKey = preg_replace('/[^a-z0-9_]+/', '_', $context);
    $safePrefix = preg_replace('/[^a-z0-9_.-]+/', '-', $context);
    if ($safePrefix === '') $safePrefix = 'changed-book';

    $source = realpath((string)$gnucashPath);
    $dataRoot = realpath(__DIR__ . '/data');

    if ($source === false || !is_file($source) || !is_readable($source)) {
        $error = 'Could not create changed-file download: selected uploaded GnuCash book is not readable.';
    } elseif ($dataRoot === false || !path_is_under($source, $dataRoot)) {
        $error = 'Could not create changed-file download: the selected book is not under the tool data/ directory. Upload/select the working copy in data/ first.';
    } else {
        $stamp = date('Ymd-His');
        $name = gnucash_download_basename($safePrefix . '-changed-file', $stamp);
        [$ok, $info] = copy_gnucash_book_for_download($source, $name);
        if ($ok) {
            set_config($db, 'last_changed_book_download_' . $contextKey, $name);
            $message = 'Created changed GnuCash file download copy: ' . $name . '.';
            $exportLinks[] = ['name' => $name, 'batch' => 'Changed uploaded GnuCash working file. Download and validate this modified copy in GnuCash.'];
        } else {
            $error = 'Could not create changed-file download: ' . $info;
        }
    }

    $mode = in_array((string)($request['mode'] ?? ''), ['transactions','lowes','bills','repair','ledger','sanity','matching'], true) ? (string)$request['mode'] : 'transactions';
    if (isset($request['payment_wizard_step']) && in_array((string)$request['payment_wizard_step'], ['start','missing','exceptions','ready','apply','credit'], true)) {
        set_config($db, 'payment_wizard_step', (string)$request['payment_wizard_step']);
    }
    set_config($db, 'ui_mode', $mode);
}

$lowesDryRunDetailHtml = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($request['action'] ?? '') === 'ajax_save') {
        try {
            save_review_edits($db, $request);
            respond_json(['ok'=>true, 'message'=>'Saved at ' . date('H:i:s')]);
        } catch (Throwable $e) {
            respond_json(['ok'=>false, 'message'=>'Save failed: ' . $e->getMessage()]);
        }
    }
    if (($request['action'] ?? '') === 'ajax_add_account') {
        [$ok, $msg] = add_extra_account($db, (string)($request['account_name'] ?? ''));
        respond_json(['ok'=>$ok, 'message'=>$msg, 'account'=>trim((string)($request['account_name'] ?? ''))]);
    }
if (($request['action'] ?? '') === 'save_vendor_ids') {
    // save_vendor_ids is accepted for compatibility with early v155 drafts.
    $request['default_var'] = [
        'DEFAULT_VENDOR_AMAZON' => (string)($request['vendor_id_amazon'] ?? default_config_value('DEFAULT_VENDOR_AMAZON', $db)),
        'DEFAULT_VENDOR_COSTCO' => (string)($request['vendor_id_costco'] ?? default_config_value('DEFAULT_VENDOR_COSTCO', $db)),
        'DEFAULT_VENDOR_WALMART' => (string)($request['vendor_id_walmart'] ?? default_config_value('DEFAULT_VENDOR_WALMART', $db)),
        'DEFAULT_VENDOR_LOWES' => (string)($request['vendor_id_lowes'] ?? default_config_value('DEFAULT_VENDOR_LOWES', $db)),
        'DEFAULT_VENDOR_HOME_DEPOT' => (string)($request['vendor_id_home_depot'] ?? default_config_value('DEFAULT_VENDOR_HOME_DEPOT', $db)),
        'DEFAULT_VENDOR_TRACTOR_SUPPLY' => (string)($request['vendor_id_tractor_supply'] ?? default_config_value('DEFAULT_VENDOR_TRACTOR_SUPPLY', $db)),
    ];

    save_default_config_values($db, $request);
    set_config($db, 'ui_mode', 'config');

    header('Location: ?mode=config&saved=vendor_ids#default-variables');
    exit;
}

if (($request['action'] ?? '') === 'save_default_variables') {
    save_default_config_values($db, $request);
    set_config($db, 'ui_mode', 'config');

    header('Location: ?mode=config&saved=default_variables#default-variables');
    exit;
}
    if (($request['action'] ?? '') === 'add_account') {
        [$ok, $msg] = add_extra_account($db, (string)($request['account_name'] ?? ''));
        if ($ok) $message = $msg; else $error = $msg;
    }
    if (($request['action'] ?? '') === 'soft_reset_new_import') {
        $nextVendor = strtolower((string)($request['next_vendor'] ?? 'auto'));
        if (!in_array($nextVendor, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $nextVendor = 'auto';
        try {
            if (trim((string)$gnucashPath) !== '') set_config($db, 'gnucash_path', (string)$gnucashPath);
            set_config($db, 'next_vendor_hint', $nextVendor);
            soft_reset_for_new_import_preserve_rules($db);
            header('Location: ' . clean_review_url((string)$gnucashPath, $nextVendor));
            exit;
        } catch (Throwable $e) {
            $error = 'Soft reset failed because SQLite is locked: ' . $e->getMessage() . '. Restart php8.5-fpm or use Hard reset review data.';
        }
    }
    if (($request['action'] ?? '') === 'reset_working_data') {
        $nextVendor = strtolower((string)($request['next_vendor'] ?? 'auto'));
        if (!in_array($nextVendor, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $nextVendor = 'auto';
        if (trim((string)$gnucashPath) !== '') set_config($db, 'gnucash_path', (string)$gnucashPath);
        $db = hard_reset_review_database_preserving_settings($db);
        set_config($db, 'next_vendor_hint', $nextVendor);
        header('Location: ' . clean_review_url((string)$gnucashPath, $nextVendor));
        exit;
    }
    if (($request['action'] ?? '') === 'new_dataset') {
        $nextVendor = strtolower((string)($request['next_vendor'] ?? 'auto'));
        if (!in_array($nextVendor, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $nextVendor = 'auto';
        if (trim((string)$gnucashPath) !== '') set_config($db, 'gnucash_path', (string)$gnucashPath);
        $db = hard_reset_review_database_preserving_settings($db);
        set_config($db, 'next_vendor_hint', $nextVendor);
        header('Location: ' . clean_review_url((string)$gnucashPath, $nextVendor));
        exit;
    }
    if (($request['action'] ?? '') === 'unskip_selected') {
        $n = unskip_selected_orders($db, (array)($request['unskip_order'] ?? []));
        $message = "Restored $n skipped order(s) to processing/exportable status. Use the Skipped filter to confirm, or Exportable to continue review.";
    }
    if (($request['action'] ?? '') === 'upload_gnucash' && isset($_FILES['gnucash_file']) && is_uploaded_file($_FILES['gnucash_file']['tmp_name'])) {
        $dest = __DIR__ . '/data/gnucash.sqlite';
        if (!move_uploaded_file($_FILES['gnucash_file']['tmp_name'], $dest)) {
            $error = 'Could not save uploaded GnuCash file to data/gnucash.sqlite. Check data/ directory permissions.';
        } else {
            chmod($dest, 0660);
            $gnucashPath = $dest;
            set_config($db, 'gnucash_path', $gnucashPath);
            set_config($db, 'gnucash_uploaded_copy', '1');
            set_config($db, 'gnucash_uploaded_original_name', (string)($_FILES['gnucash_file']['name'] ?? 'uploaded.gnucash'));
            $message = 'Uploaded GnuCash SQLite book copy for account browsing and safe web-run operations. The working server copy is stored as data/gnucash.sqlite; downloads produced after apply use a .gnucash extension.';
        }
    }
    if (($request['action'] ?? '') === 'set_gnucash_path') {
        $gnucashPath = trim((string)($request['gnucash_path'] ?? ''));
        set_config($db, 'gnucash_path', $gnucashPath);
        set_config($db, 'gnucash_backend', 'sqlite');
        set_config($db, 'gnucash_uploaded_copy', '0');
        $message = 'Saved GnuCash SQLite file path and selected SQLite backend. Because this was entered as a filesystem path, apply helpers still treat it as an explicit path rather than an uploaded working copy.';
    }
    if (($request['action'] ?? '') === 'set_gnucash_backend') {
        $backend = strtolower((string)($request['gnucash_backend'] ?? 'sqlite'));
        if (!in_array($backend, ['sqlite','pgsql','mysql'], true)) $backend = 'sqlite';
        set_config($db, 'gnucash_backend', $backend);
        foreach (['gnucash_db_host','gnucash_db_port','gnucash_db_name','gnucash_db_user','gnucash_db_pass'] as $k) set_config($db, $k, (string)($request[$k] ?? ''));
        $message = 'Saved GnuCash read-only backend settings for ' . strtoupper($backend) . '.';
    }
    if (($request['action'] ?? '') === 'save_payment_apply_match_window') {
    $before = trim((string)($request['payment_match_before_days'] ?? '0'));
    $after = trim((string)($request['payment_match_after_days'] ?? '14'));

    if ($before === '' || !preg_match('/^-?\d+$/', $before)) $before = '0';
    if ($after === '' || !preg_match('/^-?\d+$/', $after)) $after = '14';

    $before = (string)max(0, min(365, (int)$before));
    $after = (string)max(0, min(365, (int)$after));

    set_config($db, 'payment_apply_match_window_before_days', $before);
    set_config($db, 'payment_apply_match_window_after_days', $after);

    $message = 'Saved transaction match window adjustment: look for payments from ' . $before . ' day(s) before through ' . $after . ' day(s) after the vendor payment date.';
    $mode = 'transactions';
    $paymentWizardStep = 'apply';
    set_config($db, 'ui_mode', $mode);
}

if (($request['action'] ?? '') === 'reset_transaction_matching') {
        $resetVendor = strtolower((string)($request['payment_vendor'] ?? 'amazon'));
        if (!in_array($resetVendor, ['amazon','all'], true)) $resetVendor = 'amazon';
        try {
            $n = clear_transaction_matching_dataset($db, $resetVendor);
            $message = 'Cleared ' . $n . ' imported transaction/payment matching row(s). Payment method account mappings and exclude flags were preserved.';
            $mode = 'transactions';
            set_config($db, 'ui_mode', $mode);
        } catch (Throwable $e) {
            $error = 'Transaction matching reset failed: ' . $e->getMessage();
        }
    }
    if (($request['action'] ?? '') === 'refresh_payment_scan') {
        $validForAdjust = load_valid_account_set((string)$gnucashPath);
        $primeYoungSplitAfterRefresh = split_amazon_prime_young_adults_cashback_payments($db);
        $inferredAfterRefresh = infer_amazon_missing_stored_value_payments($db);
        $adjustedAfterRefresh = reconcile_amazon_order_promotions($db, $validForAdjust);
        $amazonDiscountCats = apply_amazon_discount_categories($db, $validForAdjust);
        set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
        $message = 'Rebuilt/refreshed the read-only payment scan against the currently loaded GnuCash file.' . ($primeYoungSplitAfterRefresh ? ' Cleaned/restored '.$primeYoungSplitAfterRefresh.' Prime for Young Adults display-derived payment row(s).' : '') . ($inferredAfterRefresh ? ' Updated '.$inferredAfterRefresh.' Amazon stored-value amount(s) from transactions.' : '') . ($adjustedAfterRefresh ? ' Rebuilt '.$adjustedAfterRefresh.' Amazon reconciliation/discount line(s).' : '') . ' Export or reload the wizard reports again to see updated statuses.';
        $requestedModeForRefresh = (string)($request['mode'] ?? '');
        $mode = in_array($requestedModeForRefresh, ['sanity','repair','ledger'], true) ? $requestedModeForRefresh : 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'rebuild_bill_import_against_gnucash') {
        $res = rebuild_bill_import_against_gnucash($db, (string)$gnucashPath);
        $message = (string)$res['message'];
        $page = 1;
        $filter = 'all';
        $search = '';
        $mode = 'bills';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'save_payment_allocation_override') {
        $ovVendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon')));
        $ovOrder = trim((string)($request['order_id'] ?? ''));
        $ovDate = normalize_import_date((string)($request['payment_date'] ?? ''));
        $ovMethod = trim((string)($request['payment_method'] ?? ''));
        $ovAmount = abs(money_to_float((string)($request['override_amount'] ?? '0')));
        $ovNote = trim((string)($request['override_note'] ?? 'Manual allocation override from payment exception handler.'));
        if ($ovOrder === '' || $ovDate === '' || $ovMethod === '' || $ovAmount <= 0.005) {
            $error = 'Could not save payment allocation override: order, payment date, method, and positive amount are required.';
        } else {
            $stmt = $db->prepare('INSERT INTO payment_allocation_overrides (vendor,order_id,payment_date,payment_method,amount,note,updated_at) VALUES (:vendor,:order_id,:pdate,:method,:amount,:note,CURRENT_TIMESTAMP) ON CONFLICT(vendor,order_id,payment_date,payment_method) DO UPDATE SET amount=excluded.amount, note=excluded.note, updated_at=CURRENT_TIMESTAMP');
            $stmt->bindValue(':vendor', $ovVendor, SQLITE3_TEXT);
            $stmt->bindValue(':order_id', $ovOrder, SQLITE3_TEXT);
            $stmt->bindValue(':pdate', $ovDate, SQLITE3_TEXT);
            $stmt->bindValue(':method', $ovMethod, SQLITE3_TEXT);
            $stmt->bindValue(':amount', $ovAmount, SQLITE3_FLOAT);
            $stmt->bindValue(':note', $ovNote, SQLITE3_TEXT);
            $stmt->execute();
            set_config($db, 'payment_scan_refresh_token', (string)time());
            $message = 'Saved payment allocation override for ' . $ovOrder . ' / ' . $ovMethod . ' on ' . $ovDate . ': $' . fmt_money($ovAmount) . '. Rebuild/export the payment plan again.';
        }
    }
    if (($request['action'] ?? '') === 'delete_payment_allocation_override') {
        $ovVendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon')));
        $ovOrder = trim((string)($request['order_id'] ?? ''));
        $ovDate = normalize_import_date((string)($request['payment_date'] ?? ''));
        $ovMethod = trim((string)($request['payment_method'] ?? ''));
        $stmt = $db->prepare('DELETE FROM payment_allocation_overrides WHERE vendor=:vendor AND order_id=:order_id AND payment_date=:pdate AND payment_method=:method');
        $stmt->bindValue(':vendor', $ovVendor, SQLITE3_TEXT);
        $stmt->bindValue(':order_id', $ovOrder, SQLITE3_TEXT);
        $stmt->bindValue(':pdate', $ovDate, SQLITE3_TEXT);
        $stmt->bindValue(':method', $ovMethod, SQLITE3_TEXT);
        $stmt->execute();
        set_config($db, 'payment_scan_refresh_token', (string)time());
        $message = 'Deleted payment allocation override for ' . $ovOrder . ' / ' . $ovMethod . '.';
    }
    if (($request['action'] ?? '') === 'mark_payment_verified') {
        $pid = trim((string)($request['payment_id'] ?? ''));
        $vendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        if ($pid !== '') {
            $stmt = $db->prepare('INSERT INTO payment_manual_verifications (vendor,payment_id,verified_at,note) VALUES (:vendor,:pid,:ts,:note) ON CONFLICT(vendor,payment_id) DO UPDATE SET verified_at=excluded.verified_at, note=excluded.note');
            $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $stmt->bindValue(':pid',$pid,SQLITE3_TEXT); $stmt->bindValue(':ts',now_sql(),SQLITE3_TEXT); $stmt->bindValue(':note','User marked this payment row verified against GnuCash.',SQLITE3_TEXT); $stmt->execute();
            $message = 'Marked payment row verified.';
            $mode = 'transactions'; set_config($db, 'ui_mode', $mode);
        }
    }
    if (($request['action'] ?? '') === 'unmark_payment_verified') {
        $pid = trim((string)($request['payment_id'] ?? ''));
        $vendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        if ($pid !== '') {
            $stmt = $db->prepare('DELETE FROM payment_manual_verifications WHERE vendor=:vendor AND payment_id=:pid');
            $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $stmt->bindValue(':pid',$pid,SQLITE3_TEXT); $stmt->execute();
            $message = 'Removed manual verification marker from payment row.';
            $mode = 'transactions'; set_config($db, 'ui_mode', $mode);
        }
    }
    if (($request['action'] ?? '') === 'save_lowes_module_config') {
        $daysRaw = trim((string)($request['lowes_payment_match_date_window_days'] ?? default_config_value('DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS', $db)));
        $days = preg_match('/^-?\d+$/', $daysRaw) ? (int)$daysRaw : lowes_payment_match_date_window_days($db);
        $days = max(0, min(365, $days));
        set_config($db, default_config_key('DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS'), (string)$days);
        $minRaw = trim((string)($request['lowes_partial_return_manual_stage_min_amount'] ?? default_config_value('DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT', $db)));
        $minAmt = is_numeric($minRaw) ? round((float)$minRaw, 2) : lowes_partial_return_manual_stage_min_amount($db);
        $minAmt = max(0.0, min(100000.0, $minAmt));
        set_config($db, default_config_key('DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT'), fmt_money($minAmt));
        default_config_cache_reset();
        set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
        $message = "Saved Lowe's module settings. Step 5 posting-lag tolerance is now " . $days . ' day(s); high-value partial-return manual-stage minimum is $' . fmt_money($minAmt) . '.';
        $returnMode = strtolower(trim((string)($request['return_mode'] ?? $request['mode'] ?? 'config')));
        $returnStep = strtolower(trim((string)($request['return_vendor_step'] ?? $request['vendor_step'] ?? '')));
        if (!in_array($returnMode, ['config','lowes','bills'], true)) $returnMode = 'config';
        $mode = $returnMode;
        if ($mode === 'lowes' && in_array($returnStep, ['scrape','import','review','stored_value','match_dry_run','match_apply','diagnostics'], true)) $vendorStep = $returnStep;
        if ($mode === 'bills') { $vendorHint = 'lowes'; if ($returnStep !== '') $vendorStep = $returnStep; }
        set_config($db, 'ui_mode', $mode);
    }

    if (($request['action'] ?? '') === 'save_transaction_match_window') {
        $start = normalize_import_date((string)($request['payment_match_start_date'] ?? ''));
        $end = normalize_import_date((string)($request['payment_match_end_date'] ?? ''));
        if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) { $tmp = $start; $start = $end; $end = $tmp; }
        set_config($db, 'payment_match_start_date', $start);
        set_config($db, 'payment_match_end_date', $end);
        set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
        $message = 'Saved transaction matching date window: ' . ($start !== '' ? $start : 'open') . ' through ' . ($end !== '' ? $end : 'open') . '. Existing imported rows are retained, but reports/plans now use this window; soft-reset and re-import to physically drop outside-window rows.';
        $returnMode = strtolower(trim((string)($request['return_mode'] ?? $request['mode'] ?? 'transactions')));
        $returnStep = strtolower(trim((string)($request['return_vendor_step'] ?? $request['vendor_step'] ?? '')));
        if (in_array($returnMode, ['lowes','bills'], true)) {
            $mode = $returnMode;
            if ($mode === 'lowes' && in_array($returnStep, ['scrape','import','review','stored_value','match_dry_run','match_apply','diagnostics'], true)) $vendorStep = $returnStep;
            if ($mode === 'bills') { $vendorHint = 'lowes'; if ($returnStep !== '') $vendorStep = $returnStep; }
        } else {
            $mode = 'transactions';
        }
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'exclude_payment_target') {
        $vendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        $target = trim((string)($request['target_invoice_id'] ?? $request['target_id'] ?? $request['order_id'] ?? ''));
        $reason = trim((string)($request['exclude_reason'] ?? 'Manually handled / out of transaction matching scope.'));
        if ($target !== '' && save_payment_target_exclusion($db, $vendor, $target, $reason)) {
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            $message = 'Excluded target from transaction matching: ' . $target;
        } else {
            $error = 'Could not exclude target; no target ID was supplied.';
        }
        if ($vendor === 'lowes' || strtolower(trim((string)($request['mode'] ?? ''))) === 'lowes') {
            $mode = 'lowes';
            $vendorStep = trim((string)($request['vendor_step'] ?? 'match_dry_run')) ?: 'match_dry_run';
        } else {
            $mode = 'transactions';
        }
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'unexclude_payment_target') {
        $vendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        $target = trim((string)($request['target_invoice_id'] ?? $request['target_id'] ?? $request['order_id'] ?? ''));
        if ($target !== '') {
            $stmt = $db->prepare('DELETE FROM payment_target_exclusions WHERE vendor=:vendor AND target_id=:target');
            $stmt->bindValue(':vendor',$vendor,SQLITE3_TEXT); $stmt->bindValue(':target',$target,SQLITE3_TEXT); $stmt->execute();
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            $message = 'Removed transaction-matching exclusion for target: ' . $target;
        } else {
            $error = 'Could not remove exclusion; no target ID was supplied.';
        }
        if ($vendor === 'lowes' || strtolower(trim((string)($request['mode'] ?? ''))) === 'lowes') {
            $mode = 'lowes';
            $vendorStep = trim((string)($request['vendor_step'] ?? 'match_dry_run')) ?: 'match_dry_run';
        } else {
            $mode = 'transactions';
        }
        set_config($db, 'ui_mode', $mode);
    }

    if (($request['action'] ?? '') === 'resolve_prime_young_card_only') {
        $oid = trim((string)($request['order_id'] ?? $request['target_invoice_id'] ?? ''));
        if ($oid !== '') {
            [$n, $msg] = restore_amazon_prime_young_card_only_for_order($db, $oid);
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            $message = $msg . ($n === 0 ? ' No synthetic rows needed removal.' : '');
        } else {
            $error = 'Could not resolve Prime Young mismatch; no order ID was supplied.';
        }
        $mode = 'transactions';
        $paymentWizardStep = 'exceptions';
        set_config($db, 'ui_mode', $mode);
    }

    if (($request['action'] ?? '') === 'save_stored_value_import_settings') {
        set_config($db, 'stored_value_offset_account', trim((string)($request['stored_value_offset_account'] ?? default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT'))));
        $message = 'Saved stored-value transaction CSV settings.';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'save_vendor_ledger_diff_settings') {
        set_config($db, 'suppress_zero_vendor_ledger_targets', !empty($request['suppress_zero_vendor_ledger_targets']) ? '1' : '0');
        if (!empty($request['ledger_vendor'])) set_config($db, 'ledger_audit_vendor', strtolower(trim((string)$request['ledger_vendor'])));
        set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
        $message = 'Saved vendor ledger audit settings.';
        $mode = 'ledger';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'import_amazon_transactions' && isset($_FILES['amazon_transactions'])) {
        $uploads = uploaded_file_entries('amazon_transactions');
        $totalImported = 0; $successfulFiles = 0; $fileMessages = []; $fileErrors = [];
        foreach ($uploads as $i => $upload) {
            $label = trim((string)($upload['name'] ?? '')) ?: ('file ' . ($i + 1));
            $errCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string)($upload['tmp_name'] ?? '');
            if ($errCode !== UPLOAD_ERR_OK) { $fileErrors[] = $label . ': ' . upload_error_message($errCode); continue; }
            if ($tmpName === '' || !is_uploaded_file($tmpName)) { $fileErrors[] = $label . ': uploaded temporary file was not available to PHP.'; continue; }
            $sp = payment_import_savepoint_name($i);
            $db->exec('SAVEPOINT ' . $sp);
            try {
                [$n, $msg] = import_amazon_transaction_history_csv($db, $tmpName);
                if (payment_import_message_is_success((string)$msg)) {
                    $db->exec('RELEASE SAVEPOINT ' . $sp);
                    $totalImported += (int)$n; $successfulFiles++;
                    $fileMessages[] = $label . ': ' . $msg;
                } else {
                    $db->exec('ROLLBACK TO SAVEPOINT ' . $sp); $db->exec('RELEASE SAVEPOINT ' . $sp);
                    $fileErrors[] = $label . ': ' . $msg;
                }
            } catch (Throwable $e) {
                try { $db->exec('ROLLBACK TO SAVEPOINT ' . $sp); $db->exec('RELEASE SAVEPOINT ' . $sp); } catch (Throwable $ignored) {}
                $fileErrors[] = $label . ': ' . $e->getMessage();
            }
        }
        if ($successfulFiles > 0) {
            $skippedByMethod = apply_payment_method_invoice_handling($db, 'amazon');
            $validForAdjust = load_valid_account_set((string)$gnucashPath);
            $primeYoungSplitAfterPayments = split_amazon_prime_young_adults_cashback_payments($db);
            $inferredAfterPayments = infer_amazon_missing_stored_value_payments($db);
            $adjustedAfterPayments = reconcile_amazon_order_promotions($db, $validForAdjust);
            $amazonDiscountCats = apply_amazon_discount_categories($db, $validForAdjust);
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            $message = 'Imported/updated ' . $totalImported . ' Amazon transaction payment row(s) from ' . $successfulFiles . ' file(s). ' . implode(' ', $fileMessages)
                . ($primeYoungSplitAfterPayments ? ' Cleaned/restored '.$primeYoungSplitAfterPayments.' Prime for Young Adults display-derived payment row(s).' : '')
                . ($inferredAfterPayments ? ' Updated '.$inferredAfterPayments.' Amazon gift-card/rewards amount(s) from transaction history.' : '')
                . ($adjustedAfterPayments ? ' Rebuilt '.$adjustedAfterPayments.' Amazon discount/reconciliation line(s).' : '')
                . ($skippedByMethod ? ' Marked '.$skippedByMethod.' staged invoice(s) skipped because payment methods are excluded/out-of-book.' : '')
                . (!empty($fileErrors) ? ' Some file(s) were skipped: ' . implode(' ', $fileErrors) : '');
        } else {
            $error = !empty($fileErrors) ? 'No Amazon transaction files were imported. ' . implode(' ', $fileErrors) : 'No Amazon transaction CSV files were uploaded.';
        }
        $mode = 'transactions';
        $paymentWizardStep = 'start';
        set_config($db, 'payment_wizard_step', $paymentWizardStep);
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'save_payment_mappings') {
        $n = save_payment_method_mappings($db, $request);
        $skippedByMethod = apply_payment_method_invoice_handling($db, 'amazon') + apply_payment_method_invoice_handling($db, 'lowes') + apply_payment_method_invoice_handling($db, 'walmart') + apply_payment_method_invoice_handling($db, 'costco');
        $message = 'Saved ' . $n . ' payment method account mapping(s).' . ($skippedByMethod ? ' Marked '.$skippedByMethod.' staged invoice(s) skipped because payment methods are excluded/out-of-book.' : '');
        // Preserve the tab/page context that submitted the form.  The Lowe's
        // payment-mapping panel can appear on the Invoices/Bills tab, while the
        // Amazon payment workflow uses the Transactions/Payments tab.  Older
        // builds forced every save back to transactions, which made the Bills
        // page unexpectedly jump tabs after saving.
        $returnMode = strtolower((string)($request['return_mode'] ?? $request['mode'] ?? $mode ?? 'transactions'));
        if (!in_array($returnMode, ['config','bills','transactions','sanity','repair','ledger','lowes'], true)) $returnMode = 'transactions';
        $mode = $returnMode;
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_payment_matches') {
        $name = 'amazon-payment-matches-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_payment_match_report($db, $path, 'amazon');
        $exportLinks[] = ['name'=>$name, 'batch'=>'payments', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created Amazon payment match report with ' . $n . ' row(s).';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_payment_application_plan') {
        $name = 'amazon-payment-application-plan-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_payment_application_plan($db, $path, 'amazon', $gnucashPath);
        $exportLinks[] = ['name'=>$name, 'batch'=>'payment-plan', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created Amazon payment application plan with ' . $n . ' row(s).';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_payment_exception_report') {
        $name = 'amazon-payment-exception-report-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_payment_exception_report($db, $path, 'amazon', $gnucashPath);
        $exportLinks[] = ['name'=>$name, 'batch'=>'payment-exceptions', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created Amazon payment exception report with ' . $n . ' row(s).';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_payment_excluded_invoice_report') {
        $name = 'amazon-excluded-out-of-book-invoice-report-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_payment_excluded_invoice_report($db, $path, 'amazon', $gnucashPath);
        $exportLinks[] = ['name'=>$name, 'batch'=>'excluded-invoices', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created Amazon excluded/out-of-book invoice report with ' . $n . ' row(s).';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_out_of_book_bill_csv') {
        $vendorForExport = strtolower(trim((string)($request['out_of_book_vendor'] ?? 'lowes'))) ?: 'lowes';
        $accountPrefix = trim((string)($request['account_prefix'] ?? get_config($db, 'account_export_prefix', '')));
        $postInvoices = isset($request['post_invoices']) && (string)$request['post_invoices'] === '1';
        $postingAccount = trim((string)($request['posting_account'] ?? get_config($db, 'posting_account', default_config_value('DEFAULT_AP_ACCOUNT'))));
        if ($postingAccount === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT');
        $postDateFormat = (string)($request['post_date_format'] ?? get_config($db, 'post_date_format', 'mdy_slash'));
        if (!in_array($postDateFormat, ['mdy_slash','dmy_slash','iso'], true)) $postDateFormat = 'mdy_slash';
        $name = $vendorForExport . '-out-of-book-bill-import-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        [$rows, $targets] = export_out_of_book_bill_csv($db, $path, $vendorForExport, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat);
        if ($rows > 0) {
            $exportLinks[] = ['name'=>$name, 'batch'=>'out-of-book-bills', 'count'=>$targets, 'rows'=>$rows, 'first'=>'', 'last'=>''];
            $message = 'Created ' . ucfirst($vendorForExport) . ' out-of-book bill CSV for ' . $targets . ' invoice/credit document(s), ' . $rows . ' CSV row(s). Import this CSV into the other entity book, then keep those payment methods marked excluded in this book.';
        } else {
            $error = 'No ' . ucfirst($vendorForExport) . ' orders currently have payment methods marked Exclude invoices / excluded payment method. Save payment method mappings first, then export again.';
        }
        $mode = 'bills';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_stored_value_activity') {
        $exportVendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        if (!in_array($exportVendor, ['amazon','lowes'], true)) $exportVendor = 'amazon';
        $vendorLabel = stored_value_vendor_label($exportVendor);
        $safeVendor = preg_replace('/[^a-z0-9]+/', '-', $exportVendor);
        $name = $safeVendor . '-stored-value-account-activity-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_stored_value_account_activity($db, $path, $exportVendor);
        $exportLinks[] = ['name'=>$name, 'batch'=>'stored-value', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created ' . $vendorLabel . ' stored-value account activity report with ' . $n . ' row(s).';
        $mode = ($exportVendor === 'lowes' && (string)($request['mode'] ?? '') === 'lowes') ? 'lowes' : 'transactions';
        if ($mode === 'lowes') $vendorStep = 'stored_value';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_stored_value_gnucash_csv') {
        $exportVendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        if (!in_array($exportVendor, ['amazon','lowes'], true)) $exportVendor = 'amazon';
        $vendorLabel = stored_value_vendor_label($exportVendor);
        $stamp = date('Ymd-His');
        $offset = get_config($db, 'stored_value_offset_account', default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT')) ?: default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT');
        if ($exportVendor === 'lowes') {
            $exports = [
                ['kind'=>'mylowes_money', 'name'=>'lowes-mylowes-money-gnucash-import-' . $stamp . '-' . APP_VERSION . '.csv', 'label'=>"Lowe's My Lowe's Money"],
            ];
        } else {
            $exports = [
                ['kind'=>'gift_card', 'name'=>'amazon-gift-card-gnucash-import-' . $stamp . '-' . APP_VERSION . '.csv', 'label'=>'Amazon Gift Card'],
                ['kind'=>'rewards', 'name'=>'amazon-rewards-cashback-gnucash-import-' . $stamp . '-' . APP_VERSION . '.csv', 'label'=>'Amazon Visa points / cash-back rewards'],
                ['kind'=>'prime_young_cashback', 'name'=>'amazon-prime-young-adults-cashback-gnucash-import-' . $stamp . '-' . APP_VERSION . '.csv', 'label'=>'Amazon Prime for Young Adults cash back'],
            ];
        }
        $totalRows = 0; $made = 0; $parts = [];
        foreach($exports as $ex){
            $path = __DIR__ . '/exports/' . $ex['name'];
            $n = export_stored_value_gnucash_transaction_csv($db, $path, $exportVendor, $offset, $ex['kind']);
            if($n > 0){
                $exportLinks[] = ['name'=>$ex['name'], 'batch'=>$ex['kind'].'-gnucash', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
                $parts[] = $ex['label'] . ': ' . $n . ' row(s)';
                $totalRows += $n; $made++;
            } else {
                @unlink($path);
            }
        }
        $message = $made
            ? 'Created separate ' . $vendorLabel . ' stored-value GnuCash transaction CSVs: ' . implode('; ', $parts) . '. Import each file into its matching GnuCash account; GnuCash imports one register/account file at a time.'
            : 'No ' . $vendorLabel . ' stored-value rows were available for GnuCash transaction CSV export.';
        $mode = ($exportVendor === 'lowes' && (string)($request['mode'] ?? '') === 'lowes') ? 'lowes' : 'transactions';
        if ($mode === 'lowes') $vendorStep = 'stored_value';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_payment_method_transactions') {
    $exportVendor = strtolower(trim((string)($request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
    if (!in_array($exportVendor, ['amazon','lowes','walmart','costco','home_depot','tractor_supply'], true)) $exportVendor = 'amazon';
    $methodKey = trim((string)($request['payment_method_key'] ?? ''));
    $methodName = trim((string)($request['payment_method_name'] ?? $methodKey));
    $accountFullname = trim((string)($request['payment_account'] ?? ''));
    $offset = get_config($db, 'stored_value_offset_account', default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT')) ?: default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT');
    if ($methodKey === '') {
        $error = 'No payment method key was supplied for the payment-transaction export.';
    } elseif ($accountFullname === '') {
        $error = 'No mapped GnuCash account was supplied for ' . $methodName . '. Save the payment-method account mapping first.';
    } else {
        $safeVendor = preg_replace('/[^a-z0-9]+/', '-', $exportVendor);
        $safeMethod = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($methodName ?: $methodKey)), '-');
        if ($safeMethod === '') $safeMethod = 'payment-method';
        $safeAcct = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($accountFullname)), '-');
        if ($safeAcct === '') $safeAcct = 'account';
        $name = $safeVendor . '-' . $safeMethod . '-' . $safeAcct . '-payment-transactions-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_payment_method_gnucash_transaction_csv($db, $path, $exportVendor, $methodKey, $accountFullname, $offset);
        if ($n > 0) {
            $exportLinks[] = ['name'=>$name, 'batch'=>'payment-method-transactions', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
            $message = 'Created ' . stored_value_vendor_label($exportVendor) . ' payment transaction CSV for ' . $methodName . ' into account ' . $accountFullname . ' with ' . $n . ' row(s). Import this file into the matching GnuCash account/register; GnuCash imports one account at a time.';
        } else {
            $error = 'No payment rows were available for ' . $methodName . ' / ' . $accountFullname . '. Verify that transaction CSVs have been imported and the method is mapped.';
        }
    }
    $mode = ($exportVendor === 'lowes' && (string)($request['mode'] ?? '') === 'lowes') ? 'lowes' : 'transactions';
    if ($mode === 'lowes') $vendorStep = 'stored_value';
    set_config($db, 'ui_mode', $mode);
}

if (($request['action'] ?? '') === 'export_vendor_ledger_diff_report') {
        $ledgerVendorForExport = strtolower(trim((string)($request['ledger_vendor'] ?? $request['payment_vendor'] ?? 'amazon'))) ?: 'amazon';
        set_config($db, 'ledger_audit_vendor', $ledgerVendorForExport);
        $name = $ledgerVendorForExport . '-vendor-ledger-audit-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_vendor_ledger_diff_report($db, $path, $ledgerVendorForExport, (string)$gnucashPath, $dateSort);
        $exportLinks[] = ['name'=>$name, 'batch'=>'vendor-ledger-audit', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created ' . ucfirst($ledgerVendorForExport) . ' vendor ledger audit report with ' . $n . ' row(s).';
        $mode = 'ledger';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_invoice_sanity_report') {
        $name = 'amazon-invoice-sanity-check-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_invoice_sanity_report($db, $path, 'amazon', (string)$gnucashPath, $dateSort);
        $exportLinks[] = ['name'=>$name, 'batch'=>'invoice-sanity', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $message = 'Created Amazon invoice sanity-check report with ' . $n . ' row(s).';
        $requestedModeForReport = (string)($request['mode'] ?? 'sanity');
        $mode = in_array($requestedModeForReport, ['sanity','repair'], true) ? $requestedModeForReport : 'sanity';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_missing_credit_memo_bill_csv') {
        $fromDate = normalize_import_date((string)($request['repair_from_date'] ?? repair_default_from_date($db))) ?: repair_default_from_date($db);
        $toDate = normalize_import_date((string)($request['repair_to_date'] ?? ''));
        set_config($db, 'repair_from_date', $fromDate); set_config($db, 'repair_to_date', $toDate);
        $rows = missing_credit_memo_wizard_rows($db, 'amazon', (string)$gnucashPath);
        $filteredRows = [];
        $blocked = 0;
        foreach ($rows as $r) {
            $od = normalize_import_date((string)($r['order_date'] ?? ''));
            if ($fromDate !== '' && $od !== '' && $od < $fromDate) continue;
            if ($toDate !== '' && $od !== '' && $od > $toDate) continue;
            $filteredRows[] = $r;
        }
        $targets = missing_credit_memo_stage_targets($db, 'amazon', (string)$gnucashPath, $filteredRows);
        $stagedKeys = [];
        foreach ($targets as $t) $stagedKeys[(string)$t['target_invoice_id']] = true;
        foreach ($filteredRows as $r) {
            $target = (string)($r['target_invoice_id'] ?? $r['target_id'] ?? '');
            if ($target !== '' && !isset($stagedKeys[$target])) $blocked++;
        }
        $validAccounts = load_valid_account_set((string)$gnucashPath);
        $badAccounts = $targets ? invalid_account_review_rows(account_review_rows_for_stage_targets($db, $targets), $validAccounts) : [];
        $name = 'amazon-missing-credit-memos-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        if (!empty($badAccounts)) {
            $error = 'Missing CREDIT memo export blocked because ' . count($badAccounts) . ' line(s) still have blank, placeholder, or non-existent expense accounts. Use the Missing CREDIT memo category review table below; the linked tool-review rows show the exact invoices to review before exporting.';
            @unlink($path);
            set_config($db, 'last_missing_credit_invalid_accounts', json_encode($badAccounts));
        } else {
            $n = $targets ? export_gnucash_bill_csv_for_targets($db, $path, $targets, false, '', true, default_config_value('DEFAULT_AP_ACCOUNT'), 'mdy_slash') : 0;
            if ($n > 0) {
                $exportLinks[] = ['name'=>$name, 'batch'=>'missing-credit-memos', 'count'=>count($targets), 'rows'=>$n, 'first'=>'', 'last'=>''];
                $message = 'Created missing Amazon CREDIT memo import CSV for ' . count($targets) . ' target(s), ' . $n . ' CSV row(s). Import this into GnuCash with Comma separated with quotes, then rebuild/refresh the invoice scan.' . ($blocked ? ' ' . $blocked . ' target(s) were blocked because they were not staged/exportable.' : '');
                set_config($db, 'last_missing_credit_invalid_accounts', '');
            } else {
                $error = 'No missing staged/exportable Amazon CREDIT memos were available to export for this date range. Rebuild the scan and confirm the ORDERID-CREDIT rows are staged, reviewed, categorized, and not skipped.';
            }
        }
        $mode = 'repair';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_amazon_invoice_repair_plan') {
        $ids = !empty($request['repair_ignore_order_filter']) ? [] : parse_order_id_filter_from_request($request);
        $fromDate = normalize_import_date((string)($request['repair_from_date'] ?? repair_default_from_date($db))) ?: repair_default_from_date($db);
        $toDate = normalize_import_date((string)($request['repair_to_date'] ?? ''));
        set_config($db, 'repair_from_date', $fromDate); set_config($db, 'repair_to_date', $toDate);
        $includePaid = !empty($request['repair_include_paid']);
        $name = 'amazon-invoice-repair-plan-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $rows = amazon_invoice_repair_plan_rows($db, (string)$gnucashPath, $fromDate, $toDate, $ids, $includePaid, $dateSort);
        $n = write_amazon_invoice_repair_plan_csv($rows, $path);
        set_config($db, 'last_invoice_repair_plan_name', $name); set_config($db, 'last_invoice_repair_plan_count', (string)$n); set_config($db, 'last_invoice_repair_plan_created', now_sql());
        // A newly generated plan invalidates any earlier dry-run/apply gate. This matters
        // when the new plan is header-only / zero rows.
        set_config($db, 'last_invoice_repair_dryrun_name', '');
        set_config($db, 'last_invoice_repair_dryrun_count', '0');
        set_config($db, 'last_invoice_repair_dryrun_blockers', '0');
        set_config($db, 'last_invoice_repair_dryrun_created', '');
        $exportLinks[] = ['name'=>$name, 'batch'=>'invoice-repair-plan', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        $safe = 0; foreach($rows as $rr) if ((string)($rr['safe_for_auto_repair'] ?? '') === 'yes') $safe++;
        if ($n === 0) {
            $message = 'Step 2 complete: no Amazon invoice repair rows were generated. The CSV contains only the header row, so there is nothing to dry-run or apply. Next: refresh the invoice sanity check and review the missing invoice / missing CREDIT memo panels; proceed to payment matching only after those are clean.';
        } else {
            $message = 'Step 2 complete: created Amazon invoice repair plan with ' . $n . ' row(s); ' . $safe . ' row(s) are marked safe for automated repair. ' . ($ids ? 'Filtered to ' . count($ids) . ' requested order ID(s). ' : '') . 'Next: run Step 3 dry run.';
        }
        $mode = 'repair';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'run_amazon_invoice_repair_dryrun') {
        $ids = !empty($request['repair_ignore_order_filter']) ? [] : parse_order_id_filter_from_request($request);
        $fromDate = normalize_import_date((string)($request['repair_from_date'] ?? repair_default_from_date($db))) ?: repair_default_from_date($db);
        $toDate = normalize_import_date((string)($request['repair_to_date'] ?? ''));
        set_config($db, 'repair_from_date', $fromDate); set_config($db, 'repair_to_date', $toDate);
        $includePaid = !empty($request['repair_include_paid']);
        $name = 'amazon-invoice-repair-dryrun-' . date('Ymd-His') . '-' . APP_VERSION . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $rows = amazon_invoice_repair_dryrun_rows($db, (string)$gnucashPath, $fromDate, $toDate, $ids, $includePaid, $dateSort);
        $n = write_amazon_invoice_repair_dryrun_csv($rows, $path);
        $blockers = repair_blocker_count($rows);
        set_config($db, 'last_invoice_repair_dryrun_name', $name); set_config($db, 'last_invoice_repair_dryrun_count', (string)$n); set_config($db, 'last_invoice_repair_dryrun_blockers', (string)$blockers); set_config($db, 'last_invoice_repair_dryrun_created', now_sql());
        $exportLinks[] = ['name'=>$name, 'batch'=>'invoice-repair-dryrun', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        if ($n === 0) {
            $message = 'Step 3 dry run complete: 0 row(s). There are no invoice repair rows to apply. Refresh the invoice sanity check and review missing invoice / missing CREDIT memo panels instead.';
        } else {
            $message = 'Step 3 dry run complete: ' . $n . ' row(s), ' . $blockers . ' blocker/error row(s). ' . ($blockers === 0 ? 'DRY RUN CLEAN: ready to proceed to Step 4 apply against the copied test book.' : 'Resolve blockers or narrow the order filter, then rerun dry run before apply.');
        }
        $mode = 'repair';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'run_amazon_invoice_repair_apply') {
        $ids = !empty($request['repair_ignore_order_filter']) ? [] : parse_order_id_filter_from_request($request);
        $fromDate = normalize_import_date((string)($request['repair_from_date'] ?? repair_default_from_date($db))) ?: repair_default_from_date($db);
        $toDate = normalize_import_date((string)($request['repair_to_date'] ?? ''));
        set_config($db, 'repair_from_date', $fromDate); set_config($db, 'repair_to_date', $toDate);
        $includePaid = false; // paid invoices must not be modified by this workflow
        $confirm = trim((string)($request['repair_apply_confirm'] ?? ''));
        if ($confirm !== 'APPLY REPAIR TO COPY') {
            $error = 'Apply blocked. Type APPLY REPAIR TO COPY exactly to confirm. This action modifies the selected copied GnuCash book.';
            $mode = 'repair'; set_config($db, 'ui_mode', $mode);
        } else {
            $stamp = date('Ymd-His');
            $planName = 'amazon-invoice-repair-apply-plan-' . $stamp . '.csv';
            $planPath = __DIR__ . '/exports/' . $planName;
            $planRows = export_amazon_invoice_repair_plan($db, $planPath, (string)$gnucashPath, $fromDate, $toDate, $ids, $includePaid, $dateSort);
            $outName = 'amazon-invoice-repair-apply-results-' . $stamp . '.csv';
            $outPath = __DIR__ . '/exports/' . $outName;
            $logName = 'amazon-invoice-repair-apply-log-' . $stamp . '.txt';
            $logPath = __DIR__ . '/exports/' . $logName;
            [$code, $text] = run_amazon_invoice_repair_apply_script((string)$gnucashPath, $planPath, $outPath, $fromDate, $toDate, true, $logPath);
            $consoleText = "Command/log: " . $logName . "\nBook: " . (string)$gnucashPath . "\nPlan: " . $planName . "\nResults: " . $outName . "\nExit code: " . $code . "\n\n" . $text;
            set_config($db, 'last_repair_console', $consoleText);
            set_config($db, 'last_repair_console_title', 'Last Amazon invoice repair apply run — ' . $stamp);
            $exportLinks[] = ['name'=>$planName, 'batch'=>'invoice-repair-apply-plan', 'count'=>$planRows, 'rows'=>$planRows, 'first'=>'', 'last'=>''];
            if (is_file($outPath)) $exportLinks[] = ['name'=>$outName, 'batch'=>'invoice-repair-apply-results', 'count'=>0, 'rows'=>0, 'first'=>'', 'last'=>''];
            if (is_file($logPath)) $exportLinks[] = ['name'=>$logName, 'batch'=>'invoice-repair-apply-log', 'count'=>$code, 'rows'=>0, 'first'=>'', 'last'=>''];
            if ($code === 0) {
                $message = 'Applied Amazon invoice repair script to selected GnuCash copy. Plan rows: ' . $planRows . '. Review the console output below, then open the apitest copy in GnuCash and verify the repaired invoice total before using this on any other file.';
                set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            } else {
                $error = 'Repair apply script exited with code ' . $code . '. Review the console output below and the log/results exports if present.';
            }
            $mode = 'repair'; set_config($db, 'ui_mode', $mode);
        }
    }
    if (($request['action'] ?? '') === 'save_missing_invoice_accounts') {
        $n = save_missing_invoice_account_edits($db, $request);
        $message = 'Saved ' . $n . ' missing-invoice account assignment(s). Re-run export after all accounts are valid in the loaded GnuCash account list.';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'save_missing_credit_memo_accounts') {
        $n = save_missing_invoice_account_edits($db, $request);
        $message = 'Saved ' . $n . ' missing-CREDIT-memo account assignment(s). Re-run the missing CREDIT memo export after all rows show reviewed, valid GnuCash accounts.';
        $mode = 'repair';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'restore_skipped_missing_invoice_targets') {
        $n = restore_skipped_missing_invoice_targets($db, 'amazon', (string)$gnucashPath);
        $message = 'Restored ' . $n . ' duplicate-skipped staged missing invoice target(s) back to exportable status. Re-run Step 2 validation/export.';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_missing_invoice_bill_csv') {
        $name = 'amazon-missing-invoices-bills-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $accountPrefix = get_config($db, 'account_export_prefix', '');
        $postInvoices = get_config($db, 'post_invoices', '0') === '1';
        $postingAccount = get_config($db, 'posting_account', default_config_value('DEFAULT_AP_ACCOUNT')) ?: default_config_value('DEFAULT_AP_ACCOUNT');
        $postDateFormat = get_config($db, 'post_date_format', 'mdy_slash');
        [$rows, $targets, $blocked, $badAccounts] = export_missing_invoice_bill_csv($db, $path, 'amazon', (string)$gnucashPath, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat);
        if (!empty($badAccounts)) {
            $error = 'Missing-invoice export blocked because ' . count($badAccounts) . ' line(s) have blank or invalid expense accounts. Use the Step 2 category review table to assign valid GnuCash accounts, then export again.';
        } elseif ($rows > 0) {
            $exportLinks[] = ['name'=>$name, 'batch'=>'missing-invoices', 'count'=>$targets, 'rows'=>$rows, 'first'=>'', 'last'=>''];
            $message = 'Created missing-invoice-only bill CSV for ' . $targets . ' staged invoice/credit memo target(s), ' . $rows . ' CSV row(s). Import this into GnuCash, then reload/rebuild the payment plan.' . ($blocked ? ' ' . count($blocked) . ' missing target(s) were not staged/exportable and still need order/bill recovery.' : '');
        } else {
            $error = 'No staged/exportable missing invoices were available for export. Upload/import the relevant Amazon order and item exports, restore duplicate-skipped missing targets, or mark the missing payments out-of-scope.';
        }
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'export_ready_payment_application_plan') {
        $name = 'amazon-ready-payment-application-plan-' . date('Ymd-His') . '.csv';
        $path = __DIR__ . '/exports/' . $name;
        $n = export_ready_payment_application_plan($db, $path, 'amazon', (string)$gnucashPath);
        $exportLinks[] = ['name'=>$name, 'batch'=>'ready-payment-plan', 'count'=>$n, 'rows'=>$n, 'first'=>'', 'last'=>''];
        set_config($db, 'last_ready_payment_plan_name', $name);
        set_config($db, 'last_ready_payment_plan_count', (string)$n);
        set_config($db, 'last_ready_payment_plan_created', date('Y-m-d H:i:s'));
        $message = 'Created ready-to-apply Amazon payment plan with ' . $n . ' row(s). This contains only rows currently classified as ready_exact_payment or ready_split_payment_group.';
        $mode = 'transactions';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'scan_lowes_unmatched_register_transactions') {
        set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
        $message = 'Refreshed Lowe\'s unmatched refund / free-floating register transaction scan. Review the report below on Step 5a.';
        $mode = 'lowes';
        $vendorStep = 'match_dry_run';
        set_config($db, 'ui_mode', $mode);
    }
    if (in_array(($request['action'] ?? ''), ['build_lowes_payment_plan_reports','run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run'], true)) {
        $doDryRun = in_array(($request['action'] ?? ''), ['run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run'], true);
        $skipAdded = 0;
        if (($request['action'] ?? '') === 'skip_lowes_payment_targets_and_rerun_dry_run') {
            $skipTargets = (array)($request['lowes_skip_target'] ?? []);
            $reason = trim((string)($request['lowes_skip_reason'] ?? 'Manual skip from Lowe\'s Step 5a exception window; investigate/resolution with vendor or card account outside automated apply.'));
            foreach ($skipTargets as $skipTarget) {
                $skipTarget = trim((string)$skipTarget);
                if ($skipTarget === '') continue;
                if (save_payment_target_exclusion($db, 'lowes', $skipTarget, $reason)) $skipAdded++;
            }
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            if ($skipAdded <= 0) {
                $error = 'No Lowe\'s payment targets were selected to skip.';
                $doDryRun = false;
            }
        }
        $stamp = date('Ymd-His');
        $planName = 'lowes-payment-application-plan-' . $stamp . '-' . APP_VERSION . '.csv';
        $readyName = 'lowes-ready-payment-application-plan-' . $stamp . '-' . APP_VERSION . '.csv';
        $exceptionName = 'lowes-payment-exception-report-' . $stamp . '-' . APP_VERSION . '.csv';
        $dryRunName = 'lowes-payment-match-dryrun-' . $stamp . '-' . APP_VERSION . '.csv';
        $logName = 'lowes-payment-match-dryrun-log-' . $stamp . '-' . APP_VERSION . '.txt';
        $planPath = __DIR__ . '/exports/' . $planName;
        $readyPath = __DIR__ . '/exports/' . $readyName;
        $exceptionPath = __DIR__ . '/exports/' . $exceptionName;
        $dryRunPath = __DIR__ . '/exports/' . $dryRunName;
        $logPath = __DIR__ . '/exports/' . $logName;
        $canContinue = true;
        if ($doDryRun) {
            $preflight = payment_book_preflight($db, 'lowes', (string)$gnucashPath);
            if (!payment_book_preflight_ok_for_step5($preflight)) {
                $canContinue = false;
                $error = 'Lowe\'s Step 5a blocked by GnuCash book preflight: ' . (string)($preflight['message'] ?? 'unknown preflight error');
                $message = 'Select/save the current copied/test GnuCash SQLite book in Step 1, then rerun Step 5a. Candidate payment rows: ' . (string)($preflight['payment_rows'] ?? 0) . '; candidate invoice IDs: ' . (string)($preflight['candidate_ids'] ?? 0) . '; exact IDs found: ' . (string)($preflight['matched_ids'] ?? 0) . '. You can still use the separate Build payment plan reports button to generate diagnostics without running the apply dry-run.';
            }
        }
        if ($canContinue) {
            $planRows = export_payment_application_plan($db, $planPath, 'lowes', (string)$gnucashPath);
            $readyRows = export_ready_payment_application_plan($db, $readyPath, 'lowes', (string)$gnucashPath);
            $exceptionRows = export_payment_exception_report($db, $exceptionPath, 'lowes', (string)$gnucashPath);
            $planCounts = payment_plan_status_counts_from_csv($planPath);
            $exportLinks[] = ['name'=>$planName, 'batch'=>'lowes-full-payment-plan', 'count'=>$planRows, 'rows'=>$planRows, 'first'=>'', 'last'=>''];
            if ($readyRows > 0 && is_file($readyPath)) $exportLinks[] = ['name'=>$readyName, 'batch'=>'lowes-ready-payment-plan', 'count'=>$readyRows, 'rows'=>$readyRows, 'first'=>'', 'last'=>''];
            if ($exceptionRows > 0) $exportLinks[] = ['name'=>$exceptionName, 'batch'=>'lowes-payment-exceptions', 'count'=>$exceptionRows, 'rows'=>$exceptionRows, 'first'=>'', 'last'=>''];
            set_config($db, 'last_lowes_payment_plan_name', $planName);
            set_config($db, 'last_lowes_ready_payment_plan_name', $readyRows > 0 ? $readyName : '');
            set_config($db, 'last_lowes_payment_exception_name', $exceptionName);
            set_config($db, 'last_lowes_payment_dryrun_created', now_sql());
            if (!$doDryRun) {
                if ($readyRows <= 0 && is_file($readyPath)) @unlink($readyPath);
                $message = 'Built Lowe\'s Step 5a payment plan reports only. Full plan rows: ' . $planRows . '; ready rows: ' . $readyRows . '; review/blocker rows: ' . ($planCounts['review'] ?? 0) . '; already applied rows: ' . ($planCounts['already_ok'] ?? 0) . '; excluded/out-of-scope rows: ' . ($planCounts['excluded'] ?? 0) . '; exception rows: ' . $exceptionRows . '. Review the full plan/exception report, then run the dry-run when the selected GnuCash test book preflight is clean.';
            } elseif ($readyRows <= 0) {
                if (is_file($readyPath)) @unlink($readyPath);
                $message = 'Lowe\'s Step 5a preflight complete: no ready-to-match payment rows were generated. Full plan rows: ' . $planRows . '; review/blocker rows: ' . ($planCounts['review'] ?? 0) . '; already applied rows: ' . ($planCounts['already_ok'] ?? 0) . '; excluded/out-of-scope rows: ' . ($planCounts['excluded'] ?? 0) . '. Review the full payment plan and exception report before Step 5b.';
                set_config($db, 'last_lowes_payment_dryrun_name', '');
                set_config($db, 'last_lowes_payment_dryrun_errors', '0');
                set_config($db, 'last_lowes_payment_dryrun_skips', '0');
                set_config($db, 'last_lowes_payment_dryrun_ready', '0');
            } else {
                [$code, $console] = run_payment_apply_script((string)$gnucashPath, $readyPath, $dryRunPath, false, $logPath, true, true, lowes_payment_match_date_window_days($db), false, 0);
                $dryCounts = payment_apply_result_status_counts($dryRunPath);
                if (is_file($dryRunPath)) $exportLinks[] = ['name'=>$dryRunName, 'batch'=>'lowes-dry-run-result', 'count'=>$dryCounts['rows'], 'rows'=>$dryCounts['rows'], 'first'=>'', 'last'=>''];
                if (is_file($logPath)) $exportLinks[] = ['name'=>$logName, 'batch'=>'lowes-dry-run-log', 'count'=>'', 'rows'=>'', 'first'=>'', 'last'=>''];
                set_config($db, 'last_lowes_payment_dryrun_name', $dryRunName);
                set_config($db, 'last_lowes_payment_dryrun_log_name', $logName);
                set_config($db, 'last_lowes_payment_dryrun_errors', (string)($dryCounts['errors'] ?? 0));
                set_config($db, 'last_lowes_payment_dryrun_skips', (string)($dryCounts['skips'] ?? 0));
                set_config($db, 'last_lowes_payment_dryrun_ready', (string)($dryCounts['ready'] ?? 0));
                set_config($db, 'last_lowes_payment_dryrun_code', (string)$code);
                set_config($db, 'last_lowes_payment_dryrun_book_path', (string)$gnucashPath);
                $summaryBits = [];
                foreach (($dryCounts['statuses'] ?? []) as $st=>$cnt) $summaryBits[] = $st . '=' . $cnt;
                $message = ($skipAdded > 0 ? ('Added ' . $skipAdded . ' Lowe\'s target(s) to the manual skip list, rebuilt the plan, and reran dry-run. ') : '') . 'Lowe\'s Step 5a dry run complete. Full plan rows: ' . $planRows . '; ready rows tested: ' . $readyRows . '; dry-run ready/matched rows: ' . ($dryCounts['ready'] ?? 0) . '; error rows: ' . ($dryCounts['errors'] ?? 0) . '; skipped rows: ' . ($dryCounts['skips'] ?? 0) . '; exception/preflight rows: ' . $exceptionRows . '. ' . (($dryCounts['errors'] ?? 0) === 0 && ($dryCounts['skips'] ?? 0) === 0 && $code === 0 ? 'DRY RUN CLEAN: Step 5b can be considered against the same uploaded/working book copy after reviewing the CSV/log.' : 'Do not apply yet; review the dry-run CSV/log and exception report first. If a row says no existing imported payment transaction was found, verify the bank/credit-card/My Lowe\'s Money register transactions are imported and that the mapped account is correct.') . ($summaryBits ? ' Status counts: ' . implode(', ', $summaryBits) . '.' : '');
                $lowesDryRunDetailHtml = render_lowes_dry_run_detail_html($dryRunPath, $logPath, $dryCounts, 30, (string)$gnucashPath, $db);
                set_config($db, 'last_lowes_payment_dryrun_detail_html', $lowesDryRunDetailHtml);
                if ($code !== 0 && (($dryCounts['errors'] ?? 0) === 0)) $error = 'The dry-run command exited with code ' . $code . '. Review the log file for command/runtime details.';
            }
        }
        $mode = 'lowes';
        $vendorStep = 'match_dry_run';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'run_lowes_payment_matching_apply') {
        $confirm = trim((string)($request['lowes_apply_confirm'] ?? ''));
        $lastReadyName = get_config($db, 'last_lowes_ready_payment_plan_name', '');
        $lastDryRunName = get_config($db, 'last_lowes_payment_dryrun_name', '');
        $lastDryRunErrors = (int)get_config($db, 'last_lowes_payment_dryrun_errors', '-1');
        $lastDryRunSkips = (int)get_config($db, 'last_lowes_payment_dryrun_skips', '-1');
        $lastDryRunReady = (int)get_config($db, 'last_lowes_payment_dryrun_ready', '0');
        $lastDryRunBookPath = get_config($db, 'last_lowes_payment_dryrun_book_path', '');
        $readyPath = $lastReadyName !== '' ? (__DIR__ . '/exports/' . basename($lastReadyName)) : '';
        $canApply = true;
        if ($confirm !== 'APPLY LOWES PAYMENTS TO COPY') {
            $canApply = false;
            $error = 'Step 5b apply blocked. Type APPLY LOWES PAYMENTS TO COPY exactly to confirm. This modifies the selected uploaded/working GnuCash book copy.';
        } elseif ($lastReadyName === '' || $readyPath === '' || !is_file($readyPath)) {
            $canApply = false;
            $error = 'Step 5b apply blocked. No ready Lowe\'s payment plan file exists. Return to Step 5a and run a clean dry run first.';
        } elseif ($lastDryRunName === '' || !is_file(__DIR__ . '/exports/' . basename($lastDryRunName))) {
            $canApply = false;
            $error = 'Step 5b apply blocked. No Step 5a dry-run result file exists. Run Step 5a first.';
        } elseif ($lastDryRunErrors !== 0 || $lastDryRunSkips !== 0 || $lastDryRunReady <= 0) {
            $canApply = false;
            $error = 'Step 5b apply blocked. Last Step 5a dry run was not clean: ready=' . $lastDryRunReady . ', errors=' . $lastDryRunErrors . ', skips=' . $lastDryRunSkips . '.';
        } elseif ($lastDryRunBookPath !== '' && realpath((string)$lastDryRunBookPath) !== false && realpath((string)$gnucashPath) !== realpath((string)$lastDryRunBookPath)) {
            $canApply = false;
            $error = 'Step 5b apply blocked. The selected GnuCash book path differs from the book used for the last clean dry run. Re-run Step 5a against the currently selected book.';
        } else {
            $preflight = payment_book_preflight($db, 'lowes', (string)$gnucashPath);
            if (!payment_book_preflight_ok_for_step5($preflight)) {
                $canApply = false;
                $error = 'Step 5b apply blocked by GnuCash book preflight: ' . (string)($preflight['message'] ?? 'unknown preflight error');
            }
        }
        if ($canApply) {
            $stamp = date('Ymd-His');
            $backupBookName = gnucash_download_basename('lowes-before-apply-backup', $stamp);
            $appliedBookName = gnucash_download_basename('lowes-after-apply-working-copy', $stamp);
            [$backupOk, $backupInfo] = copy_gnucash_book_for_download((string)$gnucashPath, $backupBookName);
            if (!$backupOk) {
                $canApply = false;
                $error = 'Step 5b apply blocked because the safety backup copy could not be created: ' . $backupInfo;
            }
        }
        if ($canApply) {
            $stamp = $stamp ?? date('Ymd-His');
            $applyName = 'lowes-payment-match-apply-' . $stamp . '-' . APP_VERSION . '.csv';
            $applyLogName = 'lowes-payment-match-apply-log-' . $stamp . '-' . APP_VERSION . '.txt';
            $applyPath = __DIR__ . '/exports/' . $applyName;
            $applyLogPath = __DIR__ . '/exports/' . $applyLogName;
            [$code, $console] = run_payment_apply_script((string)$gnucashPath, $readyPath, $applyPath, true, $applyLogPath, true, true, lowes_payment_match_date_window_days($db), true, 0);
            $applyCounts = payment_apply_result_status_counts($applyPath);
            if (isset($backupBookName) && is_file(__DIR__ . '/exports/' . basename($backupBookName))) {
                $exportLinks[] = ['name'=>$backupBookName, 'batch'=>'pre-apply GnuCash backup copy', 'count'=>'', 'rows'=>'', 'first'=>'', 'last'=>''];
                set_config($db, 'last_lowes_payment_apply_backup_book_name', $backupBookName);
            }
            [$appliedCopyOk, $appliedCopyInfo] = copy_gnucash_book_for_download((string)$gnucashPath, $appliedBookName ?? gnucash_download_basename('lowes-after-apply-working-copy', $stamp));
            if ($appliedCopyOk && isset($appliedBookName)) {
                $exportLinks[] = ['name'=>$appliedBookName, 'batch'=>'post-apply GnuCash working copy', 'count'=>'', 'rows'=>'', 'first'=>'', 'last'=>''];
                set_config($db, 'last_lowes_payment_apply_working_book_name', $appliedBookName);
            }
            elseif (!$appliedCopyOk) $error = trim((string)$error . ' Could not create post-apply downloadable .gnucash copy: ' . $appliedCopyInfo);
            if (is_file($applyPath)) $exportLinks[] = ['name'=>$applyName, 'batch'=>'lowes-apply-result', 'count'=>$applyCounts['rows'], 'rows'=>$applyCounts['rows'], 'first'=>'', 'last'=>''];
            if (is_file($applyLogPath)) $exportLinks[] = ['name'=>$applyLogName, 'batch'=>'lowes-apply-log', 'count'=>$code, 'rows'=>0, 'first'=>'', 'last'=>''];
            set_config($db, 'last_lowes_payment_apply_name', $applyName);
            set_config($db, 'last_lowes_payment_apply_log_name', $applyLogName);
            set_config($db, 'last_lowes_payment_apply_errors', (string)($applyCounts['errors'] ?? 0));
            set_config($db, 'last_lowes_payment_apply_skips', (string)($applyCounts['skips'] ?? 0));
            set_config($db, 'last_lowes_payment_apply_ok', (string)($applyCounts['applied_ok'] ?? 0));
            set_config($db, 'last_lowes_payment_apply_code', (string)$code);
            set_config($db, 'last_lowes_payment_apply_created', now_sql());
            set_config($db, 'payment_scan_refresh_token', sprintf('%.6f', microtime(true)));
            $summaryBits = [];
            foreach (($applyCounts['statuses'] ?? []) as $st=>$cnt) $summaryBits[] = $st . '=' . $cnt;
            $message = 'Lowe\'s Step 5b apply complete. Ready plan: ' . basename($readyPath) . '; applied/matched rows: ' . ($applyCounts['applied_ok'] ?? 0) . '; error rows: ' . ($applyCounts['errors'] ?? 0) . '; skipped rows: ' . ($applyCounts['skips'] ?? 0) . '. ' . (($code === 0 && ($applyCounts['errors'] ?? 0) === 0 && ($applyCounts['skips'] ?? 0) === 0) ? 'APPLY COMPLETE: download both the pre-apply backup .gnucash file and the post-apply working .gnucash file below, then open the post-apply copy in GnuCash and verify the Lowe\'s vendor report, A/P balance, and payment lots.' : 'Apply needs review. The downloadable pre-apply backup and post-apply working copy are listed below when they could be created. Some earlier groups may have been applied before a later group failed; review the result CSV/log against the working copy before proceeding.') . ($summaryBits ? ' Status counts: ' . implode(', ', $summaryBits) . '.' : '');
            $lowesApplyDetailHtml = render_lowes_apply_detail_html($applyPath, $applyLogPath, $applyCounts, 30);
            set_config($db, 'last_lowes_payment_apply_detail_html', $lowesApplyDetailHtml);
            if ($code !== 0 && (($applyCounts['errors'] ?? 0) === 0)) $error = 'The apply command exited with code ' . $code . '. Review the log file for command/runtime details.';
        }
        $mode = 'lowes';
        $vendorStep = 'match_apply';
        set_config($db, 'ui_mode', $mode);
    }


    if (in_array(($request['action'] ?? ''), ['ignore_vendor_register_transactions','unignore_vendor_register_transaction','scan_vendor_unmatched_register_transactions','scan_lowes_unmatched_register_transactions'], true)) {
        $scanVendor = strtolower(trim((string)($request['scan_vendor'] ?? $request['payment_vendor'] ?? ($mode === 'lowes' ? 'lowes' : $mode))));
        if (($request['action'] ?? '') === 'scan_lowes_unmatched_register_transactions') $scanVendor = 'lowes';
        if (!isset(vendor_config($scanVendor)['label']) && !in_array($scanVendor, ['amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $scanVendor = 'lowes';
        if (($request['action'] ?? '') === 'ignore_vendor_register_transactions') {
            $guids = array_values(array_filter(array_map(fn($x)=>strtolower(trim((string)$x)), (array)($request['ignore_tx_guid'] ?? []))));
            $reason = trim((string)($request['ignore_reason'] ?? 'Reviewed; ignored for free-floating vendor transaction report.'));
            $stmt = $db->prepare('INSERT INTO register_transaction_exclusions (vendor,tx_guid,reason,created_at) VALUES (:vendor,:tx,:reason,CURRENT_TIMESTAMP) ON CONFLICT(vendor,tx_guid) DO UPDATE SET reason=excluded.reason, created_at=excluded.created_at');
            $n = 0;
            foreach ($guids as $tx) { if ($tx==='') continue; $stmt->bindValue(':vendor',$scanVendor,SQLITE3_TEXT); $stmt->bindValue(':tx',$tx,SQLITE3_TEXT); $stmt->bindValue(':reason',$reason,SQLITE3_TEXT); $stmt->execute(); $n++; }
            $message = 'Ignored ' . $n . ' ' . (vendor_config($scanVendor)['label'] ?? $scanVendor) . ' register transaction(s) in the free-floating report.';
        } elseif (($request['action'] ?? '') === 'unignore_vendor_register_transaction') {
            $tx = strtolower(trim((string)($request['tx_guid'] ?? '')));
            if ($tx !== '') { $stmt=$db->prepare('DELETE FROM register_transaction_exclusions WHERE vendor=:vendor AND tx_guid=:tx'); $stmt->bindValue(':vendor',$scanVendor,SQLITE3_TEXT); $stmt->bindValue(':tx',$tx,SQLITE3_TEXT); $stmt->execute(); }
            $message = 'Removed ignored-register-transaction marker.';
        } else {
            $message = 'Refreshed ' . (vendor_config($scanVendor)['label'] ?? $scanVendor) . ' free-floating register transaction scan.';
        }
        $mode = $scanVendor; if ($scanVendor === 'lowes') $vendorStep = 'match_dry_run'; else $vendorStep = 'payments';
        set_config($db, 'ui_mode', $mode);
    }
    if (($request['action'] ?? '') === 'import' && isset($_FILES['csv'])) {
        $baseVendor = strtolower((string)($request['vendor'] ?? 'auto'));
        if (!in_array($baseVendor, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $baseVendor = 'auto';
        $requestVendorHint = strtolower((string)($request['vendor_hint'] ?? ''));
        $uploads = uploaded_file_entries('csv');
        $totalCount = 0; $totalItems = 0; $successfulFiles = 0; $fileMessages = []; $fileErrors = []; $lastImportedVendor = $baseVendor;
        foreach ($uploads as $i => $upload) {
            $label = trim((string)($upload['name'] ?? '')) ?: ('file ' . ($i + 1));
            $errCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string)($upload['tmp_name'] ?? '');
            if ($errCode !== UPLOAD_ERR_OK) { $fileErrors[] = $label . ': ' . upload_error_message($errCode); continue; }
            if ($tmpName === '' || !is_uploaded_file($tmpName)) { $fileErrors[] = $label . ': uploaded temporary file was not available to PHP.'; continue; }

            $chosenVendor = $baseVendor;
            $uploadPrefix = @file_get_contents($tmpName, false, null, 0, 4096);
            if ($chosenVendor === 'auto' && $requestVendorHint === 'home_depot') $chosenVendor = 'home_depot';
            if ($chosenVendor === 'auto' && $requestVendorHint === 'tractor_supply') $chosenVendor = 'tractor_supply';
            if ($chosenVendor === 'auto' && ($requestVendorHint === 'lowes' || ($uploadPrefix !== false && file_looks_like_zip($tmpName, (string)$uploadPrefix)))) $chosenVendor = 'lowes';

            $sp = 'vendor_import_' . max(0, $i);
            $db->exec('SAVEPOINT ' . $sp);
            try {
                [$count, $err, $itemCount, $customMessage] = array_pad(import_vendor_file($db, $tmpName, $chosenVendor, (string)$gnucashPath), 4, null);
                if ($err) {
                    $db->exec('ROLLBACK TO SAVEPOINT ' . $sp); $db->exec('RELEASE SAVEPOINT ' . $sp);
                    $fileErrors[] = $label . ': ' . $err;
                    continue;
                }
                $db->exec('RELEASE SAVEPOINT ' . $sp);
                $successfulFiles++; $totalCount += (int)$count; $totalItems += (int)($itemCount ?? 0); $lastImportedVendor = $chosenVendor;
                $fileMessages[] = $label . ': ' . ($customMessage ?: vendor_import_postprocess_message($db, $chosenVendor, (int)$count, $itemCount, (string)$gnucashPath));
            } catch (Throwable $e) {
                try { $db->exec('ROLLBACK TO SAVEPOINT ' . $sp); $db->exec('RELEASE SAVEPOINT ' . $sp); } catch (Throwable $ignored) {}
                $fileErrors[] = $label . ': ' . $e->getMessage();
            }
        }
        if ($successfulFiles > 0) {
            $message = 'Imported/updated vendor data from ' . $successfulFiles . ' file(s): ' . implode(' ', $fileMessages) . (!empty($fileErrors) ? ' Some file(s) were skipped: ' . implode(' ', $fileErrors) : '');
            if ($lastImportedVendor !== 'auto') $vendorHint = $lastImportedVendor;
            set_config($db, 'next_vendor_hint', $lastImportedVendor);
            $mode = 'import_data';
            if ($lastImportedVendor === 'tractor_supply') $vendorStep = 'overview';
            set_config($db, 'ui_mode', $mode);
            $page = 1; $filter = 'all'; $search = '';
        } else {
            $error = !empty($fileErrors) ? 'No vendor files were imported. ' . implode(' ', $fileErrors) : 'No vendor export files were uploaded.';
        }
    }
    if (($request['action'] ?? '') === 'import_local_vendor_path') {
        $chosenVendor = strtolower((string)($request['local_vendor'] ?? 'lowes'));
        if (!in_array($chosenVendor, ['auto','amazon','costco','walmart','lowes','tractor_supply','home_depot'], true)) $chosenVendor = 'lowes';
        $localPathInput = trim((string)($request['local_import_path'] ?? ''));
        $localPath = resolve_app_local_path($localPathInput);
        if ($localPathInput === '') {
            $error = 'Local import path is blank. Provide a server-side path such as ./lowes_scraper/2026-export/normalized, ./tractorsupply_scraper/2026-export/normalized, a normalized ZIP, or an absolute path.';
        } elseif (!is_readable($localPath)) {
            $error = local_import_path_error_with_suggestions($chosenVendor, $localPath, $localPathInput);
        } elseif (!is_file($localPath) && !is_dir($localPath)) {
            $error = 'Local import path must be a file or directory: ' . $localPath;
        } else {
            set_config($db, 'next_vendor_hint', $chosenVendor);
            if ($chosenVendor === 'lowes') set_config($db, 'last_lowes_normalized_path', $localPathInput);
            if ($chosenVendor === 'tractor_supply') set_config($db, 'last_tsc_normalized_path', $localPathInput);
            [$count, $err, $itemCount] = array_pad(import_vendor_file($db, $localPath, $chosenVendor, (string)$gnucashPath), 3, null);
            if ($err) $error = $err; else {
                $message = vendor_import_postprocess_message($db, $chosenVendor, (int)$count, $itemCount, (string)$gnucashPath);
                $mode = 'import_data';
                if ($chosenVendor === 'tractor_supply') $vendorStep = 'overview';
                set_config($db, 'ui_mode', $mode);
                $vendorHint = $chosenVendor; $page = 1; $filter = 'all'; $search = '';
            }
        }
    }
    if (($request['action'] ?? '') === 'clean_scraper_output') {
        $cleanVendor = strtolower((string)($request['clean_vendor'] ?? 'lowes'));
        $cleanKind = strtolower((string)($request['clean_kind'] ?? 'normalized'));
        $cleanPathInput = trim((string)($request['clean_path'] ?? ''));
        if ($cleanVendor !== 'lowes') {
            $error = 'Scraper cleanup is currently enabled for Lowe\'s only. Future vendor tabs can reuse this cleanup action.';
        } else {
            [$deleted, $skipped, $cleanErr, $sampleDeleted] = clean_generated_scraper_files($cleanPathInput, $cleanKind);
            if ($cleanErr) $error = $cleanErr;
            else {
                if ($cleanKind === 'normalized') set_config($db, 'last_lowes_normalized_path', $cleanPathInput);
                $message = 'Cleaned Lowe\'s scraper output: deleted ' . $deleted . ' generated file(s); skipped ' . $skipped . ' non-matching/undeletable item(s).';
                if (!empty($sampleDeleted)) $message .= ' Examples: ' . implode(', ', $sampleDeleted) . (count($sampleDeleted) >= 8 ? ', ...' : '') . '.';
                $mode = $cleanVendor === 'tractor_supply' ? 'tractor_supply' : 'lowes'; set_config($db, 'ui_mode', $mode);
            }
        }
    }
    if (($request['action'] ?? '') === 'save') {
        try {
            save_review_edits($db, $request);
            if (isset($request['save_credit_return_items'])) {
                $message = 'Saved returned item selection and rebuilt credit memo description/category.';
            } elseif (isset($request['recat_recent_manual'])) {
                [$changed, $msg] = recategorize_recent_manual_changes($db);
                $message = 'Saved. ' . $msg;
            } elseif (isset($request['recat_item'])) {
                $parts = explode('|', (string)$request['recat_item'], 3);
                if (count($parts) === 3) {
                    [$changed, $msg] = recategorize_from_item($db, $parts[0], $parts[1], $parts[2]);
                    $message = 'Saved. ' . $msg;
                } else $message = 'Review edits saved.';
            } elseif (isset($request['recat_order'])) {
                $parts = explode('|', (string)$request['recat_order'], 2);
                if (count($parts) === 2) {
                    [$changed, $msg] = recategorize_from_order($db, $parts[0], $parts[1]);
                    $message = 'Saved. ' . $msg;
                } else $message = 'Review edits saved.';
            } elseif (isset($request['set_invoice_category'])) {
                $key = (string)$request['set_invoice_category'];
                $parts = explode('|', $key, 2);
                $acct = trim((string)($request['order'][$key]['bulk_expense_account'] ?? ''));
                if (count($parts) === 2) {
                    [$changed, $msg] = set_invoice_items_category($db, $parts[0], $parts[1], $acct);
                    $message = 'Saved. ' . $msg;
                } else $message = 'Review edits saved, but the invoice-wide category key was invalid.';
            } elseif (isset($request['save_order'])) {
                $key = (string)$request['save_order'];
                $parts = explode('|', $key, 2);
                if (count($parts) === 2) {
                    $message = 'Review edits saved for the current page. Returned to invoice ' . $parts[1] . '.';
                } else $message = 'Review edits saved.';
            } else {
                $message = 'Review edits saved.';
            }
            if ($isJsonPost) respond_json(['ok'=>true, 'message'=>$message, 'debug'=>$GLOBALS['last_action_debug'] ?? null]);
        } catch (Throwable $e) {
            if ($isJsonPost) respond_json(['ok'=>false, 'message'=>'Save failed: ' . $e->getMessage()]);
            throw $e;
        }
    }
    if (in_array(($request['action'] ?? ''), ['validate_export','export','export_range'], true)) {
        [$tmpAccounts, $tmpStatus] = load_gnucash_accounts_with_status((string)$gnucashPath);
        $dupes = mark_existing_bills_to_skip($db, (string)$gnucashPath);
        $batchSize = max(1, min(1000, (int)($request['batch_size'] ?? get_config($db, 'export_batch_size', '50'))));
        $batchNumber = max(1, (int)($request['batch_number'] ?? get_config($db, 'export_batch_number', '1')));
        $rangeStart = max(1, (int)($request['range_start'] ?? $batchNumber));
        $rangeEnd = max($rangeStart, (int)($request['range_end'] ?? $rangeStart));
        $accountPrefix = trim((string)($request['account_prefix'] ?? get_config($db, 'account_export_prefix', '')));
        $postInvoices = isset($request['post_invoices']) && (string)$request['post_invoices'] === '1';
        $postingAccount = trim((string)($request['posting_account'] ?? get_config($db, 'posting_account', default_config_value('DEFAULT_AP_ACCOUNT'))));
        if ($postingAccount === '') $postingAccount = default_config_value('DEFAULT_AP_ACCOUNT');
        $postDateFormat = (string)($request['post_date_format'] ?? get_config($db, 'post_date_format', 'mdy_slash'));
        if (!in_array($postDateFormat, ['mdy_slash','dmy_slash','iso'], true)) $postDateFormat = 'mdy_slash';
        set_config($db, 'export_batch_size', (string)$batchSize);
        set_config($db, 'export_batch_number', (string)$batchNumber);
        set_config($db, 'account_export_prefix', $accountPrefix);
        set_config($db, 'post_invoices', $postInvoices ? '1' : '0');
        set_config($db, 'posting_account', $postingAccount);
        set_config($db, 'post_date_format', $postDateFormat);
        $timestamp = date('Ymd-His');
        if (($request['action'] ?? '') === 'validate_export') {
            $offsetOrders = ($batchNumber - 1) * $batchSize;
            [$valErrors, $invalidAccounts] = validate_export_ready($db, $tmpAccounts, (string)$gnucashPath, $batchSize, $offsetOrders, $postInvoices, $postingAccount);
            $ordersInBatch = export_batch_orders($db, $batchSize, $offsetOrders);
            if ($valErrors) {
                $error = implode(' ', $valErrors) . ' This validation was for export batch ' . $batchNumber . ' only. This batch contains ' . count($ordersInBatch) . ' invoice(s).';
                set_config($db, 'last_invalid_accounts', json_encode($invalidAccounts));
            } else {
                set_config($db, 'last_invalid_accounts', '{}');
                $message = 'Validation passed for batch ' . $batchNumber . ' (' . count($ordersInBatch) . ' invoice(s)). No CSV was exported. Posting date format setting: ' . post_date_format_label($postDateFormat) . '.';
            }
        } elseif (($request['action'] ?? '') === 'export_range') {
            $maxBatches = max(1, (int)ceil(max(1, count_exportable_orders($db)) / $batchSize));
            $rangeEnd = min($rangeEnd, $maxBatches);
            $allInvalid = [];
            $made = 0;
            for ($bn = $rangeStart; $bn <= $rangeEnd; $bn++) {
                $offsetOrders = ($bn - 1) * $batchSize;
                [$valErrors, $invalidAccounts] = validate_export_ready($db, $tmpAccounts, (string)$gnucashPath, $batchSize, $offsetOrders, $postInvoices, $postingAccount);
                if ($valErrors) {
                    foreach ($invalidAccounts as $acct => $where) {
                        if (!isset($allInvalid[$acct])) $allInvalid[$acct] = [];
                        $allInvalid[$acct] = array_merge($allInvalid[$acct], (array)$where);
                    }
                    continue;
                }
                $name = 'gnucash-bills-batch' . str_pad((string)$bn, 3, '0', STR_PAD_LEFT) . '-' . $timestamp . '.csv';
                $path = __DIR__ . '/exports/' . $name;
                $rows = export_gnucash_bill_csv($db, $path, false, $batchSize, $offsetOrders, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat);
                $ordersInBatch = export_batch_orders($db, $batchSize, $offsetOrders);
                $firstId = $ordersInBatch ? (string)$ordersInBatch[0]['order_id'] : '';
                $lastId = $ordersInBatch ? (string)$ordersInBatch[count($ordersInBatch)-1]['order_id'] : '';
                $exportLinks[] = ['name'=>$name, 'batch'=>$bn, 'rows'=>$rows, 'count'=>count($ordersInBatch), 'first'=>$firstId, 'last'=>$lastId];
                $made++;
            }
            if ($allInvalid) {
                set_config($db, 'last_invalid_accounts', json_encode($allInvalid));
                $error = 'Some batches were not exported because invalid/missing accounts were found. Fix those rows, then re-run the batch range export.';
            }
            if ($made > 0) $message = 'Created ' . $made . ' batch CSV file(s). Use the download links below. Browsers normally block automatic multi-downloads, so this page creates separate files instead of forcing multiple downloads.';
        } else {
            $offsetOrders = ($batchNumber - 1) * $batchSize;
            [$valErrors, $invalidAccounts] = validate_export_ready($db, $tmpAccounts, (string)$gnucashPath, $batchSize, $offsetOrders, $postInvoices, $postingAccount);
            if ($valErrors) {
                $ordersInBatch = export_batch_orders($db, $batchSize, $offsetOrders);
                $error = implode(' ', $valErrors) . ' This validation was for export batch ' . $batchNumber . ' only. This batch contains ' . count($ordersInBatch) . ' invoice(s).';
                set_config($db, 'last_invalid_accounts', json_encode($invalidAccounts));
            } else {
                $name = 'gnucash-bills-batch' . str_pad((string)$batchNumber, 3, '0', STR_PAD_LEFT) . '-' . $timestamp . '.csv'; $path = __DIR__ . '/exports/' . $name;
                $rows = export_gnucash_bill_csv($db, $path, false, $batchSize, $offsetOrders, $accountPrefix, $postInvoices, $postingAccount, $postDateFormat);
                $ordersInBatch = export_batch_orders($db, $batchSize, $offsetOrders);
                $firstId = $ordersInBatch ? (string)$ordersInBatch[0]['order_id'] : '';
                $lastId = $ordersInBatch ? (string)$ordersInBatch[count($ordersInBatch)-1]['order_id'] : '';
                $exportLinks[] = ['name'=>$name, 'batch'=>$batchNumber, 'rows'=>$rows, 'count'=>count($ordersInBatch), 'first'=>$firstId, 'last'=>$lastId];
                $message = 'Created selected batch CSV file for batch ' . $batchNumber . '. Use the download link in the export report below.';
                $mode = 'bills';
                set_config($db, 'ui_mode', $mode);
            }
        }
    }
}
[$accounts, $accountStatus] = load_gnucash_accounts_with_status((string)$gnucashPath);
// v117: Payment-source mappings need access to Asset accounts as well as Liability accounts.
// Keep the general `accounts` datalist focused on Expense/Liability accounts for invoice category fields,
// and expose a broader `payment_accounts` datalist only where payment/refund/source accounts are edited.
[$paymentAccounts, $paymentAccountStatus] = load_gnucash_accounts_with_status((string)$gnucashPath, ['Assets:', 'Liabilities:', 'Expenses:', 'Income:', 'Equity:'], 'Asset/Liability/Expense/Income/Equity');
[$offsetAccounts, $offsetAccountStatus] = load_gnucash_accounts_with_status((string)$gnucashPath, ['Assets:', 'Income:', 'Expenses:', 'Liabilities:', 'Equity:'], 'Asset/Income/Expense/Liability/Equity');
$paymentMappings = load_payment_account_map($db, 'amazon');
$paymentMappingVendor = in_array($vendorHint, ['amazon','costco','walmart','lowes','home_depot','tractor_supply'], true) ? $vendorHint : 'lowes';
$paymentMappingVendorConfig = vendor_config($paymentMappingVendor);
$paymentMappingVendorLabel = (string)($paymentMappingVendorConfig['label'] ?? ucfirst(str_replace('_', ' ', $paymentMappingVendor)));
$lowesPaymentMappings = load_payment_account_map($db, $paymentMappingVendor);
$lowesOutOfBookTargets = out_of_book_stage_targets($db, $paymentMappingVendor);
$storedValueOffsetAccount = get_config($db, 'stored_value_offset_account', default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT')) ?: default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT');
$suppressZeroVendorLedgerTargets = get_config($db, 'suppress_zero_vendor_ledger_targets', '1') === '1';
$paymentMatchStartDate = get_config($db, 'payment_match_start_date', '');
$paymentMatchEndDate = get_config($db, 'payment_match_end_date', '');
$paymentTargetExclusions = payment_target_exclusion_map($db, 'amazon');
$paymentMatchesPreview = payment_match_rows($db, 'amazon', 50);
$amazonOrderPaymentMapRows = ($mode === 'transactions') ? amazon_order_payment_map_rows($db, $dateSort, $gnucashPath) : [];
$invoiceSanityRowsAll = in_array($mode, ['sanity','repair'], true) ? invoice_sanity_check_rows($db, 'amazon', (string)$gnucashPath, $dateSort) : [];
$invoiceSanityPreview = in_array($mode, ['sanity','repair'], true) ? array_slice($invoiceSanityRowsAll, 0, 300) : [];
$invoiceSanityCounts = in_array($mode, ['sanity','repair'], true) ? invoice_sanity_status_counts($invoiceSanityRowsAll) : [];
$repairMissingCreditRows = ($mode === 'repair') ? missing_credit_memo_wizard_rows($db, 'amazon', (string)$gnucashPath) : [];
$repairMissingCreditAccountRows = ($mode === 'repair') ? sort_rows_by_date_pref(missing_credit_memo_account_review_rows($db, 'amazon', (string)$gnucashPath), 'order_date') : [];
$repairMissingCreditInvalidAccountRows = ($mode === 'repair') ? invalid_accounts_for_missing_credit_memo_targets($db, 'amazon', (string)$gnucashPath, load_valid_account_set((string)$gnucashPath)) : [];
$loadedLedgerVendors = [];
try {
    $vres = $db->query('SELECT vendor FROM orders GROUP BY vendor UNION SELECT vendor FROM vendor_payments GROUP BY vendor ORDER BY vendor');
    while ($vres && ($vr = $vres->fetchArray(SQLITE3_ASSOC))) {
        $vv = strtolower(trim((string)($vr['vendor'] ?? '')));
        if ($vv !== '') $loadedLedgerVendors[$vv] = $vv;
    }
} catch (Throwable $e) {}
if (!$loadedLedgerVendors) $loadedLedgerVendors = ['amazon'=>'amazon'];
$ledgerVendor = strtolower(trim((string)($_GET['ledger_vendor'] ?? $request['ledger_vendor'] ?? get_config($db, 'ledger_audit_vendor', 'amazon'))));
if ($ledgerVendor === '' || !isset($loadedLedgerVendors[$ledgerVendor])) $ledgerVendor = array_key_first($loadedLedgerVendors) ?: 'amazon';
set_config($db, 'ledger_audit_vendor', $ledgerVendor);
$vendorLedgerDiffRowsAll = ($mode === 'ledger') ? vendor_ledger_diff_rows($db, $ledgerVendor, (string)$gnucashPath, $dateSort) : [];
$vendorLedgerDiffPreview = ($mode === 'ledger') ? array_slice(array_values(array_filter($vendorLedgerDiffRowsAll, fn($r) => (string)($r['status'] ?? '') !== 'ok_or_reviewed')), 0, 150) : [];
$vendorLedgerDiffCounts = ($mode === 'ledger') ? vendor_ledger_diff_status_counts($vendorLedgerDiffRowsAll) : [];
$paymentPlanRowsAll = ($mode === 'transactions') ? sort_rows_by_date_pref(build_payment_application_plan($db, 'amazon', $gnucashPath), 'payment_date') : [];
$paymentExceptionRowsAll = ($mode === 'transactions') ? sort_rows_by_date_pref(payment_exception_rows($db, 'amazon', $gnucashPath), 'payment_date') : [];
$paymentExcludedInvoiceRowsAll = ($mode === 'transactions') ? sort_rows_by_date_pref(payment_excluded_invoice_rows($db, 'amazon', $gnucashPath), 'payment_date') : [];
$paymentExcludedInvoicePreview = ($mode === 'transactions') ? array_slice($paymentExcludedInvoiceRowsAll, 0, 75) : [];
$paymentPlanPreview = ($mode === 'transactions') ? array_slice(array_values(array_filter($paymentPlanRowsAll, fn($r) => !in_array((string)($r['match_status'] ?? ''), ['excluded_payment_method','excluded_invoice_exists_review','excluded_invoice_unposted_ignored','target_excluded_from_matching'], true))), 0, 100) : [];
$paymentExceptionPreview = ($mode === 'transactions') ? array_slice($paymentExceptionRowsAll, 0, 100) : [];
$primeYoungDiscrepancyRows = ($mode === 'transactions') ? prime_young_display_discrepancy_rows($db, $paymentExceptionRowsAll) : [];
$paymentStatusCounts = ($mode === 'transactions') ? payment_plan_status_counts($paymentPlanRowsAll) : [];
$paymentMissingRows = ($mode === 'transactions') ? sort_rows_by_date_pref(missing_invoice_wizard_rows($db, 'amazon', $gnucashPath), 'payment_date') : [];
$paymentMissingAccountRows = ($mode === 'transactions') ? sort_rows_by_date_pref(missing_invoice_account_review_rows($db, 'amazon', $gnucashPath), 'order_date') : [];
$paymentMissingInvalidAccountRows = ($mode === 'transactions') ? invalid_accounts_for_missing_invoice_targets($db, 'amazon', $gnucashPath, load_valid_account_set((string)$gnucashPath)) : [];
$paymentReadyRows = ($mode === 'transactions') ? sort_rows_by_date_pref(array_values(array_filter($paymentPlanRowsAll, fn($r) => in_array((string)($r['match_status'] ?? ''), ['ready_exact_payment','ready_split_payment_group'], true))), 'payment_date') : [];
$paymentOtherExceptionRows = ($mode === 'transactions') ? sort_rows_by_date_pref(array_values(array_filter($paymentExceptionRowsAll, fn($r) => (string)($r['match_status'] ?? '') !== 'invoice_missing')), 'payment_date') : [];
$paymentWizardStep = (string)($_GET['payment_wizard_step'] ?? $_POST['payment_wizard_step'] ?? get_config($db, 'payment_wizard_step', 'start'));
if (!in_array($paymentWizardStep, ['start','missing','exceptions','ready','apply','credit'], true)) $paymentWizardStep = payment_wizard_next_recommended_step($paymentPlanRowsAll, $paymentExceptionRowsAll);
$paymentWizardRecommendedStep = ($mode === 'transactions') ? payment_wizard_next_recommended_step($paymentPlanRowsAll, $paymentExceptionRowsAll) : 'start';
$paymentRowCount = count_vendor_payment_rows($db, 'amazon');
$repairFromDateDefault = repair_default_from_date($db);
$repairToDateDefault = repair_default_to_date($db);
$lastInvoiceRepairPlanName = get_config($db, 'last_invoice_repair_plan_name', '');
$lastInvoiceRepairPlanCount = get_config($db, 'last_invoice_repair_plan_count', '');
$lastInvoiceRepairPlanCountInt = (int)$lastInvoiceRepairPlanCount;
$lastInvoiceRepairPlanCreated = get_config($db, 'last_invoice_repair_plan_created', '');
$hasInvoiceRepairPlan = ($lastInvoiceRepairPlanName !== '' && is_file(__DIR__ . '/exports/' . basename($lastInvoiceRepairPlanName)));
$hasNonEmptyInvoiceRepairPlan = ($hasInvoiceRepairPlan && $lastInvoiceRepairPlanCountInt > 0);
$lastInvoiceRepairDryRunName = get_config($db, 'last_invoice_repair_dryrun_name', '');
$lastInvoiceRepairDryRunCount = get_config($db, 'last_invoice_repair_dryrun_count', '');
$lastInvoiceRepairDryRunCountInt = (int)$lastInvoiceRepairDryRunCount;
$lastInvoiceRepairDryRunBlockers = (int)get_config($db, 'last_invoice_repair_dryrun_blockers', '-1');
$lastInvoiceRepairDryRunCreated = get_config($db, 'last_invoice_repair_dryrun_created', '');
$hasCleanInvoiceRepairDryRun = ($lastInvoiceRepairDryRunName !== '' && is_file(__DIR__ . '/exports/' . basename($lastInvoiceRepairDryRunName)) && $lastInvoiceRepairDryRunCountInt > 0 && $lastInvoiceRepairDryRunBlockers === 0);
$extraAccounts = load_extra_accounts($db);
$seenAccounts = [];
foreach ($accounts as $a) $seenAccounts[$a['name']] = true;
foreach ($extraAccounts as $a) if (!isset($seenAccounts[$a['name']])) { $accounts[] = $a; $seenAccounts[$a['name']] = true; }
usort($accounts, fn($a,$b) => strcmp($a['name'], $b['name']));
$reviewAccountSet = account_names_set($accounts);
$lastInvalidAccounts = json_decode(get_config($db, 'last_invalid_accounts', '{}'), true); if (!is_array($lastInvalidAccounts)) $lastInvalidAccounts = [];
$exportableOrderCount = count_exportable_orders($db);
$stagedOrderCount = count_staged_orders($db);
$skippedOrderCount = count_skipped_orders($db);
$storedBatchSize = max(1, min(1000, (int)get_config($db, 'export_batch_size', '50')));
$storedBatchNumber = max(1, (int)get_config($db, 'export_batch_number', '1'));
$storedAccountPrefix = get_config($db, 'account_export_prefix', '');
$storedPostInvoices = get_config($db, 'post_invoices', '0') === '1';
$exportPostInvoicesChecked = $storedPostInvoices || vendor_requires_posted_bill_export($vendorHint);
$storedPostingAccount = get_config($db, 'posting_account', default_config_value('DEFAULT_AP_ACCOUNT'));
$defaultConfigValues = default_config_values($db);
$defaultConfigMeta = default_variable_config_metadata();
$storedPostDateFormat = get_config($db, 'post_date_format', 'mdy_slash');
if (!in_array($storedPostDateFormat, ['mdy_slash','dmy_slash','iso'], true)) $storedPostDateFormat = 'mdy_slash';
$storedTotalBatches = max(1, (int)ceil(max(1, $exportableOrderCount) / $storedBatchSize));
$selectedBatchNumber = min($storedBatchNumber, $storedTotalBatches);
$selectedBatchOffset = ($selectedBatchNumber - 1) * $storedBatchSize;
$selectedBatchOrders = export_batch_orders($db, $storedBatchSize, $selectedBatchOffset);
$selectedBatchStart = $selectedBatchOrders ? ($selectedBatchOffset + 1) : 0;
$selectedBatchEnd = $selectedBatchOrders ? ($selectedBatchOffset + count($selectedBatchOrders)) : 0;
$orderDir = normalize_date_sort($dateSort) === 'asc' ? 'ASC' : 'DESC';
$allOrders=[]; $res=$db->query("SELECT * FROM orders ORDER BY order_date $orderDir, CASE WHEN order_id LIKE '%-CREDIT' THEN 1 ELSE 0 END ASC, REPLACE(order_id,'-CREDIT','') $orderDir, order_id $orderDir"); while($r=$res->fetchArray(SQLITE3_ASSOC)) $allOrders[]=$r;
$allItems=[]; $res=$db->query("SELECT * FROM order_items ORDER BY order_date $orderDir, CASE WHEN order_id LIKE '%-CREDIT' THEN 1 ELSE 0 END ASC, REPLACE(order_id,'-CREDIT','') $orderDir, order_id $orderDir, description"); while($r=$res->fetchArray(SQLITE3_ASSOC)) $allItems[$r['vendor'].'|'.$r['order_id']][]=$r;
$itemTotals = order_item_totals($db);
$warnings=0; foreach($allOrders as $r) if(trim((string)$r['warning'])!=='') $warnings++;
$ordersFiltered = array_values(array_filter($allOrders, function($r) use ($filter, $search, $allItems, $itemTotals, $showSkippedInAll) {
    $okey = $r['vendor'].'|'.$r['order_id'];
    $hay = strtolower(($r['order_id'] ?? '') . ' ' . ($r['items'] ?? '') . ' ' . ($r['warning'] ?? '') . ' ' . ($r['payments'] ?? ''));
    foreach (($allItems[$okey] ?? []) as $it) $hay .= ' ' . strtolower(($it['description'] ?? '') . ' ' . ($it['asin'] ?? ''));
    if ($search !== '' && !str_contains($hay, strtolower($search))) return false;
    if ($filter === 'all' && !$showSkippedInAll && (int)$r['skip'] !== 0) return false;
    if ($filter === 'exportable' && (int)$r['skip'] !== 0) return false;
    if ($filter === 'skipped' && (int)$r['skip'] === 0) return false;
    if ($filter === 'warnings' && trim((string)$r['warning']) === '') return false;
    if ($filter === 'zero_price') {
        $found = false; foreach (($allItems[$okey] ?? []) as $it) if ((float)$it['unit_price'] == 0.0 && !(int)$it['skip']) $found = true;
        if (!$found) return false;
    }
    if ($filter === 'multi_item' && count($allItems[$okey] ?? []) < 2) return false;
    return true;
}));
$totalFiltered = count($ordersFiltered);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$pageOffset = ($page - 1) * $perPage;
$displayStart = $totalFiltered > 0 ? $pageOffset + 1 : 0;
$displayEnd = min($totalFiltered, $pageOffset + $perPage);
$orders = array_slice($ordersFiltered, $pageOffset, $perPage);
$items = $allItems;
$totalItemLines = array_sum(array_map('count',$allItems));
$lastRepairConsole = get_config($db, 'last_repair_console', '');
$lastRepairConsoleTitle = get_config($db, 'last_repair_console_title', 'Last Amazon invoice repair console output');
$jumpToValidationReport = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'validate_export');
$lastReadyPaymentPlanName = get_config($db, 'last_ready_payment_plan_name', '');
$lastReadyPaymentPlanCount = get_config($db, 'last_ready_payment_plan_count', '');
$lastReadyPaymentPlanCreated = get_config($db, 'last_ready_payment_plan_created', '');
$lastReadyPaymentPlanPath = $lastReadyPaymentPlanName !== '' ? (__DIR__ . '/exports/' . $lastReadyPaymentPlanName) : (__DIR__ . '/exports/amazon-ready-payment-application-plan-EXPORT-FIRST.csv');
$hasReadyPaymentPlan = ($lastReadyPaymentPlanName !== '' && is_file($lastReadyPaymentPlanPath));
// v126: Do not let the wizard enter Step 5 until Step 4 has actually exported a ready-payment plan file.
if ($paymentWizardStep === 'apply' && !$hasReadyPaymentPlan) $paymentWizardStep = 'ready';
$lastReadyStem = $hasReadyPaymentPlan ? preg_replace('/^amazon-ready-payment-application-plan-/', '', preg_replace('/\.csv$/', '', $lastReadyPaymentPlanName)) : 'EXPORT-FIRST';
$paymentDryRunOutPath = __DIR__ . '/exports/amazon-payment-apply-dryrun-' . $lastReadyStem . '-' . APP_VERSION . '.csv';
$paymentApplyOutPath = __DIR__ . '/exports/amazon-payment-apply-results-' . $lastReadyStem . '-' . APP_VERSION . '.csv';
$paymentApplyLogPath = __DIR__ . '/exports/amazon-payment-apply-log-' . $lastReadyStem . '-' . APP_VERSION . '.txt';
$bookArg = escapeshellarg((string)$gnucashPath);
$scriptArg = escapeshellarg(__DIR__ . '/gnucash_payment_apply_v1.py');
$planArg = escapeshellarg($lastReadyPaymentPlanPath);
$dryOutArg = escapeshellarg($paymentDryRunOutPath);
$applyOutArg = escapeshellarg($paymentApplyOutPath);
$applyLogArg = escapeshellarg($paymentApplyLogPath); $paymentMatchWindowBeforeDays = payment_apply_match_window_before_days($db); $paymentMatchWindowAfterDays = payment_apply_match_window_after_days($db); $paymentMatchWindowBeforeArg = escapeshellarg((string)$paymentMatchWindowBeforeDays); $paymentMatchWindowAfterArg = escapeshellarg((string)$paymentMatchWindowAfterDays);
$step5DryRunCommand = "HOME='/tmp/gnucash-repair-www' \\\nXDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \\\nGSETTINGS_BACKEND=memory \\\nXDG_DATA_DIRS=/usr/local/share:/usr/share \\\npython3 $scriptArg \\\n  --book $bookArg \\\n  --plan $planArg \\\n  --out $dryOutArg \\\n  --match-existing \\\n  --no-create-missing \\\n  --match-date-window-before-days $paymentMatchWindowBeforeArg \\\n  --match-date-window-after-days $paymentMatchWindowAfterArg \\\n  --dry-run";
$step5ApplyCommand = "HOME='/tmp/gnucash-repair-www' \\\nXDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \\\nGSETTINGS_BACKEND=memory \\\nXDG_DATA_DIRS=/usr/local/share:/usr/share \\\npython3 $scriptArg \\\n  --book $bookArg \\\n  --plan $planArg \\\n  --out $applyOutArg \\\n  --match-existing \\\n  --no-create-missing \\\n  --match-date-window-before-days $paymentMatchWindowBeforeArg \\\n  --match-date-window-after-days $paymentMatchWindowAfterArg \\\n  --apply 2>&1 | tee $applyLogArg";
$creditScriptArg = escapeshellarg(__DIR__ . '/gnucash_credit_refund_match_v1.py');
$creditStamp = date('Ymd-His');
$creditDryRunOutPath = __DIR__ . '/exports/amazon-credit-refund-match-dryrun-' . $creditStamp . '-' . APP_VERSION . '.csv';
$creditApplyOutPath = __DIR__ . '/exports/amazon-credit-refund-match-apply-' . $creditStamp . '-' . APP_VERSION . '.csv';
$creditApplyLogPath = __DIR__ . '/exports/amazon-credit-refund-match-apply-log-' . $creditStamp . '-' . APP_VERSION . '.txt';
$creditDryRunOutArg = escapeshellarg($creditDryRunOutPath);
$creditApplyOutArg = escapeshellarg($creditApplyOutPath);
$creditApplyLogArg = escapeshellarg($creditApplyLogPath);
$step6CreditDryRunCommand = "HOME='/tmp/gnucash-repair-www' \
XDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \
GSETTINGS_BACKEND=memory \
XDG_DATA_DIRS=/usr/local/share:/usr/share \
python3 $creditScriptArg \
  --book $bookArg \
  --from-date '2025-01-01' \
  --to-date '2026-12-31' \
  --out $creditDryRunOutArg \
  --refund-date-window-days 183 \
  --max-combination-size 4 \
  --dry-run";
$step6CreditApplyCommand = "HOME='/tmp/gnucash-repair-www' \
XDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \
GSETTINGS_BACKEND=memory \
XDG_DATA_DIRS=/usr/local/share:/usr/share \
python3 $creditScriptArg \
  --book $bookArg \
  --from-date '2025-01-01' \
  --to-date '2026-12-31' \
  --out $creditApplyOutArg \
  --refund-date-window-days 183 \
  --max-combination-size 4 \
  --apply 2>&1 | tee $creditApplyLogArg";

$lastLowesReadyPaymentPlanName = get_config($db, 'last_lowes_ready_payment_plan_name', '');
$lastLowesPaymentDryRunName = get_config($db, 'last_lowes_payment_dryrun_name', '');
$lastLowesPaymentDryRunLogName = get_config($db, 'last_lowes_payment_dryrun_log_name', '');
$lastLowesPaymentDryRunErrors = (int)get_config($db, 'last_lowes_payment_dryrun_errors', '-1');
$lastLowesPaymentDryRunSkips = (int)get_config($db, 'last_lowes_payment_dryrun_skips', '-1');
$lastLowesPaymentDryRunReady = (int)get_config($db, 'last_lowes_payment_dryrun_ready', '0');
$lastLowesPaymentDryRunCreated = get_config($db, 'last_lowes_payment_dryrun_created', '');
$lastLowesPaymentDryRunDetailHtml = get_config($db, 'last_lowes_payment_dryrun_detail_html', '');
$lastLowesPaymentApplyName = get_config($db, 'last_lowes_payment_apply_name', '');
$lastLowesPaymentApplyLogName = get_config($db, 'last_lowes_payment_apply_log_name', '');
$lastLowesPaymentApplyErrors = (int)get_config($db, 'last_lowes_payment_apply_errors', '-1');
$lastLowesPaymentApplySkips = (int)get_config($db, 'last_lowes_payment_apply_skips', '-1');
$lastLowesPaymentApplyOk = (int)get_config($db, 'last_lowes_payment_apply_ok', '0');
$lastLowesPaymentApplyCreated = get_config($db, 'last_lowes_payment_apply_created', '');
$lastLowesPaymentApplyDetailHtml = get_config($db, 'last_lowes_payment_apply_detail_html', '');
$lastLowesPaymentApplyBackupBookName = get_config($db, 'last_lowes_payment_apply_backup_book_name', '');
$lastLowesPaymentApplyWorkingBookName = get_config($db, 'last_lowes_payment_apply_working_book_name', '');
$hasCleanLowesPaymentDryRun = ($lastLowesPaymentDryRunName !== '' && is_file(__DIR__ . '/exports/' . basename($lastLowesPaymentDryRunName)) && $lastLowesPaymentDryRunErrors === 0 && $lastLowesPaymentDryRunSkips === 0 && $lastLowesPaymentDryRunReady > 0);
$lowesReadyPlanPath = $lastLowesReadyPaymentPlanName !== '' ? (__DIR__ . '/exports/' . basename($lastLowesReadyPaymentPlanName)) : (__DIR__ . '/exports/lowes-ready-payment-application-plan-RUN-STEP-5A-FIRST.csv');
$lowesPaymentBookPreflight = ($mode==='lowes' && in_array($vendorStep, ['match_dry_run','match_apply'], true)) ? payment_book_preflight($db, 'lowes', (string)$gnucashPath) : [];
$lowesPaymentTargetExclusions = ($mode==='lowes' && in_array($vendorStep, ['match_dry_run','match_apply'], true)) ? payment_target_exclusion_map($db, 'lowes') : [];
if (!empty($lowesPaymentTargetExclusions)) { foreach (array_keys($lowesPaymentTargetExclusions) as $exTarget) { $lowesPaymentTargetExclusions[$exTarget]['invoice_date'] = payment_target_invoice_date($db, 'lowes', (string)$exTarget); } }
$lowesDryRunOutPath = $lastLowesPaymentDryRunName !== '' ? (__DIR__ . '/exports/' . basename($lastLowesPaymentDryRunName)) : (__DIR__ . '/exports/lowes-payment-match-dryrun-RUN-STEP-5A-FIRST.csv');
$lowesDryRunLogPath = $lastLowesPaymentDryRunLogName !== '' ? (__DIR__ . '/exports/' . basename($lastLowesPaymentDryRunLogName)) : (__DIR__ . '/exports/lowes-payment-match-dryrun-log-RUN-STEP-5A-FIRST.txt');
$lowesPlanArg = escapeshellarg($lowesReadyPlanPath);
$lowesDryOutArg = escapeshellarg($lowesDryRunOutPath);
$lowesDryLogArg = escapeshellarg($lowesDryRunLogPath);
$lowesPaymentMatchDateWindowDays = lowes_payment_match_date_window_days($db);
$lowesStep5DryRunCommand = "HOME='/tmp/gnucash-repair-www' \\\nXDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \\\nGSETTINGS_BACKEND=memory \\\nXDG_DATA_DIRS=/usr/local/share:/usr/share \\\npython3 $scriptArg \\\n  --book $bookArg \\\n  --plan $lowesPlanArg \\\n  --out $lowesDryOutArg \\\n  --match-existing \\\n  --no-create-missing \\\n  --match-date-window-days $lowesPaymentMatchDateWindowDays \\\n  --dry-run 2>&1 | tee $lowesDryLogArg";
$lowesApplyStamp = date('Ymd-His');
$lowesApplyOutPath = __DIR__ . '/exports/lowes-payment-match-apply-' . $lowesApplyStamp . '-' . APP_VERSION . '.csv';
$lowesApplyLogPath = __DIR__ . '/exports/lowes-payment-match-apply-log-' . $lowesApplyStamp . '-' . APP_VERSION . '.txt';
$lowesApplyOutArg = escapeshellarg($lowesApplyOutPath);
$lowesApplyLogArg = escapeshellarg($lowesApplyLogPath);
$lowesStep5ApplyCommand = "HOME='/tmp/gnucash-repair-www' \
XDG_CACHE_HOME='/tmp/gnucash-repair-www/cache' \
GSETTINGS_BACKEND=memory \
XDG_DATA_DIRS=/usr/local/share:/usr/share \
python3 $scriptArg \
  --book $bookArg \
  --plan $lowesPlanArg \
  --out $lowesApplyOutArg \
  --match-existing \
  --no-create-missing \
  --match-date-window-days $lowesPaymentMatchDateWindowDays \
  --allow-non-copy-name \
  --apply 2>&1 | tee $lowesApplyLogArg";
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>GnuCash Vendor Bill Tool</title>
<style>
body { font-family: system-ui, sans-serif; margin: 1.25rem; background: #fafafa; overflow-x:hidden; } .card { background: white; border: 1px solid #ddd; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; box-shadow: 0 1px 2px #ddd; max-width:100%; box-sizing:border-box; }
input[type=text], input[type=number], input[type=date], select { max-width:100%; box-sizing: border-box; } input[type=text], input[type=number] { width: 100%; } textarea { width: 100%; min-height: 3rem; box-sizing:border-box; max-width:100%; } table { border-collapse: collapse; width: 100%; max-width:100%; background: white; font-size: 0.88rem; }
th,td { border:1px solid #ddd; padding:0.35rem; vertical-align:top; overflow-wrap:anywhere; word-break:normal; } th { background:#f0f0f0; position:sticky; top:0; } .warn { color:#9a5b00; font-weight:600; } .small { font-size:0.84rem; color:#555; } button { padding:0.45rem 0.8rem; } pre.command-box, textarea.command-box { width:100%; max-width:100%; box-sizing:border-box; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace; font-size:.86rem; white-space:pre; overflow:auto; background:#111827; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:.75rem; } textarea.command-box { min-height:10rem; resize:vertical; } .msg { background:#e6ffed; border-color:#b7ebc6; } .err { background:#ffecec; border-color:#e0aaaa; } .itemrow td { background:#fcfcff; } .itemrow-uncategorized td { background:#fff1e6; } .itemrow-categorized td { background:#edf9f0; } .itemrow-invalid-account td { background:#fff7d6; } .itemrow-uncategorized input[name$="[expense_account]"] { border:2px solid #d9822b; background:#fffaf5; } .itemrow-categorized input[name$="[expense_account]"] { border:2px solid #72b879; background:#fbfffb; } .itemrow-invalid-account input[name$="[expense_account]"] { border:2px solid #d6a100; background:#fffdf2; } .category-legend{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin:.45rem 0}.category-chip{display:inline-flex;align-items:center;gap:.35rem}.category-swatch{display:inline-block;width:1rem;height:1rem;border:1px solid #bbb;border-radius:3px}.category-swatch.uncat{background:#fff1e6;border-color:#d9822b}.category-swatch.cat{background:#edf9f0;border-color:#72b879}.category-swatch.invalid{background:#fff7d6;border-color:#d6a100}.orderrow td { background:#f8f8f8; font-weight:600; } .ordercard{border:1px solid #ddd;border-radius:8px;padding:.75rem;margin:1rem 0;background:#fff;overflow-x:hidden;max-width:100%;box-sizing:border-box}.orderhead{display:flex;gap:1rem;align-items:center;background:#f4f4f4;padding:.4rem;border-radius:6px;flex-wrap:wrap}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem}.grid3{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,2fr);gap:.5rem}.grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:.5rem}.items{margin-top:.75rem;table-layout:fixed;width:100%;max-width:100%;min-width:0}.items th{position:relative}.items th,.items td{overflow:hidden;text-overflow:clip}.items td:nth-child(3){overflow-wrap:anywhere;word-break:break-word;hyphens:auto}.items td:nth-child(5) input{min-width:100%;font-family:monospace}.items td:nth-child(4) input{max-width:7.5rem}.export-controls form,.responsive-form{max-width:100%;grid-template-columns:repeat(auto-fit,minmax(9rem,auto)) !important}.export-controls label{min-width:0}.export-controls .small{min-width:14rem}.col-resizer{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize;user-select:none;z-index:5}.col-resizer:hover,.col-resizing .col-resizer{background:rgba(0,0,0,.12)}.resize-help{font-size:.82rem;color:#555;margin:.25rem 0}.ordercard:target{outline:3px solid #d39e00;background:#fff8df}tr:target td{outline:2px solid #d39e00;background:#fff8df}.tabs{display:flex;gap:.35rem;margin:.75rem 0 1rem 0;border-bottom:1px solid #ccc}.wizard-steps{display:flex;gap:.4rem;flex-wrap:wrap;margin:.5rem 0 1rem}.wizard-step{padding:.4rem .65rem;border:1px solid #ccc;border-radius:999px;background:#f7f7f7;text-decoration:none;color:#222}.wizard-step.active{background:#d1ecf1;border-color:#0c5460;font-weight:700}.wizard-step.blocked{background:#fff3cd;border-color:#d39e00}.wizard-step.ok{background:#e6ffed;border-color:#7abf8a}.status-pill{display:inline-block;padding:.12rem .45rem;border-radius:999px;background:#eee;margin:.08rem}.tab{display:inline-block;padding:.55rem .9rem;border:1px solid #ccc;border-bottom:none;border-radius:8px 8px 0 0;background:#f2f2f2;text-decoration:none;color:#222}.tab.active{background:#fff;font-weight:700}.tab .small{margin-left:.25rem}.mode-note{background:#f7f7ff;border:1px solid #c8c8ea;border-radius:8px;padding:.65rem;margin:.5rem 0 1rem}.payment-lines{width:100%;border-collapse:collapse}.payment-lines th,.payment-lines td{font-size:.9rem;padding:.2rem .3rem}.payment-lines th{position:static;background:#f7f7f7}.orderpay-mismatch{background:#fff3cd}.orderpay-ok{background:#e6ffed}.pay-present{color:#176d2f;font-weight:700}.pay-missing{color:#9a1f1f;font-weight:700}.pay-warn{color:#9a5b00;font-weight:700}.mini-form{display:inline;margin:0}.mini-form button{padding:.15rem .35rem;font-size:.8rem}.invoice-actions{display:flex;gap:.4rem;align-items:end;flex-wrap:wrap}.invoice-actions label{font-weight:400}.invoice-actions input{min-width:18rem}.invoice-actions button{padding:.35rem .55rem}.collapsible-step>summary{cursor:pointer;font-weight:700;font-size:1.15rem}.collapsible-step[open]>summary{margin-bottom:.75rem}.action-report{scroll-margin-top:1rem}.action-report ul{margin-top:.4rem}
@media(max-width:900px){.grid4,.grid3,.grid2{grid-template-columns:1fr}.tabs{flex-wrap:wrap}}

.donation-card {
    margin: 0 0 1rem 0;
    padding: 0.75rem 1rem;
    border: 1px solid #d8d8d8;
    border-radius: 8px;
    background: #eef7ff;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
}

.donation-card span {
    display: block;
    margin-top: 0.25rem;
    color: #555;
}

.donation-card a {
    white-space: nowrap;
}
    
.lowes-dry-run-problems { table-layout: fixed; width: 100%; }
.lowes-dry-run-problems th, .lowes-dry-run-problems td { white-space: normal; overflow-wrap: anywhere; vertical-align: top; }
.lowes-dry-run-problems .select-col { width: 3.5rem; text-align: center; }
.lowes-dry-run-problems .amount-col { min-width: 8rem; width: 8.5rem; white-space: nowrap; text-align: right; }
.lowes-dry-run-problems .detail-col { width: 26%; }
.lowes-dry-run-problems td:nth-child(6) { min-width: 8rem; width: 8.5rem; white-space: nowrap; text-align: right; }
.lowes-dry-run-problems td:nth-child(9) { width: 26%; }
.lowes-unmatched-register { table-layout: fixed; width: 100%; }
.lowes-unmatched-register th, .lowes-unmatched-register td { white-space: normal; overflow-wrap: anywhere; vertical-align: top; }
.lowes-unmatched-register .amount-col { min-width: 7rem; width: 7.5rem; white-space: nowrap; text-align: right; }

.skipped-orders-table { table-layout: auto; }
.skipped-orders-table .amount-col { min-width: 10rem; width: 10rem; white-space: nowrap; text-align: right; }
.skipped-orders-table td.amount-col { font-variant-numeric: tabular-nums; }

</style></head><body><h1>GnuCash Vendor Bill Tool <span class="small"><?=h(APP_VERSION)?></span></h1>
<datalist id="accounts"><?php foreach($accounts as $a): ?><option value="<?=h($a['name'])?>"><?php endforeach; ?><option value="Expenses:Uncategorized"><option value="<?=h(default_config_value('DEFAULT_SHIPPING_ACCOUNT', $db))?>"><option value="<?=h(default_config_value('DEFAULT_TAX_ACCOUNT', $db))?>"><option value="<?=h(default_config_value('DEFAULT_GIFT_WRAP_ACCOUNT', $db))?>"></datalist>
<datalist id="all_accounts"><?php foreach($paymentAccounts as $a): ?><option value="<?=h($a['name'])?>"><?php endforeach; ?><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES', $db))?>"><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY', $db))?>"><option value="<?=h(default_config_value('DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT', $db))?>"></datalist>
<datalist id="payment_accounts"><?php foreach($paymentAccounts as $a): ?><option value="<?=h($a['name'])?>"><?php endforeach; ?><option value="<?=h(default_config_value('DEFAULT_PAYMENT_ACCOUNT_AMAZON', $db))?>"><option value="<?=h(default_config_value('DEFAULT_REWARDS_ACCOUNT_AMAZON', $db))?>"><option value="<?=h(default_config_value('DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON', $db))?>"><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_COSTCO', $db))?>"><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_WALMART', $db))?>"><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES', $db))?>"></datalist>
<datalist id="offset_accounts"><?php foreach($offsetAccounts as $a): ?><option value="<?=h($a['name'])?>"><?php endforeach; ?><option value="<?=h(default_config_value('DEFAULT_STORED_VALUE_OFFSET_ACCOUNT', $db))?>"><option value="Income:Credit Card Rewards"></datalist>
<nav class="tabs" aria-label="Main tool tabs">
  <a class="tab <?=$mode==='instructions'?'active':''?>" href="?mode=instructions#instructions">1. Instructions</a>
  <a class="tab <?=$mode==='config'?'active':''?>" href="?mode=config#default-config">2. Config</a>
  <a class="tab <?=in_array($mode, ['import_data','amazon','costco','walmart','lowes','home_depot','tractor_supply'], true)?'active':''?>" href="?mode=import_data#import-data">3. Import Data</a>
  <a class="tab <?=$mode==='bills'?'active':''?>" href="?mode=bills&vendor_hint=<?=h($vendorHint)?>#review-top">4. Review Bills &amp; Line Items</a>
  <a class="tab <?=in_array($mode, ['matching','transactions'], true) || ($mode==='lowes' && in_array($vendorStep, ['stored_value','match_dry_run','match_apply'], true))?'active':''?>" href="?mode=matching#transaction-matching">5. Transaction Matching</a>
  <a class="tab <?=in_array($mode, ['utility','repair','ledger','sanity'], true)?'active':''?>" href="?mode=utility#utility">Utility</a>
</nav>
<?php if($mode==='instructions'): ?><div class="mode-note"><strong>Instructions.</strong> The tool is organized as a normalized pipeline: configure, import vendor data, review bills and line items, then run transaction matching. Vendor-specific scrapers and stored-value exports live under Import Data.</div><?php elseif($mode==='config'): ?><div class="mode-note"><strong>Config.</strong> Set the GnuCash source, default variables, vendor IDs, and account defaults here.</div><?php elseif($mode==='import_data' || in_array($mode, ['amazon','costco','walmart','lowes','home_depot','tractor_supply'], true)): ?><div class="mode-note"><strong>Import Data.</strong> Choose a vendor plugin below, scrape/download/import its source data, and build any vendor-specific stored-value transaction exports. After import, use Review Bills &amp; Line Items for normalized invoice processing.</div><?php elseif($mode==='bills'): ?><div class="mode-note"><strong>Review Bills &amp; Line Items.</strong> Once data is ingested, vendor rows are normalized into the shared bill review/export engine. You may work one vendor at a time or review all staged vendors together.</div><?php elseif($mode==='matching' || $mode==='transactions' || ($mode==='lowes' && in_array($vendorStep, ['stored_value','match_dry_run','match_apply'], true))): ?><div class="mode-note"><strong>Transaction Matching.</strong> Run vendor payment/stored-value diagnostics, dry runs, and live apply steps after bill CSVs and register transactions have been imported into the uploaded GnuCash copy.</div><?php elseif(in_array($mode, ['utility','repair','ledger','sanity'], true)): ?><div class="mode-note"><strong>Utility.</strong> Invoice repair, ledger audit, and sanity-check tools live here while the vendor modules are still being completed.</div><?php else: ?><div class="mode-note"><strong>Vendor plugin.</strong> This direct vendor page remains available during the v192 transition, but the main workflow tabs now live at the top.</div><?php endif; ?>
<?php $hasLocalActionReport = (($mode==='bills' && in_array($lastPostAction, ['validate_export','export','export_range','export_out_of_book_bill_csv'], true)) || ($mode==='transactions' && is_export_or_validation_action($lastPostAction)) || (($mode==='lowes' && $vendorStep==='stored_value' && in_array($lastPostAction, ['export_stored_value_activity','export_stored_value_gnucash_csv'], true)) || ($mode==='lowes' && $vendorStep==='match_dry_run' && in_array($lastPostAction, ['build_lowes_payment_plan_reports','run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run','run_lowes_payment_matching_apply','scan_lowes_unmatched_register_transactions','scan_vendor_unmatched_register_transactions','ignore_vendor_register_transactions','unignore_vendor_register_transaction'], true)) || ($mode==='lowes' && $vendorStep==='match_apply' && $lastPostAction==='run_lowes_payment_matching_apply'))); if (!$hasLocalActionReport) echo render_action_report_html($message, $error, $exportLinks, 'Action report', 'action-report'); ?><?php if (!empty($lastInvalidAccounts)): ?><div class="card err" id="validation-report"><strong>Validation issues from last export/validation attempt:</strong><p class="small">Click an entry to load that order in the review section. This includes invalid accounts and total-mismatch rows. The page search will be set to the order ID so the matching bill should be visible immediately.</p><ul><?php foreach(array_slice($lastInvalidAccounts,0,25,true) as $acct=>$where): ?><li><code><?=h($acct)?></code><ul><?php foreach(array_slice((array)$where,0,10) as $w): ?><li class="small"><?=invalid_account_link_html($w, (string)$gnucashPath, $perPage)?></li><?php endforeach; ?><?php if(count((array)$where)>10): ?><li class="small">...<?=count((array)$where)-10?> more for this account</li><?php endif; ?></ul></li><?php endforeach; ?></ul><?php if(count($lastInvalidAccounts)>25): ?><p class="small">Showing first 25 invalid accounts.</p><?php endif; ?><p class="small"><a href="#export-controls">Jump back to batch export controls</a></p></div><?php elseif (!empty($jumpToValidationReport)): ?><div class="card msg" id="validation-report"><strong>Validation passed.</strong><p class="small">No validation issues were reported for the selected batch. Review the message above for batch details, then continue to export when ready.</p><p class="small"><a href="#export-controls">Jump back to batch export controls</a></p></div><?php endif; ?>
<details class="card collapsible-step" <?=in_array($mode, ['instructions','config'], true)?'open':''?>><summary>1. Connect account list / import data</summary>
<p class="small">PHP extensions: SQLite3 <?=extension_loaded('sqlite3')?'OK':'MISSING'?>; mbstring <?=extension_loaded('mbstring')?'OK':'missing but optional'?>. Review DB: <?=h(db_file_diagnostics())?></p>
<form method="post" style="margin-bottom:1rem"><input type="hidden" name="action" value="set_gnucash_path"><label>GnuCash SQLite file path, read-only account browser:</label><input type="text" name="gnucash_path" value="<?=h($gnucashPath)?>" placeholder="/home/alan/path/to/books.gnucash"><button type="submit">Save / load path</button> <span class="small"><?=h($accountStatus)?></span></form>
<form method="post" enctype="multipart/form-data" style="margin-bottom:1rem"><input type="hidden" name="action" value="upload_gnucash"><label>Or upload a copy of the GnuCash SQLite file for account browsing:</label><input type="file" name="gnucash_file" accept=".gnucash,.sqlite,.db,application/octet-stream"><button type="submit">Upload GnuCash file copy</button></form>
<form method="post" id="addAccountForm" style="margin-bottom:1rem"><input type="hidden" name="action" value="add_account"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><label>Add local account option:</label><input type="text" name="account_name" list="accounts" placeholder="Expenses:Farm Supplies:Feed"><button type="submit">Add account option</button> <span class="small">Adds to this tool's dropdown/autocomplete only; create the account in GnuCash proper before final import if it does not already exist.</span></form>
<?php if($mode==='bills'): ?>
<div class="card" style="background:#f7fbff;border-color:#9cc7ef"><strong>Changed GnuCash file?</strong><p class="small">After importing bill CSVs into GnuCash, restoring an older test copy, or deleting/renaming bills, rebuild the staged import status before exporting more invoices. This re-reads the selected GnuCash file, marks staged orders that already exist as skipped, and restores only rows that this tool previously auto-skipped as duplicates but that no longer exist in the current GnuCash file. Manual skips and ignored CREDIT targets are not changed.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap"><input type="hidden" name="action" value="rebuild_bill_import_against_gnucash"><input type="hidden" name="mode" value="bills"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Rebuild staged import status from current GnuCash file</button><span class="small">Last rebuild: <?=h(get_config($db, 'bill_import_rebuild_last_at', 'never'))?>; checked <?=h(get_config($db, 'bill_import_rebuild_last_checked', '0'))?>, marked <?=h(get_config($db, 'bill_import_rebuild_last_marked', '0'))?>, restored <?=h(get_config($db, 'bill_import_rebuild_last_restored', '0'))?>.</span></form></div>
<?php endif; ?>
</details>
<?php if($mode==='instructions'): ?>
<div class="card" id="instructions"><h2>Vendor workbench overview</h2>
<p>This tool is now organized as a multi-vendor GnuCash import workbench. Use <strong>Config</strong> for global defaults and vendor IDs, use each <strong>vendor tab</strong> for that vendor's import/review/payment workflow, and use <strong>Utility</strong> for repair and audit tools.</p>
<ol>
<li><strong>Pick a vendor tab.</strong> Amazon, Costco, Walmart, and Lowe's have active or partially active workflows. Home Depot and Tractor Supply are placeholders for later modules.</li>
<li><strong>Gather/import source data.</strong> Vendors with scrapers use <code>&lt;vendor&gt;_scraper/</code> subdirectories; direct upload remains available where appropriate.</li>
<li><strong>Categorize and export bills.</strong> The shared invoice/bill review engine is still reused, but vendor tabs route to it with the appropriate vendor hint.</li>
<li><strong>Handle payments after GnuCash import.</strong> Amazon currently has the fullest payment application wizard. Lowe's currently exports/records My Lowe's Money and card evidence and will get fuller apply/match tooling next.</li>
<li><strong>Audit before applying to the real book.</strong> Use the Utility tab for ledger audit, invoice sanity checks, and the Amazon invoice repair tool while development continues.</li>
</ol>
<div class="wizard-steps"><a class="wizard-step" href="?mode=lowes&vendor_step=scrape#lowes-scraper">Start Lowe's workflow</a><a class="wizard-step" href="?mode=amazon&vendor_step=import#vendor-amazon">Start Amazon workflow</a><a class="wizard-step" href="?mode=config#default-config">Open Config</a><a class="wizard-step" href="?mode=utility#utility">Open Utility</a></div>
</div>
<?php endif; ?>
<?php if($mode==='config'): ?>
<div class="card" id="default-variables"><h2>Default variables control panel</h2>
<p class="small">These values replace the hard-coded <code>DEFAULT_...</code> variables used by imports, exports, payment hints, and account suggestions. They are stored in this tool's local review database. The constants in the PHP file remain fallback values only.</p>
<form method="post"><input type="hidden" name="action" value="save_default_variables"><input type="hidden" name="mode" value="config">
<table><thead><tr><th>Variable</th><th>Value</th><th>Use/status</th></tr></thead><tbody>
<?php $lastDefaultGroup = ''; foreach ($defaultConfigValues as $defaultName => $defaultValue): $meta = $defaultConfigMeta[$defaultName] ?? ['label'=>$defaultName,'group'=>'Other defaults','status'=>'']; if (($meta['group'] ?? '') !== $lastDefaultGroup): $lastDefaultGroup = (string)($meta['group'] ?? 'Other defaults'); ?>
<tr><th colspan="3" style="text-align:left;background:#eaf3ff"><?=h($lastDefaultGroup)?></th></tr>
<?php endif; $inputList = str_contains($defaultName, 'ACCOUNT') ? 'all_accounts' : ''; ?>
<tr><td><code><?=h($defaultName)?></code><br><span class="small"><?=h((string)($meta['label'] ?? ''))?></span></td><td><input type="text" name="default_var[<?=h($defaultName)?>]" value="<?=h($defaultValue)?>" <?=($inputList !== '' ? 'list="'.h($inputList).'"' : '')?>></td><td class="small"><?=h((string)($meta['status'] ?? ''))?></td></tr>
<?php endforeach; ?>
</tbody></table>
<button type="submit" style="margin-top:.75rem">Save DEFAULT_ variables</button>
</form>
<p class="small">Current active vendor IDs: Amazon <?=h(default_config_value('DEFAULT_VENDOR_AMAZON', $db))?>, Costco <?=h(default_config_value('DEFAULT_VENDOR_COSTCO', $db))?>, Walmart <?=h(default_config_value('DEFAULT_VENDOR_WALMART', $db))?>, Lowe's <?=h(default_config_value('DEFAULT_VENDOR_LOWES', $db))?>. Home Depot and Tractor Supply now have initial modules; payment matching may still be expanded vendor-by-vendor.</p>
</div>
<div class="card" id="lowes-module-config"><h2>Lowe's module settings</h2>
<p class="small">Online Lowe's orders may not charge until shipped, and split shipments can post as several card transactions. This window controls Step 5a's forward-looking search for existing imported card/My Lowe's Money transactions.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><input type="hidden" name="action" value="save_lowes_module_config"><input type="hidden" name="mode" value="config"><label>Step 5 posting-lag tolerance, days<input type="number" min="0" max="365" name="lowes_payment_match_date_window_days" value="<?=h((string)lowes_payment_match_date_window_days($db))?>" style="width:8rem"></label><label>Partial-return manual-stage minimum $<input type="number" step="0.01" min="0" max="100000" name="lowes_partial_return_manual_stage_min_amount" value="<?=h(fmt_money(lowes_partial_return_manual_stage_min_amount($db)))?>" style="width:8rem"></label><button type="submit">Save Lowe's settings</button><span class="small">Default variables: <code>DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS</code>, <code>DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT</code>.</span></form>
</div>
<div class="card" id="recommended-packages"><h2>Recommended local packages</h2>
<p class="small">Install these on the local web/PHP host before using scraper ZIP imports and browser-assisted captures. <code>php8.5-zip</code> is recommended for normalized ZIP uploads; the tool can fall back to CLI <code>unzip</code>, but the PHP extension avoids slower or less-informative fallback behavior.</p>
<textarea class="command-box" readonly>sudo apt update
sudo apt install python3-venv unzip php8.5-zip
sudo systemctl restart php8.5-fpm</textarea>
<p class="small">For TSC/Lowe's CDP capture, use an already-open Chromium/Chrome session. If the Snap Chromium wrapper is noisy or has a broken <code>user-dirs.dirs</code>, system Chrome/Chromium may be cleaner, but the scraper only requires that DevTools is listening on <code>127.0.0.1:9222</code>.</p>
</div>
<div class="card" id="shared-scraper-environment"><h2>Shared scraper environment / refresh all scraped vendors</h2>
<p class="small">Use one root-level Python virtual environment for all scraper modules. This keeps the directory clean and avoids duplicate dependency installs. Recommended local packages are listed above; in particular, <code>python3-venv</code>, <code>unzip</code>, and <code>php8.5-zip</code> should be present on the PHP-FPM host. The refresh wrapper is intended for periodic monthly/quarterly updates after you launch a normal system Chromium/Chrome browser with remote debugging and log in to each vendor as needed. On Ubuntu 26.04, do not run <code>python -m playwright install chromium</code>; use the already-open browser/CDP workflow.</p>
<?php $rootQ = escapeshellarg(__DIR__); $scraperYears = scraper_years_config($db); $scraperYearsCsvArg = escapeshellarg(scraper_years_csv_config($db)); $sharedInstallCmd = "cd " . $rootQ . "\npython3 -m venv scraper_venv\n. scraper_venv/bin/activate\npip install -U pip\npip install -r lowes_scraper/requirements.txt\npip install -r tractorsupply_scraper/requirements.txt\n"; $sharedRefreshCmd = "cd " . $rootQ . "\n. scraper_venv/bin/activate\npython scrape_all_vendors.py --vendors lowes,tractor_supply --years " . $scraperYearsCsvArg . " --cdp-endpoint http://127.0.0.1:9222\n"; ?>
<h3>Install/update shared scraper environment</h3><textarea class="command-box" readonly><?=h($sharedInstallCmd)?></textarea>
<h3>Refresh all scraped vendors</h3><textarea class="command-box" readonly><?=h($sharedRefreshCmd)?></textarea>
<p class="small">The wrapper writes per-vendor/per-year normalized folders and ZIPs, for example <code>./lowes_scraper/2026-normalized.zip</code> and <code>./tractorsupply_scraper/2026-normalized.zip</code>. You can still run each vendor scraper individually from that vendor tab.</p>
</div>
<div class="card" id="config"><h2>Safe workflow and configuration</h2>
<ol>
<li><strong>Work on a copied test book first.</strong> Keep named checkpoints before every apply pass.</li>
<li><strong>Invoices / bills:</strong> import vendor exports, review categories, validate accounts, then export bill CSVs. For Costco receipts, re-import after parser changes with a soft reset so stale rounded rows are removed.</li>
<li><strong>Invoice repair wizard:</strong> use only after bills exist in the test book. A zero-row plan is a completed no-op; do not reuse old plans.</li>
<li><strong>Transactions / payments:</strong> follow the wizard in order. Resolve missing invoices and exception rows before exporting a ready plan. Step 5 matches existing bank/card/stored-value transactions; it should not create duplicates.</li>
<li><strong>Credit/refund matching:</strong> run after normal payments. The refund matcher may combine multiple refund transactions only when the dry run is clean.</li>
<li><strong>Ledger audit:</strong> use after each vendor to confirm the vendor balance returns to zero and to find duplicate or direct A/P artifacts.</li>
</ol>
<h3>Vendor parser notes</h3>
<ul class="small">
<li><strong>Costco:</strong> receipt <code>amount</code> is authoritative. Costco may report scaled quantities like <code>10</code> with rounded display unit prices. <?=h(APP_VERSION)?> exports derived unit prices with extra decimals so line totals match the receipt.</li>
<li><strong>Walmart / future vendors:</strong> use source extended line totals where available; do not round unit prices to cents when doing so would change the line total.</li>
<li><strong>Amazon:</strong> related-transactions/register data is payment truth. Displayed rewards/cash-back lines can disagree with actual card charges and should be handled as exceptions.</li>
</ul>
<p class="small">Lowe's is active for v10 normalized ZIPs and local normalized folders. Planned future vendor plugins, including Home Depot and Tractor Supply, reuse the same plugin rules: source totals win, category review blocks export, and payment matching must be dry-run clean before apply.</p>
</div>
<?php endif; ?>
<?php if($mode==='import_data'): ?>
<?php render_donation_banner($donationUrl, $showDonationBanner); ?>
<div class="card" id="import-data"><h2>3. Import Data</h2>
<p class="small">Vendor plugins gather or normalize source data, then feed the same shared bill/line-item engine. Use this page for vendor-specific import instructions and stored-value exports. After data is imported, go to <strong>Review Bills &amp; Line Items</strong>.</p>
<div class="wizard-steps">
<a class="wizard-step" href="#import-amazon">Amazon</a>
<a class="wizard-step" href="#import-costco">Costco</a>
<a class="wizard-step" href="#import-walmart">Walmart</a>
<a class="wizard-step" href="#import-lowes">Lowe's</a>
<a class="wizard-step" href="#import-home-depot">Home Depot</a>
<a class="wizard-step" href="#import-tractor-supply">Tractor Supply</a>
</div>
</div>
<div class="card" id="import-shared"><h3>Upload vendor export / normalized ZIP</h3>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import"><input type="hidden" name="mode" value="import_data"><input type="hidden" name="vendor_step" value="import"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><label>Vendor/export type:</label><select name="vendor"><option value="auto">Auto-detect</option><option value="amazon">Amazon</option><option value="costco">Costco</option><option value="walmart">Walmart</option><option value="lowes">Lowe's</option><option value="home_depot">Home Depot</option><option value="tractor_supply">Tractor Supply</option></select><label>Vendor export file(s) / normalized ZIP(s)</label><input type="file" name="csv[]" accept=".csv,.json,.zip,text/csv,application/json,application/zip" multiple required><button type="submit">Import / update vendor rows</button></form>
<p class="small">You may select multiple files at once. Amazon commonly needs at least the orders CSV, items CSV, and transaction-history CSV, and may need more files when importing multiple years. After import, stay on this tab until you are ready to continue to <a href="?mode=bills&vendor_hint=auto#review-top">Review Bills &amp; Line Items</a>.</p></div>
<div class="card" id="import-amazon"><h3>Amazon</h3><p class="small">Import Amazon order/item exports here, then use the Amazon payment wizard for transaction-history CSVs and gift card/rewards matching.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=amazon#data-import-account-mapping">Import/review Amazon bills</a> <a class="wizard-step" href="?mode=transactions&payment_wizard_step=start#payment-wizard">Amazon payment wizard</a></p></div>
<div class="card" id="import-costco"><h3>Costco</h3><p class="small">Import Costco receipt JSON/exports and review them in the shared bill engine.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=costco#data-import-account-mapping">Import/review Costco bills</a></p></div>
<div class="card" id="import-walmart"><h3>Walmart</h3><p class="small">Import Walmart order CSV exports and review them in the shared bill engine.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=walmart#data-import-account-mapping">Import/review Walmart bills</a></p></div>
<div class="card" id="import-lowes"><h3>Lowe's</h3><p class="small">Use a full Lowe's scrape/normalize run. The former limited test-run guidance has been removed; the scraper command writes one normalized folder/ZIP per year under <code>./lowes_scraper/</code>.</p><p><a class="wizard-step" href="?mode=lowes&vendor_step=scrape#lowes-scraper">Lowe's scrape/normalize commands</a> <a class="wizard-step" href="?mode=lowes&vendor_step=stored_value#lowes-stored-value-export">Build/export My Lowe's Money transactions</a> <a class="wizard-step" href="?mode=bills&vendor_hint=lowes#review-top">Review Lowe's bills</a></p></div>
<div class="card" id="import-home-depot"><h3>Home Depot</h3><p class="small">Upload Home Depot Purchase History CSV. Tender detail is limited in the CSV, so use Transaction Matching/free-floating scans for register reconciliation.</p><p><a class="wizard-step" href="?mode=home_depot&vendor_step=import#vendor-home-depot-active">Home Depot CSV import</a></p></div>
<div class="card" id="import-tractor-supply"><h3>Tractor Supply</h3><p class="small">Use the TSC scraper/parser or import a local TSC normalized folder/ZIP. TSC output feeds the same shared multi-category bill review engine as Lowe's.</p><p><a class="wizard-step" href="?mode=tractor_supply&vendor_step=scrape&tsc_nav=scraper#vendor-tractor-supply-active">TSC scraper/parser</a> <a class="wizard-step" href="?mode=bills&vendor_hint=tractor_supply#review-top">Review TSC bills</a></p></div>
<?php endif; ?>
<?php if($mode==='matching'): ?>
<div class="card" id="transaction-matching"><h2>5. Transaction Matching</h2><p class="small">Run vendor payment/stored-value diagnostics, dry runs, and live apply steps after bill CSVs and relevant bank/card/stored-value register transactions are imported into the uploaded GnuCash copy. Choose a vendor matching workflow below.</p></div>
<div class="matching-vendor-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem;align-items:stretch">
<div class="card" id="matching-lowes"><h3>Lowe's matching</h3><p><a class="wizard-step" href="?mode=lowes&vendor_step=match_dry_run#lowes-payment-dry-run">5a. Lowe's dry run</a> <a class="wizard-step" href="?mode=lowes&vendor_step=match_apply#lowes-payment-apply">5b. Lowe's apply</a> <a class="wizard-step" href="?mode=lowes&vendor_step=stored_value#lowes-stored-value-export">My Lowe's Money export</a></p></div>
<div class="card" id="matching-amazon"><h3>Amazon matching</h3><p class="small">Amazon payment matching uses the dedicated payment wizard for transaction-history CSVs, payment-method mapping, missing-invoice checks, exceptions, and payment application.</p><p><a class="wizard-step" href="?mode=transactions&payment_wizard_step=start#payment-wizard">Open Amazon payment wizard</a></p></div>
</div>
<div class="card" id="matching-register-scans"><h3>Free-floating (unlinked) payment transactions by vendor</h3><p class="small">Use these to find vendor-like card/register transactions that are not attached to A/P lots or a current matching result.</p><div class="wizard-steps"><?php foreach(['amazon','costco','walmart','lowes','home_depot','tractor_supply'] as $scanV): $scanLabel = vendor_config($scanV)['label'] ?? ucfirst(str_replace('_',' ', $scanV)); ?><a class="wizard-step" href="?mode=<?=h($scanV)?>&vendor_step=payments#vendor-unmatched-register-scan"><?=h($scanLabel)?></a><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if(in_array($mode, ['amazon','costco','walmart','home_depot','tractor_supply'], true)): ?>
<?php $vc = vendor_config($mode); $plannedVendor = false; ?>
<div class="card" id="vendor-<?=h(str_replace('_','-', $mode))?>"><h2><?=h($vc['label'])?> workflow</h2>
<?php if($plannedVendor): ?>
<p class="small"><strong>Placeholder vendor.</strong> The default vendor ID and scraper directory convention are available now, but the importer/parser for <?=h($vc['label'])?> is not implemented yet.</p>
<div class="wizard-steps"><span class="wizard-step blocked">Scraper directory: <code>./<?=h($mode==='home_depot'?'homedepot':'tractorsupply')?>_scraper/</code></span><a class="wizard-step" href="?mode=config#default-config">Set vendor ID/defaults</a><a class="wizard-step" href="?mode=utility#utility">Utility tools</a></div>
<?php else: ?>
<p class="small">This vendor tab provides wizard-style entry points while the shared import/review/payment engines remain underneath. The links below pass the vendor context to those shared pages.</p>
<?php if($mode==='amazon'): ?>
<div class="wizard-steps"><a class="wizard-step <?=$vendorStep==='import'?'active':''?>" href="?mode=import_data#import-shared">1. Import orders/items/transactions</a><a class="wizard-step" href="?mode=bills&vendor_hint=amazon#review-top">2. Categorize & export bills</a><a class="wizard-step" href="?mode=transactions&payment_wizard_step=start#payment-wizard">3. Payment mappings</a><a class="wizard-step" href="?mode=transactions&payment_wizard_step=apply#payment-wizard">4. Match/apply payments</a><a class="wizard-step" href="?mode=utility&utility_step=ledger#utility">5. Audit</a></div>
<div class="card" style="background:#f8fbff;border-color:#8bb7e0"><h3>Amazon active workflow</h3><ol class="small"><li>Upload Amazon order, item, and transaction-history CSV exports using the Import Data tab.</li><li>Review categories and export GnuCash bill CSVs.</li><li>Review/save payment-method mappings in the payment wizard.</li><li>Run missing-invoice, exception, ready-plan, apply, and refund matching steps in order.</li></ol><p><a class="wizard-step" href="?mode=import_data#import-shared">Open Step 3 import</a> <a class="wizard-step" href="?mode=bills&vendor_hint=amazon#review-top">Open Amazon invoice/bill review</a> <a class="wizard-step" href="?mode=transactions&payment_wizard_step=start#payment-wizard">Open Amazon payment wizard</a></p></div>
<?php elseif($mode==='costco'): ?>
<div class="wizard-steps"><a class="wizard-step <?=$vendorStep==='import'?'active':''?>" href="?mode=costco&vendor_step=import#vendor-costco">1. Import Costco JSON</a><a class="wizard-step" href="?mode=bills&vendor_hint=costco#review-top">2. Categorize & export bills</a><span class="wizard-step blocked">3. Payment matching planned</span><a class="wizard-step" href="?mode=utility&utility_step=ledger#utility">4. Audit</a></div>
<p class="small">Costco currently uses the shared bill review/export engine. Payment matching will be vendor-specific later.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=costco#review-top">Open Costco import/review</a></p>
<?php elseif($mode==='walmart'): ?>
<div class="wizard-steps"><a class="wizard-step <?=$vendorStep==='import'?'active':''?>" href="?mode=walmart&vendor_step=import#vendor-walmart">1. Import Walmart CSV</a><a class="wizard-step" href="?mode=bills&vendor_hint=walmart#review-top">2. Categorize & export bills</a><span class="wizard-step blocked">3. Stored value/payment export planned</span><a class="wizard-step" href="?mode=utility&utility_step=ledger#utility">4. Audit</a></div>
<p class="small">Walmart currently uses the shared bill review/export engine. The v159 tab keeps the workflow separate from Amazon-specific payment tools.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=walmart#review-top">Open Walmart import/review</a></p>
<?php if($mode==='walmart'): ?>
<div class="card" id="vendor-unmatched-register-scan" style="background:#fffdf3;border-color:#d39e00"><h3><?=h($vc['label'])?> free-floating register transaction scan</h3><p class="small">This report looks for vendor-like transactions in the mapped payment accounts that are not already tied to A/P lots or a current matching result. Use it to find purchases/refunds recorded in a register but not tied to a bill or CREDIT memo. Select reviewed rows to ignore/suppress them from future scans.</p><form method="post"><input type="hidden" name="action" value="scan_vendor_unmatched_register_transactions"><input type="hidden" name="scan_vendor" value="<?=h($mode)?>"><input type="hidden" name="mode" value="<?=h($mode)?>"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Refresh <?=h($vc['label'])?> free-floating register scan</button></form><?=render_vendor_unmatched_register_report_html($db, $mode, (string)$gnucashPath, '', 100)?></div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if(in_array($mode, ['amazon','costco'], true)): $vc = vendor_config($mode); ?>
<div class="card" id="vendor-unmatched-register-scan" style="background:#fffdf3;border-color:#d39e00"><h3><?=h($vc['label'])?> free-floating register transaction scan</h3><p class="small">This report looks for vendor-like transactions in the mapped payment accounts that are not already tied to A/P lots or a current matching result. Use it to find purchases/refunds recorded in a register but not tied to a bill or CREDIT memo. Select reviewed rows to ignore/suppress them from future scans.</p><form method="post"><input type="hidden" name="action" value="scan_vendor_unmatched_register_transactions"><input type="hidden" name="scan_vendor" value="<?=h($mode)?>"><input type="hidden" name="mode" value="<?=h($mode)?>"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Refresh <?=h($vc['label'])?> free-floating register scan</button></form><?=render_vendor_unmatched_register_report_html($db, $mode, (string)$gnucashPath, '', 100)?></div>
<?php endif; ?>

<?php if($mode==='home_depot'): ?>
<div class="card" id="vendor-home-depot-active"><h2>Home Depot workflow</h2><p class="small">Initial Home Depot module for the Pro/Purchase Tracking CSV export. The CSV groups rows by invoice/order/transaction and uses the shared bill/category/export engine. The CSV does not include tender detail or sales-tax totals, so use the free-floating register scan to reconcile card transactions and identify returns that need CREDIT memos.</p><div class="wizard-steps"><a class="wizard-step <?=$vendorStep==='import'?'active':''?>" href="?mode=home_depot&vendor_step=import#vendor-home-depot-active">1. Import Purchase History CSV</a><a class="wizard-step" href="?mode=bills&vendor_hint=home_depot#review-top">2. Categorize & export bills/credits</a><a class="wizard-step <?=$vendorStep==='payments'?'active':''?>" href="?mode=home_depot&vendor_step=payments#vendor-unmatched-register-scan">3. Payment/register diagnostics</a><a class="wizard-step" href="?mode=ledger&ledger_vendor=home_depot#ledger-audit">4. Ledger audit</a></div></div>
<?php if($vendorStep==='import' || $vendorStep==='overview'): ?>
<div class="card"><h2>Home Depot Purchase History CSV import</h2><p class="small">Download the CSV from Home Depot Purchase Tracking / Purchase History, then upload it here or from the shared import control with vendor set to <strong>Home Depot</strong>. The importer accepts metadata rows before the header and Windows-1252 CSV encoding.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import"><input type="hidden" name="mode" value="bills"><input type="hidden" name="vendor_hint" value="home_depot"><input type="hidden" name="vendor_step" value="review"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><input type="hidden" name="vendor" value="home_depot"><label>Home Depot Purchase History CSV<input type="file" name="csv" accept=".csv,text/csv" required></label><button type="submit">Import Home Depot purchase-history CSV</button></form></div>
<?php elseif($vendorStep==='payments'): ?>
<div class="card" id="vendor-unmatched-register-scan" style="background:#fffdf3;border-color:#d39e00"><h3>Home Depot free-floating register transaction scan</h3><p class="small">Map the relevant Home Depot card/payment account in payment mappings or set <code>DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT</code> on the Config tab, then refresh this report. Descriptions commonly look like <code>THE HOME DEPOT #6935</code> or similar register import text.</p><form method="post"><input type="hidden" name="action" value="scan_vendor_unmatched_register_transactions"><input type="hidden" name="scan_vendor" value="home_depot"><input type="hidden" name="mode" value="home_depot"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Refresh Home Depot free-floating register scan</button></form><?=render_vendor_unmatched_register_report_html($db, 'home_depot', (string)$gnucashPath, '', 100)?></div>
<?php endif; ?>
<?php endif; ?>

<?php if($mode==='tractor_supply'): ?>
<?php
$gnu2Root = __DIR__;
$lastTscPath = get_config($db, 'last_tsc_normalized_path', './tractorsupply_scraper/2026-export/normalized');
if (!is_readable(resolve_app_local_path((string)$lastTscPath))) {
    $tscCandidates = find_vendor_normalized_path_candidates('tractor_supply', 1);
    if (!empty($tscCandidates)) $lastTscPath = $tscCandidates[0];
}
$rootQ = escapeshellarg($gnu2Root);
$scraperYears = scraper_years_config($db);
$scraperYearsCsv = scraper_years_csv_config($db);
$tscInstallCmd = <<<CMD
cd {$rootQ}
python3 -m venv scraper_venv
. scraper_venv/bin/activate
pip install -U pip
pip install -r lowes_scraper/requirements.txt
pip install -r tractorsupply_scraper/requirements.txt
CMD;
$tscBrowserCmd = <<<'CMD'
# Pick the first available browser. System Chrome/Chromium is quieter than Snap Chromium,
# but Snap Chromium is acceptable if it reports "DevTools listening on ws://127.0.0.1:9222/...".
BROWSER="$(command -v google-chrome || command -v chromium || command -v chromium-browser || command -v /snap/bin/chromium)"
if [ -z "$BROWSER" ]; then
  echo "No Chromium/Chrome launcher found. Install Chromium/Chrome, or edit BROWSER to the correct path." >&2
  exit 1
fi
PROFILE="/home/alan/snap/chromium/common/tsc-manual-profile"
mkdir -p "$PROFILE"

"$BROWSER" \
  --remote-debugging-port=9222 \
  --remote-allow-origins=http://127.0.0.1:9222 \
  --user-data-dir="$PROFILE" \
  'https://www.tractorsupply.com/AccountDashboardView?topNav=purchaseOrder#tscOrders'
CMD;
$tscSnapRepairCmd = <<<'CMD'
# Optional: only needed if Snap Chromium repeatedly prints
# unexpected EOF while looking for matching quote from user-dirs.dirs.
# This rewrites a minimal valid Snap Chromium user-dirs file.
mkdir -p "$HOME/snap/chromium/current/.config"
cat > "$HOME/snap/chromium/current/.config/user-dirs.dirs" <<'EOF'
XDG_DESKTOP_DIR="$HOME/Desktop"
XDG_DOWNLOAD_DIR="$HOME/Downloads"
XDG_TEMPLATES_DIR="$HOME/Templates"
XDG_PUBLICSHARE_DIR="$HOME/Public"
XDG_DOCUMENTS_DIR="$HOME/Documents"
XDG_MUSIC_DIR="$HOME/Music"
XDG_PICTURES_DIR="$HOME/Pictures"
XDG_VIDEOS_DIR="$HOME/Videos"
EOF
CMD;
$tscParseSavedCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate
YEAR=2026
IN=./tractorsupply_scraper/import/
OUT=./tractorsupply_scraper/\${YEAR}-manual-normalized
ZIP=./tractorsupply_scraper/\${YEAR}-manual-normalized.zip
mkdir -p "\$IN"
python tractorsupply_scraper/tsc_extract.py parse-saved --input "\$IN" --out "\$OUT" --zip-out "\$ZIP"
printf 'ZIP upload path: %s\nDirect folder import path: %s\n' "\$ZIP" "\$OUT"
CMD;
$tscCaptureCdpCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate
YEARS="{$scraperYears}"
for YEAR in \$YEARS; do
  OUT=./tractorsupply_scraper/\${YEAR}-export
  ZIP=./tractorsupply_scraper/\${YEAR}-normalized.zip
  python tractorsupply_scraper/tsc_extract.py capture-cdp \\
    --years "\$YEAR" \\
    --order-types ONLINE,INSTORE \\
    --cdp-endpoint http://127.0.0.1:9222 \\
    --out "\$OUT" \\
    --zip-out "\$ZIP"
  printf 'Year %s complete. ZIP upload path: %s\nDirect folder import path: %s\n' "\$YEAR" "\$ZIP" "\$OUT/normalized"
done
CMD;
$tscReparseRawCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate
YEAR=2026
RAW=./tractorsupply_scraper/\${YEAR}-export/raw_html
OUT=./tractorsupply_scraper/\${YEAR}-reparse-normalized
ZIP=./tractorsupply_scraper/\${YEAR}-reparse-normalized.zip
python tractorsupply_scraper/tsc_extract.py parse-saved --input "\$RAW" --out "\$OUT" --zip-out "\$ZIP"
printf 'ZIP upload path: %s
Direct folder import path: %s
' "\$ZIP" "\$OUT"
CMD;
$sharedScraperRefreshCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate
python scrape_all_vendors.py \
  --vendors lowes,tractor_supply \
  --years {$scraperYearsCsv} \
  --cdp-endpoint http://127.0.0.1:9222
CMD;
?>
<div class="card" id="vendor-tractor-supply-active"><h2>Tractor Supply workflow</h2><p class="small">Initial Tractor Supply module. It uses the same shared bill/category/export engine as Lowe's. Payment apply automation is not enabled yet, but free-floating register scans and payment mapping are available.</p><div class="wizard-steps"><a class="wizard-step <?=$vendorStep==='scrape'?'active':''?>" href="?mode=tractor_supply&vendor_step=scrape&tsc_nav=scraper#vendor-tractor-supply-active-active">1. Scrape / parse saved pages</a><a class="wizard-step" href="?mode=bills&vendor_hint=tractor_supply#review-top">2. Categorize & export bills</a><a class="wizard-step <?=$vendorStep==='payments'?'active':''?>" href="?mode=tractor_supply&vendor_step=payments#vendor-unmatched-register-scan">3. Payment/register diagnostics</a><a class="wizard-step" href="?mode=ledger&ledger_vendor=tractor_supply#ledger-audit">4. Ledger audit</a></div></div>
<?php if($vendorStep==='scrape' || $vendorStep==='overview'): ?>
<div class="card"><h2>Tractor Supply scraper / parser instructions</h2><p class="small">Use the shared <code>./scraper_venv/</code>. TSC can parse complete saved webpages, or it can walk order detail pages from an already-open logged-in Chromium/Chrome session using raw Chrome DevTools Protocol. This avoids Playwright-managed browser installs on Ubuntu 26.04. The parser writes <code>tsc_orders.csv</code>, <code>tsc_order_items.csv</code>, and <code>tsc_order_payments.csv</code>, then builds a ZIP that can be uploaded/imported by this tool.</p><p class="small"><strong>Browser stderr note:</strong> Snap Chromium may print namespace, D-Bus, GPU, GCM, SSL, or P2P warnings. These are usually non-fatal. The important success line is <code>DevTools listening on ws://127.0.0.1:9222/...</code>. If the browser opens and that line appears, leave it running and run the scraper in a second terminal.</p><h3>Install/update shared scraper dependencies</h3><textarea class="command-box" readonly><?=h($tscInstallCmd)?></textarea><h3>Launch system Chromium/Chrome for TSC</h3><textarea class="command-box" readonly><?=h($tscBrowserCmd)?></textarea><h3>Optional Snap Chromium user-dirs repair</h3><p class="small">Use only if Snap Chromium repeatedly reports <code>unexpected EOF while looking for matching &quot;</code> from <code>user-dirs.dirs</code>.</p><textarea class="command-box" readonly><?=h($tscSnapRepairCmd)?></textarea><h3>Auto-walk logged-in TSC orders and build ZIP</h3><p class="small">The TSC scraper forces the purchase-history filter away from the site default <strong>30 Days</strong> view. For each requested year it scans both <strong>Online</strong> and <strong>In-Store</strong> order types by default. A fresh capture now clears that run's old <code>raw_html</code> and <code>normalized</code> folders first so stale pages from previous runs cannot be mixed into the new output.</p><textarea class="command-box" readonly><?=h($tscCaptureCdpCmd)?></textarea><h3>Reparse captured TSC raw_html if normalized output is empty</h3><p class="small">Use this when the browser capture saved raw HTML pages but <code>tsc_orders.csv</code> / <code>tsc_order_items.csv</code> are header-only. This does not contact Tractor Supply again; it reparses the saved raw evidence with the current bundled parser.</p><textarea class="command-box" readonly><?=h($tscReparseRawCmd)?></textarea><h3>Parse manually saved webpages and build ZIP</h3><p class="small">Save troublesome TSC order-detail pages into <code>./tractorsupply_scraper/import/</code> using browser <strong>Save Page As → Webpage, Complete</strong>. This manual parse writes to a separate <code>*-manual-normalized</code> folder so an empty manual import directory cannot overwrite a successful CDP capture.</p><textarea class="command-box" readonly><?=h($tscParseSavedCmd)?></textarea><h3>Refresh all scraped vendors</h3><textarea class="command-box" readonly><?=h($sharedScraperRefreshCmd)?></textarea></div>
<div class="card"><h2>Import Tractor Supply normalized output directly</h2><form method="post" style="display:grid;grid-template-columns:minmax(0,2fr) auto;gap:.75rem;align-items:end"><input type="hidden" name="action" value="import_local_vendor_path"><input type="hidden" name="mode" value="tractor_supply"><input type="hidden" name="local_vendor" value="tractor_supply"><label>Local normalized folder or ZIP path<input type="text" name="local_import_path" value="<?=h($lastTscPath)?>" placeholder="./tractorsupply_scraper/2026-export/normalized"></label><button type="submit">Import Tractor Supply local path</button></form></div>
<div class="card"><h2>Clean generated Tractor Supply scraper files</h2><form method="post" onsubmit="return confirm('Delete generated scraper files of the selected type from this folder? This cannot be undone.');" style="display:grid;grid-template-columns:minmax(0,2fr) 14rem auto;gap:.75rem;align-items:end"><input type="hidden" name="action" value="clean_scraper_output"><input type="hidden" name="mode" value="tractor_supply"><input type="hidden" name="clean_vendor" value="tractor_supply"><label>Folder to clean<input type="text" name="clean_path" value="<?=h($lastTscPath)?>"></label><label>File set<select name="clean_kind"><option value="normalized">Normalized CSV/JSON only</option><option value="raw">Raw capture HTML/JSON only</option><option value="zip">ZIP files only</option><option value="all">All generated CSV/JSON/HTML/ZIP</option></select></label><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Clean selected folder</button></form></div>
<?php elseif($vendorStep==='payments'): ?>
<div class="card" id="vendor-unmatched-register-scan" style="background:#fffdf3;border-color:#d39e00"><h3>Tractor Supply free-floating register transaction scan</h3><form method="post"><input type="hidden" name="action" value="scan_vendor_unmatched_register_transactions"><input type="hidden" name="scan_vendor" value="tractor_supply"><input type="hidden" name="mode" value="tractor_supply"><input type="hidden" name="vendor_step" value="payments"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Refresh Tractor Supply free-floating register scan</button></form><?=render_vendor_unmatched_register_report_html($db, 'tractor_supply', (string)$gnucashPath, '', 100)?></div>
<?php endif; ?>
<?php endif; ?>
<?php if($mode==='lowes'): ?>
<?php
$gnu2Root = __DIR__;
$lastLowesPath = get_config($db, 'last_lowes_normalized_path', './lowes_scraper/2026-export/normalized');
if (!is_readable(resolve_app_local_path((string)$lastLowesPath))) {
    $lowesCandidates = find_vendor_normalized_path_candidates('lowes', 1);
    if (!empty($lowesCandidates)) $lastLowesPath = $lowesCandidates[0];
}
$lowesDefaultZipPath = './lowes_scraper/2026-normalized.zip';
$lowesDefaultRawPath = './lowes_scraper/2026-export/raw_html';
$rootQ = escapeshellarg($gnu2Root);
$scraperYears = scraper_years_config($db);
$scraperYearsCsv = scraper_years_csv_config($db);
$lowesInstallCmd = <<<CMD
# Assumes this tool is installed at: {$gnu2Root}
cd {$rootQ}

# Lowe's scraper uses the shared root-level venv, but this vendor page installs only the Lowe's requirements.
# Use Config -> Shared scraper environment for the all-vendor install/update command.
python3 -m venv scraper_venv
. scraper_venv/bin/activate
pip install -U pip
pip install -r lowes_scraper/requirements.txt
CMD;
$lowesBrowserCmd = <<<CMD
mkdir -p /home/alan/snap/chromium/common/lowes-retail-manual-profile

/snap/bin/chromium \
  --remote-debugging-port=9222 \
  --user-data-dir=/home/alan/snap/chromium/common/lowes-retail-manual-profile \
  'https://www.lowes.com/mylowes/orders?page=1'
CMD;
$lowesFullCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate

YEARS="{$scraperYears}"
for YEAR in \$YEARS; do
  OUT="{$gnu2Root}/lowes_scraper/\${YEAR}-export"
  NORM="\$OUT/normalized"
  ZIP="{$gnu2Root}/lowes_scraper/\${YEAR}-normalized.zip"

  mkdir -p "\$OUT"
  python lowes_scraper/lowes_retail_scraper/lowes_extract.py \
    capture-cdp \
    --years "\$YEAR" \
    --cdp-endpoint http://127.0.0.1:9222 \
    --direct-summary-pages 20 \
    --order-types BOTH,ONLINE,INSTOREBACKROOM \
    --out "\$OUT"

  ( cd "\$NORM" && zip -rq "\$ZIP" . )
  printf 'Year %s complete. ZIP: %s  Direct folder: %s\n' "\$YEAR" "\$ZIP" "\$NORM"
done
CMD;
$lowesParseSavedCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate

YEAR=2026
# Set RUN to the capture directory stem you actually used.
# Example: 2026-export
RUN="\${YEAR}-export"
RAW="{$gnu2Root}/lowes_scraper/\${RUN}/raw_html"
NORM="{$gnu2Root}/lowes_scraper/\${RUN}-reparse-normalized"
ZIP="{$gnu2Root}/lowes_scraper/\${RUN}-reparse-normalized.zip"

python lowes_scraper/lowes_retail_scraper/lowes_extract.py \
  parse-saved \
  --input "\$RAW" \
  --out "\$NORM"

( cd "\$NORM" && zip -rq "\$ZIP" . )
printf 'ZIP upload path: %s
Direct folder import path: %s
' "\$ZIP" "\$NORM"
CMD;
$lowesManualImportCmd = <<<CMD
cd {$rootQ}
. scraper_venv/bin/activate

YEAR=2026
IMPORT="{$gnu2Root}/lowes_scraper/import"
NORM="{$gnu2Root}/lowes_scraper/\${YEAR}-manual-normalized"
ZIP="{$gnu2Root}/lowes_scraper/\${YEAR}-manual-normalized.zip"

mkdir -p "\$IMPORT"
# Save troublesome Lowe's order/search pages into \$IMPORT, then run:
python lowes_scraper/lowes_retail_scraper/lowes_extract.py \
  parse-saved \
  --input "\$IMPORT" \
  --out "\$NORM"

( cd "\$NORM" && zip -rq "\$ZIP" . )
printf 'ZIP upload path: %s
Direct folder import path: %s
' "\$ZIP" "\$NORM"
CMD;
$lowesShellCleanCmd = <<<CMD
# Optional shell cleanup helpers. The web form below performs the same kind of constrained cleanup.
cd {$rootQ}

# Clean normalized CSV/JSON files for one year/run:
find ./lowes_scraper/2026-export/normalized -type f \( -name '*.csv' -o -name '*.json' \) -delete

# Clean raw capture HTML/JSON files for one year/run:
find ./lowes_scraper/2026-export/raw_html -type f \( -name '*.html' -o -name '*.htm' -o -name '*.json' \) -delete
CMD;
?>
<div class="card" id="vendor-lowes"><h2>Lowe's workflow</h2>
<p class="small">Lowe's now has a vendor-specific workflow tab. The underlying importer still uses the shared review/export engine, but the steps below keep scraper, data import, bill review, My Lowe's Money export, and later transaction matching separate from Amazon-specific screens.</p>
<div class="wizard-steps lowes-workflow-nav"><a class="wizard-step <?=$vendorStep==='scrape'?'active':''?>" href="?mode=lowes&vendor_step=scrape#lowes-scraper">1. Scrape / normalize</a><a class="wizard-step <?=$vendorStep==='import'?'active':''?>" href="?mode=bills&vendor_hint=lowes&vendor_step=import#data-import-account-mapping">2. Data Import</a><a class="wizard-step <?=$vendorStep==='review'?'active':''?>" href="?mode=bills&vendor_hint=lowes&vendor_step=review#review-top">3. Review Bills and Line Items</a><a class="wizard-step <?=$vendorStep==='stored_value'?'active':''?>" href="?mode=lowes&vendor_step=stored_value#vendor-lowes">4. Build &amp; export My Lowe's Money transactions</a><a class="wizard-step <?=$vendorStep==='match_dry_run'?'active':''?>" href="?mode=lowes&vendor_step=match_dry_run#vendor-lowes">5a. Transaction Matching Dry Run</a><a class="wizard-step <?=$vendorStep==='match_apply'?'active':''?>" href="?mode=lowes&vendor_step=match_apply#vendor-lowes">5b. Apply Transaction Matching to GnuCash File</a><a class="wizard-step <?=$vendorStep==='diagnostics'?'active':''?>" href="?mode=lowes&vendor_step=diagnostics#vendor-lowes">Diagnostics</a></div>
<?php render_lowes_step5_controls($db, 'lowes', (string)$vendorStep, 'lowes-payment-dry-run'); ?>
<?php if($vendorStep==='import'): ?>
<div class="card" style="background:#f8fbff;border-color:#8bb7e0"><h3>Step 2 — Data Import</h3><p class="small">Import the normalized ZIP/folder. Payment-method mapping is shown as a separate vendor-neutral card on the Bill Review page.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=lowes&vendor_step=import#data-import-account-mapping">Open Data Import</a></p></div>
<?php elseif($vendorStep==='review'): ?>
<div class="card" style="background:#f8fbff;border-color:#8bb7e0"><h3>Step 3 — Review Bills and Line Items</h3><p class="small">Use the shared bill review engine filtered to Lowe's. This is where you set item categories, save invoice mappings, validate accounts, and export GnuCash bill CSV batches.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=lowes&vendor_step=review#review-top">Open Lowe's bill/line-item review</a></p></div>
<?php elseif($vendorStep==='stored_value'): ?>
<div class="card" style="background:#f8fbff;border-color:#8bb7e0" id="lowes-stored-value-export"><h3>Step 4 — Build &amp; export My Lowe's Money transactions</h3><p class="small">Lowe's normalized imports create <code>vendor_payments</code> rows for My Lowe's Money and credit-card evidence. My Lowe's Money defaults to <?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES', $db))?>. Use this step after bills/credits have been exported/imported so stored-value transactions can be prepared and checked separately from credit-card payment matching.</p><div style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><form method="post"><input type="hidden" name="action" value="export_stored_value_activity"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="stored_value"><input type="hidden" name="payment_vendor" value="lowes"><button type="submit">Build My Lowe's Money activity report</button></form><form method="post"><input type="hidden" name="action" value="export_stored_value_gnucash_csv"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="stored_value"><input type="hidden" name="payment_vendor" value="lowes"><button type="submit">Export My Lowe's Money GnuCash CSV</button></form><a class="wizard-step" href="?mode=bills&vendor_hint=lowes&vendor_step=stored_value#lowes-payment-mapping">Open Lowe's payment mapping/export controls</a> <a class="wizard-step" href="?mode=ledger&ledger_vendor=lowes#ledger-audit">Audit Lowe's ledger/payment evidence</a></div><p class="small">The first button creates a readable activity/audit CSV. The second creates a GnuCash transaction-import CSV for the My Lowe's Money account, using the configured stored-value offset account.</p><?php if($mode==='lowes' && $vendorStep==='stored_value' && in_array($lastPostAction, ['export_stored_value_activity','export_stored_value_gnucash_csv'], true)) echo render_action_report_html($message, $error, $exportLinks, "My Lowe's Money export report", 'payment-export-report'); ?></div>
<?php elseif($vendorStep==='match_dry_run'): ?>
<div class="card" style="background:#f8fbff;border-color:#8bb7e0" id="lowes-payment-dry-run"><h3>Step 5a — Transaction Matching Dry Run</h3><p class="small">This step is now active. It builds a Lowe's full payment plan, a ready-only payment plan, and an exception report from the current staged Lowe's payment evidence, then runs <code>gnucash_payment_apply_v1.py --dry-run --match-existing --no-create-missing</code> against the selected GnuCash book. It does not modify the book. Use this after importing the Lowe's bill CSV and any My Lowe's Money / credit-card transactions into the copied <code>.apitest</code> book.</p><?php if(!empty($lowesPaymentBookPreflight)): ?><div class="small <?=payment_book_preflight_ok_for_step5($lowesPaymentBookPreflight)?'msg':'warn'?>"><strong>Selected GnuCash book preflight:</strong> <?=h((string)($lowesPaymentBookPreflight['message'] ?? ''))?> <br>Path: <code><?=h((string)($lowesPaymentBookPreflight['resolved_path'] ?? $gnucashPath))?></code></div><?php endif; ?><div style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><form method="post"><input type="hidden" name="action" value="build_lowes_payment_plan_reports"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_dry_run"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Build Lowe's payment plan reports only</button></form><form method="post"><input type="hidden" name="action" value="run_lowes_payment_matching_dry_run"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_dry_run"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Run Lowe's transaction matching dry run</button></form><a class="wizard-step" href="?mode=ledger&ledger_vendor=lowes#ledger-audit">Open Lowe's ledger audit</a></div><?php if($lastLowesPaymentDryRunName !== ''): ?><p class="small">Last Lowe's dry run: <code><?=h($lastLowesPaymentDryRunName)?></code>, ready/matched rows <?=h((string)$lastLowesPaymentDryRunReady)?>, errors <?=h((string)$lastLowesPaymentDryRunErrors)?>, skips <?=h((string)$lastLowesPaymentDryRunSkips)?>, created <?=h($lastLowesPaymentDryRunCreated)?>.</p><?php endif; ?><?php if(!empty($lowesPaymentTargetExclusions)): ?><details><summary>Current Lowe's manual skip / exclusion list (<?=count($lowesPaymentTargetExclusions)?>)</summary><table><thead><tr><th>Target</th><th>Invoice date</th><th>Reason</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($lowesPaymentTargetExclusions as $target=>$ex): ?><tr><td><code><?=h((string)$target)?></code></td><td><?=h((string)($ex['invoice_date'] ?? ''))?></td><td class="small"><?=h((string)($ex['reason'] ?? ''))?></td><td><?=h((string)($ex['created_at'] ?? ''))?></td><td><form method="post" class="mini-form"><input type="hidden" name="action" value="unexclude_payment_target"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_dry_run"><input type="hidden" name="payment_vendor" value="lowes"><input type="hidden" name="target_invoice_id" value="<?=h((string)$target)?>"><button type="submit">re-enable</button></form></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?><div class="card" id="lowes-unmatched-register-scan" style="background:#fffdf3;border-color:#d39e00"><h4>Lowe's unmatched refund / free-floating register transaction scan</h4><p class="small">Use this to find Lowe's-like card or My Lowe's Money register transactions in the mapped payment accounts that are not tied to the current dry-run/apply result and are not already attached to A/P lots. This helps identify missing bills, missing CREDIT memos, unresolved refunds, out-of-scope entity transactions, or duplicate/manual imports.</p><form method="post" style="margin-bottom:.5rem"><input type="hidden" name="action" value="scan_lowes_unmatched_register_transactions"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_dry_run"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit">Refresh unmatched Lowe's register transaction scan</button></form><?php $lowesUnmatchedResultPath = ($lastLowesPaymentDryRunName !== '') ? (__DIR__ . '/exports/' . basename($lastLowesPaymentDryRunName)) : ''; echo render_lowes_unmatched_register_report_html($db, (string)$gnucashPath, $lowesUnmatchedResultPath, 100); ?></div><h4>Equivalent shell command</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($lowesStep5DryRunCommand)?></textarea><p class="small">A clean dry run means zero <code>ERROR_...</code> rows and zero <code>SKIP_...</code> rows in the dry-run result CSV. Rows that are already applied or excluded are reported in the full plan/exception outputs for review rather than applied again.</p><?php if($mode==='lowes' && $vendorStep==='match_dry_run' && in_array($lastPostAction, ['build_lowes_payment_plan_reports','run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run','run_lowes_payment_matching_apply','scan_lowes_unmatched_register_transactions','scan_vendor_unmatched_register_transactions','ignore_vendor_register_transactions','unignore_vendor_register_transaction'], true)) { echo render_action_report_html($message, $error, $exportLinks, "Lowe's transaction matching dry-run report", 'lowes-payment-dry-run-report'); if(in_array($lastPostAction, ['run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run'], true)) echo $lowesDryRunDetailHtml; } elseif($mode==='lowes' && $vendorStep==='match_dry_run' && $lastLowesPaymentDryRunDetailHtml !== '') { echo $lastLowesPaymentDryRunDetailHtml; } ?></div>
<?php elseif($vendorStep==='match_apply'): ?>
<div class="card" style="background:#fffdf3;border-color:#d39e00" id="lowes-payment-apply"><h3>Step 5b — Apply Transaction Matching to GnuCash File</h3><?php if($hasCleanLowesPaymentDryRun): ?><p class="small"><strong>Ready:</strong> the last Lowe's dry run was clean against the currently recorded ready plan. This step modifies the selected uploaded/working GnuCash SQLite book copy by attaching/matching existing credit-card and My Lowe's Money transactions to the imported Lowe's vendor bill lots. It does not create new payment transactions when <code>--no-create-missing</code> is active. Because a file uploaded into the tool is already a copy, the web apply path is allowed to operate on <code>data/gnucash.sqlite</code>; before applying, the tool creates a downloadable pre-apply backup and then a downloadable post-apply <code>.gnucash</code> working file.</p><p class="small">Ready plan: <code><?=h($lastLowesReadyPaymentPlanName)?></code>. Last dry run: <code><?=h($lastLowesPaymentDryRunName)?></code>, ready/matched rows <?=h((string)$lastLowesPaymentDryRunReady)?>, created <?=h($lastLowesPaymentDryRunCreated)?>.</p><form method="post" style="display:grid;grid-template-columns:minmax(18rem,34rem) auto;gap:.75rem;align-items:end;max-width:52rem"><input type="hidden" name="action" value="run_lowes_payment_matching_apply"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="vendor_step" value="match_apply"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><label>Confirmation phrase<input type="text" name="lowes_apply_confirm" placeholder="APPLY LOWES PAYMENTS TO COPY"></label><button type="submit" style="background:#ffd6d6;border:2px solid #9a1f1f;font-weight:bold" onclick="return confirm('This will modify the selected uploaded/working GnuCash book copy, create a pre-apply backup, and create a downloadable post-apply .gnucash file. Continue only after reviewing the clean Step 5a dry-run result.')">Apply Lowe's matching to uploaded/working book copy</button></form><?php else: ?><p class="warn">Apply is blocked until Step 5a has a clean Lowe's dry run: zero error rows, zero skipped rows, and at least one ready/matched row.</p><?php endif; ?><h4>Equivalent shell command</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($lowesStep5ApplyCommand)?></textarea><p class="small">After apply completes, download the post-apply <code>.gnucash</code> working copy, open it in GnuCash, and verify the Lowe's vendor report, A/P balance, and the credit-card/My Lowe's Money registers before using the result as a production repair.</p><?php if($lastLowesPaymentApplyName !== ''): ?><p class="small">Last Lowe's apply: <code><?=h($lastLowesPaymentApplyName)?></code>, applied/matched rows <?=h((string)$lastLowesPaymentApplyOk)?>, errors <?=h((string)$lastLowesPaymentApplyErrors)?>, skips <?=h((string)$lastLowesPaymentApplySkips)?>, created <?=h($lastLowesPaymentApplyCreated)?>.<?php if($lastLowesPaymentApplyBackupBookName !== ''): ?> Backup: <a href="<?=h(make_export_link($lastLowesPaymentApplyBackupBookName))?>" download><?=h($lastLowesPaymentApplyBackupBookName)?></a>.<?php endif; ?><?php if($lastLowesPaymentApplyWorkingBookName !== ''): ?> Working copy: <a href="<?=h(make_export_link($lastLowesPaymentApplyWorkingBookName))?>" download><?=h($lastLowesPaymentApplyWorkingBookName)?></a>.<?php endif; ?></p><?php endif; ?><p><a class="wizard-step" href="?mode=lowes&vendor_step=match_dry_run#lowes-payment-dry-run">Return to Step 5a dry run</a> <a class="wizard-step" href="?mode=ledger&ledger_vendor=lowes#ledger-audit">Open Lowe's ledger audit</a></p><?php if($mode==='lowes' && $vendorStep==='match_apply' && $lastPostAction==='run_lowes_payment_matching_apply') { echo render_action_report_html($message, $error, $exportLinks, "Lowe's transaction matching apply report", 'lowes-payment-apply-report'); if(isset($lowesApplyDetailHtml)) echo $lowesApplyDetailHtml; } elseif($mode==='lowes' && $vendorStep==='match_apply' && $lastLowesPaymentApplyDetailHtml !== '') { echo $lastLowesPaymentApplyDetailHtml; } ?></div>
<?php elseif($vendorStep==='diagnostics'): ?>
<div class="card"><h3>Lowe's diagnostics</h3><p class="small">Review normalized diagnostics from the scraper output, parser warnings on staged invoices, and ledger-audit differences after importing into GnuCash.</p><p><a class="wizard-step" href="?mode=bills&vendor_hint=lowes&filter=warnings#review-top">Show Lowe's staged warnings</a> <a class="wizard-step" href="?mode=ledger&ledger_vendor=lowes#ledger-audit">Open Lowe's ledger audit</a></p></div>
<?php endif; ?>
</div>
<?php if($vendorStep==='scrape'): ?>
<div class="card" id="lowes-scraper"><h2>Lowe's scraper instructions</h2>
<p class="small">The importer accepts either a Lowe's v10 normalized ZIP or a server-side normalized folder containing <code>lowes_orders.csv</code>, <code>lowes_order_items.csv</code>, and <code>lowes_order_payments.csv</code>. v157 includes the scraper in <code>./lowes_scraper/</code>. Future vendor scrapers should follow the same directory convention: <code>./homedepot_scraper/</code>, <code>./tractorsupply_scraper/</code>, etc.</p>
<p class="small">Recommended local layout: keep each year/run under <code>./lowes_scraper/</code>, for example <code>./lowes_scraper/2026-export/normalized</code> and <code>./lowes_scraper/2026-normalized.zip</code>. The web importer can use either path.</p>
<h3>1. Install/update scraper dependencies</h3>
<textarea class="command-box" readonly><?=h($lowesInstallCmd)?></textarea>
<h3>2. Open a terminal session and run the below command. This will open a browser window for you to log into Lowe's.</h3>
<p class="small">Leave this browser running after logging in manually.</p>
<textarea class="command-box" readonly><?=h($lowesBrowserCmd)?></textarea>
<h3>3. Open a SECOND terminal screen and run the below command to process the specified years.</h3>
<p class="small">The configured years come from <strong>Config → DEFAULT_SCRAPER_YEARS</strong>. This keeps each year separate so the direct folder importer can use paths such as <code>./lowes_scraper/2026-export/normalized</code>, <code>./lowes_scraper/2025-export/normalized</code>, etc.</p>
<textarea class="command-box" readonly><?=h($lowesFullCmd)?></textarea>
<h3>4. Rebuild normalized output from saved raw HTML</h3>
<p class="small">Use this when raw HTML was already captured and only the parser/importer changed. Edit <code>YEAR=...</code> or <code>RAW=...</code> as needed.</p>
<textarea class="command-box" readonly><?=h($lowesParseSavedCmd)?></textarea>
<h3>5. Manual import folder for problem pages</h3>
<p class="small">If the scraper reports unsuccessful order detail captures, search/browse those order numbers manually in Lowe's, open the fully rendered order or search-results page, and save it into <code>./lowes_scraper/import/</code>. Prefer <strong>Save Page As → Webpage, Complete</strong>. The parser only reads the <code>.html</code>/<code>.htm</code> files, but the Complete save mode is more likely to preserve the hydrated visible DOM on Lowe's single-page app pages. Plain HTML may work when the saved file contains the visible order text; page source or PDF will not.</p>
<textarea class="command-box" readonly><?=h($lowesManualImportCmd)?></textarea>
</div>
<div class="card"><h2>Import Lowe's normalized output directly from server folder/path</h2>
<p class="small">Use this when the normalized folder or ZIP already exists on the server. Relative paths are resolved from this tool's root directory, so <code>./lowes_scraper/2026-export/normalized</code> works. Use the exact path printed by the scrape command.</p>
<?php $lowesImportCandidates = find_vendor_normalized_path_candidates('lowes', 6); if(!empty($lowesImportCandidates)): ?><p class="small"><strong>Detected readable Lowe's normalized outputs:</strong> <?php foreach($lowesImportCandidates as $cand): ?><code><?=h($cand)?></code> <?php endforeach; ?></p><?php endif; ?>
<form method="post" style="display:grid;grid-template-columns:minmax(0,2fr) auto;gap:.75rem;align-items:end"><input type="hidden" name="action" value="import_local_vendor_path"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="local_vendor" value="lowes"><label>Local normalized folder or ZIP path<input type="text" name="local_import_path" value="<?=h($lastLowesPath)?>" placeholder="./lowes_scraper/2026-export/normalized"></label><button type="submit">Import Lowe's local path</button></form>
<p class="small">Examples: <code>./lowes_scraper/2026-export/normalized</code>, <code>./lowes_scraper/2025-export/normalized</code>, or <code>./lowes_scraper/2026-normalized.zip</code>. After import, review the rows on the Invoices / bills tab. My Lowe's Money maps to <?=h(default_config_value('DEFAULT_STORED_VALUE_ACCOUNT_LOWES', $db))?> and Lowe's vendor ID is <?=h(default_config_value('DEFAULT_VENDOR_LOWES', $db))?>.</p>
</div>
<div class="card"><h2>Clean generated Lowe's scraper files</h2>
<p class="small">This deletes generated files only, not directories. It is intentionally restricted to paths under <code>./lowes_scraper/</code> or <code>./exports/</code>. Choose <strong>normalized</strong> for CSV/JSON output, <strong>raw capture</strong> for HTML/JSON capture files, or <strong>all generated</strong> for CSV/JSON/HTML/ZIP files.</p>
<form method="post" onsubmit="return confirm('Delete generated scraper files of the selected type from this folder? This cannot be undone.');" style="display:grid;grid-template-columns:minmax(0,2fr) 14rem auto;gap:.75rem;align-items:end"><input type="hidden" name="action" value="clean_scraper_output"><input type="hidden" name="mode" value="lowes"><input type="hidden" name="clean_vendor" value="lowes"><label>Folder to clean<input type="text" name="clean_path" value="<?=h($lastLowesPath)?>" placeholder="./lowes_scraper/2026-export/normalized"></label><label>File set<select name="clean_kind"><option value="normalized">Normalized CSV/JSON only</option><option value="raw">Raw capture HTML/JSON only</option><option value="zip">ZIP files only</option><option value="all">All generated CSV/JSON/HTML/ZIP</option></select></label><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Clean selected folder</button></form>
<h3>Equivalent shell cleanup examples</h3>
<textarea class="command-box" readonly><?=h($lowesShellCleanCmd)?></textarea>
</div>
<div class="card"><h2>Expected normalized files</h2><ul class="small"><li><code>lowes_orders.csv</code> — document-level sales/returns.</li><li><code>lowes_order_items.csv</code> — accounting line items using source extended amounts.</li><li><code>lowes_order_payments.csv</code> — card, My Lowe's Money, and refund-evidence rows.</li><li><code>lowes_order_stored_value_payments.csv</code> and <code>lowes_order_payment_refunds.csv</code> — convenience/audit files.</li><li><code>lowes_parse_diagnostics.csv</code> — parser warnings and validation evidence.</li><li><code>lowes_detail_capture_errors.csv</code> — explicit list of order numbers/detail pages the scraper saw but could not turn into parsed invoice rows.</li></ul></div>
<?php endif; ?>
<?php endif; ?>
<?php if($mode==='bills'): ?>
<?php if($mode==='bills' && $vendorHint==='lowes'): ?>
<div class="card" id="lowes-workflow-header"><h2>Lowe's workflow</h2><div class="wizard-steps lowes-workflow-nav"><a class="wizard-step" href="?mode=lowes&vendor_step=scrape#lowes-scraper">1. Scrape / normalize</a><a class="wizard-step <?=(($vendorStep==='import')?'active':'')?>" href="?mode=bills&vendor_hint=lowes&vendor_step=import#data-import-account-mapping">2. Data Import</a><a class="wizard-step <?=(($vendorStep==='' || $vendorStep==='review')?'active':'')?>" href="?mode=bills&vendor_hint=lowes&vendor_step=review#review-top">3. Review Bills and Line Items</a><a class="wizard-step <?=(($vendorStep==='stored_value')?'active':'')?>" href="?mode=lowes&vendor_step=stored_value#vendor-lowes">4. Build &amp; export My Lowe's Money transactions</a><a class="wizard-step <?=(($vendorStep==='match_dry_run')?'active':'')?>" href="?mode=lowes&vendor_step=match_dry_run#vendor-lowes">5a. Transaction Matching Dry Run</a><a class="wizard-step <?=(($vendorStep==='match_apply')?'active':'')?>" href="?mode=lowes&vendor_step=match_apply#vendor-lowes">5b. Apply Transaction Matching to GnuCash File</a></div><p class="small">These workflow buttons remain available from the review page, but Steps 4 and 5 are moving toward vendor-neutral payment export and transaction matching.</p><?php render_lowes_step5_controls($db, 'bills', (string)$vendorStep, 'data-import-account-mapping'); ?></div>
<?php endif; ?>
<?php if($vendorHint==='amazon'): ?>
<div class="card" id="data-import-account-mapping"><h3>2. Data Import</h3><p class="small"><strong>Import additional files on step 3 tab.</strong> The Review Bills tab is for category/account review and bill export. Amazon orders, items, and transaction-history CSVs should be imported from the Import Data tab before returning here.</p><p><a class="wizard-step" href="?mode=import_data#import-shared">Open Step 3 — Import Data</a></p></div>
<?php else: ?>
<details class="card collapsible-step" id="data-import-account-mapping" <?=count($allOrders)===0?'open':''?>><summary>2. Data Import</summary>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import"><input type="hidden" name="mode" value="import_data"><input type="hidden" name="vendor_hint" value="<?=h($vendorHint)?>"><input type="hidden" name="vendor_step" value="import"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><label>Vendor/export type:</label><select name="vendor"><option value="auto" <?=$vendorHint==='auto'?'selected':''?>>Auto-detect</option><option value="amazon" <?=$vendorHint==='amazon'?'selected':''?>>Amazon</option><option value="costco" <?=$vendorHint==='costco'?'selected':''?>>Costco</option><option value="walmart" <?=$vendorHint==='walmart'?'selected':''?>>Walmart</option><option value="lowes" <?=$vendorHint==='lowes'?'selected':''?>>Lowe's</option><option value="home_depot" <?=$vendorHint==='home_depot'?'selected':''?>>Home Depot</option><option value="tractor_supply" <?=$vendorHint==='tractor_supply'?'selected':''?>>Tractor Supply</option></select><label>Vendor export file(s): Costco receipt JSON; Walmart order CSV; Lowe's/TSC normalized ZIP:</label><input type="file" name="csv[]" accept=".csv,.json,.zip,text/csv,application/json,application/zip" multiple required><button type="submit">Import / update vendor rows</button>
</form>
<p class="small">After import, the tool returns to the Import Data tab. Continue to Review Bills &amp; Line Items when you are ready to categorize/export. For Lowe's scraper commands, ZIP creation, or direct normalized-folder import, use the <a href="?mode=lowes&vendor_step=scrape#lowes-scraper">Lowe's scraper</a> tab.</p>
</details>
<?php endif; ?>
<?php if($mode==='bills' && !empty($lowesPaymentMappings)): ?>
<div class="card" id="lowes-payment-mapping" style="background:#f8fbff;border-color:#8bb7e0;margin-top:.75rem"><h3>Payment method matching / other-entity export</h3><p class="small"><?=h($paymentMappingVendorLabel)?> imports may seed stored-value, gift-card, reward, and credit-card payment methods from normalized payment rows. Map each method to the GnuCash account where that payment transaction will be imported or already exists. These mappings support generic Step 5 transaction matching across vendors. Card accounts may be guessed from the last four digits in GnuCash account names when possible. Mark another-entity card as <strong>Exclude invoices</strong>; those invoices/credits can then be exported as a separate bill CSV for the other entity book.</p>
<form method="post"><input type="hidden" name="action" value="save_payment_mappings"><input type="hidden" name="mode" value="bills"><table><thead><tr><th>Exclude</th><th>Payment method</th><th>Invoice handling</th><th>GnuCash account</th><th>External note/account</th></tr></thead><tbody><?php foreach($lowesPaymentMappings as $pm): $id=$pm['vendor'].'|'.$pm['method_key']; ?><tr><td><input type="checkbox" name="pm_excluded[]" value="<?=h($id)?>" <?=((int)($pm['excluded']??0)===1?'checked':'')?>></td><td><?=h($pm['display_name'])?><input type="hidden" name="pm_vendor[]" value="<?=h($pm['vendor'])?>"><input type="hidden" name="pm_key[]" value="<?=h($pm['method_key'])?>"><input type="hidden" name="pm_name[]" value="<?=h($pm['display_name'])?>"></td><td><select name="pm_handling[]"><option value="normal" <?=(($pm['invoice_handling']??'normal')==='normal'?'selected':'')?>>Normal / this book</option><option value="exclude_invoice" <?=(($pm['invoice_handling']??'')==='exclude_invoice'?'selected':'')?>>Exclude invoices / other entity</option><option value="external_expense" <?=(($pm['invoice_handling']??'')==='external_expense'?'selected':'')?>>Keep bill here, map payment to external clearing</option></select></td><td><input list="payment_accounts" name="pm_account[]" value="<?=h($pm['account_fullname'])?>" style="min-width:28rem"></td><td><input list="payment_accounts" name="pm_external_account[]" value="<?=h($pm['external_account'] ?? '')?>" placeholder="optional clearing/account note" style="min-width:22rem"></td></tr><?php endforeach; ?></tbody></table><button type="submit">Save payment mappings</button></form>
<p class="small"><strong>What this export does:</strong> use <em>Export other-entity bill CSV</em> after marking one or more payment methods as <strong>Exclude invoices / other entity</strong>. It creates a separate GnuCash bill CSV for those invoices/credit memos so they can be imported into a different company/entity book, while keeping them out of the normal bill export for this book.</p><form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><input type="hidden" name="action" value="export_out_of_book_bill_csv"><input type="hidden" name="mode" value="bills"><input type="hidden" name="out_of_book_vendor" value="<?=h($paymentMappingVendor)?>"><label><input type="checkbox" name="post_invoices" value="1" <?=$exportPostInvoicesChecked?'checked':''?>> Post invoices</label><label>Post date format<select name="post_date_format"><option value="mdy_slash" <?=$storedPostDateFormat==='mdy_slash'?'selected':''?>>MM/DD/YYYY</option><option value="dmy_slash" <?=$storedPostDateFormat==='dmy_slash'?'selected':''?>>DD/MM/YYYY</option><option value="iso" <?=$storedPostDateFormat==='iso'?'selected':''?>>YYYY-MM-DD</option></select></label><label>Posting/AP account<input list="accounts" type="text" name="posting_account" value="<?=h($storedPostingAccount)?>"></label><button type="submit">Export other-entity bill CSV</button><span class="small"><?=count($lowesOutOfBookTargets)?> <?=h($paymentMappingVendorLabel)?> invoice/credit document(s) currently match excluded payment methods. This export includes skipped rows so they can be imported into the other book.</span></form><?php if($lastPostAction==='export_out_of_book_bill_csv') echo render_action_report_html($message, $error, $exportLinks, 'Other-entity export report', 'export-report'); ?></div>
<?php endif; ?>
<?php endif; ?>
<?php if($mode==='transactions'): ?>
<div class="card" id="payment-wizard">
<h2>Amazon payment application wizard</h2>
<p class="small">This wizard is intentionally conservative. It builds a review plan from Amazon transaction rows and the loaded GnuCash book, stops on missing bills/credit memos and exceptions, and only exposes a ready-to-apply export after validation is clean enough for a later GnuCash API dry run.</p><form method="get" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;margin:.5rem 0"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="<?=h($paymentWizardStep)?>"><label>Date sort<select name="date_sort"><option value="desc" <?=$dateSort==='desc'?'selected':''?>>Newest first</option><option value="asc" <?=$dateSort==='asc'?'selected':''?>>Oldest first</option></select></label><button type="submit">Apply sort</button><span class="small">Use oldest-first when working GnuCash reports top-down, newest-first when reconciling from recent activity.</span></form>
<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.5rem 0">
<form method="post" onsubmit="return confirm('Clear imported Amazon transaction/payment rows? Payment method account mappings and exclude flags will be preserved.');"><input type="hidden" name="action" value="reset_transaction_matching"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Soft reset transaction matching only</button></form>
<a class="wizard-step" href="?mode=bills&vendor_hint=amazon#review-top">Go to invoice/bill review</a>
</div>
<div class="wizard-steps"><?php $steps=['start'=>'1. Inputs / mapping','missing'=>'2. Missing invoices','exceptions'=>'3. Exceptions','ready'=>'4. Ready plan','apply'=>'5. Match/apply payments','credit'=>'6. Credit/refund matching']; foreach($steps as $k=>$label): $cls=$k===$paymentWizardStep?'active':($k===$paymentWizardRecommendedStep?'blocked':''); ?><span class="wizard-step <?=$cls?>"><?=h($label)?></span><?php endforeach; ?></div>
<p class="small"><strong>Recommended next step:</strong> <?=h($steps[$paymentWizardRecommendedStep] ?? 'Review')?>. Use the Continue/action buttons inside the current step; direct step-jumping is intentionally disabled. Status counts: <?php foreach($paymentStatusCounts as $st=>$ct): ?><span class="status-pill"><?=h($st)?>: <?=$ct?></span><?php endforeach; ?></p>
<?php if($paymentWizardStep==='start'): ?>
<h3>Step 1 — Inputs and payment mappings</h3>
<p>Load the Amazon order exports in <strong>Invoices / bills</strong>, then load the Amazon transaction history below. Save payment-method mappings and set business/out-of-book methods to <strong>Exclude invoices for this method</strong> before proceeding.</p>
<ul class="small"><li>Imported payment rows: <?=$paymentRowCount?></li><li>Staged invoices/bills in tool: <?=$stagedOrderCount?></li><li>Loaded GnuCash source: <?=h($accountStatus)?></li></ul>
<p><a class="wizard-step <?=($paymentWizardRecommendedStep==='missing'?'blocked':'')?>" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=missing#payment-wizard">Continue to missing-invoice validation</a></p>
<?php elseif($paymentWizardStep==='missing'): ?>
<h3>Step 2 — Missing invoices / credit memos</h3>
<?php if(empty($paymentMissingRows)): ?>
<p class="msg card">No missing invoices or credit memos are currently blocking the payment plan. Continue to exceptions.</p>
<p><a class="wizard-step ok" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=exceptions#payment-wizard">Continue to exceptions</a></p>
<?php else: ?>
<p class="warn">The payment plan found <?=count($paymentMissingRows)?> missing bill/credit target(s). Resolve skipped conflicts and invalid categories first, then export any staged/exportable missing invoices. Import the CSV into GnuCash using <em>Comma separated with quotes</em>, then rebuild/revalidate this step.</p>
<?php $hasSkippedDuplicate=false; foreach($paymentMissingRows as $mr){ if(($mr['staged_status']??'')==='staged_skipped' && stripos((string)($mr['staged_warning']??''),'Existing bill/invoice ID found')!==false) $hasSkippedDuplicate=true; } ?>
<?php if($hasSkippedDuplicate): ?><div class="card warn"><strong>Stale duplicate-skip conflict detected.</strong> Some targets are reported missing by the current GnuCash validation, but the staged bill was previously skipped because a bill ID was found. This usually means the staged skip flag is stale or came from a different/older GnuCash copy.<form method="post" style="margin-top:.5rem"><input type="hidden" name="action" value="restore_skipped_missing_invoice_targets"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="missing"><button type="submit">Restore duplicate-skipped missing targets to export queue</button></form></div><?php endif; ?>
<?php if(!empty($paymentMissingAccountRows)): ?>
<form method="post" style="margin:.75rem 0"><input type="hidden" name="action" value="save_missing_invoice_accounts"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="missing"><strong>Category review for missing-invoice export</strong><p class="small">GnuCash import will fail if any exported line uses a missing account such as <code>Expenses:Uncategorized</code>. Assign valid GnuCash accounts here before exporting the missing-invoice-only CSV.</p><?php if(!empty($paymentMissingInvalidAccountRows)): ?><p class="warn"><?=count($paymentMissingInvalidAccountRows)?> missing-invoice line(s) currently have blank/invalid accounts and will block export.</p><?php endif; ?><table><thead><tr><th>Target</th><th>Order</th><th>Description</th><th>Amount</th><th>Expense account</th><th>Notes</th></tr></thead><tbody><?php foreach($paymentMissingAccountRows as $ar): $key=(string)$ar['vendor'].'|'.(string)$ar['order_id'].'|'.(string)$ar['item_key']; $acct=(string)($ar['expense_account'] ?? ''); $bad=(export_account_review_issue($acct, load_valid_account_set((string)$gnucashPath)) !== ''); ?><tr<?=($bad?' style="background:#fff3cd"':'')?>><td><?=h((string)$ar['target_invoice_id'])?></td><td><?=order_links_html((string)$ar['vendor'], (string)$ar['order_id'], (string)($ar['order_url'] ?? ''), 25)?></td><td><?=h(clean_text((string)$ar['description'], 180))?></td><td>$<?=fmt_money((float)$ar['amount'])?></td><td><input list="accounts" name="missing_acct[<?=h($key)?>]" value="<?=h($acct)?>" style="width:100%"></td><td class="small"><?php if($bad): ?>Invalid/missing account.<?php endif; ?> <?=h(clean_text((string)($ar['notes'] ?? ''), 140))?></td></tr><?php endforeach; ?></tbody></table><button type="submit">Save missing-invoice category edits</button></form>
<?php endif; ?>
<form method="post" style="margin:.5rem 0"><input type="hidden" name="action" value="export_missing_invoice_bill_csv"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="missing"><button type="submit">Export missing-invoice-only bill CSV</button></form>
<table><thead><tr><th>Order date</th><th>Payment date</th><th>Target bill/credit</th><th>Order</th><th>Amount</th><th>Payment method</th><th>Staged state</th><th>Action</th></tr></thead><tbody><?php foreach($paymentMissingRows as $r): ?><tr><td><?=h((string)($r['order_date'] ?? ''))?></td><td><?=h((string)($r['payment_date'] ?? ''))?></td><td><?=h((string)($r['target_invoice_id'] ?? ''))?></td><td><?=order_links_html((string)($r['vendor'] ?? 'amazon'), (string)$r['order_id'], (string)($r['order_url'] ?? ''), 25)?></td><td>$<?=fmt_money(abs((float)($r['amount'] ?? 0)))?></td><td><?=h((string)$r['payment_method'])?></td><td><?=h((string)($r['staged_status'] ?? ''))?><?php if(!empty($r['staged_warning'])): ?><div class="small"><?=h((string)$r['staged_warning'])?></div><?php endif; ?></td><td class="small"><?php if(!empty($r['can_export_missing_invoice'])): ?>Ready to export if account validation passes.<?php elseif(($r['staged_status']??'')==='staged_skipped'): ?>Staged but skipped. Restore if this is a stale duplicate-skip, or review manually.<?php else: ?>Not staged/exportable. Upload/import the missing Amazon order/item export or mark out-of-scope.<?php endif; ?><form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h((string)($r['target_invoice_id'] ?? $r['order_id'] ?? ''))?>"><input type="hidden" name="exclude_reason" value="Missing/manual legacy invoice; exclude from transaction matching."><button type="submit">exclude target</button></form></td></tr><?php endforeach; ?></tbody></table>
<p class="small">After importing the missing-invoice CSV into GnuCash, reload this page or click the Missing step again. The wizard will move forward once these targets are no longer missing.</p>
<?php endif; ?>
<?php elseif($paymentWizardStep==='exceptions'): ?>
<h3>Step 3 — Resolve non-missing exceptions</h3>
<?php if(empty($paymentOtherExceptionRows)): ?><p class="msg card">No non-missing exceptions remain. Continue to ready payment plan.</p><p><a class="wizard-step ok" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=ready#payment-wizard">Continue to ready plan</a></p><?php else: ?><p class="warn"><?=count($paymentOtherExceptionRows)?> non-missing exception row(s) remain. Export the exception report and resolve/accept these before any API apply pass.</p><form method="post"><input type="hidden" name="action" value="export_payment_exception_report"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="exceptions"><button type="submit">Export exception/error report</button></form><table><thead><tr><th>Order date</th><th>Amazon payment date</th><th>GnuCash payment date</th><th>Order</th><th>Status</th><th>Payment method</th><th>Amount</th><th>Recommended action</th></tr></thead><tbody><?php foreach(array_slice($paymentOtherExceptionRows,0,75) as $r): ?><tr><td><?=h((string)($r['order_date'] ?? ''))?></td><td><?=h((string)($r['payment_date'] ?? ''))?></td><td><?=h((string)($r['gnucash_payment_date'] ?? ''))?></td><td><?=order_links_html((string)($r['vendor'] ?? 'amazon'), (string)$r['order_id'], (string)($r['order_url'] ?? ''), 25)?></td><td><?=h((string)$r['match_status'])?></td><td><?=h((string)$r['payment_method'])?></td><td>$<?=fmt_money(abs((float)$r['amount']))?></td><td class="small"><?=h((string)($r['recommended_action'] ?? $r['notes'] ?? 'Review'))?><form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h((string)($r['target_invoice_id'] ?? $r['order_id'] ?? ''))?>"><input type="hidden" name="exclude_reason" value="Manually reviewed/handled; exclude from transaction matching."><button type="submit">exclude target</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php elseif($paymentWizardStep==='ready'): ?>
<h3>Step 4 — Ready-to-apply payment plan</h3><p><?=count($paymentReadyRows)?> row(s) are currently classified as <code>ready_exact_payment</code> or <code>ready_split_payment_group</code>. Export this subset for the GnuCash dry-run/apply script.</p><form method="post"><input type="hidden" name="action" value="export_ready_payment_application_plan"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_wizard_step" value="ready"><button type="submit">Export ready-to-apply plan only</button></form><?php if($hasReadyPaymentPlan): ?><p class="small">Last exported plan: <code><?=h($lastReadyPaymentPlanName)?></code>, <?=h($lastReadyPaymentPlanCount)?> row(s), created <?=h($lastReadyPaymentPlanCreated)?>.</p><p><a class="wizard-step ok" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=apply#payment-wizard">Continue to payment match/apply prep</a></p><?php else: ?><p class="warn">Export the ready-to-apply plan before continuing to Step 5. This prevents the command block from using a placeholder or stale plan path.</p><?php endif; ?><p class="small">Rows already marked <code>already_applied_ok</code> are intentionally skipped. Rows with missing invoices, wrong/unverified accounts, unposted invoices, or amount mismatches are not included.</p>
<?php elseif($paymentWizardStep==='apply'): ?>
<h3>Step 5 — Match/apply existing payment transactions</h3><p>The write/apply phase is handled by <code>gnucash_payment_apply_v1.py</code>. In <?=h(APP_VERSION)?> the recommended command first tries to match existing imported bank/gift-card transactions and attach their offset split to the bill lot, instead of creating duplicate payment transactions.</p><?php if(!$hasReadyPaymentPlan): ?><p class="warn">No ready-payment plan has been exported in this browser tool session. Go back to Step 4 and export the ready plan first. Step 5 commands are intentionally hidden until a plan file exists.</p><p><a class="wizard-step" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=ready#payment-wizard">Return to Step 4 — Ready plan</a></p><?php else: ?><p class="small">Using last exported plan: <code><?=h($lastReadyPaymentPlanName)?></code>.</p><div class="card" style="background:#eef7ff;border-color:#1f6fb2;margin:.75rem 0">
<h4>Transaction match window adjustment</h4>
<p class="small">Use this when imported bank/card/stored-value register transactions post before or after the vendor payment date. The dry-run/apply matcher will look for existing payment transactions in the mapped account within this date window.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
<input type="hidden" name="action" value="save_payment_apply_match_window">
<input type="hidden" name="mode" value="transactions">
<input type="hidden" name="payment_wizard_step" value="apply">
<label>Days before transaction<input type="number" min="0" max="365" name="payment_match_before_days" value="<?=h((string)$paymentMatchWindowBeforeDays)?>" style="width:8rem"></label>
<label>Days after transaction<input type="number" min="0" max="365" name="payment_match_after_days" value="<?=h((string)$paymentMatchWindowAfterDays)?>" style="width:8rem"></label>
<button type="submit">Save match window</button>
<span class="small">Current search window: <strong><?=h(payment_apply_match_window_label($db))?></strong>.</span>
</form>
</div><h4>Dry-run command, match existing only</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($step5DryRunCommand)?></textarea><h4>Apply command, match existing only</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($step5ApplyCommand)?></textarea><?=render_changed_gnucash_download_section($db, 'amazon-step5-payment-apply', 'transactions', 'apply')?><ol class="small"><li>Run the dry-run first. The script prints a summary of matched rows, errors, and invoice groups; proceed only when it reports zero errors and says it is ready for apply.</li><li>These commands run against the uploaded <strong>copy</strong> of your primary GnuCash file.</li><li>Once the changes are applied, download the changed copy of your file and validate that all transactions were posted successfully.</li></ol><div class="card" style="background:#fffdf7;border-color:#e0d39c;margin:.75rem 0"><h4>Need to change payment mappings or transaction filters?</h4><p class="small">Use the match-window adjustment above for before/after date tolerance in the generated dry-run/apply commands. If you need to change payment-method mappings, exclusions, imported transaction files, or transaction date filters, return to Step 1, save/rebuild the scan, and return through Step 4 to export a fresh ready-to-apply plan. Step 5 command boxes intentionally use the last exported ready-plan filename.</p><p><a class="wizard-step" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=start#payment-wizard">Return to Step 1 — Inputs / mapping</a> <a class="wizard-step" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=ready#payment-wizard">Return to Step 4 — Ready plan</a></p></div><p><a class="wizard-step ok" href="?mode=transactions&date_sort=<?=urlencode($dateSort)?>&payment_wizard_step=credit#payment-wizard">Continue to credit/refund matching</a></p><?php endif; ?>
<?php elseif($paymentWizardStep==='credit'): ?>
<h3>Step 6 — CREDIT / refund matching</h3><p>This step is for <code>*-CREDIT</code> vendor credit bills. It does not create gift-card or credit-card refund transactions. First enter/import the refund activity into the appropriate GnuCash account, then run this matcher to attach matched existing refund splits to the credit bill lots.</p><h4>Dry-run command, match existing refunds only</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($step6CreditDryRunCommand)?></textarea><h4>Apply command, match existing refunds only</h4><textarea readonly spellcheck="false" class="command-box small"><?=h($step6CreditApplyCommand)?></textarea><?=render_changed_gnucash_download_section($db, 'amazon-step6-credit-refund-apply', 'transactions', 'credit')?><p class="small">In <?=h(APP_VERSION)?> refund matching uses a forward-only six-month window from the credit invoice date. Exact single refund matches are preferred; if none is safe, the script can match a small combination of unapplied refund transactions that sum to the credit amount. If no matching gift-card or credit-card refund transaction exists, the credit remains a review item instead of being auto-created. Run dry-run first and apply only when the summary reports zero errors/blockers.</p>
<?php endif; ?>
</div>
<?php if($paymentWizardStep !== 'apply'): ?><div class="card" style="background:#f8fbff;border-color:#8bb7e0"><h3>Payment matching — Amazon transaction history</h3><p class="small">Import the Amazon transaction-history CSV after importing bills. This stores one payment/refund row per Amazon order ID, maps payment methods to GnuCash accounts, and builds a read-only payment application plan from the loaded GnuCash book. It does not modify GnuCash directly.</p><form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:auto 1fr auto;gap:.5rem;align-items:end"><input type="hidden" name="action" value="import_amazon_transactions"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_wizard_step" value="start"><label>Amazon transaction history CSV(s)<input type="file" name="amazon_transactions[]" accept=".csv,text/csv" multiple required></label><span class="small">Select one or more CSVs. Expected columns: date, order ids, order urls, vendor, card_details, amount. These files can also be imported from the Import Data tab.</span><button type="submit">Import Amazon payment rows</button></form><form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;background:#fffdf7;border:1px solid #e0d39c;padding:.5rem;border-radius:6px"><input type="hidden" name="action" value="save_transaction_match_window"><input type="hidden" name="mode" value="transactions"><label>Payment date start<input type="date" name="payment_match_start_date" value="<?=h($paymentMatchStartDate)?>"></label><label>Payment date end<input type="date" name="payment_match_end_date" value="<?=h($paymentMatchEndDate)?>"></label><button type="submit">Save transaction date window</button><span class="small">Filters transaction matching reports/plans by Amazon payment date. Leave blank for open-ended. Soft reset and re-import to physically drop outside-window rows from the local review database.</span></form><form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;background:#f7fff7;border:1px solid #b7d7b7;padding:.5rem;border-radius:6px"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><label>Exclude order/target from matching<input type="text" name="target_invoice_id" placeholder="112-0190967-2405060" style="min-width:16rem"></label><label>Reason<input type="text" name="exclude_reason" value="Manually handled / out of transaction matching scope" style="min-width:24rem"></label><button type="submit">Add exclusion</button><span class="small"><?=count($paymentTargetExclusions)?> target exclusion(s) saved. Excluded targets are suppressed from exception/apply reports and shown in the excluded/out-of-book report.</span></form><form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><input type="hidden" name="action" value="save_stored_value_import_settings"><input type="hidden" name="mode" value="transactions"><label>Stored-value import offset account<input list="offset_accounts" type="text" name="stored_value_offset_account" value="<?=h($storedValueOffsetAccount)?>" style="min-width:28rem"></label><button type="submit">Save stored-value CSV settings</button><span class="small">Used only for optional GnuCash transaction-import CSVs. Gift Card, Visa/cash-back Rewards, and Prime for Young Adults cash back export as separate files because GnuCash imports one account/register file at a time. Review signs before importing to avoid duplicating later bill-payment application.</span></form><form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap"><input type="hidden" name="action" value="save_vendor_ledger_diff_settings"><input type="hidden" name="mode" value="transactions"><label style="display:flex;gap:.35rem;align-items:center"><input type="checkbox" name="suppress_zero_vendor_ledger_targets" value="1" <?=($suppressZeroVendorLedgerTargets?'checked':'')?>> Suppress zero-dollar/no-balance ledger targets</label><button type="submit">Save ledger diff settings</button><span class="small">Hides $0.00 Amazon orders/digital/no-charge rows from the active ledger-drift report.</span></form><?php if(!empty($paymentMappings)): ?><form method="post" style="margin-top:.75rem"><input type="hidden" name="action" value="save_payment_mappings"><input type="hidden" name="mode" value="transactions"><strong>Payment method account mapping</strong><table><thead><tr><th>Exclude</th><th>Vendor</th><th>Payment method</th><th>Invoice handling</th><th>GnuCash account</th><th>External expense / clearing account</th></tr></thead><tbody><?php foreach($paymentMappings as $pm): $pmId=(string)$pm['vendor'].'|'.(string)$pm['method_key']; ?><tr><td style="text-align:center"><input type="checkbox" name="pm_excluded[]" value="<?=h($pmId)?>" <?=((int)($pm['excluded'] ?? 0)===1?'checked':'')?>><div class="small">skip</div></td><td><?=h($pm['vendor'])?><input type="hidden" name="pm_vendor[]" value="<?=h($pm['vendor'])?>"><input type="hidden" name="pm_key[]" value="<?=h($pm['method_key'])?>"><input type="hidden" name="pm_name[]" value="<?=h($pm['display_name'])?>"></td><td><?=h($pm['display_name'])?><div class="small"><?=h($pm['method_key'])?></div></td><td><select name="pm_handling[]"><option value="normal" <?=((string)($pm['invoice_handling'] ?? 'normal')==='normal'?'selected':'')?>>Normal: match/pay bill</option><option value="exclude_invoice" <?=((string)($pm['invoice_handling'] ?? '')==='exclude_invoice' || (int)($pm['excluded'] ?? 0)===1?'selected':'')?>>Exclude invoices for this method</option><option value="external_expense" <?=((string)($pm['invoice_handling'] ?? '')==='external_expense'?'selected':'')?>>Keep invoice; map out-of-book</option></select><div class="small">Use exclude for business/out-of-book cards.</div></td><td><input list="payment_accounts" type="text" name="pm_account[]" value="<?=h($pm['account_fullname'])?>" style="width:100%"><div class="small">Payment account for normal methods.</div></td><td><input list="payment_accounts" type="text" name="pm_external_account[]" value="<?=h($pm['external_account'] ?? '')?>" style="width:100%"><div class="small">Optional expense/clearing account for out-of-book handling.</div></td></tr><?php endforeach; ?></tbody></table><button type="submit">Save payment mappings</button></form><?php endif; ?><?php if(!empty($paymentMappings)): ?>
<div class="card" id="payment-method-transaction-exports" style="background:#f8fbff;border-color:#8bb7e0;margin-top:.75rem">
<strong>Payment method transaction exports by mapped account</strong>
<p class="small">Use these when a mapped payment method needs its own GnuCash transaction-import CSV, such as Amazon Points, Amazon Gift Card, cash-back/rewards, or a vendor stored-value account. GnuCash imports one account/register at a time, so export and import each mapped account separately.</p>
<table><thead><tr><th>Vendor</th><th>Payment method</th><th>Mapped GnuCash account</th><th>Export</th></tr></thead><tbody>
<?php foreach($paymentMappings as $pm): $acct=trim((string)($pm['account_fullname'] ?? '')); $methodName=(string)($pm['display_name'] ?? $pm['method_key'] ?? ''); ?>
<tr><td><?=h((string)$pm['vendor'])?></td><td><?=h($methodName)?><div class="small"><?=h((string)$pm['method_key'])?></div></td><td><?=h($acct !== '' ? $acct : '(not mapped)')?></td><td><form method="post" style="margin:0;display:inline-flex;gap:.35rem;align-items:center"><input type="hidden" name="action" value="export_payment_method_transactions"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="<?=h((string)$pm['vendor'])?>"><input type="hidden" name="payment_method_key" value="<?=h((string)$pm['method_key'])?>"><input type="hidden" name="payment_method_name" value="<?=h($methodName)?>"><input type="hidden" name="payment_account" value="<?=h($acct)?>"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" <?=($acct===''?'disabled title="Save a mapped GnuCash account first"':'')?>>Export payment transactions</button></form></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?><div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:.75rem"><form method="post" onsubmit="return confirm('Clear imported Amazon transaction/payment rows? Payment method account mappings and exclude flags will be preserved.');"><input type="hidden" name="action" value="reset_transaction_matching"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Soft reset transaction matching only</button></form><form method="post"><input type="hidden" name="action" value="refresh_payment_scan"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Rebuild / refresh payment scan</button></form><form method="post"><input type="hidden" name="action" value="export_payment_matches"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export raw payment match CSV</button></form><a class="wizard-step" href="?mode=ledger&ledger_vendor=amazon&date_sort=<?=urlencode($dateSort)?>#ledger-audit">Open ledger audit</a><form method="post"><input type="hidden" name="action" value="export_payment_application_plan"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export payment application plan</button></form><form method="post"><input type="hidden" name="action" value="export_payment_exception_report"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Export exception/error report</button></form><form method="post"><input type="hidden" name="action" value="export_payment_excluded_invoice_report"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export excluded/out-of-book invoice report</button></form><span class="small"><?=$paymentRowCount?> imported Amazon payment row(s). Plan uses loaded GnuCash book read-only to check missing/unposted/paid invoices and mapped payment accounts.</span></div><?php if($mode==='transactions' && is_export_or_validation_action($lastPostAction)) echo render_action_report_html($message, $error, $exportLinks, 'Payment export/report result', 'payment-export-report'); ?><?php endif; ?>
<div class="card" id="amazon-order-payment-map">
<div class="mode-note"><strong>Ledger audit moved.</strong> The vendor ledger diff / duplicate audit now has its own tab so it can be run for any loaded vendor/plugin. <a href="?mode=ledger&ledger_vendor=amazon&date_sort=<?=urlencode($dateSort)?>#ledger-audit">Open Ledger audit</a>.</div>
<?php if(!empty($paymentExcludedInvoicePreview)): ?><details open><summary>Excluded / out-of-book invoice status</summary><p class="small">Excluded payment methods are suppressed from the payment exception report and are not candidates for payment automation. Posted matching GnuCash invoices are flagged here because they may still affect reports until unposted/removed; unposted matching invoices are informational only.</p><table><thead><tr><th>Order date</th><th>Amazon payment date</th><th>Order</th><th>Target</th><th>Payment method</th><th>Amount</th><th>GnuCash invoice status</th><th>Recommended action</th></tr></thead><tbody><?php foreach($paymentExcludedInvoicePreview as $ex): ?><tr><td><?=h($ex['order_date'] ?? '')?></td><td><?=h($ex['payment_date'] ?? '')?></td><td><?=order_links_html((string)($ex['vendor'] ?? 'amazon'), (string)$ex['order_id'], (string)($ex['order_url'] ?? ''), 25)?></td><td><?=h($ex['target_invoice_id'] ?? '')?></td><td><?=h($ex['payment_method'] ?? '')?></td><td>$<?=fmt_money(abs((float)($ex['amount'] ?? 0)))?></td><td><?=h($ex['posted_flag'] ?? $ex['match_status'] ?? '')?></td><td class="small"><?=h($ex['recommended_action'] ?? '')?><?php if(($ex['match_status'] ?? '')==='target_excluded_from_matching'): ?><form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="unexclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h((string)($ex['target_invoice_id'] ?? $ex['order_id'] ?? ''))?>"><button type="submit">re-enable</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?><?php if(!empty($primeYoungDiscrepancyRows)): ?>
<div class="card" style="background:#fff3cd;border:2px solid #d39e00;margin:1rem 0">
  <h3 style="margin-top:0">Highlighted payment-display discrepancy: Prime for Young Adults cash back</h3>
  <p class="small">Amazon sometimes shows “Prime for Young Adults cash back” on the order summary but still charges the full amount on the related transactions page and in the imported credit-card register. For these rows, payment matching should follow the actual transaction/register charge. Use the button below only after verifying Amazon’s related-transactions page shows the full card charge and no separate Prime Young stored-value payment.</p>
  <table><thead><tr><th>Order date</th><th>Order</th><th>Displayed grand total</th><th>Gross bill total</th><th>Displayed Prime Young amount</th><th>Current card rows</th><th>Synthetic Prime Young rows</th><th>Action</th></tr></thead><tbody>
  <?php foreach($primeYoungDiscrepancyRows as $py): ?>
    <tr>
      <td><?=h($py['order_date'])?></td>
      <td><?=order_links_html('amazon', (string)$py['order_id'], (string)$py['order_url'], 25)?></td>
      <td>$<?=fmt_money((float)$py['displayed_total'])?></td>
      <td>$<?=fmt_money((float)$py['gross_total'])?></td>
      <td>$<?=fmt_money((float)$py['cashback'])?></td>
      <td>$<?=fmt_money((float)$py['card_payment_total'])?></td>
      <td>$<?=fmt_money((float)$py['synthetic_prime_total'])?></td>
      <td class="small">
        <form method="post" onsubmit="return confirm('Use the full card charge for this order and remove synthetic Prime Young cash-back payment rows?');">
          <input type="hidden" name="action" value="resolve_prime_young_card_only">
          <input type="hidden" name="mode" value="transactions">
          <input type="hidden" name="payment_wizard_step" value="exceptions">
          <input type="hidden" name="order_id" value="<?=h((string)$py['order_id'])?>">
          <button type="submit" style="background:#ffe8a1;border:1px solid #b8860b;font-weight:bold">Use full card charge; ignore displayed Prime Young line</button>
        </form>
        <div class="small">Adds a discrepancy note to the staged invoice. If Amazon later corrects the error, handle that as a later refund/credit.</div>
        <form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h((string)$py['order_id'])?>"><input type="hidden" name="exclude_reason" value="Prime Young display/transaction mismatch manually reviewed; exclude from automated payment matching for now."><button type="submit">manual review / exclude target</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php if(!empty($paymentExceptionPreview)): ?><details open><summary>Payment exception / error report preview</summary><table><thead><tr><th>Order date</th><th>Amazon payment date</th><th>GnuCash payment date</th><th>Order</th><th>Target</th><th>Payment method</th><th>Amount</th><th>Status</th><th>Staged</th><th>Recommended action</th></tr></thead><tbody><?php foreach($paymentExceptionPreview as $ex): ?><tr><td><?=h($ex['order_date'] ?? '')?></td><td><?=h($ex['payment_date'] ?? '')?></td><td><?=h($ex['gnucash_payment_date'] ?? '')?></td><td><?=order_links_html((string)($ex['vendor'] ?? 'amazon'), (string)$ex['order_id'], (string)($ex['order_url'] ?? ''), 25)?></td><td><?=h($ex['target_invoice_id'] ?? '')?></td><td><?=h($ex['payment_method'])?></td><td>$<?=fmt_money((float)$ex['amount'])?></td><td><?=h($ex['match_status'])?></td><td><?=h($ex['staged_status'] ?? '')?></td><td class="small"><?=h($ex['recommended_action'] ?? '')?><form method="post" class="mini-form" style="margin-top:.25rem;background:#fff8dc;border:1px solid #d6b656;padding:.25rem"><input type="hidden" name="action" value="save_payment_allocation_override"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="<?=h((string)($ex['vendor'] ?? 'amazon'))?>"><input type="hidden" name="order_id" value="<?=h((string)($ex['order_id'] ?? ''))?>"><input type="hidden" name="payment_date" value="<?=h((string)($ex['payment_date'] ?? ''))?>"><input type="hidden" name="payment_method" value="<?=h((string)($ex['payment_method'] ?? ''))?>"><label>override amount $<input name="override_amount" value="<?=h(fmt_money(abs((float)($ex['amount'] ?? 0))))?>" style="width:6rem"></label><input type="hidden" name="override_note" value="Manual payment allocation override from exception handler; use for batched rewards, residual card-charge, or manual split-payment corrections."><button type="submit">save allocation override</button></form><form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h((string)($ex['target_invoice_id'] ?? $ex['order_id'] ?? ''))?>"><input type="hidden" name="exclude_reason" value="Manually reviewed/handled; exclude from transaction matching."><button type="submit">exclude target</button></form></td></tr><?php endforeach; ?></tbody></table><p class="small">This report is the cleanup queue: missing invoices/credit memos, already-paid wrong/unverified accounts, excluded/business invoices that already exist in GnuCash, unposted invoices, and amount mismatches.</p><p class="small"><strong>Manual allocation overrides:</strong> for a split-payment group, adjust every affected row to match the actual Amazon related-transactions page and the existing GnuCash register. This is intended for batched rewards/points rows and residual card charges. After saving overrides, rebuild/export the payment plan before running dry run.</p></details><?php endif; ?><?php if(!empty($paymentPlanPreview)): ?><details open><summary>Payment application plan preview</summary><table><thead><tr><th>Order date</th><th>Amazon payment date</th><th>GnuCash payment date</th><th>Order</th><th>Target bill/credit</th><th>Payment method</th><th>Amount</th><th>Mapped account</th><th>Status</th><th>Already-applied payment evidence</th></tr></thead><tbody><?php foreach($paymentPlanPreview as $pr): ?><tr<?=($pr['match_status']==='excluded_payment_method'?' style="opacity:.6"':'')?>><td><?=h($pr['order_date'] ?? '')?></td><td><?=h($pr['payment_date'] ?? '')?></td><td><?=h($pr['gnucash_payment_date'] ?? '')?></td><td><?=order_links_html((string)($pr['vendor'] ?? 'amazon'), (string)$pr['order_id'], (string)($pr['order_url'] ?? ''), 25)?></td><td><?=h($pr['target_invoice_id'] ?? '')?></td><td><?=h($pr['payment_method'])?></td><td>$<?=fmt_money((float)$pr['amount'])?></td><td><?=h($pr['account_fullname'])?></td><td><?=h($pr['match_status'])?><form method="post" class="mini-form" style="margin-top:.25rem;background:#fff8dc;border:1px solid #d6b656;padding:.25rem"><input type="hidden" name="action" value="save_payment_allocation_override"><input type="hidden" name="mode" value="transactions"><input type="hidden" name="payment_vendor" value="<?=h((string)($pr['vendor'] ?? 'amazon'))?>"><input type="hidden" name="order_id" value="<?=h((string)($pr['order_id'] ?? ''))?>"><input type="hidden" name="payment_date" value="<?=h((string)($pr['payment_date'] ?? ''))?>"><input type="hidden" name="payment_method" value="<?=h((string)($pr['payment_method'] ?? ''))?>"><label>override $<input name="override_amount" value="<?=h(fmt_money(abs((float)($pr['amount'] ?? 0))))?>" style="width:5.5rem"></label><input type="hidden" name="override_note" value="Manual payment allocation override from payment plan preview; use for batched rewards, residual card-charge, or manual split-payment corrections."><button type="submit">save</button></form></td><td class="small"><?=h($pr['applied_payment_summary'] ?? '')?></td></tr><?php endforeach; ?></tbody></table><p class="small">Statuses: <code>ready_exact_payment</code>/<code>ready_split_payment_group</code> are candidates for a later API apply pass; <code>already_applied_ok</code> should be skipped; <code>paid_wrong_or_unverified_account</code> needs review; <code>invoice_missing</code> often means the bill/credit memo has not been imported yet.</p></details><?php elseif(!empty($paymentMatchesPreview)): ?><details open><summary>Recent imported payment rows</summary><table><thead><tr><th>Date</th><th>Order</th><th>Payment method</th><th>Amount</th><th>Mapped account</th><th>Status</th></tr></thead><tbody><?php foreach($paymentMatchesPreview as $pr): ?><tr<?=($pr['match_status']==='excluded_payment_method'?' style="opacity:.6"':'')?>><td><?=h($pr['payment_date'])?></td><td><?=order_links_html((string)($pr['vendor'] ?? 'amazon'), (string)$pr['order_id'], (string)($pr['order_url'] ?? ''), 25)?></td><td><?=h($pr['payment_method'])?></td><td>$<?=fmt_money((float)$pr['amount'])?></td><td><?=h($pr['account_fullname'])?></td><td><?=h($pr['match_status'])?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?></div>
<?php endif; ?>
<?php if(in_array($mode, ['utility','repair','ledger','sanity'], true)): ?>
<div class="card" id="utility"><h2>Utility tools</h2>
<p class="small">These tools are intentionally kept while the vendor-specific modules are still being completed. They are not normal import steps for every vendor.</p>
<div class="wizard-steps"><a class="wizard-step <?=$mode==='utility'?'active':''?>" href="?mode=utility#utility">Overview</a><a class="wizard-step <?=$mode==='repair'?'active':''?>" href="?mode=repair&date_sort=<?=urlencode($dateSort)?>#invoice-repair-wizard">Invoice repair</a><a class="wizard-step <?=$mode==='ledger'?'active':''?>" href="?mode=ledger&ledger_vendor=<?=urlencode($ledgerVendor)?>#ledger-audit">Ledger audit</a><a class="wizard-step <?=$mode==='sanity'?'active':''?>" href="?mode=sanity#invoice-sanity">Sanity check</a></div>
<?php if($mode==='utility'): ?>
<div class="grid3"><div class="card"><h3>Invoice repair</h3><p class="small">Amazon-focused repair plan/dry-run/apply tooling. Keep this while payment and vendor modules are still being developed.</p><p><a class="wizard-step" href="?mode=repair&date_sort=<?=urlencode($dateSort)?>#invoice-repair-wizard">Open invoice repair</a></p></div><div class="card"><h3>Ledger audit</h3><p class="small">Audits staged invoices, imported payment rows, posted GnuCash bills/credits, and vendor-linked payments for any loaded vendor.</p><p><a class="wizard-step" href="?mode=ledger&ledger_vendor=<?=urlencode($ledgerVendor)?>#ledger-audit">Open ledger audit</a></p></div><div class="card"><h3>Sanity check</h3><p class="small">Focused Amazon invoice-total mismatch check. This will likely become vendor-scoped later.</p><p><a class="wizard-step" href="?mode=sanity#invoice-sanity">Open sanity check</a></p></div></div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if($mode==='ledger'): ?>
<div class="card" id="ledger-audit" style="background:#f8fbff;border-color:#8bb7e0">
<h2>Vendor ledger audit</h2>
<p class="small">Compares the selected vendor's loaded order/payment exports against the posted vendor bills, credit memos, and vendor-linked payments in the selected GnuCash copy. Use this to find missing documents, mismatched invoice totals, missing/extra payments, duplicate posted IDs, and ledger drift that prevents the vendor balance from returning to zero.</p>
<form method="get" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;margin:.75rem 0">
  <input type="hidden" name="mode" value="ledger">
  <label>Vendor/plugin
    <select name="ledger_vendor">
      <?php foreach($loadedLedgerVendors as $vv): ?><option value="<?=h($vv)?>" <?=$ledgerVendor===$vv?'selected':''?>><?=h(ucfirst($vv))?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Date sort <select name="date_sort"><option value="desc" <?=$dateSort==='desc'?'selected':''?>>Newest first</option><option value="asc" <?=$dateSort==='asc'?'selected':''?>>Oldest first</option></select></label>
  <button type="submit">Run ledger audit</button>
</form>
<div class="wizard-steps"><?php foreach($loadedLedgerVendors as $vv): ?><a class="wizard-step <?=$ledgerVendor===$vv?'active':''?>" href="?mode=ledger&ledger_vendor=<?=urlencode($vv)?>&date_sort=<?=urlencode($dateSort)?>#ledger-audit"><?=h(ucfirst($vv))?></a><?php endforeach; ?></div>
<form method="post" style="margin-top:.75rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
  <input type="hidden" name="action" value="save_vendor_ledger_diff_settings">
  <input type="hidden" name="mode" value="ledger">
  <input type="hidden" name="ledger_vendor" value="<?=h($ledgerVendor)?>">
  <label style="display:flex;gap:.35rem;align-items:center"><input type="checkbox" name="suppress_zero_vendor_ledger_targets" value="1" <?=($suppressZeroVendorLedgerTargets?'checked':'')?>> Suppress zero-dollar/no-balance ledger targets</label>
  <button type="submit">Save audit settings</button>
  <span class="small">Hides $0.00/no-balance rows from the active ledger audit.</span>
</form>
<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.75rem 0">
  <form method="post"><input type="hidden" name="action" value="refresh_payment_scan"><input type="hidden" name="mode" value="ledger"><input type="hidden" name="ledger_vendor" value="<?=h($ledgerVendor)?>"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Rebuild / refresh ledger audit</button></form>
  <form method="post"><input type="hidden" name="action" value="export_vendor_ledger_diff_report"><input type="hidden" name="mode" value="ledger"><input type="hidden" name="ledger_vendor" value="<?=h($ledgerVendor)?>"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export ledger audit CSV</button></form>
</div>
<?php if(!empty($vendorLedgerDiffCounts)): ?><div class="small"><strong>Status counts:</strong> <?php foreach($vendorLedgerDiffCounts as $st=>$ct): ?><code><?=h($st)?></code>: <?=$ct?> <?php endforeach; ?></div><?php endif; ?>
<?php if(empty($vendorLedgerDiffPreview)): ?><p class="ok">No ledger audit differences found in the preview set for <?=h(ucfirst($ledgerVendor))?>.</p><?php else: ?><table><thead><tr><th>Order date</th><th>Target</th><th>Status</th><th>Expected invoice</th><th>Book invoice</th><th>Expected payments</th><th>Book vendor payments</th><th>Methods / book accounts</th><th>Recommended action</th></tr></thead><tbody><?php foreach($vendorLedgerDiffPreview as $r): ?><tr><td><?=h((string)$r['order_date'])?><div class="small"><?=h((string)$r['payment_dates'])?></div></td><td><a href="?mode=bills&filter=all&search=<?=urlencode((string)$r['target_id'])?>&per_page=25#review-top"><?=h((string)$r['target_id'])?></a><div class="small"><?=h((string)$r['staged_status'])?>; book <?=h((string)$r['book_found'])?>/posted <?=h((string)$r['book_posted'])?></div></td><td><?=h((string)$r['status'])?></td><td>$<?=fmt_money((float)$r['expected_invoice_total'])?><div class="small"><?=h((string)($r['expected_invoice_basis'] ?? ''))?></div></td><td>$<?=fmt_money((float)$r['book_invoice_total'])?><div class="small">Δ $<?=fmt_money((float)$r['invoice_delta'])?></div></td><td>$<?=fmt_money((float)$r['expected_payment_total'])?></td><td>$<?=fmt_money((float)$r['book_payment_total'])?><div class="small">Δ $<?=fmt_money((float)$r['payment_delta'])?></div></td><td><div class="small"><?=h((string)$r['payment_methods'])?></div><div class="small"><?=h((string)$r['book_payment_accounts'])?></div></td><td class="small"><?=h((string)$r['recommended_action'])?><?php if(!empty($r['book_notes'])): ?><div><?=h(clean_text((string)$r['book_notes'],180))?></div><?php endif; ?><?php if(!empty($r['warning'])): ?><div class="warn"><?=h(dedupe_warning_text((string)$r['warning']))?></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php if($mode==='repair'): ?>
<div class="card" id="invoice-repair-wizard">
<h2>Amazon invoice repair wizard</h2>
<p class="small">Use this tab after the invoice CSV import has created GnuCash bills and after the current Amazon order/item/payment exports have been loaded into this tool. The repair wizard is for fixing already-imported Amazon bills whose GnuCash invoice total does not match Amazon's expected total. It also checks for missing Amazon <code>ORDERID-CREDIT</code> memos. Run this before payment matching and before credit/refund matching. It handles stored-value/rewards payments that were accidentally imported as discounts, missing gift-wrap lines, missing free-shipping discounts, safe inferred order-level adjustments, and missing/overstated CREDIT memo workflows. A normal base order bill must not be altered to become the refund credit; the credit memo is a separate <code>ORDERID-CREDIT</code> vendor document.</p>
<p class="warn"><strong>Safety rule:</strong> run the full sequence against a copied test book such as <code>Johnson2026.apitest.gnucash</code>. Keep GnuCash closed while running the Python apply script. Do not run Step 4 unless Step 3 reports zero blockers.</p>
<?php $repairSteps=['scan'=>'1. Refresh / inspect','plan'=>'2. Export repair plan','dryrun'=>'3. Dry run','apply'=>'4. Apply to test copy','validate'=>'5. Validate']; ?>
<div class="wizard-steps"><?php foreach($repairSteps as $label): ?><span class="wizard-step"><?=h($label)?></span><?php endforeach; ?></div>

<h3>Step 1 — Refresh scan and inspect mismatches</h3>
<p>This step does not write to GnuCash. It rebuilds the read-only comparison between staged Amazon data and the selected GnuCash book. Use it after importing 2026 order files, item files, or transaction history, or after changing the selected <code>.gnucash</code> file.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.75rem 0"><input type="hidden" name="action" value="refresh_payment_scan"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Rebuild / refresh invoice scan</button></form>
<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.75rem 0"><form method="post"><input type="hidden" name="action" value="export_invoice_sanity_report"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export invoice sanity-check report</button></form><form method="get" style="display:inline-flex;gap:.5rem;align-items:center"><input type="hidden" name="mode" value="repair"><label>Date sort <select name="date_sort"><option value="desc" <?=$dateSort==='desc'?'selected':''?>>Newest first</option><option value="asc" <?=$dateSort==='asc'?'selected':''?>>Oldest first</option></select></label><button type="submit">Apply</button></form></div>
<?php if(!empty($invoiceSanityCounts)): ?><p class="small"><strong>Current mismatch counts:</strong> <?php foreach($invoiceSanityCounts as $st=>$ct): ?><span class="status-pill"><?=h($st)?>: <?=h((string)$ct)?></span><?php endforeach; ?></p><?php endif; ?>
<?php if(!empty($repairMissingCreditRows)): ?>
<div class="card warn" style="margin:.75rem 0"><strong>Missing Amazon CREDIT memo target(s): <?=count($repairMissingCreditRows)?></strong><p class="small">These are expected refund/return credit memos staged as <code>ORDERID-CREDIT</code> but not found as exact invoice IDs in the selected GnuCash book. Do not repair the normal base order bill down to the refund amount. First review and categorize every staged credit memo line, then export/import the missing credit memo CSV, rebuild the scan, and only then proceed with invoice repairs. If Amazon itself reports the order as not found, use the ignore button to mark only that CREDIT memo target out-of-scope; the base order remains separate.</p>
<?php if(!empty($repairMissingCreditInvalidAccountRows)): ?><p class="warn"><strong>Export blocked:</strong> <?=count($repairMissingCreditInvalidAccountRows)?> missing-CREDIT line(s) still need review because their account is blank, <code>Expenses:Uncategorized</code>, or not present in the selected GnuCash account list. Use the category review table below; each row links back to the matching invoice review entry.</p><?php endif; ?>
<?php if(!empty($repairMissingCreditAccountRows)): ?>
<form method="post" style="margin:.75rem 0"><input type="hidden" name="action" value="save_missing_credit_memo_accounts"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><strong>Category review for missing CREDIT memo export</strong><p class="small">GnuCash skips the entire credit memo if any exported line references a missing account. Credit memo lines must be assigned to the same expense category as the returned/refunded item or to another valid reviewed account before export.</p><table><thead><tr><th>Target credit memo</th><th>Tool review</th><th>Description</th><th>Amount</th><th>Expense account</th><th>Notes</th></tr></thead><tbody><?php foreach($repairMissingCreditAccountRows as $ar): $key=(string)$ar['vendor'].'|'.(string)$ar['order_id'].'|'.(string)$ar['item_key']; $acct=(string)($ar['expense_account'] ?? ''); $issue=export_account_review_issue($acct, load_valid_account_set((string)$gnucashPath)); $bad=($issue !== ''); ?><tr<?=($bad?' style="background:#fff3cd"':'')?>><td><?=h((string)$ar['target_invoice_id'])?></td><td><?=order_links_html((string)$ar['vendor'], (string)$ar['order_id'], (string)($ar['order_url'] ?? ''), 25)?></td><td><?=h(clean_text((string)$ar['description'], 180))?></td><td>$<?=fmt_money((float)$ar['amount'])?></td><td><input list="accounts" name="missing_acct[<?=h($key)?>]" value="<?=h($acct)?>" style="width:100%"></td><td class="small"><?php if($bad): ?><strong><?=h($issue)?></strong>. <?php endif; ?><?=h(clean_text((string)($ar['notes'] ?? ''), 140))?></td></tr><?php endforeach; ?></tbody></table><button type="submit">Save missing-CREDIT category edits</button></form>
<?php endif; ?>
<form method="post" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end"><input type="hidden" name="action" value="export_missing_credit_memo_bill_csv"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><label>From date<input type="date" name="repair_from_date" value="<?=h($repairFromDateDefault)?>"></label><label>End date<input type="date" name="repair_to_date" value="<?=h($repairToDateDefault)?>"></label><button type="submit" <?=(!empty($repairMissingCreditInvalidAccountRows)?'disabled title="Review/category rows above before exporting"':'')?>>Export missing CREDIT memo import CSV</button></form><table style="margin-top:.5rem"><thead><tr><th>Order date</th><th>Credit target</th><th>Expected refund total</th><th>Staged state</th><th>Recommendation</th></tr></thead><tbody><?php foreach(array_slice($repairMissingCreditRows,0,25) as $cr): $crTarget=(string)($cr['target_invoice_id'] ?? $cr['target_id'] ?? ''); ?><tr><td><?=h((string)($cr['order_date'] ?? ''))?></td><td><?=order_links_html('amazon', $crTarget, '', 25)?></td><td>$<?=fmt_money((float)($cr['expected_invoice_total'] ?? 0.0))?></td><td><?=h((string)($cr['staged_status'] ?? ''))?><?php if(!empty($cr['staged_warning'])): ?><div class="small"><?=h(dedupe_warning_text((string)$cr['staged_warning']))?></div><?php endif; ?></td><td class="small"><?php if(($cr['staged_status'] ?? '')==='not_staged'): ?>Not staged/exportable. Upload the matching Amazon order/item export, or ignore if Amazon reports the order as not found/out-of-scope.<?php elseif(!empty($repairMissingCreditInvalidAccountRows)): ?>Review/category the staged credit memo line(s) above before exporting.<?php else: ?>Ready to export as a separate credit memo. The base order bill, if present, is the original purchase and should remain separate.<?php endif; ?><form method="post" class="mini-form" style="margin-top:.25rem"><input type="hidden" name="action" value="exclude_payment_target"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="payment_vendor" value="amazon"><input type="hidden" name="target_invoice_id" value="<?=h($crTarget)?>"><input type="hidden" name="exclude_reason" value="Amazon reports this CREDIT order as not found / out-of-scope; suppress missing credit memo only."><button type="submit">ignore this CREDIT target</button></form></td></tr><?php endforeach; ?></tbody></table><?php if(count($repairMissingCreditRows)>25): ?><p class="small">Showing first 25 missing credit memos.</p><?php endif; ?></div>
<?php endif; ?>

<h3>Step 2 — Export repair plan</h3>
<p>The repair plan is a CSV instruction file. It identifies which invoices or CREDIT memos appear repairable and labels each row as safe or manual review. This step still does not modify GnuCash. Review the suggested action column before moving on.</p>
<form method="post" enctype="multipart/form-data" autocomplete="off" style="display:grid;grid-template-columns:12rem 1fr;gap:.5rem;align-items:start">
<input type="hidden" name="action" value="export_amazon_invoice_repair_plan"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>">
<label>From date</label><input type="date" name="repair_from_date" value="<?=h($repairFromDateDefault)?>">
<label>End date</label><input type="date" name="repair_to_date" value="<?=h($repairToDateDefault)?>" placeholder="optional">
<label>Order ID filter</label><div><textarea name="repair_order_ids" rows="4" spellcheck="false" placeholder="Optional. Leave blank to export all repair candidates in the date range.&#10;For a first test, paste one Amazon order ID here."></textarea><div class="small">Use the filter for a single-order test or small batch. Use the all-candidates button only after single-order dry runs look correct.</div></div>
<label>Order ID text file</label><input type="file" name="repair_order_ids_file" accept=".txt,.csv,text/plain,text/csv">
<label>Paid invoices</label><label><input type="checkbox" name="repair_include_paid" value="1"> Include paid invoices in the plan for review only</label>
<span></span><div style="display:flex;gap:.5rem;flex-wrap:wrap"><button type="submit">Export filtered/single-order plan</button><button type="submit" name="repair_ignore_order_filter" value="1" style="background:#e8f6e8;border:2px solid #348a34;font-weight:bold">Export all candidates in date range</button></div>
</form>
<?php if($hasInvoiceRepairPlan): ?><p class="small">Last exported repair plan: <code><?=h($lastInvoiceRepairPlanName)?></code>, <?=h($lastInvoiceRepairPlanCount)?> row(s), created <?=h($lastInvoiceRepairPlanCreated)?>.</p><?php else: ?><p class="warn">No repair plan has been exported yet. Export a plan before running the dry run so the workflow has a checkpoint.</p><?php endif; ?>

<h3>Step 3 — Dry run the repair</h3>
<p>The dry run re-checks the selected GnuCash book and writes a result CSV. It does not modify GnuCash. Proceed to apply only when the dry-run summary says zero blockers and ready to proceed.</p>
<?php if(!$hasInvoiceRepairPlan): ?>
<p class="warn">Export a repair plan in Step 2 before running a dry run.</p>
<?php elseif(!$hasNonEmptyInvoiceRepairPlan): ?>
<p class="ok"><strong>No dry run needed.</strong> The last repair plan has 0 row(s), so its CSV contains only a header row and there is nothing to dry-run or apply. Continue with the sanity-check / missing-document validation instead.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" autocomplete="off" style="display:grid;grid-template-columns:12rem 1fr;gap:.5rem;align-items:start">
<input type="hidden" name="action" value="run_amazon_invoice_repair_dryrun"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>">
<label>From date</label><input type="date" name="repair_from_date" value="<?=h($repairFromDateDefault)?>">
<label>End date</label><input type="date" name="repair_to_date" value="<?=h($repairToDateDefault)?>" placeholder="optional">
<label>Order ID filter</label><div><textarea name="repair_order_ids" rows="4" spellcheck="false" placeholder="Use the same order filter as Step 2 for repeatability. Leave blank only for all candidates."></textarea><div class="small">For a first apply test, use exactly one order ID through Step 2 and Step 3. Then expand to a small batch, then all candidates.</div></div>
<label>Order ID text file</label><input type="file" name="repair_order_ids_file" accept=".txt,.csv,text/plain,text/csv">
<label>Paid invoices</label><label><input type="checkbox" name="repair_include_paid" value="1"> Include paid invoices for review only; they will still be skipped by apply</label>
<span></span><div style="display:flex;gap:.5rem;flex-wrap:wrap"><button type="submit" style="background:#eef6ff;border:2px solid #3273c7;font-weight:bold">Run filtered/single-order dry run</button><button type="submit" name="repair_ignore_order_filter" value="1">Run all candidates in date range</button></div>
</form>
<?php endif; ?>
<?php if($lastInvoiceRepairDryRunName !== ''): ?><p class="small">Last dry run: <code><?=h($lastInvoiceRepairDryRunName)?></code>, <?=h($lastInvoiceRepairDryRunCount)?> row(s), <?=h((string)$lastInvoiceRepairDryRunBlockers)?> blocker(s), created <?=h($lastInvoiceRepairDryRunCreated)?>.</p><?php endif; ?>

<h3>Step 4 — Apply repairs to the copied test book</h3>
<p>This step modifies the selected copied GnuCash book. It creates a same-directory backup first and then adjusts only rows that the script re-checks as safe. Paid invoices are skipped.</p>
<?php if(!$hasNonEmptyInvoiceRepairPlan): ?>
<p class="ok"><strong>No apply needed.</strong> The last repair plan has 0 row(s). There are no invoice repairs to apply from a header-only CSV.</p>
<?php elseif(!$hasCleanInvoiceRepairDryRun): ?>
<p class="warn">Apply is intentionally gated. Run Step 3 first and continue only when the last dry run reports zero blockers and at least one repair row.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" autocomplete="off" style="display:grid;grid-template-columns:12rem 1fr;gap:.5rem;align-items:start;border:1px solid #e1b5b5;background:#fff7f7;padding:.75rem">
<input type="hidden" name="action" value="run_amazon_invoice_repair_apply"><input type="hidden" name="mode" value="repair"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>">
<label>From date</label><input type="date" name="repair_from_date" value="<?=h($repairFromDateDefault)?>">
<label>End date</label><input type="date" name="repair_to_date" value="<?=h($repairToDateDefault)?>" placeholder="optional">
<label>Order ID filter</label><div><textarea name="repair_order_ids" rows="4" spellcheck="false" placeholder="Use the same filter that passed dry run. Leave blank only if the all-candidates dry run was clean."></textarea><div class="small">The apply script generates a fresh plan and revalidates the book before writing. Keep this filter aligned with the dry run you just reviewed.</div></div>
<label>Order ID text file</label><input type="file" name="repair_order_ids_file" accept=".txt,.csv,text/plain,text/csv">
<label>Confirmation</label><div><input type="text" name="repair_apply_confirm" placeholder="APPLY REPAIR TO COPY" style="width:20rem"><div class="small">Type <code>APPLY REPAIR TO COPY</code> exactly. Apply only to the copied test book.</div></div>
<span></span><button type="submit" style="background:#ffe4e1;border:2px solid #b22222;font-weight:bold">Apply filtered repair to selected copied book</button>
</form>
<?php endif; ?>

<h3>Step 5 — Validate after apply</h3>
<p>After apply, open the copied book in GnuCash and inspect the changed invoices. Then return here, refresh the scan, and rerun the invoice sanity-check report. Expected result: repaired invoice totals disappear from the mismatch queue, and the vendor report shows the corrected bill totals.</p>
<?php if(empty($invoiceSanityPreview)): ?><p class="ok"><strong>No posted Amazon invoice total mismatches found</strong> using the currently loaded order/payment data and GnuCash copy.</p><?php else: ?>
<table><thead><tr><th>Order date</th><th>Target invoice</th><th>Issue</th><th>Expected total</th><th>GnuCash invoice total</th><th>Δ book - expected</th><th>Expected basis</th><th>Payment methods</th><th>Recommended action</th></tr></thead><tbody>
<?php foreach($invoiceSanityPreview as $sr): $delta=(float)($sr['invoice_delta'] ?? 0.0); ?><tr class="<?=($sr['sanity_status']==='possible_stored_value_imported_as_discount'?'orderpay-mismatch':'')?>"><td><?=h((string)($sr['order_date'] ?? ''))?></td><td><?=order_links_html('amazon', (string)$sr['target_id'], '', 25)?><div class="small">GnuCash GUID: <?=h((string)($sr['book_invoice_guid'] ?? ''))?></div></td><td><?=h((string)($sr['sanity_status'] ?? ''))?></td><td>$<?=fmt_money((float)($sr['expected_invoice_total'] ?? 0.0))?></td><td>$<?=fmt_money((float)($sr['book_invoice_total'] ?? 0.0))?></td><td class="<?=abs($delta)>=0.01?'warn':''?>">$<?=fmt_money($delta)?></td><td><?=h((string)($sr['expected_invoice_basis'] ?? ''))?></td><td class="small"><?=h((string)($sr['payment_methods'] ?? ''))?></td><td class="small"><?=h((string)($sr['sanity_recommended_action'] ?? ''))?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if(count($invoiceSanityRowsAll) > count($invoiceSanityPreview)): ?><p class="small">Showing first <?=count($invoiceSanityPreview)?> of <?=count($invoiceSanityRowsAll)?> mismatched invoices.</p><?php endif; ?>
<?php endif; ?>
<?php $lastConsole = get_config($db, 'last_repair_console', ''); if($lastConsole !== ''): ?><details open style="margin-top:.75rem"><summary><?=h(get_config($db, 'last_repair_console_title', 'Last invoice repair apply console'))?></summary><textarea readonly spellcheck="false" class="command-box small"><?=h($lastConsole)?></textarea></details><?php endif; ?>
</div>
<?php endif; ?>
<?php if($mode==='sanity'): ?>
<div class="card" id="invoice-sanity">
<h2>Amazon invoice sanity check</h2>
<p class="small">This focused table finds posted Amazon invoices in the loaded GnuCash copy whose invoice total does not match the expected Amazon total from loaded order exports and transaction-history payment rows. Use the separate <a href="?mode=repair&date_sort=<?=urlencode($dateSort)?>#invoice-repair-wizard">Invoice repair wizard</a> to generate repair plans, dry runs, and apply actions.</p>
<form method="post" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.75rem 0"><input type="hidden" name="action" value="refresh_payment_scan"><input type="hidden" name="mode" value="sanity"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit" style="background:#cfe8ff;border:2px solid #1f6fb2;font-weight:bold">Rebuild / refresh scan</button></form>
<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin:.75rem 0"><form method="post"><input type="hidden" name="action" value="export_invoice_sanity_report"><input type="hidden" name="mode" value="sanity"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><button type="submit">Export invoice sanity-check report</button></form><form method="get" style="display:inline-flex;gap:.5rem;align-items:center"><input type="hidden" name="mode" value="sanity"><label>Date sort <select name="date_sort"><option value="desc" <?=$dateSort==='desc'?'selected':''?>>Newest first</option><option value="asc" <?=$dateSort==='asc'?'selected':''?>>Oldest first</option></select></label><button type="submit">Apply</button></form></div>
<?php if(!empty($invoiceSanityCounts)): ?><p class="small"><strong>Issue counts:</strong> <?php foreach($invoiceSanityCounts as $st=>$ct): ?><span class="status-pill"><?=h($st)?>: <?=h((string)$ct)?></span><?php endforeach; ?></p><?php endif; ?>
<?php if(empty($invoiceSanityPreview)): ?><p class="small"><strong>No posted Amazon invoice total mismatches found</strong> using the currently loaded order/payment data and GnuCash copy.</p><?php else: ?>
<table><thead><tr><th>Order date</th><th>Target invoice</th><th>Issue</th><th>Expected total</th><th>GnuCash invoice total</th><th>Δ book - expected</th><th>Expected basis</th><th>Payment methods</th><th>Recommended action</th></tr></thead><tbody>
<?php foreach($invoiceSanityPreview as $sr): $delta=(float)($sr['invoice_delta'] ?? 0.0); ?><tr class="<?=($sr['sanity_status']==='possible_stored_value_imported_as_discount'?'orderpay-mismatch':'')?>"><td><?=h((string)($sr['order_date'] ?? ''))?></td><td><?=order_links_html('amazon', (string)$sr['target_id'], '', 25)?><div class="small">GnuCash GUID: <?=h((string)($sr['book_invoice_guid'] ?? ''))?></div></td><td><?=h((string)($sr['sanity_status'] ?? ''))?></td><td>$<?=fmt_money((float)($sr['expected_invoice_total'] ?? 0.0))?></td><td>$<?=fmt_money((float)($sr['book_invoice_total'] ?? 0.0))?></td><td class="<?=abs($delta)>=0.01?'warn':''?>">$<?=fmt_money($delta)?></td><td><?=h((string)($sr['expected_invoice_basis'] ?? ''))?></td><td class="small"><?=h((string)($sr['payment_methods'] ?? ''))?></td><td class="small"><?=h((string)($sr['sanity_recommended_action'] ?? ''))?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if(count($invoiceSanityRowsAll) > count($invoiceSanityPreview)): ?><p class="small">Showing first <?=count($invoiceSanityPreview)?> of <?=count($invoiceSanityRowsAll)?> mismatched invoices.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if($mode==='bills'): ?>
<p class="small"><strong>GnuCash import setting:</strong> choose <em>Comma separated with quotes</em>; the default comma-separated mode can misread quoted account names/descriptions and ignore valid rows. Recommended for Amazon: import the order-level CSV first for tax/shipping/payment/refund fields, then import the item-level CSV or JSON for per-item bill lines. Costco JSON receipts import as Costco vendor bills with item numbers stored as [SKU:...]. Vendor IDs are configurable on the Instructions/config tab. Current active IDs: Amazon <?=h(default_config_value('DEFAULT_VENDOR_AMAZON', $db))?>, Costco <?=h(default_config_value('DEFAULT_VENDOR_COSTCO', $db))?>, Walmart <?=h(default_config_value('DEFAULT_VENDOR_WALMART', $db))?>, Lowe's <?=h(default_config_value('DEFAULT_VENDOR_LOWES', $db))?>. Sales tax account: <?=h(default_config_value('DEFAULT_TAX_ACCOUNT', $db))?>.</p>
<hr>
<form method="post" onsubmit="return confirm('Soft reset active review rows and start a fresh import while preserving learned SKU/ASIN category mappings?');" style="display:grid;grid-template-columns:auto 12rem 1fr;gap:.5rem;align-items:end; margin-bottom:.5rem">
<input type="hidden" name="action" value="soft_reset_new_import"><input type="hidden" name="mode" value="bills"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit" style="background:#d1ecf1;border:1px solid #0c5460">Soft reset / fresh import, keep mappings</button><label>Next vendor<select name="next_vendor"><option value="auto" <?=$vendorHint==='auto'?'selected':''?>>Auto-detect</option><option value="amazon" <?=$vendorHint==='amazon'?'selected':''?>>Amazon</option><option value="costco" <?=$vendorHint==='costco'?'selected':''?>>Costco</option><option value="walmart" <?=$vendorHint==='walmart'?'selected':''?>>Walmart</option><option value="lowes" <?=$vendorHint==='lowes'?'selected':''?>>Lowe's</option><option value="home_depot" <?=$vendorHint==='home_depot'?'selected':''?>>Home Depot</option><option value="tractor_supply" <?=$vendorHint==='tractor_supply'?'selected':''?>>Tractor Supply</option></select></label><span class="small">Recommended when re-importing Costco/Lowe's after parser changes. Clears pending invoices/items and validation state, but preserves SKU/ASIN learned category mappings, GnuCash path, local accounts, and export settings.</span></form>
<form method="post" onsubmit="return confirm('Flush all staged order/item working data and switch to a clean import screen? This keeps the GnuCash account path/settings and learned account rules, but removes imported orders, item lines, review edits, skip flags, warnings, and the last validation report.');" style="display:grid;grid-template-columns:auto 12rem 1fr;gap:.5rem;align-items:end">
<input type="hidden" name="action" value="new_dataset"><input type="hidden" name="mode" value="bills"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit" style="background:#fff3cd;border:1px solid #d39e00">Start a new dataset / hard reset review</button><label>Next vendor<select name="next_vendor"><option value="auto" <?=$vendorHint==='auto'?'selected':''?>>Auto-detect</option><option value="amazon" <?=$vendorHint==='amazon'?'selected':''?>>Amazon</option><option value="costco" <?=$vendorHint==='costco'?'selected':''?>>Costco</option><option value="walmart" <?=$vendorHint==='walmart'?'selected':''?>>Walmart</option><option value="lowes" <?=$vendorHint==='lowes'?'selected':''?>>Lowe's</option><option value="home_depot" <?=$vendorHint==='home_depot'?'selected':''?>>Home Depot</option><option value="tractor_supply" <?=$vendorHint==='tractor_supply'?'selected':''?>>Tractor Supply</option></select></label><span class="small">Use this when switching vendors or starting over. It clears the active review rows and redirects to a clean page so old search/filter/page state cannot keep showing the prior dataset. Learned account rules are kept for future matching.</span></form>
<form method="post" onsubmit="return confirm('Hard clear staged review data? This deletes active orders/items and last validation state, but keeps settings and learned rules.');" style="margin-top:.5rem"><input type="hidden" name="action" value="reset_working_data"><input type="hidden" name="mode" value="bills"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><input type="hidden" name="next_vendor" value="auto"><button type="submit">Hard reset review database only</button></form></div>
<div class="card" id="review-top"><h2>3. Review Bills and Line Items</h2><p><?=count($allOrders)?> orders staged. <?=$exportableOrderCount?> exportable/non-skipped. <?=$skippedOrderCount?> skipped. <?=$totalItemLines?> item lines staged. <?=$warnings?> orders have warnings. Showing orders <?=$displayStart?>-<?=$displayEnd?> of <?=$totalFiltered?> matching orders, page <?=$page?> of <?=$totalPages?>.</p><?php if(count($allOrders)===0): ?><p class="small"><strong>No active review dataset is loaded.</strong> Import an Amazon, Costco, Walmart, or Lowe's export above. If you just switched vendors, this is the expected clean state.</p><?php endif; ?>
<?php if($skippedOrderCount>0): $skipPreview=skipped_orders_preview($db, 75); ?>
<div class="card" style="background:#fffdf7;border-color:#e0c36a"><strong>Skipped orders review</strong><p class="small">Skipped orders are not exported. Reasons are usually duplicate detection against the loaded GnuCash book, manual skip flags, or older import state. Click an order ID to load it in review. Select orders below and click restore to add them back into processing.</p>
<form method="post"><input type="hidden" name="action" value="unskip_selected"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><div style="max-height:16rem;overflow:auto"><table class="skipped-orders-table"><thead><tr><th>Restore</th><th>Date</th><th>Vendor</th><th>Order/receipt</th><th class="amount-col">Total</th><th>Reason</th></tr></thead><tbody>
<?php foreach($skipPreview as $sr): $skey=$sr['vendor'].'|'.$sr['order_id']; $surl='?filter=skipped&search='.rawurlencode($sr['order_id']).'&per_page='.rawurlencode((string)$perPage); ?>
<tr><td><input type="checkbox" name="unskip_order[]" value="<?=h($skey)?>"></td><td><?=h($sr['order_date'])?></td><td><?=h($sr['vendor'])?></td><td><a href="<?=h($surl)?>"><?=h($sr['order_id'])?></a></td><td class="amount-col">$<?=fmt_money((float)$sr['total'])?></td><td class="small"><?=h(clean_text($sr['reason'],220))?></td></tr>
<?php endforeach; ?>
</tbody></table></div><p><button type="submit">Restore selected skipped orders</button> <a href="?filter=skipped&per_page=25">Open skipped-orders review filter</a><?php if($skippedOrderCount>count($skipPreview)): ?> <span class="small">Showing first <?=count($skipPreview)?> of <?=$skippedOrderCount?> skipped orders.</span><?php endif; ?></p></form></div>
<?php endif; ?>
<form method="get" style="display:grid; grid-template-columns: 1fr 10rem 8rem 9rem 13rem auto; gap:.5rem; align-items:end; margin-bottom:1rem"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><input type="hidden" name="mode" value="bills"><label>Search/order/item/key<input type="text" name="search" value="<?=h($search)?>"></label><label>Filter<select name="filter"><option value="all" <?= $filter==='all'?'selected':'' ?>>All staged</option><option value="exportable" <?= $filter==='exportable'?'selected':'' ?>>Exportable / non-skipped</option><option value="skipped" <?= $filter==='skipped'?'selected':'' ?>>Skipped</option><option value="warnings" <?= $filter==='warnings'?'selected':'' ?>>Warnings</option><option value="zero_price" <?= $filter==='zero_price'?'selected':'' ?>>Zero-price lines</option><option value="multi_item" <?= $filter==='multi_item'?'selected':'' ?>>Multi-item orders</option></select></label><label>Per page<input type="number" name="per_page" value="<?=h((string)$perPage)?>" min="5" max="100"></label><label>Date sort<select name="date_sort"><option value="desc" <?=$dateSort==='desc'?'selected':''?>>Newest first</option><option value="asc" <?=$dateSort==='asc'?'selected':''?>>Oldest first</option></select></label><label><input type="checkbox" name="show_skipped" value="1" <?=$showSkippedInAll?'checked':''?>> Show skipped in All</label><button type="submit">Apply</button></form>
<p><strong>Displaying <?=$displayStart?>-<?=$displayEnd?> of <?=$totalFiltered?> orders</strong> &nbsp; <a href="?gnucash_path=<?=urlencode((string)$gnucashPath)?>&search=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>&show_skipped=<?=($showSkippedInAll?'1':'0')?>&date_sort=<?=urlencode($dateSort)?>&per_page=<?=$perPage?>&page=<?=max(1,$page-1)?>#review-top">Previous</a> | <a href="?gnucash_path=<?=urlencode((string)$gnucashPath)?>&search=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>&show_skipped=<?=($showSkippedInAll?'1':'0')?>&date_sort=<?=urlencode($dateSort)?>&per_page=<?=$perPage?>&page=<?=min($totalPages,$page+1)?>#review-top">Next</a><?php if($filter==='skipped'): ?> | <a class="wizard-step" href="?mode=bills&gnucash_path=<?=urlencode((string)$gnucashPath)?>&vendor_hint=<?=urlencode($vendorHint)?>&filter=exportable&show_skipped=0&date_sort=<?=urlencode($dateSort)?>&per_page=<?=$perPage?>#review-top">Exit skipped-orders review</a><?php endif; ?></p>
<div class="card export-controls" id="export-controls" style="background:#fbfbfb; margin:.5rem 0 1rem 0"><strong>Batch export</strong><div class="small">For GnuCash import, select <em>Comma separated with quotes</em>. Batch export includes only non-skipped/exportable invoices. Optional posting fills date_posted/due_date/account_posted so bills are posted during import; leave off for manual review. All supported vendor-plugin rows are posted automatically because unposted vendor bills otherwise look missing in GnuCash and cannot be used by payment workflows. Your current working set has <?=$stagedOrderCount?> staged invoices, <?=$skippedOrderCount?> skipped, and <?=$exportableOrderCount?> exportable.</div>
<form method="post" style="display:grid; grid-template-columns: auto auto 9rem 9rem 13rem 11rem 12rem 18rem 1fr; gap:.5rem; align-items:end; margin-top:.5rem">
  <input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>">
  <button type="submit" name="action" value="validate_export">Validate selected batch</button>
  <button type="submit" name="action" value="export">Export selected batch</button>
  <label>Invoices per batch<input type="number" name="batch_size" value="<?=h((string)$storedBatchSize)?>" min="1" max="1000"></label>
  <label>Batch #<input type="number" name="batch_number" value="<?=h((string)$selectedBatchNumber)?>" min="1" max="<?=$storedTotalBatches?>"></label>
  <label>Export account prefix<input type="text" name="account_prefix" value="<?=h($storedAccountPrefix)?>" placeholder="leave blank for GnuCash"></label>
  <label><input type="checkbox" name="post_invoices" value="1" <?=$exportPostInvoicesChecked?'checked':''?>> Post invoices</label>
  <label>Post date format<select name="post_date_format"><option value="mdy_slash" <?=$storedPostDateFormat==='mdy_slash'?'selected':''?>>MM/DD/YYYY</option><option value="dmy_slash" <?=$storedPostDateFormat==='dmy_slash'?'selected':''?>>DD/MM/YYYY</option><option value="iso" <?=$storedPostDateFormat==='iso'?'selected':''?>>YYYY-MM-DD</option></select></label>
  <label>Posting/AP account<input list="accounts" type="text" name="posting_account" value="<?=h($storedPostingAccount)?>"></label>
  <span class="small"><?=$exportableOrderCount?> exportable/non-skipped invoices out of <?=$stagedOrderCount?> staged; <?=$skippedOrderCount?> skipped. <?=$storedTotalBatches?> batch(es) at <?=$storedBatchSize?> each. Leave account prefix blank; Root Account: is rejected by GnuCash bill import. Date format: MM/DD/YYYY worked in testing.</span>
</form>
<?php if(in_array($lastPostAction, ['validate_export','export'], true)) echo render_action_report_html($message, $error, $exportLinks, 'Selected batch export report', 'export-report'); ?>
<form method="post" style="display:grid; grid-template-columns: auto 9rem 9rem 9rem 13rem 11rem 12rem 18rem 1fr; gap:.5rem; align-items:end; margin-top:.5rem">
  <input type="hidden" name="action" value="export_range"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>">
  <button type="submit">Create batch files for range</button>
  <label>Invoices per batch<input type="number" name="batch_size" value="<?=h((string)$storedBatchSize)?>" min="1" max="1000"></label>
  <label>Start batch<input type="number" name="range_start" value="1" min="1" max="<?=$storedTotalBatches?>"></label>
  <label>End batch<input type="number" name="range_end" value="<?=$storedTotalBatches?>" min="1" max="<?=$storedTotalBatches?>"></label>
  <label>Export account prefix<input type="text" name="account_prefix" value="<?=h($storedAccountPrefix)?>" placeholder="leave blank for GnuCash"></label>
  <label><input type="checkbox" name="post_invoices" value="1" <?=$exportPostInvoicesChecked?'checked':''?>> Post invoices</label>
  <label>Post date format<select name="post_date_format"><option value="mdy_slash" <?=$storedPostDateFormat==='mdy_slash'?'selected':''?>>MM/DD/YYYY</option><option value="dmy_slash" <?=$storedPostDateFormat==='dmy_slash'?'selected':''?>>DD/MM/YYYY</option><option value="iso" <?=$storedPostDateFormat==='iso'?'selected':''?>>YYYY-MM-DD</option></select></label>
  <label>Posting/AP account<input list="accounts" type="text" name="posting_account" value="<?=h($storedPostingAccount)?>"></label>
  <span class="small">Creates separate CSV files with download links. This avoids forcing multiple downloads in the browser.</span>
</form>
<?php if($lastPostAction==='export_range') echo render_action_report_html($message, $error, $exportLinks, 'Batch range export report', 'export-report'); ?>
<?=export_batch_summary_html($selectedBatchOrders, $selectedBatchNumber, $storedBatchSize, $exportableOrderCount)?>
</div>
<form method="post" id="reviewForm"><input type="hidden" name="action" value="save"><input type="hidden" name="mode" value="bills"><input type="hidden" name="vendor_hint" value="<?=h($vendorHint)?>"><input type="hidden" name="vendor_step" value="review"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><input type="hidden" name="page" value="<?=$page?>"><input type="hidden" name="per_page" value="<?=$perPage?>"><input type="hidden" name="filter" value="<?=h($filter)?>"><input type="hidden" name="search" value="<?=h($search)?>"><input type="hidden" name="date_sort" value="<?=h($dateSort)?>"><input type="hidden" name="show_skipped" value="<?=($showSkippedInAll?'1':'0')?>"><p><button type="submit">Save review edits on this page</button> <button type="button" id="ajaxSaveBtn">Save without refresh</button> <button type="submit" name="recat_recent_manual" value="1" data-anchor="#review-top" title="Use all account fields manually changed since the last bulk apply as reference rules, then fill blank/Uncategorized matching rows">Apply changed categories since last bulk apply</button> <span id="autosaveStatus" class="small">Autosave ready</span></p>
<div id="review-top" class="resize-help">Tip: drag the right edge of an item-table column header to resize columns. Double-click a resize handle to reset the item columns. Widths are saved in this browser. Use <strong>Apply same SKU/ASIN</strong> to force the selected category onto all unlocked exact matching SKU/ASIN rows; it also updates pending rows with the same normalized product title. Discount/reconciliation rows are exempt from global SKU/ASIN propagation and instead follow a categorized item in their own invoice, defaulting to the highest-value item when ambiguous. Use the invoice header controls to save while staying near that invoice, or to set every item line on that invoice to one category without creating global product rules. Use <strong>Use invoice as category reference</strong> to propagate all categorized SKUs/ASINs from that invoice. Use <strong>Apply changed categories since last bulk apply</strong> after manually changing several account fields; it will train rules from those edits and fill blank/Uncategorized matching rows. Locked rows are not changed by automatic propagation.</div>
<div class="category-legend small"><span class="category-chip"><span class="category-swatch uncat"></span>Needs category / Expenses:Uncategorized</span><span class="category-chip"><span class="category-swatch cat"></span>Reviewed valid category</span><span class="category-chip"><span class="category-swatch invalid"></span>Non-empty but not in loaded account list</span></div>
<div class="small" style="margin:.5rem 0">Debug: this page has <?=count($orders)?> orders selected after pagination. Item counts for first orders: <?php $dbg=[]; foreach(array_slice($orders,0,8) as $dr){$dk=$dr['vendor'].'|'.$dr['order_id']; $dbg[]=$dr['order_id'].':'.count($items[$dk]??[]);} echo h(implode(', ', $dbg)); ?></div>
<?php foreach($orders as $r): $okey=$r['vendor'].'|'.$r['order_id']; $its=$items[$okey]??[]; $itSum=$itemTotals[$okey]['sum']??0; $itCount=$itemTotals[$okey]['count']??0; ?>
  <div class="ordercard" id="<?=h(anchor_id('order', (string)$r['vendor'], (string)$r['order_id']))?>">
    <div class="orderhead">
      <label><input type="checkbox" name="order[<?=h($okey)?>][skip]" <?=((int)$r['skip']?'checked':'')?>> Skip</label>
      <label><input type="checkbox" name="order[<?=h($okey)?>][locked]" <?=((int)($r['locked'] ?? 0)?'checked':'')?>> Lock invoice</label>
      <button type="submit" name="save_order" value="<?=h($okey)?>" data-anchor="#<?=h(anchor_id('order', (string)$r['vendor'], (string)$r['order_id']))?>" title="Save review edits and stay near this invoice">Save this invoice</button>
      <div class="invoice-actions">
        <label><span class="small">Set all item categories on this invoice</span><br><input list="accounts" type="text" name="order[<?=h($okey)?>][bulk_expense_account]" placeholder="Expenses:..."></label>
        <button type="submit" name="set_invoice_category" value="<?=h($okey)?>" data-anchor="#<?=h(anchor_id('order', (string)$r['vendor'], (string)$r['order_id']))?>" title="Save current edits, then set every item line on this invoice to the entered category only">Set all items</button>
      </div>
      <button type="submit" name="recat_order" value="<?=h($okey)?>" data-anchor="#<?=h(anchor_id('order', (string)$r['vendor'], (string)$r['order_id']))?>" title="Use this invoice as a reference and apply its item categories to matching pending rows">Use invoice as category reference</button>
      <div><strong><?=h($r['order_date'])?> &nbsp; <?=h($r['order_id'])?></strong><?php if($r['order_url']): ?> &nbsp; <a href="<?=h($r['order_url'])?>" target="_blank"><?=h(vendor_config((string)$r['vendor'])['label'])?> order</a><?php endif; ?></div>
    </div>
    <?php if($r['items']): ?><div class="small" style="margin:.35rem 0"><?=h(clean_text($r['items']??'',500))?></div><?php endif; ?>
    <?php if($r['warning']): ?><div class="warn" style="margin:.35rem 0"><?=h(dedupe_warning_text((string)$r['warning']))?></div><?php endif; ?>
    <?php $shipDisplay=(float)$r['shipping']-(float)$r['shipping_refund']; $calcDisplay=round((float)$itSum + $shipDisplay + (float)$r['tax'], 2); $expectedDisplay=order_bill_total_for_validation($r); $deltaDisplay=round($calcDisplay - $expectedDisplay, 2); $vendorPayHint=vendor_payment_hint($r); $manualPaymentVerify=invoice_missing_payment_method_identified($r); ?>
    <div class="grid4">
      <div><span class="small">Item lines</span><br><?=$itCount?></div>
      <div><span class="small">Item sum</span><br>$<?=fmt_money((float)$itSum)?></div>
      <div><span class="small">Calc total</span><br>$<?=fmt_money($calcDisplay)?><?php if(abs($deltaDisplay)>0.01): ?><br><span class="warn small">Δ $<?=fmt_money($deltaDisplay)?></span><?php endif; ?></div>
      <div><span class="small">Expected bill total</span><br>$<?=fmt_money($expectedDisplay)?><?php if(in_array($r['vendor'], ['amazon','walmart','costco'], true) && abs((float)$r['gift'])>0.005): ?><br><span class="small"><?=h(ucfirst((string)$r['vendor']))?> charged/reported total $<?=fmt_money((float)$r['total'])?>; stored value payment $<?=fmt_money(abs((float)$r['gift']))?></span><?php endif; ?></div>
    </div>
    <div class="grid3" style="margin-top:.5rem">
      <label>Shipping account<br><span class="small">Shipping $<?=fmt_money((float)$r['shipping']-(float)$r['shipping_refund'])?></span><input list="accounts" type="text" name="order[<?=h($okey)?>][shipping_account]" value="<?=h($r['shipping_account'])?>"></label>
      <label>Sales tax account<br><span class="small">Tax $<?=fmt_money((float)$r['tax'])?></span><input list="accounts" type="text" name="order[<?=h($okey)?>][tax_account]" value="<?=h($r['tax_account'])?>"></label>
      <label>Order notes<br><span class="small"><?=h($r['payments'])?><?php if($vendorPayHint): ?><br><?=h($vendorPayHint)?><?php endif; ?><?php if($manualPaymentVerify): ?><br><strong class="warn">Manually verify payment account!</strong><?php endif; ?></span><textarea name="order[<?=h($okey)?>][notes]"><?=h($r['notes'])?></textarea></label>
    </div>
    <?php if(!$its): ?>
      <div class="grid2" style="margin-top:.5rem">
        <label>Fallback expense account<input list="accounts" type="text" name="order[<?=h($okey)?>][expense_account]" value="<?=h($r['expense_account'])?>"></label>
        <label>Fallback item amount<input type="text" name="order[<?=h($okey)?>][item_amount]" value="<?=fmt_money((float)$r['item_amount'])?>"></label>
      </div>
      <div class="warn small">No item rows are attached to this order. Use the fallback fields, or re-import the item-level file.</div>
    <?php else: ?>
      <input type="hidden" name="order[<?=h($okey)?>][expense_account]" value="<?=h($r['expense_account'])?>">
      <input type="hidden" name="order[<?=h($okey)?>][item_amount]" value="<?=fmt_money((float)$r['item_amount'])?>">
      <table class="items"><thead><tr><th>Skip</th><th>Lock</th><th>Description</th><th>Qty</th><th>Unit price</th><th>Expense account</th><th>Apply</th><th>Notes</th></tr></thead><tbody>
      <?php foreach($its as $it):
          $ikey=$it['vendor'].'|'.$it['order_id'].'|'.$it['item_key'];
          $itemAcctForClass = trim((string)($it['expense_account'] ?? ''));
          if ($itemAcctForClass === '' || is_placeholder_account($itemAcctForClass)) $itemRowClass = 'itemrow itemrow-uncategorized';
          elseif (!empty($reviewAccountSet) && !isset($reviewAccountSet[$itemAcctForClass])) $itemRowClass = 'itemrow itemrow-invalid-account';
          else $itemRowClass = 'itemrow itemrow-categorized';
        ?>
        <tr class="<?=h($itemRowClass)?>" id="<?=h(anchor_id('item', (string)$it['vendor'], (string)$it['order_id'], (string)$it['item_key']))?>">
          <td><input type="checkbox" name="item[<?=h($ikey)?>][skip]" <?=((int)$it['skip']?'checked':'')?>></td>
          <td><input type="checkbox" name="item[<?=h($ikey)?>][locked]" <?=((int)($it['locked'] ?? 0)?'checked':'')?> title="Lock this row so future auto-categorization does not change it"></td>
          <td><?=h(clean_text($it['description']??'',420))?><br><span class="small"><?=h(vendor_config((string)$it['vendor'])['product_key_label'])?> <?=h($it['asin'])?> <?php if($it['match_reason']): ?> | <?=h($it['match_reason'])?><?php endif; ?> <?php $displayItemUrl=item_display_url((string)$it['vendor'], (string)($it['item_url'] ?? ''), (string)($it['description'] ?? ''), (string)($it['asin'] ?? '')); if($displayItemUrl): ?>| <a href="<?=h($displayItemUrl)?>" target="_blank" rel="noopener noreferrer">item</a><?php endif; ?></span><?php if(is_credit_memo_row($it)): $creditOpts=credit_return_item_options($db, (string)$it['vendor'], (string)$it['order_id']); if($creditOpts): ?><div class="small" style="margin-top:.35rem"><strong>Returned item(s):</strong><br><?php $selectedCreditKeys = credit_selected_item_keys($it); ?><select name="credit_return_items[<?=h($ikey)?>][]" multiple size="<?=min(6,max(3,count($creditOpts)))?>" style="width:100%"><?php foreach($creditOpts as $co): ?><option value="<?=h($co['item_key'])?>" <?=in_array((string)$co['item_key'], $selectedCreditKeys, true)?'selected':''?>><?=h('$'.fmt_money((float)$co['amount']).' — '.clean_text((string)$co['description'], 120).' — '.(string)$co['expense_account'])?></option><?php endforeach; ?></select><br><button type="submit" name="save_credit_return_items" value="<?=h($ikey)?>" data-anchor="#<?=h(anchor_id('item', (string)$it['vendor'], (string)$it['order_id'], (string)$it['item_key']))?>" style="margin-top:.25rem">Save returned item selection</button><br><span class="small">Select one or more returned original-order lines, then click <strong>Save returned item selection</strong>. The credit memo description/category will be rebuilt from the selection.</span></div><?php endif; endif; ?></td>
          <td><input type="text" name="item[<?=h($ikey)?>][quantity_display]" value="<?=h(fmt_money((float)$it['quantity']))?>" disabled style="max-width:5rem"></td>
          <td><input type="text" name="item[<?=h($ikey)?>][unit_price]" value="<?=h(fmt_money((float)$it['unit_price']))?>"><br><span class="small">line $<?=h(fmt_money(isset($it['source_amount']) && $it['source_amount'] !== null && $it['vendor']==='costco' ? (float)$it['source_amount'] : (float)$it['quantity']*(float)$it['unit_price']))?></span></td>
          <td><input list="accounts" type="text" name="item[<?=h($ikey)?>][expense_account]" value="<?=h($it['expense_account'])?>"></td>
          <td><button type="submit" name="recat_item" value="<?=h($ikey)?>" data-anchor="#<?=h(anchor_id('item', (string)$it['vendor'], (string)$it['order_id'], (string)$it['item_key']))?>" title="Save this row and apply this account to matching pending SKU/ASIN rows">Apply same <?=h(vendor_config((string)$it['vendor'])['product_key_label'])?></button></td>
          <td><textarea name="item[<?=h($ikey)?>][notes]"><?=h($it['notes'])?></textarea></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php if(in_array((string)$r['vendor'], ['lowes','tractor_supply','home_depot'], true) && count($its) > 10): ?>
        <div class="invoice-footer-actions" style="margin-top:.65rem;padding-top:.5rem;border-top:1px solid #ddd;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
          <button type="submit" name="save_order" value="<?=h($okey)?>" data-anchor="#<?=h(anchor_id('order', (string)$r['vendor'], (string)$r['order_id']))?>" title="Save review edits and stay near this invoice">Save this invoice</button>
          <span class="small">Repeated footer button for long <?=h(vendor_config((string)$r['vendor'])['label'])?> invoices with more than 10 line items.</span>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<p><button type="submit">Save review edits on this page</button></p></form>
<?php if($totalFiltered > 0): ?>
<p class="pagination-footer"><strong>Displaying <?=$displayStart?>-<?=$displayEnd?> of <?=$totalFiltered?> orders</strong> &nbsp; <a href="?gnucash_path=<?=urlencode((string)$gnucashPath)?>&search=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>&show_skipped=<?=($showSkippedInAll?'1':'0')?>&date_sort=<?=urlencode($dateSort)?>&per_page=<?=$perPage?>&page=<?=max(1,$page-1)?>#review-top">Previous</a> | <a href="?gnucash_path=<?=urlencode((string)$gnucashPath)?>&search=<?=urlencode($search)?>&filter=<?=urlencode($filter)?>&show_skipped=<?=($showSkippedInAll?'1':'0')?>&date_sort=<?=urlencode($dateSort)?>&per_page=<?=$perPage?>&page=<?=min($totalPages,$page+1)?>#review-top">Next</a></p>
<?php endif; ?>
<form method="post" class="export-controls responsive-form" style="display:grid; grid-template-columns: auto auto 10rem 10rem 14rem 11rem 12rem 18rem 1fr; gap:.5rem; align-items:end"><input type="hidden" name="gnucash_path" value="<?=h($gnucashPath)?>"><button type="submit" name="action" value="validate_export">Validate selected batch</button><button type="submit" name="action" value="export">Export selected batch</button><label>Invoices per batch<input type="number" name="batch_size" value="<?=h((string)$storedBatchSize)?>" min="1" max="1000"></label><label>Batch #<input type="number" name="batch_number" value="<?=h((string)$selectedBatchNumber)?>" min="1" max="<?=$storedTotalBatches?>"></label><label>Export account prefix<input type="text" name="account_prefix" value="<?=h($storedAccountPrefix)?>" placeholder="leave blank for GnuCash"></label><label><input type="checkbox" name="post_invoices" value="1" <?=$exportPostInvoicesChecked?'checked':''?>> Post invoices</label><label>Post date format<select name="post_date_format"><option value="mdy_slash" <?=$storedPostDateFormat==='mdy_slash'?'selected':''?>>MM/DD/YYYY</option><option value="dmy_slash" <?=$storedPostDateFormat==='dmy_slash'?'selected':''?>>DD/MM/YYYY</option><option value="iso" <?=$storedPostDateFormat==='iso'?'selected':''?>>YYYY-MM-DD</option></select></label><label>Posting/AP account<input list="accounts" type="text" name="posting_account" value="<?=h($storedPostingAccount)?>"></label><span class="small">Selected batch includes exportable/non-skipped invoice numbers <?=$selectedBatchStart?>-<?=$selectedBatchEnd?> of <?=$exportableOrderCount?>. <?=$skippedOrderCount?> staged invoices are skipped and not exported. See the detailed batch list near the top of this section.</span></form><?php if(in_array($lastPostAction, ['validate_export','export'], true)) echo render_action_report_html($message, $error, $exportLinks, 'Selected batch export report', 'export-report-bottom'); ?><?php if($mode==='bills' && $vendorHint==='lowes'): ?>
<div class="card" id="lowes-workflow-footer"><h2>Lowe's workflow</h2><div class="wizard-steps lowes-workflow-nav"><a class="wizard-step" href="?mode=lowes&vendor_step=scrape#lowes-scraper">1. Scrape / normalize</a><a class="wizard-step <?=(($vendorStep==='import')?'active':'')?>" href="?mode=bills&vendor_hint=lowes&vendor_step=import#data-import-account-mapping">2. Data Import</a><a class="wizard-step <?=(($vendorStep==='' || $vendorStep==='review')?'active':'')?>" href="?mode=bills&vendor_hint=lowes&vendor_step=review#review-top">3. Review Bills and Line Items</a><a class="wizard-step <?=(($vendorStep==='stored_value')?'active':'')?>" href="?mode=lowes&vendor_step=stored_value#vendor-lowes">4. Build &amp; export My Lowe's Money transactions</a><a class="wizard-step <?=(($vendorStep==='match_dry_run')?'active':'')?>" href="?mode=lowes&vendor_step=match_dry_run#vendor-lowes">5a. Transaction Matching Dry Run</a><a class="wizard-step <?=(($vendorStep==='match_apply')?'active':'')?>" href="?mode=lowes&vendor_step=match_apply#vendor-lowes">5b. Apply Transaction Matching to GnuCash File</a></div><p class="small">Footer copy for long review pages: use this after saving mappings, validating, or exporting without scrolling back to the top. Steps 4 and 5 are intended to be vendor-neutral after vendor-specific Step 3 import is complete.</p><?php render_lowes_step5_controls($db, 'bills', (string)$vendorStep, 'lowes-workflow-footer'); ?></div>
<?php endif; ?>
</div>
<?php endif; ?>
<script>
(function(){
  const shouldScroll = <?=json_encode(is_export_or_validation_action($lastPostAction) || in_array($lastPostAction, ['build_lowes_payment_plan_reports','run_lowes_payment_matching_dry_run','skip_lowes_payment_targets_and_rerun_dry_run','run_lowes_payment_matching_apply','scan_lowes_unmatched_register_transactions','scan_vendor_unmatched_register_transactions','ignore_vendor_register_transactions','unignore_vendor_register_transaction'], true))?>;
  if (shouldScroll) {
    window.addEventListener('load', function(){
      const target = document.getElementById('export-report') || document.getElementById('export-report-bottom') || document.getElementById('lowes-payment-dry-run-report') || document.getElementById('payment-export-report') || document.getElementById('action-report') || document.getElementById('validation-report');
      if (target) target.scrollIntoView({block:'start'});
    });
  }
})();

(function(){
  function itemAccountState(value){
    const acct = (value || '').trim();
    if (!acct || acct.toLowerCase() === 'expenses:uncategorized') return 'uncategorized';
    const dl = document.getElementById('accounts');
    if (dl) {
      const valid = Array.from(dl.options || []).some(opt => (opt.value || '').trim() === acct);
      if (!valid) return 'invalid';
    }
    return 'categorized';
  }
  function applyItemRowCategoryState(input){
    if (!input) return;
    const tr = input.closest('tr.itemrow');
    if (!tr) return;
    tr.classList.remove('itemrow-uncategorized','itemrow-categorized','itemrow-invalid-account');
    const state = itemAccountState(input.value);
    if (state === 'categorized') tr.classList.add('itemrow-categorized');
    else if (state === 'invalid') tr.classList.add('itemrow-invalid-account');
    else tr.classList.add('itemrow-uncategorized');
  }
  document.addEventListener('input', function(e){
    const t = e.target;
    if (t && t.matches('tr.itemrow input[name$="[expense_account]"]')) applyItemRowCategoryState(t);
  });
  document.addEventListener('change', function(e){
    const t = e.target;
    if (t && t.matches('tr.itemrow input[name$="[expense_account]"]')) applyItemRowCategoryState(t);
  });
  document.querySelectorAll('tr.itemrow input[name$="[expense_account]"]').forEach(applyItemRowCategoryState);
})();

(function(){
  const shouldJump = <?=!empty($jumpToValidationReport) ? 'true' : 'false'?>;
  if (!shouldJump) return;
  window.requestAnimationFrame(function(){
    const el = document.getElementById('validation-report');
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
  });
})();

(function(){
  const storageKey = 'gnucash_vendor_import_item_column_widths_v152';
  const defaults = [48, 48, 360, 68, 92, 330, 115, 220];
  function readWidths(){
    try {
      const v = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (Array.isArray(v) && v.length === defaults.length) return v.map(x => Math.max(40, parseInt(x,10) || 80));
    } catch(e) {}
    return defaults.slice();
  }
  function saveWidths(w){ localStorage.setItem(storageKey, JSON.stringify(w)); }
  function fittedWidths(table, widths){
    const parent = table.closest('.ordercard') || table.parentElement || document.body;
    const available = Math.max(520, Math.floor((parent.clientWidth || window.innerWidth || 1100) - 12));
    const base = widths.map((w,i) => Math.max([42,42,180,58,75,180,90,150][i] || 60, parseInt(w,10) || defaults[i]));
    const total = base.reduce((a,b)=>a+b,0);
    if (total <= available) return base;
    const fixedIdx = new Set([0,1,3,4,6]);
    const min = [42,42,180,58,75,180,90,150];
    const fixedTotal = base.reduce((a,b,i)=>a+(fixedIdx.has(i)?Math.max(min[i],Math.min(b,defaults[i])):0),0);
    const flexIdx = base.map((_,i)=>i).filter(i=>!fixedIdx.has(i));
    const flexTotal = flexIdx.reduce((a,i)=>a+base[i],0);
    let remaining = Math.max(flexIdx.reduce((a,i)=>a+min[i],0), available - fixedTotal);
    const out = base.slice();
    flexIdx.forEach(i => { out[i] = Math.max(min[i], Math.floor(base[i] * remaining / Math.max(1, flexTotal))); });
    fixedIdx.forEach(i => { out[i] = Math.max(min[i], Math.min(base[i], defaults[i])); });
    return out;
  }
  function applyWidths(widths){
    document.querySelectorAll('table.items').forEach(table => {
      let cg = table.querySelector('colgroup[data-resizable="1"]');
      if (!cg) {
        cg = document.createElement('colgroup');
        cg.dataset.resizable = '1';
        for (let i=0;i<defaults.length;i++) cg.appendChild(document.createElement('col'));
        table.insertBefore(cg, table.firstChild);
      }
      const fitted = fittedWidths(table, widths);
      Array.from(cg.children).forEach((col,i) => { col.style.width = (fitted[i] || defaults[i]) + 'px'; });
      table.style.width = '100%';
      table.style.minWidth = '0';
    });
  }
  function addHandles(){
    document.querySelectorAll('table.items thead th').forEach((th, idx) => {
      if (th.querySelector('.col-resizer')) return;
      const handle = document.createElement('span');
      handle.className = 'col-resizer';
      handle.title = 'Drag to resize column; double-click to reset all item columns';
      th.appendChild(handle);
      handle.addEventListener('dblclick', function(e){
        e.preventDefault(); e.stopPropagation();
        saveWidths(defaults.slice()); applyWidths(defaults.slice());
      });
      handle.addEventListener('mousedown', function(e){
        e.preventDefault();
        const widths = readWidths();
        const startX = e.clientX;
        const startW = widths[idx] || th.getBoundingClientRect().width;
        document.body.classList.add('col-resizing');
        function move(ev){
          widths[idx] = Math.max(idx === 4 ? 220 : 45, Math.round(startW + ev.clientX - startX));
          saveWidths(widths); applyWidths(widths);
        }
        function up(){
          document.removeEventListener('mousemove', move);
          document.removeEventListener('mouseup', up);
          document.body.classList.remove('col-resizing');
        }
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
      });
    });
  }
  const widths = readWidths();
  applyWidths(widths);
  addHandles();
})();

(function(){
  const form = document.getElementById('reviewForm');
  const status = document.getElementById('autosaveStatus');
  const btn = document.getElementById('ajaxSaveBtn');
  if (!form || !status) return;
  let dirty = false;
  let saving = false;
  function setStatus(t){ status.textContent = t; }
  function assignNested(obj, name, value){
    const m = name.match(/^([^\[]+)\[([^\]]+)\]\[([^\]]+)\]$/);
    if (m) {
      const root = m[1], key = m[2], field = m[3];
      if (!obj[root]) obj[root] = {};
      if (!obj[root][key]) obj[root][key] = {};
      obj[root][key][field] = value;
    } else {
      obj[name] = value;
    }
  }
  function formToPayload(submitter, ajaxAction){
    const payload = {};
    const fd = new FormData(form);
    for (const [name, value] of fd.entries()) assignNested(payload, name, value);
    if (submitter && submitter.name) assignNested(payload, submitter.name, submitter.value || '1');
    if (ajaxAction) payload.action = ajaxAction;
    return payload;
  }
  function logApplySameSku(stage, detail){
    try {
      console.log('[GnuCash Vendor Bill Tool] Apply same SKU/item id debug - ' + stage, detail);
    } catch(e) {}
  }
  function buildReviewReloadUrl(anchor=''){
    const url = new URL(window.location.href);
    const copyFields = ['mode','vendor_hint','vendor_step','page','per_page','filter','search','date_sort','show_skipped'];
    copyFields.forEach(function(name){
      const el = form.elements[name];
      if (el && typeof el.value !== 'undefined') url.searchParams.set(name, el.value);
    });
    if (!url.searchParams.get('mode')) url.searchParams.set('mode', 'bills');
    if (!url.searchParams.get('vendor_step')) url.searchParams.set('vendor_step', 'review');
    if (anchor) url.hash = anchor.replace(/^#/, '');
    else if (!url.hash) url.hash = 'review-top';
    return url;
  }
  async function savePayload(payload, reloadAfter=false, anchor=''){
    if (saving) return;
    saving = true;
    setStatus('Saving...');
    try {
      const res = await fetch(window.location.href, {
        method:'POST',
        body: JSON.stringify(payload),
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin'
      });
      const data = await res.json();
      if (data.ok) {
        if (payload && payload.recat_item) {
          logApplySameSku('server response', data);
          try { sessionStorage.setItem('gnucashApplySameSkuLastResponse', JSON.stringify(data)); } catch(e) {}
        }
        dirty = false;
        setStatus(data.message || 'Saved');
        if (reloadAfter) {
          window.location.assign(buildReviewReloadUrl(anchor).toString());
          return;
        }
      } else {
        if (payload && payload.recat_item) logApplySameSku('server error response', data);
        setStatus(data.message || 'Save failed');
        alert(data.message || 'Save failed');
      }
    } catch(e) {
      if (payload && payload.recat_item) logApplySameSku('fetch exception', e);
      setStatus('Save failed: ' + e);
      alert('Save failed: ' + e);
    }
    saving = false;
  }
  async function saveNow(){
    if (!dirty && !confirm('No unsaved changes detected. Save current page anyway?')) return;
    await savePayload(formToPayload(null, 'ajax_save'), false);
  }
  // Avoid automatic full-page posts. Costco receipts can exceed PHP max_input_vars when posted as normal form data.
  // We mark dirty and save only when the user clicks Save, Apply same SKU/ASIN, Use invoice as category reference, or an invoice-header category/save button.
  form.addEventListener('input', function(){ dirty = true; setStatus('Unsaved changes'); });
  form.addEventListener('change', function(){ dirty = true; setStatus('Unsaved changes'); });
  if (btn) btn.addEventListener('click', function(){ dirty = true; saveNow(); });
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    var submitter = ev.submitter || document.activeElement;
    var payload = formToPayload(submitter, 'save');
    var isApplySameSku = !!(submitter && submitter.name === 'recat_item');
    if (isApplySameSku) {
      const row = submitter.closest('tr[id]');
      const acctInput = row ? row.querySelector('input[name$="[expense_account]"]') : null;
      logApplySameSku('click', {
        button_value: submitter.value || '',
        row_id: row ? row.id : '',
        account_field_value: acctInput ? acctInput.value : '',
        payload_recat_item: payload.recat_item || '',
        payload_action: payload.action || '',
        current_url: window.location.href
      });
    }
    var isApplySameSku = !!(submitter && submitter.name === 'recat_item');
    if (isApplySameSku) {
      const row = submitter.closest('tr[id]');
      const acctInput = row ? row.querySelector('input[name$=\"[expense_account]\"]') : null;
      logApplySameSku('click', {
        button_value: submitter.value || '',
        row_id: row ? row.id : '',
        account_field_value: acctInput ? acctInput.value : '',
        payload_recat_item: payload.recat_item || '',
        payload_action: payload.action || '',
        current_url: window.location.href
      });
    }
    let anchor = '';
    if (submitter) {
      anchor = submitter.dataset && submitter.dataset.anchor ? submitter.dataset.anchor : '';
      if (!anchor) {
        const node = submitter.closest('tr[id], .ordercard[id]');
        if (node && node.id) anchor = '#' + node.id;
      }
    }
    const shouldReload = !!(submitter && (submitter.name === 'recat_item' || submitter.name === 'recat_order' || submitter.name === 'recat_recent_manual' || submitter.name === 'save_credit_return_items' || submitter.name === 'set_invoice_category'));
    savePayload(payload, shouldReload, anchor);
  });
  window.addEventListener('beforeunload', function(ev){
    if (dirty) { ev.preventDefault(); ev.returnValue = 'Unsaved review changes'; }
  });
  const addForm = document.getElementById('addAccountForm');
  if (addForm) addForm.addEventListener('submit', async function(ev){
    ev.preventDefault();
    const fd = new FormData(addForm); fd.set('action','ajax_add_account');
    const acct = (fd.get('account_name') || '').toString().trim();
    if (!acct) return;
    try {
      const res = await fetch(window.location.pathname, {method:'POST', body:fd, credentials:'same-origin'});
      const data = await res.json();
      if (data.ok) {
        const dl = document.getElementById('accounts');
        if (dl) { const opt=document.createElement('option'); opt.value=acct; dl.appendChild(opt); }
        addForm.reset();
        setStatus(data.message || 'Account option added');
      } else alert(data.message || 'Could not add account option');
    } catch(e) { alert('Could not add account option: '+e); }
  });
})();
</script>








<script>
document.addEventListener('click', function(ev) {
  const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
  if (!a) return;

  const href = a.getAttribute('href') || '';
  if (!href.includes('mode=tractor_supply') || !href.includes('vendor_step=scrape')) return;

  // After a local import, the browser may already be on the same URL/hash.
  // Force a real request by replacing tsc_nav with a click timestamp.
  ev.preventDefault();
  ev.stopImmediatePropagation();

  const url = new URL(href, window.location.href);
  url.searchParams.set('mode', 'tractor_supply');
  url.searchParams.set('vendor_step', 'scrape');
  url.searchParams.set('tsc_nav', String(Date.now()));
  url.hash = 'vendor-tractor-supply-active';

  window.location.assign(url.pathname + url.search + url.hash);
}, true);
</script>
<script>
(function(){
  try {
    const raw = sessionStorage.getItem('gnucashApplySameSkuLastResponse');
    if (raw) {
      sessionStorage.removeItem('gnucashApplySameSkuLastResponse');
      console.log('[GnuCash Vendor Bill Tool] Apply same SKU/item id debug - previous server response after reload', JSON.parse(raw));
    }
  } catch(e) {}
})();
</script>
</body></html>
