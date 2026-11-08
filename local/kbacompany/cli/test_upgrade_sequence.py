#!/usr/bin/env python3
"""Negative tests: migration regression gate must reject bad upgrade scripts."""
from check_upgrade_sequence import validate

BASE = '''function xmldb_local_kbacompany_upgrade($oldversion) {
 if ($oldversion < 2026101777) { upgrade_plugin_savepoint(true, 2026101777, 'local', 'kbacompany'); }
 if ($oldversion < 2026101778) { upgrade_plugin_savepoint(true, 2026101778, 'local', 'kbacompany'); }
 return true;
}'''
assert not validate(BASE, 2026101778)
assert validate(BASE.replace('if ($oldversion < 2026101778)', 'if ($oldversion < 2026101777)'), 2026101778)
assert validate(BASE.replace('return true;', 'return true;\nreturn true;'), 2026101778)
assert validate(BASE.replace('upgrade_plugin_savepoint(true, 2026101778', 'upgrade_plugin_savepoint(true, 2026101776'), 2026101778)
assert validate(BASE.replace('if ($oldversion < 2026101778)', 'if ($oldversion < 2026101776)'), 2026101778)
assert validate(BASE.replace('return true;', '').replace('if ($oldversion < 2026101778)', 'return true;\nif ($oldversion < 2026101778)'), 2026101778)
print('PASS: 6 upgrade sequence regression cases')
