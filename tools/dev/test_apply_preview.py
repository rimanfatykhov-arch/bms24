import json
import tempfile
import unittest
import zipfile
from unittest.mock import patch
from pathlib import Path
from apply_preview import apply
from verify_preview_package import digest


class Lifecycle(unittest.TestCase):
    def fixture(self):
        t=tempfile.TemporaryDirectory();self.addCleanup(t.cleanup);r=Path(t.name)
        (r/'synthetic-dev.json').write_text(json.dumps({'role':'synthetic-dev','web':'bms24-synthetic-dev-web'}))
        c=r/'code/local/kbacompany';(c/'db').mkdir(parents=True)
        for n,v in [('version.php',b'1'),('db/install.xml',b'schema'),('page.php',b'old')]: (c/n).write_bytes(v)
        (r/'data').mkdir();(r/'data/new-user-record').write_text('must survive')
        z=r/'candidate.zip';m=r/'manifest.json';entries=[]
        with zipfile.ZipFile(z,'w') as f:
            for n,v in [('version.php',b'1'),('db/install.xml',b'schema'),('page.php',b'new')]:
                path='kbacompany/'+n;f.writestr(path,v);entries.append({'path':path,'bytes':len(v),'sha256':digest(v)})
        m.write_text(json.dumps({'artifact':{'component':'local_kbacompany','zip_sha256':digest(z.read_bytes()),'files':3},'entries':entries}))
        return r,c,z,m

    def call(self,r,z,m,run): return apply(r,z,m,digest(z.read_bytes()),digest(m.read_bytes()),run=run)

    def test_success_changes_only_module_keeps_previous_code_and_data(self):
        r,c,z,m=self.fixture();calls=[];self.call(r,z,m,calls.append)
        self.assertEqual(b'new',(c/'page.php').read_bytes());self.assertEqual('must survive',(r/'data/new-user-record').read_text())
        self.assertEqual(1,len(list((r/'code-history').glob('*/previous/page.php'))));self.assertFalse((r/'preview-operator.lock').exists())

    def test_purge_failure_rolls_code_back_without_restoring_data(self):
        r,c,z,m=self.fixture();failed=False
        def run(a):
            nonlocal failed
            if a[1]=='exec' and not failed: failed=True;raise RuntimeError('fixture purge failure')
        with self.assertRaisesRegex(RuntimeError,'purge'):self.call(r,z,m,run)
        self.assertEqual(b'old',(c/'page.php').read_bytes());self.assertEqual('must survive',(r/'data/new-user-record').read_text())

    def test_lock_blocks_parallel_operator(self):
        r,c,z,m=self.fixture();(r/'preview-operator.lock').write_text('another operator')
        with self.assertRaises(FileExistsError):self.call(r,z,m,lambda a:self.fail('must not invoke Docker'))

    def test_schema_drift_is_not_a_fast_preview(self):
        r,c,z,m=self.fixture();(c/'db/install.xml').write_text('different baseline')
        with self.assertRaisesRegex(ValueError,'schema/version'):self.call(r,z,m,lambda a:self.fail('must not invoke Docker'))

    def test_non_dev_marker_is_rejected(self):
        r,c,z,m=self.fixture();(r/'synthetic-dev.json').write_text('{"role":"production"}')
        with self.assertRaisesRegex(ValueError,'marked synthetic'):self.call(r,z,m,lambda a:self.fail('must not invoke Docker'))

    def test_failed_second_rename_restores_old_code(self):
        r,c,z,m=self.fixture();original=Path.rename
        def rename(p,target):
            if p.name.startswith('.kbacompany-preview-'):raise RuntimeError('fixture rename failure')
            return original(p,target)
        with patch.object(Path,'rename',new=rename):
            with self.assertRaisesRegex(RuntimeError,'rename'):self.call(r,z,m,lambda a:None)
        self.assertEqual(b'old',(c/'page.php').read_bytes())

    def test_failed_stop_still_attempts_service_recovery(self):
        r,c,z,m=self.fixture();calls=[]
        def run(a):
            calls.append(a)
            if a[1]=='stop':raise RuntimeError('fixture stop failure')
        with self.assertRaisesRegex(RuntimeError,'stop'):self.call(r,z,m,run)
        self.assertIn(['docker','start','bms24-synthetic-dev-web'],calls)
        self.assertEqual(b'old',(c/'page.php').read_bytes())

if __name__=='__main__': unittest.main()
