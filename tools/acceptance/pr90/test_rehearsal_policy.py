"""Offline fail-closed policy tests; not a substitute for real Moodle rehearsal."""
import ast
import copy
import json
import sys
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).with_name('rehearsal.py')
TREE = ast.parse(SOURCE.read_text())
sys.path.insert(0,str(SOURCE.parents[2]/'ci/browser'))

def menu_report(mode='owned'):
    rows=[]
    for width in (320,360,390,480,482,720,768,1024,1280,1440):
        geometry={'pageWidth':width,'scrollWidth':width,'card':{'x':0,'y':0,'width':width,'height':200}}
        for kind in ('tools','actions'):
            rows.append({'width':width,'menu':kind,'status':'PASS','ordinary_click':'navigation and closure verified',
                'before':copy.deepcopy(geometry),'after':copy.deepcopy(geometry),
                'panel':{'x':8,'y':8,'width':200,'height':200}})
        rows.append({'width':width,'history':'PASS'})
    return {'status':'PASS','shell':mode,'failures':[],'rows':rows,'browser_events':[],
        'browser_contexts':[{'width':width,'events':[]} for width in (320,360,390,480,482,720,768,1024,1280,1440)],
        'loaded_css':[{'status':200,'sha256':'a'*64}] if mode=='owned' else []}

