# BMS24 RC29 · Emergency hotfix build2026101772

## Blocker
The Moodle upgrade failed in the 2026101769 checkpoint: `Invalid table name {local_kbacompany_bp_bulkaudit}: name is too long. Limit is 28 chars.`

## Fix
- Renamed XMLDB table **logical** name `local_kbacompany_bp_bulkaudit` (29 chars) to `local_kbacompany_bp_baudit` (26 chars).
- Updated `db/install.xml`, both `db/upgrade.php` checkpoints (1769/1770), and all application SQL reads and writes in `battle_plan_decision_registry.php`.
- Bumped Moodle plugin version to 2026101772 and added checkpoint.
- No data renaming is attempted. The table could not have been created by the failing 1769 checkpoint under XMLDB naming rules.

## Recovery
1. Back up Moodle database and plugin directory.
2. Replace `/local/kbacompany/` with this corrected **complete** package (do not delete plugin via Moodle UI).
3. Run `/admin/index.php` once or `php admin/cli/upgrade.php --non-interactive` using the correct site PHP binary.
4. Moodle resumes from the last successful checkpoint and creates the shorter table name.
5. Clear Moodle caches and check decisions registry.

Do **not** delete arbitrary database tables, modify saved plugin version in the DB, or keep retrying the defective installer ZIP.

## Validation
PHP syntax lint, XML structure checks, references to renamed table, ZIP CRC integrity. Live Moodle upgrade was not performed.
