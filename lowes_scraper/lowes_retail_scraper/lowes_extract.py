#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: GPL-3.0-or-later
"""
lowes_extract.py - local Lowe's retail purchase-history scraper/parser. v10 payment classification build.

This tool is intentionally local-only. It can:
  * parse Lowe's saved HTML pages containing window['__PRELOADED_STATE__']
  * scrape logged-in retail order summary/detail pages with Playwright
  * emit normalized JSON and CSV files suitable for a later GnuCash importer

No credentials are stored by this script. For scraping, use a dedicated browser
profile and log in manually when the browser opens.
"""

from __future__ import annotations

import argparse
import base64
import csv
import json
import re
import shutil
import sys
import time
from datetime import datetime
from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP, InvalidOperation
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple
from urllib.parse import urlparse, urljoin, parse_qs, urlencode

try:
    from bs4 import BeautifulSoup
except ImportError as exc:
    raise SystemExit("Missing dependency: beautifulsoup4. Install with: pip install -r requirements.txt") from exc


PRELOADED_RE = re.compile(r"window\[['\"]__PRELOADED_STATE__['\"]\]\s*=\s*(\{.*?\})\s*;?\s*$", re.S)


STATE_MARKERS = (
    "window['__PRELOADED_STATE__']",
    'window[\"__PRELOADED_STATE__\"]',
    "__PRELOADED_STATE__",
)


def D(value: Any, default: str = "0") -> Decimal:
    """Parse money-like values safely as Decimal."""
    if value is None:
        value = default
    if isinstance(value, Decimal):
        return value
    if isinstance(value, (int, float)):
        value = str(value)
    s = str(value).strip().replace("$", "").replace(",", "")
    if s in ("", "None", "null"):
        s = default
    try:
        return Decimal(s)
    except InvalidOperation:
        return Decimal(default)


def money(value: Any) -> str:
    """Normalize to a two-decimal money string."""
    return str(D(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP))


def safe_str(value: Any) -> str:
    return "" if value is None else str(value)


