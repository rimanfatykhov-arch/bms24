#!/usr/bin/env python3
import unittest
from pathlib import Path
from check_workflow_policy import validate, WorkflowHold, WORKFLOW

class WorkflowPolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = WORKFLOW.read_text(encoding='utf-8')

    def test_actual_policy(self):
        validate(self.text)

    def assert_hold(self, text):
        with self.assertRaises(WorkflowHold):
            validate(text)

    def test_no_pr_trigger(self):
        self.assert_hold(self.text.replace('  pull_request:', '  workflow_dispatch:'))

    def test_no_target_trigger(self):
        self.assert_hold(self.text.replace('  pull_request:', '  pull_request_target:\n  pull_request:'))

    def test_no_write_token(self):
        self.assert_hold(self.text.replace('contents: read', 'contents: write'))

    def test_no_secret(self):
        self.assert_hold(self.text + '\n      - run: echo ${{ secrets.PROD_TOKEN }}\n')

    def test_no_ssh_deploy(self):
        self.assert_hold(self.text + '\n      - run: ssh root@production ./deploy.sh\n')

    def test_no_scp_deploy(self):
        self.assert_hold(self.text + '\n      - run: scp local.zip root@production:/var/www\n')

    def test_no_credentials(self):
        self.assert_hold(self.text + '\n        persist-credentials: true\n')

    def test_no_main_only(self):
        self.assert_hold(self.text.replace("github.event_name == 'pull_request' || ", ''))

    def test_no_zip_verify(self):
        self.assert_hold(self.text.replace('Build and verify deterministic candidate', 'Build candidate'))

    def test_no_date_gate(self):
        self.assert_hold(self.text.replace('tools/test_business_dates_1876.py','tools/no_date_test.py'))

    def test_no_wrong_build(self):
        self.assert_hold(self.text.replace('--expected-version 2026110301','--expected-version 2026101877'))

if __name__ == '__main__':
    unittest.main(verbosity=2)
