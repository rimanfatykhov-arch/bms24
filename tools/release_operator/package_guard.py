"""Pure, fail-closed package guards. Never operates on a live service."""
import hashlib
import json
import re
import stat
import zipfile
from pathlib import PurePosixPath

HEX = re.compile(r'^[0-9a-f]{64}$')

def require(ok, reason):
    if not ok:
        raise ValueError(reason)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def safe_path(name, archive=False):
    require(isinstance(name,str) and name and '\\' not in name and ':' not in name and '\x00' not in name,
            'unsafe file path')
    p=PurePosixPath(name)
    require(not p.is_absolute() and all(x not in ('','.', '..') for x in name.split('/')),
            'noncanonical file path')
    require(not any(part.endswith((' ','.')) for part in p.parts), 'ambiguous file path')
    if archive:
        require(len(p.parts)>1 and p.parts[0]=='kbacompany','unexpected component')
    return name

def valid_hash(value):
    require(isinstance(value,str) and HEX.fullmatch(value) is not None,'invalid SHA256')

def protected(name):
    return name=='version.php' or name=='db' or name.startswith('db/')

def validate_changes(before, after, policy):
    require(isinstance(policy,dict) and set(policy)=={'schema','candidate_head','changes'}
            and type(policy.get('schema')) is int and policy['schema']==1,'unreviewed policy schema')
    require(policy.get('candidate_head') and re.fullmatch('[0-9a-f]{40}',policy['candidate_head']), 'missing exact candidate SHA')
    require(isinstance(before,dict) and isinstance(after,dict),'invalid inventories')
    for inventory in (before,after):
        for name,value in inventory.items():safe_path(name);valid_hash(value)
    require(set(before)<=set(after),'file deletion forbidden')
    require('version.php' in before,'baseline version missing')
    for name in set(before)|set(after):
        if protected(name):require(name in before and name in after and before[name]==after[name],'version/schema drift')
    rows=policy.get('changes');require(isinstance(rows,list) and rows,'empty reviewed change policy')
    reviewed={}
    for row in rows:
        require(isinstance(row,dict) and set(row)=={'path','before','after'},'invalid change record')
        name=safe_path(row['path']);require(name not in reviewed,'duplicate reviewed path')
        require(not protected(name),'protected path in allowlist')
        if row['before'] is not None:valid_hash(row['before'])
        valid_hash(row['after']);require(row['before']!=row['after'],'unchanged allowlist record')
        reviewed[name]=row
    actual={name for name in after if before.get(name)!=after[name]}
    require(actual==set(reviewed),'actual changes differ from reviewed allowlist')
    for name,row in reviewed.items():
        require(before.get(name)==row['before'] and after[name]==row['after'],'reviewed before/after hash mismatch')
        require((row['before'] is None)==(name not in before),'addition must be explicit')
    return sorted(actual)

def validate_zip(path, manifest, expected_entries):
    require(isinstance(manifest,dict) and isinstance(manifest.get('entries'),list),'invalid manifest')
    rows=manifest['entries'];require(len(rows)==expected_entries,'manifest entry count mismatch')
    entries={}
    for row in rows:
        require(isinstance(row,dict) and set(row)=={'path','sha256','bytes'},'invalid manifest entry')
        require(type(row['bytes']) is int and row['bytes']>=0,'invalid entry size')
        name=safe_path(row['path'],True);valid_hash(row['sha256'])
        require(name.casefold() not in {n.casefold() for n in entries},'duplicate manifest path');entries[name]=row['sha256']
    with zipfile.ZipFile(path) as archive:
        infos=archive.infolist();names=[x.filename for x in infos]
        require(len(names)==len(set(names)),'duplicate ZIP path')
        require(set(names)==set(entries),'ZIP inventory mismatch')
        result={}
        for info in infos:
            name=safe_path(info.filename,True)
            mode=info.external_attr>>16
            require(not info.is_dir() and not stat.S_ISLNK(mode) and stat.S_IFMT(mode) in (0,stat.S_IFREG),'nonregular ZIP entry')
            require(not info.flag_bits & 1,'encrypted install package unsupported')
            data=archive.read(info);require(digest(data)==entries[name],'ZIP entry hash mismatch')
            require(len(data)==next(row['bytes'] for row in rows if row['path']==name),'ZIP entry size mismatch')
            result[name]=data
    return result
