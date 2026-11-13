#!/usr/bin/env python3
"""Classifier safety: real diffs, unknown/mixed risk, executable translations."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from ci_profile import classify, inspect

class ProfileTest(unittest.TestCase):
    def test_sensitive_semantics_override_extension(self):
        for text in ['+ .upload {color:red}', '+ /* sesskey */', '+ @import "x";', '+ .x {display:none}']:
            self.assertEqual('FULL', classify('local/kbacompany/bms_ui/base.css', 'M', text)[0])

    def test_binary_and_mode_only_css_are_not_cosmetic(self):
        for patch in ['Binary files a/x.css and b/x.css differ', 'old mode 100644\nnew mode 100755', '']:
            self.assertEqual('FULL', classify('local/kbacompany/bms_ui/base.css', 'M', patch)[0])

    def test_php_translation_is_literal_only(self):
        path = 'local/kbacompany/lang/en/local_kbacompany.php'
        self.assertEqual('FAST', classify(path, 'M', "+$string['hello'] = 'Hello';")[0])
        for patch in ["+$string['hello'] = system('id');", "+$string['hello'] = 'Hello'; evil();", "+$string['upload'] = 'OK';"]:
            self.assertEqual('FULL', classify(path, 'M', patch)[0])

    def test_unknown_and_structural_fail_closed(self):
        for path in ['local/kbacompany/db/upgrade.php', 'local/kbacompany/lib.php', '.github/workflows/x.yml', 'unknown.txt']:
            self.assertEqual('FULL', classify(path, 'M', '+ harmless')[0])
        for status in ['A','D','T','R100']:
            self.assertEqual('FULL', classify('local/kbacompany/bms_ui/base.css', status, '+color:red')[0])

    def test_real_mixed_diff_and_rename(self):
        with tempfile.TemporaryDirectory() as tmp:
            previous = Path.cwd()
            try:
                os.chdir(tmp)
                def git(*args):
                    return subprocess.check_output(['git', *args], stderr=subprocess.DEVNULL).decode().strip()
                git('init'); git('config','user.email','ci@example.invalid'); git('config','user.name','CI')
                css=Path('local/kbacompany/bms_ui/base.css'); css.parent.mkdir(parents=True)
                css.write_text('.x {color: red;}\n'); Path('unknown.txt').write_text('a')
                git('add','.'); git('commit','-m','base'); base=git('rev-parse','HEAD')
                css.write_text('.x {color: blue;}\n'); git('add','.'); git('commit','-m','cosmetic')
                self.assertEqual('FAST', inspect(base,'HEAD')['recommendation'])
                Path('unknown.txt').write_text('b'); git('add','.'); git('commit','-m','mixed')
                result=inspect(base,'HEAD'); self.assertEqual('FULL',result['recommendation'])
                self.assertTrue(result['mandatory_full']); self.assertFalse(result['release_authorized'])
                css.rename(css.with_name('new.css')); git('add','.'); git('commit','-m','rename')
                self.assertEqual('FULL',inspect('HEAD~1','HEAD')['recommendation'])
            finally:
                os.chdir(previous)

if __name__ == '__main__': unittest.main()
