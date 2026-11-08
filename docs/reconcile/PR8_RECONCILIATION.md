# PR 8 source reconciliation for review

Status: **DRAFT / HOLD for merge and deployment**. This is a source integration
candidate, not a new production release or RC30 acceptance. Authorized scope:
prepare safe reconciliation with main in a separate branch, audit deletions and
dependencies; no production/staging changes and no PR merges.

## Fixed source inputs

| Source | Commit | Purpose |
|---|---|---|
| main | 740fce16db15bb3551071cc11fe584c680fd738d | Preserved repository baseline, plugin build1645 |
| PR 8 | eccc1eb221df90675067b1011e986702d96a4985 | Imported plugin build1879 and development tooling |
| PR 40 | e2374237edd1f726f861843bd4aa8a4f3320b450 | Acceptance version fix, regression tests and CI invocation |

The branch starts from main. It overlays source files from PR 8 plus PR 40;
it does not merge either PR or rewrite their branches. All main paths remain.
Historical documents are retained at their original locations, even when the
import also carries copies under local/kbacompany/release. Removing duplicates
can be a later documentation-only change after links are migrated.

## Deletion decisions

The complete machine-readable register is `pr8-deletion-inventory.json`:
one row for every missing main path, its original blob, category, exact-content
destinations in the import (when any), and the preservation decision.
Counts use Git paths without rename detection, not GitHub's rename-aware diff.
All 410 removed plugin documents have an exact-content destination in the import;
the register records those matches. Original locations remain to avoid broken links.

| Category | Deleted by original import | Reconcile decision |
|---|---:|---|
| Sibling plugins and local assets | 1628 | Preserve source bytes and paths |
| Historical kbacompany Markdown/TXT documents | 410 | Preserve original paths and import release documents |
| Repository governance and tools | 40 | Preserve paths; two reviewed content updates below |
| Total | 2078 | Zero deleted main paths |

PR 8 also adds 986 paths and changes 75 existing paths. No plugin PHP file is
in the 410-path deletion group. The two deliberate updates to originally deleted
paths are the master-context annotation and the fail-closed release workflow.
This classification checks completeness/preservation; it is not an independent
functional review of every imported business-logic change.

## Dependencies and distribution

`plugin-class-dependencies.json` records qualified external PHP class references
with file/line evidence and checks that referenced source files remain present.
This is static source inventory, not Moodle autoload, API-signature or live DB proof.

| Dependency | Evidence and treatment |
|---|---|
| local_kbacorporate | Legacy company/member tables through legacy_company_adapter::is_ready/require_ready; learning bridge and access integration; repository used directly by PHPUnit fixtures. Keep the plugin. Tests using Corporate require it installed in the test Moodle. |
| local_bitrixsync | bitrix_adapter_service and integration_service check bridge/consumer classes. Preserve provider source; availability guards do not prove REST compatibility or successful synchronization. |
| local_kbaai | bms_ai_bridge_service checks provider class, contract and supported domains. Preserve provider; credentials and external calls are not exercised. |
| local_kbaadmin / local_kbagamification | Existing repository components retained unchanged; removal is outside this reconcile. Lack of direct qualified references is not proof of no indirect dependency. |
| theme_kba | Not supplied by main or import. bms_page_shell_service chooses owned, KBA legacy or standard fallback. Existing theme selection checks remain; legacy/owned + Boost/KBA runtime acceptance is pending. |
| Moodle | Core authentication, DB/file APIs and lifecycle remain external. Plugin requires=2022041900 is a minimum, not certification of every host version. |

The INSTALL artifact built by tools/build_install.py still contains only
`kbacompany/`. It must not be advertised as a complete Moodle/sibling-plugin
installation. Retaining sibling sources in Git does not automatically install
or upgrade them; capture their actual deployed versions before acceptance.
No new mandatory Moodle dependency declaration is invented for optional adapters.

### Confirmed Corporate API gap

The retained Corporate build is 2026092111 and its bridge API_RELEASE is **1.21**.
Class presence therefore does not mean all imported integrations are usable:

| Imported consumer | Minimum bridge API | Missing methods in retained Corporate |
|---|---|---|
| kbacorporate_impl_task_provider | 1.22 | list_implementation_task_projections, get_implementation_task_projection |
| implementation_project_link_service | 1.23 | list_implementation_program_projections, get_implementation_program_projection |
| implementation_project_stages_service | 1.24 | get_implementation_program_sections |
| implementation_project_weekly_service | 1.25 | get_implementation_program_task_sections |
| implementation_project_result_service | 1.26 | get_implementation_program_result_statuses |

