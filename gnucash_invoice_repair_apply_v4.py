#!/usr/bin/env python3
"""
GnuCash Amazon invoice repair apply script v4.

Intended for copied test books only. It repairs two narrow automated repair cases:
1. Amazon stored-value/rewards/gift-card payment was accidentally imported as part
of a negative [DISCOUNT:AMAZON] bill entry.
2. A posted unpaid Amazon bill is missing exactly one staged invoice line
   (Promotion Applied, Free Shipping, Gift Wrap, duplicate/missing item, or fee). The script unposts the unpaid posted
bill, updates exactly one candidate bill entry's bill price, then reposts the
bill transaction in-place using narrow SQLite updates. v4 is intentionally
limited to copied/test books and to unpaid posted invoices where the repair plan
identifies exactly one discount entry. The previous API unpost/repost path was
not reliable on some local GnuCash Python builds; this version updates the
entry, the matching posted account split, and the A/P lot split together while
verifying that the transaction remains balanced.

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
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Dict, List, Optional, Tuple

CENT = Decimal('0.01')


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


def load_plan(path: str) -> List[dict]:
    with open(path, newline='', encoding='utf-8-sig') as f:
        return list(csv.DictReader(f))


def sqlite_invoice_state(book_path: str, invoice_id: str, invoice_guid: str = '') -> dict:
    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        rows = []
        if invoice_guid:
            rows = con.execute("SELECT * FROM invoices WHERE lower(guid)=lower(?)", (norm_guid(invoice_guid),)).fetchall()
        if not rows:
            rows = con.execute("SELECT * FROM invoices WHERE id=?", (invoice_id,)).fetchall()
        if not rows:
            return {'found': False, 'posted': False, 'paid': False, 'lot_balance': Decimal('0.00')}
        def is_posted(r):
            post_txn = norm_guid(str(r['post_txn'] or ''))
            post_lot = norm_guid(str(r['post_lot'] or ''))
            return bool(post_txn and post_txn != '0'*32 and post_lot and post_lot != '0'*32)
        posted_rows = [r for r in rows if is_posted(r)]
        r = posted_rows[0] if posted_rows else rows[0]
        post_lot = norm_guid(str(r['post_lot'] or ''))
        lot_balance = Decimal('0.00')
        if post_lot and post_lot != '0'*32:
            res = con.execute("SELECT COALESCE(SUM(CAST(value_num AS REAL)/value_denom),0) AS bal FROM splits WHERE lot_guid=?", (post_lot,)).fetchone()
            lot_balance = D(res['bal'] if res else 0)
        posted = is_posted(r)
        paid = posted and abs(lot_balance) < Decimal('0.005')
        return {
            'found': True,
            'guid': norm_guid(str(r['guid'] or '')),
            'id': str(r['id'] or ''),
            'posted': posted,
            'paid': paid,
            'post_lot': post_lot,
            'post_txn': norm_guid(str(r['post_txn'] or '')),
            'lot_balance': lot_balance,
            'row_count': len(rows),
            'posted_row_count': len(posted_rows),
        }
    finally:
        con.close()


def invoice_id_matches_target(actual_id: str, target_id: str) -> bool:
    """Return True when a GnuCash invoice id safely matches the repair target.

    The repair plan may carry a GUID, but the visible invoice id still has to be
    the same document. In particular, Amazon ORDERID and ORDERID-CREDIT are
    separate vendor documents and must never be substituted for one another.
    """
    actual = (actual_id or '').strip()
    target = (target_id or '').strip()
    if not actual or not target:
        return True
    return actual == target


def invoice_id_mismatch_error(state: dict, target_id: str) -> Optional[str]:
    actual = (state.get('id') or '').strip()
    target = (target_id or '').strip()
    if invoice_id_matches_target(actual, target):
        return None
    return (
        f'Repair target ID [{target}] resolved to different GnuCash invoice ID [{actual}]. '
        'Refusing repair. Amazon ORDERID and ORDERID-CREDIT are separate documents; '
        'refresh the repair scan/plan after importing any missing credit memos.'
    )


def sqlite_entry_state(book_path: str, entry_guid: str) -> dict:
    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        r = con.execute("SELECT * FROM entries WHERE lower(guid)=lower(?)", (norm_guid(entry_guid),)).fetchone()
        if not r:
            return {'found': False}
        qty = qmoney(r['quantity_num'], r['quantity_denom'])
        if abs(qty) < Decimal('0.000001'):
            qty = Decimal('1.00')
        price = qmoney(r['b_price_num'], r['b_price_denom'])
        amount = (qty * price).quantize(CENT, rounding=ROUND_HALF_UP)
        return {
            'found': True,
            'guid': norm_guid(str(r['guid'] or '')),
            'bill': norm_guid(str(r['bill'] or '')),
            'description': str(r['description'] or ''),
            'qty': qty,
            'bill_price': price.quantize(CENT, rounding=ROUND_HALF_UP),
            'amount': amount,
        }
    finally:
        con.close()


def sqlite_invoice_total_from_entries(book_path: str, invoice_guid: str) -> Decimal:
    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        rows = con.execute("SELECT quantity_num, quantity_denom, b_price_num, b_price_denom FROM entries WHERE lower(bill)=lower(?)", (norm_guid(invoice_guid),)).fetchall()
        total = Decimal('0.00')
        for r in rows:
            qty = qmoney(r['quantity_num'], r['quantity_denom'])
            if abs(qty) < Decimal('0.000001'):
                qty = Decimal('1.00')
            price = qmoney(r['b_price_num'], r['b_price_denom'])
            total += (qty * price)
        return total.quantize(CENT, rounding=ROUND_HALF_UP)
    finally:
        con.close()


def decimal_to_num_denom(value: Decimal, places: int = 6) -> Tuple[int, int]:
    """Return a small rational representation for a Decimal."""
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
    # Trim common factors of 10 so ordinary cents become /100.
    while denom > 1 and numerator % 10 == 0 and denom % 10 == 0:
        numerator //= 10
        denom //= 10
    return int(numerator), int(denom)


def gnc_numeric_from_decimal(value: Decimal):
    from gnucash import GncNumeric
    num, den = decimal_to_num_denom(value, 2)
    return GncNumeric(int(num), int(den))


def open_gnucash_session(book_path: str):
    from gnucash import Session, SessionOpenMode
    # The bindings accept ordinary file paths for existing GnuCash files in common builds.
    # Try path first, then the explicit sqlite3 URI if needed.
    try:
        return Session(book_path, SessionOpenMode.SESSION_NORMAL_OPEN)
    except Exception:
        return Session('sqlite3:///' + os.path.abspath(book_path), SessionOpenMode.SESSION_NORMAL_OPEN)


def make_guid(guid_string: str):
    from gnucash.gnucash_core import GUID, GUIDString
    g = GUID()
    GUIDString(norm_guid(guid_string), g)
    return g


def obj_guid_string(obj) -> str:
    try:
        g = obj.GetGUID()
        for attr in ('to_string', 'ToString'):
            fn = getattr(g, attr, None)
            if callable(fn):
                val = norm_guid(str(fn()))
                if len(val) >= 32:
                    return val[-32:]
        val = norm_guid(str(g))
        if len(val) >= 32:
            return val[-32:]
    except Exception:
        pass
    return ''


def lookup_invoice(book, invoice_guid: str, invoice_id: str):
    inv = None
    if invoice_guid:
        try:
            inv = book.InvoiceLookup(make_guid(invoice_guid))
        except Exception:
            inv = None
    if inv is None and invoice_id:
        try:
            inv = book.BillLookupByID(invoice_id)
        except Exception:
            inv = None
    return inv


def lookup_entry(book, entry_guid: str):
    try:
        return book.EntryLookup(make_guid(entry_guid))
    except Exception:
        return None


def lookup_account(book, account_guid: str):
    if not account_guid:
        return None
    try:
        return book.AccountLookup(make_guid(account_guid))
    except Exception:
        return None


def to_date(value, fallback: Optional[_dt.date] = None) -> _dt.date:
    if isinstance(value, _dt.datetime):
        return value.date()
    if isinstance(value, _dt.date):
        return value
    if isinstance(value, str) and value[:10]:
        try:
            return _dt.date.fromisoformat(value[:10])
        except Exception:
            pass
    return fallback or _dt.date.today()


def book_name_is_test_copy(path: str) -> bool:
    base = os.path.basename(path).lower()
    return any(tok in base for tok in ['copy', 'test', 'apitest', 'working', 'sandbox'])


def sqlite_set_entry_bill_total_while_unposted(book_path: str, entry_guid: str, new_total: Decimal) -> Tuple[bool, str]:
    """Legacy helper retained for reference; v4 uses posted transaction SQL repair."""
    state = sqlite_entry_state(book_path, entry_guid)
    if not state.get('found'):
        return False, 'candidate entry not found before SQLite update'
    qty = Decimal(state.get('qty') or '1.00')
    if abs(qty) < Decimal('0.000001'):
        qty = Decimal('1.00')
    price = (Decimal(new_total) / qty).quantize(Decimal('0.000001'), rounding=ROUND_HALF_UP)
    num, den = decimal_to_num_denom(price, 6)
    con = sqlite3.connect(book_path)
    try:
        cur = con.execute(
            "UPDATE entries SET b_price_num=?, b_price_denom=? WHERE lower(guid)=lower(?)",
            (num, den, norm_guid(entry_guid)),
        )
        con.commit()
        if cur.rowcount != 1:
            return False, f'expected to update one entry, updated {cur.rowcount}'
        after = sqlite_entry_state(book_path, entry_guid)
        return True, f"SQLite entry update: qty={qty} price={price} ({num}/{den}); entry amount now {fmt(after.get('amount', Decimal('0.00')))}"
    finally:
        con.close()


def split_decimal(row) -> Decimal:
    return qmoney(row['value_num'], row['value_denom']).quantize(CENT, rounding=ROUND_HALF_UP)


def num_for_existing_denom(value: Decimal, denom) -> Tuple[int, int]:
    den = int(denom or 100)
    if den == 0:
        den = 100
    num = int((Decimal(value) * Decimal(den)).quantize(Decimal('1'), rounding=ROUND_HALF_UP))
    return num, den


def txn_balance(con: sqlite3.Connection, tx_guid: str) -> Decimal:
    con.row_factory = sqlite3.Row
    rows = con.execute("SELECT value_num, value_denom FROM splits WHERE lower(tx_guid)=lower(?)", (norm_guid(tx_guid),)).fetchall()
    total = Decimal('0.00')
    for r in rows:
        total += qmoney(r['value_num'], r['value_denom'])
    return total.quantize(CENT, rounding=ROUND_HALF_UP)



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
    # Permit accidental Root Account prefix mismatch in either direction.
    for guid, name in amap.items():
        n = normalize_account_name(name)
        if n == ('root account:' + want) or want == ('root account:' + n):
            return guid
    return ''


def sqlite_add_posted_invoice_line_repair(book_path: str, row: dict) -> Tuple[str, str]:
    """Add one missing bill entry and matching posted split to an unpaid posted invoice.

    Guardrails:
      * invoice must exist, be posted, and be unpaid;
      * current entry total must equal the plan's book total;
      * the new line amount must reconcile the bill total to the plan's expected total;
      * the target account must resolve to exactly one GnuCash account name;
      * the posted transaction must remain balanced after adding the split and adjusting A/P.
    """
    order_id = (row.get('target_id') or '').strip()
    inv_guid = norm_guid(row.get('gnucash_invoice_guid') or '')
    amount = D(row.get('new_entry_amount') or '0.00')
    description = (row.get('new_entry_description') or '').strip() or 'Amazon adjustment'
    action_text = (row.get('new_entry_action') or '').strip()
    account_name = (row.get('new_entry_account') or '').strip()
    notes_text = (row.get('new_entry_notes') or '').strip()
    source = (row.get('new_entry_source') or '').strip()
    expected_total = D(row.get('expected_invoice_total') or '0.00')
    plan_book_total = D(row.get('book_invoice_total') or '0.00')
    delta = amount
    notes = []

    if abs(amount) < Decimal('0.005'):
        return 'ERROR', 'New entry amount is zero; refusing missing-line repair.'
    if not account_name:
        return 'ERROR', 'New entry account is blank; refusing missing-line repair.'

    state = sqlite_invoice_state(book_path, order_id, inv_guid)
    if not state.get('found'):
        return 'ERROR', 'Invoice not found before missing-line SQL repair.'
    mismatch = invoice_id_mismatch_error(state, order_id)
    if mismatch:
        return 'ERROR', mismatch
    if not state.get('posted'):
        return 'ERROR', 'Invoice is not posted; missing-line SQL repair not applicable.'
    if state.get('paid'):
        return 'ERROR', 'Invoice is paid; refusing missing-line SQL repair.'
    post_txn = norm_guid(state.get('post_txn') or '')
    post_lot = norm_guid(state.get('post_lot') or '')
    if not post_txn or not post_lot:
        return 'ERROR', 'Invoice does not have a valid post_txn/post_lot.'

    before_total = sqlite_invoice_total_from_entries(book_path, inv_guid)
    notes.append(f'before entry total={fmt(before_total)}')
    if abs(before_total - plan_book_total) > Decimal('0.01'):
        return 'ERROR', f'Current entry total {fmt(before_total)} does not match repair-plan book total {fmt(plan_book_total)}; refresh plan before applying.'
    expected_after_by_delta = (before_total + delta).quantize(CENT, rounding=ROUND_HALF_UP)
    notes.append(f'new entry amount={fmt(amount)}; expected after={fmt(expected_after_by_delta)}')
    if abs(expected_after_by_delta - expected_total) > Decimal('0.01'):
        return 'ERROR', f'New line would produce invoice total {fmt(expected_after_by_delta)}, not expected {fmt(expected_total)}.'

    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        con.execute('BEGIN IMMEDIATE')
        inv = con.execute("SELECT * FROM invoices WHERE lower(guid)=lower(?)", (inv_guid,)).fetchone()
        if inv is None:
            con.rollback(); return 'ERROR', 'Invoice disappeared before update.'
        account_guid = sqlite_account_guid_by_fullname(con, account_name)
        if not account_guid:
            con.rollback(); return 'ERROR', f'Could not resolve account [{account_name}] in GnuCash book.'

        ap_splits = con.execute("SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(lot_guid)=lower(?)", (post_txn, post_lot)).fetchall()
        if len(ap_splits) != 1:
            con.rollback(); return 'ERROR', f'Expected exactly one A/P lot split for posted invoice; found {len(ap_splits)}.'
        ap = ap_splits[0]
        ap_guid = norm_guid(str(ap['guid'] or ''))
        ap_current = split_decimal(ap)
        if abs(abs(ap_current) - before_total) > Decimal('0.01'):
            con.rollback(); return 'ERROR', f'A/P lot split amount {fmt(ap_current)} does not match current invoice total {fmt(before_total)}.'

        entry_template = con.execute("SELECT * FROM entries WHERE lower(bill)=lower(?) LIMIT 1", (inv_guid,)).fetchone()
        if entry_template is None:
            con.rollback(); return 'ERROR', 'No existing entry found to use as a safe template for the missing-line insert.'
        split_template = con.execute("SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(guid)<>lower(?) LIMIT 1", (post_txn, ap_guid)).fetchone()
        if split_template is None:
            con.rollback(); return 'ERROR', 'No non-A/P posted split found to use as a safe template for the new split.'

        new_entry_guid = uuid.uuid4().hex
        ent = row_to_dict(entry_template)
        ent['guid'] = new_entry_guid
        ent['bill'] = inv_guid
        if 'invoice' in ent:
            ent['invoice'] = None
        ent['description'] = description
        if 'action' in ent:
            ent['action'] = action_text
        if 'notes' in ent:
            ent['notes'] = (notes_text + (f' Source: {source}.' if source else '')).strip()
        ent['quantity_num'], ent['quantity_denom'] = 1, 1
        bnum, bden = decimal_to_num_denom(amount, 6)
        if 'b_price_num' in ent:
            ent['b_price_num'] = bnum
            ent['b_price_denom'] = bden
        if 'i_price_num' in ent:
            ent['i_price_num'] = 0
            ent['i_price_denom'] = 1
        if 'b_acct' in ent:
            ent['b_acct'] = account_guid
        if 'i_acct' in ent:
            ent['i_acct'] = None
        for col in ('b_taxable', 'b_taxincluded', 'i_taxable', 'i_taxincluded', 'billable'):
            if col in ent:
                ent[col] = 0
        for col in ('b_taxtable', 'i_taxtable', 'billto_guid', 'order_guid'):
            if col in ent:
                ent[col] = None
        sqlite_insert_dynamic(con, 'entries', ent)

        new_split_guid = uuid.uuid4().hex
        spl = row_to_dict(split_template)
        spl['guid'] = new_split_guid
        spl['tx_guid'] = post_txn
        spl['account_guid'] = account_guid
        if 'memo' in spl:
            spl['memo'] = description[:2048]
        if 'action' in spl:
            spl['action'] = action_text
        if 'lot_guid' in spl:
            spl['lot_guid'] = None
        if 'reconcile_state' in spl:
            spl['reconcile_state'] = 'n'
        if 'reconcile_date' in spl:
            spl['reconcile_date'] = None
        vnum, vden = decimal_to_num_denom(amount, 2)
        qnum, qden = decimal_to_num_denom(amount, 2)
        spl['value_num'], spl['value_denom'] = vnum, vden
        spl['quantity_num'], spl['quantity_denom'] = qnum, qden
        sqlite_insert_dynamic(con, 'splits', spl)

        if ap_current < 0:
            ap_new = (ap_current - delta).quantize(CENT, rounding=ROUND_HALF_UP)
        else:
            ap_new = (ap_current + delta).quantize(CENT, rounding=ROUND_HALF_UP)
        avnum, avden = num_for_existing_denom(ap_new, ap['value_denom'])
        aqnum, aqden = num_for_existing_denom(ap_new, ap['quantity_denom'])
        cur = con.execute("UPDATE splits SET value_num=?, value_denom=?, quantity_num=?, quantity_denom=? WHERE lower(guid)=lower(?)", (avnum, avden, aqnum, aqden, ap_guid))
        if cur.rowcount != 1:
            con.rollback(); return 'ERROR', f'Expected to update one A/P split, updated {cur.rowcount}.'
        notes.append(f'inserted entry {new_entry_guid} and split {new_split_guid}; account={account_name}; A/P split {ap_guid}: {fmt(ap_current)} -> {fmt(ap_new)}')

        bal = txn_balance(con, post_txn)
        if abs(bal) > Decimal('0.005'):
            con.rollback(); return 'ERROR', f'Posted transaction would not balance after missing-line insert; balance={fmt(bal)}. Rolled back.'
        con.commit()
    except Exception as e:
        try:
            con.rollback()
        except Exception:
            pass
        return 'ERROR', f'{type(e).__name__} during missing-line SQL repair: {e}'
    finally:
        con.close()

    final_entry_total = sqlite_invoice_total_from_entries(book_path, inv_guid)
    final_state = sqlite_invoice_state(book_path, order_id, inv_guid)
    notes.append(f'final entry total={fmt(final_entry_total)}; lot_balance={fmt(final_state.get("lot_balance", Decimal("0.00")))}; posted={"yes" if final_state.get("posted") else "no"}')
    if abs(final_entry_total - expected_total) > Decimal('0.01'):
        return 'ERROR', 'Missing-line repair wrote rows but final entry total does not match expected total. ' + ' | '.join(notes)
    if not final_state.get('posted'):
        return 'ERROR', 'Missing-line repair wrote rows but invoice no longer appears posted. ' + ' | '.join(notes)
    if final_state.get('paid'):
        return 'APPLY_WARNING', 'Missing-line repair completed but invoice now appears paid; review manually. ' + ' | '.join(notes)
    return 'APPLY_OK', 'Missing-line SQL repair applied. ' + ' | '.join(notes)


def sqlite_apply_posted_invoice_repair(book_path: str, row: dict) -> Tuple[str, str]:
    """Repair one posted, unpaid bill by updating entry price + posted splits.

    This is deliberately narrow and guarded. It assumes the candidate discount
    entry has already been selected by the web repair plan and that the invoice
    is unpaid. It updates:
      * entries.b_price_* for the candidate bill entry,
      * the posted transaction split for that entry's expense account, and
      * the A/P split carrying the invoice lot.

    The delta is applied symmetrically so the posted transaction remains balanced.
    """
    order_id = (row.get('target_id') or '').strip()
    inv_guid = norm_guid(row.get('gnucash_invoice_guid') or '')
    entry_guids = [norm_guid(x) for x in re.split(r'[;\s,]+', row.get('candidate_discount_entry_guids') or '') if norm_guid(x)]
    if len(entry_guids) != 1:
        return 'ERROR', 'Expected exactly one candidate entry GUID for posted SQL repair.'
    entry_guid = entry_guids[0]
    new_entry_amount = D(row.get('new_candidate_discount_amount') or '0.00')
    expected_total = D(row.get('expected_invoice_total') or '0.00')
    plan_book_total = D(row.get('book_invoice_total') or '0.00')

    notes = []
    state = sqlite_invoice_state(book_path, order_id, inv_guid)
    if not state.get('found'):
        return 'ERROR', 'Invoice not found before posted SQL repair.'
    mismatch = invoice_id_mismatch_error(state, order_id)
    if mismatch:
        return 'ERROR', mismatch
    if not state.get('posted'):
        return 'ERROR', 'Invoice is not posted; posted SQL repair not applicable.'
    if state.get('paid'):
        return 'ERROR', 'Invoice is paid; refusing posted SQL repair.'
    post_txn = norm_guid(state.get('post_txn') or '')
    post_lot = norm_guid(state.get('post_lot') or '')
    if not post_txn or not post_lot:
        return 'ERROR', 'Invoice does not have a valid post_txn/post_lot.'

    before_total = sqlite_invoice_total_from_entries(book_path, inv_guid)
    notes.append(f'before entry total={fmt(before_total)}')
    if abs(before_total - plan_book_total) > Decimal('0.01'):
        return 'ERROR', f'Current entry total {fmt(before_total)} does not match repair-plan book total {fmt(plan_book_total)}; refresh plan before applying.'

    entry_state = sqlite_entry_state(book_path, entry_guid)
    if not entry_state.get('found'):
        return 'ERROR', 'Candidate entry not found before posted SQL repair.'
    if norm_guid(entry_state.get('bill') or '') != inv_guid:
        return 'ERROR', 'Candidate entry belongs to a different bill GUID.'
    current_entry_amount = D(entry_state.get('amount') or '0.00')
    delta = (new_entry_amount - current_entry_amount).quantize(CENT, rounding=ROUND_HALF_UP)
    expected_after_by_delta = (before_total + delta).quantize(CENT, rounding=ROUND_HALF_UP)
    notes.append(f'candidate entry {entry_guid}: {fmt(current_entry_amount)} -> {fmt(new_entry_amount)}; delta={fmt(delta)}')
    if abs(expected_after_by_delta - expected_total) > Decimal('0.01'):
        return 'ERROR', f'Entry delta would produce invoice total {fmt(expected_after_by_delta)}, not expected {fmt(expected_total)}.'

    con = sqlite3.connect(book_path)
    con.row_factory = sqlite3.Row
    try:
        con.execute('BEGIN IMMEDIATE')
        inv = con.execute("SELECT * FROM invoices WHERE lower(guid)=lower(?)", (inv_guid,)).fetchone()
        ent = con.execute("SELECT * FROM entries WHERE lower(guid)=lower(?)", (entry_guid,)).fetchone()
        if inv is None or ent is None:
            con.rollback(); return 'ERROR', 'Invoice or entry disappeared before update.'
        entry_account = norm_guid(str(ent['b_acct'] or ''))
        if not entry_account:
            con.rollback(); return 'ERROR', 'Candidate bill entry has no b_acct expense account GUID.'

        ap_splits = con.execute("SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(lot_guid)=lower(?)", (post_txn, post_lot)).fetchall()
        if len(ap_splits) != 1:
            con.rollback(); return 'ERROR', f'Expected exactly one A/P lot split for posted invoice; found {len(ap_splits)}.'
        ap = ap_splits[0]
        ap_guid = norm_guid(str(ap['guid'] or ''))
        ap_current = split_decimal(ap)
        if abs(abs(ap_current) - before_total) > Decimal('0.01'):
            con.rollback(); return 'ERROR', f'A/P lot split amount {fmt(ap_current)} does not match current invoice total {fmt(before_total)}.'

        acct_splits_all = con.execute("SELECT * FROM splits WHERE lower(tx_guid)=lower(?) AND lower(account_guid)=lower(?) AND lower(guid)<>lower(?)", (post_txn, entry_account, ap_guid)).fetchall()
        if not acct_splits_all:
            con.rollback(); return 'ERROR', 'No posted split found for candidate entry account in invoice transaction.'

        # Multiple bill entries can share the same expense account. In that case
        # GnuCash posts multiple splits to the same account. The candidate repair
        # entry is a negative [DISCOUNT:AMAZON] line, so select the posted split
        # by the current entry amount rather than by account alone. This handles
        # orders that have both normal item lines and the discount line assigned
        # to the same expense account.
        signed_matches = [s for s in acct_splits_all if abs(split_decimal(s) - current_entry_amount) <= Decimal('0.005')]
        abs_matches = []
        if len(signed_matches) != 1:
            abs_matches = [s for s in acct_splits_all if abs(abs(split_decimal(s)) - abs(current_entry_amount)) <= Decimal('0.005')]
        if len(signed_matches) == 1:
            acct = signed_matches[0]
            selector_note = 'selected posted split by exact signed amount match'
        elif len(abs_matches) == 1:
            acct = abs_matches[0]
            selector_note = 'selected posted split by absolute amount match'
        elif len(acct_splits_all) == 1:
            acct = acct_splits_all[0]
            selector_note = 'selected the only split for candidate entry account'
        else:
            detail_parts = []
            for srow in acct_splits_all:
                detail_parts.append(f"{norm_guid(str(srow['guid'] or ''))}:{fmt(split_decimal(srow))}")
            con.rollback(); return 'ERROR', (
                f'Expected one posted split for candidate entry account and entry amount {fmt(current_entry_amount)}; '
                f'found {len(acct_splits_all)} account splits, signed_matches={len(signed_matches)}, abs_matches={len(abs_matches)}. '
                f'Candidate splits: ' + '; '.join(detail_parts)
            )
        acct_current = split_decimal(acct)
        notes.append(selector_note)

        if ap_current < 0:
            ap_new = (ap_current - delta).quantize(CENT, rounding=ROUND_HALF_UP)
        else:
            ap_new = (ap_current + delta).quantize(CENT, rounding=ROUND_HALF_UP)
        acct_new = (acct_current + delta).quantize(CENT, rounding=ROUND_HALF_UP)
        notes.append(f'A/P split {ap_guid}: {fmt(ap_current)} -> {fmt(ap_new)}')
        notes.append(f'entry account split {norm_guid(str(acct["guid"] or ""))}: {fmt(acct_current)} -> {fmt(acct_new)}')

        # Candidate entry bill price: for bill entries the amount is quantity * b_price.
        qty = qmoney(ent['quantity_num'], ent['quantity_denom'])
        if abs(qty) < Decimal('0.000001'):
            qty = Decimal('1.00')
        new_price = (new_entry_amount / qty).quantize(Decimal('0.000001'), rounding=ROUND_HALF_UP)
        bnum, bden = decimal_to_num_denom(new_price, 6)
        cur = con.execute("UPDATE entries SET b_price_num=?, b_price_denom=? WHERE lower(guid)=lower(?)", (bnum, bden, entry_guid))
        if cur.rowcount != 1:
            con.rollback(); return 'ERROR', f'Expected to update one entry row, updated {cur.rowcount}.'

        avnum, avden = num_for_existing_denom(ap_new, ap['value_denom'])
        aqnum, aqden = num_for_existing_denom(ap_new, ap['quantity_denom'])
        cur = con.execute("UPDATE splits SET value_num=?, value_denom=?, quantity_num=?, quantity_denom=? WHERE lower(guid)=lower(?)", (avnum, avden, aqnum, aqden, ap_guid))
        if cur.rowcount != 1:
            con.rollback(); return 'ERROR', f'Expected to update one A/P split, updated {cur.rowcount}.'

        acct_guid = norm_guid(str(acct['guid'] or ''))
        evnum, evden = num_for_existing_denom(acct_new, acct['value_denom'])
        eqnum, eqden = num_for_existing_denom(acct_new, acct['quantity_denom'])
        cur = con.execute("UPDATE splits SET value_num=?, value_denom=?, quantity_num=?, quantity_denom=? WHERE lower(guid)=lower(?)", (evnum, evden, eqnum, eqden, acct_guid))
        if cur.rowcount != 1:
            con.rollback(); return 'ERROR', f'Expected to update one expense/account split, updated {cur.rowcount}.'

        bal = txn_balance(con, post_txn)
        if abs(bal) > Decimal('0.005'):
            con.rollback(); return 'ERROR', f'Posted transaction would not balance after update; balance={fmt(bal)}. Rolled back.'
        con.commit()
    except Exception as e:
        try:
            con.rollback()
        except Exception:
            pass
        return 'ERROR', f'{type(e).__name__} during posted SQL repair: {e}'
    finally:
        con.close()

    final_entry_total = sqlite_invoice_total_from_entries(book_path, inv_guid)
    final_state = sqlite_invoice_state(book_path, order_id, inv_guid)
    notes.append(f'final entry total={fmt(final_entry_total)}; lot_balance={fmt(final_state.get("lot_balance", Decimal("0.00")))}; posted={"yes" if final_state.get("posted") else "no"}')
    if abs(final_entry_total - expected_total) > Decimal('0.01'):
        return 'ERROR', 'Repair wrote rows but final entry total does not match expected total. ' + ' | '.join(notes)
    if not final_state.get('posted'):
        return 'ERROR', 'Repair wrote rows but invoice no longer appears posted. ' + ' | '.join(notes)
    if final_state.get('paid'):
        return 'APPLY_WARNING', 'Repair completed but invoice now appears paid; review manually. ' + ' | '.join(notes)
    return 'APPLY_OK', 'Posted SQL repair applied. ' + ' | '.join(notes)


def api_unpost_invoice_capture_context(book_path: str, invoice_guid: str, order_id: str) -> Tuple[bool, dict, str]:
    session = None
    try:
        session = open_gnucash_session(book_path)
        book = session.book
        invoice = lookup_invoice(book, invoice_guid, order_id)
        if invoice is None:
            return False, {}, 'GnuCash API could not find invoice/bill for unpost.'
        post_acc = invoice.GetPostedAcc()
        if post_acc is None:
            return False, {}, 'Invoice has no posted account through API.'
        post_acc_guid = obj_guid_string(post_acc)
        if not post_acc_guid:
            return False, {}, 'Could not read posted account GUID through API.'
        post_date = to_date(invoice.GetDatePosted())
        try:
            due_date = to_date(invoice.GetDateDue(), post_date)
        except Exception:
            due_date = post_date
        memo = 'Reposted by gnucash_vendor_import_tool invoice repair'
        try:
            txn = invoice.GetPostedTxn()
            if txn is not None and getattr(txn, 'GetDescription', None):
                desc = txn.GetDescription()
                if desc:
                    memo = desc
        except Exception:
            pass
        try:
            invoice.Unpost(False)
        except TypeError:
            invoice.Unpost()
        session.save()
        session.end()
        session = None
        state = sqlite_invoice_state(book_path, order_id, invoice_guid)
        if state.get('posted'):
            return False, {}, 'API Unpost returned, but SQLite still shows invoice posted; refusing direct entry update.'
        return True, {'post_acc_guid': post_acc_guid, 'post_date': post_date, 'due_date': due_date, 'memo': memo}, 'API unposted invoice successfully.'
    except Exception as e:
        return False, {}, f'{type(e).__name__} during API unpost: {e}'
    finally:
        if session is not None:
            try:
                session.end()
            except Exception:
                pass


def api_repost_invoice(book_path: str, invoice_guid: str, order_id: str, context: dict) -> Tuple[bool, str]:
    session = None
    try:
        session = open_gnucash_session(book_path)
        book = session.book
        invoice = lookup_invoice(book, invoice_guid, order_id)
        if invoice is None:
            return False, 'GnuCash API could not find invoice/bill for repost.'
        post_acc = lookup_account(book, context.get('post_acc_guid', ''))
        if post_acc is None:
            return False, 'GnuCash API could not find original posted account for repost.'
        post_date = context.get('post_date') or _dt.date.today()
        due_date = context.get('due_date') or post_date
        memo = context.get('memo') or 'Reposted by gnucash_vendor_import_tool invoice repair'
        invoice.PostToAccount(post_acc, post_date, due_date, memo, True, False)
        session.save()
        session.end()
        session = None
        return True, 'API reposted invoice successfully.'
    except Exception as e:
        return False, f'{type(e).__name__} during API repost: {e}'
    finally:
        if session is not None:
            try:
                session.end()
            except Exception:
                pass


def apply_one_with_api(book_path: str, row: dict) -> Tuple[str, str]:
    """Apply one repair. Name retained for caller compatibility.

    v4/v110 uses guarded posted-invoice SQL repair instead of API unpost/repost.
    """
    action = (row.get('suggested_action') or '').strip()
    if action.startswith('add_missing_'):
        return sqlite_add_posted_invoice_line_repair(book_path, row)
    return sqlite_apply_posted_invoice_repair(book_path, row)


def lock_files_for_book(book_path: str) -> List[str]:
    candidates = [book_path + '.LCK', book_path + '.LNK']
    return [p for p in candidates if os.path.exists(p)]


def make_apply_backup(book_path: str) -> str:
    stamp = time.strftime('%Y%m%d-%H%M%S')
    backup = f'{book_path}.pre-invoice-repair-{stamp}.bak'
    shutil.copy2(book_path, backup)
    return backup

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--book', required=True)
    ap.add_argument('--plan', required=True)
    ap.add_argument('--from-date', default='2025-01-01')
    ap.add_argument('--to-date', default='')
    ap.add_argument('--out', required=True)
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--apply', action='store_true')
    ap.add_argument('--allow-non-copy-name', action='store_true')
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
            print('Created pre-repair backup: ' + backup_path)
        except Exception as e:
            print('Refusing apply: could not create same-directory backup copy: ' + repr(e), file=sys.stderr)
            return 7

    rows = load_plan(args.plan)
    out_rows = []
    any_error = False
    applied = 0
    for idx, row in enumerate(rows, start=2):
        order_id = (row.get('target_id') or '').strip()
        inv_guid = norm_guid(row.get('gnucash_invoice_guid') or '')
        action = (row.get('suggested_action') or '').strip()
        safe = (row.get('safe_for_auto_repair') or '').strip().lower()
        new_amt = row.get('new_candidate_discount_amount') or ''
        expected = D(row.get('expected_invoice_total') or '0.00')
        book_total = D(row.get('book_invoice_total') or '0.00')
        notes = []
        status = 'SKIP_NOT_SAFE'
        state = sqlite_invoice_state(book_path, order_id, inv_guid)
        entry_guids = [norm_guid(x) for x in re.split(r'[;\s,]+', row.get('candidate_discount_entry_guids') or '') if norm_guid(x)]
        entry_state = sqlite_entry_state(book_path, entry_guids[0]) if len(entry_guids) == 1 else {'found': False}
        notes.append(f"actual_book_paid={'yes' if state.get('paid') else 'no'}; lot_balance={fmt(state.get('lot_balance', Decimal('0.00')))}")
        if not state.get('found'):
            status = 'ERROR_INVOICE_NOT_FOUND'
            any_error = True
        elif not state.get('posted'):
            status = 'SKIP_UNPOSTED_INVOICE'
        elif state.get('paid'):
            status = 'SKIP_PAID_INVOICE'
        elif safe != 'yes':
            status = 'SKIP_NOT_SAFE'
            notes.append('Plan is not marked safe_for_auto_repair=yes.')
        elif action.startswith('add_missing_'):
            add_amt = D(row.get('new_entry_amount') or '0.00')
            add_desc = (row.get('new_entry_description') or '').strip()
            add_acct = (row.get('new_entry_account') or '').strip()
            if abs(add_amt) < Decimal('0.005') or not add_desc or not add_acct:
                status = 'ERROR_MISSING_NEW_ENTRY_FIELDS'
                any_error = True
                notes.append('Missing-line repair requires new_entry_amount, new_entry_description, and new_entry_account.')
            elif args.dry_run:
                status = 'READY_DRYRUN_ONLY'
                notes.append(f"Would add invoice entry amount {fmt(add_amt)} account [{add_acct}] description [{add_desc}] and adjust posted A/P split.")
            else:
                status, api_note = apply_one_with_api(book_path, row)
                notes.append(api_note)
                if status.startswith('APPLY_OK') or status.startswith('APPLY_WARNING'):
                    applied += 1
                if status == 'ERROR':
                    any_error = True
        elif len(entry_guids) != 1 or not entry_state.get('found'):
            status = 'ERROR_ENTRY_NOT_FOUND_OR_AMBIGUOUS'
            any_error = True
        elif new_amt == '':
            status = 'ERROR_NO_NEW_DISCOUNT_AMOUNT'
            any_error = True
        else:
            if args.dry_run:
                status = 'READY_DRYRUN_ONLY'
                notes.append(f"Would set candidate entry {entry_guids[0]} amount from {fmt(entry_state.get('amount', Decimal('0.00')))} to {fmt(D(new_amt))} and repost.")
            else:
                status, api_note = apply_one_with_api(book_path, row)
                notes.append(api_note)
                if status.startswith('APPLY_OK') or status.startswith('APPLY_WARNING'):
                    applied += 1
                if status == 'ERROR':
                    any_error = True
        print(f"{order_id} [{row.get('order_date','')}]: {status} {'; '.join(notes)}")
        out_rows.append({
            'line_no': idx,
            'target_id': order_id,
            'order_date': row.get('order_date',''),
            'status': status,
            'suggested_action': action,
            'safe_for_auto_repair': safe,
            'actual_book_paid': 'yes' if state.get('paid') else 'no',
            'lot_balance': fmt(state.get('lot_balance', Decimal('0.00'))),
            'book_invoice_total': fmt(book_total),
            'expected_invoice_total': fmt(expected),
            'current_discount_amount': fmt(D(row.get('candidate_discount_amount_sum') or '0.00')),
            'planned_new_discount_amount': new_amt,
            'new_entry_amount': row.get('new_entry_amount') or '',
            'new_entry_description': row.get('new_entry_description') or '',
            'new_entry_account': row.get('new_entry_account') or '',
            'candidate_entry_guids': ';'.join(entry_guids),
            'notes': ' | '.join(notes),
        })
    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    with open(args.out, 'w', newline='', encoding='utf-8') as f:
        fieldnames = ['line_no','target_id','order_date','status','suggested_action','safe_for_auto_repair','actual_book_paid','lot_balance','book_invoice_total','expected_invoice_total','current_discount_amount','planned_new_discount_amount','new_entry_amount','new_entry_description','new_entry_account','candidate_entry_guids','notes']
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader(); w.writerows(out_rows)
    print(f"Wrote result CSV: {args.out}")
    if args.apply:
        print(f"Applied rows: {applied}")
    return 1 if any_error else 0


if __name__ == '__main__':
    sys.exit(main())
