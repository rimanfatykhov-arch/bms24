"""Exact hash exceptions must not become path exemptions."""
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('preservation', ROOT / 'tools/check_pr53_pr56_integration.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class SourcePreservationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        planpath = Path('docs/reconcile/pr53-pr56-conflict-map.json')
        self.plan = json.loads((ROOT / planpath).read_text(encoding='utf-8'))
        paths = {row['path'] for row in self.plan['rows']}
        paths.update(self.plan['unchanged_schema_hashes'])
        paths.update([str(planpath), 'docs/reconcile/PR53_RELEASE_NOTES_0902_HISTORICAL.md',
            'local/kbacompany/db/upgrade.php', 'tools/tests/moodle_acceptance.php'])
        for name in paths:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, target)
        evidence = json.loads((ROOT / 'docs/reconcile/pr102-source-preservation.json').read_text(encoding='utf-8'))
        self.reviewed = evidence['files']
        self.assertEqual(5, len(self.reviewed))
        self.assertEqual([], gate.check(self.root), 'exact candidate must first pass')

    def assert_mutation_blocked(self, name, prefix):
        path = self.root / name
        original = path.read_bytes()
        try:
            path.write_bytes(original + b'\n/* deliberately unexpected source mutation */\n')
            self.assertIn(prefix + name, gate.check(self.root))
        finally:
            path.write_bytes(original)

    def test_exact_review_evidence_matches_candidate(self):
        for row in self.reviewed:
            with self.subTest(path=row['path']):
                self.assertEqual(row['after_sha256_lf'], gate.digest(self.root / row['path']))

    def test_every_reviewed_path_still_rejects_unreviewed_changes(self):
        for row in self.reviewed:
            with self.subTest(path=row['path']):
                self.assert_mutation_blocked(row['path'], 'parent source lost: ')

    def test_unrelated_parent_source_is_still_protected(self):
        reviewed = {row['path'] for row in self.reviewed}
        name = next(row['path'] for row in self.plan['rows']
            if row['resolution'] == 'select-pr53' and row['path'] not in reviewed
            and row['path'].endswith('.php'))
        self.assert_mutation_blocked(name, 'parent source lost: ')

    def test_shared_schema_is_still_protected(self):
        name = next(iter(self.plan['unchanged_schema_hashes']))
        self.assert_mutation_blocked(name, 'shared schema/helper changed: ')


if __name__ == '__main__':
    unittest.main()
