#!/usr/bin/env python3
"""BMS24 one-command release builder.

Usage from plugin root or repository:
    python cli/build_release.py --summary "Short release summary" --output-dir PATH

The script is intentionally independent from Moodle bootstrap and uses only Python
standard-library modules plus a local PHP CLI executable.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from xml.etree import ElementTree as ET
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed

ROOT = Path(__file__).resolve().parents[1]


def fail(message: str, *, details=None) -> None:
    payload = {"status": "HOLD", "message": message}
    if details is not None:
        payload["details"] = details
    print(json.dumps(payload, ensure_ascii=False, indent=2), file=sys.stderr)
    raise SystemExit(2)


def parse_version() -> tuple[int, str]:
    text = (ROOT / "version.php").read_text(encoding="utf-8")
    build_m = re.search(r"\$plugin->version\s*=\s*(\d+)\s*;", text)
    release_m = re.search(r"\$plugin->release\s*=\s*['\"]([^'\"]+)['\"]\s*;", text)
    if not build_m or not release_m:
        fail("Cannot parse version.php")
    return int(build_m.group(1)), release_m.group(1)


def find_php(explicit: str | None) -> str:
    candidates = []
    if explicit:
        candidates.append(explicit)
    env_php = os.environ.get("PHP_BIN")
    if env_php:
        candidates.append(env_php)
    candidates.extend(["php", r"C:\php\php.exe"])
    for candidate in candidates:
        path = shutil.which(candidate) if candidate == "php" else candidate
        if path and Path(path).exists():
            return str(path)
        if candidate == "php" and path:
            return path
    fail("PHP CLI not found. Use --php PATH or set PHP_BIN.")
    raise AssertionError


def run(cmd: list[str], *, cwd: Path = ROOT) -> subprocess.CompletedProcess[str]:
    cp = subprocess.run(cmd, cwd=str(cwd), text=True, capture_output=True)
    if cp.returncode != 0:
        fail("Command failed", details={"command": cmd, "stdout": cp.stdout, "stderr": cp.stderr, "exit": cp.returncode})
    return cp


def release_note_for(build: int) -> Path:
    matches = sorted((ROOT / "release").glob(f"RELEASE_NOTES_*{build}*.md"))
    if not matches:
        fail(f"Release note for build {build} is required before packaging")
    return matches[-1]


def validate_upgrade(build: int) -> None:
    text = (ROOT / "db" / "upgrade.php").read_text(encoding="utf-8")
    needle = f"if ($oldversion < {build})"
    if text.count(needle) != 1:
        fail("Current upgrade checkpoint is missing or duplicated", details={"build": build, "count": text.count(needle)})


def validate_xml() -> None:
    ET.parse(ROOT / "db" / "install.xml")


def language_keys(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    return re.findall(r"\$string\['([^']+)'\]", text)


def validate_languages() -> None:
    ru = language_keys(ROOT / "lang" / "ru" / "local_kbacompany.php")
    en = language_keys(ROOT / "lang" / "en" / "local_kbacompany.php")
    if len(ru) != len(set(ru)):
        fail("Duplicate RU language keys")
    if len(en) != len(set(en)):
        fail("Duplicate EN language keys")
    if set(ru) != set(en):
        fail("RU/EN language-key parity failed", details={"ru_only": sorted(set(ru)-set(en))[:30], "en_only": sorted(set(en)-set(ru))[:30]})


def php_lint_all(php: str) -> int:
    files = sorted(ROOT.rglob("*.php"))
    failures = []

    def lint_one(path: Path):
        cp = subprocess.run([php, "-l", str(path)], text=True, capture_output=True)
        return path, cp

    workers = min(8, max(1, (os.cpu_count() or 2)))
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(lint_one, path) for path in files]
        for future in as_completed(futures):
            path, cp = future.result()
            if cp.returncode != 0:
                failures.append({"file": str(path.relative_to(ROOT)), "output": (cp.stdout + cp.stderr).strip()})
    if failures:
        failures.sort(key=lambda item: item["file"])
        fail("PHP lint failed", details=failures[:20])
    return len(files)


def docs_prepare_and_check(php: str, summary: str, live: str) -> None:
    script = ROOT / "cli" / "release_docs_checkpoint.php"
    run([php, str(script), f"--summary={summary}", f"--live={live}"])
    run([php, str(script), "--check"])


def excluded(rel: Path) -> bool:
    parts = set(rel.parts)
    if parts & {".git", "__pycache__", ".idea"}:
        return True
    if rel.name in {"Thumbs.db", ".DS_Store"}:
        return True
    if rel.suffix.lower() in {".zip", ".pyc"}:
        return True
    if ".tmp." in rel.name:
        return True
    return False


def make_zip(out_zip: Path) -> int:
    files = [p for p in ROOT.rglob("*") if p.is_file() and not excluded(p.relative_to(ROOT))]
    with zipfile.ZipFile(out_zip, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=3) as zf:
        for path in files:
            arc = Path("kbacompany") / path.relative_to(ROOT)
            zf.write(path, arc.as_posix())
    # Integrity read-back.
    with zipfile.ZipFile(out_zip, "r") as zf:
        bad = zf.testzip()
        if bad:
            fail("ZIP integrity check failed", details=bad)
        if "kbacompany/version.php" not in zf.namelist():
            fail("ZIP root structure invalid: kbacompany/version.php missing")
    return len(files)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser(description="BMS24 one-command release builder")
    parser.add_argument("--summary", required=True, help="Short release checkpoint summary")
    parser.add_argument("--live", default="NOT RUN", help="Live acceptance status")
    parser.add_argument("--output-dir", default="", help="Artifact output directory. Default: ../release-builds/<release>_build<build>")
    parser.add_argument("--php", default="", help="PHP CLI executable path")
    args = parser.parse_args()

    build, release = parse_version()
    release_note = release_note_for(build)
    php = find_php(args.php or None)

    docs_prepare_and_check(php, args.summary.strip(), args.live.strip() or "NOT RUN")
    validate_upgrade(build)
    run([sys.executable, str(ROOT / "cli" / "check_upgrade_sequence.py")])
    run([sys.executable, str(ROOT / "cli" / "test_upgrade_sequence.py")])
    validate_xml()
    run([sys.executable, str(ROOT / "cli" / "check_xmldb_schema_names.py")])
    run([sys.executable, str(ROOT / "cli" / "test_xmldb_schema_names.py")])
    validate_languages()
    run([sys.executable, str(ROOT / 'cli' / 'test_php81_compat.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_php81_compat.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_decision_bulk_contract.py')])
    # RC29 source regression gate: fail closed before ZIP creation.
    run([sys.executable, str(ROOT / 'cli' / 'check_rc29_regression_gate.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_review_readiness_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_review_readiness_projection.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_approval_queue_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_approval_queue_projection.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_approval_queue_filters.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_approval_review_shortcuts.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_weekly_review_outcomes.py')])
    run([php, str(ROOT / 'cli' / 'test_weekly_review_outcomes.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_review_timeliness_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_review_timeliness_projection.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_review_cycle_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_review_cycle_events.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_review_cycle_analytics_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_review_cycle_analytics.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_review_stage_delays_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_review_stage_delays.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_manager_dashboard_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_manager_dashboard_projection.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_battle_plan_navigation_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_battle_plan_navigation.php')])
    run([php, str(ROOT / 'cli' / 'test_battle_plan_navigation_render.php')])
    run([php, str(ROOT / 'cli' / 'test_approval_queue_filters.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_bms_main_tool_contract.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_main_tool_topbar_cleanup.py')])
    run([php, str(ROOT / 'cli' / 'test_bms_main_tool.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_bms_workspace_widgets_contract.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_workspace_1806_contract.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_bms_button_standard_1807.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_workspace_1808_contract.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_workspace_1809_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_workspace_1809_queue.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_workspace_1810_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_workspace_1810_focus.php')])
    # RC29/1814: independently validated public Corporate implementation task provider.
    run([sys.executable, str(ROOT / 'cli' / 'check_corporate_1814_contract.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_corporate_attach_1815.py')])
    run([php, str(ROOT / 'cli' / 'test_implementation_battle_plan_link.php')])
    run([php, str(ROOT / 'cli' / 'test_kbacorporate_impl_provider.php')])
    # RC30/1811 preflight: fail-closed company isolation and link-origin tests.
    run([sys.executable, str(ROOT / 'cli' / 'check_rc30_1811_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_rc30_company_isolation.php')])
    run([php, str(ROOT / 'cli' / 'test_workspace_1806_policy.php')])
    run([php, str(ROOT / 'cli' / 'test_bms_workspace_widgets.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_management_cycle_acceptance_contract.py')])
    run([php, str(ROOT / 'cli' / 'test_management_cycle_acceptance.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_communication_moodle40_api.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_communication_shell_collapse.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_shared_shell_route_integration.py')])
    run([sys.executable, str(ROOT / 'cli' / 'check_battle_plan_status_counts.py')])
    # RC29/1819: latest evidence result projection and non-ambiguous curator approval.
    run([php, str(ROOT / 'tests' / 'implementation_project_result_service_test.php')])
    # RC29/1820: manager implementation dashboard and Corporate curator assignment bridge.
    run([php, str(ROOT / 'tests' / 'implementation_project_dashboard_service_test.php')])
    run([sys.executable, str(ROOT / 'cli' / 'check_impl_dashboard_1820.py')])
    lint_count = php_lint_all(php)
    # Re-check docs after all preflight steps.
    run([php, str(ROOT / "cli" / "release_docs_checkpoint.php"), "--check"])

    if args.output_dir:
        out_dir = Path(args.output_dir).expanduser().resolve()
    else:
        out_dir = (ROOT.parent / "release-builds" / f"{release}_build{build}").resolve()
    out_dir.mkdir(parents=True, exist_ok=True)

    zip_name = f"INSTALL_local_kbacompany_{release}_build{build}.zip"
    out_zip = out_dir / zip_name
    tmp_zip = Path(tempfile.mkstemp(prefix="bms24_", suffix=".zip", dir=str(out_dir))[1])
    try:
        file_count = make_zip(tmp_zip)
        os.replace(tmp_zip, out_zip)
    finally:
        if tmp_zip.exists():
            tmp_zip.unlink()

    digest = sha256(out_zip)
    (out_dir / "SHA256SUMS.txt").write_text(f"{digest}  {zip_name}\n", encoding="utf-8")
    shutil.copy2(release_note, out_dir / release_note.name)
    result = {
        "status": "READY",
        "component": "local_kbacompany",
        "release": release,
        "build": build,
        "artifact": str(out_zip),
        "sha256": digest,
        "php_lint_files": lint_count,
        "packaged_files": file_count,
        "release_note": release_note.name,
        "live_acceptance": args.live.strip() or "NOT RUN",
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }
    (out_dir / "BUILD_RESULT.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
