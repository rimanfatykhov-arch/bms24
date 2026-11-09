# Functional0804 + schema0900 integration

Owner request2026-11-09: prepare a separate common candidate, run regression and two-company isolation, no merge or production changes.

Base: PR50 `5c59057cb7af3fd545cd661bb7c2b3daa5ae7cd9`. Functional source: PR51 `d4887103c13f45c42b73b455a95dba142d7ba116`, delta from1878 `a87252b642e5796c6d84105dd448d51d9a5b5241`. The histories do not share a merge-base. This is a selective content integration, not a replacement of the repository tree.

Of44 functional plugin paths,41 are transferred exactly (normalized line endings); the queue test is extended with explicit second-company grant/revoke and grade visibility checks. Only upgrade.php/version.php overlap schema work: preserve1879, insert version-only0301–0804 before0890/0900, keep the schema helpers, then add monotonic0901. Current acceptance/version/CI pins move together. See functional0804-integration.json for per-file evidence. All sibling components remain from PR50; Corporate2610 is not silently replaced with a guessed2613.

## Acceptance scope

Disposable GitHub Linux workers, stock Moodle4.1.22, PHP8.1, pinned MariaDB10.11.19 and Percona8.0.46-37. Fresh install; actual CLI upgrades1645/1879/0890/0804/0900; repeat upgrade;183-table schema equality and synthetic data preservation. The0804 and1879 historical sources omit Corporate; tests explicitly reconstruct that dependency from main1645. This does not establish compatibility with deployed Corporate2613.

Focused schema/index/task isolation, reviewqueue, employee learning/profile, readiness, company and design suites run separately before full BMS/Corporate suites. Existing failures remain failures, not waived. Queue scope is personal Moodle permissions, not the currently selected company: test restricted target-user permissions across companies, direct work URLs, revoked rights and grade visibility. Employee-card requirements/results must remain company-scoped. No browser UI/HTTP or live-stage acceptance is implied by PHP service tests.

## Pending results

CI and full matrix pending. PR50 full acceptance was HOLD (40 errors/30 failures per engine in BMS plus Corporate blockers); compare failing identities on the integrated tree. This candidate is not approved for deployment. Rollback here is discarding/reverting this isolated branch; deployed DB rollback would require a matched backup, not a version decrement.