def read_html(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore")


def _raw_decode_json_object_after_marker(text: str, marker_pos: int) -> Optional[Dict[str, Any]]:
    """Decode the first JSON object following a __PRELOADED_STATE__ marker.

    Lowe's has used both single and double quoted window keys, sometimes with
    a trailing semicolon or additional script text. json.JSONDecoder.raw_decode
    is more reliable than a greedy regex for this.
    """
    eq = text.find("=", marker_pos)
    if eq < 0:
        return None
    brace = text.find("{", eq)
    if brace < 0:
        return None
    try:
        obj, _end = json.JSONDecoder().raw_decode(text[brace:])
    except json.JSONDecodeError:
        return None
    return obj if isinstance(obj, dict) else None


def extract_preloaded_state(html: str) -> Optional[Dict[str, Any]]:
    """Extract Lowe's SSR/application state from saved page HTML."""
    # Fast path: search whole HTML first. This catches pages where the state
    # assignment is not represented cleanly as a BeautifulSoup script.string.
    for marker in STATE_MARKERS[:2]:
        pos = html.find(marker)
        if pos >= 0:
            obj = _raw_decode_json_object_after_marker(html, pos)
            if obj is not None:
                return obj

    soup = BeautifulSoup(html, "html.parser")
    for script in soup.find_all("script"):
        txt = script.string if script.string is not None else script.get_text()
        if not txt or "__PRELOADED_STATE__" not in txt:
            continue
        for marker in STATE_MARKERS[:2]:
            pos = txt.find(marker)
            if pos >= 0:
                obj = _raw_decode_json_object_after_marker(txt, pos)
                if obj is not None:
                    return obj
        m = PRELOADED_RE.search(txt.strip())
        if m:
            try:
                return json.loads(m.group(1))
            except json.JSONDecodeError:
                continue
    return None


def diagnose_html_file(path: Path) -> Dict[str, Any]:
    """Return parse diagnostics for one saved HTML file."""
    html = read_html(path)
    lower = html.lower()
    soup = BeautifulSoup(html, "html.parser")
    title = soup.title.get_text(strip=True) if soup.title else ""
    links = detail_links_from_html(html)
    state = extract_preloaded_state(html)
    summary_count = 0
    detail_order_number = ""
    detail_item_count = 0
    detail_payment_count = 0
    rendered_detail_order_number = ""
    rendered_detail_item_count = 0
    order_details_error_status = ""
    state_top_keys = ""
    if state:
        state_top_keys = ",".join(sorted(state.keys())[:30])
        summary_count = len(normalize_summary_state(state, links, source_file=str(path)))
        od = state.get("orderDetails") or {}
        if isinstance(od.get("error"), dict):
            order_details_error_status = safe_str((od.get("error") or {}).get("status"))
        detail = normalize_detail_state(state, source_file=str(path))
        if detail:
            detail_order_number = safe_str(detail.get("order_number"))
            detail_item_count = len(detail.get("items", []))
            detail_payment_count = len(detail.get("payments", []))
    rendered_detail = normalize_detail_rendered_dom(html, source_file=str(path))
    if rendered_detail:
        rendered_detail_order_number = safe_str(rendered_detail.get("order_number"))
        rendered_detail_item_count = len(rendered_detail.get("items", []))

    reasons = []
    has_order_data = bool(summary_count or detail_order_number or rendered_detail_order_number)
    if "errors.edgesuite.net" in lower or "you don't have permission to access" in lower or "you don’t have permission to access" in lower:
        reasons.append("access_denied_edgesuite")
    # Normal Lowe's order pages contain generic "sign in" strings in headers or
    # scripts, and sometimes verification-related support text. Only flag these
    # as likely problems when no order data was parsed from the page.
    if not has_order_data and ("sign in" in lower or "signin" in lower or "login" in lower):
        reasons.append("signin_or_login_page_possible")
    if not has_order_data and ("captcha" in lower or "verify you are human" in lower):
        reasons.append("captcha_or_human_verification_possible")
    if "__preloaded_state__" not in lower:
        reasons.append("missing_preloaded_state_marker")
    elif state is None:
        reasons.append("preloaded_state_marker_found_but_json_not_decoded")
    if state and summary_count == 0 and not detail_order_number and not rendered_detail_order_number:
        reasons.append("state_decoded_but_no_order_history_or_order_details")
    if rendered_detail_order_number and not detail_order_number:
        reasons.append("rendered_dom_detail_fallback_ok")
    if not reasons and (summary_count or detail_order_number or rendered_detail_order_number):
        reasons.append("ok")

    return {
        "source_file": str(path),
        "bytes": str(path.stat().st_size),
        "title": title,
        "has_preloaded_state_marker": "yes" if "__preloaded_state__" in lower else "no",
        "state_decoded": "yes" if state is not None else "no",
        "state_top_keys": state_top_keys,
        "summary_order_count": summary_count,
        "detail_order_number": detail_order_number,
        "detail_item_count": detail_item_count,
        "detail_payment_count": detail_payment_count,
        "rendered_detail_order_number": rendered_detail_order_number,
        "rendered_detail_item_count": rendered_detail_item_count,
        "order_details_error_status": order_details_error_status,
        "detail_link_count": len(links),
        "diagnosis": ";".join(reasons),
    }


def detail_url_for_order_number(order_number: str) -> str:
    """Build a stable Lowe's order-detail URL from an order number.

    Lowe's rendered order-list links often include an expiring/encrypted `s=`
    parameter. The detail page also works with just the base64-encoded order
    number token plus `ih=Qg==`, which is more reliable for recapture.
    """
    order_no = safe_str(order_number).strip()
    if not re.fullmatch(r"\d{8,}", order_no):
        return ""
    token = base64.b64encode(order_no.encode("ascii")).decode("ascii")
    return "https://www.lowes.com/mylowes/orders/details?" + urlencode({"t": token, "ih": "Qg=="})


def order_number_from_detail_url(url: str) -> str:
    try:
        q = parse_qs(urlparse(url).query)
        token = safe_str((q.get("t") or [""])[0]).strip()
        if not token:
            return ""
        padded = token + ("=" * ((4 - len(token) % 4) % 4))
        decoded = base64.b64decode(padded.encode("ascii"), validate=False).decode("ascii", errors="ignore")
        return decoded if re.fullmatch(r"\d{8,}", decoded) else ""
    except Exception:
        return ""


def canonical_detail_url(url: str) -> str:
    """Return the stable t=<order> detail URL for reports/fallbacks.

    Do not use this as the primary navigation URL. Lowe's rendered detail links
    often contain a short-lived encrypted ``s=`` token. Some accounts/pages load
    order data reliably only when that original rendered URL is used first.
    """
    abs_url = urljoin("https://www.lowes.com", safe_str(url).replace("&amp;", "&"))
    order_no = order_number_from_detail_url(abs_url)
    return detail_url_for_order_number(order_no) if order_no else abs_url


def preserve_detail_url(url: str) -> str:
    return urljoin("https://www.lowes.com", safe_str(url).replace("&amp;", "&")).strip()


def detail_link_key(url: str) -> str:
    link = preserve_detail_url(url)
    return order_number_from_detail_url(link) or link


def add_unique_detail_link(links: List[str], seen: set, url: str) -> bool:
    # Preserve the original rendered URL, including Lowe's encrypted s= token,
    # but de-duplicate by decoded order number when possible. This avoids losing
    # access to detail pages that do not fully hydrate from the stable URL alone.
    link = preserve_detail_url(url)
    key = detail_link_key(link)
    if not link or "/mylowes/orders/details" not in link or key in seen:
        return False
    seen.add(key)
    links.append(link)
    return True


def detail_links_from_html(html: str) -> List[str]:
    soup = BeautifulSoup(html, "html.parser")
    links: List[str] = []
    seen: set[str] = set()
    for a in soup.find_all("a", href=True):
        href = a["href"]
        if "/mylowes/orders/details" in href:
            add_unique_detail_link(links, seen, href)
    return links


def rendered_summary_orders_from_html(html: str, source_file: str = "") -> List[Dict[str, Any]]:
    """Extract order-number/detail-link evidence from rendered order-list HTML.

    Lowe's can leave stale `orderHistory.data.orders` in __PRELOADED_STATE__ while
    the visible DOM contains a later page of orders. The rendered DOM is the
    authoritative evidence for which detail links should be walked, so collect
    order numbers and their nearest detail links directly from the saved HTML.
    These rows are intentionally minimal; detail pages remain authoritative for
    accounting totals/items/payments.
    """
    soup = BeautifulSoup(html, "html.parser")
    out: List[Dict[str, Any]] = []
    seen: set[str] = set()

    for text_node in soup.find_all(string=re.compile(r"Order\s*#\s*\d{10,}")):
        text = safe_str(text_node)
        m = re.search(r"Order\s*#\s*(\d{10,})", text)
        if not m:
            continue
        order_no = m.group(1)
        if order_no in seen:
            continue

        # Search in increasingly broad ancestors for the corresponding View Details link.
        href = ""
        node = getattr(text_node, "parent", None)
        for _ in range(10):
            if node is None:
                break
            a = node.find("a", href=lambda h: h and "/mylowes/orders/details" in h)
            if a and a.get("href"):
                href = urljoin("https://www.lowes.com", a.get("href"))
                break
            node = getattr(node, "parent", None)

        # If the link was not in the same ancestor, fall back to the first nearby
        # details href after this order number in source order.
        if not href:
            pos = html.find(text)
            if pos >= 0:
                chunk = html[pos:pos + 12000]
                hm = re.search(r'href=["\']([^"\']*/mylowes/orders/details[^"\']*)["\']', chunk)
                if hm:
                    href = urljoin("https://www.lowes.com", hm.group(1).replace("&amp;", "&"))

        # Keep the original rendered detail href when present. It may include an
        # encrypted s= token that helps Lowe's hydrate old/split orders.
        href = preserve_detail_url(href) if href else detail_url_for_order_number(order_no)

        seen.add(order_no)
        out.append({
            "vendor": "lowes",
            "source": "retail_account_order_summary_rendered",
            "source_file": source_file,
            "order_number": order_no,
            "purchase_date": "",
            "type": "",
            "status": "",
            "channel": "",
            "document_type": "",
            "total": "0.00",
            "quantity_items": "",
            "store_number": "",
            "loyalty_reward": "",
            "detail_url": href,
        })
    return out


MONTHS = {
    "jan": 1, "january": 1,
    "feb": 2, "february": 2,
    "mar": 3, "march": 3,
    "apr": 4, "april": 4,
    "may": 5,
    "jun": 6, "june": 6,
    "jul": 7, "july": 7,
    "aug": 8, "august": 8,
    "sep": 9, "sept": 9, "september": 9,
    "oct": 10, "october": 10,
    "nov": 11, "november": 11,
    "dec": 12, "december": 12,
}

MONEY_RE = re.compile(r"\$\s*([0-9][0-9,]*\.\d{2})")


def normalize_human_date(value: str) -> str:
    """Convert Lowe's visible dates such as 'Placed February 3, 2026' to YYYY-MM-DD."""
    text = re.sub(r"\s+", " ", safe_str(value)).strip()
    text = re.sub(r"^(Placed|Order Date:?|Delivered|Returned)\s+", "", text, flags=re.I).strip()
    text = re.sub(r"^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*", "", text, flags=re.I)
    m = re.search(r"([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})", text)
    if not m:
        return ""
    mon = MONTHS.get(m.group(1).lower())
    if not mon:
        return ""
    try:
        return datetime(int(m.group(3)), mon, int(m.group(2))).strftime("%Y-%m-%d")
    except ValueError:
        return ""


def first_money(value: str) -> str:
    m = MONEY_RE.search(safe_str(value))
    return money(m.group(1)) if m else "0.00"


def all_money_values(value: str) -> List[str]:
    return [money(m.group(1)) for m in MONEY_RE.finditer(safe_str(value))]


def html_text_lines(html: str) -> List[str]:
    soup = BeautifulSoup(html, "html.parser")
    raw = soup.get_text("\n", strip=True)
    lines: List[str] = []
    for line in raw.splitlines():
        line = re.sub(r"\s+", " ", line).strip()
        if line:
            lines.append(line)
    return lines


def _nearest_money_after(lines: List[str], start: int, stop_words: Iterable[str], max_lines: int = 80) -> str:
    stop_l = tuple(x.lower() for x in stop_words)
    for j in range(start, min(len(lines), start + max_lines)):
        low = lines[j].lower()
        if any(w in low for w in stop_l):
            break
        vals = all_money_values(lines[j])
        if vals:
            return vals[-1]
    return "0.00"


def _summary_amount_after_label(lines: List[str], label: str, max_lines: int = 6) -> str:
    label_l = label.lower()
    for i, line in enumerate(lines):
        if label_l in line.lower():
            inline = first_money(line)
            if inline != "0.00":
                return inline
            return _nearest_money_after(lines, i + 1, ["subtotal", "tax", "delivery", "total", "payment method", "present or show"], max_lines=max_lines)
    return "0.00"


def _extract_dom_items(lines: List[str], order_number: str, purchase_date: str, is_return: bool) -> List[Dict[str, Any]]:
    """Extract item lines from rendered Lowe's detail DOM text.

    This is a fallback for pages where Lowe's orderDetails state contains only a
    payload/error object even though the visible saved page has product rows.
    The logic is intentionally conservative and de-duplicates the print-details
    and live-page copies of the same item row.
    """
    items: List[Dict[str, Any]] = []
    seen: set[str] = set()
    item_re = re.compile(r"Item\s*#\s*([^\s]+)(?:\s+Model\s*#\s*(.*))?", re.I)
    for i, line in enumerate(lines):
        m = item_re.search(line)
        if not m:
            continue
        sku = safe_str(m.group(1)).strip()
        model = safe_str(m.group(2)).strip()
        if not sku:
            continue
        # Product title is usually the preceding non-empty line. Skip generic UI lines.
        title = ""
        for k in range(i - 1, max(-1, i - 8), -1):
            cand = lines[k].strip()
            if not cand or cand.lower() in {"delivered", "return completed", "buy it again", "track package"}:
                continue
            if cand.startswith("$") or cand.upper().startswith("QTY") or cand.lower().startswith("saved $"):
                continue
            title = cand
            break
        qty = Decimal("1")
        qty_idx = -1
        for j in range(i + 1, min(len(lines), i + 10)):
            qm = re.search(r"\bQTY\s+([0-9]+(?:\.[0-9]+)?)", lines[j], re.I)
            if qm:
                qty = D(qm.group(1), "1")
                qty_idx = j
                break
        prices: List[Decimal] = []
        scan_end = min(len(lines), i + 18)
        for j in range(i + 1, scan_end):
            if j != i + 1 and item_re.search(lines[j]):
                break
            low = lines[j].lower()
            if low in {"payment method", "order summary", "deliver to"}:
                break
            for val in all_money_values(lines[j]):
                prices.append(D(val))
        if not prices:
            # A search-result return card can list items without prices. Leave it
            # to the failure/candidate report instead of inventing line values.
            continue
        # Prefer the last amount before a discount/save label; Lowe's print details
        # often show list price, net line amount, then "Saved $...".
        line_amount = prices[-1]
        # If the last value is clearly a discount after a "Saved" label, use the previous value.
        window = " ".join(lines[i + 1:scan_end]).lower()
        if "saved $" in window and len(prices) >= 2 and prices[-1] < prices[-2]:
            line_amount = prices[-2]
        unit_price = line_amount / qty if qty != 0 else line_amount
        key = f"{sku}|{model}|{title}|{money(line_amount)}|{qty}"
        if key in seen:
            continue
        seen.add(key)
        status = "Return Completed" if is_return else ""
        if not status:
            for j in range(i, min(len(lines), i + 10)):
                if "return completed" in lines[j].lower():
                    status = "Return Completed"
                    break
                if "delivered" in lines[j].lower():
                    status = "Delivered"
                    break
        items.append({
            "order_number": order_number,
            "purchase_date": purchase_date,
            "line_key": f"rendered-dom-{len(items)+1:03d}",
            "sku": sku,
            "omni_item_id": "",
            "model_number": model,
            "brand": "",
            "description": title or f"Lowe's item {sku}",
            "quantity": str(qty.normalize()) if qty == qty.to_integral() else str(qty),
            "unit_price": str(unit_price.quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)),
            "extended_amount": money(line_amount),
            "tax": "0.00",
            "line_total_with_tax": money(line_amount),
            "discount_total": "0.00",
            "status": status,
            "fulfillment_type": "",
            "delivery_method": "",
            "marketplace": False,
            "vendor_name": "",
            "product_url": "",
            "image_url": "",
        })
    return items


