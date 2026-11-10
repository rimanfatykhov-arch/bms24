"""Exact owner-approved retained-index policy; never executes DDL or edits input."""
import argparse,copy,json
from pathlib import Path

ORIGIN='687c230595bce020fc001db91b3aac276f0fee7d'
EXCEPTIONS=(('local_kbacorp_course','locakbaccour_cou','courseid'),
            ('local_kbacorp_member','locakbacmemb_use','userid'))

def evaluate(fresh,before,after,model,origin=ORIGIN):
    if not all(isinstance(x,dict) for x in (fresh,before,after,model)):
        raise ValueError('Evidence roots must be objects')
    errors=[]
    if origin != ORIGIN or model.get('status')!='PASS' or model.get('differences')!=[] or model.get('checked_columns')!=2505 or model.get('checked_index_rows')!=1569:
        errors.append('unverified exact2613 origin')
    if before.get('versions')!={'local_kbacompany':'2026101878','local_kbacorporate':'2026102613'}:
        errors.append('wrong historical versions')
    if after.get('versions')!={'local_kbacompany':'2026111002','local_kbacorporate':'2026111004'} or fresh.get('versions')!=after.get('versions'):
        errors.append('wrong candidate versions')
    f=copy.deepcopy(fresh);a=copy.deepcopy(after)
    fp=f.pop('physical');ap=a.pop('physical');bp=before['physical']
    if fp['db_prefix']!='mdl_' or ap['db_prefix']!='mdl_' or bp['db_prefix']!='mdl_':
        errors.append('unexpected physical prefix')
    # The live plugin-table inventory must equal XMLDB: an extra/missing table is
    # not hidden by iterating only install.xml during the logical snapshot.
    for logical,physical in ((fresh,fp),(after,ap)):
        if set(logical['tables'])!=set(physical['tables']): errors.append('physical/XMLDB table inventory mismatch')
    allowed=[]
    for table,stem,column in EXCEPTIONS:
        base='mdl_'+stem+'_ix';extra='mdl_'+stem+'2_ix'
        spec=[{'nonunique':1,'sequence':1,'column':column,'subpart':None,'type':'BTREE','collation':'A','nullable':''}]
        for physical,label in ((bp,'before'),(ap,'after')):
            indexes=physical['indexes'].get(table,{})
            if indexes.get(base)!=spec or indexes.get(extra)!=spec:
                errors.append(label+': invalid retained index pair '+table)
        if extra in fp['indexes'].get(table,{}) or fp['indexes'].get(table,{}).get(base)!=spec:
            errors.append('fresh cannot use historical exception '+table)
        logical={'unique':False,'columns':[column]}
        fi=f['tables'][table]['indexes'];ai=a['tables'][table]['indexes']
        if fi.count(logical)!=1 or ai.count(logical)!=2:
            errors.append('index multiplicity mismatch '+table)
        else:
            ai.remove(logical) # Comparison copy ONLY; no mutation of DB or inputs.
        allowed.append({'table':table,'retained_index':extra,'definition':spec})
    if f!=a: errors.append('unapproved logical schema difference')
    # Corporate physical table/column/index metadata must be unchanged except
    # exactly the two approved defaults. BMS migrations retain their own gates.
    corp=lambda d:{k:v for k,v in d.items() if k.startswith('local_kbacorp_')}
    for category in ('tables','indexes'):
        if corp(bp[category])!=corp(ap[category]): errors.append('Corporate physical '+category+' changed')
    expected=copy.deepcopy(corp(bp['columns']))
    for name,target in [('local_kbacorp_member.isemployee','0'),('local_kbacorp_devplan.status','draft')]:
        expected[name]['default']=target
        if ap['columns'].get(name,{}).get('default')!=target: errors.append('wrong approved default '+name)
    if expected!=corp(ap['columns']): errors.append('unapproved Corporate physical column change')
    # Engine/collation and full table inventory must also agree fresh/upgrade.
    if fp['tables']!=ap['tables']: errors.append('fresh/upgrade physical table mismatch')
    # Compare every physical index, including name, uniqueness, prefix and type.
    # Only the two named historical copies are removed from this comparison copy.
    physicalindexes=copy.deepcopy(ap['indexes'])
    for table,stem,column in EXCEPTIONS:
        physicalindexes.get(table,{}).pop('mdl_'+stem+'2_ix',None)
    if fp['indexes']!=physicalindexes: errors.append('unapproved fresh/upgrade physical index difference')
    if fp['columns']!=ap['columns']: errors.append('unapproved fresh/upgrade physical column difference')
    return {'status':'FAIL' if errors else 'PASS','policy':'Accepted ADR-0005, exact2retained physical duplicates',
        'origin_sha':origin,'allowed_differences':allowed,'errors':errors,'deployment':'HOLD'}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    for name in ('fresh','before','after','model','output'): parser.add_argument('--'+name,type=Path,required=True)
    args=parser.parse_args()
    try:
        result=evaluate(*(json.loads(getattr(args,n).read_text()) for n in ('fresh','before','after','model')))
    except (KeyError,TypeError,ValueError,OSError,AttributeError) as error:
        result={'status':'FAIL','errors':['missing or malformed schema evidence: '+type(error).__name__]}
    args.output.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result));raise SystemExit(result['status']!='PASS')
