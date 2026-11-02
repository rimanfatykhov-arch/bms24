#!/usr/bin/env python3
"""Compatibility entrypoint: the newer comparison contract supersedes old fixtures."""
from pathlib import Path
import runpy
runpy.run_path(str(Path(__file__).with_name('test_rc30_compare_1868.py')), run_name='__main__')