These consumers check class/method availability and/or API version before the
calls. The dashboard also checks its projection method. They degrade to an
unavailable integration, not a proven complete implementation. **Functional
acceptance of these imported features is HOLD** until a separately reviewed
compatible Corporate source is supplied and tested, or an explicit reduced
release scope is approved. Do not fabricate a higher API_RELEASE or substitute
empty successful responses. No Corporate code is changed by this reconcile.

## Reconciled governance and CI

- Preserve main AGENTS.md architecture/security rules, README, .gitattributes,
  scripts, source baseline and historical architecture documents. Annotate the
  actual candidate version; BASELINE.json remains a historical pack record.
- Correct the imported Dev AI architecture statement: the plugin owns BMS shell
  and UI Kit; the theme remains an optional host/legacy fallback.
- Combine ignore rules. Anchor `/release/` at repository root: main's unanchored
  rule otherwise silently excludes all 610 imported plugin release documents.
- Use the imported PHP 8.1 candidate workflow with PR 40 regressions, plus main's
  scripts/qa_local.py and the new source-preservation gate. Retain PHP 7.4 lint
  in a separate job, plus main's forbidden-file and whitespace checks. Syntax
  compatibility does not establish runtime or production environment parity.
- Preserve prepare-release.yml as an explicit read-only HOLD. Its old generator
  changes version.php/savepoint without updating the exact acceptance version,
  and the candidate packager/policy also pin 1879. Unifying these contracts is a
  prerequisite to re-enabling automated release preparation. The old Python
  generator remains as historical tooling; do not use it to publish this candidate.
- CI has no deployment commands or production credentials. Artifacts are review
  candidates. New deployable identity must be allocated before installation;
  build1879 alone does not distinguish original import from this reconciled tree.
- Normalize whitespace in 21 imported/annotated files so the retained whitespace
  gate passes; Markdown hard line breaks use backslashes instead of trailing spaces.
- Retained PHP 7.4 lint found PHP 8-only match syntax in bms_workspace_focus_service.
  Replace it with strict if/else comparisons preserving widget selection and fallback.
  A pure regression runs on both PHP 7.4 and 8.1 (modes, input order/duplicates,
  empty input and forbidden-mode fallback). This is the only reconcile-specific
  runtime compatibility adjustment beyond PR 40; no new permissions are granted.

## Schema and security impact inherited from the import

This is not a documentation-only or schema-free change relative to main. The
import contains six additional XMLDB tables (commrev, bp_proto, bp_baudit,
implproj, taskbundle, taskbrun), task/deviation fields, four read indexes and
upgrade checkpoints through 1879. Runtime access-role, task, Battle Plan,
communication and privacy code also changes. The reconcile adds no further
business behavior beyond the imported source and PR 40 fix (the Workspace syntax
adjustment preserves existing filtering), but must inherit
their full migration/RBAC acceptance burden.

Before approval: fresh install and supported upgrade paths, schema equivalence,
two-company non-admin HTTP/file/export checks, role revocation, task/plan lifecycle,
cron idempotency, real DB EXPLAIN and restore rehearsal. Strict acceptance still
targets PHP 8.1/MySQL 5.7; MariaDB profile reconciliation remains a separate task.
No runtime configuration or data was copied from any server.

## Verification and remaining approval gates

The source-preservation gate checks every main path exists, original deleted
paths retain their blobs except the two documented updates, sibling plugin
content remains unchanged, inventoried classes exist, and acceptance matches 1879.
The existing candidate CI validates syntax, language keys, schema names/upgrade
sequence, policy, regression scripts and deterministic packaging.

Full CI results are linked in the PR. Local Windows symlink-test restrictions
are handled by running the unchanged suites on Linux CI, not by disabling them.
Moodle PHPUnit, live DB migrations, browser/ACL and restore tests are not run here.
Independent QA and security agents could not start their new reviews because
the service usage limit was reached; earlier PR 40 reviews do not cover this
large reconcile. Independent review and explicit human approval remain pending.

Merge remains blocked until source/dependency review and required runtime gates
are accepted. Staging changes and production deployment each require their own
explicit authorization. No deployment or automatic merge is part of this branch.

## Proposed Corporate dependency resolution

The original API1.21 blocker below is historical to PR42. See [Corporate API1.26 candidate](CORPORATE_API126.md) for the separately reviewed provider update, precise preservation exception and remaining runtime HOLD.
