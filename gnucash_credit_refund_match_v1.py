#!/usr/bin/env python3
"""
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: GPL-3.0-or-later

GnuCash Amazon CREDIT/refund matcher v1 (v128 package).

Matches posted Amazon *-CREDIT vendor bills to existing refund/stored-value
transactions already entered in the GnuCash register.  This script does NOT
invent gift-card/refund activity.  It only rewires the existing offset split in
an already-entered refund transaction so that the offset clears the CREDIT bill's
Accounts Payable lot.

v128 behavior:
  * refund candidates are constrained to a one-sided refund window beginning on
    the CREDIT invoice/post date and extending forward by --refund-date-window-days
    (default 183 days, roughly six months);
  * exact single refund matches are preferred;
  * if no safe exact single match exists, the matcher can use combinations of
    unapplied refund transactions whose amounts sum to the CREDIT amount;
  * dry-run/apply output includes match/error counts and a ready-to-proceed
    message when no blockers remain.

Intended workflow:
  1. Manually enter/import Amazon gift-card / cash-back / card-refund activity.
  2. Run this script in --dry-run mode to find exact or combination matches.
  3. Apply only to a copied/test GnuCash book first.

Guardrails:
  * target invoice ID must end with -CREDIT by default;
  * credit invoice must be posted and have a non-zero lot balance;
  * refund transaction dates must be on/after the credit invoice date and within
    the configured forward window;
  * exact single refund matches are preferred over combinations;
  * a combination match is allowed only when exactly one safe combination sums to
    the CREDIT amount;
  * each refund transaction must have exactly one counterpart split with the sign
    needed to clear the credit lot;
  * counterpart splits already tied to any lot are not reused;
  * transaction balance must remain zero;
  * final credit lot balance must be zero;
  * apply refuses non-test-looking book names unless --allow-non-copy-name is set.
"""
from __future__ import annotations

import argparse
import csv
import datetime as _dt
import os
import re
import shutil
import sqlite3
import sys
import time
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
from itertools import combinations
from pathlib import Path
from typing import Dict, List, Tuple

CENT = Decimal('0.01')
DEFAULT_REFUND_ACCOUNTS = [
    'Assets:Other Current Assets:Amazon Gift Card (Returns)',
    'Assets:Other Current Assets:Amazon Credit Card Cash Back',
    'Liabilities:Credit Cards:Amazon Chase Card 2246',
]
DEFAULT_REFUND_DATE_WINDOW_DAYS = 183
DEFAULT_MAX_COMBINATION_SIZE = 4


def D(value) -> Decimal:
    if value is None:
        return Decimal('0.00')
    s = str(value).strip().replace('$', '').replace(',', '')
    if s == '':
        return Decimal('0.00')
    return Decimal(s).quantize(CENT, rounding=ROUND_HALF_UP)


def fmt(value: Decimal) -> str:
    return str(D(value))


def norm_guid(s: str) -> str:
    return re.sub(r'[^0-9a-fA-F]', '', (s or '')).lower()


def qmoney(num, denom) -> Decimal:
    if denom in (None, 0, '0'):
        return Decimal('0.00')
    return (Decimal(int(num or 0)) / Decimal(int(denom))).quantize(Decimal('0.000001'))


def split_decimal(row) -> Decimal:
    return qmoney(row['value_num'], row['value_denom']).quantize(CENT, rounding=ROUND_HALF_UP)


def date_only(s: str) -> str:
    return (str(s or '').strip()[:10])


def parse_date(s: str) -> _dt.date | None:
    s = date_only(s)
    if not s:
        return None
    try:
        return _dt.date.fromisoformat(s)
    except ValueError:
        return None


def date_in_forward_window(candidate_date: str, anchor_date: str, days: int) -> bool:
    c = parse_date(candidate_date)
    a = parse_date(anchor_date)
    if c is None or a is None:
        return True
    return a <= c <= (a + _dt.timedelta(days=max(0, days)))


def normalize_account_name(s: str) -> str:
    return re.sub(r'\s+', ' ', (s or '').strip()).lower()


