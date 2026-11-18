"""Module-only operator for marked synthetic dev. Never accepts production targets or deployment approval."""
import argparse
import datetime
import json
import os
import subprocess
import shutil
import uuid
import zipfile
from pathlib import Path
from verify_preview_package import verify


def apply(root, archive, manifest, zipsha, manifestsha, run=None):
    if Path(root).is_symlink(): raise ValueError('unsafe dev root')
    root = Path(root).resolve(strict=True)
    if root == Path(root.anchor) or root.is_symlink(): raise ValueError('unsafe dev root')
    marker = json.loads((root/'synthetic-dev.json').read_text())
    if marker != {'role': 'synthetic-dev', 'web': 'bms24-synthetic-dev-web'}:
        raise ValueError('not the marked synthetic dev')
    binding = verify(archive, manifest, zipsha, manifestsha)
    code = root/'code/local/kbacompany'
    if code.is_symlink() or not code.is_dir() or not code.resolve().is_relative_to(root):
        raise ValueError('unsafe module root')
    with zipfile.ZipFile(archive) as z:
        if shutil.disk_usage(root).free < sum(i.file_size for i in z.infolist()) + 128*1024**2:
            raise ValueError('insufficient preview space')
        if hasattr(os, 'statvfs') and os.statvfs(root).f_favail < len(z.infolist()) + 1024:
            raise ValueError('insufficient preview inodes')
        # No schema/version change on this bounded preview route. Extended work needs another procedure.
        guarded = [n for n in z.namelist() if n.startswith('kbacompany/db/') or n == 'kbacompany/version.php']
        oldguard = {p.relative_to(code).as_posix() for p in (code/'db').rglob('*') if p.is_file()} | {'version.php'}
        if {n[len('kbacompany/'):] for n in guarded} != oldguard:
            raise ValueError('schema inventory changed')
        for n in guarded:
            p = code/n[len('kbacompany/'):]
            if p.is_symlink() or p.read_bytes() != z.read(n): raise ValueError('schema/version change requires extended route')
    lock = root/'preview-operator.lock'
    with lock.open('x') as f:
        f.write(json.dumps({'pid': os.getpid(), **binding})); f.flush(); os.fsync(f.fileno())
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+uuid.uuid4().hex[:8]
    history = root/'code-history'/stamp; history.mkdir(parents=True)
    stage = root/'code/local'/('.kbacompany-preview-'+stamp); stage.mkdir()
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    executor = run or (lambda args: subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90))
    switched = False; stopped = False; previousmoved = False
    try:
        with zipfile.ZipFile(archive) as z:
            for n in z.namelist():
                p = stage/n[len('kbacompany/'):]; p.parent.mkdir(parents=True, exist_ok=True)
                p.write_bytes(z.read(n)); p.chmod(0o644)
        for p in [stage, *[p for p in stage.rglob('*') if p.is_dir()]]: p.chmod(0o755)
        stopped = True; executor(['docker', 'stop', 'bms24-synthetic-dev-web'])
        code.rename(history/'previous'); previousmoved = True
        stage.rename(code); switched = True
        executor(['docker', 'start', 'bms24-synthetic-dev-web']); stopped = False
        executor(['docker', 'exec', 'bms24-synthetic-dev-web', 'php', '/var/www/html/admin/cli/purge_caches.php'])
        receipt = {'status': 'PREVIEW_INSTALLED_SMOKE_PENDING', 'started': started,
                   'completed': datetime.datetime.now(datetime.timezone.utc).isoformat(), **binding}
        receipt['status'] = 'PREVIEW_INSTALLED_SMOKE_PENDING'
        (history/'receipt.json').write_text(json.dumps(receipt, indent=2)); return receipt
    except Exception:
        if switched:
            executor(['docker', 'stop', 'bms24-synthetic-dev-web'])
            code.rename(history/'failed-candidate'); (history/'previous').rename(code)
        elif previousmoved:
            (history/'previous').rename(code)
        if switched or stopped:
            executor(['docker', 'start', 'bms24-synthetic-dev-web'])
            executor(['docker', 'exec', 'bms24-synthetic-dev-web', 'php', '/var/www/html/admin/cli/purge_caches.php'])
        raise
    finally:
        lock.unlink()  # Only this invocation's exclusive lock; never stale locks/backups.


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['root', 'zip', 'manifest', 'zip-sha256', 'manifest-sha256']: p.add_argument('--'+name, required=True)
    a = vars(p.parse_args()); print(json.dumps(apply(a['root'], a['zip'], a['manifest'], a['zip_sha256'], a['manifest_sha256'])))
