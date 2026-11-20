#!/usr/bin/env python3
"""V1h: real MySQL/Percona CI-only backup/restore and SIGKILL after DDL.

ONLY newly generated, synthetic disposable DBs; no Moodle/production access.
This exercises genuine MySQL DDL and SQL dump/restore under process failure,
NOT a full site migration or continuous writer pause. Always HOLD.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import signal
import subprocess
import sys

DB_RE = re.compile(r"bmsci_fault_[a-f0-9]{12}(?:_restore)?\Z")
SYNTHETIC = "isolated-ci-only"


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def guard():
    env = os.environ
    require(env.get("GITHUB_ACTIONS") == "true" and
            env.get("CI") == "true" and
            env.get("BMS_FAULT_REHEARSAL") == "1",
            "disposable GitHub Actions opt-in required")
    require(env.get("DBHOST") == "127.0.0.1" and
            env.get("DBPORT") == "3306" and
            env.get("DBUSER") == "root" and
            env.get("DBPASS") == SYNTHETIC,
            "only the isolated CI localhost database is permitted")
    root = env.get("RUNNER_TEMP", "")
    require(root.startswith("/home/runner/work/_temp") and
            Path(root).is_dir() and ".." not in Path(root).parts,
            "private GitHub-hosted runner temp required")
    return Path(root)


def safe_db(name):
    require(isinstance(name, str) and DB_RE.fullmatch(name) is not None,
            "only synthetic randomized databases allowed")
    return name


def command(args, *, input_bytes=None, output_limit=1024*1024):
    require(args[0] in ("/usr/bin/mysql", "/usr/bin/mysqldump"),
            "unapproved executable")
    env = dict(os.environ, MYSQL_PWD=SYNTHETIC)
    try:
        p = subprocess.run(args, input=input_bytes, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, env=env, timeout=50)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError("synthetic SQL command unavailable") from exc
    require(p.returncode == 0 and len(p.stdout) <= output_limit,
            "synthetic SQL command failed")
    return p.stdout


def mysql(sql, db=None):
    args = ["/usr/bin/mysql", "--no-defaults", "-h127.0.0.1", "-P3306",
            "-uroot", "-N", "-B"]
    if db is not None:
        args += ["-D", safe_db(db)]
    return command(args + ["-e", sql])


def create(db):
    mysql("CREATE DATABASE " + safe_db(db) +
          " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")


def remove(db):
    mysql("DROP DATABASE IF EXISTS " + safe_db(db))


def setup(db):
    mysql("CREATE TABLE retained(id INT PRIMARY KEY, val VARCHAR(32) NOT NULL) ENGINE=InnoDB", db)
    mysql("INSERT INTO retained VALUES (1,'alpha'),(2,'beta'),(3,'gamma')", db)
    mysql("CREATE TABLE history(id INT PRIMARY KEY, retainedid INT NOT NULL) ENGINE=InnoDB", db)
    mysql("INSERT INTO history VALUES (10,1),(20,2)", db)


def fingerprint(db):
    rows = mysql("SELECT id,val FROM retained ORDER BY id", db)
    history = mysql("SELECT id,retainedid FROM history ORDER BY id", db)
    return hashlib.sha256(rows + b"\0" + history).hexdigest()


def table_exists(db):
    return mysql("SELECT COUNT(*) FROM information_schema.TABLES "
                 "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='new_report'", db).strip() == b"1"


def fsync_file(path, value):
    require(value in ("PRE_DDL", "DDL_INTENT"), "unexpected DDL marker")
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        data = (value + "\n").encode()
        require(os.write(fd, data) == len(data), "incomplete marker")
        os.fsync(fd)
    finally:
        os.close(fd)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def child(stage, db, marker):
    root = guard()
    safe_db(db)
    require(stage in ("before", "after"), "unknown fault phase")
    require(marker.parent.resolve() == root.resolve() and
            marker.name.startswith("bmsci-fault-"),
            "marker outside disposable runner")
    fsync_file(marker, "PRE_DDL" if stage == "before" else "DDL_INTENT")
    if stage == "after":
        mysql("CREATE TABLE new_report(id INT PRIMARY KEY) ENGINE=InnoDB", db)
    os.kill(os.getpid(), signal.SIGKILL)


def kill_and_verify(stage, db, marker):
    args = [sys.executable, str(Path(__file__).resolve()),
            "--child", stage, db, str(marker)]
    p = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=50)
    require(p.returncode == -signal.SIGKILL, "child did not die from SIGKILL")
    expected = "PRE_DDL" if stage == "before" else "DDL_INTENT"
    require(marker.read_text() == expected + "\n", "durable DDL marker not recovered")
    exists = table_exists(db)
    require(exists == (stage == "after"), "actual DDL phase was not observed")
    return {"stage":stage, "sigkill":True, "durable_intent":expected,
            "actual_new_table":exists, "auto_code_downgrade_allowed":False,
            "automatic_restore_allowed":False}


def dump(db, path):
    args = ["/usr/bin/mysqldump", "--no-defaults", "-h127.0.0.1", "-P3306",
            "-uroot", "--single-transaction", "--skip-lock-tables",
            "--no-tablespaces", "--skip-comments", safe_db(db)]
    data = command(args, output_limit=10*1024*1024)
    require(len(data) > 100, "empty SQL dump")
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        require(os.write(fd, data) == len(data), "incomplete SQL dump")
        os.fsync(fd)
    finally:
        os.close(fd)
    return {"bytes": len(data), "sha256":hashlib.sha256(data).hexdigest()}


def restore(db, path):
    args = ["/usr/bin/mysql", "--no-defaults", "-h127.0.0.1", "-P3306",
            "-uroot", "-D", safe_db(db)]
    data = path.read_bytes()
    command(args, input_bytes=data)


def run():
    root = guard()
    token = secrets.token_hex(6)
    original = "bmsci_fault_" + token
    restored = original + "_restore"
    marker = root / ("bmsci-fault-" + token)
    sql = root / ("bmsci-private-dump-" + token)
    created = []
    try:
        create(original)
        created.append(original)
        setup(original)
        expected = fingerprint(original)
        backup = dump(original, sql)
        create(restored)
        created.append(restored)
        restore(restored, sql)
        require(fingerprint(restored) == expected and not table_exists(restored),
                "independent SQL restore differs from original")
        before = kill_and_verify("before", original, marker)
        require(fingerprint(original) == expected, "pre-DDL crash modified existing data")
        marker.unlink()
        after = kill_and_verify("after", original, marker)
        require(fingerprint(original) == expected and
                fingerprint(restored) == expected and
                not table_exists(restored), "post-DDL crash corrupted baseline/backup")
        return {
            "schema": "bms24.release_operator.mysql_fault_result.v1h",
            "status": "HOLD", "deployable": False,
            "engine": mysql("SELECT VERSION()").decode().strip(),
            "real_synthetic_sql_backup_and_independent_restore": True,
            "original_rows_preserved": True,
            "synthetic_baseline_sha256": expected,
            "private_dump_sha256":backup["sha256"],
            "private_dump_bytes":backup["bytes"],
            "before_ddl":before, "after_ddl":after,
            "actual_moodle_upgrade_tested":False,
            "production_writer_pause_verified":False,
            "production_backup_verified":False,
            "actions_executed":["synthetic_database_creation",
                                "synthetic_sql_dump_and_restore",
                                "synthetic_real_ddl_and_sigkill"],
        }
    finally:
        for db in reversed(created):
            try:
                remove(db)
            except Exception:
                pass
        for path in (marker, sql):
            if path.exists():
                path.unlink()


def main():
    try:
        if len(sys.argv) == 5 and sys.argv[1] == "--child":
            child(sys.argv[2],sys.argv[3],Path(sys.argv[4]))
            return 2
        require(len(sys.argv) == 1, "unexpected arguments")
        print(json.dumps(run(),sort_keys=True))
        return 0
    except (RuntimeError, OSError, ValueError) as exc:
        print(json.dumps({"schema":"bms24.release_operator.mysql_fault_result.v1h",
                          "status":"REJECTED","deployable":False,
                          "reason":str(exc)[:180]}))
        return 2


if __name__ == "__main__":
    sys.exit(main())
