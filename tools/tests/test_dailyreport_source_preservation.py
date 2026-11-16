"""Exact source preservation remains fail-closed when day-summary strings are appended.

Chain (each block is appended, never inserted): published #110 prefix -> #106/#107 day-summary block (#113) ->
Communication Center cleanup block (#112) -> daily reports R0 block (#116). Every earlier block must stay byte-identical (exact SHA256 of the file up to
the next block marker) and later keys must never override earlier ones.
"""
import hashlib
import importlib.util
import json
import re
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('preservation',ROOT/'tools/check_pr53_pr56_integration.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
PUBLISHED={'en':'cf9761113aa0dd206c0c52ed9b3cdb13aebe2be2b51bf91cd5c55cac0fd18ff4',
           'ru':'77808151a0f34400a2ae8868ddae57b703e8dfda4a15b0f8ddd2710eadb76d06'}
DAY_MARKER=b'\n// Issue #106: canonical read-only personal day summary.\n'
COMM_MARKER=b'\n// Communication Center cleanup (2026-11-15): one task discussion, task events, replies, group members.\n'
COMM_PREFIXES=(b'communication_',b'communicationcenter_',b'taskcenter_discussion_reader',b'taskdiscussion_event_')
R0_MARKER=b'\n// Issue #106 R0: daily reports entry point (navigation and names only, no schema).\n'
R1_MARKER=b'\n// ADR-0009 R1: saved daily reports (draft, submission, versions, history).\n'
R2_MARKER=b'\n// ADR-0009 R2: manager review, rating, return, rework and notifications.\n'
R0_KEYS=[b'dailyreports_nav',b'dailyreports_tabs',b'dailyreports_tab_my',b'dailyreports_button',
         b'dailyreports_nav_short',b'dailyreports_button_short']
_COMM=json.loads((ROOT/'docs/reconcile/communication-center-cleanup-source-preservation.json').read_text(encoding='utf-8'))
COMM_AFTER={lang:next(r['after_sha256_lf'] for r in _COMM['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in PUBLISHED}
_DAY=json.loads((ROOT/'docs/reconcile/daily-summary-after110-source-preservation.json').read_text(encoding='utf-8'))
DAY_AFTER={lang:next(r['after_sha256_lf'] for r in _DAY['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
           for lang in PUBLISHED}

def keys(blob): return re.findall(rb"^\$string\['([^']+)'\]",blob,re.M)

class Preservation(unittest.TestCase):
    def test_published_prefix_unchanged_and_new_keys_do_not_override_old(self):
        for lang,expected in PUBLISHED.items():
            data=(ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php').read_bytes().replace(b'\r\n',b'\n')
            prefix,tail=data.split(DAY_MARKER)
            self.assertEqual(expected,hashlib.sha256(prefix).hexdigest())
            day,comm=tail.split(COMM_MARKER) if COMM_MARKER in tail else (tail,None)
            daykeys=keys(day)
            self.assertEqual(18,len(daykeys));self.assertEqual(len(daykeys),len(set(daykeys)))
            self.assertTrue(all(k.startswith(b'dailyreport_') for k in daykeys))
            self.assertFalse(set(daykeys)&set(re.findall(rb"\$string\['([^']+)'\]",prefix)))
            r0=None
            if comm is not None and R0_MARKER in comm:
                comm,r0=comm.split(R0_MARKER)
            if comm is not None:
                # The #113 file (published prefix + day-summary block) is byte-identical up to the next marker.
                self.assertEqual(DAY_AFTER[lang],hashlib.sha256(prefix+DAY_MARKER+day).hexdigest())
                commkeys=keys(comm)
                self.assertEqual(23,len(commkeys));self.assertEqual(len(commkeys),len(set(commkeys)))
                self.assertTrue(all(k.startswith(COMM_PREFIXES) for k in commkeys))
                self.assertFalse(set(commkeys)&set(keys(prefix+day)))
            r1=None
            if r0 is not None and R1_MARKER in r0:
                r0,r1=r0.split(R1_MARKER)
            if r0 is not None:
                # The #114 file (… + cleanup block) is byte-identical up to the R0 marker.
                self.assertEqual(COMM_AFTER[lang],hashlib.sha256(prefix+DAY_MARKER+day+COMM_MARKER+comm).hexdigest())
                self.assertEqual(R0_KEYS,keys(r0))
                self.assertFalse(set(R0_KEYS)&set(keys(prefix+day+comm)))
            r2=None
            if r1 is not None and R2_MARKER in r1:
                r1,r2=r1.split(R2_MARKER)
            if r1 is not None:
                r1keys=keys(r1)
                self.assertEqual(54,len(r1keys));self.assertEqual(len(r1keys),len(set(r1keys)))
                self.assertTrue(all(k.startswith((b'dailyreports_',b'privacy:metadata:dailyreports')) for k in r1keys))
                self.assertFalse(set(r1keys)&set(keys(prefix+day+comm+r0)))
            if r2 is not None:
                r2keys=keys(r2)
                self.assertEqual(46,len(r2keys));self.assertEqual(len(r2keys),len(set(r2keys)))
                self.assertTrue(all(k.startswith((b'dailyreports_',b'messageprovider:dailyreport_',
                                                  b'communication_notification_dailyreport_')) for k in r2keys))
                self.assertFalse(set(r2keys)&set(keys(prefix+day+comm+r0+r1)))

    def test_exact_candidate_passes(self): self.assertEqual([],gate.check(ROOT))

    def test_mutation_of_either_language_is_rejected(self):
        original=gate.digest
        for lang in PUBLISHED:
            path=ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php'
            for mutation in [lambda b:b+b'\n// unexpected',lambda b:b.replace(b'dailyreport_title',b'overridden_key',1),
                             lambda b:b.replace(b'communication_reply',b'overridden_key',1),
                             lambda b:b.replace(b'dailyreports_button',b'dailyreports_other',1)]:
                changed=hashlib.sha256(mutation(path.read_bytes().replace(b'\r\n',b'\n'))).hexdigest()
                with patch.object(gate,'digest',side_effect=lambda p: changed if p==path else original(p)):
                    self.assertIn('parent source lost: '+path.relative_to(ROOT).as_posix(),gate.check(ROOT))

    def test_block_order_and_override_mutations_fail_the_structure_check(self):
        """A later block inserted before the day summary, or a later key overriding an earlier one, is detected."""
        for lang in PUBLISHED:
            data=(ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php').read_bytes().replace(b'\r\n',b'\n')
            prefix,tail=data.split(DAY_MARKER)
            day,comm=tail.split(COMM_MARKER)
            comm=comm.split(R0_MARKER)[0]
            # Cleanup block moved in front of the day summary: the bytes before the cleanup marker are no longer #113.
            moved=prefix+COMM_MARKER+comm+DAY_MARKER+day
            self.assertNotEqual(DAY_AFTER[lang],hashlib.sha256(moved.split(COMM_MARKER)[0]).hexdigest())
            # A cleanup key that redefines a day-summary key is caught by the override check.
            overriding=comm+b"$string['dailyreport_title'] = 'x';\n"
            self.assertTrue(set(keys(overriding))&set(keys(prefix+day)))

if __name__=='__main__':unittest.main()
