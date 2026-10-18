#!/usr/bin/env python3
from pathlib import Path
import argparse, re, sys

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'local' / 'kbacompany'
VERSION = PLUGIN / 'version.php'
UPGRADE = PLUGIN / 'db' / 'upgrade.php'

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--summary', required=True)
    args = ap.parse_args()
    vtext = VERSION.read_text(encoding='utf-8')
    vm = re.search(r'(\\$plugin->version\\s*=\\s*)(\\d+)(\\s*;)', vtext)
    rm = re.search(r"\\$plugin->release\\s*=\\s*'([^']+)'\\s*;", vtext)
    if not vm or not rm:
        raise RuntimeError('Cannot parse version.php')
    current = int(vm.group(2))
    new = current + 1
    release = rm.group(1)
    vtext = vtext[:vm.start(2)] + str(new) + vtext[vm.end(2):]
    VERSION.write_text(vtext, encoding='utf-8')

    utext = UPGRADE.read_text(encoding='utf-8')
    if str(new) in utext:
        raise RuntimeError('Upgrade version already exists: ' + str(new))
    marker = '\\n    return true;\\n}'
    if marker not in utext:
        raise RuntimeError('Cannot find final return true in db/upgrade.php')
    safe = ' '.join(args.summary.strip().split())
    block = ('\\n    if ($oldversion < ' + str(new) + ') {\\n'
             '        // Release checkpoint: ' + safe + '\\n'
             '        // No DDL is performed by this checkpoint itself.\\n'
             "        upgrade_plugin_savepoint(true, " + str(new) + ", 'local', 'kbacompany');\\n"
             '    }\\n')
    UPGRADE.write_text(utext.replace(marker, block + marker, 1), encoding='utf-8')

    safe_release = release.replace('.', '_').replace('-', '_')
    notes = PLUGIN / ('RELEASE_NOTES_' + safe_release + '_BUILD_' + str(new) + '.md')
    notes.write_text(
        '# ' + release + ' build ' + str(new) + '\\n\\n'
        '## Summary\\n' + args.summary.strip() + '\\n\\n'
        '## Automated release preparation\\n'
        '- Previous build: `' + str(current) + '`\\n'
        '- New build: `' + str(new) + '`\\n'
        '- version.php bumped automatically.\\n'
        '- Moodle upgrade savepoint added automatically.\\n\\n'
        '## Required acceptance\\n'
        '- GitHub static CI\\n'
        '- Moodle install/upgrade\\n'
        '- Browser/live acceptance appropriate to the feature\\n'
        '- Security/RBAC/company-isolation acceptance when applicable\\n',
        encoding='utf-8')
    print('release=' + release)
    print('previous_build=' + str(current))
    print('new_build=' + str(new))
    print('notes=' + notes.relative_to(ROOT).as_posix())
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
