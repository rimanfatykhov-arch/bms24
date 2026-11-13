#!/usr/bin/env python3
"""Record actual wall/child CPU timings without recording command arguments."""
import argparse
import json
import os
import resource
import subprocess
import time
from pathlib import Path

if __name__ == '__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--label',required=True)
    p.add_argument('--output',type=Path,required=True)
    p.add_argument('command',nargs=argparse.REMAINDER)
    a=p.parse_args()
    if a.command[:1] == ['--']: a.command=a.command[1:]
    if not a.command: p.error('command required')
    before=resource.getrusage(resource.RUSAGE_CHILDREN)
    start=time.time(); tick=time.monotonic()
    result=subprocess.run(a.command)
    after=resource.getrusage(resource.RUSAGE_CHILDREN)
    row={'label':a.label,'started_epoch':start,'wall_seconds':time.monotonic()-tick,
         'child_cpu_seconds':after.ru_utime+after.ru_stime-before.ru_utime-before.ru_stime,
         'exit_code':result.returncode,'sha':os.environ.get('GITHUB_SHA'),'runner':os.environ.get('RUNNER_OS')}
    with a.output.open('a') as f: f.write(json.dumps(row)+'\n')
    raise SystemExit(result.returncode)