def row_to_dict(row) -> dict:
    return {k: row[k] for k in row.keys()}


def account_fullname_map(con: sqlite3.Connection) -> Dict[str, str]:
    con.row_factory = sqlite3.Row
    rows = {norm_guid(str(r['guid'] or '')): row_to_dict(r) for r in con.execute('SELECT guid, name, account_type, parent_guid FROM accounts').fetchall()}
    memo: Dict[str, str] = {}

    def full(guid: str) -> str:
        guid = norm_guid(guid)
        if guid in memo:
            return memo[guid]
        r = rows.get(guid)
        if not r:
            return ''
        name = str(r.get('name') or '')
        parent = norm_guid(str(r.get('parent_guid') or ''))
        pr = rows.get(parent)
        if not parent or not pr or str(pr.get('account_type') or '').upper() == 'ROOT' or str(pr.get('name') or '').lower() == 'root account':
            memo[guid] = name
        else:
            p = full(parent)
            memo[guid] = (p + ':' if p else '') + name
        return memo[guid]

    return {guid: full(guid) for guid in rows}


def txn_balance(con: sqlite3.Connection, tx_guid: str) -> Decimal:
    rows = con.execute('SELECT value_num, value_denom FROM splits WHERE lower(tx_guid)=lower(?)', (norm_guid(tx_guid),)).fetchall()
    total = Decimal('0.00')
    for r in rows:
        total += qmoney(r['value_num'], r['value_denom'])
    return total.quantize(CENT, rounding=ROUND_HALF_UP)


def lot_balance(con: sqlite3.Connection, lot_guid: str) -> Decimal:
    res = con.execute('SELECT COALESCE(SUM(CAST(value_num AS REAL)/value_denom),0) AS bal FROM splits WHERE lower(lot_guid)=lower(?)', (norm_guid(lot_guid),)).fetchone()
    return D(res['bal'] if res else 0)


def book_name_is_test_copy(path: str) -> bool:
    base = os.path.basename(path).lower()
    return any(tok in base for tok in ['copy', 'test', 'apitest', 'working', 'sandbox'])


def lock_files_for_book(book_path: str) -> List[str]:
    return [p for p in [book_path + '.LCK', book_path + '.LNK'] if os.path.exists(p)]


def make_apply_backup(book_path: str) -> str:
    stamp = time.strftime('%Y%m%d-%H%M%S')
    backup = f'{book_path}.pre-credit-refund-match-{stamp}.bak'
    shutil.copy2(book_path, backup)
    return backup


def load_credit_invoices(con: sqlite3.Connection, from_date: str, to_date: str, include_non_credit_ids: bool = False) -> List[dict]:
    con.row_factory = sqlite3.Row
    where = []
    if not include_non_credit_ids:
        where.append("i.id LIKE '%-CREDIT'")
    sql = '''
        SELECT i.*, t.post_date AS invoice_post_date, t.description AS post_description
          FROM invoices i
          LEFT JOIN transactions t ON lower(t.guid)=lower(i.post_txn)
    '''
    if where:
        sql += ' WHERE ' + ' AND '.join(where)
    sql += ' ORDER BY i.id'
    out = []
    for r in con.execute(sql).fetchall():
        post_lot = norm_guid(str(r['post_lot'] or ''))
        post_txn = norm_guid(str(r['post_txn'] or ''))
        if not post_lot or post_lot == '0' * 32 or not post_txn or post_txn == '0' * 32:
            continue
        bal = lot_balance(con, post_lot)
        if abs(bal) < Decimal('0.005'):
            continue
        inv_date = date_only(r['invoice_post_date'])
        # Keep invoice filtering broad.  Refund transaction date and the forward window
        # decide actual candidate eligibility.
        if from_date and inv_date and inv_date < from_date:
            pass
        out.append({
            'invoice_id': str(r['id'] or ''),
            'invoice_guid': norm_guid(str(r['guid'] or '')),
            'post_txn': post_txn,
            'post_lot': post_lot,
            'invoice_post_date': inv_date,
            'lot_balance': bal,
            'amount_abs': abs(bal).quantize(CENT, rounding=ROUND_HALF_UP),
        })
    return out


