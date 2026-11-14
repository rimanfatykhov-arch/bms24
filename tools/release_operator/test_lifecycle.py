"""Actual-process synthetic tests. Fixtures retained; no real service is addressed."""
import hashlib
import json
import math
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest

SCRIPT = Path(__file__).with_name('lifecycle.py').resolve()
LOG_LIMIT = 65536

class LifecycleTest(unittest.TestCase):
    def run_case(self, fault='none', timeout=10):
        parent = Path(tempfile.mkdtemp(prefix='bms-lifecycle-test-')).resolve()
        parent.chmod(0o700)
        result = subprocess.run([sys.executable, str(SCRIPT), 'rehearse', '--parent', str(parent),
                                 '--fault', fault, '--phase-timeout', str(timeout)], capture_output=True, text=True, timeout=150)
        roots = list(parent.glob('bms-operator-synthetic-*'))
        self.assertEqual(len(roots), 1, result.stderr)
        root = roots[0]
        self.assertEqual((root/'cron.txt').read_text(), 'SYNTHETIC CRON ENABLED\n')
        self.assertFalse((root/'maintenance.txt').exists())
        report = json.loads((root/'result.json').read_text())
        self.assertEqual(report['scope'], 'SYNTHETIC')
        self.assertEqual(report['tool_sha256'],hashlib.sha256(SCRIPT.read_bytes()).hexdigest())
        return root, report, result

    def test_success_real_children_and_retained_backup(self):
        root, report, result = self.run_case()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(report['status'], 'PASS')
        self.assertEqual(report['phases'], ['capture','verify','restore','install','smoke','rollback','reinstall','final_smoke'])
        data=(root/'backup.json').read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(),(root/'backup.sha256').read_text())
        self.assertEqual(json.loads((root/'clone/business.json').read_text()),['baseline','new-business-record'])
        self.assertEqual((root/'clone/file.txt').read_text(),'baseline file\nnew user file\n')
        self.assertEqual((root/'source/code.txt').read_text(),'SYNTHETIC BASELINE CODE\n')
        self.assertEqual([row['phase'] for row in report['phase_telemetry']],report['phases'])
        for phase in report['phases']:
            row=json.loads((root/(phase+'.phase.json')).read_text())
            self.assertEqual(row['scope'],'SYNTHETIC')
            self.assertEqual(row['exitcode'],0)
            self.assertFalse(row['timed_out'])
            self.assertTrue(math.isfinite(row['start_epoch']) and row['start_epoch']>0)
            self.assertTrue(math.isfinite(row['duration_monotonic_seconds']) and row['duration_monotonic_seconds']>=0)
            self.assertEqual(row['tool_sha256'],report['tool_sha256'])
            self.assertIn('pid='+str(row['pid']), (root/(phase+'.stdout.log')).read_text())
            self.assertIn('phase PASS', (root/(phase+'.stdout.log')).read_text())
        # Exclusive create must refuse a second capture, retaining the exact archive.
        config=json.loads((root/'.synthetic-operator.json').read_text())
        repeat=subprocess.run([sys.executable,str(SCRIPT),'_phase','--root',str(root),'--token',config['token'],'--phase','capture'],capture_output=True,timeout=30)
        self.assertNotEqual(repeat.returncode,0)
        self.assertEqual((root/'backup.json').read_bytes(),data)

    def test_phase_failures_fail_closed(self):
        for fault in ('source','capture','verify','wrong_hash','restore','install','smoke','rollback_purge','space','time'):
            with self.subTest(fault=fault):
                root, report, result = self.run_case(fault)
                self.assertNotEqual(result.returncode,0)
                self.assertEqual(report['status'],'FAIL')
                last={'verify':'capture','wrong_hash':'capture','restore':'verify','install':'restore','smoke':'install','rollback_purge':'smoke'}.get(fault)
                if last:self.assertEqual(report['phases'][-1],last,report)
                expected_phase={'source':'capture','capture':'capture','verify':'verify','wrong_hash':'verify','restore':'restore','install':'install','smoke':'smoke','rollback_purge':'rollback'}.get(fault)
                if expected_phase:
                    self.assertEqual(report['reason'],'child phase failed: '+expected_phase)
                    telemetry=json.loads((root/(expected_phase+'.phase.json')).read_text())
                    self.assertNotEqual(telemetry['exitcode'],0)
                    self.assertFalse(telemetry['timed_out'])
                    expected_error={'source':'source code mismatch','wrong_hash':'backup checksum mismatch','rollback_purge':'synthetic rollback purge failed'}.get(fault,'injected '+fault+' failure')
                    self.assertIn(expected_error,(root/(expected_phase+'.stderr.log')).read_text())
                else:
                    self.assertEqual(report['reason'],{'space':'insufficient fixture space','time':'expired synthetic window'}[fault])
                if (root/'clone').exists():
                    self.assertEqual((root/'clone/code.txt').read_text(),'SYNTHETIC BASELINE CODE\n')
                if fault in ('smoke','rollback_purge'):
                    self.assertEqual(json.loads((root/'clone/business.json').read_text()),['baseline','new-business-record'])
                    self.assertEqual((root/'clone/file.txt').read_text(),'baseline file\nnew user file\n')
                if fault=='rollback_purge':self.assertTrue((root/'rollback-purge-failed.json').exists())

    def test_timeout_waits_for_child_and_bounds_logs(self):
        root, report, result=self.run_case('child_timeout',10)
        self.assertNotEqual(result.returncode,0)
        self.assertIn('timeout',report['reason'])
        self.assertIsNone(json.loads((root/'active-child.json').read_text())['pid'])
        self.assertEqual((root/'capture.stdout.log').stat().st_size,LOG_LIMIT)
        self.assertFalse((root/'backup.json').exists())
        row=json.loads((root/'capture.phase.json').read_text())
        self.assertTrue(row['timed_out'])
        self.assertNotEqual(row['exitcode'],0)
        self.assertTrue(math.isfinite(row['duration_monotonic_seconds']) and row['duration_monotonic_seconds']>=10)
        self.assertEqual(report['phase_telemetry'],[row])

    def test_wrong_token_and_unsafe_parent_refused(self):
        root, _, _=self.run_case()
        result=subprocess.run([sys.executable,str(SCRIPT),'_phase','--root',str(root),'--token','0'*64,'--phase','install'],capture_output=True,timeout=30)
        self.assertNotEqual(result.returncode,0)
        result=subprocess.run([sys.executable,str(SCRIPT),'rehearse','--parent',str(root.parent)],capture_output=True,timeout=30)
        self.assertNotEqual(result.returncode,0)
        result=subprocess.run([sys.executable,str(SCRIPT),'rehearse','--parent',str(root.parent),'--fault','unknown'],capture_output=True,timeout=30)
        self.assertNotEqual(result.returncode,0)

    def test_symlink_mutation_is_rejected(self):
        root, _, _=self.run_case()
        external=root.parent/'outside.txt';external.write_text('untouched')
        target=root/'clone/code.txt';target.unlink()
        try:target.symlink_to(external)
        except OSError:
            if os.name != 'nt':raise
            # Windows may lack symlink privilege: exercise a noncanonical parent instead; Linux CI executes link case.
            result=subprocess.run([sys.executable,str(SCRIPT),'rehearse','--parent',str(root/'..')],capture_output=True,timeout=30)
            self.assertNotEqual(result.returncode,0)
            return
        config=json.loads((root/'.synthetic-operator.json').read_text())
        result=subprocess.run([sys.executable,str(SCRIPT),'_phase','--root',str(root),'--token',config['token'],'--phase','rollback'],capture_output=True,timeout=30)
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(external.read_text(),'untouched')

    def test_process_identity_concurrent_state_and_hardlink(self):
        root, _, _=self.run_case()
        config=json.loads((root/'.synthetic-operator.json').read_text())
        def invoke(command, *args):
            return subprocess.run([sys.executable,str(SCRIPT),command,'--root',str(root),'--token',config['token'],*args],capture_output=True,text=True,timeout=30)
        # Wrong PID fails before any signal, even when that PID belongs to this test process.
        wrong=invoke('_watchdog','--pid',str(os.getpid()))
        self.assertNotEqual(wrong.returncode,0);self.assertIn('controller identity mismatch',wrong.stderr)
        original=dict(config)
        config.update(controller_pid=os.getpid(),controller_birth='0')
        (root/'.synthetic-operator.json').write_text(json.dumps(config))
        wrong=invoke('_watchdog','--pid',str(os.getpid()))
        self.assertNotEqual(wrong.returncode,0);self.assertIn('process birth mismatch',wrong.stderr)
        (root/'.synthetic-operator.json').write_text(json.dumps(original))
        for target, foreign in [('cron.txt','FOREIGN CRON'),('maintenance.txt','FOREIGN MAINTENANCE')]:
            path=root/target;path.write_text(foreign)
            code=(root/'clone/code.txt').read_bytes()
            bad=invoke('_phase','--phase','rollback')
            self.assertNotEqual(bad.returncode,0);self.assertIn('concurrent fixture state',bad.stderr)
            self.assertEqual(path.read_text(),foreign)
            self.assertEqual((root/'clone/code.txt').read_bytes(),code)
            self.assertTrue((root/'service-conflict.json').exists())
            if target=='cron.txt':path.write_text('SYNTHETIC CRON ENABLED\n')
            else:path.unlink()
        external=root.parent/'hardlink-outside.txt';external.write_text('OUTSIDE UNTOUCHED')
        linked=root/'clone/hardlink.txt';os.link(external,linked)
        bad=invoke('_phase','--phase','rollback')
        self.assertNotEqual(bad.returncode,0);self.assertIn('hardlinked fixture file forbidden',bad.stderr)
        self.assertEqual(external.read_text(),'OUTSIDE UNTOUCHED')

    @unittest.skipUnless(os.name=='posix', 'Windows SIGKILL not applicable; required Linux CI covers detached-watchdog recovery')
    def test_sigkill_controller_watchdog_recovers_and_preserves_new_writes(self):
        parent=Path(tempfile.mkdtemp(prefix='bms-lifecycle-kill-')).resolve();parent.chmod(0o700)
        with (parent.parent/(parent.name+'.console.log')).open('wb') as log:
            process=subprocess.Popen([sys.executable,str(SCRIPT),'rehearse','--parent',str(parent),'--fault','controller_pause'],stdout=log,stderr=log,start_new_session=True)
            until=time.monotonic()+90;root=None
            while time.monotonic()<until:
                roots=list(parent.glob('bms-operator-synthetic-*'))
                if roots and (roots[0]/'kill-ready.json').exists():root=roots[0];break
                self.assertIsNone(process.poll());time.sleep(.05)
            self.assertIsNotNone(root,'controller never reached safe kill checkpoint')
            self.assertTrue((root/'maintenance.txt').exists())
            os.kill(process.pid,signal.SIGKILL);process.wait(timeout=5)
            until=time.monotonic()+10
            while time.monotonic()<until and not (root/'done.json').exists():time.sleep(.05)
            self.assertTrue((root/'watchdog.json').exists())
            self.assertEqual(json.loads((root/'result.json').read_text())['status'],'FAIL')
            self.assertEqual((root/'clone/code.txt').read_text(),'SYNTHETIC BASELINE CODE\n')
            self.assertEqual((root/'cron.txt').read_text(),'SYNTHETIC CRON ENABLED\n')
            self.assertFalse((root/'maintenance.txt').exists())
            self.assertEqual(json.loads((root/'clone/business.json').read_text()),['baseline','new-business-record'])
            self.assertEqual((root/'clone/file.txt').read_text(),'baseline file\nnew user file\n')
            self.assertTrue((root/'backup.json').exists())

if __name__=='__main__':unittest.main()