def normalize_detail_rendered_dom(html: str, source_file: str = "") -> Optional[Dict[str, Any]]:
    """Fallback parser for fully-rendered Lowe's saved pages.

    Some Lowe's order-detail pages save with `orderDetails.error` in
    __PRELOADED_STATE__ while the visible DOM has order number, dates, item rows,
    and totals. This recovers enough detail to stage the bill for review.
    """
    lines = html_text_lines(html)
    joined = "\n".join(lines)
    m = re.search(r"Order\s*#\s*(\d{10,})", joined)
    if not m:
        # Search-result return pages use Transaction # instead of Order #.
        m = re.search(r"Transaction\s*#\s*(\d{10,})", joined, flags=re.I)
    if not m:
        return None
    order_number = m.group(1)

    purchase_date = ""
    for line in lines:
        if re.search(r"\bPlaced\s+[A-Za-z]+\s+\d{1,2},\s*\d{4}", line):
            purchase_date = normalize_human_date(line)
            break
        if re.search(r"Order Date:?\s+[A-Za-z]+\s+\d{1,2},\s*\d{4}", line, flags=re.I):
            purchase_date = normalize_human_date(line)
            break

    is_return = "return completed" in joined.lower() or "total refunded" in joined.lower()
    doc_type = "return" if is_return else "sale"
    status = "Return Completed" if is_return else ("Delivered" if "Delivered" in joined else "")

    total = _summary_amount_after_label(lines, "Total Billed")
    if total == "0.00":
        total = _summary_amount_after_label(lines, "Total Refunded")
    if total == "0.00":
        # Near the order header the first total after Placed/Order Date is usually the order total.
        for i, line in enumerate(lines):
            if "Placed" in line or "Order Date" in line:
                total = _nearest_money_after(lines, i, ["points", "print details", "delivered", "return completed"], max_lines=8)
                if total != "0.00":
                    break

    subtotal = _summary_amount_after_label(lines, "Subtotal")
    tax = _summary_amount_after_label(lines, "Tax")
    shipping = "0.00"
    for label in ("Truck Delivery", "Delivery", "Shipping"):
        amt = _summary_amount_after_label(lines, label)
        if amt != "0.00":
            shipping = amt
            break

    items = _extract_dom_items(lines, order_number, purchase_date, is_return)
    if not items:
        return None

    item_subtotal = sum(D(i["extended_amount"]) for i in items)
    header_tax = D(tax)
    if subtotal == "0.00":
        subtotal = money(item_subtotal)
    if total == "0.00":
        total = money(D(subtotal) + header_tax + D(shipping))
    source_total = D(total)
    no_shipping_total = item_subtotal + header_tax
    with_shipping_total = no_shipping_total + D(shipping)
    include_shipping_for_validation = abs(source_total - with_shipping_total) < abs(source_total - no_shipping_total)
    accounting_total = with_shipping_total if include_shipping_for_validation else no_shipping_total

    # Allocate any known header tax proportionally for reporting. The importer uses
    # header tax as the source of truth, but per-line tax helps diagnostics.
    if header_tax != 0 and item_subtotal != 0:
        running = Decimal("0.00")
        for idx, item in enumerate(items):
            if idx == len(items) - 1:
                item_tax = header_tax - running
            else:
                item_tax = (D(item["extended_amount"]) / item_subtotal * header_tax).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
                running += item_tax
            item["tax"] = money(item_tax)
            item["line_total_with_tax"] = money(D(item["extended_amount"]) + item_tax)

    order = {
        "vendor": "lowes",
        "source": "retail_account_rendered_dom_fallback",
        "source_file": source_file,
        "type": doc_type,
        "order_number": order_number,
        "purchase_date": purchase_date,
        "status": status,
        "channel": "rendered_dom",
        "store_number": "",
        "document_type": "RenderedDomFallback",
        "subtotal": money(subtotal),
        "shipping": money(shipping),
        "adjustments": "0.00",
        "tax": money(tax),
        "total": money(total),
        "loyalty_reward": "",
        "items": items,
        "payments": [],
        "return_releases": [],
        "excluded_items": [],
    }
    order["calculated_item_subtotal"] = money(item_subtotal)
    order["calculated_item_tax"] = money(header_tax)
    order["calculated_item_total_with_tax"] = money(accounting_total)
    order["line_item_tax_sum"] = money(sum(D(i["tax"]) for i in items))
    order["line_item_tax_delta_vs_order_tax"] = money(header_tax - sum(D(i["tax"]) for i in items))
    order["shipping_used_in_validation"] = "yes" if include_shipping_for_validation and D(shipping) != 0 else "no"
    order["validation_delta_total_minus_item_lines"] = money(source_total - accounting_total)
    order["diagnostic_delta_total_minus_line_tax_total"] = money(source_total - (item_subtotal + sum(D(i["tax"]) for i in items)))
    order["diagnostic_delta_total_minus_with_shipping"] = money(source_total - with_shipping_total)
    order["diagnostic_delta_total_minus_items_tax_shipping_adjustments"] = money(source_total - accounting_total)
    order["warning"] = "Parsed from rendered saved-page DOM because Lowe's orderDetails state did not contain usable order data; review totals/items before export."
    return order


def classify_document_type(order: Dict[str, Any]) -> str:
    doc = safe_str(order.get("documentType") or order.get("orderType") or order.get("extnOrderType")).lower()
    status = safe_str(order.get("status")).lower()
    typ = safe_str(order.get("type")).lower()
    total = D(order.get("total") or order.get("totalAmount"))

    if "return" in doc or "return" in status or "return" in typ or total < 0:
        return "return"
    return "sale"


def item_discount_total(item: Dict[str, Any]) -> Decimal:
    """Return display discount total without double-counting.

    Lowe's often exposes the same discount twice:
      * orderItemAdjustments
      * charges

    Prefer orderItemAdjustments when present; fall back to charges only when the
    adjustment list is absent.
    """
    adjustments = item.get("orderItemAdjustments") or []
    if adjustments:
        return sum(abs(D(adj.get("totalAmount", adj.get("amount", "0")))) for adj in adjustments)

    total = Decimal("0")
    for ch in item.get("charges") or []:
        if ch.get("discount") or ch.get("is_discount"):
            # charge_value can be per-line total for Lowe's discount objects.
            total += abs(D(ch.get("charge_value", ch.get("charge_per_unit", "0"))))
    return total


def item_tax_total(item: Dict[str, Any]) -> Decimal:
    total = Decimal("0")
    for tax in item.get("orderItemTax") or []:
        total += D(tax.get("taxAmount", "0"))
    return total


def normalize_item(item: Dict[str, Any], order_number: str, purchase_date: str, release: Dict[str, Any]) -> Dict[str, Any]:
    qty = D(item.get("quantity", item.get("originalOrderedQuantity", "1")), "1")
    pre_tax_total = D(item.get("totalPrice", item.get("lineTotal", "0")))
    line_total_with_tax = D(item.get("lineTotal", "0"))
    tax = item_tax_total(item)
    if pre_tax_total == 0 and line_total_with_tax != 0:
        pre_tax_total = line_total_with_tax - tax

    unit_price = pre_tax_total / qty if qty != 0 else pre_tax_total
    discount_total = item_discount_total(item)

    return {
        "order_number": order_number,
        "purchase_date": purchase_date,
        "line_key": safe_str(item.get("orderLineKey")),
        "sku": safe_str(item.get("itemNumber") or item.get("partNumber")),
        "omni_item_id": safe_str(item.get("omniItemId")),
        "model_number": safe_str(item.get("modelNumber")),
        "brand": safe_str(item.get("brand")),
        "description": safe_str(item.get("name")),
        "quantity": str(qty.normalize()) if qty == qty.to_integral() else str(qty),
        "unit_price": str(unit_price.quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)),
        "extended_amount": money(pre_tax_total),
        "tax": money(tax),
        "line_total_with_tax": money(pre_tax_total + tax),
        "discount_total": money(discount_total),
        "status": safe_str(item.get("status") or release.get("status")),
        "fulfillment_type": safe_str(item.get("fulfillmentType") or release.get("deliveryMethod")),
        "delivery_method": safe_str(item.get("deliveryMethodId") or release.get("deliveryMethod")),
        "marketplace": bool(item.get("isMarketPlaceItem")),
        "vendor_name": safe_str((item.get("vendorInfo") or {}).get("vendorName")),
        "product_url": safe_str(item.get("productURL")),
        "image_url": safe_str(item.get("productImagePath")),
    }


def normalize_payment(payment: Dict[str, Any], order_number: str, purchase_date: str, payment_index: int = 0) -> Dict[str, Any]:
    method = safe_str(payment.get("method") or payment.get("cardType") or payment.get("paymentType"))
    raw_identifier = safe_str(payment.get("cardNumber") or payment.get("displayAccountNumber") or payment.get("last4"))
    charged = payment.get("totalCharged", payment.get("maxChargeLimit", payment.get("amount", "0")))
    refund = payment.get("refundAmount", "0")
    amount = D(charged)
    refund_amount = D(refund)
    net_amount = amount - refund_amount

    method_l = method.strip().lower()
    ident_digits = "".join(ch for ch in raw_identifier if ch.isdigit())
    display_last4 = ident_digits[-4:] if len(ident_digits) >= 4 else raw_identifier

    payment_class = "other"
    method_normalized = method.upper() if method else ""
    payment_label = method or "Unknown"
    stored_value_program = ""
    account_hint = ""
    importer_treatment = "review"

    # Lowe's retail shows My Lowe's Money as method GC in orderPayments, with
    # a long stored-value identifier. Treat this as the Lowe's stored-value /
    # rewards tender for the later GnuCash importer. If Lowe's ever exposes
    # a distinct purchased gift card in the same shape, it can still be reviewed
    # because the raw method and identifier remain preserved.
    if method_l in {"gc", "gift card", "giftcard"} or "gift" in method_l:
        payment_class = "stored_value"
        method_normalized = "MYLOWES_MONEY"
        payment_label = "My Lowe's Money"
        stored_value_program = "My Lowe's Money"
        account_hint = "Assets:Other Current Assets:My Lowe's Money"
        importer_treatment = "stored_value_payment"
    elif method_l in {"mc", "master card", "mastercard"} or "master" in method_l:
        payment_class = "credit_card"
        method_normalized = "MASTERCARD"
        payment_label = "Mastercard"
        account_hint = f"Liabilities:Credit Cards:Mastercard {display_last4}" if display_last4 else "Liabilities:Credit Cards:<map Mastercard>"
        importer_treatment = "credit_card_payment"
    elif method_l in {"visa"} or "visa" in method_l:
        payment_class = "credit_card"
        method_normalized = "VISA"
        payment_label = "Visa"
        account_hint = f"Liabilities:Credit Cards:Visa {display_last4}" if display_last4 else "Liabilities:Credit Cards:<map Visa>"
        importer_treatment = "credit_card_payment"
    elif method_l in {"amex", "american express"} or "american" in method_l:
        payment_class = "credit_card"
        method_normalized = "AMEX"
        payment_label = "American Express"
        account_hint = f"Liabilities:Credit Cards:American Express {display_last4}" if display_last4 else "Liabilities:Credit Cards:<map American Express>"
        importer_treatment = "credit_card_payment"

    has_refund = refund_amount != 0
    if has_refund and payment_class == "credit_card":
        importer_treatment = "credit_card_payment_with_refund_evidence"
    elif has_refund and payment_class == "stored_value":
        importer_treatment = "stored_value_payment_with_refund_evidence"

    return {
        "order_number": order_number,
        "purchase_date": purchase_date,
        "method": method,
        "method_normalized": method_normalized,
        "payment_label": payment_label,
        "payment_class": payment_class,
        "stored_value_program": stored_value_program,
        "last4": raw_identifier,
        "display_last4": display_last4,
        "payment_identifier": raw_identifier,
        "amount": money(amount),
        "refund_amount": money(refund_amount),
        "net_amount": money(net_amount),
        "has_refund": "yes" if has_refund else "no",
        "transaction_type": safe_str(payment.get("transactionType")),
        # v171: Lowe's may omit paymentKey for every tender. Preserve duplicate
        # same-denomination My Lowe's Money rows by using the identifier plus row index
        # as a stable fallback key instead of leaving this blank.
        "payment_key": safe_str(payment.get("paymentKey")) or f"row{payment_index + 1:03d}|{method_normalized}|{raw_identifier}|{money(amount)}|refund:{money(refund_amount)}",
        "account_hint": account_hint,
        "importer_treatment": importer_treatment,
    }


