#!/usr/bin/env python3
"""
GnuCash vendor payment apply script v1.5 (v167 package).

Applies vendor payment rows from the vendor import tool's
ready payment application plan to posted, unpaid GnuCash vendor bills.

This intentionally uses narrow SQLite writes against a copied/test GnuCash book:
  * can either create new payment transactions, or match/reuse existing imported bank/gift-card transactions;
  * attaches one A/P split to the bill's existing post lot for each vendor payment row;
  * source splits use the mapped payment-source account, such as credit-card, rewards, gift-card, or stored-value asset;
  * all rows for a target invoice are applied as one atomic group.

Guardrails:
  * target invoice/bill must exist and be posted;
  * target bill lot must still be unpaid;
  * current bill lot balance must equal the grouped payment amount;
  * every mapped payment account must resolve in the book;
  * each inserted transaction must balance;
  * final bill lot balance must be zero.

Do not run while GnuCash has the same book open.
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
import uuid
from collections import OrderedDict, Counter
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Dict, List, Tuple

CENT = Decimal('0.01')

READY_STATUSES = {'ready_exact_payment', 'ready_split_payment_group'}


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


def decimal_to_num_denom(value: Decimal, places: int = 2) -> Tuple[int, int]:
    q = Decimal('1').scaleb(-places)
    value = Decimal(value).quantize(q, rounding=ROUND_HALF_UP)
    sign, digits, exponent = value.as_tuple()
    numerator = 0
    for d in digits:
        numerator = numerator * 10 + d
    if sign:
        numerator = -numerator
    if exponent < 0:
        denom = 10 ** (-exponent)
    else:
        numerator *= 10 ** exponent
        denom = 1
    while denom > 1 and numerator % 10 == 0 and denom % 10 == 0:
        numerator //= 10
        denom //= 10
    return int(numerator), int(denom)


def split_decimal(row) -> Decimal:
    return qmoney(row['value_num'], row['value_denom']).quantize(CENT, rounding=ROUND_HALF_UP)


def txn_balance(con: sqlite3.Connection, tx_guid: str) -> Decimal:
    con.row_factory = sqlite3.Row
    rows = con.execute("SELECT value_num, value_denom FROM splits WHERE lower(tx_guid)=lower(?)", (norm_guid(tx_guid),)).fetchall()
    total = Decimal('0.00')
    for r in rows:
        total += qmoney(r['value_num'], r['value_denom'])
    return total.quantize(CENT, rounding=ROUND_HALF_UP)


def lot_balance(con: sqlite3.Connection, lot_guid: str) -> Decimal:
    con.row_factory = sqlite3.Row
    res = con.execute(
        "SELECT COALESCE(SUM(CAST(value_num AS REAL)/value_denom),0) AS bal FROM splits WHERE lower(lot_guid)=lower(?)",
        (norm_guid(lot_guid),),
    ).fetchone()
    return D(res['bal'] if res else 0)


def row_to_dict(row) -> dict:
    return {k: row[k] for k in row.keys()}


def sqlite_table_columns(con: sqlite3.Connection, table: str) -> List[str]:
    return [str(r[1]) for r in con.execute(f"PRAGMA table_info({table})").fetchall()]


def sqlite_insert_dynamic(con: sqlite3.Connection, table: str, values: dict) -> None:
    cols = [c for c in sqlite_table_columns(con, table) if c in values]
    if not cols:
        raise ValueError(f'No insertable columns for {table}')
    sql = f"INSERT INTO {table} (" + ','.join(cols) + ") VALUES (" + ','.join(['?'] * len(cols)) + ")"
    con.execute(sql, [values.get(c) for c in cols])


def sqlite_account_fullname_map(con: sqlite3.Connection) -> Dict[str, str]:
    con.row_factory = sqlite3.Row
    rows = {norm_guid(str(r['guid'] or '')): row_to_dict(r) for r in con.execute("SELECT guid, name, account_type, parent_guid FROM accounts").fetchall()}
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


def normalize_account_name(s: str) -> str:
    return re.sub(r'\s+', ' ', (s or '').strip()).lower()


def sqlite_account_guid_by_fullname(con: sqlite3.Connection, fullname: str) -> str:
    want = normalize_account_name(fullname)
    if not want:
        return ''
    amap = sqlite_account_fullname_map(con)
    for guid, name in amap.items():
        if normalize_account_name(name) == want:
            return guid
    for guid, name in amap.items():
        n = normalize_account_name(name)
        if n == ('root account:' + want) or want == ('root account:' + n):
            return guid
    return ''


def load_plan(path: str) -> List[dict]:
    with open(path, newline='', encoding='utf-8-sig') as f:
        return list(csv.DictReader(f))


def group_plan_rows(rows: List[dict]) -> OrderedDict[str, List[dict]]:
    grouped: OrderedDict[str, List[dict]] = OrderedDict()
    for r in rows:
        target = (r.get('target_invoice_id') or r.get('order_id') or '').strip()
        if target not in grouped:
            grouped[target] = []
        grouped[target].append(r)
    return grouped


def sqlite_invoice_state(book_path: str, invoice_id: str) -> dict:
    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        rows = con.execute("SELECT * FROM invoices WHERE id=?", (invoice_id,)).fetchall()
        if not rows:
            return {'found': False, 'posted': False, 'paid': False, 'lot_balance': Decimal('0.00')}

        def is_posted(r):
            post_txn = norm_guid(str(r['post_txn'] or ''))
            post_lot = norm_guid(str(r['post_lot'] or ''))
            return bool(post_txn and post_txn != '0' * 32 and post_lot and post_lot != '0' * 32)

        posted_rows = [r for r in rows if is_posted(r)]
        r = posted_rows[0] if posted_rows else rows[0]
        post_lot = norm_guid(str(r['post_lot'] or ''))
        bal = Decimal('0.00')
        if post_lot and post_lot != '0' * 32:
            bal = lot_balance(con, post_lot)
        posted = is_posted(r)
        paid = posted and abs(bal) < Decimal('0.005')
        return {
            'found': True,
            'guid': norm_guid(str(r['guid'] or '')),
            'id': str(r['id'] or ''),
            'posted': posted,
            'paid': paid,
            'post_lot': post_lot,
            'post_txn': norm_guid(str(r['post_txn'] or '')),
            'lot_balance': bal,
            'row_count': len(rows),
            'posted_row_count': len(posted_rows),
        }
    finally:
        con.close()


def date_for_db(template_value, date_str: str) -> str:
    date_str = (date_str or '').strip()[:10]
    if not date_str:
        date_str = _dt.date.today().isoformat()
    tv = str(template_value or '')
    if ' ' in tv and len(tv) > 10:
        return date_str + ' 12:00:00'
    return date_str


def now_for_db(template_value) -> str:
    tv = str(template_value or '')
    if ' ' in tv and len(tv) > 10:
        return _dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    return _dt.date.today().isoformat()


def make_payment_description(target: str, row: dict) -> str:
    method = (row.get('payment_method') or '').strip()
    order_id = (row.get('order_id') or target).strip()
    vendor = (row.get('vendor') or 'vendor').strip().title()
    if method:
        return f'{vendor} payment {order_id} - {method}'
    return f'{vendor} payment {order_id}'


def split_amount_cents(row) -> Decimal:
    return qmoney(row['value_num'], row['value_denom']).quantize(CENT, rounding=ROUND_HALF_UP)


def is_blank_guid(value) -> bool:
    g = norm_guid(str(value or ''))
    return (not g) or g == '0' * 32


def split_matches_amount(row, amount: Decimal) -> bool:
    return abs(split_amount_cents(row) - D(amount)) <= Decimal('0.005')


def row_date_ymd(value) -> str:
    return str(value or '').strip()[:10]


def date_offset_days(tx_date: str, expected_date: str) -> int | None:
    """Return tx_date - expected_date in days.

    For imported vendor payment matching we intentionally use a one-sided window:
    bank/credit-card postings and stored-value activity may appear on the vendor
    payment date or a few days after it, but they should not pre-date the vendor
    payment record.  This also removes many false ambiguous matches when the same
    amount appears shortly before and shortly after the expected date.
    """
    tx_date = row_date_ymd(tx_date)
    expected_date = row_date_ymd(expected_date)
    try:
        dt = _dt.date.fromisoformat(tx_date)
        de = _dt.date.fromisoformat(expected_date)
        return (dt - de).days
    except Exception:
        return None


def find_existing_payment_candidate(con: sqlite3.Connection, *, source_account_guid: str, ap_account_guid: str,
                                    target: str, post_lot: str, desired_date: str,
                                    source_amount: Decimal, ap_amount: Decimal,
                                    date_window_days: int, vendor_hint: str = '') -> Tuple[str, dict | None, str]:
    """Return (status, candidate, note).

    Candidate actions:
      already_attached: existing transaction already has the correct A/P split attached to the bill lot.
      attach_existing_ap: existing transaction has an A/P split with no lot; attach it to the bill lot.
      convert_other_split: existing two-split imported transaction; convert the other split to A/P and attach.

    v125 matching is intentionally broader than the original source-split-first lookup:
      * it still prefers the exact signed source-account split;
      * it also searches for an existing unattached A/P split in the same transaction, because manual
        reward/gift-card entries and bank-import matches are often already transferred to A/P;
      * source-account signs are treated as a scoring signal, not the only lookup path, while the A/P
        split must have the correct payment sign for the target invoice lot.
    """
    desired_date = row_date_ymd(desired_date)
    source_abs = abs(D(source_amount))
    target_lot = norm_guid(post_lot)
    source_acct = norm_guid(source_account_guid)
    ap_acct = norm_guid(ap_account_guid)
    vendor_hint = (vendor_hint or '').strip().lower()

    candidates = []
    diagnostic_bits = []

    def split_abs_matches_amount(row, amount: Decimal) -> bool:
        return abs(abs(split_amount_cents(row)) - abs(D(amount))) <= Decimal('0.005')

    def usable_tx(tx_guid: str):
        splits = con.execute("SELECT * FROM splits WHERE lower(tx_guid)=lower(?)", (tx_guid,)).fetchall()
        if not splits:
            return []
        # Do not repurpose transactions whose non-A/P splits are already assigned to lots.
        # A/P splits may legitimately be in a standalone prepayment lot; v125 can safely
        # reassign such a split to the bill lot after checking that the old lot contains
        # only that one split.
        for sp in splits:
            lg = norm_guid(str(sp['lot_guid'] or '')) if 'lot_guid' in sp.keys() else ''
            acct = norm_guid(str(sp['account_guid'] or ''))
            if lg and lg != target_lot and lg != '0' * 32 and acct != ap_acct:
                return []
        return splits

    def standalone_ap_prepayment_lot(ap_split_row) -> bool:
        """True if ap_split_row is in a different lot that appears to be a standalone prepayment.

        This allows matching GnuCash transactions already transferred to Accounts Payable
        as vendor prepayments but not assigned to the specific bill.  We only permit the
        reassignment when the old lot contains exactly this one A/P split.
        """
        lg = norm_guid(str(ap_split_row['lot_guid'] or '')) if 'lot_guid' in ap_split_row.keys() else ''
        if is_blank_guid(lg) or lg == target_lot:
            return False
        lot_rows = con.execute("SELECT guid, account_guid FROM splits WHERE lower(lot_guid)=lower(?)", (lg,)).fetchall()
        if len(lot_rows) != 1:
            return False
        only = lot_rows[0]
        return norm_guid(str(only['guid'] or '')) == norm_guid(str(ap_split_row['guid'] or '')) and norm_guid(str(only['account_guid'] or '')) == ap_acct

    def add_candidate(action: str, tx_guid: str, source_split_guid: str, ap_split_guid: str, dd: int,
                      description: str, source_sign_ok: bool, origin: str):
        if not ap_split_guid:
            return
        key = (action, norm_guid(tx_guid), norm_guid(source_split_guid), norm_guid(ap_split_guid))
        for c in candidates:
            if c.get('_key') == key:
                return
        candidates.append({
            'action': action,
            'tx_guid': norm_guid(tx_guid),
            'source_split_guid': norm_guid(source_split_guid),
            'ap_split_guid': norm_guid(ap_split_guid),
            'date_distance': dd,
            'description': str(description or ''),
            'source_sign_ok': bool(source_sign_ok),
            'origin': origin,
            '_key': key,
        })

    # Pass 1: source-account rows by absolute amount. This catches liability/asset sign differences
    # and then validates the A/P side of the same transaction.
    src_rows = con.execute(
        """
        SELECT s.*, t.guid AS tx_guid2, t.post_date AS tx_post_date, t.description AS tx_description
        FROM splits s
        JOIN transactions t ON lower(t.guid)=lower(s.tx_guid)
        WHERE lower(s.account_guid)=lower(?)
          AND ABS(ABS(CAST(s.value_num AS REAL)/s.value_denom) - ?) < 0.0051
        """,
        (source_acct, float(source_abs)),
    ).fetchall()
    near_src = 0
    for src in src_rows:
        tx_guid = norm_guid(str(src['tx_guid'] or src['tx_guid2'] or ''))
        if not tx_guid:
            continue
        dd = date_offset_days(str(src['tx_post_date'] or ''), desired_date)
        if dd is None or dd < 0 or dd > date_window_days:
            continue
        near_src += 1
        splits = usable_tx(tx_guid)
        if not splits:
            continue
        source_sign_ok = split_matches_amount(src, source_amount)
        ap_attached = []
        ap_unattached = []
        ap_reassignable = []
        for sp in splits:
            acct = norm_guid(str(sp['account_guid'] or ''))
            if acct == ap_acct and split_matches_amount(sp, ap_amount):
                lg = norm_guid(str(sp['lot_guid'] or '')) if 'lot_guid' in sp.keys() else ''
                if lg == target_lot:
                    ap_attached.append(sp)
                elif is_blank_guid(lg):
                    ap_unattached.append(sp)
                elif standalone_ap_prepayment_lot(sp):
                    ap_reassignable.append(sp)
        if ap_attached:
            add_candidate('already_attached', tx_guid, norm_guid(str(src['guid'])), norm_guid(str(ap_attached[0]['guid'])), dd, str(src['tx_description'] or ''), source_sign_ok, 'source_abs')
            continue
        if len(ap_unattached) == 1:
            add_candidate('attach_existing_ap', tx_guid, norm_guid(str(src['guid'])), norm_guid(str(ap_unattached[0]['guid'])), dd, str(src['tx_description'] or ''), source_sign_ok, 'source_abs')
            continue
        if len(ap_reassignable) == 1:
            add_candidate('reassign_existing_ap_lot', tx_guid, norm_guid(str(src['guid'])), norm_guid(str(ap_reassignable[0]['guid'])), dd, str(src['tx_description'] or ''), source_sign_ok, 'source_abs')
            continue
        # For a simple imported charge transaction, convert the one non-source split to A/P, but only
        # if that offset split already has the correct A/P-side payment sign.
        others = [sp for sp in splits if norm_guid(str(sp['guid'] or '')) != norm_guid(str(src['guid']))]
        if len(splits) == 2 and len(others) == 1 and split_matches_amount(others[0], ap_amount):
            lg = norm_guid(str(others[0]['lot_guid'] or '')) if 'lot_guid' in others[0].keys() else ''
            if is_blank_guid(lg):
                add_candidate('convert_other_split', tx_guid, norm_guid(str(src['guid'])), norm_guid(str(others[0]['guid'])), dd, str(src['tx_description'] or ''), source_sign_ok, 'source_abs')

    # Pass 2: A/P-side lookup. This is the key v123 addition for transactions that were already
    # manually/import-matched to Accounts Payable but have no lot attached yet.
    ap_rows = con.execute(
        """
        SELECT s.*, t.guid AS tx_guid2, t.post_date AS tx_post_date, t.description AS tx_description
        FROM splits s
        JOIN transactions t ON lower(t.guid)=lower(s.tx_guid)
        WHERE lower(s.account_guid)=lower(?)
          AND ABS((CAST(s.value_num AS REAL)/s.value_denom) - ?) < 0.0051
        """,
        (ap_acct, float(ap_amount)),
    ).fetchall()
    near_ap = 0
    for ap in ap_rows:
        tx_guid = norm_guid(str(ap['tx_guid'] or ap['tx_guid2'] or ''))
        if not tx_guid:
            continue
        dd = date_offset_days(str(ap['tx_post_date'] or ''), desired_date)
        if dd is None or dd < 0 or dd > date_window_days:
            continue
        near_ap += 1
        splits = usable_tx(tx_guid)
        if not splits:
            continue
        lg = norm_guid(str(ap['lot_guid'] or '')) if 'lot_guid' in ap.keys() else ''
        if lg and lg == target_lot:
            action = 'already_attached'
        elif is_blank_guid(lg):
            action = 'attach_existing_ap'
        elif standalone_ap_prepayment_lot(ap):
            action = 'reassign_existing_ap_lot'
        else:
            continue
        # Require the same transaction to contain the mapped source-account split with matching abs amount.
        srcs = [sp for sp in splits if norm_guid(str(sp['account_guid'] or '')) == source_acct and split_abs_matches_amount(sp, source_abs)]
        if len(srcs) != 1:
            continue
        src = srcs[0]
        source_sign_ok = split_matches_amount(src, source_amount)
        add_candidate(action, tx_guid, norm_guid(str(src['guid'])), norm_guid(str(ap['guid'])), dd, str(ap['tx_description'] or ''), source_sign_ok, 'ap_lookup')


    # Pass 3: split-set source-account lookup.  Some Lowe's/online orders are
    # settled by the card processor as several same-account transactions whose
    # amounts sum to the order payment total.  The earlier matcher required one
    # exact register transaction per payment row, which incorrectly flagged those
    # orders as missing even when all component transactions were imported.
    if not candidates and source_abs > Decimal('0.00'):
        partial_options = []
        start_date = desired_date
        try:
            end_date = (_dt.date.fromisoformat(desired_date) + _dt.timedelta(days=date_window_days)).isoformat()
        except Exception:
            end_date = desired_date
        vendor_desc_clause = ''
        vendor_desc_params = []
        if vendor_hint == 'lowes':
            vendor_desc_clause = " AND (lower(t.description) LIKE '%lowes%' OR lower(t.description) LIKE '%lowe%') "
        partial_rows = con.execute(
            """
            SELECT s.*, t.guid AS tx_guid2, t.post_date AS tx_post_date, t.description AS tx_description
            FROM transactions t
            JOIN splits s ON s.tx_guid=t.guid
            WHERE s.account_guid=?
              AND substr(t.post_date,1,10) BETWEEN ? AND ?
              AND ABS(CAST(s.value_num AS REAL)/s.value_denom) > 0.0049
              AND ABS(CAST(s.value_num AS REAL)/s.value_denom) <= ?
            """ + vendor_desc_clause,
            tuple([source_acct, start_date, end_date, float(source_abs + Decimal('0.005'))] + vendor_desc_params),
        ).fetchall()
        for src in partial_rows:
            tx_guid = norm_guid(str(src['tx_guid'] or src['tx_guid2'] or ''))
            if not tx_guid:
                continue
            dd = date_offset_days(str(src['tx_post_date'] or ''), desired_date)
            if dd is None or dd < 0 or dd > date_window_days:
                continue
            part_abs = abs(split_amount_cents(src)).quantize(CENT, rounding=ROUND_HALF_UP)
            if part_abs <= Decimal('0.00') or part_abs > source_abs:
                continue
            part_source_amount = part_abs if source_amount > 0 else -part_abs
            part_ap_amount = -part_source_amount
            # For split-set matching, keep the source sign strict.  This avoids
            # using a credit-card charge to clear a vendor credit memo, or a refund
            # to clear a normal bill.
            if not split_matches_amount(src, part_source_amount):
                continue
            splits = usable_tx(tx_guid)
            if not splits:
                continue
            # Existing A/P split in the same transaction, unattached or safely
            # reassignable, can be attached as one component.
            for sp in splits:
                acct = norm_guid(str(sp['account_guid'] or ''))
                if acct == ap_acct and split_matches_amount(sp, part_ap_amount):
                    lg = norm_guid(str(sp['lot_guid'] or '')) if 'lot_guid' in sp.keys() else ''
                    if lg == target_lot:
                        partial_options.append({'action': 'already_attached', 'tx_guid': tx_guid, 'source_split_guid': norm_guid(str(src['guid'])), 'ap_split_guid': norm_guid(str(sp['guid'])), 'date_distance': dd, 'description': str(src['tx_description'] or ''), 'source_sign_ok': True, 'origin': 'source_split_set', 'amount': part_abs, 'ap_amount': part_ap_amount})
                    elif is_blank_guid(lg):
                        partial_options.append({'action': 'attach_existing_ap', 'tx_guid': tx_guid, 'source_split_guid': norm_guid(str(src['guid'])), 'ap_split_guid': norm_guid(str(sp['guid'])), 'date_distance': dd, 'description': str(src['tx_description'] or ''), 'source_sign_ok': True, 'origin': 'source_split_set', 'amount': part_abs, 'ap_amount': part_ap_amount})
                    elif standalone_ap_prepayment_lot(sp):
                        partial_options.append({'action': 'reassign_existing_ap_lot', 'tx_guid': tx_guid, 'source_split_guid': norm_guid(str(src['guid'])), 'ap_split_guid': norm_guid(str(sp['guid'])), 'date_distance': dd, 'description': str(src['tx_description'] or ''), 'source_sign_ok': True, 'origin': 'source_split_set', 'amount': part_abs, 'ap_amount': part_ap_amount})
            # Simple imported two-split transaction: convert the non-source split
            # to A/P for this bill lot.
            others = [sp for sp in splits if norm_guid(str(sp['guid'] or '')) != norm_guid(str(src['guid']))]
            if len(splits) == 2 and len(others) == 1 and split_matches_amount(others[0], part_ap_amount):
                lg = norm_guid(str(others[0]['lot_guid'] or '')) if 'lot_guid' in others[0].keys() else ''
                if is_blank_guid(lg):
                    partial_options.append({'action': 'convert_other_split', 'tx_guid': tx_guid, 'source_split_guid': norm_guid(str(src['guid'])), 'ap_split_guid': norm_guid(str(others[0]['guid'])), 'date_distance': dd, 'description': str(src['tx_description'] or ''), 'source_sign_ok': True, 'origin': 'source_split_set', 'amount': part_abs, 'ap_amount': part_ap_amount})
        # De-duplicate options by source split.  Prefer the most conservative action
        # if the same transaction can be interpreted more than one way.
        action_rank = {'already_attached': 0, 'attach_existing_ap': 1, 'reassign_existing_ap_lot': 1, 'convert_other_split': 2}
        dedup = {}
        for opt in partial_options:
            key = (opt.get('tx_guid'), opt.get('source_split_guid'))
            prev = dedup.get(key)
            if prev is None or action_rank.get(opt.get('action'), 9) < action_rank.get(prev.get('action'), 9):
                dedup[key] = opt
        partial_options = sorted(dedup.values(), key=lambda o: (int(o.get('date_distance') or 0), str(o.get('description') or ''), str(o.get('tx_guid') or '')))
        subset_matches = []
        max_parts = min(6, len(partial_options))
        for n in range(2, max_parts + 1):
            for combo in __import__('itertools').combinations(partial_options, n):
                # Do not use two interpretations of the same transaction/split.
                if len({c['tx_guid'] for c in combo}) != len(combo):
                    continue
                total = sum((D(c.get('amount') or '0.00') for c in combo), Decimal('0.00')).quantize(CENT, rounding=ROUND_HALF_UP)
                if abs(total - source_abs) <= Decimal('0.005'):
                    subset_matches.append(list(combo))
        if subset_matches:
            def subset_score(combo):
                descs = [str(c.get('description') or '').lower() for c in combo]
                # Prefer all components from the same visible merchant/store text.
                same_desc_score = 0 if len(set(descs)) == 1 else 1
                return (max(int(c.get('date_distance') or 0) for c in combo), len(combo), same_desc_score, ';'.join(sorted(c.get('tx_guid','') for c in combo)))
            subset_matches.sort(key=subset_score)
            best = subset_score(subset_matches[0])[:3]
            tied = [c for c in subset_matches if subset_score(c)[:3] == best]
            if len(tied) > 1:
                diagnostic_bits.append('ambiguous split-set source matches=' + str(len(tied)))
            else:
                combo = subset_matches[0]
                candidates.append({
                    'action': 'split_existing_group',
                    'tx_guid': '+'.join(c['tx_guid'] for c in combo),
                    'source_split_guid': '+'.join(c['source_split_guid'] for c in combo),
                    'ap_split_guid': '+'.join(c['ap_split_guid'] for c in combo),
                    'date_distance': max(int(c.get('date_distance') or 0) for c in combo),
                    'description': 'split-set: ' + ' | '.join(f"{c.get('amount')} on +{c.get('date_distance')}d {str(c.get('description') or '')[:50]}" for c in combo),
                    'source_sign_ok': True,
                    'origin': 'source_split_set',
                    'parts': combo,
                })

    if not candidates:
        if near_src:
            diagnostic_bits.append(f'nearby source-account amount matches={near_src}')
        if near_ap:
            diagnostic_bits.append(f'nearby A/P payment-side matches={near_ap}')
        # Include a few same-account same-amount nearby rows for troubleshooting, regardless of sign.
        diag = con.execute(
            """
            SELECT t.post_date, t.description, s.memo,
                   (CAST(s.value_num AS REAL)/s.value_denom) AS value
            FROM splits s JOIN transactions t ON lower(t.guid)=lower(s.tx_guid)
            WHERE lower(s.account_guid)=lower(?)
              AND ABS(ABS(CAST(s.value_num AS REAL)/s.value_denom) - ?) < 0.0051
            ORDER BY ABS(julianday(substr(t.post_date,1,10)) - julianday(?)), t.post_date
            LIMIT 5
            """,
            (source_acct, float(source_abs), desired_date),
        ).fetchall()
        if diag:
            snippets = []
            sign_mismatch = 0
            for d in diag:
                cand_val = D(str(d['value'])).quantize(CENT)
                snippets.append(f"{row_date_ymd(d['post_date'])} value={cand_val} desc={str(d['description'] or d['memo'] or '')[:60]}")
                if (source_amount > 0 and cand_val < 0) or (source_amount < 0 and cand_val > 0):
                    sign_mismatch += 1
            diagnostic_bits.append('source candidates: ' + ' | '.join(snippets))
            if sign_mismatch:
                expected = 'positive/decrease/refund source split' if source_amount > 0 else 'negative/increase/charge source split'
                diagnostic_bits.append(f'same-amount source row exists but has the opposite sign; expected {expected} for this bill/credit target')
        extra = (' ' + ' ; '.join(diagnostic_bits)) if diagnostic_bits else ''
        return 'not_found', None, f'No existing imported payment transaction found in mapped account within 0..+{date_window_days} day(s).{extra}'

    def score(c):
        desc = (c.get('description') or '').lower()
        contains_target = 0 if target.lower() in desc else 1
        source_sign_score = 0 if c.get('source_sign_ok') else 1
        # Prefer transactions that are already AP-side payments, then source-side matches, then conversions.
        action_score = {'already_attached': 0, 'attach_existing_ap': 1, 'reassign_existing_ap_lot': 1, 'convert_other_split': 2, 'split_existing_group': 3}.get(c.get('action'), 9)
        origin_score = 0 if c.get('origin') == 'ap_lookup' else 1
        return (int(c.get('date_distance') or 0), contains_target, source_sign_score, action_score, origin_score, c.get('tx_guid',''))
    candidates.sort(key=score)
    best_score = score(candidates[0])[:5]
    tied = [c for c in candidates if score(c)[:5] == best_score]
    if len(tied) > 1:
        clean_ties = []
        for c in tied:
            cc = dict(c)
            cc.pop('_key', None)
            clean_ties.append(cc)
        details = '; '.join(f"{c['tx_guid']} {c.get('description','')[:80]} origin={c.get('origin')} sign_ok={c.get('source_sign_ok')}" for c in clean_ties[:5])
        return 'ambiguous', {'ties': clean_ties}, f'Ambiguous existing payment candidates: {details}'
    c = candidates[0]
    c.pop('_key', None)
    return 'ok', c, describe_existing_candidate(c, source_abs)


def existing_candidate_key(candidate: dict | None) -> str:
    """Stable identity used to prevent assigning one imported transaction twice."""
    if not candidate:
        return ''
    if candidate.get('action') == 'split_existing_group':
        parts = candidate.get('parts') or []
        return 'split_existing_group:' + '|'.join(sorted(existing_candidate_key(p) for p in parts))
    return ':'.join([
        str(candidate.get('tx_guid') or ''),
        str(candidate.get('source_split_guid') or ''),
        str(candidate.get('ap_split_guid') or ''),
    ])


def existing_candidate_blob(candidate: dict | None) -> str:
    if not candidate:
        return ''
    bits = [str(candidate.get('description') or ''), str(candidate.get('tx_guid') or ''),
            str(candidate.get('source_split_guid') or ''), str(candidate.get('ap_split_guid') or '')]
    for part in candidate.get('parts') or []:
        bits.append(existing_candidate_blob(part))
    return ' '.join(bits).lower()


def payment_row_identity_tokens(row: dict) -> list[str]:
    """Tokens carried from the scraped/normalized payment row into the ready plan.

    Lowe's duplicate My Lowe's Money rows can have the same date, amount, and order.
    v173 prefers a candidate whose GnuCash description contains the row's unique
    normalized/export token, e.g. GC2758-ROW003, before falling back to deterministic
    duplicate-pool allocation.
    """
    hay = ' '.join(str(row.get(k) or '') for k in (
        'payment_id', 'unique_payment_ref', 'payment_ref', 'payment_key', 'notes',
        'source_row', 'source_row_key', 'scraper_payment_key'
    ))
    out = []
    import re
    for m in re.finditer(r'\b[A-Z]{1,6}\d{2,12}[-_ ]?ROW\d{1,6}\b', hay, flags=re.I):
        tok = m.group(0).replace(' ', '-').upper()
        out.append(tok)
    # Also preserve bracketed refs from descriptions such as [GC2758-ROW003].
    for m in re.finditer(r'\[([^\]]{4,80})\]', hay):
        tok = m.group(1).strip()
        if tok:
            out.append(tok.upper())
    seen = set(); dedup = []
    for t in out:
        if t not in seen:
            seen.add(t); dedup.append(t)
    return dedup


def describe_existing_candidate(candidate: dict, source_abs: Decimal) -> str:
    if candidate.get('action') == 'split_existing_group':
        part_text = ' ; '.join(f"{p.get('amount')} tx={p.get('tx_guid')} +{p.get('date_distance')}d {str(p.get('description') or '')[:60]}" for p in (candidate.get('parts') or []))
        return f"Matched existing split-set transactions count={len(candidate.get('parts') or [])} total={source_abs} origin={candidate.get('origin')} max_date_distance={candidate.get('date_distance')}: {part_text}"
    return f"Matched existing transaction {candidate['tx_guid']} action={candidate['action']} origin={candidate.get('origin')} date_distance={candidate['date_distance']} source_sign_ok={candidate.get('source_sign_ok')} description={candidate.get('description','')[:120]}"


def resolve_ambiguous_existing_payment_plans(row_plans: list[dict]) -> None:
    """Resolve safe duplicate-tender ambiguity within a single invoice group.

    This does not invent matches. It only chooses among candidate transactions already
    accepted by find_existing_payment_candidate() for the same amount/account/date window.
    It is meant for Lowe's duplicate stored-value/card tenders where the scrape says the
    invoice has N payment rows and the GnuCash register has N matching imported rows, but
    identical denomination rows are interchangeable.
    """
    used = set()
    for rp in row_plans:
        cand = rp.get('candidate')
        if cand and rp.get('existing_status') != 'ambiguous':
            key = existing_candidate_key(cand)
            if key:
                used.add(key)

    ambiguous = [rp for rp in row_plans if rp.get('existing_status') == 'ambiguous' and isinstance(rp.get('candidate'), dict)]
    if not ambiguous:
        return

    def ties_for(rp):
        out = []
        for c in (rp.get('candidate') or {}).get('ties') or []:
            key = existing_candidate_key(c)
            if not key or key in used:
                continue
            out.append(c)
        return out

    # Pass 1: exact normalized row token in the existing transaction description.
    changed = True
    while changed:
        changed = False
        for rp in ambiguous:
            if rp.get('existing_status') != 'ambiguous':
                continue
            tokens = payment_row_identity_tokens(rp.get('row') or {})
            if not tokens:
                continue
            matches = []
            for c in ties_for(rp):
                blob = existing_candidate_blob(c).upper()
                if any(tok in blob for tok in tokens):
                    matches.append(c)
            unique = []
            seen = set()
            for c in matches:
                key = existing_candidate_key(c)
                if key not in seen:
                    seen.add(key); unique.append(c)
            if len(unique) == 1:
                c = unique[0]
                rp['candidate'] = c
                rp['existing_status'] = 'ok'
                rp['existing_note'] = describe_existing_candidate(c, abs(D(rp.get('source_amount') or '0.00'))) + ' Resolved duplicate-tender ambiguity by normalized payment-row token.'
                used.add(existing_candidate_key(c))
                changed = True

    # Pass 2: deterministic duplicate-pool allocation for still-ambiguous rows.
    # Group by the actual payment need; if every row has at least one remaining
    # candidate and the candidate pool has enough unique entries, assign a stable
    # one-to-one mapping.  This uses the scraped ready plan as the authority for how
    # many duplicate tenders should exist.
    remaining = [rp for rp in ambiguous if rp.get('existing_status') == 'ambiguous']
    groups = {}
    for rp in remaining:
        row = rp.get('row') or {}
        sig = (
            str(rp.get('acct_guid') or ''),
            str(D(rp.get('source_amount') or '0.00').quantize(CENT)),
            str(D(rp.get('ap_amount') or '0.00').quantize(CENT)),
            str(rp.get('pdate') or ''),
            str(row.get('target_invoice_id') or row.get('order_id') or ''),
            str(row.get('payment_method') or ''),
        )
        groups.setdefault(sig, []).append(rp)

    for sig, rps in groups.items():
        cand_by_key = {}
        for rp in rps:
            for c in ties_for(rp):
                cand_by_key.setdefault(existing_candidate_key(c), c)
        if len(cand_by_key) < len(rps):
            continue
        # Only use candidates that are available to each row in this duplicate group.
        ordered_rps = sorted(rps, key=lambda rp: ('|'.join(payment_row_identity_tokens(rp.get('row') or {})), str((rp.get('row') or {}).get('notes') or ''), str((rp.get('row') or {}).get('payment_id') or '')))
        ordered_candidates = sorted(cand_by_key.values(), key=lambda c: (int(c.get('date_distance') or 0), existing_candidate_blob(c), existing_candidate_key(c)))
        assigned = []
        local_used = set()
        ok = True
        for rp in ordered_rps:
            available_keys = {existing_candidate_key(c) for c in ties_for(rp)}
            chosen = None
            # Prefer token match for this row, then first unused candidate.
            toks = payment_row_identity_tokens(rp.get('row') or {})
            for c in ordered_candidates:
                ck = existing_candidate_key(c)
                if ck in local_used or ck not in available_keys:
                    continue
                blob = existing_candidate_blob(c).upper()
                if toks and any(tok in blob for tok in toks):
                    chosen = c; break
            if chosen is None:
                for c in ordered_candidates:
                    ck = existing_candidate_key(c)
                    if ck not in local_used and ck in available_keys:
                        chosen = c; break
            if chosen is None:
                ok = False; break
            local_used.add(existing_candidate_key(chosen))
            assigned.append((rp, chosen))
        if not ok or len(assigned) != len(rps):
            continue
        for rp, c in assigned:
            rp['candidate'] = c
            rp['existing_status'] = 'ok'
            rp['existing_note'] = describe_existing_candidate(c, abs(D(rp.get('source_amount') or '0.00'))) + ' Resolved duplicate-tender ambiguity by one-to-one allocation within the scraped invoice payment group.'
            used.add(existing_candidate_key(c))

def attach_or_convert_existing_payment(con: sqlite3.Connection, candidate: dict, *, ap_account_guid: str, post_lot: str,
                                       target: str, ap_amount: Decimal) -> str:
    action = candidate.get('action')
    if action == 'split_existing_group':
        notes = []
        for part in candidate.get('parts') or []:
            notes.append(attach_or_convert_existing_payment(
                con, part, ap_account_guid=ap_account_guid, post_lot=post_lot,
                target=target, ap_amount=D(part.get('ap_amount') or '0.00')
            ))
        txs = ','.join(str(p.get('tx_guid','')) for p in (candidate.get('parts') or []))
        return f'Matched existing split-set payment transactions [{txs}] to bill lot; parts={len(candidate.get("parts") or [])}.'
    tx_guid = candidate['tx_guid']
    ap_split_guid = candidate.get('ap_split_guid')
    if action == 'already_attached':
        return f'Existing payment transaction {tx_guid} was already attached to the target bill lot.'
    if not ap_split_guid:
        raise RuntimeError('Existing payment candidate has no A/P/offset split GUID to update.')
    if action not in {'attach_existing_ap', 'convert_other_split', 'reassign_existing_ap_lot'}:
        raise RuntimeError(f'Unsupported existing payment match action: {action}.')
    updates = {
        'account_guid': norm_guid(ap_account_guid),
        'lot_guid': norm_guid(post_lot),
        'memo': f'Payment for vendor bill {target}'[:2048],
        'action': 'Payment',
    }
    # Preserve value/quantity; the candidate was only accepted if the split amount already matched ap_amount.
    cols = sqlite_table_columns(con, 'splits')
    set_parts = []
    vals = []
    for k, v in updates.items():
        if k in cols:
            set_parts.append(f"{k}=?")
            vals.append(v)
    vals.append(norm_guid(ap_split_guid))
    con.execute("UPDATE splits SET " + ', '.join(set_parts) + " WHERE lower(guid)=lower(?)", vals)
    bal = txn_balance(con, tx_guid)
    if abs(bal) > Decimal('0.005'):
        raise RuntimeError(f'Existing payment transaction {tx_guid} would not balance after attaching; balance={fmt(bal)}.')
    return f'Matched existing payment transaction {tx_guid}; action={action}; attached split {ap_split_guid} to bill lot.'


def apply_payment_group(book_path: str, target: str, group_rows: List[dict], do_apply: bool,
                        match_existing: bool = False, create_missing: bool = True,
                        date_window_days: int = 2) -> Tuple[List[Tuple[dict, str, str]], bool, int]:
    """Return per-row result tuples, any_error, applied_count."""
    results: List[Tuple[dict, str, str]] = []
    applied = 0

    ready_rows = [r for r in group_rows if (r.get('status') or '').strip() in READY_STATUSES]
    if len(ready_rows) != len(group_rows):
        for r in group_rows:
            results.append((r, 'SKIP_NOT_READY', f"Plan status [{r.get('status','')}] is not a ready payment status."))
        return results, False, 0

    group_sum = sum((D(r.get('amount_abs') or r.get('amount_signed') or '0.00') for r in ready_rows), Decimal('0.00')).quantize(CENT, rounding=ROUND_HALF_UP)
    state = sqlite_invoice_state(book_path, target)
    if not state.get('found'):
        for r in ready_rows:
            results.append((r, 'ERROR_INVOICE_NOT_FOUND', 'Target invoice/bill not found in GnuCash book.'))
        return results, True, 0
    if not state.get('posted'):
        for r in ready_rows:
            results.append((r, 'SKIP_UNPOSTED_INVOICE', 'Target invoice/bill is not posted.'))
        return results, False, 0
    lot_bal = D(state.get('lot_balance') or '0.00')
    if abs(lot_bal) < Decimal('0.005'):
        for r in ready_rows:
            results.append((r, 'SKIP_ALREADY_PAID', f'Target bill lot balance is already zero; lot_balance={fmt(lot_bal)}.'))
        return results, False, 0
    if abs(abs(lot_bal) - group_sum) > Decimal('0.01'):
        for r in ready_rows:
            results.append((r, 'ERROR_GROUP_SUM_MISMATCH', f'Grouped payment amount {fmt(group_sum)} does not match current bill lot balance {fmt(lot_bal)}. Refresh plan.'))
        return results, True, 0

    plan_totals = {D(r.get('book_invoice_total') or '0.00') for r in ready_rows if (r.get('book_invoice_total') or '').strip()}
    if plan_totals and any(abs(t - abs(lot_bal)) > Decimal('0.01') for t in plan_totals):
        for r in ready_rows:
            results.append((r, 'ERROR_STALE_PLAN', f'Plan book total(s) {sorted(fmt(t) for t in plan_totals)} do not match current lot balance {fmt(lot_bal)}. Refresh plan.'))
        return results, True, 0

    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        post_txn = con.execute("SELECT * FROM transactions WHERE lower(guid)=lower(?)", (state['post_txn'],)).fetchone()
        if post_txn is None:
            raise RuntimeError('Invoice posted transaction not found.')
        ap_splits = con.execute(
            "SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(lot_guid)=lower(?)",
            (state['post_txn'], state['post_lot']),
        ).fetchall()
        if len(ap_splits) != 1:
            raise RuntimeError(f'Expected exactly one posted A/P lot split; found {len(ap_splits)}.')
        ap_template = ap_splits[0]
        ap_account_guid = norm_guid(str(ap_template['account_guid'] or ''))
        if not ap_account_guid:
            raise RuntimeError('Posted A/P split has no account GUID.')

        account_cache: Dict[str, str] = {}
        for r in ready_rows:
            acct_name = (r.get('mapped_account') or '').strip()
            if not acct_name:
                raise RuntimeError(f'Blank mapped account for {target}.')
            if acct_name not in account_cache:
                account_guid = sqlite_account_guid_by_fullname(con, acct_name)
                if not account_guid:
                    raise RuntimeError(f'Could not resolve mapped account [{acct_name}] in GnuCash book.')
                account_cache[acct_name] = account_guid

        row_plans = []
        for r in ready_rows:
            amt = D(r.get('amount_abs') or r.get('amount_signed') or '0.00')
            if amt <= Decimal('0.00'):
                raise RuntimeError(f'Non-positive payment amount for target {target}: {fmt(amt)}')
            acct_name = (r.get('mapped_account') or '').strip()
            acct_guid = account_cache[acct_name]
            pdate = (r.get('gnucash_payment_date') or r.get('amazon_payment_date') or '').strip()[:10]
            if not pdate:
                raise RuntimeError(f'Blank payment date for target {target}.')
            if lot_bal < 0:
                ap_amount = amt
                source_amount = -amt
            else:
                ap_amount = -amt
                source_amount = amt
            existing_status = 'not_requested'
            candidate = None
            existing_note = ''
            if match_existing:
                existing_status, candidate, existing_note = find_existing_payment_candidate(
                    con,
                    source_account_guid=acct_guid,
                    ap_account_guid=ap_account_guid,
                    target=target,
                    post_lot=state['post_lot'],
                    desired_date=pdate,
                    source_amount=source_amount,
                    ap_amount=ap_amount,
                    date_window_days=date_window_days,
                    vendor_hint=str(r.get('vendor') or ''),
                )
                if existing_status == 'not_found' and not create_missing and do_apply:
                    raise RuntimeError(existing_note + f' Mapped account=[{acct_name}]. Verify all bank/card/stored-value transactions for this account are imported and rerun the dry run.')
            row_plans.append({
                'row': r, 'amount': amt, 'acct_guid': acct_guid, 'acct_name': acct_name, 'pdate': pdate,
                'ap_amount': ap_amount, 'source_amount': source_amount,
                'existing_status': existing_status, 'candidate': candidate, 'existing_note': existing_note,
            })

        if match_existing:
            resolve_ambiguous_existing_payment_plans(row_plans)

        if not do_apply:
            for rp in row_plans:
                r = rp['row']
                if rp['candidate']:
                    status = 'READY_MATCH_EXISTING_DRYRUN'
                    note = f"Would match existing transaction instead of creating a duplicate. {rp['existing_note']} group_sum={fmt(group_sum)} lot_balance={fmt(lot_bal)}."
                elif rp.get('existing_status') == 'ambiguous':
                    status = 'ERROR_AMBIGUOUS_EXISTING_PAYMENT_MATCH'
                    note = f"Ambiguous existing imported payment match in mapped account=[{rp['acct_name']}]. {rp['existing_note']} If this is a duplicate stored-value/card tender for the same invoice, re-export the ready plan with v173 so payment-row identity tokens are included; otherwise review duplicate imported transactions manually."
                elif match_existing and not create_missing:
                    status = 'ERROR_NO_EXISTING_PAYMENT_MATCH'
                    note = f"Would not create a new payment because --no-create-missing was used. Mapped account=[{rp['acct_name']}]. {rp['existing_note']} Verify all bank/card/stored-value transactions for this mapped account are imported into the selected test book, or correct the payment-method mapping/date window."
                elif match_existing:
                    status = 'READY_CREATE_MISSING_DRYRUN'
                    note = f"No existing imported transaction matched; would create new payment. {rp['existing_note']} group_sum={fmt(group_sum)} lot_balance={fmt(lot_bal)}."
                else:
                    status = 'READY_DRYRUN_ONLY'
                    note = f"Would create payment {fmt(rp['amount'])} on {rp['pdate']} from [{rp['acct_name']}] to bill lot; group_sum={fmt(group_sum)} lot_balance={fmt(lot_bal)}."
                results.append((r, status, note))
            group_error = any(str(st).startswith('ERROR') for _, st, _ in results)
            return results, group_error, 0
    except Exception as e:
        con.close()
        note = f'{type(e).__name__} during payment dry-run validation group for {target}: {e}.'
        for r in ready_rows:
            results.append((r, 'ERROR', note))
        return results, True, 0

    created_or_matched: List[str] = []
    try:
        for rp in row_plans:
            if rp.get('existing_status') == 'ambiguous':
                raise RuntimeError(f"Ambiguous existing imported payment match for {target}: {rp.get('existing_note','')}")
        con.execute('BEGIN IMMEDIATE')
        current_lot_bal = lot_balance(con, state['post_lot'])
        if abs(abs(current_lot_bal) - group_sum) > Decimal('0.01'):
            raise RuntimeError(f'Current lot balance {fmt(current_lot_bal)} no longer matches group payment sum {fmt(group_sum)}.')

        for rp in row_plans:
            r = rp['row']
            amt = rp['amount']
            desc = make_payment_description(target, r)
            method = (r.get('payment_method') or '').strip()
            if rp['candidate']:
                note = attach_or_convert_existing_payment(
                    con, rp['candidate'], ap_account_guid=ap_account_guid, post_lot=state['post_lot'],
                    target=target, ap_amount=rp['ap_amount'],
                )
                created_or_matched.append('existing:' + rp['candidate']['tx_guid'])
                continue

            if match_existing and not create_missing:
                raise RuntimeError(f"No existing imported payment transaction matched for {target} {fmt(amt)} on {rp['pdate']} and creation is disabled.")

            tx_guid = uuid.uuid4().hex
            tx = row_to_dict(post_txn)
            tx['guid'] = tx_guid
            if 'num' in tx:
                tx['num'] = ''
            if 'post_date' in tx:
                tx['post_date'] = date_for_db(post_txn['post_date'], rp['pdate'])
            if 'enter_date' in tx:
                tx['enter_date'] = now_for_db(post_txn['enter_date'])
            if 'description' in tx:
                tx['description'] = desc[:2048]
            sqlite_insert_dynamic(con, 'transactions', tx)

            ap_split_guid = uuid.uuid4().hex
            ap_split = row_to_dict(ap_template)
            ap_split['guid'] = ap_split_guid
            ap_split['tx_guid'] = tx_guid
            ap_split['account_guid'] = ap_account_guid
            if 'memo' in ap_split:
                ap_split['memo'] = f'Payment for vendor bill {target}'[:2048]
            if 'action' in ap_split:
                ap_split['action'] = 'Payment'
            if 'lot_guid' in ap_split:
                ap_split['lot_guid'] = state['post_lot']
            if 'reconcile_state' in ap_split:
                ap_split['reconcile_state'] = 'n'
            if 'reconcile_date' in ap_split:
                ap_split['reconcile_date'] = None
            vnum, vden = decimal_to_num_denom(rp['ap_amount'], 2)
            ap_split['value_num'], ap_split['value_denom'] = vnum, vden
            ap_split['quantity_num'], ap_split['quantity_denom'] = vnum, vden
            sqlite_insert_dynamic(con, 'splits', ap_split)

            source_split_guid = uuid.uuid4().hex
            src_split = row_to_dict(ap_template)
            src_split['guid'] = source_split_guid
            src_split['tx_guid'] = tx_guid
            src_split['account_guid'] = rp['acct_guid']
            if 'memo' in src_split:
                src_split['memo'] = (f'{str(r.get("vendor") or "Vendor").title()} {method} {target}' if method else f'{str(r.get("vendor") or "Vendor").title()} payment {target}')[:2048]
            if 'action' in src_split:
                src_split['action'] = 'Payment'
            if 'lot_guid' in src_split:
                src_split['lot_guid'] = None
            if 'reconcile_state' in src_split:
                src_split['reconcile_state'] = 'n'
            if 'reconcile_date' in src_split:
                src_split['reconcile_date'] = None
            vnum, vden = decimal_to_num_denom(rp['source_amount'], 2)
            src_split['value_num'], src_split['value_denom'] = vnum, vden
            src_split['quantity_num'], src_split['quantity_denom'] = vnum, vden
            sqlite_insert_dynamic(con, 'splits', src_split)

            bal = txn_balance(con, tx_guid)
            if abs(bal) > Decimal('0.005'):
                raise RuntimeError(f'Inserted payment transaction {tx_guid} would not balance; balance={fmt(bal)}.')
            created_or_matched.append('created:' + tx_guid)

        final_bal = lot_balance(con, state['post_lot'])
        if abs(final_bal) > Decimal('0.005'):
            raise RuntimeError(f'Final bill lot balance would be {fmt(final_bal)}, not zero.')
        con.commit()

        for rp, marker in zip(row_plans, created_or_matched):
            r = rp['row']
            if marker.startswith('existing:'):
                status = 'APPLY_OK_MATCHED_EXISTING'
                note = f"Matched existing transaction {marker.split(':',1)[1]}; amount={fmt(rp['amount'])} date={rp['pdate']} source=[{rp['acct_name']}]; final lot balance=0.00."
            else:
                status = 'APPLY_OK_CREATED'
                note = f"Created payment transaction {marker.split(':',1)[1]}; amount={fmt(rp['amount'])} date={rp['pdate']} source=[{rp['acct_name']}]; final lot balance=0.00."
            results.append((r, status, note))
            applied += 1
        return results, False, applied
    except Exception as e:
        try:
            con.rollback()
        except Exception:
            pass
        note = f'{type(e).__name__} during payment apply group for {target}: {e}. Rolled back group.'
        for r in ready_rows:
            results.append((r, 'ERROR', note))
        return results, True, 0
    finally:
        con.close()


def book_name_is_test_copy(path: str) -> bool:
    base = os.path.basename(path).lower()
    return any(tok in base for tok in ['copy', 'test', 'apitest', 'working', 'sandbox'])


def lock_files_for_book(book_path: str) -> List[str]:
    candidates = [book_path + '.LCK', book_path + '.LNK']
    return [p for p in candidates if os.path.exists(p)]


def make_apply_backup(book_path: str) -> str:
    stamp = time.strftime('%Y%m%d-%H%M%S')
    backup = f'{book_path}.pre-payment-apply-{stamp}.bak'
    shutil.copy2(book_path, backup)
    return backup


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--book', required=True)
    ap.add_argument('--plan', required=True)
    ap.add_argument('--out', required=True)
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--apply', action='store_true')
    ap.add_argument('--allow-non-copy-name', action='store_true')
    ap.add_argument('--match-existing', action='store_true', help='Prefer matching/reclassifying existing imported bank/gift-card transactions instead of creating duplicates.')
    ap.add_argument('--no-create-missing', action='store_true', help='When --match-existing is used, fail/skip if an existing transaction is not found instead of creating a new one.')
    ap.add_argument('--match-date-window-days', type=int, default=5, help='Allowed one-sided day window when matching existing imported transactions: expected date through expected date + N days. Default: 5.')
    args = ap.parse_args()

    book_path = os.path.abspath(args.book)
    if args.apply and not args.allow_non_copy_name and not book_name_is_test_copy(book_path):
        print('Refusing apply: book filename does not look like a copy/test/sandbox file.', file=sys.stderr)
        print('Rename the copied book with copy/test/apitest/working/sandbox in the filename, or pass --allow-non-copy-name manually.', file=sys.stderr)
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
            print('Close GnuCash completely, or remove stale lock files only after confirming the book is not open.', file=sys.stderr)
            return 6
        try:
            backup_path = make_apply_backup(book_path)
            print('Created pre-payment backup: ' + backup_path)
        except Exception as e:
            print('Refusing apply: could not create same-directory backup copy: ' + repr(e), file=sys.stderr)
            return 7

    rows = load_plan(args.plan)
    groups = group_plan_rows(rows)
    out_rows = []
    any_error = False
    applied = 0
    applied_groups = 0
    for target, group_rows in groups.items():
        group_results, group_error, group_applied = apply_payment_group(book_path, target, group_rows, args.apply, match_existing=args.match_existing, create_missing=(not args.no_create_missing), date_window_days=max(0, args.match_date_window_days))
        if group_error:
            any_error = True
        if group_applied:
            applied_groups += 1
        applied += group_applied
        for row, status, notes in group_results:
            print(f"{target} [{row.get('amazon_payment_date','')} {row.get('payment_method','')} {row.get('amount_abs','')} acct={row.get('mapped_account','')}]: {status} {notes}")
            out_rows.append({
                'vendor': row.get('vendor', ''),
                'order_id': row.get('order_id', ''),
                'target_invoice_id': target,
                'order_id_text': 'ID:' + str(row.get('order_id', '')),
                'target_invoice_id_text': 'ID:' + str(target),
                'order_date': row.get('order_date', ''),
                'amazon_payment_date': row.get('amazon_payment_date', ''),
                'gnucash_payment_date': row.get('gnucash_payment_date', ''),
                'payment_method': row.get('payment_method', ''),
                'mapped_account': row.get('mapped_account', ''),
                'amount_signed': row.get('amount_signed', ''),
                'amount_abs': row.get('amount_abs', ''),
                'plan_status': row.get('status', ''),
                'apply_status': status,
                'book_invoice_total': row.get('book_invoice_total', ''),
                'book_lot_balance': row.get('book_lot_balance', ''),
                'notes': notes,
            })

    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    with open(args.out, 'w', newline='', encoding='utf-8') as f:
        fieldnames = ['vendor','order_id','order_id_text','target_invoice_id','target_invoice_id_text','order_date','amazon_payment_date','gnucash_payment_date','payment_method','mapped_account','amount_signed','amount_abs','plan_status','apply_status','book_invoice_total','book_lot_balance','notes']
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        w.writerows(out_rows)
    print(f"Wrote result CSV: {args.out}")

    status_counts = Counter(r['apply_status'] for r in out_rows)
    error_rows = [r for r in out_rows if str(r['apply_status']).startswith('ERROR')]
    skip_rows = [r for r in out_rows if str(r['apply_status']).startswith('SKIP')]
    ready_rows = [r for r in out_rows if str(r['apply_status']).startswith('READY')]
    ok_rows = [r for r in out_rows if str(r['apply_status']).startswith('APPLY_OK')]
    error_groups = {r['target_invoice_id'] for r in error_rows}
    skip_groups = {r['target_invoice_id'] for r in skip_rows}
    ready_groups = {r['target_invoice_id'] for r in ready_rows}
    ok_groups = {r['target_invoice_id'] for r in ok_rows}

    print('')
    print('Payment apply summary:')
    print(f"  Plan rows processed: {len(out_rows)}")
    print(f"  Invoice groups processed: {len(groups)}")
    if args.dry_run:
        print(f"  Matched/ready rows: {len(ready_rows)}")
        print(f"  Matched/ready invoice groups: {len(ready_groups)}")
    else:
        print(f"  Applied OK rows: {len(ok_rows)}")
        print(f"  Applied OK invoice groups: {len(ok_groups)}")
        print(f"  Applied payment rows: {applied}")
        print(f"  Applied invoice groups: {applied_groups}")
    print(f"  Error rows: {len(error_rows)}")
    print(f"  Error invoice groups: {len(error_groups)}")
    if skip_rows:
        print(f"  Skipped rows: {len(skip_rows)}")
        print(f"  Skipped invoice groups: {len(skip_groups)}")
    print('')
    print('Status counts:')
    for status, count in status_counts.most_common():
        print(f"  {count:5d} {status}")

    if args.dry_run:
        if not error_rows and not skip_rows:
            print('')
            print('DRY RUN CLEAN: 0 errors and 0 skipped rows. Ready to proceed with the matching --apply command against the same copied/test book and plan.')
        elif error_rows:
            print('')
            print('DRY RUN HAS ERRORS: do not apply yet. Review ERROR rows in the result CSV and rerun dry-run after fixing/excluding them.')
        else:
            print('')
            print('DRY RUN HAS SKIPS: review skipped rows before applying.')
    else:
        if not error_rows and not skip_rows:
            print('')
            print('APPLY COMPLETE: 0 errors and 0 skipped rows. Existing payment transactions were matched/applied successfully.')
        elif error_rows:
            print('')
            print('APPLY COMPLETED WITH ERRORS: review ERROR rows in the result CSV/log before proceeding.')
        else:
            print('')
            print('APPLY COMPLETED WITH SKIPS: review skipped rows in the result CSV/log.')

    return 1 if any_error else 0


if __name__ == '__main__':
    sys.exit(main())
