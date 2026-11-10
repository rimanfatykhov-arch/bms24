#!/usr/bin/env python3
"""Static acceptance contract; requires no Moodle DB or external services."""
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
cli = (ROOT / 'cli/acceptance_integration_1825.php').read_text()
metrics = (ROOT / 'classes/local/implementation_acceptance_metrics.php').read_text()
version = (ROOT / 'version.php').read_text()
checks = {
    'CLI-only': "define('CLI_SCRIPT', true)" in cli,
    'Moodle bootstrapping': "../../../config.php" in cli,
    'Command arguments': all('--' + s in cli for s in ('companyid', 'actorid', 'othercompanyid', 'otheractorid', 'bmsuuid', 'runs')),
    'Company ACL and role checks': 'manageimplementationaudits' in cli and 'can_manage_company_implementation' in cli,
    'Read-only Corporate API': 'list_implementation_task_projections' in cli,
    'Cross-company projection gate': 'Cross-company task ID isolation' in cli,
    'BMS source binding': 'linked_company_id' in cli,
    'Statuses distinct': all(s in cli for s in ("'PASS'", "'FAIL'", "'SKIP'")),
    'No raw SQL output': 'json_encode($report' in cli and 'get_class($e)' in cli,
    'No writes in script': all(keyword not in cli.lower() for keyword in ('insert_record(', 'update_record(', 'delete_records(', 'set_field(', 'execute(')),
    'Median p95 queries': all(s in metrics for s in ('median_ms', 'p95_ms', 'median_queries', 'p95_queries')),
    'Latest version': '$plugin->version   = 2026102613' in version,
}
for k,v in checks.items(): print(('PASS' if v else 'FAIL') + ' ' + k)
if not all(checks.values()): raise SystemExit(1)
print(f"{len(checks)}/{len(checks)} static checks passed")
