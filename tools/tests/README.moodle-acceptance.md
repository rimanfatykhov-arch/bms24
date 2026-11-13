# PR48 isolated Moodle acceptance

Original candidate: PR48 `e06cc96e2933e9142c1d51d2fcfa06b159737718`, BMS0890.
PR50 updates the candidate to BMS `2026110900`, Corporate `2026102610`, and adds
the ordered-index repair and warning-free XMLDB constructor. It does not
authorize deployment or merge. See ADR-0003 and the PR50 acceptance report.

The workflow runs on disposable GitHub-hosted Ubuntu workers, with no deployment
credentials, read-only repository permissions and isolated service containers.
Production, stage41 and the shared Windows Moodle QA installation are not targets.

Pinned core: Moodle 4.1.22, annotated tag object
`b27f0816159a7555a0b3ad9ff2f8f8ef701a29fa`, resolving to core commit
`df1157bb845eabedd31989d6a1a6e18f638ff890`. PHP 8.1 is supplied by setup-php;
the patch version is recorded by the run. Container manifest digests are pinned
for MariaDB 10.11.19 and Percona Server 8.0.46-37. These versions reproduce the
observed engine families; they do not reproduce host configuration or live data.

The upstream Moodle tag has no composer.lock. `moodle-4.1-composer.lock` is the
existing local Moodle 4.1.22 test dependency lock (PHPUnit 9.5.28), copied without
modification; SHA256 `f351c096fed26efc0f37a483127ad5f2025f3c8772f5353ef07fc27a592b4b31`.
Composer validates it against the pinned upstream composer.json before install.
This is a historical test environment, not an endorsed supported runtime stack.

Scenarios use only the two domain plugins, `local_kbacompany` and
`local_kbacorporate`, with stock Moodle. Optional themes and other repository
plugins are outside this acceptance scope.

1. Fresh core + candidate plugin installation through Moodle CLI.
2. Actual main build1645 installation, then replacement with candidate source and
   full Moodle CLI upgrade.
3. Actual imported build1879 installation and candidate upgrade. The import
   deleted Corporate, so this scenario explicitly reconstructs the dependency
   from main; it is not an assertion about the original production deployment.
4. Actual PR48 build0890 installation followed by candidate upgrade.
5. Database schema validation and fresh/upgrade comparison for all three origins.
6. Moodle PHPUnit, including field and ordered-index repair checkpoints and real
   two-company access tests. Collection-fatals remain FAIL while additional
   per-file runs expose diagnostics for the remaining test files.

The missing-field PHPUnit matrix is a simulated damaged-schema regression;
only the historical CLI scenarios are full upgrade tests. None uses production
data. Success here does not establish upgrade safety for stage41 build0802 or
Corporate .13: those source trees still require independent reconciliation.

Evidence is uploaded from the disposable test root only; config.php, dataroots,
database files and credentials are not artifacts. Failed and unexecuted gates
must remain explicit in the acceptance report.