def ap_template_for_invoice(con: sqlite3.Connection, inv: dict):
    rows = con.execute('SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(lot_guid)=lower(?)', (inv['post_txn'], inv['post_lot'])).fetchall()
    if len(rows) != 1:
        return None, f'Expected exactly one posted A/P lot split; found {len(rows)}.'
    return rows[0], ''


def load_refund_candidates(con: sqlite3.Connection, account_names: List[str], from_date: str, to_date: str) -> Tuple[List[dict], List[str]]:
    con.row_factory = sqlite3.Row
    amap = account_fullname_map(con)
    wanted = {normalize_account_name(a) for a in account_names if a.strip()}
    account_guids = [g for g, n in amap.items() if normalize_account_name(n) in wanted]
    missing = [a for a in account_names if normalize_account_name(a) not in {normalize_account_name(amap.get(g, '')) for g in account_guids}]
    if not account_guids:
        return [], missing
    placeholders = ','.join(['?'] * len(account_guids))
    params = list(account_guids)
    date_where = ''
    if from_date:
        date_where += ' AND substr(t.post_date,1,10) >= ?'
        params.append(from_date)
    if to_date:
        date_where += ' AND substr(t.post_date,1,10) <= ?'
        params.append(to_date)
    sql = f'''
        SELECT s.*, t.post_date, t.description AS tx_description
          FROM splits s
          JOIN transactions t ON lower(t.guid)=lower(s.tx_guid)
         WHERE lower(s.account_guid) IN ({placeholders})
           {date_where}
         ORDER BY t.post_date, t.guid, s.guid
    '''
    out = []
    for s in con.execute(sql, params).fetchall():
        amt = split_decimal(s)
        if abs(amt) < Decimal('0.005'):
            continue
        out.append({
            'tx_guid': norm_guid(str(s['tx_guid'] or '')),
            'split_guid': norm_guid(str(s['guid'] or '')),
            'account_guid': norm_guid(str(s['account_guid'] or '')),
            'account_name': amap.get(norm_guid(str(s['account_guid'] or '')), ''),
            'amount': amt,
            'amount_abs': abs(amt).quantize(CENT, rounding=ROUND_HALF_UP),
            'post_date': date_only(s['post_date']),
            'description': str(s['tx_description'] or ''),
        })
    return out, missing


def needed_sign_for_invoice(inv: dict) -> Decimal:
    needed = -D(inv['lot_balance'])
    return Decimal('1.00') if needed >= 0 else Decimal('-1.00')


def safe_component_for_refund_split(con: sqlite3.Connection, inv: dict, c: dict) -> Tuple[dict | None, str]:
    """Return a safe component dict or a reason string.

    The refund account split remains in its refund/gift-card/card account.  The
    counterpart split is the one that should be moved/attached to the credit
    invoice's A/P lot.  For combination matching, each counterpart must have the
    same sign as the total A/P amount needed and its absolute value must equal the
    refund account split's absolute value.
    """
    already = con.execute('SELECT COUNT(*) AS n FROM splits WHERE lower(tx_guid)=lower(?) AND lower(lot_guid)=lower(?)', (c['tx_guid'], inv['post_lot'])).fetchone()['n']
    if already:
        return None, 'already_applied_to_this_lot'

    tx_bal = txn_balance(con, c['tx_guid'])
    if abs(tx_bal) > Decimal('0.005'):
        return None, f'transaction_not_balanced_{fmt(tx_bal)}'

    sign = needed_sign_for_invoice(inv)
    needed_for_this_component = (c['amount_abs'] * sign).quantize(CENT, rounding=ROUND_HALF_UP)
    splits = con.execute('SELECT * FROM splits WHERE lower(tx_guid)=lower(?)', (c['tx_guid'],)).fetchall()
    counterparts = []
    for s in splits:
        sg = norm_guid(str(s['guid'] or ''))
        if sg == c['split_guid']:
            continue
        if norm_guid(str(s['lot_guid'] or '')):
            continue
        sval = split_decimal(s)
        if abs(sval - needed_for_this_component) <= Decimal('0.01'):
            counterparts.append(s)
    if len(counterparts) == 1:
        return {
            'refund': c,
            'counterpart_split': counterparts[0],
            'needed_ap_amount': needed_for_this_component,
        }, 'ready'
    if len(counterparts) == 0:
        return None, f'no_counterpart_split_with_needed_sign_{fmt(needed_for_this_component)}'
    return None, f'multiple_counterpart_splits_{len(counterparts)}'


