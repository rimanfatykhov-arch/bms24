#!/usr/bin/env bash
# Negative control for the CLOUD-1a CI step (COORD-D04). Runs the exact step
# script of .github/workflows/bms24-ci.yml in a disposable detached worktree of a
# commit; the original checkout and the imported REG-002 bytes are never modified.
# Not run by CI. Usage: bash cloud/tools/ci_negative_control.sh [commit] [scratch-dir]
# Expected: NC0 exit 0; NC1-NC5 non-zero (1 = violations, 2 = unreadable input).
set -u
REPO="$(git rev-parse --show-toplevel)"
HEAD_SHA="$(git -C "$REPO" rev-parse "${1:-HEAD}")"
WT="${2:-${TMPDIR:-/tmp}/bms24-cloud1a-nc}"
echo "commit $HEAD_SHA"
STEP='set -euo pipefail
python -B cloud/tools/cloud_contracts.py validate
python -B -m unittest discover -s cloud/tests'

fresh() {
  git -C "$REPO" worktree remove --force "$WT" >/dev/null 2>&1
  rm -rf "$WT"
  git -C "$REPO" worktree add -q --detach "$WT" "$HEAD_SHA"
}
run_step() {
  (cd "$WT" && bash -c "$STEP" >"$WT.log" 2>&1); echo $?
}

fresh; echo "NC0 baseline (unchanged head) step_exit=$(run_step)"

fresh
python -I - "$WT" <<'PY'
import json, sys
p = sys.argv[1] + "/cloud/fixtures/v0.1/negative/N01_cross_tenant_a_to_b.json"
d = json.load(open(p, encoding="utf-8")); d["expect"]["violations"] = ["INV_RAW_SECRET"]
json.dump(d, open(p, "w", encoding="utf-8"), indent=2)
PY
echo "NC1 broken fixture expectation step_exit=$(run_step) :: $(grep -m1 -o 'ERROR FIXTURE_EXPECTATION scope=fixture:N01[^:]*' "$WT.log")"

fresh
printf ' ' >> "$WT/cloud/contracts/registry/v0.1/imported/bms_cloud_registry_mvp_v0.1.yaml"
echo "NC2 one byte appended to imported REG-002 YAML step_exit=$(run_step) :: $(grep -m1 -o 'ERROR PKG_HASH_MISMATCH[^:]*' "$WT.log")"

fresh
python -I - "$WT" <<'PY'
import json, sys
p = sys.argv[1] + "/cloud/contracts/provenance/reg002_package_manifest.json"
d = json.load(open(p, encoding="utf-8")); d["import"]["state"] = "HOLD"
json.dump(d, open(p, "w", encoding="utf-8"), indent=2)
PY
echo "NC3 validate passes but a unit test fails step_exit=$(run_step) :: $(grep -m1 -E 'RESULT' "$WT.log") / $(grep -m1 -E '^FAILED' "$WT.log")"

fresh
rm "$WT/local/kbacompany/classes/local/workspace_mapping_service.php"
echo "NC4 unreadable Moodle source (usage error) step_exit=$(run_step) :: $(grep -m1 -o 'USAGE_ERROR MOODLE_SOURCE_UNREADABLE' "$WT.log")"

fresh
python -I - "$WT" <<'PY'
import json, sys
p = sys.argv[1] + "/cloud/contracts/registry/v0.1/registry_overlay.json"
d = json.load(open(p, encoding="utf-8")); d["vocabularies"]["commercial_provider"].append("stripe")
json.dump(d, open(p, "w", encoding="utf-8"), indent=2)
PY
echo "NC5 contract drift (overlay vocabulary vs schema) step_exit=$(run_step) :: $(grep -m1 -o 'ERROR MODEL_ENUM_DRIFT[^:]*' "$WT.log")"

git -C "$REPO" worktree remove --force "$WT"; rm -f "$WT.log"
echo "original checkout status: $(git -C "$REPO" status --short | wc -l) changed files"
