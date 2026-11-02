#!/usr/bin/env python3
"""Source-level safety contract for RC29 cross-week decision bulk operations.

This is a static regression gate, not a substitute for live Moodle integration tests.
"""
from pathlib import Path
import re
import sys
from xml.etree import ElementTree as ET
ROOT = Path(__file__).resolve().parents[1]
registry = (ROOT / 'battle_plan_decision_registry.php').read_text(encoding='utf-8')
service = (ROOT / 'classes/local/battle_plan_protocol_service.php').read_text(encoding='utf-8')
upgrade = (ROOT / 'db/upgrade.php').read_text(encoding='utf-8')
xml = (ROOT / 'db/install.xml').read_text(encoding='utf-8')
checks = {
    'bulk operations restricted to known actions': "['status', 'owner', 'duedate']" in registry,
    'CSRF token mandatory for preview and confirmation': "require_sesskey();" in registry,
    'selection bounded to 20 decisions': 'count($selected) > 20' in registry,
    'authorized owner options validated': 'company_owner_options(' in registry and 'isset($allowedowners[$bulkowner])' in registry,
    'date parsed and roundtrip-validated': "DateTimeImmutable::createFromFormat('!Y-m-d'" in registry and "->format('Y-m-d') !== $bulkduedate" in registry,
    'per-record exception isolation': 'foreach ($selected as $id)' in registry and r'catch (\Throwable $e)' in registry,
    'no internal exception data copied to UI audit': "'reason'=>'validation'" in registry and 'json_encode($itemresults' in registry,
    'only confirmed operations audited': registry.index("if ($bulkphase === 'confirm')") < registry.index("insert_record('local_kbacompany_bp_baudit'"),
    'latest revision check during preview': "'revision DESC'" in registry and 'reset($latest)->id === $id' in registry,
    'status service entry exists': 'function bulk_change_status(' in service,
    'assignment service entry exists': 'function bulk_change_assignment(' in service,
    'corrected audit table in code and schema': 'local_kbacompany_bp_baudit' in registry and 'local_kbacompany_bp_baudit' in upgrade and 'local_kbacompany_bp_baudit' in xml,
    'obsolete overlong audit table absent': 'local_kbacompany_bp_bulkaudit' not in registry+upgrade+xml,
    'savepoint for this build': "upgrade_plugin_savepoint(true, 2026101774, 'local', 'kbacompany')" in upgrade,
}
try:
    tree=ET.fromstring(xml)
    names=[t.get('NAME','') for t in tree.findall('.//TABLE')]
    checks['all XMLDB table names at most 28 chars']=all(len(n)<=28 for n in names)
except ET.ParseError:
    checks['all XMLDB table names at most 28 chars']=False
for label, ok in checks.items():
    print(('PASS' if ok else 'FAIL') + ': ' + label)
if not all(checks.values()):
    sys.exit(2)
print('PASS: %d bulk-operation and migration contracts' % len(checks))