def summarize_components(components: List[dict]) -> str:
    parts = []
    for comp in components:
        c = comp['refund']
        parts.append(f'{c["post_date"]} {c["tx_guid"]} [{c["account_name"]}] {fmt(c["amount_abs"])}')
    return '; '.join(parts)


def candidate_for_invoice(con: sqlite3.Connection, inv: dict, refund_splits: List[dict], refund_date_window_days: int, max_combination_size: int) -> Tuple[str, str, dict | None]:
    """Return status, notes, candidate dict."""
    amount = inv['amount_abs']
    windowed = [
        c for c in refund_splits
        if date_in_forward_window(c.get('post_date', ''), inv.get('invoice_post_date', ''), refund_date_window_days)
    ]
    if not windowed:
        return 'NO_MATCHING_REFUND_TRANSACTION', f'No refund/stored-value transaction split found within 0..+{refund_date_window_days} days of {inv.get("invoice_post_date", "")}.', None

    # First pass: exact single-transaction match.
    exact = [c for c in windowed if abs(c['amount_abs'] - amount) <= Decimal('0.01')]
    exact_enriched = []
    for c in exact:
        comp, reason = safe_component_for_refund_split(con, inv, c)
        exact_enriched.append((c, comp, reason))
    exact_ready = [comp for _, comp, reason in exact_enriched if reason == 'ready' and comp]
    if len(exact_ready) == 1:
        comp = exact_ready[0]
        c = comp['refund']
        return 'READY', f'Matched refund transaction {c["tx_guid"]} on {c["post_date"]} in [{c["account_name"]}] amount={fmt(c["amount_abs"])}.', {
            'match_kind': 'single',
            'components': [comp],
            'needed_ap_amount': -D(inv['lot_balance']),
        }
    if len(exact_ready) > 1:
        detail = summarize_components(exact_ready[:5])
        return 'AMBIGUOUS_MULTIPLE_REFUND_MATCHES', f'{len(exact_ready)} exact refund transaction candidates for {fmt(amount)} within 0..+{refund_date_window_days} days: {detail}', None

    # Second pass: safe combinations of not-yet-applied refund transactions.
    # Only use components whose amount is smaller than the target; exact-sized components
    # were already considered above.
    safe_components = []
    rejected_notes = []
    for c in windowed:
        if c['amount_abs'] >= amount - Decimal('0.005'):
            continue
        comp, reason = safe_component_for_refund_split(con, inv, c)
        if comp:
            safe_components.append(comp)
        elif len(rejected_notes) < 5:
            rejected_notes.append(f'{c["post_date"]} {c["tx_guid"]} {reason}')

    # Keep search bounded.  There are normally very few actual refund credits, but
    # account activity can include many gift-card applications that will already be
    # rejected by the safe-component test above.
    safe_components.sort(key=lambda comp: (comp['refund']['post_date'], comp['refund']['tx_guid']))
    combo_matches: List[List[dict]] = []
    max_size = max(2, min(max_combination_size, 8))
    for size in range(2, min(max_size, len(safe_components)) + 1):
        for combo in combinations(safe_components, size):
            # Do not combine two components from the same transaction; that normally
            # indicates a malformed or duplicate candidate extraction.
            txs = [comp['refund']['tx_guid'] for comp in combo]
            if len(set(txs)) != len(txs):
                continue
            total = sum((comp['refund']['amount_abs'] for comp in combo), Decimal('0.00')).quantize(CENT, rounding=ROUND_HALF_UP)
            if abs(total - amount) <= Decimal('0.01'):
                combo_matches.append(list(combo))
                if len(combo_matches) > 1:
                    break
        if combo_matches:
            break

    if len(combo_matches) == 1:
        combo = combo_matches[0]
        total = sum((comp['refund']['amount_abs'] for comp in combo), Decimal('0.00')).quantize(CENT, rounding=ROUND_HALF_UP)
        return 'READY', f'Matched {len(combo)} refund transactions totaling {fmt(total)} within 0..+{refund_date_window_days} days: {summarize_components(combo)}.', {
            'match_kind': 'combination',
            'components': combo,
            'needed_ap_amount': -D(inv['lot_balance']),
        }
    if len(combo_matches) > 1:
        first = summarize_components(combo_matches[0])
        return 'AMBIGUOUS_MULTIPLE_REFUND_COMBINATIONS', f'Multiple safe refund combinations total {fmt(amount)}. First candidate: {first}', None

    if exact:
        detail = '; '.join(f'{c["post_date"]} {c["tx_guid"]} {reason}' for c, _, reason in exact_enriched[:5])
        return 'NO_SAFE_COUNTERPART_SPLIT', detail, None
    detail = '; '.join(rejected_notes[:5])
    suffix = f' Candidate rejections: {detail}' if detail else ''
    return 'NO_MATCHING_REFUND_TRANSACTION', f'No exact refund or safe refund combination found for {fmt(amount)} within 0..+{refund_date_window_days} days of {inv.get("invoice_post_date", "")}.{suffix}', None


