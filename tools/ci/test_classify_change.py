#!/usr/bin/env python3
"""Unit tests for tools/ci/classify_change.py on a throw-away Git repository (no network)."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from classify_change import classify  # noqa: E402

BASE_FILES = {
    'local/kbacompany/version.php': "<?php\n$plugin->version = 2026111100;\n",
    'local/kbacompany/bms_ui/task_center.css': '.a{color:red}\n',
    'local/kbacompany/lang/en/local_kbacompany.php': "<?php\n$string['a'] = 'A';\n",
    'local/kbacompany/classes/local/report_ui_service.php': "<?php\nfunction render() { return 'x'; }\n",
    'local/kbacompany/classes/local/report_service.php': "<?php\nfunction save() { require_capability('x', $c); confirm_sesskey(); }\n",
    'local/kbacompany/tests/report_service_test.php': "<?php\n",
    'local/kbacompany/tests/pr48_tenant_isolation_test.php': "<?php\n",
    'docs/guide.md': '# Guide\n',
}


class ClassifierTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name)
        self.git('init', '-q')
        self.git('config', 'user.email', 'ci@example.invalid')
        self.git('config', 'user.name', 'ci')
        self.git('config', 'core.autocrlf', 'false')
        for path, text in BASE_FILES.items():
            self.write(path, text)
        self.base = self.commit('base')

    def tearDown(self):
        self.tmp.cleanup()

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True, stdout=subprocess.PIPE,
                              universal_newlines=True).stdout.strip()

    def write(self, path, text):
        p = self.repo / path
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text, encoding='utf-8')

    def commit(self, msg):
        self.git('add', '-A')
        self.git('commit', '-q', '-m', msg)
        return self.git('rev-parse', 'HEAD')

    def change(self, files):
        for path, text in files.items():
            self.write(path, text)
        return classify(self.repo, self.base, self.commit('change'))

    def test_css_only_is_fast(self):
        r = self.change({'local/kbacompany/bms_ui/task_center.css': '.a{color:blue}\n'})
        self.assertEqual('FAST', r['profile'])
        self.assertEqual([], r['security_gates'])

    def test_lang_and_docs_are_fast(self):
        r = self.change({'local/kbacompany/lang/en/local_kbacompany.php': "<?php\n$string['a'] = 'B';\n", 'docs/guide.md': '# G2\n'})
        self.assertEqual('FAST', r['profile'])

    def test_ui_php_without_triggers_is_standard_with_gates_and_focused_tests(self):
        r = self.change({'local/kbacompany/classes/local/report_ui_service.php': "<?php\nfunction render() { return 'y'; }\n"})
        self.assertEqual('STANDARD', r['profile'])
        self.assertIn('pr48_tenant_isolation_test', r['security_gates'])
        self.assertIn('report_service_test', r['focused_phpunit'])

    def test_file_upload_code_is_full(self):
        r = self.change({'local/kbacompany/classes/local/report_ui_service.php': "<?php\nfunction up() { return $_FILES['a']; }\n"})
        self.assertEqual('FULL', r['profile'])
        self.assertTrue(any('File API' in x['reason'] for x in r['reasons']))

    def test_removing_capability_or_sesskey_check_is_full(self):
        r = self.change({'local/kbacompany/classes/local/report_service.php': "<?php\nfunction save() { }\n"})
        self.assertEqual('FULL', r['profile'])
        why = ' '.join(x['reason'] for x in r['reasons'])
        self.assertIn('capability', why)
        self.assertIn('sesskey', why)

    def test_raw_sql_is_full(self):
        r = self.change({'local/kbacompany/classes/local/report_ui_service.php': "<?php\n$DB->get_records_sql('SELECT 1');\n"})
        self.assertEqual('FULL', r['profile'])

    def test_schema_version_corporate_and_workflow_paths_are_full(self):
        for path in ['local/kbacompany/db/install.xml', 'local/kbacompany/version.php', 'local/kbacorporate/lib.php',
                     '.github/workflows/x.yml', 'local/kbacompany/lib.php']:
            with self.subTest(path=path):
                r = self.change({path: '<x>%s</x>\n' % path})
                self.assertEqual('FULL', r['profile'])

    def test_unknown_path_defaults_to_full(self):
        r = self.change({'vendor/unknown/blob.bin': 'data\n'})
        self.assertEqual('FULL', r['profile'])
        self.assertTrue(any('unknown path' in x['reason'] for x in r['reasons']))

    def test_mixed_change_takes_highest_profile(self):
        r = self.change({'local/kbacompany/bms_ui/task_center.css': '.a{color:green}\n',
                         'local/kbacompany/db/access.php': '<?php\n'})
        self.assertEqual('FULL', r['profile'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
