#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parse or capture Tractor Supply order pages into normalized CSVs.

Modes:
  parse-saved   Parse saved "Webpage, Complete" HTML pages or a ZIP/folder.
  capture-cdp   Attach to an already-open Chrome/Chromium session and walk orders.

The capture mode is intentionally local-only. It does not store credentials. The
user launches a browser with --remote-debugging-port, logs in manually, and the
script saves raw HTML evidence plus normalized CSVs/ZIP for the web importer.
"""
from __future__ import annotations

import argparse
import csv
import json
import re
import hashlib
import sys
import time
import zipfile
import shutil
import datetime as dt
from pathlib import Path
from typing import Iterable
from urllib.parse import urljoin, urlparse, parse_qs, quote
from urllib.request import urlopen

try:
    from bs4 import BeautifulSoup
except Exception as exc:  # pragma: no cover
    raise SystemExit("BeautifulSoup is required. Run: pip install -r tractorsupply_scraper/requirements.txt") from exc

TSC_BASE = "https://www.tractorsupply.com"
SCRIPT_VERSION = "v203"


def money_to_float(value: str) -> float:
    value = (value or "").replace("−", "-").strip()
    neg = False
    if value.startswith("(") and value.endswith(")"):
        neg = True
    value = re.sub(r"[^0-9.\-]", "", value)
    if value in ("", "-", "."):
        return 0.0
    try:
        out = round(float(value), 2)
        return -abs(out) if neg else out
    except ValueError:
        return 0.0


def fmt_money(value: float) -> str:
    return f"{value:.2f}"


def normalize_date(value: str) -> str:
    value = (value or "").strip()
    if not value:
        return ""
    value = re.sub(r"^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+", "", value, flags=re.I)
    import datetime as _dt
    for fmt in ("%B %d, %Y", "%b %d, %Y", "%m/%d/%Y", "%Y-%m-%d"):
        try:
            return _dt.datetime.strptime(value, fmt).strftime("%Y-%m-%d")
        except ValueError:
            pass
    return value


def html_text_lines(path: Path) -> list[str]:
    html = path.read_text(errors="replace")
    soup = BeautifulSoup(html, "html.parser")
    text = soup.get_text("\n", strip=True)
    return [ln.strip() for ln in text.splitlines() if ln.strip()]


def next_after(lines: list[str], label: str, start: int = 0) -> str:
    label_l = label.lower()
    for i in range(start, len(lines)):
        if lines[i].strip().lower() == label_l and i + 1 < len(lines):
            return lines[i + 1].strip()
    return ""


def next_money_after(lines: list[str], label: str, start: int = 0, max_scan: int = 80) -> str:
    label_l = label.lower()
    for i in range(start, len(lines)):
        if lines[i].strip().lower() == label_l:
            for j in range(i + 1, min(len(lines), i + 1 + max_scan)):
                candidate = lines[j].strip()
                if re.search(r"[$−-]?\(?\d[\d,]*\.\d{2}\)?", candidate):
                    return candidate
            return ""
    return ""


def find_index(lines: list[str], label: str, start: int = 0) -> int:
    label_l = label.lower()
    for i in range(start, len(lines)):
        if lines[i].strip().lower() == label_l:
            return i
    return -1


def safe_filename(value: str, fallback: str = "page") -> str:
    value = re.sub(r"[^0-9A-Za-z_.-]+", "_", value or "").strip("._")
    return value or fallback


def detail_links_from_html(html: str) -> list[str]:
    soup = BeautifulSoup(html, "html.parser")
    out: list[str] = []
    seen = set()
    for a in soup.find_all("a", href=True):
        href = str(a.get("href") or "")
        txt = a.get_text(" ", strip=True).lower()
        if "subNav=orderDetail" in href or "externalOrderId=" in href or "view order details" in txt:
            url = urljoin(TSC_BASE, href)
            if url not in seen:
                seen.add(url)
                out.append(url)
    return out


def external_order_id_from_url(url: str) -> str:
    try:
        qs = parse_qs(urlparse(url).query)
        for key in ("externalOrderId", "orderId", "orderNumber"):
            vals = qs.get(key)
            if vals and vals[0]:
                return vals[0]
    except Exception:
        pass
    return ""



def external_order_id_from_path(path: Path) -> str:
    """Recover TSC externalOrderId from captured detail filenames.

    In-store receipt/detail pages often omit a visible "Order Number:" label,
    but the URL/filename contains a long externalOrderId.  That ID is the only
    stable identifier available for those rendered pages.
    """
    name = path.name
    m = re.search(r"tsc_order_detail(?:_[a-z]+)?_([0-9]{12,})\.html?$", name, flags=re.I)
    if m:
        return m.group(1)
    m = re.search(r"externalOrderId[=_-]([0-9]{12,})", name, flags=re.I)
    if m:
        return m.group(1)
    return ""


def compact_instore_order_id(external_id: str, order_date: str = "") -> str:
    """Build a readable invoice ID for in-store TSC receipts.

    The raw externalOrderId can be 40+ digits.  Keep enough information to be
    unique and human-searchable: date plus the receipt-like trailing digits.
    """
    ext = re.sub(r"\D+", "", external_id or "")
    if not ext:
        return ""
    if order_date and re.fullmatch(r"\d{4}-\d{2}-\d{2}", order_date):
        date_part = order_date.replace("-", "")
    else:
        date_part = ext[:8] if len(ext) >= 8 else ""
    tail = ext[-9:] if len(ext) >= 9 else ext
    return f"TSC-INSTORE-{date_part}-{tail}" if date_part else f"TSC-INSTORE-{tail}"




def clean_product_description(value: str) -> str:
    """Normalize a product title/description found in TSC HTML or helper CSVs."""
    value = BeautifulSoup(value or "", "html.parser").get_text(" ", strip=True)
    value = value.replace("\xa0", " ")
    value = re.sub(r"\s+", " ", value).strip(" -|\t\r\n")
    value = re.sub(r"\s+at\s+Tractor\s+Supply\s+Co\.?$", "", value, flags=re.I).strip()
    value = re.sub(r"^Shop\s+for\s+", "", value, flags=re.I).strip()
    value = re.sub(r"\s+,", ",", value).strip()
    return value


def is_placeholder_description(value: str) -> bool:
    value = clean_product_description(value).lower().rstrip(":")
    if not value:
        return True
    if value in {"quantity", "price", "subtotal", "status", "product thumbnail", "sku", "item"}:
        return True
    return bool(re.fullmatch(r"tractor supply item\s+\S+", value, flags=re.I))


def sku_key(value: str) -> str:
    return re.sub(r"[^0-9A-Za-z]+", "", value or "").upper()


def product_url_key(value: str) -> str:
    if not value:
        return ""
    try:
        parsed = urlparse(urljoin(TSC_BASE, value))
        return parsed.path.rstrip("/").lower()
    except Exception:
        return ""


def title_from_product_url(value: str) -> str:
    """Fallback title from /tsc/product/<slug> when the order page has a blank anchor."""
    try:
        path = urlparse(urljoin(TSC_BASE, value)).path.rstrip("/")
    except Exception:
        return ""
    m = re.search(r"/product/([^/]+)$", path, flags=re.I)
    if not m:
        return ""
    slug = re.sub(r"-p$", "", m.group(1), flags=re.I)
    words = [w for w in slug.split("-") if w]
    if not words:
        return ""
    small = {"and", "or", "with", "for", "of", "the", "a", "an"}
    out: list[str] = []
    for i, w in enumerate(words):
        lw = w.lower()
        if lw in {"lb", "lbs"}:
            out.append("lb.")
        elif lw in {"oz", "gal", "qt", "pt"}:
            out.append(lw + ".")
        elif lw in small and i != 0:
            out.append(lw)
        elif re.fullmatch(r"\d+(?:\.\d+)?", lw):
            out.append(lw)
        else:
            out.append(" ".join(part.capitalize() for part in lw.split("+")))
    title = " ".join(out)
    title = re.sub(r"\b(\d+(?:\.\d+)?)\s+lb\.$", r", \1 lb.", title, flags=re.I)
    return clean_product_description(title)


def empty_product_lookup() -> dict:
    return {"by_sku": {}, "by_url": {}, "records": []}


def register_product_record(lookup: dict, sku: str = "", description: str = "", item_url: str = "", source: str = "", confidence: int = 50) -> None:
    desc = clean_product_description(description)
    if not desc or is_placeholder_description(desc):
        return
    sk = sku_key(sku)
    uk = product_url_key(item_url)
    record = {"sku": sk, "description": desc, "item_url": urljoin(TSC_BASE, item_url) if item_url else "", "source": source or "unknown", "confidence": int(confidence)}
    def better(existing: dict | None) -> bool:
        if not existing:
            return True
        if int(record["confidence"]) > int(existing.get("confidence", 0)):
            return True
        return len(record["description"]) > len(str(existing.get("description", ""))) and int(record["confidence"]) >= int(existing.get("confidence", 0))
    if sk and better(lookup["by_sku"].get(sk)):
        lookup["by_sku"][sk] = record
    if uk and better(lookup["by_url"].get(uk)):
        lookup["by_url"][uk] = record
    lookup["records"].append(record)


def load_sku_description_csv(path: Path, lookup: dict) -> int:
    if not path or not path.is_file():
        return 0
    count = 0
    with path.open(newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        for row in reader:
            lower = {str(k or "").strip().lower(): (v or "") for k, v in row.items()}
            sku = lower.get("sku") or lower.get("item_id") or lower.get("item id") or lower.get("partnumber") or lower.get("part_number")
            desc = lower.get("description") or lower.get("title") or lower.get("name") or lower.get("shortdescription")
            url = lower.get("item_url") or lower.get("product_url") or lower.get("url")
            if sku and desc:
                register_product_record(lookup, sku, desc, url, f"sku-db:{path.name}", 80)
                count += 1
    return count


def canonical_product_url(soup: BeautifulSoup) -> str:
    for selector in (("link", {"rel": "canonical"}), ("meta", {"property": "og:url"})):
        tag = soup.find(selector[0], attrs=selector[1])
        if tag:
            value = tag.get("href") or tag.get("content") or ""
            if value:
                return urljoin(TSC_BASE, str(value))
    return ""


def parse_tsc_product_page(path: Path, lookup: dict) -> int:
    """Learn SKU -> product title mappings from saved Tractor Supply PDP pages."""
    try:
        html = path.read_text(errors="replace")
    except Exception:
        return 0
    if "tractorsupply" not in html.lower() and "Tractor Supply" not in path.name:
        return 0
    soup = BeautifulSoup(html, "html.parser")
    default_url = canonical_product_url(soup)
    title = ""
    for tag in (soup.find("meta", attrs={"property": "og:title"}), soup.find("title"), soup.find("meta", attrs={"name": "description"})):
        if tag:
            title = clean_product_description(tag.get("content") or tag.get_text(" ", strip=True))
            if title:
                break
    before = len(lookup["records"])
    data_tag = soup.find("script", id="__NEXT_DATA__")
    if data_tag and data_tag.get_text(strip=True):
        try:
            data = json.loads(data_tag.get_text())
        except Exception:
            data = None
        def walk(obj):
            if isinstance(obj, dict):
                desc = obj.get("shortDescription") or obj.get("name") or obj.get("productName")
                part = obj.get("partNumber") or obj.get("partNumber_ntk") or obj.get("sku")
                url = obj.get("seo_url") or obj.get("seoToken") or default_url
                productish = any(k in obj for k in ("catalogEntryTypeCode", "catenttype_id_ntk_cs", "buyable", "sKUs", "hasSingleSKU"))
                if productish and isinstance(part, str) and desc:
                    register_product_record(lookup, part, str(desc), str(url or default_url), f"pdp:{path.name}", 95)
                thumb = obj.get("xf_thumbnail") or obj.get("thumbnail")
                if productish and isinstance(thumb, str) and re.fullmatch(r"/?(?:wcsstore/TSCCatalogAssetStore/)?[0-9A-Za-z_-]+", thumb):
                    thumb_sku = thumb.rsplit("/", 1)[-1]
                    if desc:
                        register_product_record(lookup, thumb_sku, str(desc), str(url or default_url), f"pdp-thumbnail:{path.name}", 90)
                for v in obj.values():
                    walk(v)
            elif isinstance(obj, list):
                for v in obj:
                    walk(v)
        if data is not None:
            walk(data)
    # Text fallback: many TSC pages expose "Item # 1234567" near the visible title.
    lines = [ln.strip() for ln in soup.get_text("\n", strip=True).splitlines() if ln.strip()]
    for i, line in enumerate(lines):
        m = re.search(r"\bItem\s*#\s*([0-9A-Za-z_-]+)", line, flags=re.I)
        if m:
            candidate_title = title
            for j in range(max(0, i - 6), i):
                if len(lines[j]) > 8 and not re.search(r"reviews?|stocked|shop all|home|/", lines[j], flags=re.I):
                    candidate_title = clean_product_description(lines[j])
            register_product_record(lookup, m.group(1), candidate_title, default_url, f"pdp-text:{path.name}", 70)
    return len(lookup["records"]) - before


def write_sku_description_suggestions(path: Path, lookup: dict) -> None:
    rows: dict[tuple[str, str], dict] = {}
    for rec in lookup.get("records", []):
        sku = rec.get("sku", "")
        desc = rec.get("description", "")
        if not sku or not desc:
            continue
        key = (sku, desc)
        prior = rows.get(key)
        if not prior or int(rec.get("confidence", 0)) > int(prior.get("confidence", 0)):
            rows[key] = rec
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["sku", "description", "item_url", "source", "confidence"])
        w.writeheader()
        for rec in sorted(rows.values(), key=lambda r: (r.get("sku", ""), -int(r.get("confidence", 0)))):
            w.writerow({k: rec.get(k, "") for k in ["sku", "description", "item_url", "source", "confidence"]})


def build_product_lookup(html_files: list[Path], sku_db_paths: list[Path] | None = None) -> tuple[dict, int, int]:
    lookup = empty_product_lookup()
    db_count = 0
    default_db = Path(__file__).resolve().parent / "tsc_sku_descriptions.csv"
    seen_dbs: list[Path] = []
    for db in [default_db] + list(sku_db_paths or []):
        if db and db not in seen_dbs:
            seen_dbs.append(db)
            db_count += load_sku_description_csv(db, lookup)
    pdp_count = 0
    for html in html_files:
        pdp_count += parse_tsc_product_page(html, lookup)
    return lookup, db_count, pdp_count


def resolve_item_description(raw_desc: str, sku: str, item_url: str, lookup: dict) -> tuple[str, str]:
    desc = clean_product_description(raw_desc)
    if desc and not is_placeholder_description(desc):
        return desc, "order-detail"
    sk = sku_key(sku)
    uk = product_url_key(item_url)
    if sk and sk in lookup.get("by_sku", {}):
        rec = lookup["by_sku"][sk]
        return rec.get("description", ""), rec.get("source", "sku-db")
    if uk and uk in lookup.get("by_url", {}):
        rec = lookup["by_url"][uk]
        return rec.get("description", ""), rec.get("source", "product-url")
    fallback = title_from_product_url(item_url)
    if fallback:
        register_product_record(lookup, sku, fallback, item_url, "product-url-slug", 40)
        return fallback, "product-url-slug"
    return f"Tractor Supply item {sku}", "placeholder"


def li_value_by_label(container, label: str) -> str:
    label_l = label.lower().rstrip(":")
    for li in container.find_all("li"):
        span = li.find("span")
        if not span:
            continue
        span_text = span.get_text(" ", strip=True).lower().rstrip(":")
        if span_text != label_l:
            continue
        strong = li.find("strong")
        if strong:
            return strong.get_text(" ", strip=True)
        txt = li.get_text(" ", strip=True)
        txt = re.sub(r"^" + re.escape(span.get_text(" ", strip=True)) + r"\s*", "", txt).strip()
        return txt
    return ""


def extract_order_items_from_dom(soup: BeautifulSoup, order_no: str, order_date: str, source_path: Path, lookup: dict) -> list[dict]:
    items: list[dict] = []
    for ul in soup.find_all("ul"):
        sku = li_value_by_label(ul, "SKU")
        if not sku:
            continue
        desc_anchor = ul.find("a", id=re.compile(r"catalogEntry_desc", re.I))
        img_anchor = ul.find("a", id=re.compile(r"catalogEntry_img", re.I))
        href = ""
        if desc_anchor and desc_anchor.get("href"):
            href = str(desc_anchor.get("href"))
        elif img_anchor and img_anchor.get("href"):
            href = str(img_anchor.get("href"))
        item_url = urljoin(TSC_BASE, href) if href else ""
        raw_desc = desc_anchor.get_text(" ", strip=True) if desc_anchor else ""
        desc, source = resolve_item_description(raw_desc, sku, item_url, lookup)
        qty = li_value_by_label(ul, "Quantity") or "1"
        price = li_value_by_label(ul, "Price")
        subtotal = li_value_by_label(ul, "Subtotal")
        status = li_value_by_label(ul, "Status")
        items.append({
            "order_id": order_no,
            "order_date": order_date,
            "line_index": str(len(items) + 1),
            "sku": sku,
            "description": desc,
            "quantity": qty,
            "unit_price": fmt_money(money_to_float(price)),
            "line_total": fmt_money(money_to_float(subtotal)),
            "status": status,
            "item_url": item_url,
            "notes": f"source_file={source_path.name}; desc_source={source}",
        })
    return items


def extract_item_links_from_order_detail_html(html: str) -> list[str]:
    soup = BeautifulSoup(html, "html.parser")
    out: list[str] = []
    seen = set()
    for a in soup.find_all("a", href=True, id=re.compile(r"catalogEntry_(?:desc|img)", re.I)):
        href = str(a.get("href") or "")
        if "/tsc/product/" not in href:
            continue
        url = urljoin(TSC_BASE, href)
        if url not in seen:
            seen.add(url)
            out.append(url)
    return out


def parse_order_detail(path: Path, product_lookup: dict | None = None) -> tuple[dict, list[dict], list[dict], list[dict]]:
    html = path.read_text(errors="replace")
    soup = BeautifulSoup(html, "html.parser")
    text = soup.get_text("\n", strip=True)
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    order_date = normalize_date(next_after(lines, "Order Date:"))
    order_no = next_after(lines, "Order Number:")
    diags: list[dict] = []
    external_id = external_order_id_from_path(path)
    product_lookup = product_lookup or empty_product_lookup()
    has_order_detail = find_index(lines, "Order Details") >= 0 and find_index(lines, "SKU:") >= 0 and find_index(lines, "Order Summary") >= 0
    if not order_no or not re.search(r"\d", order_no):
        # TSC in-store receipt detail pages often do not display an order number.
        # Use a readable ID derived from the externalOrderId captured from the URL.
        if external_id and order_date:
            order_no = compact_instore_order_id(external_id, order_date)
            diags.append({"source_file": str(path), "level": "warn", "message": f"No visible Order Number label; using externalOrderId-derived invoice id {order_no} from {external_id}."})
        elif has_order_detail and order_date:
            digest = hashlib.sha1((path.name + "\n" + "\n".join(lines)).encode("utf-8", errors="replace")).hexdigest()[:10].upper()
            order_no = f"TSC-SAVED-{order_date.replace('-', '')}-{digest}"
            diags.append({"source_file": str(path), "level": "warn", "message": f"No visible Order Number label and no externalOrderId filename; using saved-page-derived invoice id {order_no}."})
        else:
            return {}, [], [], [{"source_file": str(path), "level": "skip", "message": "No order detail block / order number found"}]
    detail_url = ""

    end_item_at = find_index(lines, "Order Summary")
    if end_item_at < 0:
        end_item_at = len(lines)

    items: list[dict] = extract_order_items_from_dom(soup, order_no, order_date, path, product_lookup)
    if not items:
        i = 0
        item_idx = 0
        while i < end_item_at:
            if lines[i].lower() == "sku:" and i + 1 < end_item_at:
                sku = lines[i + 1].strip()
                raw_desc = lines[i + 2].strip() if i + 2 < end_item_at else ""
                desc, source = resolve_item_description(raw_desc, sku, "", product_lookup)
                qty = next_after(lines, "Quantity:", i)
                price = next_after(lines, "Price:", i)
                subtotal = next_after(lines, "Subtotal:", i)
                status = next_after(lines, "Status:", i)
                item_idx += 1
                items.append({
                    "order_id": order_no,
                    "order_date": order_date,
                    "line_index": str(item_idx),
                    "sku": sku,
                    "description": desc,
                    "quantity": qty or "1",
                    "unit_price": fmt_money(money_to_float(price)),
                    "line_total": fmt_money(money_to_float(subtotal)),
                    "status": status,
                    "item_url": "",
                    "notes": f"source_file={path.name}; desc_source={source}",
                })
            i += 1

    subtotal = money_to_float(next_money_after(lines, "Subtotal", end_item_at))
    delivery = money_to_float(next_money_after(lines, "Delivery", end_item_at))
    discount = abs(money_to_float(next_money_after(lines, "Discount", end_item_at)))
    tax = money_to_float(next_money_after(lines, "SalesTax", end_item_at) or next_money_after(lines, "Sales Tax", end_item_at) or next_money_after(lines, "Tax", end_item_at))
    total = money_to_float(next_money_after(lines, "Total", end_item_at))
    item_line_sum = round(sum(money_to_float(str(it.get("line_total", "0"))) for it in items), 2)
    if discount <= 0.005 and subtotal > 0.005 and item_line_sum > subtotal + 0.02 and abs(round(subtotal + delivery + tax - total, 2)) <= 0.02:
        inferred_discount = round(item_line_sum - subtotal, 2)
        discount = inferred_discount
        subtotal = item_line_sum
        diags.append({"source_file": str(path), "level": "info", "message": f"Inferred discount/reconciliation ${inferred_discount:.2f}: item-line subtotals exceed order-summary subtotal."})

    pay_idx = find_index(lines, "Payment:", end_item_at)
    method = ""
    last4 = ""
    if pay_idx >= 0 and pay_idx + 1 < len(lines):
        method = lines[pay_idx + 1]
        if pay_idx + 2 < len(lines) and re.fullmatch(r"\d{3,4}", lines[pay_idx + 2].strip()):
            last4 = lines[pay_idx + 2].strip()
        else:
            m = re.search(r"(\d{4})\b", method)
            if m:
                last4 = m.group(1)
        if last4:
            method = re.sub(r"\s*[*•xX-]{2,}\s*" + re.escape(last4) + r"\b", "", method).strip()
    if not method:
        method = "Tractor Supply payment"

    recipient = ""
    ship_idx = find_index(lines, "Delivery to:", 0)
    if ship_idx >= 0 and ship_idx + 1 < len(lines):
        recipient = lines[ship_idx + 1]

    order = {
        "order_id": order_no,
        "order_date": order_date,
        "order_url": detail_url,
        "recipient": recipient,
        "subtotal": fmt_money(subtotal),
        "shipping": fmt_money(delivery),
        "discount": fmt_money(discount),
        "tax": fmt_money(tax),
        "total": fmt_money(total),
        "payment_summary": f"{method} {last4}".strip(),
        "source_file": str(path),
    }
    payments: list[dict] = []
    if abs(total) > 0.005:
        payments.append({
            "order_id": order_no,
            "payment_date": order_date,
            "payment_method": method,
            "last4": last4,
            "amount": fmt_money(abs(total)),
            "source_file": str(path),
            "notes": "Tractor Supply order detail payment summary",
        })
    calc = round(subtotal + delivery + tax - discount, 2)
    if abs(calc - total) > 0.02:
        diags.append({"source_file": str(path), "level": "warn", "message": f"summary_total_mismatch calc={calc:.2f} total={total:.2f}"})
    return order, items, payments, diags


def iter_html_files(path: Path) -> Iterable[Path]:
    if path.is_file():
        if path.suffix.lower() == ".zip":
            tmp = path.with_suffix("")
            tmp.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(path) as zf:
                zf.extractall(tmp)
            path = tmp
        else:
            yield path
            return
    for pat in ("*.html", "*.htm"):
        yield from path.rglob(pat)


def write_csv(path: Path, fieldnames: list[str], rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow(r)


def zip_folder(folder: Path, zip_path: Path) -> None:
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    if zip_path.exists():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for p in sorted(folder.rglob("*")):
            if p.is_file():
                zf.write(p, p.relative_to(folder))


def parse_saved_to_folder(inp: Path, out: Path, sku_db_paths: list[Path] | None = None, suggestion_path: Path | None = None) -> tuple[int, int, int, int, int]:
    """Parse saved HTML pages and write normalized TSC CSVs.

    Returns (orders, items, payments, diagnostics, html_files).  The html_files
    count is intentionally included so the caller can detect the confusing case
    where raw capture succeeded but the normalized CSVs would otherwise be
    header-only.
    """
    orders: dict[str, dict] = {}
    items: list[dict] = []
    payments: list[dict] = []
    diags: list[dict] = []
    html_files = sorted(iter_html_files(inp))
    if not html_files:
        diags.append({"source_file": str(inp), "level": "error", "message": "No .html/.htm files found under input path; normalized output will be empty."})
    product_lookup, db_product_count, pdp_product_count = build_product_lookup(html_files, sku_db_paths)
    if db_product_count or pdp_product_count:
        diags.append({"source_file": str(inp), "level": "info", "message": f"Loaded {db_product_count} SKU helper row(s) and learned {pdp_product_count} SKU/title row(s) from saved product pages."})
    for html in html_files:
        order, order_items, order_payments, order_diags = parse_order_detail(html, product_lookup)
        diags.extend(order_diags)
        if not order:
            continue
        orders[order["order_id"]] = order
        items.extend(order_items)
        payments.extend(order_payments)
    if html_files and not orders:
        diags.append({"source_file": str(inp), "level": "error", "message": f"Parsed 0 orders from {len(html_files)} HTML file(s). Check that the installed tractorsupply_scraper/tsc_extract.py is current and that saved pages contain the rendered Order Details text."})
    write_csv(out / "tsc_orders.csv", ["order_id", "order_date", "order_url", "recipient", "subtotal", "shipping", "discount", "tax", "total", "payment_summary", "source_file"], list(orders.values()))
    write_csv(out / "tsc_order_items.csv", ["order_id", "order_date", "line_index", "sku", "description", "quantity", "unit_price", "line_total", "status", "item_url", "notes"], items)
    write_csv(out / "tsc_order_payments.csv", ["order_id", "payment_date", "payment_method", "last4", "amount", "source_file", "notes"], payments)
    if suggestion_path is None:
        suggestion_path = out / "tsc_sku_descriptions_suggested.csv"
    write_sku_description_suggestions(suggestion_path, product_lookup)
    write_csv(out / "tsc_parse_diagnostics.csv", ["source_file", "level", "message"], diags)
    return len(orders), len(items), len(payments), len(diags), len(html_files)


def _print_empty_parse_guidance(counts: tuple[int, int, int, int, int], inp: Path) -> None:
    orders, items, payments, diags, html_count = counts
    if html_count == 0:
        print(f"WARNING: No .html/.htm files were found under: {inp}")
        print("Check the input path, or use Save Page As -> Webpage, Complete into the expected saved_pages/import folder.")
    elif orders == 0:
        print(f"ERROR: Found {html_count} HTML file(s), but parsed 0 orders.")
        print("The raw capture is not necessarily lost. Try reparsing the raw_html folder with the current v199 script, and inspect tsc_parse_diagnostics.csv.")
    elif items == 0:
        print(f"WARNING: Parsed {orders} order(s) but 0 item line(s). Review tsc_parse_diagnostics.csv and the saved HTML pages before import.")

def cmd_parse_saved(args: argparse.Namespace) -> int:
    inp = Path(args.input).expanduser()
    out = Path(args.out).expanduser()
    print(f"Tractor Supply parser {SCRIPT_VERSION}: parsing {inp}")
    html_probe = sorted(iter_html_files(inp))
    if not html_probe:
        print(f"ERROR: No .html/.htm files were found under: {inp}")
        print("No normalized CSVs or ZIP were written, so existing capture output was not overwritten.")
        print("Save pages into ./tractorsupply_scraper/import/ with Save Page As -> Webpage, Complete, or reparse ./tractorsupply_scraper/<YEAR>-export/raw_html.")
        return 2
    sku_db_paths = [Path(p).expanduser() for p in getattr(args, "sku_db", []) or []]
    suggestion_path = Path(args.write_sku_suggestions).expanduser() if getattr(args, "write_sku_suggestions", "") else None
    counts = parse_saved_to_folder(inp, out, sku_db_paths, suggestion_path)
    if getattr(args, "zip_out", ""):
        zip_folder(out, Path(args.zip_out).expanduser())
        print(f"Wrote ZIP: {Path(args.zip_out).expanduser()}")
    print(f"Wrote {counts[0]} orders, {counts[1]} items, {counts[2]} payments, {counts[3]} diagnostics from {counts[4]} HTML file(s) to {out}")
    _print_empty_parse_guidance(counts, inp)
    return 2 if counts[4] > 0 and counts[0] == 0 else 0


def tsc_day_range_for_year(year: str) -> str:
    """Return the TSC order-history range value for a selected year.

    The TSC page defaults to 30 days.  Its own JavaScript converts the selected
    year into a day-count while preserving fromOptionChosen as the visible year.
    Reproduce that here so direct dashboard URLs open the requested year instead
    of falling back to the 30-day default.
    """
    y = str(year or "").strip()
    if y in ("30", "60", "90", "730"):
        return y
    try:
        selected = int(y)
    except ValueError:
        return y
    today = dt.date.today()
    if selected == today.year:
        return str((today - dt.date(today.year, 1, 1)).days + 1)
    if selected == today.year - 1:
        return "365"
    if selected == today.year - 2:
        return "730"
    # Older years are uncommon in the current TSC UI, but this keeps the URL
    # useful if TSC exposes more history later.
    if selected < today.year:
        return str(max(1, (today - dt.date(selected, 1, 1)).days + 1))
    return y


def normalize_order_types(value: str) -> list[str]:
    aliases = {
        "ONLINE": "ONLINE",
        "INSTORE": "INSTORE",
        "IN-STORE": "INSTORE",
        "STORE": "INSTORE",
        "STORE_PURCHASES": "INSTORE",
        "STORE-PURCHASES": "INSTORE",
        "ALL": "ONLINE,INSTORE",
        "BOTH": "ONLINE,INSTORE",
    }
    out: list[str] = []
    for raw in re.split(r"[,\s]+", value or "ONLINE,INSTORE"):
        key = raw.strip().upper()
        if not key:
            continue
        mapped = aliases.get(key, key)
        for part in mapped.split(','):
            part = part.strip().upper()
            if part and part not in out:
                out.append(part)
    return out or ["ONLINE", "INSTORE"]


def default_orders_url(year: str, order_type: str = "ONLINE") -> str:
    order_type = (order_type or "ONLINE").upper()
    range_days = tsc_day_range_for_year(year)
    from_value = str(year or range_days).strip()
    if order_type == "INSTORE":
        return (
            "https://www.tractorsupply.com/AccountDashboardView?"
            "companyCode=TSC&orderType=INSTORE"
            f"&fromOptionChosen={quote(from_value)}&catalogId=10051&dayselect={quote(range_days)}"
            "&taxExemptIndicator=Y&langId=&storeId=10151&topNav=purchaseOrder&currentIndex=1#tscOrders"
        )
    return (
        "https://www.tractorsupply.com/AccountDashboardView?"
        "companyCode=TSC&orderType=ONLINE"
        f"&fromOptionChosen={quote(from_value)}&startPage=1&catalogId=10051&orderRange={quote(range_days)}"
        "&lastExternalOrderIds=&langId=&storeId=10151&topNav=purchaseOrder#tscOrders"
    )


def _endpoint_base(endpoint: str) -> str:
    return (endpoint or "http://127.0.0.1:9222").rstrip("/")


def _read_json(url: str):
    with urlopen(url, timeout=15) as resp:
        return json.loads(resp.read().decode("utf-8", errors="replace"))


def _cdp_targets(endpoint: str) -> list[dict]:
    try:
        data = _read_json(_endpoint_base(endpoint) + "/json/list")
        return data if isinstance(data, list) else []
    except Exception as exc:
        raise SystemExit(f"Unable to query Chrome DevTools endpoint {endpoint}: {exc}") from exc


def _new_cdp_target(endpoint: str, url: str) -> dict:
    base = _endpoint_base(endpoint)
    # Chrome accepts /json/new?<url>. Some builds require PUT; urllib's simple GET works
    # for Chromium/Snap in the workflows we use here.
    return _read_json(base + "/json/new?" + quote(url, safe=":/?&=#%"))


def _select_page_target(endpoint: str, preferred_url_substring: str = "tractorsupply") -> dict:
    targets = [t for t in _cdp_targets(endpoint) if t.get("type") == "page" and t.get("webSocketDebuggerUrl")]
    if not targets:
        return _new_cdp_target(endpoint, "about:blank")
    for t in targets:
        if preferred_url_substring.lower() in (t.get("url") or "").lower():
            return t
    return targets[0]


class RawCdpPage:
    """Tiny Chrome DevTools Protocol client for user-launched Chrome/Chromium.

    This intentionally avoids Playwright-managed browser installs. It attaches to
    an already-open browser started with --remote-debugging-port=9222, which is
    the same Ubuntu-friendly pattern used for the Lowe's workflow.
    """

    def __init__(self, websocket_url: str, timeout: int = 90):
        try:
            import websocket  # provided by websocket-client
        except ImportError as exc:  # pragma: no cover
            raise SystemExit("Missing dependency: websocket-client. Run: pip install -r tractorsupply_scraper/requirements.txt") from exc
        try:
            self.ws = websocket.create_connection(websocket_url, timeout=timeout, origin='http://127.0.0.1:9222')
        except Exception as exc:
            msg = str(exc)
            if '403' in msg and 'remote-allow-origins' in msg:
                raise SystemExit("Chrome/Chromium rejected the DevTools WebSocket connection. Restart Chromium with: --remote-allow-origins=http://127.0.0.1:9222 (or --remote-allow-origins=* for a local-only troubleshooting session).") from exc
            raise
        self.next_id = 0
        self.call("Page.enable")
        self.call("Runtime.enable")

    def close(self) -> None:
        try:
            self.ws.close()
        except Exception:
            pass

    def call(self, method: str, params: dict | None = None, timeout: float = 90.0) -> dict:
        self.next_id += 1
        msg_id = self.next_id
        self.ws.send(json.dumps({"id": msg_id, "method": method, "params": params or {}}))
        deadline = time.time() + timeout
        while time.time() < deadline:
            raw = self.ws.recv()
            if not raw:
                continue
            msg = json.loads(raw)
            if msg.get("id") != msg_id:
                continue
            if "error" in msg:
                raise RuntimeError(f"CDP {method} failed: {msg['error']}")
            return msg.get("result") or {}
        raise TimeoutError(f"Timed out waiting for CDP response to {method}")

    def evaluate(self, expression: str, timeout: float = 30.0):
        result = self.call("Runtime.evaluate", {
            "expression": expression,
            "returnByValue": True,
            "awaitPromise": True,
        }, timeout=timeout)
        value = result.get("result", {})
        if "value" in value:
            return value.get("value")
        return value.get("description", "")

    def navigate(self, url: str, settle_ms: int = 2500) -> None:
        self.call("Page.navigate", {"url": url}, timeout=90)
        self.wait_for_ready(max(5000, settle_ms * 2))
        self.sleep_ms(settle_ms)

    def wait_for_ready(self, timeout_ms: int = 30000) -> None:
        deadline = time.time() + timeout_ms / 1000.0
        while time.time() < deadline:
            try:
                state = self.evaluate("document.readyState", timeout=5)
                if state in ("interactive", "complete"):
                    return
            except Exception:
                pass
            time.sleep(0.5)

    def wait_for_detail_links(self, timeout_ms: int) -> bool:
        js = """(() => Array.from(document.querySelectorAll('a[href]')).some(a => {
            const href = a.getAttribute('href') || '';
            const txt = (a.innerText || a.textContent || '').toLowerCase();
            return href.includes('externalOrderId=') || href.includes('subNav=orderDetail') || txt.includes('view order details');
        }))()"""
        deadline = time.time() + timeout_ms / 1000.0
        while time.time() < deadline:
            try:
                if self.evaluate(js, timeout=5):
                    return True
            except Exception:
                pass
            time.sleep(1)
        return False

    def html(self) -> str:
        return str(self.evaluate("document.documentElement.outerHTML", timeout=30) or "")

    def sleep_ms(self, ms: int) -> None:
        time.sleep(max(0, ms) / 1000.0)

    def scroll_down(self) -> None:
        self.evaluate("window.scrollTo(0, document.body.scrollHeight); true", timeout=10)

    def click_next_or_more(self) -> bool:
        js = r"""(() => {
            const labels = ['next', 'load more', 'show more', 'view more'];
            const els = Array.from(document.querySelectorAll('a,button'));
            for (const el of els) {
                const text = ((el.innerText || el.textContent || el.getAttribute('aria-label') || '') + '').trim().toLowerCase();
                const disabled = el.disabled || el.getAttribute('aria-disabled') === 'true' || el.classList.contains('disabled');
                const visible = !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                if (!disabled && visible && labels.some(label => text === label || text.includes(label))) {
                    el.click();
                    return true;
                }
            }
            return false;
        })()"""
        try:
            return bool(self.evaluate(js, timeout=10))
        except Exception:
            return False


def apply_order_filters(page: RawCdpPage, year: str, order_type: str, settle_ms: int) -> str:
    """Ask the rendered dashboard to switch to the requested year/type.

    Direct URLs carry the right query parameters, but TSC sometimes hydrates the
    purchase-history component with the default 30-day filter anyway.  This
    mirrors a user selecting the year and order type from the visible controls.
    """
    order_type = (order_type or "ONLINE").upper()
    year_js = json.dumps(str(year))
    type_js = json.dumps(order_type)
    js = f"""(() => {{
        const wantedYear = {year_js};
        const wantedType = {type_js};
        const day = document.querySelector('#selectDayHistory');
        const typ = document.querySelector('#myselect');
        if (typ) typ.value = wantedType;
        if (day) day.value = wantedYear;
        if (window.TSCProfile && typeof window.TSCProfile.changeDayForOrderHistory === 'function' && day) {{
            try {{
                window.TSCProfile.changeDayForOrderHistory('selectDayHistory', wantedType);
                return 'invoked changeDayForOrderHistory year=' + wantedYear + ' orderType=' + wantedType;
            }} catch (e) {{
                return 'set controls but changeDayForOrderHistory failed: ' + e;
            }}
        }}
        return 'set controls only; TSCProfile.changeDayForOrderHistory unavailable';
    }})()"""
    try:
        result = str(page.evaluate(js, timeout=10) or "")
        page.sleep_ms(max(1000, settle_ms))
        return result
    except Exception as exc:
        return f"filter apply skipped: {exc}"


def collect_detail_links_from_page(page: RawCdpPage, rawdir: Path, year: str, order_type: str, args: argparse.Namespace) -> list[str]:
    seen: dict[str, bool] = {}
    summary_count = 0
    max_summary_pages = int(getattr(args, "max_summary_pages", 0) or 0)
    limit = max_summary_pages if max_summary_pages > 0 else 25
    for _page_no in range(1, limit + 1):
        page.sleep_ms(int(args.settle_ms))
        # Lazy-loaded dashboards often need scroll passes before links appear.
        for _ in range(4):
            page.scroll_down()
            page.sleep_ms(500)
        html = page.html()
        summary_count += 1
        (rawdir / f"tsc_summary_{year}_{order_type.lower()}_{summary_count:03d}.html").write_text(html, encoding="utf-8")
        for link in detail_links_from_html(html):
            seen[link] = True
        clicked = page.click_next_or_more()
        if not clicked:
            break
        page.sleep_ms(int(args.settle_ms))
    return list(seen.keys())


def cmd_capture_cdp(args: argparse.Namespace) -> int:
    outroot = Path(args.out).expanduser().resolve()
    rawdir = outroot / "raw_html"
    normdir = outroot / "normalized"
    if not getattr(args, "append", False):
        # Avoid mixing prior raw_html pages into a fresh capture.  This was a
        # common source of confusing results after switching filters/order types.
        for folder in (rawdir, normdir):
            if folder.exists():
                shutil.rmtree(folder)
    rawdir.mkdir(parents=True, exist_ok=True)
    years = [y.strip() for y in args.years.split(",") if y.strip()]
    if not years:
        raise SystemExit("No years specified.")
    total_links = 0
    total_details = 0
    product_links: dict[str, bool] = {}

    print(f"Tractor Supply parser {SCRIPT_VERSION}")
    print(f"Connecting to already-open browser at {args.cdp_endpoint} using raw Chrome DevTools Protocol")
    print("This mode does not launch or install a Playwright browser. Log in manually in the open browser if prompted.")
    target = _select_page_target(args.cdp_endpoint, "tractorsupply")
    ws_url = target.get("webSocketDebuggerUrl")
    if not ws_url:
        raise SystemExit("Selected Chrome target does not expose a webSocketDebuggerUrl.")
    page = RawCdpPage(ws_url)
    try:
        for year in years:
            for order_type in normalize_order_types(args.order_types):
                url = args.orders_url.replace("{year}", year).replace("{order_type}", order_type) if args.orders_url else default_orders_url(year, order_type)
                print(f"Opening Tractor Supply order dashboard for {year} {order_type}: {url}")
                print(f"Using TSC history filter fromOptionChosen={year}, range_days={tsc_day_range_for_year(year)}, orderType={order_type}")
                page.navigate(url, settle_ms=int(args.settle_ms))
                if not getattr(args, "no_ui_filter", False):
                    filter_result = apply_order_filters(page, year, order_type, int(args.settle_ms))
                    print(f"Filter apply result: {filter_result}")
                if not page.wait_for_detail_links(int(args.login_wait_ms)):
                    print("No order-detail links found yet. Complete login/verification/filter selection in the open browser, then waiting 30s more...")
                    page.sleep_ms(30000)
                links = collect_detail_links_from_page(page, rawdir, year, order_type, args)
                if int(args.max_details or 0) > 0:
                    links = links[: int(args.max_details)]
                total_links += len(links)
                print(f"Found {len(links)} Tractor Supply detail links for {year} {order_type}.")
                for i, link in enumerate(links, start=1):
                    ext = external_order_id_from_url(link) or f"{year}_{order_type}_{i:04d}"
                    name = safe_filename(ext, f"{year}_{order_type}_{i:04d}")
                    print(f"[{year} {order_type} {i}/{len(links)}] {link}")
                    page.navigate(link, settle_ms=int(args.settle_ms))
                    html = page.html()
                    (rawdir / f"tsc_order_detail_{order_type.lower()}_{name}.html").write_text(html, encoding="utf-8")
                    for product_url in extract_item_links_from_order_detail_html(html):
                        product_links[product_url] = True
                    total_details += 1
    finally:
        page.close()

    if not getattr(args, "no_product_pages", False) and product_links:
        product_dir = rawdir / "product_pages"
        product_dir.mkdir(parents=True, exist_ok=True)
        max_product_pages = int(getattr(args, "max_product_pages", 0) or 0)
        urls = list(product_links.keys())
        if max_product_pages > 0:
            urls = urls[:max_product_pages]
        print(f"Capturing {len(urls)} linked Tractor Supply product page(s) for SKU/title resolution.")
        for i, product_url in enumerate(urls, start=1):
            slug = safe_filename(urlparse(product_url).path.rstrip('/').rsplit('/', 1)[-1], f"product_{i:04d}")
            print(f"[product {i}/{len(urls)}] {product_url}")
            try:
                page.navigate(product_url, settle_ms=int(args.settle_ms))
                product_html = page.html()
                (product_dir / f"tsc_product_{slug}.html").write_text(product_html, encoding="utf-8")
            except Exception as exc:
                print(f"WARNING: product page capture failed for {product_url}: {exc}")
    sku_db_paths = [Path(p).expanduser() for p in getattr(args, "sku_db", []) or []]
    suggestion_path = Path(args.write_sku_suggestions).expanduser() if getattr(args, "write_sku_suggestions", "") else None
    counts = parse_saved_to_folder(rawdir, normdir, sku_db_paths, suggestion_path)
    if getattr(args, "zip_out", ""):
        zip_folder(normdir, Path(args.zip_out).expanduser())
        print(f"Wrote ZIP: {Path(args.zip_out).expanduser()}")
    print(f"Captured {total_details}/{total_links} detail pages. Normalized {counts[0]} orders, {counts[1]} items, {counts[2]} payments from {counts[4]} HTML file(s) to {normdir}")
    _print_empty_parse_guidance(counts, rawdir)
    return 2 if counts[4] > 0 and counts[0] == 0 else 0


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Tractor Supply scraper/parser")
    sub = ap.add_subparsers(dest="cmd", required=True)
    ps = sub.add_parser("parse-saved", help="Parse saved Tractor Supply HTML pages into normalized CSVs")
    ps.add_argument("--input", required=True, help="Saved HTML file, ZIP, or folder")
    ps.add_argument("--out", required=True, help="Output normalized folder")
    ps.add_argument("--zip-out", default="", help="Optional ZIP path to build from the normalized folder")
    ps.add_argument("--sku-db", action="append", default=[], help="Optional fallback CSV with columns sku,description,item_url. Can be repeated.")
    ps.add_argument("--write-sku-suggestions", default="", help="Optional path for learned SKU/title suggestions CSV. Default: <out>/tsc_sku_descriptions_suggested.csv")
    ps.set_defaults(func=cmd_parse_saved)

    pc = sub.add_parser("capture-cdp", help="Auto-walk Tractor Supply order dashboard from an already-open logged-in browser")
    pc.add_argument("--years", required=True, help="Comma-separated years, e.g. 2024,2025,2026")
    pc.add_argument("--out", required=True, help="Output root folder; raw_html and normalized are created below it")
    pc.add_argument("--zip-out", default="", help="Optional ZIP path to build from the normalized folder")
    pc.add_argument("--cdp-endpoint", default="http://127.0.0.1:9222", help="Chrome DevTools endpoint")
    pc.add_argument("--orders-url", default="", help="Optional dashboard URL template; use {year} and {order_type} placeholders")
    pc.add_argument("--order-types", default="ONLINE,INSTORE", help="Comma-separated TSC order types to scan. Default: ONLINE,INSTORE")
    pc.add_argument("--no-ui-filter", action="store_true", help="Do not invoke TSC's visible year/order-type filter after navigation")
    pc.add_argument("--login-wait-ms", type=int, default=120000, help="How long to wait for login/detail links")
    pc.add_argument("--settle-ms", type=int, default=2500, help="Wait after navigation/clicks before saving")
    pc.add_argument("--max-details", type=int, default=0, help="Optional detail-page cap per year; 0 means all found")
    pc.add_argument("--max-summary-pages", type=int, default=0, help="Optional page/load-more cap; 0 means conservative default")
    pc.add_argument("--append", action="store_true", help="Do not clear existing raw_html/normalized output before capture")
    pc.add_argument("--no-product-pages", action="store_true", help="Do not visit linked product pages after order-detail capture for SKU/title resolution")
    pc.add_argument("--max-product-pages", type=int, default=0, help="Optional cap for linked product-page capture; 0 means all unique products found")
    pc.add_argument("--sku-db", action="append", default=[], help="Optional fallback CSV with columns sku,description,item_url. Can be repeated.")
    pc.add_argument("--write-sku-suggestions", default="", help="Optional path for learned SKU/title suggestions CSV. Default: <out>/normalized/tsc_sku_descriptions_suggested.csv")
    pc.set_defaults(func=cmd_capture_cdp)

    args = ap.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