def apply_match(con: sqlite3.Connection, inv: dict, ap_template, candidate: dict) -> str:
    ap_account_guid = norm_guid(str(ap_template['account_guid'] or ''))
    components = candidate.get('components') or []
    if not components:
        raise RuntimeError('No components in candidate.')

    tx_guids = sorted({comp['refund']['tx_guid'] for comp in components})
    before_tx_balances = {tx: txn_balance(con, tx) for tx in tx_guids}
    bad_before = {tx: bal for tx, bal in before_tx_balances.items() if abs(bal) > Decimal('0.005')}
    if bad_before:
        detail = ', '.join(f'{tx}={fmt(bal)}' for tx, bal in bad_before.items())
        raise RuntimeError(f'Refund transaction not balanced before apply: {detail}')

    updated = []
    for comp in components:
        cp = comp['counterpart_split']
        refund = comp['refund']
        cp_guid = norm_guid(str(cp['guid'] or ''))
        tx_guid = refund['tx_guid']
        con.execute('''
            UPDATE splits
               SET account_guid=?, lot_guid=?, memo=?, action=?
             WHERE lower(guid)=lower(?)
        ''', (ap_account_guid, inv['post_lot'], f'Apply Amazon refund credit {inv["invoice_id"]}'[:2048], 'Refund', cp_guid))
        updated.append(f'{cp_guid}@{tx_guid}')

    bad_after = {tx: txn_balance(con, tx) for tx in tx_guids if abs(txn_balance(con, tx)) > Decimal('0.005')}
    if bad_after:
        detail = ', '.join(f'{tx}={fmt(bal)}' for tx, bal in bad_after.items())
        raise RuntimeError(f'Refund transaction would not balance after apply: {detail}')
    final_lot = lot_balance(con, inv['post_lot'])
    if abs(final_lot) > Decimal('0.005'):
        raise RuntimeError(f'Credit lot would not be zero after apply: {fmt(final_lot)}')
    return f'Updated {len(updated)} counterpart split(s) on existing refund transaction(s): {"; ".join(updated)}; final credit lot balance=0.00.'


