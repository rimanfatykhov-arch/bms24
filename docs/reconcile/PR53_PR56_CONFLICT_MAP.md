# PR53 + PR56 conflict map — prepared before code integration

Date:2026-11-09. Exact input53:28a60d010d8c59fd301a2bf1b2df628e38fb79dc;
input56:1ced6b88e5933bb8d63aa1806411ac4d8a6a5443. Common source PR50:5c59057.
Both BMS0902 candidates are distinct. This map and accompanying99-path hash
inventory were prepared before creating the integration branch or changing code.

## All changed paths and dispositions

PR53 changes59 paths; PR56 changes54; overlap14; unique53=45; unique56=40.
`conflict-map.json` enumerates every path and normalized source hashes.

| Conflict/group | Resolution |
|---|---|
| Unique53 learning/queue/Role Intelligence/staffing/design/UI/tests | Select exact53 bytes, preserving its permission checks and fixtures |
| Unique53 reports/manifests | Preserve as historical evidence, never claim they certify combined source |
| Unique56 communication/RC30/Corporate/privacy tests | Preserve exact source; keep all regression coverage |
| install.xml/upgradelib.php | Identical to common50; no DDL change |
| upgrade.php | Ordered union:0301–0804 from53;0890/0900 unchanged;0901 from56;one0902 with both provenances;new0903 |
| CLI schema and2 migration tests | Keep53 expanded0804 cases/failure checkpoint semantics; strictly assert new0903 and replay installed version |
| version/EXPECTED_VERSION +5 version/tool pins | New BMS2026110903, Corporate2026110901 unchanged |
|0902 release note collision | Keep56 path, preserve53 note separately; new0903 notes identify both parents |
|2 workflows +Moodle driver | Union focused coverage/history; test upgrade FROM BOTH exact0902 sources separately |

No wholesale merge or tree overlay. The branch will start at exact PR56.
No unrelated BMS behavior or unresolved test expectations are changed.

## Migration/version check before allocation

PR53 has475 ordered unique checkpoints, PR56 has463. Additional0301–0804,
0901/0902 blocks only save versions; common0890/0900 repairs are identical.
Union plus0903 should contain477 ordered unique checkpoints. No new DDL is
skipped for either0902 origin because0903 is code-only. Proposed build0903 is
greater than both inputs and is allocated to this integration, not either0902.
The physical index/checkpoint helper0900 remains named0900.

## Corporate dependencies and environment boundary

PR53 retains Corporate2026102610/API1.26; PR56 patches that lineage to0901:
ERP missing mapping, provider-scoped SQL, audit, valid section0, role merge,
complete user names and functional PHPUnit fixtures. Keep the full PR56 provider
and its817-file manifest. Learning uses Moodle native competency/assignment APIs;
do not substitute BMS HR roles for grading permissions.

Recorded stage41 inventory2026-11-08 identifies Corporate2026102613/0.40.13.
No full2613 source was found in available inventory folders; its exact source
diff is NOT VERIFIED. Numeric0901>2613 does not establish feature inclusion.
This is a deployment blocker, not permission to replace the installed plugin.
At initial map preparation no server was accessed; the subsequent narrow
read-only verification is recorded below and does not authorize deployment.

Post-map verification2026-11-09: existing pinned SSH host key was used for
read-only version.php and SHA256 metadata collection, with no Moodle bootstrap,
DB changes or remote files created. Code still identifies2613. Its830-file hash
inventory differs from2610 by16 added,3 absent and11 modified files, including
board/query/paging/acceptance metrics and task projection/discussion. Install.xml
and upgrade.php hashes match2610; this does not establish semantic compatibility.
See `corporate2613-source-comparison.json`. A complete source archive export was
rejected by automatic approval review; it was not performed. The narrower hash
comparison succeeded, but the content/behavior of changed2613 files is NOT
certified. Corporate0901 remains a deployment blocker against that feature tree.

## Security boundary retained, not redesigned

The53 queue is a personal Moodle capability projection, not a company registry.
Explicit target-user Moodle review/read/assignment permissions may span companies;
BMS membership/HR roles alone grant nothing. Keep grant/revoke tests, per-click
guards, self-only learning grades, course/module visibility and separate groups.
BMS canonical company data remains company UUID/RBAC scoped. No new rights,
implicit enrolments or cross-company BMS queries are introduced by the union.
Architecture review found no new policy decision required for this preservation.
Stop for ADR/owner approval if resolving a conflict requires widening those rights
or changing this personal queue into a company-only model. Related-work shortcuts
do not prove exact review-request/submission-attempt binding; browser ACL remains
a separate acceptance item. Do not claim global security PASS from focused suites.

## Baseline comparison and acceptance contract

Use final JUnit53 from outputs/integration-0902/{mariadb-final,percona-final},
and56 from outputs/pr56-acceptance/{mariadb-final,percona-final}.
53 BMS587/6813 has37 errors+29 failures;56 BMS563/5496 has23+20.
39 failing identities are common,27 only53 and4 only56. Combined tests must
retain both sets, preserve both sides' resolved cases and show no new failures.
Corporate53 discovery fatal/per-file diagnostics is NOT comparable to full suite;
56 full150/962 PASS must remain. Run fresh, historical1645/1879/0804/0890/0900/0901,
both0902 heads, exact schemas/data/replay, full BMS/Corporate, focused queue,
learning, memberships/documents, tenant, privacy/communication and RC30 gates.
Existing unresolved failures stay HOLD. No test disabling, assertion weakening,
merge to main, staging or production change is authorized.