def normalize_detail_state(state: Dict[str, Any], source_file: str = "") -> Optional[Dict[str, Any]]:
    od = state.get("orderDetails") or {}
    data = od.get("data") or {}
    payload = od.get("payload") or {}
    tracking = od.get("trackingV1Payload") or []
    if not data:
        return None

    # Lowe's retail has at least two detail-page shapes:
    #   * online/special-order pages usually expose data.masterOrderNumber
    #   * in-store receipt pages often expose only data.lwsOrderHeaderKey
    # Older builds required masterOrderNumber and therefore skipped many valid
    # in-store Purchase Details pages even though line items and payments were present.
    order_number = safe_str(
        data.get("masterOrderNumber")
        or payload.get("masterOrderNumber")
        or data.get("lwsOrderHeaderKey")
        or (tracking[0].get("orderNumber") if tracking and isinstance(tracking[0], dict) else "")
        or data.get("transactionId")
        or data.get("masterInvoiceNumber")
    )
    if not order_number:
        return None

    purchase_date = safe_str(data.get("purchaseDate") or data.get("currentStatusDate"))
    doc_type = classify_document_type(data)

    items: List[Dict[str, Any]] = []
    excluded_items: List[Dict[str, Any]] = []
    return_releases: List[Dict[str, Any]] = []
    for rel in data.get("orderRelease") or []:
        rel_items: List[Dict[str, Any]] = []
        for item in rel.get("orderItems") or []:
            norm_item = normalize_item(item, order_number, purchase_date, rel)
            item_status = safe_str(item.get("status") or rel.get("status"))
            if cancelled_like_status(item_status):
                norm_item["excluded_reason"] = "cancelled_line"
                excluded_items.append(norm_item)
                continue
            items.append(norm_item)
            rel_items.append(norm_item)
        if rel_items and return_like_status(rel.get("status")):
            return_releases.append({
                "status": safe_str(rel.get("status")),
                "current_status_date": safe_str(rel.get("currentStatusDate")),
                "items": rel_items,
            })

    payments = [normalize_payment(p, order_number, purchase_date, idx) for idx, p in enumerate(data.get("orderPayments") or [])]

    order = {
        "vendor": "lowes",
        "source": "retail_account_preloaded_state",
        "source_file": source_file,
        "type": doc_type,
        "order_number": order_number,
        "purchase_date": purchase_date,
        "status": safe_str(data.get("status")),
        "channel": safe_str(data.get("type") or data.get("channelId")),
        "store_number": safe_str(data.get("storeNumber")),
        "document_type": safe_str(data.get("documentType")),
        "subtotal": money(data.get("subTotalAmount", "0")),
        "shipping": money(data.get("shippingAmount", "0")),
        "adjustments": money(data.get("adjustments", "0")),
        "tax": money(data.get("taxTotalWithoutFee", data.get("taxAmount", "0"))),
        "total": money(data.get("totalAmount", data.get("total", "0"))),
        "loyalty_reward": safe_str(data.get("loyaltyReward")),
        "items": items,
        "payments": payments,
        "return_releases": return_releases,
        "excluded_items": excluded_items,
    }

    # Accounting validation: source extended item amounts plus the *source header tax*
    # should match the source order total. Lowe's embedded per-line tax values are
    # useful evidence, but they are not always reliable after partial returns or
    # mixed fulfillment. For GnuCash import, the header tax should be imported as
    # the tax line, while item extended amounts remain authoritative for categories.
    item_subtotal = sum(D(i["extended_amount"]) for i in items)
    line_item_tax = sum(D(i["tax"]) for i in items)
    header_tax = D(order["tax"])
    shipping = D(order["shipping"])
    no_shipping_total = item_subtotal + header_tax
    with_shipping_total = no_shipping_total + shipping
    source_total = D(order["total"])
    no_shipping_delta = source_total - no_shipping_total
    with_shipping_delta = source_total - with_shipping_total
    include_shipping_for_validation = abs(with_shipping_delta) < abs(no_shipping_delta)
    accounting_total = with_shipping_total if include_shipping_for_validation else no_shipping_total

    order["calculated_item_subtotal"] = money(item_subtotal)
    order["calculated_item_tax"] = money(header_tax)
    order["calculated_item_total_with_tax"] = money(accounting_total)
    order["line_item_tax_sum"] = money(line_item_tax)
    order["line_item_tax_delta_vs_order_tax"] = money(header_tax - line_item_tax)
    order["shipping_used_in_validation"] = "yes" if include_shipping_for_validation and shipping != 0 else "no"

    # Primary validation for importer readiness: source total vs item extended
    # amounts + source header tax. Shipping is included only when it is actually
    # needed to reconcile the source total; Lowe's can expose a shipping amount
    # in orderDetails even when the customer total does not include it.
    order["validation_delta_total_minus_item_lines"] = money(source_total - accounting_total)

    # Diagnostics only. Non-zero values here flag Lowe's line-tax or shipping
    # exposure oddities, not necessarily import blockers.
    order["diagnostic_delta_total_minus_line_tax_total"] = money(source_total - (item_subtotal + line_item_tax))
    order["diagnostic_delta_total_minus_with_shipping"] = money(with_shipping_delta)

    # Secondary diagnostic only. Lowe's may expose adjustments even when item
    # totals are already net of them, so this can be non-zero without indicating
    # a bad import.
    order["diagnostic_delta_total_minus_items_tax_shipping_adjustments"] = money(
        source_total - (accounting_total + D(order["adjustments"]))
    )
    return order


def normalize_summary_state(state: Dict[str, Any], detail_links: Optional[List[str]] = None, source_file: str = "") -> List[Dict[str, Any]]:
    oh = state.get("orderHistory") or {}
    data = oh.get("data") or {}
    orders = data.get("orders") or []
    detail_links = detail_links or []

    normalized: List[Dict[str, Any]] = []
    for idx, o in enumerate(orders):
        order_number = safe_str(o.get("masterOrderNumber"))
        link = detail_links[idx] if idx < len(detail_links) else ""
        normalized.append({
            "vendor": "lowes",
            "source": "retail_account_order_summary",
            "source_file": source_file,
            "order_number": order_number,
            "purchase_date": safe_str(o.get("purchaseDate")),
            "type": classify_document_type(o),
            "status": safe_str(o.get("status")),
            "channel": safe_str(o.get("type") or o.get("channelId")),
            "document_type": safe_str(o.get("documentType")),
            "total": money(o.get("totalAmount", o.get("total", "0"))),
            "quantity_items": safe_str(o.get("quantityItems")),
            "store_number": safe_str(o.get("storeNumber")),
            "loyalty_reward": safe_str(o.get("loyaltyReward")),
            "detail_url": link,
        })
    return normalized


def dedupe_and_backfill_summaries(
    summaries: List[Dict[str, Any]],
    details: List[Dict[str, Any]],
) -> List[Dict[str, Any]]:
    """Return one summary row per order, backfilled from detail pages when needed.

    Lowe's client-side pagination can leave the original `orderHistory.data.orders`
    object in __PRELOADED_STATE__ while the rendered page links have moved to the
    next page. That means saved page 2 can parse as duplicate page-1 summary rows
    even though its rendered detail links point to later orders. Details are the
    authoritative captured evidence for accounting; keep summary rows unique and
    add detail-derived fallback rows for captured details that were not present in
    the stale summary state.
    """
    by_order: Dict[str, Dict[str, Any]] = {}
    for s in summaries:
        order_number = safe_str(s.get("order_number"))
        if not order_number:
            continue
        if order_number not in by_order:
            by_order[order_number] = s

    for d in details:
        order_number = safe_str(d.get("order_number"))
        if not order_number or order_number in by_order:
            continue
        by_order[order_number] = {
            "vendor": "lowes",
            "source": "retail_account_detail_fallback",
            "source_file": safe_str(d.get("source_file")),
            "order_number": order_number,
            "purchase_date": safe_str(d.get("purchase_date")),
            "type": safe_str(d.get("type")),
            "status": safe_str(d.get("status")),
            "channel": safe_str(d.get("channel")),
            "document_type": safe_str(d.get("document_type")),
            "total": money(d.get("total", "0")),
            "quantity_items": str(len(d.get("items", []) or [])),
            "store_number": safe_str(d.get("store_number")),
            "loyalty_reward": safe_str(d.get("loyalty_reward")),
            "detail_url": "",
        }

    return sorted(by_order.values(), key=lambda r: (safe_str(r.get("purchase_date")), safe_str(r.get("order_number"))))


def parse_html_file(path: Path) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    """Return (summary_orders, detail_orders) extracted from one HTML file."""
    html = read_html(path)
    state = extract_preloaded_state(html)

    summaries: List[Dict[str, Any]] = []
    details: List[Dict[str, Any]] = []

    if state and (state.get("orderHistory") or {}).get("data"):
        summaries.extend(normalize_summary_state(state, detail_links_from_html(html), source_file=str(path)))

    # Also capture the actually-rendered order list rows. This covers Lowe's
    # pagination pages where __PRELOADED_STATE__ is stale but the visible DOM has
    # later order numbers and detail links.
    summaries.extend(rendered_summary_orders_from_html(html, source_file=str(path)))

    detail = normalize_detail_state(state, source_file=str(path)) if state else None
    if not detail:
        detail = normalize_detail_rendered_dom(html, source_file=str(path))
    if detail:
        details.append(detail)

    return summaries, details


def find_html_files(input_path: Path) -> List[Path]:
    if input_path.is_file():
        return [input_path]
    return sorted([p for p in input_path.rglob("*") if p.suffix.lower() in (".html", ".htm")])


