"""Exact source preservation remains fail-closed when day-summary strings are appended."""
import hashlib
import importlib.util
import re
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('preservation',ROOT/'tools/check_pr53_pr56_integration.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
PUBLISHED={'en':'0494fad97271909666a779281d1eb25944b8cc9bf55e1df5ff73f356922c8dc1',
           'ru':'6dd439078e4a5afa7d409d162a09bf008795d7e5336ab72a80cc59b7de3bc274'}

class Preservation(unittest.TestCase):
    def test_published_prefix_unchanged_and_new_keys_do_not_override_old(self):
        for lang,expected in PUBLISHED.items():
            data=(ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php').read_bytes().replace(b'\r\n',b'\n')
            prefix,tail=data.split(b'\n// Issue #106: canonical read-only personal day summary.\n')
            self.assertEqual(expected,hashlib.sha256(prefix).hexdigest())
            keys=re.findall(rb"^\$string\['([^']+)'\]",tail,re.M)
            self.assertEqual(18,len(keys));self.assertEqual(len(keys),len(set(keys)))
            self.assertTrue(all(k.startswith(b'dailyreport_') for k in keys))
            self.assertFalse(set(keys)&set(re.findall(rb"\$string\['([^']+)'\]",prefix)))

    def test_exact_candidate_passes(self): self.assertEqual([],gate.check(ROOT))

    def test_mutation_of_either_language_is_rejected(self):
        original=gate.digest
        for lang in PUBLISHED:
            path=ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php'
            for mutation in [lambda b:b+b'\n// unexpected',lambda b:b.replace(b'dailyreport_title',b'overridden_key',1)]:
                changed=hashlib.sha256(mutation(path.read_bytes().replace(b'\r\n',b'\n'))).hexdigest()
                with patch.object(gate,'digest',side_effect=lambda p: changed if p==path else original(p)):
                    self.assertIn('parent source lost: '+path.relative_to(ROOT).as_posix(),gate.check(ROOT))

if __name__=='__main__':unittest.main()
