#!/usr/bin/env python3
"""Read two BMS24 Moodle inventories and produce a local, read-only release gap report."""
import argparse
from decimal import Decimal, InvalidOperation
from pathlib import Path


def read_inventory(path: Path):
    lines = path.read_text(encoding='utf-8').splitlines()
    if not lines or lines[0] != 'FORMAT|bms24-inventory-v1':
        raise ValueError(f'{path}: wrong inventory format')
    if lines[-1] != 'END|bms24-inventory-v1':
        raise ValueError(f'{path}: incomplete inventory')
    info = {}
    plugins = {}
    for line in lines[1:-1]:
        cells = line.split('|')
        key = cells[0]
        if key == 'PLUGIN':
            if len(cells) != 7:
                raise ValueError(f'{path}: invalid plugin record')
            _, name, state, component, version, requires, release = cells
            if name in plugins:
                raise ValueError(f'{path}: duplicate {name}')
            plugins[name] = dict(state=state, component=component,
                                 version=version, requires=requires, release=release)
        elif key == 'MOODLE':
            if len(cells) != 4 or key in info:
                raise ValueError(f'{path}: invalid MOODLE record')
            info[key] = dict(version=cells[1], release=cells[2], branch=cells[3])
        elif key in {'ROOT', 'PHP_CLI', 'PHP_WEB', 'CONFIG', 'COLLECTED_AT_UTC'}:
            if len(cells) != 2 or key in info:
                raise ValueError(f'{path}: invalid {key} record')
            info[key] = cells[1]
        elif key != 'PLUGIN_FIELDS':
            raise ValueError(f'{path}: unexpected inventory field {key}')
    if 'MOODLE' not in info or len(plugins) < 8:
        raise ValueError(f'{path}: incomplete Moodle/plugin inventory')
    return info, plugins


def numeric(value):
    try:
        return Decimal(value)
    except InvalidOperation:
        return None


def escape(value):
    return str(value).replace('|', r'\|').replace('\n', ' ')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--production', required=True, type=Path)
    parser.add_argument('--staging', required=True, type=Path)
    args = parser.parse_args()
    prod, pp = read_inventory(args.production)
    stage, sp = read_inventory(args.staging)
    if pp.keys() != sp.keys():
        raise ValueError('Different audit manifests. Re-run with matching script versions.')
    print('# BMS24 — Production / staging inventory gap')
    print('\nRead-only inventory: no production settings or data were changed.\n')
    print('| Property | Production edu.kzn.mba | Staging test.bms24.online |')
    print('|---|---|---|')
    for key in ('MOODLE', 'PHP_CLI', 'PHP_WEB'):
        pv = prod[key]['release'] if key == 'MOODLE' else prod.get(key, 'UNKNOWN')
        sv = stage[key]['release'] if key == 'MOODLE' else stage.get(key, 'UNKNOWN')
        print(f'| {key} | {escape(pv)} | {escape(sv)} |')
    print('\n| Plugin | Production version | Staging version | Status |')
    print('|---|---|---|---|')
    flags = []
    prodcore = numeric(prod['MOODLE']['version'])
    for name in pp:
        a, b = pp[name], sp[name]
        if a['state'] == 'MISSING':
            status = 'Not installed on production'
        elif b['state'] == 'MISSING':
            status = 'Missing on staging; needs review'
            flags.append(f'{name}: staging has no plugin to test')
        elif a['version'] == b['version']:
            status = 'Version matches'
        else:
            status = 'Version differs; review changes'
        if a['state'] == 'PRESENT' and a['component'] != b['component'] and b['state'] == 'PRESENT':
            status += '; component mismatch'
            flags.append(f'{name}: plugin component mismatch')
        pversion, sversion = numeric(a['version']), numeric(b['version'])
        if a['state'] == b['state'] == 'PRESENT' and pversion is not None and sversion is not None and sversion < pversion:
            status += '; DOWNGRADE BLOCKED'
            flags.append(f'{name}: staging version {b["version"]} is older than production {a["version"]}')
        required = numeric(b['requires'])
        if prodcore is not None and required is not None and required > prodcore:
            status += '; STAGING CODE REQUIRES NEWER MOODLE CORE'
            flags.append(f'{name}: staging requires Moodle {b["requires"]} but production is {prod["MOODLE"]["version"]}')
        print(f'| `\${escape(name)}` | {escape(a["version"])} | {escape(b["version"])} | {escape(status)} |')
    print('\n## Release gates')
    if flags:
        print('\n**BLOCKERS / review:**')
        for flag in flags:
            print(f'- {flag}')
    else:
        print('\nNo static `requires` / component blockers detected. This does **not** mean ready to deploy.')
    print('\nBefore any installation: check production PHP web SAPI, plugin dependencies,')
    print('database upgrade path, backup restore test, authenticated UX/ACL tests and rollback plan.')
    print('Never copy staging config.php, moodledata or database to production.' )
if __name__ == '__main__':
    main()
