import copy
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from preflight import inspect


def request():
    return {
        'schema': 'bms24.release_operator.request.v0',
        'release_id': 'BMS24-R1R2-20261119',
        'repository': 'rimanfatykhov-arch/bms24',
        'environment': 'production',
        'mode': 'read_only_preflight',
        'production_baseline': {'source_sha': '9' * 40, 'plugin_version': 2026111100},
        'candidate': {'pr_number': 124, 'head_sha': 'a' * 40, 'ci_checkout_sha': 'b' * 40,
                      'plugin_version': 2026111601, 'zip_sha256': 'c' * 64, 'manifest_sha256': 'd' * 64},
        'migration': {'required': True, 'expected_new_tables': 5,
                      'recovery': 'feature_off_forward_fix_or_separately_approved_restore'},
        'authorization': {'owner_approval': 'NOT_GRANTED', 'deployment_window': None},
    }


class ReadOnlyPreflightTest(unittest.TestCase):
    def test_valid_request_always_hold_and_does_not_deploy(self):
        result = inspect(request())
        self.assertEqual(result['status'], 'HOLD')
        self.assertFalse(result['deployable'])
        self.assertFalse(result['evidence_verified'])
        self.assertEqual(result['actions_executed'], [])
        self.assertGreaterEqual(len(result['blockers']), 5)

    def test_unknown_fields_or_credential_material_rejected(self):
        for field in ('ssh_password', 'private_key', 'allow_deploy', 'workflow_token'):
            candidate = request()
            candidate[field] = 'anything'
            with self.subTest(field=field):
                self.assertEqual(inspect(candidate)['status'], 'INVALID')

    def test_bad_sha_and_bad_repository_rejected(self):
        for field, bad in [('head_sha', 'NOT-A-SHA'), ('zip_sha256', '0' * 63),
                           ('manifest_sha256', 'A' * 64)]:
            candidate = request()
            candidate['candidate'][field] = bad
            with self.subTest(field=field):
                self.assertEqual(inspect(candidate)['status'], 'INVALID')
        candidate = request()
        candidate['repository'] = 'someone/fork'
        self.assertEqual(inspect(candidate)['status'], 'INVALID')

    def test_no_executable_authorization(self):
        for approval in ('APPROVED', True, None):
            candidate = request()
            candidate['authorization']['owner_approval'] = approval
            with self.subTest(approval=approval):
                self.assertEqual(inspect(candidate)['status'], 'INVALID')
        candidate = request()
        candidate['mode'] = 'deploy'
        self.assertEqual(inspect(candidate)['status'], 'INVALID')

    def test_no_code_only_downgrade_with_schema(self):
        candidate = request()
        candidate['migration']['recovery'] = 'code_only_if_schema_unchanged'
        self.assertEqual(inspect(candidate)['status'], 'INVALID')
        candidate = request()
        candidate['candidate']['plugin_version'] = 2026111100
        self.assertEqual(inspect(candidate)['status'], 'INVALID')

    def test_versions_and_types_and_migration_consistency(self):
        candidate = request()
        candidate['candidate']['plugin_version'] = 2026110900
        self.assertEqual(inspect(candidate)['status'], 'INVALID')
        candidate = request()
        candidate['migration']['required'] = False
        self.assertEqual(inspect(candidate)['status'], 'INVALID')
        candidate = request()
        candidate['candidate']['pr_number'] = True
        self.assertEqual(inspect(candidate)['status'], 'INVALID')

    def test_valid_code_only_is_still_hold(self):
        candidate = request()
        candidate['migration'] = {'required': False, 'expected_new_tables': 0,
                                  'recovery': 'code_only_if_schema_unchanged'}
        candidate['candidate']['plugin_version'] = 2026111100
        self.assertEqual(inspect(candidate)['status'], 'HOLD')


if __name__ == '__main__':
    unittest.main()
