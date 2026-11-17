"""ADR-0005: the repository Corporate tree and the production Corporate2613 package must define the same
fresh defaults and the same upgrade step, so fresh and upgraded schemas cannot diverge again (PR #124 P1)."""
import re
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PATCH = (ROOT / 'release/corporate2613/compatibility.patch').read_text(encoding='utf-8')
UPGRADE = (ROOT / 'local/kbacorporate/db/upgrade.php').read_text(encoding='utf-8')


def fresh_default(table, field):
    tree = ET.parse(ROOT / 'local/kbacorporate/db/install.xml')
    node = tree.find(".//TABLE[@NAME='%s']/FIELDS/FIELD[@NAME='%s']" % (table, field))
    return node.get('DEFAULT')


class CorporateAdr0005Convergence(unittest.TestCase):
    def test_fresh_defaults_match_accepted_adr(self):
        self.assertEqual('0', fresh_default('local_kbacorp_member', 'isemployee'))
        self.assertEqual('draft', fresh_default('local_kbacorp_devplan', 'status'))

    def test_upgrade_function_is_identical_to_the_package(self):
        start = PATCH.index('+/** ADR-0005: defaults for future rows only; never update membership/status data. */')
        end = PATCH.index('+}\n', start) + 3
        packaged = ''.join(line[1:] + '\n' for line in PATCH[start:end].splitlines())
        self.assertEqual(1, UPGRADE.count(packaged))

    def test_upgrade_step_order_and_savepoint(self):
        steps = [int(v) for v in re.findall(r"if \(\$oldversion < (\d+)\)", UPGRADE)]
        self.assertEqual(steps, sorted(steps))
        self.assertEqual([2026110901, 2026111004, 2026111100], steps[-3:])
        block = re.search(r"if \(\$oldversion < 2026111004\) \{(.*?)\n    \}", UPGRADE, re.S).group(1)
        self.assertIn('local_kbacorporate_adr0005_defaults();', block)
        self.assertIn("upgrade_plugin_savepoint(true, 2026111004, 'local', 'kbacorporate');", block)
        # Defaults only: the step never updates membership or plan data.
        function = UPGRADE[UPGRADE.index('function local_kbacorporate_adr0005_defaults'):]
        function = function[:function.index('\n}\n')]
        self.assertNotRegex(function, r'(?i)\bUPDATE\s|\bINSERT\s|\bDELETE\s|set_field|update_record')


if __name__ == '__main__':
    unittest.main()
