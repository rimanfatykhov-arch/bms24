#!/usr/bin/env python3
"""Regression tests for the 1769 XMLDB table-name installation blocker."""
from check_xmldb_schema_names import validate

VALID_XML = '<XMLDB><TABLES><TABLE NAME="local_kbacompany_bp_baudit" /></TABLES></XMLDB>'
assert validate(VALID_XML, "new xmldb_table('local_kbacompany_bp_baudit')") == []
BAD = 'local_kbacompany_bp_bulkaudit'
assert len(BAD) == 29
assert any('install.xml' in e for e in validate(VALID_XML.replace('bp_baudit', 'bp_bulkaudit'), ''))
assert any('upgrade.php' in e for e in validate(VALID_XML, "new xmldb_table('" + BAD + "')"))
print('PASS: valid schema and overlong-table regressions')
