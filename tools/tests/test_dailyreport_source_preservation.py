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
PUBLISHED={'en':'cf9761113aa0dd206c0c52ed9b3cdb13aebe2be2b51bf91cd5c55cac0fd18ff4',
           'ru':'77808151a0f34400a2ae8868ddae57b703e8dfda4a15b0f8ddd2710eadb76d06'}

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
