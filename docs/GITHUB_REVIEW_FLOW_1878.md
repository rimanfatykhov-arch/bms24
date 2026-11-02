# BMS24 build1878: review candidate without deploy

1. Update the offline source to build1878 and produce a full source Git bundle.
2. Reconcile Git histories through `tools/github_import.py` using its read-only default mode.
3. On explicit approval, push **only** `import/build2026101878`. Never force-update `main`.
4. Open a draft PR against `main`. Its GitHub Actions run tests and upload an **unapproved candidate ZIP** for inspection. This automation is implemented in the imported build1878 workflow; the older remote GitHub `main` has a separate bootstrap PR #7 for PHP 8.1 and PR artifacts.
5. Download artifact and verify manifest/ZIP checksum. Perform real Moodle Staging install + PHPUnit/Behat/integration tests.
6. Independent auditor / product owner decides on merge. Production install is manual and separately approved.

The current GitHub `main` is still build2026101645; local build1878 source is not yet there. A GitHub PR on the old main is not equivalent to importing latest source. No production credentials belong in CI. **No automatic production deployment.**
