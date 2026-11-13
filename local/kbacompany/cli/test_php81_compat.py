#!/usr/bin/env python3
"""Fixture tests for PHP 8.1 source compatibility gate."""
import importlib.util
from pathlib import Path
p = Path(__file__).with_name('check_php81_compat.py')
spec = importlib.util.spec_from_file_location('php81_gate', p)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
fixtures = [
    ('readonly class Demo {}', True),
    ('public const string FOO = "x";', True),
    ('public(set) string $name;', True),
    ('new moodle_url($url)->out(false);', True),
    ('(new moodle_url($url))->out(false);', False),
    ('class Sample { public function save(): void {} }', False),
]
for code, expect_error in fixtures:
    assert bool(gate.check_text('fixture.php', code)) is expect_error, code
print(f'PHP 8.1 gate fixture tests PASS: {len(fixtures)}')
