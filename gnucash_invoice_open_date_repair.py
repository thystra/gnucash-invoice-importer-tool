#!/usr/bin/env python3
"""
GnuCash invoice Date Opened repair helper.

Reads an Amazon order-level CSV (columns: "order id", "date") and updates the
matching GnuCash invoice Date Opened value to the source order date. This is for
books where older imports accidentally created bills with Date Opened equal to
the import date while Date Posted was correct.

Default mode is dry-run. Use --apply only after testing on a copied .gnucash
book and while GnuCash is closed.
"""
from __future__ import annotations

import argparse
import csv
import datetime as dt
import os
import re
import shutil
import sqlite3
from pathlib import Path
from typing import Dict, Iterable, List, Tuple


def clean_order_id(value: str) -> str:
    return (value or '').strip()


def valid_date(value: str) -> bool:
    return bool(re.match(r'^\d{4}-\d{2}-\d{2}$', (value or '').strip()))


def load_amazon_order_dates(path: str, from_date: str = '') -> Dict[str, str]:
    out: Dict[str, str] = {}
    with open(path, newline='', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for r in reader:
            oid = clean_order_id(r.get('order id', ''))
            d = (r.get('date') or r.get('order date') or '').strip()
            if not oid or not valid_date(d):
                continue
            if from_date and d < from_date:
                continue
            out[oid] = d
    return out


def date_part(value: object) -> str:
    s = '' if value is None else str(value)
    m = re.match(r'^(\d{4}-\d{2}-\d{2})', s)
    return m.group(1) if m else ''


def with_date_part(old_value: object, new_date: str) -> str:
    old = '' if old_value is None else str(old_value)
    if re.match(r'^\d{4}-\d{2}-\d{2}', old):
        return re.sub(r'^\d{4}-\d{2}-\d{2}', new_date, old, count=1)
    return new_date + ' 00:00:00'


def table_columns(con: sqlite3.Connection, table: str) -> List[str]:
    return [r[1] for r in con.execute(f'PRAGMA table_info({table})').fetchall()]


def find_invoice_rows(con: sqlite3.Connection, order_id: str, vendor_prefix: str) -> List[sqlite3.Row]:
    cols = set(table_columns(con, 'invoices'))
    if 'billing_id' in cols:
        return con.execute(
            "SELECT rowid, * FROM invoices WHERE id=? OR billing_id=? ORDER BY rowid",
            (order_id, f'{vendor_prefix} {order_id}')
        ).fetchall()
    return con.execute("SELECT rowid, * FROM invoices WHERE id=? ORDER BY rowid", (order_id,)).fetchall()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--book', required=True, help='Path to copied .gnucash SQLite book')
    ap.add_argument('--orders-csv', required=True, help='Amazon order-level CSV with order id/date columns')
    ap.add_argument('--vendor-prefix', default='AMAZON', help='Billing ID prefix to match; default AMAZON')
    ap.add_argument('--from-date', default='', help='Only consider CSV orders on/after YYYY-MM-DD')
    ap.add_argument('--out', required=True, help='Result CSV path')
    mode = ap.add_mutually_exclusive_group()
    mode.add_argument('--dry-run', action='store_true', help='Report changes only')
    mode.add_argument('--apply', action='store_true', help='Apply changes to the book')
    args = ap.parse_args()

    book = Path(args.book)
    if not book.exists():
        raise SystemExit(f'Book not found: {book}')

    dates = load_amazon_order_dates(args.orders_csv, args.from_date)
    con = sqlite3.connect(str(book))
    con.row_factory = sqlite3.Row

    results: List[dict] = []
    try:
        # Confirm expected table/column before doing anything destructive.
        inv_cols = set(table_columns(con, 'invoices'))
        if 'date_opened' not in inv_cols:
            raise SystemExit('This GnuCash book does not have invoices.date_opened.')

        if args.apply:
            stamp = dt.datetime.now().strftime('%Y%m%d-%H%M%S')
            backup = book.with_name(book.name + f'.pre-date-opened-repair-{stamp}.bak')
            shutil.copy2(book, backup)
            print(f'Created pre-repair backup: {backup}')

        if args.apply:
            con.execute('BEGIN IMMEDIATE')

        for oid, desired in sorted(dates.items(), key=lambda kv: (kv[1], kv[0])):
            rows = find_invoice_rows(con, oid, args.vendor_prefix.upper())
            if not rows:
                continue
            for r in rows:
                old = r['date_opened']
                posted = r['date_posted'] if 'date_posted' in inv_cols else ''
                old_date = date_part(old)
                new_value = with_date_part(old, desired)
                if old_date == desired:
                    status = 'OK_ALREADY'
                else:
                    status = 'APPLY_OK' if args.apply else 'WOULD_UPDATE'
                    if args.apply:
                        con.execute('UPDATE invoices SET date_opened=? WHERE rowid=?', (new_value, r['rowid']))
                results.append({
                    'order_id': oid,
                    'invoice_guid': r['guid'] if 'guid' in inv_cols else '',
                    'old_date_opened': old,
                    'new_date_opened': new_value,
                    'date_posted': posted,
                    'status': status,
                })

        if args.apply:
            con.commit()
    except Exception:
        if args.apply:
            con.rollback()
        raise
    finally:
        con.close()

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open('w', newline='', encoding='utf-8') as f:
        cols = ['order_id', 'invoice_guid', 'old_date_opened', 'new_date_opened', 'date_posted', 'status']
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        for r in results:
            w.writerow(r)

    counts: Dict[str, int] = {}
    for r in results:
        counts[r['status']] = counts.get(r['status'], 0) + 1
    for k in sorted(counts):
        print(f'{counts[k]:5d} {k}')
    print(f'Wrote result CSV: {out}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