class RehearsalPolicyTest(unittest.TestCase):
    def test_discussion_panels_are_phase_bound_and_history_required(self):
        source=SOURCE.parents[1]/'discussion_button.py'
        tree=ast.parse(source.read_text())
        nodes=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name in ('require','validate_panels')]
        ns={};exec(compile(ast.Module(body=nodes,type_ignores=[]),str(source),'exec'),ns)
        validate=ns['validate_panels']
        for phase in ('baseline','rollback','candidate','reinstall'):
            for mode in ('owned','legacy'):
                recent=int(phase in ('baseline','rollback') and mode=='owned')
                good=[phase,mode,1,recent,True,200,True,1,2]
                validate(*good)
                for index,value in ((2,0),(2,2),(3,1-recent),(4,False),(5,500),(5,302),(6,False),(7,0),(8,0)):
                    bad=good.copy();bad[index]=value
                    with self.assertRaises(RuntimeError):validate(*bad)
        with self.assertRaises(RuntimeError):validate('unknown','owned',1,0,True,200,True,1,2)

    def test_menu_report_requires_exact_matrix_and_evidence(self):
        node=next(n for n in TREE.body if isinstance(n,ast.FunctionDef) and n.name=='validate_menu_report')
        ns={};exec(compile(ast.Module(body=[node],type_ignores=[]),str(SOURCE),'exec'),ns)
        validate=ns['validate_menu_report']
        for mode in ('owned','legacy'):validate(menu_report(mode),mode,'a'*64)
        mutations=[
            lambda r:r.update(rows=[{}]*30),
            lambda r:r['rows'].pop(),
            lambda r:r['rows'].__setitem__(1,copy.deepcopy(r['rows'][0])),
            lambda r:r.update(shell='legacy'),
            lambda r:r.update(status='FAIL'),
            lambda r:r.update(failures=['unexpected error']),
            lambda r:r.update(loaded_css=[]),
            lambda r:r['loaded_css'][0].update(sha256='b'*64),
            lambda r:r['loaded_css'][0].update(status=500),
            lambda r:r['rows'][0].update(status='FAIL'),
            lambda r:r['rows'][0].update(width=321),
            lambda r:r['rows'][2].update(history='NOT RUN'),
            lambda r:r['rows'][0]['after'].update(scrollWidth=900),
            lambda r:r['rows'][0]['after']['card'].update(width=100),
            lambda r:r['rows'][0]['panel'].update(x=1000),
            lambda r:r['rows'][0].pop('ordinary_click'),
            lambda r:r.pop('browser_events'),
            lambda r:r.pop('browser_contexts'),
            lambda r:r['browser_contexts'].pop(),
            lambda r:r['browser_contexts'].__setitem__(1,copy.deepcopy(r['browser_contexts'][0])),
            lambda r:r['browser_events'].append({'source':'requestfailed','url':'http://127.0.0.1:8080/script.js','failure':'net::ERR_FAILED','resource_type':'script'})]
        for mutate in mutations:
            report=menu_report();mutate(report)
            with self.assertRaises(RuntimeError):validate(report,'owned','a'*64)
        # A valid global abort+consequence pair must not launder a failure in another viewport.
        import contract
        url=contract.EXPECTED_BLOCKED_EXTERNAL[0]
        aborted={'source':'route_abort','url':url,'resource_type':'script','reason':contract.ABORT_REASON}
        consequence={'source':'requestfailed','url':url,'resource_type':'script','failure':contract.ALLOWED_ABORT_FAILURE}
        report=menu_report();report['browser_events']=[aborted,consequence]
        report['browser_contexts'][0]['events']=[aborted]
        report['browser_contexts'][1]['events']=[consequence]
        self.assertEqual([],contract.browser_event_violations(report['browser_events']))
        with self.assertRaisesRegex(RuntimeError,'context'):validate(report,'owned','a'*64)
        report['browser_contexts'][0]['events']=[aborted,consequence]
        report['browser_contexts'][1]['events']=[]
        validate(report,'owned','a'*64)
        report['browser_events']=[]
        with self.assertRaisesRegex(RuntimeError,'flat evidence differ'):validate(report,'owned','a'*64)

    def test_baseline_validator_pin_is_independent_of_product(self):
        origins=json.loads(SOURCE.with_name('origins.json').read_text())
        self.assertEqual(origins['baseline_validator'],'01af6405bcb7db73f67cb45fee837ba97b8c6ed3')
        self.assertNotEqual(origins['baseline_validator'],origins['main'])
        self.assertIn("BASE!='f34ab6ff0d2f67a56972e99c23d096ed77a9873c'",SOURCE.read_text())
        source=SOURCE.read_text()
        self.assertIn("validator/'tools/ci/browser_acceptance.py'",source)
        self.assertIn("actualvalidator!=BASELINE_VALIDATOR",source)
        self.assertNotIn("sys.executable,cand/'tools/ci/browser_acceptance.py'",source)

    def test_candidate_and_reinstall_share_mandatory_new_checks(self):
        smoke=next(n for n in TREE.body if isinstance(n,ast.FunctionDef) and n.name=='smoke')
        calls={n.func.id for n in ast.walk(smoke) if isinstance(n,ast.Call) and isinstance(n.func,ast.Name)}
        self.assertTrue({'write_checks','multiattachment_checks','menu_overlay_checks','role_checks','observed'}<=calls)
        source=SOURCE.read_text()
        self.assertIn("smoke('candidate')",source)
        self.assertIn("smoke('reinstall')",source)

    def test_missing_multi_result_is_fail_and_exitcode_reaches_contract(self):
        node=next(n for n in TREE.body if isinstance(n,ast.FunctionDef) and n.name=='multiattachment_checks')
        import subprocess,sys,shutil
        with tempfile.TemporaryDirectory() as tmp:
            base=Path(tmp);ev=base/'evidence';ev.mkdir();public=base/'public';public.mkdir()
            (ev/'actors.private.json').write_text('{}')
            seen=[]
            def check(obs,code):
                seen.append((obs,code));return {'required':{'status':'FAIL'}}
            fake=types.SimpleNamespace(multiattachment_checks=check,evaluate=lambda x:x)
            ns=dict(ev=ev,public=public,core=base,scripts=base,env={},json=json,sys=sys,shutil=shutil,subprocess=subprocess)
            exec(compile(ast.Module(body=[node],type_ignores=[]),str(SOURCE),'exec'),ns)
            with patch.dict(sys.modules,{'contract':fake}),patch.object(subprocess,'run',return_value=types.SimpleNamespace(returncode=17)):
                with self.assertRaisesRegex(RuntimeError,'required candidate'):
                    ns['multiattachment_checks']('candidate')
            self.assertEqual(seen,[(None,17)])
            self.assertEqual(json.loads((public/'candidate-multiattachments.json').read_text())['checks']['required']['status'],'FAIL')
            self.assertFalse((public/'actors.private.json').exists())

if __name__=='__main__':unittest.main()
