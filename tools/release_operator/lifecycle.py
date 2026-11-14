"""Portable SYNTHETIC operator rehearsal. No Moodle, real database, network or deployment.

Only creates a new sandbox inside an empty private directory below the OS temp root.
No cleanup: callers retain all archives, logs and evidence. Internal subprocess entry
points accept only the generated fixture marker and fixed paths, never commands.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time

BASE = 'SYNTHETIC BASELINE CODE\n'
CANDIDATE = 'SYNTHETIC CANDIDATE CODE\n'
CRON = 'SYNTHETIC CRON ENABLED\n'
MARKER = '.synthetic-operator.json'
FAULTS = ('none', 'source', 'capture', 'verify', 'wrong_hash', 'restore', 'install',
          'smoke', 'rollback_purge', 'space', 'time', 'child_timeout', 'controller_pause')
PHASES = ('capture', 'verify', 'restore', 'install', 'smoke', 'rollback', 'reinstall', 'final_smoke')
LOG_LIMIT = 65536


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def atomic(path, value):
    # Always inside a validated sandbox; replace only our own metadata, never archives.
    temporary = path.with_name(path.name + '.tmp-' + secrets.token_hex(4))
    with temporary.open('x', encoding='utf-8') as stream:
        json.dump(value, stream, indent=2)
    os.replace(temporary, path)


def checked_root(raw, token):
    root = Path(raw)
    require(root.is_absolute() and not root.is_symlink() and root.resolve() == root, 'unsafe sandbox root')
    temp = Path(tempfile.gettempdir()).resolve()
    require(temp in root.parents and root.name.startswith('bms-operator-synthetic-'), 'outside temporary sandbox')
    marker = root / MARKER
    require(marker.is_file() and not marker.is_symlink(), 'fixture marker missing')
    require(marker.stat().st_nlink == 1, 'hardlinked fixture marker forbidden')
    if os.name == 'posix':require(root.stat().st_uid == os.getuid() and root.stat().st_mode & 0o077 == 0, 'sandbox owner or permissions invalid')
    config = json.loads(marker.read_text(encoding='utf-8'))
    require(config.get('kind') == 'BMS_SYNTHETIC_ONLY_V1' and config.get('token') == token
            and isinstance(token, str) and len(token) == 64, 'fixture identity mismatch')
    require(config.get('fault') in FAULTS, 'unknown simulation fault')
    # Every internal entry validates all existing paths before writing anything.
    for path in root.rglob('*'):
        require(not path.is_symlink() and root in path.resolve().parents, 'unsafe sandbox descendant')
        if path.is_file():require(path.stat().st_nlink == 1, 'hardlinked fixture file forbidden')
    return root, config


def safe_descendants(root):
    require(not root.is_symlink() and root.resolve() == root, 'unsafe sandbox root')
    for path in root.rglob('*'):
        require(not path.is_symlink() and root in path.resolve().parents, 'unsafe sandbox descendant')
        if path.is_file():require(path.stat().st_nlink == 1, 'hardlinked fixture file forbidden')


def freeze(root):
    service_precheck(root)
    require((root / 'cron.txt').read_text() == CRON and not (root / 'maintenance.txt').exists(), 'fixture not initially running')
    (root / 'maintenance.txt').write_text('SYNTHETIC MAINTENANCE\n')
    (root / 'cron.txt').write_text('SYNTHETIC CRON PAUSED\n')


def service_precheck(root):
    safe_descendants(root)
    mark = root / 'maintenance.txt'
    ok = ((root / 'cron.txt').read_text() in (CRON, 'SYNTHETIC CRON PAUSED\n')
          and (not mark.exists() or mark.read_text() == 'SYNTHETIC MAINTENANCE\n'))
    if not ok:
        atomic(root / 'service-conflict.json', {'status': 'FAIL', 'reason': 'concurrent fixture state', 'scope': 'SYNTHETIC'})
    require(ok, 'concurrent fixture state')


def resume(root):
    service_precheck(root)
    (root / 'cron.txt').write_text(CRON)
    mark = root / 'maintenance.txt'
    if mark.exists():mark.unlink()


def restore_code(root, purge_failure=False):
    service_precheck(root)
    try:
        clone = root / 'clone'
        if clone.exists():
            (clone / 'code.txt').write_text(BASE)
        if purge_failure:
            atomic(root / 'rollback-purge-failed.json', {'status': 'FAIL', 'scope': 'SYNTHETIC'})
            raise RuntimeError('synthetic rollback purge failed')
    finally:
        # Even a cache-purge error must not leave the restored service stopped.
        resume(root)


def phase(root, config, name):
    fault = config['fault']
    print('SYNTHETIC phase ' + name, flush=True)
    if fault == name:
        raise RuntimeError('injected ' + name + ' failure')
    if name == 'capture':
        if fault == 'child_timeout':
            # Exercise bounded draining as well as actual process timeout.
            sys.stdout.write('X' * (LOG_LIMIT * 3)); sys.stdout.flush()
            time.sleep(30)
        source = root / 'source'
        require((source / 'code.txt').read_text() == BASE, 'source code mismatch')
        payload = {key: (source / key).read_text() for key in ('code.txt', 'business.json', 'file.txt')}
        data = json.dumps(payload, sort_keys=True).encode()
        with (root / 'backup.json').open('xb') as stream:
            stream.write(data)
        with (root / 'backup.sha256').open('x') as stream:
            stream.write(sha(data))
    elif name == 'verify':
        actual = sha((root / 'backup.json').read_bytes())
        expected = (root / 'backup.sha256').read_text()
        require(actual == expected, 'backup checksum mismatch')
        payload = json.loads((root / 'backup.json').read_bytes())
        require(set(payload) == {'code.txt', 'business.json', 'file.txt'} and payload['code.txt'] == BASE,
                'backup inventory mismatch')
        require(json.loads(payload['business.json']) == ['baseline'], 'backup data mismatch')
    elif name == 'restore':
        # Reverify at point of use; successful earlier verification cannot bless changed bytes.
        require(sha((root / 'backup.json').read_bytes()) == (root / 'backup.sha256').read_text(), 'restore checksum mismatch')
        payload = json.loads((root / 'backup.json').read_bytes())
        require(set(payload) == {'code.txt', 'business.json', 'file.txt'}, 'restore inventory mismatch')
        clone = root / 'clone'; clone.mkdir()
        for key in ('code.txt', 'business.json', 'file.txt'):
            with (clone / key).open('x') as stream:
                stream.write(payload[key])
        require(json.loads((clone / 'business.json').read_text()) == ['baseline'], 'restored records mismatch')
    elif name in ('install', 'reinstall'):
        require((root / 'clone/code.txt').read_text() == BASE, 'baseline code missing before install')
        freeze(root)
        try:
            (root / 'clone/code.txt').write_text(CANDIDATE)
        finally:
            resume(root)
    elif name in ('smoke', 'final_smoke'):
        require((root / 'clone/code.txt').read_text() == CANDIDATE, 'installed code mismatch')
        require(json.loads((root / 'clone/business.json').read_text()) == ['baseline', 'new-business-record'], 'new data lost')
        require((root / 'clone/file.txt').read_text() == 'baseline file\nnew user file\n', 'new file lost')
    elif name == 'rollback':
        freeze(root)
        restore_code(root, fault == 'rollback_purge')
        require((root / 'clone/code.txt').read_text() == BASE, 'rollback code mismatch')
    else:
        raise RuntimeError('unknown phase')
    print('SYNTHETIC phase PASS ' + name, flush=True)


def process_birth(pid):
    if os.name == 'posix':
        try:
            # Field 22 after the final ')' of comm, which may itself contain spaces.
            return Path('/proc/%d/stat' % pid).read_text().rsplit(')', 1)[1].split()[19]
        except FileNotFoundError:return None
    import ctypes
    from ctypes import wintypes
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
    kernel.OpenProcess.restype = wintypes.HANDLE
    kernel.GetProcessTimes.argtypes = [wintypes.HANDLE] + [ctypes.POINTER(wintypes.FILETIME)] * 4
    kernel.CloseHandle.argtypes = [wintypes.HANDLE]
    handle = kernel.OpenProcess(0x1000, False, pid)
    if not handle:return None
    try:
        times = [wintypes.FILETIME() for _ in range(4)]
        require(kernel.GetProcessTimes(handle, *(ctypes.byref(x) for x in times)), 'process birth unavailable')
        return str((times[0].dwHighDateTime << 32) | times[0].dwLowDateTime)
    finally:kernel.CloseHandle(handle)


def matching_process(pid, birth):
    require(isinstance(pid, int) and pid > 0 and isinstance(birth, str) and birth.isdigit(), 'invalid process identity')
    current = process_birth(pid)
    require(current is None or current == birth, 'process birth mismatch; no signal permitted')
    return current is not None


def kill_child(pid, birth):
    if not matching_process(pid, birth):return
    try:
        if os.name == 'posix':
            os.killpg(pid, signal.SIGKILL)
        else:
            os.kill(pid, signal.SIGTERM)
    except ProcessLookupError:
        pass


def child(root, token, name, timeout):
    args = [sys.executable, str(Path(__file__).resolve()), '_phase', '--root', str(root), '--token', token, '--phase', name]
    process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               start_new_session=os.name == 'posix')
    birth = process_birth(process.pid)
    require(birth is not None, 'child birth unavailable')
    atomic(root / 'active-child.json', {'pid': process.pid, 'birth': birth, 'phase': name})
    drain_errors = []
    def drain(pipe, target):
        try:
            drain_impl(pipe, target)
        except Exception as error:
            drain_errors.append(type(error).__name__)
    def drain_impl(pipe, target):
        kept = 0
        with target.open('xb') as stream:
            while True:
                data = pipe.read(4096)
                if not data:
                    break
                if kept < LOG_LIMIT:
                    fragment = data[:LOG_LIMIT-kept];stream.write(fragment);kept += len(fragment)
        pipe.close()
    threads = [threading.Thread(target=drain, args=(pipe, root / (name + '.' + label + '.log')))
               for pipe, label in ((process.stdout, 'stdout'), (process.stderr, 'stderr'))]
    for thread in threads:thread.start()
    try:
        try:
            code = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            kill_child(process.pid, birth);process.wait(timeout=5)
            raise RuntimeError('child phase timeout: ' + name)
        require(code == 0, 'child phase failed: ' + name)
    finally:
        if process.poll() is None:
            kill_child(process.pid, birth);process.wait(timeout=5)
        for thread in threads:thread.join(timeout=5)
        require(not any(thread.is_alive() for thread in threads) and not drain_errors, 'log drain incomplete or failed')
        atomic(root / 'active-child.json', {'pid': None, 'phase': name})


def controller_alive(pid):
    if os.name == 'nt':
        # os.kill(pid, 0) terminates processes on Windows; use a read-only wait handle.
        import ctypes
        from ctypes import wintypes
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        kernel.OpenProcess.restype = wintypes.HANDLE
        kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        handle = kernel.OpenProcess(0x00100000, False, pid)
        if not handle:return False
        try:return kernel.WaitForSingleObject(handle, 0) == 258
        finally:kernel.CloseHandle(handle)
    try:
        os.kill(pid, 0);return True
    except ProcessLookupError:
        return False


def watchdog(root, config, pid):
    require(pid == config.get('controller_pid') and pid > 0, 'watchdog controller identity mismatch')
    birth = config.get('controller_birth')
    matching_process(pid, birth)
    while time.time() < config['deadline'] and controller_alive(pid):
        if (root / 'done.json').exists():return
        time.sleep(.1)
    if (root / 'done.json').exists():return
    active = root / 'active-child.json'
    if active.exists():
        active_info = json.loads(active.read_text())
        childpid = active_info.get('pid')
        if childpid:kill_child(childpid, active_info.get('birth'))
    # Stop the controller before recovery, preventing a later write from racing rollback.
    if controller_alive(pid) and matching_process(pid, birth):
        os.kill(pid, signal.SIGKILL if os.name == 'posix' else signal.SIGTERM)
    root, config = checked_root(root, config['token'])
    restore_code(root)
    atomic(root / 'watchdog.json', {'status': 'RECOVERED', 'scope': 'SYNTHETIC', 'code_only': True})
    atomic(root / 'result.json', {'status': 'FAIL', 'scope': 'SYNTHETIC', 'reason': 'controller terminated or deadline', 'watchdog_recovered': True})
    atomic(root / 'done.json', {'success': False})


def rehearse(parent, fault, timeout):
    require(fault in FAULTS and 0 < timeout <= 10, 'invalid simulation options')
    raw = Path(parent).absolute();temp = Path(tempfile.gettempdir()).resolve()
    require(not raw.is_symlink() and raw.resolve() == raw and temp in raw.parents, 'parent must be a private temporary directory')
    require(raw.is_dir() and not any(raw.iterdir()), 'parent must exist and be empty')
    if os.name == 'posix':require(raw.stat().st_uid == os.getuid() and raw.stat().st_mode & 0o077 == 0, 'parent must be owned and private (0700)')
    root = Path(tempfile.mkdtemp(prefix='bms-operator-synthetic-', dir=raw)).resolve()
    root.chmod(0o700)
    token = secrets.token_hex(32)
    config = {'kind': 'BMS_SYNTHETIC_ONLY_V1', 'token': token, 'fault': fault, 'deadline': time.time()+180, 'controller_pid': os.getpid(), 'controller_birth': process_birth(os.getpid())}
    atomic(root / MARKER, config)
    source = root / 'source';source.mkdir()
    (source / 'code.txt').write_text(BASE)
    (source / 'business.json').write_text('["baseline"]')
    (source / 'file.txt').write_text('baseline file\n')
    (root / 'cron.txt').write_text(CRON)
    print(json.dumps({'scope': 'SYNTHETIC', 'sandbox_name': root.name}), flush=True)
    flags = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP if os.name == 'nt' else 0
    watch = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '_watchdog', '--root', str(root),
                              '--token', token, '--pid', str(os.getpid())], stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                             start_new_session=os.name == 'posix', creationflags=flags)
    report = {'scope': 'SYNTHETIC', 'status': 'FAIL', 'phases': [], 'not_covered': ['Moodle', 'real database', 'production', 'network isolation']}
    try:
        checked_root(root, token)
        require(shutil.disk_usage(root).free >= (2**63 if fault == 'space' else 1024*1024), 'insufficient fixture space')
        require(fault != 'time', 'expired synthetic window')
        if fault == 'source':(source / 'code.txt').write_text('CORRUPTED')
        freeze(root)
        try:child(root, token, 'capture', timeout)
        finally:resume(root)
        report['phases'].append('capture')
        if fault == 'wrong_hash':(root / 'backup.sha256').write_text('0'*64)
        for name in PHASES[1:]:
            if name == 'smoke':
                (root / 'clone/business.json').write_text('["baseline", "new-business-record"]')
                (root / 'clone/file.txt').write_text('baseline file\nnew user file\n')
                if fault == 'controller_pause':
                    freeze(root)
                    atomic(root / 'kill-ready.json', {'scope': 'SYNTHETIC'})
                    time.sleep(40)
            child(root, token, name, timeout)
            report['phases'].append(name)
        require((root / 'source/code.txt').read_text() == BASE, 'source changed')
        report['status'] = 'PASS'
    except Exception as error:
        report['reason'] = str(error)
        restore_code(root)
    finally:
        resume(root)
        atomic(root / 'result.json', report)
        atomic(root / 'done.json', {'success': report['status'] == 'PASS'})
        watch.wait(timeout=5)
    return 0 if report['status'] == 'PASS' else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    public = sub.add_parser('rehearse');public.add_argument('--parent', required=True)
    public.add_argument('--fault', choices=FAULTS, default='none');public.add_argument('--phase-timeout', type=float, default=5)
    for kind in ('_phase','_watchdog'):
        internal = sub.add_parser(kind);internal.add_argument('--root', required=True);internal.add_argument('--token', required=True)
        if kind == '_phase':internal.add_argument('--phase', choices=PHASES, required=True)
        else:internal.add_argument('--pid', type=int, required=True)
    args = parser.parse_args()
    if args.command == 'rehearse':return rehearse(args.parent, args.fault, args.phase_timeout)
    root, config = checked_root(args.root, args.token)
    if args.command == '_phase':phase(root, config, args.phase)
    else:watchdog(root, config, args.pid)
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
