# Moodle staging gates for RC29 → FINAL

These are **pending checks**, not PASS claims.

## Before installing

Record actual Moodle version, PHP version/extensions, DB version/collation, selected theme, plugin version. Take DB/files backup and verify restore on test system. Confirm no production personal data is copied uncontrolled.

## Upgrade

Stage plugin 1639→1640→1641→1642 migration paths (or exact jump supported by upgrade.php), compare install.xml schema and upgrade data preservation; purge Moodle caches for Mustache/CSS. Switch `legacy`↔`owned` without data writes. Confirm full rollback path.

## UI parity

Test KBA theme AND Boost if supported. Task list/filter/detail/create/edit/status/attachments/comments, invalid form retry, tabs, navbar, mobile and keyboard. No duplicate Moodle chrome. Assert CSS not applied to course pages or non-BMS UI.

## Coverage and cron

Company A and B, main employee, substitute, manager, outsider. Position-bound/personal tasks, approved/current/expired/cancelled coverage, inactive position, original assignee preservation. Recurrence generated once company+series+period, including multiple cron runs, changing effective executor, stale assignments. No approval/RBAC leakage.

## Rights and privacy

Direct GET and POST cross-company denial, protected files/private calendar, indicator plan/fact write ACL, owner and substitute distinction, reviewer privilege, no internal identifiers exposed without authorization.

## Exit criteria

Record pass/fail with date, environment, user/company anonymized roles and evidence for EACH case. Block 1.1.0 FINAL while critical fail, unchecked DB+security/cron or unverified backup restore remains.
