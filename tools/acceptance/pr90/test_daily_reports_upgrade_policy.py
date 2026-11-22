import copy
import unittest
from daily_reports_upgrade_policy import NEW, upgraded, fresh_equal, unchanged, switch_preserves


def fixture():
    table='local_kbacompany_task';schema={'columns':{'id':{'type':'bigint'}},'indexes':{}}
    before={'version':2026111100,'enabled':False,'tables':[table],'schemas':{table:schema},
            'rows':{table:{'count':1,'sha256':'original'}}}
    after=copy.deepcopy(before);after['version']=2026111601
    for n in sorted(NEW):
        after['tables'].append(n);after['schemas'][n]=copy.deepcopy(schema);after['rows'][n]={'count':0,'sha256':'empty'}
    return before,after


class UpgradePolicyTest(unittest.TestCase):
    def test_additive_upgrade_and_fresh_schema(self):
        before,after=fixture();upgraded(before,after);fresh_equal(after,after);unchanged(after,after)

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
