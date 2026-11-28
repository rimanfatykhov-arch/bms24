import copy
import unittest
from daily_reports_upgrade_policy import ADR0010_TABLES, NEW, R5A_TABLES, upgraded, fresh_equal, unchanged, switch_preserves


def add_tables(before, version, tables):
    after = copy.deepcopy(before)
    after['version'] = version
    schema = before['schemas']['local_kbacompany_task']
    for name in sorted(tables):
        after['tables'].append(name)
        after['schemas'][name] = copy.deepcopy(schema)
        after['rows'][name] = {'count': 0, 'sha256': 'empty'}
    return after


def fixture():
    table='local_kbacompany_task';schema={'columns':{'id':{'type':'bigint'}},'indexes':{}}
    before={'version':2026111100,'enabled':False,'tables':[table],'schemas':{table:schema},
            'rows':{table:{'count':1,'sha256':'original'}}}
    return before, add_tables(before, 2026111601, NEW)


class UpgradePolicyTest(unittest.TestCase):
    def test_additive_upgrade_and_fresh_schema(self):
        before,after=fixture();upgraded(before,after);fresh_equal(after,after);unchanged(after,after)

    def test_r5c_adds_no_tables(self):
        r0, r1r2 = fixture()
        r1r2['rows']['local_kbacompany_dreport'] = {'count': 1, 'sha256': 'submitted'}
        r5a = add_tables(r1r2, 2026112400, ADR0010_TABLES | R5A_TABLES)
        r5c = copy.deepcopy(r5a); r5c['version'] = 2026112401
        upgraded(r5a, r5c)
        upgraded(r1r2, add_tables(r1r2, 2026112401, ADR0010_TABLES | R5A_TABLES))
        fresh_equal(add_tables(r0, 2026112401, NEW | ADR0010_TABLES | R5A_TABLES), r5c)
        extra = add_tables(r5a, 2026112401, {'local_kbacompany_dreportrem'})
        with self.assertRaises(RuntimeError):
            upgraded(r5a, extra)
        missing = copy.deepcopy(r5a)
        missing['tables'].remove('local_kbacompany_dreportabs')
        with self.assertRaises(RuntimeError):
            upgraded(missing, add_tables(missing, 2026112401, set()))

    def test_r5a_upgrade_from_every_supported_origin(self):
        r0, r1r2 = fixture()
        r1r2['rows']['local_kbacompany_dreport'] = {'count': 1, 'sha256': 'submitted'}
        candidate = add_tables(r1r2, 2026112400, ADR0010_TABLES | R5A_TABLES)
        upgraded(r1r2, candidate)
        fresh = add_tables(r0, 2026112400, NEW | ADR0010_TABLES | R5A_TABLES)
        upgraded(r0, fresh)
        fresh_equal(fresh, candidate)
        adr0010 = add_tables(r1r2, 2026112301, ADR0010_TABLES)
        upgraded(adr0010, add_tables(adr0010, 2026112400, R5A_TABLES))
        for name in sorted(R5A_TABLES):
            bad = copy.deepcopy(candidate)
            bad['rows'][name]['count'] = 1
            with self.subTest(table=name), self.assertRaises(RuntimeError):
                upgraded(r1r2, bad)
        missing = copy.deepcopy(adr0010)
        missing['tables'].remove('local_kbacompany_dreportcc')
        with self.assertRaises(RuntimeError):
            upgraded(missing, add_tables(missing, 2026112400, R5A_TABLES))

    def test_adr0010_upgrade_from_both_supported_origins(self):
        r0, r1r2 = fixture()
        r1r2['rows']['local_kbacompany_dreport'] = {'count': 1, 'sha256': 'submitted'}
        candidate = add_tables(r1r2, 2026112301, ADR0010_TABLES)
        upgraded(r1r2, candidate)
        fresh = add_tables(r0, 2026112301, NEW | ADR0010_TABLES)
        upgraded(r0, fresh)
        fresh_equal(fresh, candidate)
        for name in sorted(ADR0010_TABLES):
            bad = copy.deepcopy(candidate)
            bad['rows'][name]['count'] = 1
            with self.subTest(table=name), self.assertRaises(RuntimeError):
                upgraded(r1r2, bad)
        bad = copy.deepcopy(candidate)
        bad['rows']['local_kbacompany_dreport']['sha256'] = 'lost'
        with self.assertRaises(RuntimeError):
            upgraded(r1r2, bad)
        bad = copy.deepcopy(candidate)
        bad['enabled'] = True
        with self.assertRaises(RuntimeError):
            upgraded(r1r2, bad)
        missing = copy.deepcopy(r1r2)
        missing['tables'].remove('local_kbacompany_dreport')
        with self.assertRaises(RuntimeError):
            upgraded(missing, candidate)

    def test_missing_extra_table_wrong_version_or_old_data_change_fail(self):
        for what in ['missing','extra','version','data','schema','backfill','empty']:
            before,after=fixture()
            if what=='missing':after['tables'].remove(next(iter(NEW)))
            if what=='extra':after['tables'].append('unexpected')
            if what=='version':before['version']=2026101878
            if what=='data':after['rows']['local_kbacompany_task']['sha256']='changed'
            if what=='schema':after['schemas']['local_kbacompany_task']['columns']['x']={}
            if what=='backfill':after['rows'][next(iter(NEW))]['count']=1
            if what=='empty':before['rows']={}
            with self.subTest(what=what),self.assertRaises(RuntimeError):upgraded(before,after)

    def test_fresh_difference_and_repeat_data_loss_fail(self):
        _,after=fixture();bad=copy.deepcopy(after);bad['schemas'][next(iter(NEW))]['columns']['id']['type']='varchar'
        with self.assertRaises(RuntimeError):fresh_equal(after,bad)
        bad=copy.deepcopy(after);bad['rows']['local_kbacompany_task']['sha256']='lost'
        with self.assertRaises(RuntimeError):unchanged(after,bad)

    def test_switch_needs_existing_data_and_exact_round_trip(self):
        _,before=fixture();before['enabled']=True;disabled=copy.deepcopy(before);disabled['enabled']=False
        with self.assertRaises(RuntimeError):switch_preserves(before,disabled,before)
        before['rows'][next(iter(NEW))]['count']=2;disabled=copy.deepcopy(before);disabled['enabled']=False
        switch_preserves(before,disabled,before)
        disabled['rows'][next(iter(NEW))]['count']=0
        with self.assertRaises(RuntimeError):switch_preserves(before,disabled,before)


if __name__=='__main__':unittest.main()
