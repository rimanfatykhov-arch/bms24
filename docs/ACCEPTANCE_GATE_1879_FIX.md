# Build 1879 acceptance version correction

Review patch based on PR #8 head eccc1eb221df90675067b1011e986702d96a4985.
The candidate declares 2026101879 in version.php, but its acceptance service
expected 2026101873. A correctly upgraded database therefore failed with
plugin_version_mismatch. Align the expected version to 1879 and keep exact
equality: older, missing and newer installed versions must still fail.

The standalone regression reads the actual version.php and exercises the gate
with a fake read-only DB in strict and non-strict modes. It fails on the original
1873 constant. CI runs it together with the existing runtime classifier tests.
The Moodle PHPUnit suite also checks candidate/version alignment and mismatches.
Standalone tests do not certify Moodle DB, HTTP ACL or RC30 acceptance.

Scope authorized by the product owner's request on 2026-11-08: prepare the
1879 acceptance-version fix and tests on a separate branch. This narrowly
authorized gate change includes regression tests and their CI invocation;
it is not a routine dev_ai_cycle feature task or a release approval.

No version.php, upgrade.php, schema, ACL, runtime target or business-data changes.
The release remains RC29 and rc30_promotion_approved remains false. This is a
source review patch, not a newly numbered deployment release. Before deployment,
allocate a unique release identity and rerun the candidate acceptance process.
PR #8 reconciliation and PHP/MySQL/MariaDB profile decisions remain separate.
