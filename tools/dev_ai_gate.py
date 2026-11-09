#!/usr/bin/env python3
"""Read-only, fail-closed BMS Dev AI policy and repository hygiene gate.

Never requires a Moodle bootstrap, an API key, or production data.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
REQUIRED_ROLES = {"analyst", "architect", "developer", "qa", "security", "release"}
SAFE_ACTIONS = {
    "read_source", "draft_spec", "propose_task", "review_design", "edit_worktree",
    "run_local_tests", "write_test_report", "audit_security", "block_release",
    "package_candidate", "write_release_notes",
}
REQUIRED_APPROVALS = {
    "merge_protected_branch", "release_production", "change_agent_policy",
    "modify_permissions", "access_live_customer_data",
}


def load_json(path: Path) -> dict:
    def no_duplicate_pairs(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate JSON key")
            result[key] = value
        return result
    with path.open(encoding="utf-8") as f:
        result = json.load(f, object_pairs_hook=no_duplicate_pairs)
    if not isinstance(result, dict):
        raise ValueError("policy root must be an object")
    return result


def validate_policy(policy: dict) -> list[str]:
    errors = []
    if policy.get("schema_version") != 1:
        errors.append("schema_version must be 1")
    if policy.get("execution_enabled") is not False:
        errors.append("agent execution must be explicitly disabled in foundation release")
    if policy.get("default_policy") != "deny":
        errors.append("default_policy must be deny")
    environments = policy.get("environments")
    if (not isinstance(environments, list) or not environments or
            any(not isinstance(e, str) or e not in {"isolated_worktree", "ephemeral_ci"} for e in environments)):
        errors.append("agent environments must be limited to isolated worktrees and CI")
    approvals = policy.get("human_approval_required")
    if not isinstance(approvals, list) or not REQUIRED_APPROVALS.issubset(set(x for x in approvals if isinstance(x, str))):
        errors.append("human approval list is incomplete")
    agents = policy.get("agents")
    if not isinstance(agents, list) or not agents:
        return errors + ["agents must be a nonempty list"]
    seen = set()
    for agent in agents:
        if not isinstance(agent, dict):
            errors.append("agent must be an object")
            continue
        aid = agent.get("id")
        if not isinstance(aid, str) or not re.fullmatch(r"[a-z][a-z0-9_-]{1,48}", aid):
            errors.append("invalid agent id")
            continue
        if aid in seen:
            errors.append("duplicate agent id: " + aid)
        seen.add(aid)
        if not isinstance(agent.get("name"), str) or not agent["name"].strip():
            errors.append("agent name required: " + aid)
        if not isinstance(agent.get("mission"), str) or not agent["mission"].strip():
            errors.append("agent mission required: " + aid)
        caps = agent.get("capabilities")
        if not isinstance(caps, list) or not caps or any(not isinstance(c, str) for c in caps):
            errors.append("agent capabilities must be a nonempty string list: " + aid)
            continue
        if len(caps) != len(set(caps)):
            errors.append("duplicate capability: " + aid)
        for action in caps:
            if action not in SAFE_ACTIONS:
                errors.append("unauthorized capability in " + aid)
        if aid == "security" and "audit_security" not in caps:
            errors.append("security reviewer must have audit_security")
        if aid == "release" and "package_candidate" not in caps:
            errors.append("release manager must have package_candidate")
    if not REQUIRED_ROLES.issubset(seen):
        errors.append("missing mandatory roles: " + ", ".join(sorted(REQUIRED_ROLES - seen)))
    return errors


def validate_languages(ru: str, en: str) -> list[str]:
    errors = []
    expression = r"\$string\['([^']+)'\]"
    left = re.findall(expression, ru)
    right = re.findall(expression, en)
    if not left or not right:
        errors.append("missing language keys")
    if len(left) != len(set(left)) or len(right) != len(set(right)):
        errors.append("duplicate language key")
    if set(left) != set(right):
        errors.append("RU/EN language-key mismatch")
    return errors


def validate_repo(root: Path) -> list[str]:
    problems = []
    plugin = root / "local" / "kbacompany"
    expected = [
        "AGENTS.md", "docs/BMS_DEV_AI_ARCHITECTURE.md", "docs/RELEASE_GATES.md",
        "docs/AI_TASK_LIFECYCLE.md", "tools/dev_ai_cycle.py", "tools/test_dev_ai_cycle.py",
        ".github/workflows/bms24-ci.yml", "local/kbacompany/version.php",
        "local/kbacompany/db/upgrade.php", "local/kbacompany/db/install.xml",
        "local/kbacompany/lang/ru/local_kbacompany.php",
        "local/kbacompany/lang/en/local_kbacompany.php",
        "local/kbacompany/release/RELEASE_NOTES_RC29_build2026110900.md",
    ]
    for name in expected:
        if not (root / name).is_file():
            problems.append("required source missing: " + name)
    if not plugin.is_dir():
        return problems + ["Moodle plugin source directory missing"]
    versiontext = (plugin / "version.php").read_text(encoding="utf-8")
    match = re.search(r"\$plugin->version\s*=\s*(\d+)\s*;", versiontext)
    if not match or int(match.group(1)) != 2026110900:
        problems.append("plugin version must be 2026110900")
    if "1.1.0-rc29" not in versiontext:
        problems.append("RC30 must not be promoted before live acceptance")
    try:
        ET.parse(plugin / "db" / "install.xml")
    except (ET.ParseError, OSError):
        problems.append("invalid XMLDB install.xml")
    ru = (plugin / "lang" / "ru" / "local_kbacompany.php").read_text(encoding="utf-8")
    en = (plugin / "lang" / "en" / "local_kbacompany.php").read_text(encoding="utf-8")
    problems.extend(validate_languages(ru, en))
    upgrade = (plugin / "db" / "upgrade.php").read_text(encoding="utf-8")
    if upgrade.count("if ($oldversion < 2026110900)") != 1 or upgrade.count("upgrade_plugin_savepoint(true, 2026110900") != 1:
        problems.append("current upgrade checkpoint missing or duplicated")
    # Never allow local runtime state or credentials inside installable code.
    for path in plugin.rglob("*"):
        if path.is_symlink():
            problems.append("symlink in plugin sources: " + str(path.relative_to(plugin)))
        if path.name in {"config.php", ".env", "id_rsa", "id_ed25519"} or path.name.startswith(".env."):
            problems.append("sensitive file name in plugin: " + str(path.relative_to(plugin)))
        if path.is_dir() and path.name in {".git", "moodledata"}:
            problems.append("runtime-only directory in plugin: " + str(path.relative_to(plugin)))
    return problems


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", type=Path, default=ROOT)
    parser.add_argument("--policy", type=Path, default=None)
    parser.add_argument("--policy-only", action="store_true")
    args = parser.parse_args()
    try:
        policy_path = args.policy or args.repo / "dev_ai/agents.json"
        errors = validate_policy(load_json(policy_path))
        if not args.policy_only:
            errors.extend(validate_repo(args.repo))
    except (OSError, ValueError, json.JSONDecodeError) as ex:
        errors = ["invalid/missing policy or source: " + type(ex).__name__]
    print(json.dumps({"status": "HOLD" if errors else "PASS", "checks": "BMS Dev AI policy & repository", "errors": errors}, ensure_ascii=False))
    return 2 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
