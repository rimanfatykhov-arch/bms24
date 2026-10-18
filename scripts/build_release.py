from pathlib import Path
import re, shutil, subprocess, sys, tempfile, zipfile, hashlib, json
from datetime import datetime

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/"local"/"kbacompany"
OUTROOT=ROOT/"release"

EXCLUDE_DIRS={".git",".github",".idea",".vscode","node_modules","vendor","__pycache__","release"}
EXCLUDE_FILES={"config.php",".env",".env.local",".env.production"}
EXCLUDE_SUFFIXES={".tmp",".bak",".swp",".swo",".log",".sql"}

def run(cmd, check=True):
    p=subprocess.run(cmd,cwd=ROOT,text=True,capture_output=True)
    if check and p.returncode:
        raise RuntimeError(p.stdout+"\n"+p.stderr)
    return p

def parse_version():
    t=(PLUGIN/"version.php").read_text(encoding="utf-8",errors="replace")
    v=re.search(r"\$plugin->version\s*=\s*(\d+)\s*;",t)
    r=re.search(r"\$plugin->release\s*=\s*'([^']+)'\s*;",t)
    if not v or not r: raise RuntimeError("Cannot parse version.php")
    return v.group(1),r.group(1)

def excluded(rel):
    if any(x in EXCLUDE_DIRS for x in rel.parts): return True
    if rel.name in EXCLUDE_FILES: return True
    if rel.suffix.lower() in EXCLUDE_SUFFIXES: return True
    n=rel.name.lower()
    return any(x in n for x in ["ftp_pass","ftp-pass","credential","webhook_secret","webhook-token"])

def copy_plugin(dst):
    for p in PLUGIN.rglob("*"):
        rel=p.relative_to(PLUGIN)
        if excluded(rel): continue
        q=dst/rel
        if p.is_dir(): q.mkdir(parents=True,exist_ok=True)
        elif p.is_file():
            q.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(p,q)

def sha256(p):
    h=hashlib.sha256()
    with p.open("rb") as f:
        for b in iter(lambda:f.read(1024*1024),b""): h.update(b)
    return h.hexdigest()

def zip_tree(src,zpath,rootname):
    with zipfile.ZipFile(zpath,"w",zipfile.ZIP_DEFLATED) as z:
        for p in sorted(src.rglob("*")):
            if p.is_file(): z.write(p,(Path(rootname)/p.relative_to(src)).as_posix())

def git(cmd):
    try:return run(["git"]+cmd,check=False).stdout.strip()
    except:return ""

def main():
    version,release=parse_version()
    dirty=bool(git(["status","--porcelain"]))
    if dirty and "--allow-dirty" not in sys.argv:
        print("ERROR: working tree is dirty. Commit/stash first or use --allow-dirty intentionally.")
        return 2

    if "--skip-preflight" not in sys.argv:
        shell=shutil.which("powershell") or shutil.which("pwsh")
        if shell and (ROOT/"scripts"/"preflight.ps1").exists():
            p=subprocess.run([shell,"-ExecutionPolicy","Bypass","-File",str(ROOT/"scripts"/"preflight.ps1")],cwd=ROOT)
            if p.returncode: return p.returncode

    tag=f"{release}_build{version}".replace("/","_")
    out=OUTROOT/tag
    if out.exists(): shutil.rmtree(out)
    out.mkdir(parents=True)

    with tempfile.TemporaryDirectory() as td:
        stage=Path(td)/"kbacompany"; stage.mkdir()
        copy_plugin(stage)
        install=out/f"INSTALL_local_kbacompany_{tag}.zip"
        zip_tree(stage,install,"kbacompany")

    source=out/f"SOURCE_local_kbacompany_{tag}.zip"
    with zipfile.ZipFile(source,"w",zipfile.ZIP_DEFLATED) as z:
        for p in sorted(PLUGIN.rglob("*")):
            rel=p.relative_to(ROOT)
            if p.is_file() and not excluded(rel): z.write(p,rel.as_posix())

    changed=git(["diff","--name-status","main...HEAD"]) or git(["diff","--name-status"])
    (out/"CHANGED_FILES.md").write_text("# Changed files\n\n```text\n"+(changed or "No diff information available.")+"\n```\n",encoding="utf-8")

    branch=git(["branch","--show-current"])
    commit=git(["rev-parse","HEAD"])
    manifest={"component":"local_kbacompany","release":release,"version":version,
              "generated_at":datetime.now().astimezone().isoformat(),"branch":branch,"commit":commit}
    (out/"release-manifest.json").write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")

    notes=f"""# Release Notes — {release} build {version}

- Branch: `{branch}`
- Commit: `{commit}`

## Scope
Fill from approved task.

## DB / ACL / Privacy / Company isolation
Verify explicitly before staging.

## QA
- Local preflight: run by builder unless skipped.
- Moodle DB upgrade/install: NOT RUN by builder.
- PHPUnit/Behat: NOT RUN by builder.
- Browser/mobile: NOT RUN by builder.
- Cron/concurrency: NOT RUN by builder.
- Production acceptance: NOT RUN.

Do not mark production ready before required live gates pass.
"""
    (out/"RELEASE_NOTES.md").write_text(notes,encoding="utf-8")

    hashes=[]
    for p in sorted(out.iterdir()):
        if p.is_file() and p.name!="SHA256SUMS.txt": hashes.append(f"{sha256(p)}  {p.name}")
    (out/"SHA256SUMS.txt").write_text("\n".join(hashes)+"\n",encoding="utf-8")

    print(f"Release kit: {out}")
    print(f"INSTALL: {install.name}")
    print(f"SOURCE: {source.name}")
    return 0

if __name__=="__main__":
    raise SystemExit(main())
