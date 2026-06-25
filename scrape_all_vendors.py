#!/usr/bin/env python3
"""Refresh all supported vendor scrapers into normalized ZIPs.

Run this from the tool root after starting Chrome/Chromium with
--remote-debugging-port=9222 plus --remote-allow-origins=http://127.0.0.1:9222 and logging in to the vendor sites in that browser.
The wrapper is intentionally simple: it calls each vendor's scraper for each
year and builds the normalized ZIP expected by the web importer.
"""
from __future__ import annotations

import argparse
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def run(cmd: list[str]) -> int:
    print("\n$ " + " ".join(cmd), flush=True)
    return subprocess.call(cmd, cwd=str(ROOT))


def zip_folder(folder: Path, zip_path: Path) -> None:
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    if zip_path.exists():
        zip_path.unlink()
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for p in sorted(folder.rglob("*")):
            if p.is_file():
                zf.write(p, p.relative_to(folder))


def refresh_lowes(year: str, endpoint: str, max_details: int) -> int:
    out = ROOT / "lowes_scraper" / f"{year}-export"
    norm = out / "normalized"
    zpath = ROOT / "lowes_scraper" / f"{year}-normalized.zip"
    cmd = [sys.executable, "lowes_scraper/lowes_retail_scraper/lowes_extract.py", "capture-cdp", "--years", year, "--out", str(out), "--cdp-endpoint", endpoint]
    if max_details > 0:
        cmd += ["--max-details", str(max_details)]
    code = run(cmd)
    if code == 0 and norm.is_dir():
        zip_folder(norm, zpath)
        print(f"Lowe's {year}: ZIP={zpath} direct_folder={norm}")
    return code


def refresh_tsc(year: str, endpoint: str, max_details: int) -> int:
    out = ROOT / "tractorsupply_scraper" / f"{year}-export"
    zpath = ROOT / "tractorsupply_scraper" / f"{year}-normalized.zip"
    cmd = [sys.executable, "tractorsupply_scraper/tsc_extract.py", "capture-cdp", "--years", year, "--out", str(out), "--zip-out", str(zpath), "--cdp-endpoint", endpoint]
    if max_details > 0:
        cmd += ["--max-details", str(max_details)]
    code = run(cmd)
    if code == 0:
        print(f"Tractor Supply {year}: ZIP={zpath} direct_folder={out / 'normalized'}")
    return code


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--vendors", default="lowes,tractor_supply", help="Comma-separated vendors: lowes,tractor_supply")
    ap.add_argument("--years", required=True, help="Comma-separated years, e.g. 2024,2025,2026")
    ap.add_argument("--cdp-endpoint", default="http://127.0.0.1:9222")
    ap.add_argument("--max-details", type=int, default=0, help="Optional detail cap per vendor/year")
    ap.add_argument("--keep-going", action="store_true", help="Continue after a vendor/year failure")
    args = ap.parse_args(argv)
    vendors = [v.strip().lower() for v in args.vendors.split(",") if v.strip()]
    years = [y.strip() for y in args.years.split(",") if y.strip()]
    failures = 0
    for year in years:
        for vendor in vendors:
            if vendor in ("lowes", "lowe", "lowes_retail"):
                code = refresh_lowes(year, args.cdp_endpoint, args.max_details)
            elif vendor in ("tractor_supply", "tractorsupply", "tsc"):
                code = refresh_tsc(year, args.cdp_endpoint, args.max_details)
            else:
                print(f"Unknown vendor: {vendor}")
                code = 2
            if code != 0:
                failures += 1
                print(f"FAILED: {vendor} {year} exit_code={code}")
                if not args.keep_going:
                    return code
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
