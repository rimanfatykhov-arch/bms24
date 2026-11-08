#!/usr/bin/env python3
"""Regression check for PHP 7.4 syntax of coordination redirect method chaining."""
from pathlib import Path
import re
s = (Path(__file__).resolve().parents[1] / 'battle_plan_week_coordination.php').read_text()
assert s.count("redirect((new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',") == 2
assert s.count("'protocolsaved'=>1]))->out(false)") == 2
assert not re.search(r"redirect\(new moodle_url\(", s)
print('PASS PHP 7.4 coordination redirect regression')