def print_summary(out_rows: List[dict], dry_run: bool, applied: int) -> None:
    status_counts = Counter(r.get('status', '') for r in out_rows)
    total = len(out_rows)
    ready_rows = status_counts.get('READY_DRYRUN_ONLY', 0)
    applied_rows = status_counts.get('APPLY_OK', 0)
    error_rows = sum(v for k, v in status_counts.items() if k not in {'READY_DRYRUN_ONLY', 'APPLY_OK'})
    invoice_groups = len({r.get('invoice_id', '') for r in out_rows if r.get('invoice_id')})
    ready_groups = len({r.get('invoice_id', '') for r in out_rows if r.get('status') == 'READY_DRYRUN_ONLY'})
    applied_groups = len({r.get('invoice_id', '') for r in out_rows if r.get('status') == 'APPLY_OK'})
    error_groups = len({r.get('invoice_id', '') for r in out_rows if r.get('status') not in {'READY_DRYRUN_ONLY', 'APPLY_OK'}})

    print('')
    print('Credit/refund match summary:')
    print(f'  credit invoices processed: {total}')
    print(f'  invoice groups processed: {invoice_groups}')
    if dry_run:
        print(f'  ready matches: {ready_rows} row(s), {ready_groups} group(s)')
    else:
        print(f'  applied matches: {applied_rows} row(s), {applied_groups} group(s)')
        print(f'  applied write count: {applied}')
    print(f'  error/blocker rows: {error_rows}')
    print(f'  error/blocker groups: {error_groups}')
    print('  status counts:')
    for status, count in status_counts.most_common():
        print(f'    {count:5d} {status}')
    if dry_run and error_rows == 0:
        print('DRY RUN CLEAN: 0 errors/blockers. Ready to proceed with credit/refund apply against the test book.')
    elif not dry_run and error_rows == 0:
        print('APPLY COMPLETE: 0 errors/blockers.')
    else:
        print('NOT READY: resolve or deliberately exclude/manual-handle the blocker rows before applying.')


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--book', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--from-date', default='')
    ap.add_argument('--to-date', default='')
    ap.add_argument('--account', action='append', default=[], help='Refund/stored-value account full name to scan. May be repeated.')
    ap.add_argument('--refund-date-window-days', type=int, default=DEFAULT_REFUND_DATE_WINDOW_DAYS, help='Forward-only date window from the credit invoice date for refund candidates. Default: 183 days.')
    ap.add_argument('--max-combination-size', type=int, default=DEFAULT_MAX_COMBINATION_SIZE, help='Maximum number of refund transactions to combine when no exact match exists. Default: 4.')
    ap.add_argument('--include-non-credit-ids', action='store_true')
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--apply', action='store_true')
    ap.add_argument('--allow-non-copy-name', action='store_true')
    args = ap.parse_args()

    book_path = os.path.abspath(args.book)
    accounts = args.account or DEFAULT_REFUND_ACCOUNTS

    if args.apply and not args.allow_non_copy_name and not book_name_is_test_copy(book_path):
        print('Refusing apply: book filename does not look like a copy/test/sandbox file.', file=sys.stderr)
        return 4
    if args.apply and not os.access(book_path, os.W_OK):
        print('Refusing apply: selected GnuCash book is not writable by this user.', file=sys.stderr)
        return 5
    if args.apply:
        locks = lock_files_for_book(book_path)
        if locks:
            print('Refusing apply: possible GnuCash lock/link file exists for selected book:', file=sys.stderr)
            for l in locks:
                print('  ' + l, file=sys.stderr)
            return 6
        try:
            backup = make_apply_backup(book_path)
            print('Created pre-credit-refund-match backup: ' + backup)
        except Exception as e:
            print('Refusing apply: could not create backup: ' + repr(e), file=sys.stderr)
            return 7

    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    out_rows = []
    applied = 0
    try:
        invoices = load_credit_invoices(con, args.from_date, args.to_date, args.include_non_credit_ids)
        refund_splits, missing_accounts = load_refund_candidates(con, accounts, args.from_date, args.to_date)
        for ma in missing_accounts:
            print(f'WARNING: refund account not found in book: {ma}', file=sys.stderr)

        for inv in invoices:
            ap_template, template_err = ap_template_for_invoice(con, inv)
            if template_err:
                status, notes, cand = 'ERROR_AP_TEMPLATE', template_err, None
            else:
                status, notes, cand = candidate_for_invoice(con, inv, refund_splits, args.refund_date_window_days, args.max_combination_size)

            apply_status = status
            apply_notes = notes
            if status == 'READY':
                if args.dry_run:
                    apply_status = 'READY_DRYRUN_ONLY'
                    if cand and cand.get('match_kind') == 'combination':
                        apply_notes = notes + ' Would attach each existing counterpart split to the credit invoice A/P lot.'
                    else:
                        apply_notes = notes + ' Would attach existing counterpart split to credit invoice A/P lot.'
                else:
                    try:
                        con.execute('BEGIN IMMEDIATE')
                        current_bal = lot_balance(con, inv['post_lot'])
                        if abs(current_bal - D(inv['lot_balance'])) > Decimal('0.01'):
                            raise RuntimeError(f'Credit lot balance changed from {fmt(D(inv["lot_balance"]))} to {fmt(current_bal)}; refresh dry run.')
                        apply_notes = notes + ' ' + apply_match(con, inv, ap_template, cand)
                        con.commit()
                        apply_status = 'APPLY_OK'
                        applied += 1
                    except Exception as e:
                        try:
                            con.rollback()
                        except Exception:
                            pass
                        apply_status = 'ERROR'
                        apply_notes = f'{type(e).__name__}: {e}'

            components = (cand or {}).get('components') or []
            refunds = [comp['refund'] for comp in components]
            refund_tx_guids = ';'.join(r.get('tx_guid', '') for r in refunds)
            refund_split_guids = ';'.join(r.get('split_guid', '') for r in refunds)
            refund_dates = ';'.join(r.get('post_date', '') for r in refunds)
            refund_accounts = ';'.join(r.get('account_name', '') for r in refunds)
            refund_amounts = ';'.join(fmt(r.get('amount_abs', Decimal('0.00'))) for r in refunds)
            first_refund = refunds[0] if refunds else {}

            print(f"{inv['invoice_id']} [{inv['invoice_post_date']} amount={fmt(inv['amount_abs'])}]: {apply_status} {apply_notes}")
            out_rows.append({
                'invoice_id': inv['invoice_id'],
                'invoice_guid': inv['invoice_guid'],
                'invoice_post_date': inv['invoice_post_date'],
                'credit_lot_balance': fmt(D(inv['lot_balance'])),
                'credit_amount_abs': fmt(inv['amount_abs']),
                'match_kind': (cand or {}).get('match_kind', ''),
                'component_count': str(len(components)) if components else '',
                'refund_tx_guid': first_refund.get('tx_guid', ''),
                'refund_split_guid': first_refund.get('split_guid', ''),
                'refund_date': first_refund.get('post_date', ''),
                'refund_account': first_refund.get('account_name', ''),
                'refund_amount': fmt(first_refund.get('amount_abs', Decimal('0.00'))) if first_refund else '',
                'refund_tx_guids': refund_tx_guids,
                'refund_split_guids': refund_split_guids,
                'refund_dates': refund_dates,
                'refund_accounts': refund_accounts,
                'refund_amounts': refund_amounts,
                'status': apply_status,
                'notes': apply_notes,
            })
    finally:
        con.close()

    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    with open(args.out, 'w', newline='', encoding='utf-8') as f:
        fieldnames = ['invoice_id','invoice_guid','invoice_post_date','credit_lot_balance','credit_amount_abs','match_kind','component_count','refund_tx_guid','refund_split_guid','refund_date','refund_account','refund_amount','refund_tx_guids','refund_split_guids','refund_dates','refund_accounts','refund_amounts','status','notes']
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        w.writerows(out_rows)
    print(f'Wrote result CSV: {args.out}')
    print_summary(out_rows, args.dry_run, applied)
    blockers = [r for r in out_rows if r.get('status') not in {'READY_DRYRUN_ONLY', 'APPLY_OK'}]
    return 1 if blockers else 0


if __name__ == '__main__':
    sys.exit(main())
