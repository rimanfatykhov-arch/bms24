# Issue44 isolated XMLDB integration test

Run from the repository in PowerShell (local binaries must already exist):

```powershell
.\tools\tests\run_schema_parity_real_xmldb.ps1 -QaRoot ..\bms24-local-qa -OutputRoot ..\outputs\issue44-schema-repair
```

Expected QA layout: `php/php.exe`, `moodle/lib`, and
`mariadb-10.11.11-winx64/bin/{mariadb-install-db,mariadbd}.exe`.
The runner creates a unique datadir, generated local root password, loopback port,
and database. It starts a hidden process and stops only its returned PID with the
same start time. It leaves scratch files and logs for inspection. No existing
Moodle config, data directory, database, service or server is reused. The PHP
harness verifies server `@@datadir` against the owned path before creating its DB.
Do not invoke the PHP file directly against an existing database.

The test uses the actual candidate repair helper and actual Moodle MariaDB DML,
metadata and XMLDB DDL. The fixtures are **minimal target tables**, not a full
Moodle installation or an extracted production database. Ancillary localization,
MUC and the pure `is_number` predicate have standalone replacements; debugging
notifications are suppressed. Exceptions and SQL failures still fail the run.

Coverage: all eight combinations of the three fields, existing values and rows
from two tenants, metadata and defaults on old and newly inserted rows, repeated
helper invocation, incompatible type/length/nullability/default/no-default, and
either missing table. Rejections must preserve peer schema/data; repeat calls
must preserve schema/data. The test does not instrument or assert DDL query counts.

Verified locally: PHP 8.1.34, Moodle 4.1.22, MariaDB 10.11.11; 320 assertions pass.
This is helper integration evidence only. Full Moodle lifecycle/savepoints,
fresh installation of all plugin tables, MySQL 5.7, MySQL 8, staging/production,
tenant authorization behavior, and RC30 acceptance are **NOT RUN** by this harness.
The standalone CLI regression suite separately exercises upgrade guards and
failure/savepoint behavior. This script is opt-in and is not a CI service job.
