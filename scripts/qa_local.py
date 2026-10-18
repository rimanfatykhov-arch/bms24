#!/usr/bin/env python3
"""Safe static preflight for BMS24 plugin source; NOT a Moodle runtime test.

Run from repository root: python scripts/qa_local.py
"""
import argparse
import re
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

parser = argparse.ArgumentParser(description="Static BMS24 preflight; never touches DB")
parser.add_argument("--version-only", action="store_true")
parser.add_argument("--plugin", default="local/kbacompany")
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
plugin = (root / args.plugin).resolve()
if not plugin.is_dir():
    print(f"FAIL: {plugin} missing. Unpack SOURCE build1642 under local/kbacompany.", file=sys.stderr)
    sys.exit(2)

version = (plugin / "version.php")
if not version.is_file():
    print("FAIL: no version.php", file=sys.stderr)
    sys.exit(2)
text = version.read_text(encoding="utf-8")
for k, pat in [
    ("version", r"\$plugin->version\s*=\s*(\d+)"),
    ("release", r"\$plugin->release\s*=\s*['\"]([^'\"]+)['\"]"),
    ("requires", r"\$plugin->requires\s*=\s*(\d+)"),
]:
    match = re.search(pat, text)
    print(f"{k}: {match.group(1) if match else 'NOT FOUND'}")
    if not match: sys.exit(1)
if args.version_only: sys.exit(0)

failures = []
php = shutil.which("php")
files = sorted(plugin.rglob("*.php"))
if not php:
    failures.append("PHP executable missing from PATH; PHP lint NOT RUN")
else:
    bad = []
    for f in files:
        run = subprocess.run([php, "-l", str(f)], capture_output=True, text=True)
        if run.returncode: bad.append(f"{f.relative_to(plugin)}: {run.stderr or run.stdout}")
    print(f"PHP lint: {len(files)-len(bad)}/{len(files)}")
    failures.extend(bad[:30])

lang = plugin / "lang"
langs = {}
for code in ("ru", "en"):
    f = lang / code / "local_kbacompany.php"
    if not f.is_file():
        failures.append(f"missing language file: {f.relative_to(plugin)}")
        continue
    content = f.read_text(encoding="utf-8")
    keys = re.findall(r"\$string\[['\"]([^'\"]+)['\"]\]\s*=", content)
    dup = sorted({k for k in keys if keys.count(k) > 1})
    if dup: failures.append(f"{code}: duplicate language keys: {dup[:20]}")
    langs[code] = set(keys)
    print(f"lang {code}: {len(langs[code])} unique keys")
if len(langs) == 2:
    diff = sorted(langs["ru"] ^ langs["en"])
    if diff: failures.append(f"RU/EN mismatch ({len(diff)}): {diff[:30]}")

xmls = list((plugin / "db").glob("*.xml"))
for f in xmls:
    try: ET.parse(f)
    except ET.ParseError as exc: failures.append(f"XML {f.name}: {exc}")
print(f"XML syntactic parse: {len(xmls)} db files")
print("NOTE: no Moodle DB/PHPUnit/cron/browser/capability tests executed")
print("NOTE: local PHP parser syntax PASS does not establish target PHP version compatibility")
if failures:
    print("FAILURES / INCOMPLETE CHECKS:")
    for issue in failures: print("  -", issue)
    sys.exit(1)
print("PASS: static checks only")
