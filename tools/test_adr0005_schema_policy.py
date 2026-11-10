"""Negative contracts for ADR0005 evidence; synthetic data, no Moodle or database."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

import adr0005_schema_policy as policy


def evidence():
    tables = {}
    physical = {'db_prefix': 'mdl_', 'tables': {}, 'columns': {}, 'indexes': {}}
    for table, stem, column in policy.EXCEPTIONS:
        logical = {'unique': False, 'columns': [column]}
        tables[table] = {'columns': {column: {'type': 'bigint'}}, 'indexes': [logical]}
        physical['tables'][table] = {'engine': 'InnoDB', 'collation': 'utf8mb4_unicode_ci'}
        physical['columns'][table + '.' + column] = {'type': 'bigint', 'default': None, 'nullable': False}
        physical['indexes'][table] = {'mdl_' + stem + '_ix': [{'nonunique': 1, 'sequence': 1,
            'column': column, 'subpart': None, 'type': 'BTREE', 'collation': 'A', 'nullable': ''}]}
    tables['local_kbacorp_devplan'] = {'columns': {'status': {'type': 'varchar(20)', 'default': 'draft'}}, 'indexes': []}
    tables['local_kbacorp_member']['columns']['isemployee'] = {'type': 'tinyint', 'default': '0'}
    physical['tables']['local_kbacorp_devplan'] = {'engine': 'InnoDB', 'collation': 'utf8mb4_unicode_ci'}
    physical['indexes']['local_kbacorp_devplan'] = {}
    physical['columns']['local_kbacorp_member.isemployee'] = {'type': 'tinyint', 'default': '0', 'nullable': False}
    physical['columns']['local_kbacorp_devplan.status'] = {'type': 'varchar(20)', 'default': 'draft', 'nullable': False}
    fresh = {'versions': {'local_kbacompany': '2026111002', 'local_kbacorporate': '2026111004'},
             'tables': tables, 'physical': physical}
    after = copy.deepcopy(fresh)
    for table, stem, column in policy.EXCEPTIONS:
        after['tables'][table]['indexes'].append({'unique': False, 'columns': [column]})
        indexes = after['physical']['indexes'][table]
        indexes['mdl_' + stem + '2_ix'] = copy.deepcopy(indexes['mdl_' + stem + '_ix'])
    before = copy.deepcopy(after)
    before['versions'] = {'local_kbacompany': '2026101878', 'local_kbacorporate': '2026102613'}
    before['tables']['local_kbacorp_devplan']['columns']['status']['default'] = 'active'
    before['physical']['columns']['local_kbacorp_devplan.status']['default'] = 'active'
    model = {'status': 'PASS', 'differences': [], 'checked_columns': 2505, 'checked_index_rows': 1569}
    return [fresh, before, after, model]


class SchemaPolicyTest(unittest.TestCase):
    def test_common_extra_physical_copy_is_not_a_valid_fresh_reference(self):
        args=evidence()
        table,stem,_=policy.EXCEPTIONS[0]
        for side in (0,1,2):
            indexes=args[side]['physical']['indexes'][table]
            indexes['mdl_'+stem+'3_ix']=copy.deepcopy(indexes['mdl_'+stem+'_ix'])
        self.assertEqual('FAIL',policy.evaluate(*args)['status'])

    def test_layout_is_reported_but_historical_positions_must_be_preserved(self):
        args = evidence()
        key = 'local_kbacorp_member.isemployee'
        args[0]['physical']['columns'][key]['position'] = 3
        for side in (1, 2):
            args[side]['physical']['columns'][key]['position'] = 7
        result = policy.evaluate(*args)
        self.assertEqual('PASS', result['status'])
        self.assertEqual({'fresh':3,'upgraded':7}, result['historical_layout']['column_positions'][key])
        args[2]['physical']['columns'][key]['position'] = 8
        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_physical_definition_cannot_hide_behind_logical_snapshot(self):
        for key,value in [('type','char(20)'),('extra','GENERATED'),('collation','other'),('nullable',True)]:
            with self.subTest(key=key):
                args=evidence()
                for side in (1,2):
                    args[side]['physical']['columns']['local_kbacorp_devplan.status'][key]=value
                self.assertEqual('FAIL',policy.evaluate(*args)['status'])

    def test_exact_two_pairs_pass_without_mutating_input(self):
        args = evidence()
        original = copy.deepcopy(args)
        result = policy.evaluate(*args)
        self.assertEqual('PASS', result['status'])
        self.assertEqual('HOLD', result['deployment'])
        self.assertEqual(2, len(result['allowed_differences']))
        self.assertEqual(original, args)

    def test_each_physical_index_attribute_is_required(self):
        for table, stem, column in policy.EXCEPTIONS:
            for index in ['mdl_' + stem + '_ix', 'mdl_' + stem + '2_ix']:
                for side in (1, 2):
                    for key, value in [('nonunique', 0), ('sequence', 2), ('column', 'wrongcolumn'),
                                       ('subpart', 4), ('type', 'HASH'), ('collation', 'D'), ('nullable', 'YES')]:
                        with self.subTest(table=table, index=index, side=side, key=key):
                            args = evidence()
                            args[side]['physical']['indexes'][table][index][0][key] = value
                            self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_missing_or_renamed_base_and_historical_index_fail(self):
        for table, stem, _ in policy.EXCEPTIONS:
            for suffix in ['_ix', '2_ix']:
                for rename in (False, True):
                    with self.subTest(table=table, suffix=suffix, rename=rename):
                        args = evidence()
                        indexes = args[2]['physical']['indexes'][table]
                        removed = indexes.pop('mdl_' + stem + suffix)
                        if rename:
                            indexes['wrong_name'] = removed
                        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_third_historical_duplicate_is_not_an_exception(self):
        args = evidence()
        table, stem, column = policy.EXCEPTIONS[0]
        for side in (1, 2):
            args[side]['tables'][table]['indexes'].append({'unique': False, 'columns': [column]})
            indexes = args[side]['physical']['indexes'][table]
            indexes['mdl_' + stem + '3_ix'] = copy.deepcopy(indexes['mdl_' + stem + '_ix'])
        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_physical_third_duplicate_cannot_hide_behind_logical_snapshot(self):
        args = evidence()
        table, stem, _ = policy.EXCEPTIONS[0]
        # Logical snapshot is inconsistent: physical evidence must still block.
        for side in (1, 2):
            indexes = args[side]['physical']['indexes'][table]
            indexes['mdl_' + stem + '3_ix'] = copy.deepcopy(indexes['mdl_' + stem + '_ix'])
        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_fresh_cannot_claim_historical_duplicate(self):
        args = evidence()
        args[0] = copy.deepcopy(args[2])
        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_changed_compound_column_order_fails(self):
        args = evidence()
        table = policy.EXCEPTIONS[0][0]
        for side in (0, 1, 2):
            args[side]['tables'][table]['indexes'].append({'unique': True, 'columns': ['companyid', 'courseid']})
        args[2]['tables'][table]['indexes'][-1]['columns'].reverse()
        self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_defaults_and_types_fail_closed(self):
        for name, key, value in [('local_kbacorp_member.isemployee', 'default', '1'),
                                  ('local_kbacorp_devplan.status', 'default', 'active'),
                                  ('local_kbacorp_devplan.status', 'default', None),
                                  ('local_kbacorp_member.isemployee', 'type', 'bigint'),
                                  ('local_kbacorp_member.isemployee', 'nullable', True)]:
            with self.subTest(name=name, key=key):
                args = evidence()
                args[2]['physical']['columns'][name][key] = value
                self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_unexpected_table_engine_and_collation_fail(self):
        for change in ('extra', 'missing', 'engine', 'collation'):
            with self.subTest(change=change):
                args = evidence()
                tables = args[2]['physical']['tables']
                if change == 'extra':
                    tables['local_kbacorp_unapproved'] = {'engine': 'InnoDB'}
                elif change == 'missing':
                    tables.pop('local_kbacorp_course')
                else:
                    tables['local_kbacorp_course'][change] = 'unexpected'
                self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_wrong_versions_origin_prefix_or_model_fail(self):
        for side in (0, 1, 2):
            for plugin in ('local_kbacompany', 'local_kbacorporate'):
                args = evidence()
                args[side]['versions'][plugin] = '9999999999'
                self.assertEqual('FAIL', policy.evaluate(*args)['status'])
            args = evidence()
            args[side]['physical']['db_prefix'] = 'foreign_'
            self.assertEqual('FAIL', policy.evaluate(*args)['status'])
        self.assertEqual('FAIL', policy.evaluate(*evidence(), origin='unreviewed')['status'])
        for key, value in [('status', 'FAIL'), ('differences', ['unexpected']),
                           ('checked_columns', 2504), ('checked_index_rows', 1568)]:
            args = evidence()
            args[3][key] = value
            self.assertEqual('FAIL', policy.evaluate(*args)['status'])

    def test_cli_rejects_missing_and_malformed_evidence(self):
        for malformed in ({}, {'physical': None}, []):
            with self.subTest(malformed=malformed), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                args = evidence()
                args[2] = malformed
                command = [sys.executable, str(Path(policy.__file__))]
                for name, data in zip(('fresh', 'before', 'after', 'model'), args):
                    path = root / (name + '.json')
                    path.write_text(json.dumps(data), encoding='utf-8')
                    command.extend(['--' + name, str(path)])
                output = root / 'result.json'
                command.extend(['--output', str(output)])
                result = subprocess.run(command, capture_output=True, text=True)
                self.assertNotEqual(0, result.returncode)
                # A crash also rejects release, but callers require a structured FAIL report.
                self.assertTrue(output.exists(), result.stderr)
                self.assertEqual('FAIL', json.loads(output.read_text())['status'])


if __name__ == '__main__':
    unittest.main()