def write_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")


def write_csv(path: Path, rows: List[Dict[str, Any]], fieldnames: Optional[List[str]] = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if not fieldnames:
        keys: List[str] = []
        for row in rows:
            for k in row.keys():
                if k not in keys:
                    keys.append(k)
        fieldnames = keys
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow(r)



def return_like_status(value: Any) -> bool:
    s = safe_str(value).lower()
    return "return" in s or "refund" in s


def cancelled_like_status(value: Any) -> bool:
    s = safe_str(value).lower()
    # Lowe's order details can retain cancelled lines in the orderDetails payload
    # even though the displayed/order total excludes them. Do not feed these to
    # the importer or to validation totals.
    return "cancel" in s


def synthesize_missing_return_details(
    summaries: List[Dict[str, Any]],
    details: List[Dict[str, Any]],
) -> List[Dict[str, Any]]:
    """Create return detail documents from return releases embedded in sale details.

    Lowe's retail history can show a separate return in the summary list while
    the standalone return detail page contains no usable orderDetails payload.
    In the captured samples, the returned item lines are embedded as a
    "Return Completed" release inside the original sale detail page. This
    function matches missing return summaries to those embedded release totals
    and emits a separate normalized return document for downstream accounting.
    """
    details_by_order = {safe_str(d.get("order_number")): d for d in details}
    synthesized: List[Dict[str, Any]] = []

    # Build candidate return releases from all parsed details.
    candidates: List[Dict[str, Any]] = []
    for d in details:
        source_order = safe_str(d.get("order_number"))
        for rel in d.get("return_releases", []) or []:
            items = rel.get("items") or []
            subtotal = sum(D(i.get("extended_amount")) for i in items)
            tax = sum(D(i.get("tax")) for i in items)
            discount = sum(D(i.get("discount_total")) for i in items)
            candidates.append({
                "source_detail": d,
                "source_order_number": source_order,
                "source_file": safe_str(d.get("source_file")),
                "release_status": safe_str(rel.get("status")),
                "release_date": safe_str(rel.get("current_status_date"))[:10],
                "items": items,
                "subtotal": subtotal,
                "tax": tax,
                "discount": discount,
                "total": subtotal + tax,
                "item_count": len(items),
            })

    used_ids: set[int] = set()
    pending_synthesized_orders: set[str] = set()
    for s in summaries:
        order_number = safe_str(s.get("order_number"))
        if not order_number or order_number in details_by_order or order_number in pending_synthesized_orders:
            continue
        if not return_like_status(s.get("type")) and not return_like_status(s.get("status")) and not return_like_status(s.get("document_type")):
            continue

        target_total = D(s.get("total"))
        target_qty_raw = safe_str(s.get("quantity_items"))
        try:
            target_qty = int(Decimal(target_qty_raw)) if target_qty_raw else None
        except Exception:
            target_qty = None
        target_date = safe_str(s.get("purchase_date"))[:10]

        matches: List[Dict[str, Any]] = []
        for c in candidates:
            if id(c) in used_ids:
                continue
            if abs(c["total"] - target_total) > Decimal("0.01"):
                continue
            if target_qty is not None and c["item_count"] != target_qty:
                continue
            # Prefer date matches, but allow amount/count-only in case Lowe's
            # summary and release dates differ by time zone or processing date.
            date_score = 0 if (not target_date or not c["release_date"] or c["release_date"] == target_date) else 1
            matches.append({**c, "_date_score": date_score})

        if not matches:
            continue
        matches.sort(key=lambda c: (c["_date_score"], safe_str(c["source_order_number"])))
        c = matches[0]
        used_ids.add(id(c))

        items: List[Dict[str, Any]] = []
        for src_item in c["items"]:
            item = dict(src_item)
            item["order_number"] = order_number
            item["purchase_date"] = target_date or safe_str(s.get("purchase_date"))
            item["source_sale_order_number"] = c["source_order_number"]
            item["source_return_release_status"] = c["release_status"]
            item["source_return_release_date"] = c["release_date"]
            items.append(item)

        subtotal = c["subtotal"]
        # For synthesized return documents, trust the return summary total and
        # compute header tax as total - source item subtotal. Embedded release
        # per-line tax values can be stale or copied from the original sale.
        total = target_total
        tax = total - subtotal
        line_item_tax = c["tax"]
        synthetic = {
            "vendor": "lowes",
            "source": "retail_account_synthesized_return_release",
            "source_file": c["source_file"],
            "type": "return",
            "order_number": order_number,
            "purchase_date": target_date or safe_str(s.get("purchase_date")),
            "status": safe_str(s.get("status")) or c["release_status"],
            "channel": safe_str(s.get("channel")),
            "store_number": safe_str(s.get("store_number")),
            "document_type": safe_str(s.get("document_type")) or "ReturnOrder",
            "subtotal": money(subtotal),
            "shipping": "0.00",
            "adjustments": money(Decimal("0") - c["discount"]),
            "tax": money(tax),
            "total": money(total),
            "loyalty_reward": safe_str(s.get("loyalty_reward")),
            "synthesized_from_order_number": c["source_order_number"],
            "synthesized_from_release_status": c["release_status"],
            "synthesized_from_release_date": c["release_date"],
            "items": items,
            "payments": [],
        }
        synthetic["calculated_item_subtotal"] = money(subtotal)
        synthetic["calculated_item_tax"] = money(tax)
        synthetic["calculated_item_total_with_tax"] = money(subtotal + tax)
        synthetic["line_item_tax_sum"] = money(line_item_tax)
        synthetic["line_item_tax_delta_vs_order_tax"] = money(tax - line_item_tax)
        synthetic["validation_delta_total_minus_item_lines"] = money(total - (subtotal + tax))
        synthetic["diagnostic_delta_total_minus_line_tax_total"] = money(total - (subtotal + line_item_tax))
        synthetic["diagnostic_delta_total_minus_items_tax_shipping_adjustments"] = money(
            total - (subtotal + tax + D(synthetic["shipping"]) + D(synthetic["adjustments"]))
        )
        synthesized.append(synthetic)
        pending_synthesized_orders.add(order_number)

    if synthesized:
        existing = {safe_str(d.get("order_number")) for d in details}
        for d in synthesized:
            if safe_str(d.get("order_number")) not in existing:
                details.append(d)
                existing.add(safe_str(d.get("order_number")))
    return synthesized

def parse_saved(args: argparse.Namespace) -> int:
    input_path = Path(args.input).expanduser().resolve()
    outdir = Path(args.out).expanduser().resolve()
    summaries: List[Dict[str, Any]] = []
    details: List[Dict[str, Any]] = []
    diagnostics: List[Dict[str, Any]] = []

    html_files = find_html_files(input_path)
    for path in html_files:
        diagnostics.append(diagnose_html_file(path))
        s, d = parse_html_file(path)
        summaries.extend(s)
        details.extend(d)

    # Dedupe details by order number, preferring later files.
    details_by_order: Dict[str, Dict[str, Any]] = {}
    for d in details:
        details_by_order[d["order_number"]] = d
    details = list(details_by_order.values())

    synthesized_returns = synthesize_missing_return_details(summaries, details)
    summaries = dedupe_and_backfill_summaries(summaries, details)

    details_seen = {safe_str(d.get("order_number")) for d in details}
    detail_links_to_capture = []
    failed_detail_captures: List[Dict[str, Any]] = []
    failed_seen: set[str] = set()
    for s in summaries:
        order_no = safe_str(s.get("order_number"))
        detail_url = safe_str(s.get("detail_url"))
        # Only list rendered-DOM summary links here. Lowe's embedded state can be
        # stale and paired with the wrong rendered link on later pages.
        if order_no and detail_url and order_no not in details_seen and safe_str(s.get("source")) == "retail_account_order_summary_rendered":
            row = {
                "order_number": order_no,
                "purchase_date": safe_str(s.get("purchase_date")),
                "summary_source": safe_str(s.get("source")),
                "source_file": safe_str(s.get("source_file")),
                "detail_url": detail_url,
                "stable_direct_url": detail_url_for_order_number(order_no),
                "manual_search_hint": order_no,
                "note": "Summary/list page contained this order and detail link, but no parsed detail page was captured. Re-run capture-cdp or open/save this detail page into ./lowes_scraper/import/.",
            }
            detail_links_to_capture.append(row)
            failed_detail_captures.append({**row, "failure_type": "summary_without_parsed_detail"})
            failed_seen.add(order_no)

    for diag in diagnostics:
        source_file = safe_str(diag.get("source_file"))
        base = Path(source_file).name
        m = re.search(r"lowes_order_detail_(.+?)_unparsed\.html$", base)
        order_no = safe_str(diag.get("rendered_detail_order_number") or diag.get("detail_order_number"))
        if not order_no and m:
            order_no = re.sub(r"[^0-9].*$", "", m.group(1))
        if order_no and order_no not in details_seen and order_no not in failed_seen:
            failed_detail_captures.append({
                "order_number": order_no,
                "purchase_date": "",
                "summary_source": "captured_detail_unparsed",
                "source_file": source_file,
                "detail_url": "",
                "stable_direct_url": detail_url_for_order_number(order_no),
                "manual_search_hint": order_no,
                "failure_type": "captured_detail_unparsed",
                "diagnosis": safe_str(diag.get("diagnosis")),
                "order_details_error_status": safe_str(diag.get("order_details_error_status")),
                "note": "A detail page was captured but did not contain usable order data. Search the order number in Lowe's orders, save the fully displayed page as Webpage Complete into ./lowes_scraper/import/, then run parse-saved on that import folder.",
            })
            failed_seen.add(order_no)

    items: List[Dict[str, Any]] = []
    excluded_items: List[Dict[str, Any]] = []
    payments: List[Dict[str, Any]] = []
    orders_flat: List[Dict[str, Any]] = []

    for d in details:
        orders_flat.append({k: v for k, v in d.items() if k not in ("items", "payments", "return_releases", "excluded_items")})
        items.extend(d.get("items", []))
        excluded_items.extend(d.get("excluded_items", []))
        payments.extend(d.get("payments", []))

    stored_value_payments = [p for p in payments if p.get("payment_class") == "stored_value"]
    payment_refunds = [p for p in payments if D(p.get("refund_amount")) != 0]

    write_json(outdir / "lowes_order_summaries.json", summaries)
    write_json(outdir / "lowes_order_details.json", details)
    write_json(outdir / "lowes_parse_diagnostics.json", diagnostics)
    write_json(outdir / "lowes_order_excluded_items.json", excluded_items)
    write_json(outdir / "lowes_order_stored_value_payments.json", stored_value_payments)
    write_json(outdir / "lowes_order_payment_refunds.json", payment_refunds)
    write_json(outdir / "lowes_detail_links_to_capture.json", detail_links_to_capture)
    write_json(outdir / "lowes_detail_capture_errors.json", failed_detail_captures)
    write_csv(outdir / "lowes_order_summaries.csv", summaries)
    write_csv(outdir / "lowes_orders.csv", orders_flat)
    write_csv(outdir / "lowes_order_items.csv", items)
    write_csv(outdir / "lowes_order_payments.csv", payments)
    write_csv(outdir / "lowes_order_stored_value_payments.csv", stored_value_payments)
    write_csv(outdir / "lowes_order_payment_refunds.csv", payment_refunds)
    detail_error_fields = ["order_number", "purchase_date", "summary_source", "source_file", "detail_url", "stable_direct_url", "manual_search_hint", "failure_type", "diagnosis", "order_details_error_status", "note"]
    write_csv(outdir / "lowes_detail_links_to_capture.csv", detail_links_to_capture, fieldnames=detail_error_fields[:7] + ["note"])
    write_csv(outdir / "lowes_detail_capture_errors.csv", failed_detail_captures, fieldnames=detail_error_fields)
    write_csv(outdir / "lowes_order_excluded_items.csv", excluded_items)
    write_csv(outdir / "lowes_parse_diagnostics.csv", diagnostics)

    print(f"Parsed {len(summaries)} unique summary order(s), {len(details)} detail order(s) from {len(html_files)} HTML file(s).")
    if synthesized_returns:
        print(f"Synthesized {len(synthesized_returns)} missing return detail order(s) from embedded return releases.")
    if excluded_items:
        print(f"Excluded {len(excluded_items)} cancelled item line(s) from accounting item output; see lowes_order_excluded_items.csv.")
    if stored_value_payments:
        print(f"Classified {len(stored_value_payments)} stored-value payment row(s); see lowes_order_stored_value_payments.csv.")
    if payment_refunds:
        print(f"Found {len(payment_refunds)} payment row(s) with refund_amount evidence; see lowes_order_payment_refunds.csv.")
    if detail_links_to_capture:
        print(f"WARNING: {len(detail_links_to_capture)} summary order(s) have detail links but no parsed detail page; see lowes_detail_links_to_capture.csv.")
    if failed_detail_captures:
        print(f"WARNING: {len(failed_detail_captures)} order detail capture(s) still need manual review; see lowes_detail_capture_errors.csv.")
        print("Unsuccessful order detail captures:")
        for row in failed_detail_captures[:50]:
            print(f"  - {safe_str(row.get('order_number'))}  {safe_str(row.get('failure_type'))}  {safe_str(row.get('stable_direct_url'))}")
        if len(failed_detail_captures) > 50:
            print(f"  ... and {len(failed_detail_captures) - 50} more")
    print(f"Wrote output to: {outdir}")
    if not html_files:
        print("WARNING: No .html/.htm files were found under the input path.")
    if html_files and not summaries and not details:
        print("WARNING: No Lowe's orders were parsed. Review lowes_parse_diagnostics.csv.")
        print("Most common causes: saved/captured page is Access Denied, sign-in/verification page, or not an order summary/detail page.")
    if details:
        bad = [d for d in details if D(d.get("validation_delta_total_minus_item_lines", "0")) != 0]
        if bad:
            print(f"WARNING: {len(bad)} detail order(s) have non-zero item-line validation deltas. Review lowes_orders.csv.")
    return 0


def find_executable(candidates: List[str]) -> Optional[str]:
    for c in candidates:
        p = shutil.which(c)
        if p:
            return p
    for c in candidates:
        pp = Path(c).expanduser()
        if pp.exists() and pp.is_file():
            return str(pp)
    return None


def build_chromium_launch_options(args: argparse.Namespace) -> Dict[str, Any]:
    """Build Playwright launch options.

    The default in v2 is auto: prefer a system-installed Google Chrome/Chromium
    when available. This avoids Playwright's bundled-browser installer on Linux
    distributions that Playwright has not recognized yet, such as Ubuntu 26.04.
    """
    launch_options: Dict[str, Any] = {
        "headless": args.headless,
        "viewport": {"width": 1440, "height": 1100},
    }

    if args.executable_path:
        launch_options["executable_path"] = str(Path(args.executable_path).expanduser())
        print(f"Using browser executable: {launch_options['executable_path']}")
        return launch_options

    channel = (args.browser_channel or "auto").strip().lower()
    if channel in ("chrome", "chrome-beta", "chrome-dev", "chrome-canary", "msedge", "msedge-beta", "msedge-dev", "msedge-canary"):
        launch_options["channel"] = channel
        print(f"Using Playwright browser channel: {channel}")
        return launch_options

    if channel == "bundled":
        print("Using Playwright bundled Chromium. This requires: python -m playwright install chromium")
        return launch_options

    if channel not in ("auto", "system"):
        raise SystemExit(f"Unsupported --browser-channel value: {args.browser_channel}")

    # Prefer Chrome stable because Playwright officially supports branded stable/beta
    # channels and Chrome's .deb is usually simpler than Ubuntu's snap Chromium for
    # persistent profile automation.
    chrome = find_executable([
        "google-chrome",
        "google-chrome-stable",
        "/usr/bin/google-chrome",
        "/usr/bin/google-chrome-stable",
    ])
    if chrome:
        launch_options["channel"] = "chrome"
        print("Using system Google Chrome via Playwright channel=chrome")
        return launch_options

    chromium = find_executable([
        "chromium",
        "chromium-browser",
        "/usr/bin/chromium",
        "/usr/bin/chromium-browser",
        "/snap/bin/chromium",
    ])
    if chromium:
        launch_options["executable_path"] = chromium
        print(f"Using system Chromium executable: {chromium}")
        return launch_options

    # Last resort: use bundled Chromium. On unsupported Linux releases this may fail
    # unless you run Playwright in a supported distro/container.
    print("No system Chrome/Chromium found; falling back to Playwright bundled Chromium.")
    print("If this fails on Ubuntu 26.04, install Google Chrome or pass --executable-path.")
    return launch_options



def summary_total_from_html(html: str) -> Optional[int]:
    """Return Lowe's orderHistory.data.total from a summary page, if present."""
    try:
        state = extract_preloaded_state(html)
        if not state:
            return None
        total = (((state.get("orderHistory") or {}).get("data") or {}).get("total"))
        return int(total) if total is not None else None
    except Exception:
        return None


def current_detail_links(page: Any) -> List[str]:
    """Return unique order-detail links currently rendered on the page."""
    try:
        links = page.locator('a[href*="/mylowes/orders/details"]').evaluate_all(
            "(els) => Array.from(new Set(els.map(a => a.href).filter(Boolean)))"
        )
    except Exception:
        return []
    out: List[str] = []
    seen: set[str] = set()
    for link in links:
        add_unique_detail_link(out, seen, link)
    return out


def click_next_summary_page(page: Any, settle_ms: int) -> bool:
    """Click Lowe's order-history next-page button when it is available."""
    selectors = [
        'button[aria-label="Go to next page"]:not([disabled])',
        'button.pagination-item.type--next:not([disabled])',
    ]
    for sel in selectors:
        try:
            btn = page.locator(sel).first
            if btn.count() > 0:
                btn.click(timeout=10000)
                page.wait_for_timeout(settle_ms)
                return True
        except Exception:
            continue
    return False


def collect_summary_pages_and_detail_links(page: Any, rawdir: Path, year: str, args: argparse.Namespace) -> List[str]:
    """Save all needed summary pages for one year and collect detail links.

    Lowe's retail history renders five orders per summary page. Earlier builds
    only saw the first five links, so --max-details 10 still captured five. In
    v7, --max-details is a cap across paginated summary pages; 0 means collect
    every page/link Lowe's exposes for that year unless --max-summary-pages is set.
    """
    max_details = int(getattr(args, "max_details", 0) or 0)
    max_summary_pages = int(getattr(args, "max_summary_pages", 0) or 0)
    detail_link_buffer = int(getattr(args, "detail_link_buffer", 5) or 0)
    collection_cap = max_details + max(0, detail_link_buffer) if max_details > 0 else 0
    all_links: List[str] = []
    seen = set()
    page_no = 1
    total_hint: Optional[int] = None
    last_page_links: List[str] = []

    while True:
        page.wait_for_timeout(getattr(args, "settle_ms", 2500))
        html = page.content()
        this_total = summary_total_from_html(html)
        if this_total is not None:
            total_hint = this_total

        if page_no == 1:
            summary_path = rawdir / f"lowes_orders_{year}.html"
        else:
            summary_path = rawdir / f"lowes_orders_{year}_p{page_no:02d}.html"
        summary_path.write_text(html, encoding="utf-8")
        print(f"Saved {summary_path}")

        page_links = current_detail_links(page)
        rendered_orders = rendered_summary_orders_from_html(html, source_file=str(summary_path))
        for ro in rendered_orders:
            detail_url = safe_str(ro.get("detail_url"))
            if detail_url and detail_url not in page_links:
                page_links.append(detail_url)
        new_count = 0
        for link in page_links:
            if add_unique_detail_link(all_links, seen, link):
                new_count += 1

        total_msg = f" of {total_hint}" if total_hint is not None else ""
        print(
            f"Year {year} summary page {page_no}: found {len(page_links)} rendered detail link(s), "
            f"{new_count} new; collected {len(all_links)}{total_msg}."
        )

        if collection_cap > 0 and len(all_links) >= collection_cap:
            print(
                f"Collected --max-details {max_details} plus buffer {detail_link_buffer}; "
                f"stopping summary pagination for {year}."
            )
            break
        if max_summary_pages > 0 and page_no >= max_summary_pages:
            print(f"Reached --max-summary-pages {max_summary_pages}; stopping summary pagination for {year}.")
            break

        if page_no > 1 and page_links == last_page_links and new_count == 0:
            print(f"No new detail links appeared on summary page {page_no}; stopping pagination for {year} to avoid a loop.")
            break
        last_page_links = list(page_links)

        if not click_next_summary_page(page, getattr(args, "settle_ms", 2500)):
            print(f"No enabled next-page button found for {year}; collected all visible summary pages.")
            break
        page_no += 1

    # Direct page probe fallback. Lowe's sometimes exposes ONLINE orders at
    # /mylowes/orders?orderType=ONLINE&page=N even when click-based pagination or
    # the default /mylowes/orders?show=YYYY path misses a page. This pass is
    # intentionally duplicate-tolerant and keeps walking through the configured
    # page count so page 4+ gaps are not skipped merely because page 1 duplicated.
    direct_pages = int(getattr(args, "direct_summary_pages", 0) or 0)
    order_types_raw = safe_str(getattr(args, "order_types", "BOTH,ONLINE,INSTOREBACKROOM"))
    order_types = [x.strip().upper() for x in order_types_raw.split(",") if x.strip()]
    if direct_pages > 0 and order_types:
        for order_type in order_types:
            empty_pages = 0
            for direct_page in range(1, direct_pages + 1):
                if collection_cap > 0 and len(all_links) >= collection_cap:
                    break
                if order_type in {"ALL", "BOTH", ""}:
                    url = f"https://www.lowes.com/mylowes/orders?page={direct_page}&show={year}"
                else:
                    url = f"https://www.lowes.com/mylowes/orders?orderType={order_type}&page={direct_page}&show={year}"
                try:
                    page.goto(url, wait_until="domcontentloaded", timeout=60000)
                    page.wait_for_timeout(getattr(args, "settle_ms", 2500))
                    html = page.content()
                except Exception as exc:
                    print(f"WARNING: failed direct {order_type} summary page {direct_page} for {year}: {exc}")
                    empty_pages += 1
                    if empty_pages >= 2:
                        break
                    continue

                direct_path = rawdir / f"lowes_orders_{year}_{order_type.lower()}_direct_p{direct_page:02d}.html"
                direct_path.write_text(html, encoding="utf-8")
                page_links = current_detail_links(page)
                rendered_orders = rendered_summary_orders_from_html(html, source_file=str(direct_path))
                for ro in rendered_orders:
                    detail_url = safe_str(ro.get("detail_url"))
                    if detail_url and detail_url not in page_links:
                        page_links.append(detail_url)
                new_count = 0
                for link in page_links:
                    if add_unique_detail_link(all_links, seen, link):
                        new_count += 1
                print(
                    f"Year {year} direct {order_type} page {direct_page}: "
                    f"rendered orders={len(rendered_orders)}, links={len(page_links)}, "
                    f"{new_count} new; collected {len(all_links)}."
                )
                if not page_links and not rendered_orders:
                    empty_pages += 1
                    if empty_pages >= 2:
                        print(f"Stopping direct {order_type} probe for {year} after {empty_pages} empty pages.")
                        break
                else:
                    empty_pages = 0

    if collection_cap > 0 and len(all_links) > collection_cap:
        all_links = all_links[:collection_cap]
    print(f"Collected {len(all_links)} candidate detail link(s) for {year} after pagination/direct probes.")
    return all_links


def parse_detail_from_page_content(content: str) -> Optional[Dict[str, Any]]:
    state = extract_preloaded_state(content)
    if not state:
        return None
    return normalize_detail_state(state)


def wait_and_parse_detail(page: Any, expected_order_no: str, args: argparse.Namespace) -> Tuple[str, Optional[Dict[str, Any]]]:
    """Wait/retry for a Lowe's detail page to hydrate, then parse it.

    Some older Lowe's detail pages initially render a shell with orderDetails
    keys but no usable line/order payload. Give the page several chances to
    hydrate before deciding it is unparsed.
    """
    attempts = max(1, int(getattr(args, "detail_retry_count", 3) or 3))
    wait_ms = max(500, int(getattr(args, "detail_retry_ms", 4000) or 4000))
    content = ""
    detail = None
    for attempt in range(attempts):
        if attempt == 0:
            page.wait_for_timeout(getattr(args, "settle_ms", 2500))
        else:
            page.wait_for_timeout(wait_ms)
        try:
            if expected_order_no:
                page.wait_for_function(
                    "(oid) => document.body && document.body.innerText && document.body.innerText.includes(oid)",
                    arg=expected_order_no,
                    timeout=1500,
                )
        except Exception:
            pass
        content = page.content()
        detail = parse_detail_from_page_content(content)
        if detail and detail.get("order_number"):
            if not expected_order_no or safe_str(detail.get("order_number")) == expected_order_no:
                return content, detail
    return content, detail

def capture_cdp(args: argparse.Namespace) -> int:
    """Capture Lowe's pages from an already-open browser via Chrome DevTools Protocol.

    This mode is for sites that block Playwright-launched browser contexts. You
    launch your normal browser yourself with --remote-debugging-port, log in
    manually, then this tool attaches only after the session is already working.

    It does not store credentials. It saves the same raw HTML evidence and then
    runs the normal parser over the captured pages.
    """
    try:
        from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
    except ImportError as exc:
        raise SystemExit(
            "Missing dependency: playwright. Install with:\n"
            "  pip install -r requirements.txt\n"
        ) from exc

    outdir = Path(args.out).expanduser().resolve()
    rawdir = outdir / "raw_html"
    rawdir.mkdir(parents=True, exist_ok=True)

    years = [y.strip() for y in args.years.split(",") if y.strip()]
    if not years:
        raise SystemExit("No years specified.")

    endpoint = args.cdp_endpoint
    with sync_playwright() as p:
        print(f"Connecting to already-open browser at {endpoint}")
        browser = p.chromium.connect_over_cdp(endpoint)
        if not browser.contexts:
            raise SystemExit("Connected to browser, but no browser contexts were found.")
        context = browser.contexts[0]
        page = context.pages[0] if context.pages else context.new_page()

        print("Using an already-open browser session. Log in manually before/when prompted.")
        print("If Lowe's shows an access-denied page even in this browser, stop and use manual saved pages instead.")

        for year in years:
            url = f"https://www.lowes.com/mylowes/orders?show={year}"
            print(f"Opening {url}")
            page.goto(url, wait_until="domcontentloaded", timeout=60000)
            try:
                page.wait_for_selector('a[href*="/mylowes/orders/details"]', timeout=args.login_wait_ms)
            except PlaywrightTimeoutError:
                print("No detail links found yet. Complete login/verification in the existing browser, then waiting 30s more...")
                page.wait_for_timeout(30000)

            links = collect_summary_pages_and_detail_links(page, rawdir, year, args)

            target_valid_details = int(getattr(args, "max_details", 0) or 0)
            valid_details = 0
            failed_details = 0
            detail_seen = set(links)
            i = 0
            while i < len(links):
                link = links[i]
                i += 1
                expected_order_no = order_number_from_detail_url(link)
                print(f"[{year} {i}/{len(links)}] {link}")
                page.goto(link, wait_until="domcontentloaded", timeout=60000)
                content, detail = wait_and_parse_detail(page, expected_order_no, args)
                order_no = f"{year}_{i:04d}"

                # If Lowe's served a list/stale page for the rendered link, retry
                # the stable direct URL that contains only t=<base64 order>.
                if expected_order_no and (not detail or safe_str(detail.get("order_number")) != expected_order_no):
                    fallback = detail_url_for_order_number(expected_order_no)
                    if fallback and fallback != link:
                        print(f"  Detail did not match expected order {expected_order_no}; retrying stable direct URL.")
                        try:
                            page.goto(fallback, wait_until="domcontentloaded", timeout=60000)
                            fallback_content, fallback_detail = wait_and_parse_detail(page, expected_order_no, args)
                            if fallback_detail and safe_str(fallback_detail.get("order_number")) == expected_order_no:
                                content = fallback_content
                                detail = fallback_detail
                                link = fallback
                        except Exception as exc:
                            print(f"  WARNING: fallback direct detail URL failed for {expected_order_no}: {exc}")

                if detail and detail.get("order_number"):
                    order_no = re.sub(r"[^0-9A-Za-z_.-]+", "_", safe_str(detail["order_number"]))
                elif expected_order_no:
                    order_no = re.sub(r"[^0-9A-Za-z_.-]+", "_", expected_order_no) + "_unparsed"

                (rawdir / f"lowes_order_detail_{order_no}.html").write_text(content, encoding="utf-8")
                if detail and detail.get("order_number"):
                    valid_details += 1
                else:
                    failed_details += 1
                    print(f"  WARNING: detail page did not contain usable order data; saved as {order_no}.")
                    # A failed detail navigation can land on an order-list page.
                    # Mine that rendered DOM for recapturable detail URLs so page
                    # gaps do not disappear silently.
                    for ro in rendered_summary_orders_from_html(content, source_file=str(rawdir / f"lowes_order_detail_{order_no}.html")):
                        extra_url = safe_str(ro.get("detail_url"))
                        if add_unique_detail_link(links, detail_seen, extra_url):
                            print(f"  Queued recapture candidate from fallback page: {safe_str(ro.get('order_number'))}")
                if target_valid_details > 0 and valid_details >= target_valid_details:
                    print(
                        f"Reached --max-details {target_valid_details} usable detail page(s) "
                        f"for {year}; skipped/failed detail pages: {failed_details}."
                    )
                    break

        # Do not close the user's browser. Just detach.
        browser.close()

    args2 = argparse.Namespace(input=str(rawdir), out=str(outdir / "normalized"))
    return parse_saved(args2)


def save_current_cdp(args: argparse.Namespace) -> int:
    """Save the currently open Lowe's page from an existing browser via CDP.

    Useful when Lowe's blocks navigation automation but allows you to navigate
    manually. Navigate to an order summary or detail page yourself, then run this
    command to save the currently displayed DOM for parsing.
    """
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise SystemExit("Missing dependency: playwright. Install with: pip install -r requirements.txt") from exc

    outdir = Path(args.out).expanduser().resolve()
    rawdir = outdir / "raw_html"
    rawdir.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as p:
        print(f"Connecting to already-open browser at {args.cdp_endpoint}")
        browser = p.chromium.connect_over_cdp(args.cdp_endpoint)
        if not browser.contexts or not browser.contexts[0].pages:
            raise SystemExit("Connected to browser, but no open page was found.")
        page = browser.contexts[0].pages[-1]
        page.wait_for_timeout(args.settle_ms)
        url = page.url
        content = page.content()
        state = extract_preloaded_state(content)
        name = args.name.strip() if args.name else "lowes_current_page"
        if state:
            detail = normalize_detail_state(state)
            if detail and detail.get("order_number"):
                name = f"lowes_order_detail_{re.sub(r'[^0-9A-Za-z_.-]+', '_', detail['order_number'])}"
        safe_name = re.sub(r"[^0-9A-Za-z_.-]+", "_", name).strip("_") or "lowes_current_page"
        path = rawdir / f"{safe_name}.html"
        path.write_text(content, encoding="utf-8")
        print(f"Saved {path}")
        print(f"Source URL: {url}")
        browser.close()

    if args.parse:
        args2 = argparse.Namespace(input=str(rawdir), out=str(outdir / "normalized"))
        return parse_saved(args2)
    return 0

def scrape(args: argparse.Namespace) -> int:
    try:
        from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
    except ImportError as exc:
        raise SystemExit(
            "Missing dependency: playwright. Install with:\n"
            "  pip install -r requirements.txt\n"
            "For Ubuntu 26.04, do not run the bundled browser install first; use system Chrome instead."
        ) from exc

    outdir = Path(args.out).expanduser().resolve()
    rawdir = outdir / "raw_html"
    rawdir.mkdir(parents=True, exist_ok=True)
    profile = Path(args.profile).expanduser().resolve()
    profile.mkdir(parents=True, exist_ok=True)

    years = [y.strip() for y in args.years.split(",") if y.strip()]
    if not years:
        raise SystemExit("No years specified.")

    with sync_playwright() as p:
        launch_options = build_chromium_launch_options(args)
        context = p.chromium.launch_persistent_context(
            user_data_dir=str(profile),
            **launch_options,
        )
        page = context.new_page()

        for year in years:
            url = f"https://www.lowes.com/mylowes/orders?show={year}"
            print(f"Opening {url}")
            page.goto(url, wait_until="domcontentloaded", timeout=60000)
            print("If Lowe's asks you to sign in or verify, complete it in the browser window.")
            try:
                page.wait_for_selector('a[href*="/mylowes/orders/details"]', timeout=args.login_wait_ms)
            except PlaywrightTimeoutError:
                print("No detail links found yet. Waiting 30s more for manual login/verification...")
                page.wait_for_timeout(30000)

            # Save paginated summary pages and collect detail links.
            links = collect_summary_pages_and_detail_links(page, rawdir, year, args)

            target_valid_details = int(getattr(args, "max_details", 0) or 0)
            valid_details = 0
            failed_details = 0
            detail_seen = set(links)
            i = 0
            while i < len(links):
                link = links[i]
                i += 1
                expected_order_no = order_number_from_detail_url(link)
                print(f"[{year} {i}/{len(links)}] {link}")
                page.goto(link, wait_until="domcontentloaded", timeout=60000)
                content, detail = wait_and_parse_detail(page, expected_order_no, args)
                order_no = f"{year}_{i:04d}"

                # If Lowe's served a list/stale page for the rendered link, retry
                # the stable direct URL that contains only t=<base64 order>.
                if expected_order_no and (not detail or safe_str(detail.get("order_number")) != expected_order_no):
                    fallback = detail_url_for_order_number(expected_order_no)
                    if fallback and fallback != link:
                        print(f"  Detail did not match expected order {expected_order_no}; retrying stable direct URL.")
                        try:
                            page.goto(fallback, wait_until="domcontentloaded", timeout=60000)
                            fallback_content, fallback_detail = wait_and_parse_detail(page, expected_order_no, args)
                            if fallback_detail and safe_str(fallback_detail.get("order_number")) == expected_order_no:
                                content = fallback_content
                                detail = fallback_detail
                                link = fallback
                        except Exception as exc:
                            print(f"  WARNING: fallback direct detail URL failed for {expected_order_no}: {exc}")

                if detail and detail.get("order_number"):
                    order_no = re.sub(r"[^0-9A-Za-z_.-]+", "_", safe_str(detail["order_number"]))
                elif expected_order_no:
                    order_no = re.sub(r"[^0-9A-Za-z_.-]+", "_", expected_order_no) + "_unparsed"

                (rawdir / f"lowes_order_detail_{order_no}.html").write_text(content, encoding="utf-8")
                if detail and detail.get("order_number"):
                    valid_details += 1
                else:
                    failed_details += 1
                    print(f"  WARNING: detail page did not contain usable order data; saved as {order_no}.")
                    # A failed detail navigation can land on an order-list page.
                    # Mine that rendered DOM for recapturable detail URLs so page
                    # gaps do not disappear silently.
                    for ro in rendered_summary_orders_from_html(content, source_file=str(rawdir / f"lowes_order_detail_{order_no}.html")):
                        extra_url = safe_str(ro.get("detail_url"))
                        if add_unique_detail_link(links, detail_seen, extra_url):
                            print(f"  Queued recapture candidate from fallback page: {safe_str(ro.get('order_number'))}")
                if target_valid_details > 0 and valid_details >= target_valid_details:
                    print(
                        f"Reached --max-details {target_valid_details} usable detail page(s) "
                        f"for {year}; skipped/failed detail pages: {failed_details}."
                    )
                    break

        context.close()

    # Parse what we captured.
    args2 = argparse.Namespace(input=str(rawdir), out=str(outdir / "normalized"))
    return parse_saved(args2)


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Lowe's retail purchase-history scraper/parser")
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("parse-saved", help="Parse saved Lowe's HTML files into normalized JSON/CSV")
    p.add_argument("--input", required=True, help="Saved HTML file or directory")
    p.add_argument("--out", required=True, help="Output directory")
    p.set_defaults(func=parse_saved)

    p = sub.add_parser("scrape", help="Scrape logged-in Lowe's retail order history locally with Playwright-launched browser")
    p.add_argument("--years", required=True, help="Comma-separated years, e.g. 2024,2025,2026")
    p.add_argument("--out", required=True, help="Output directory")
    p.add_argument("--profile", default="~/.cache/lowes-retail-scraper-profile", help="Dedicated browser profile directory")
    p.add_argument("--browser-channel", default="auto", help="Browser choice: auto, chrome, chrome-beta, chrome-dev, chrome-canary, msedge, or bundled")
    p.add_argument("--executable-path", default="", help="Explicit path to Chrome/Chromium executable; overrides --browser-channel")
    p.add_argument("--headless", action="store_true", help="Run headless; not recommended for first login")
    p.add_argument("--login-wait-ms", type=int, default=120000, help="How long to wait for manual login/detail links")
    p.add_argument("--settle-ms", type=int, default=2500, help="Wait after navigation before saving")
    p.add_argument("--max-details", type=int, default=0, help="Optional cap on usable detail pages per year; 0 means all detail links")
    p.add_argument("--detail-link-buffer", type=int, default=5, help="When --max-details is set, collect this many extra candidate links to tolerate blank/expired detail pages")
    p.add_argument("--max-summary-pages", type=int, default=0, help="Optional cap on summary pages per year; 0 means all pages")
    p.add_argument("--direct-summary-pages", type=int, default=20, help="Also probe direct orderType pages 1..N per year; 0 disables")
    p.add_argument("--order-types", default="BOTH,ONLINE,INSTOREBACKROOM", help="Comma-separated direct orderType filters to probe, e.g. BOTH,ONLINE,INSTOREBACKROOM")
    p.add_argument("--detail-retry-count", type=int, default=4, help="Detail page hydrate parse attempts before saving as unparsed")
    p.add_argument("--detail-retry-ms", type=int, default=4000, help="Milliseconds to wait between detail hydration parse attempts")
    p.set_defaults(func=scrape)

    p = sub.add_parser("capture-cdp", help="Capture Lowe's pages from an already-open browser via Chrome DevTools Protocol")
    p.add_argument("--years", required=True, help="Comma-separated years, e.g. 2024,2025,2026")
    p.add_argument("--out", required=True, help="Output directory")
    p.add_argument("--cdp-endpoint", default="http://127.0.0.1:9222", help="Chrome DevTools endpoint for the already-open browser")
    p.add_argument("--login-wait-ms", type=int, default=120000, help="How long to wait for manual login/detail links")
    p.add_argument("--settle-ms", type=int, default=2500, help="Wait after navigation before saving")
    p.add_argument("--max-details", type=int, default=0, help="Optional cap on usable detail pages per year; 0 means all detail links")
    p.add_argument("--detail-link-buffer", type=int, default=5, help="When --max-details is set, collect this many extra candidate links to tolerate blank/expired detail pages")
    p.add_argument("--max-summary-pages", type=int, default=0, help="Optional cap on summary pages per year; 0 means all pages")
    p.add_argument("--direct-summary-pages", type=int, default=20, help="Also probe direct orderType pages 1..N per year; 0 disables")
    p.add_argument("--order-types", default="BOTH,ONLINE,INSTOREBACKROOM", help="Comma-separated direct orderType filters to probe, e.g. BOTH,ONLINE,INSTOREBACKROOM")
    p.add_argument("--detail-retry-count", type=int, default=4, help="Detail page hydrate parse attempts before saving as unparsed")
    p.add_argument("--detail-retry-ms", type=int, default=4000, help="Milliseconds to wait between detail hydration parse attempts")
    p.set_defaults(func=capture_cdp)

    p = sub.add_parser("save-current-cdp", help="Save the currently open Lowe's page from an existing browser via CDP")
    p.add_argument("--out", required=True, help="Output directory")
    p.add_argument("--cdp-endpoint", default="http://127.0.0.1:9222", help="Chrome DevTools endpoint for the already-open browser")
    p.add_argument("--name", default="", help="Optional output filename stem")
    p.add_argument("--settle-ms", type=int, default=1000, help="Wait before saving the current page")
    p.add_argument("--parse", action="store_true", help="Run parse-saved after saving the current page")
    p.set_defaults(func=save_current_cdp)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
