#!/usr/bin/env python3
"""
BMS24 Stage 1891: prepare an isolated Moodle 4.1.22 config.php from local secrets.

--check: read-only validations; does NOT print any credentials.
--create --wwwroot https://stage41.bms24.online: writes config.php ONLY if absent.
No DB migrations, Docker Compose up/down, nginx, DNS, or old staging changes.
"""
import argparse
import os
import re
import stat
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit

BASE = Path("/srv/bms24-staging/parity41")
OLD = Path("/srv/bms24-staging/moodle-test/moodle")
CORE = BASE / "moodle"
ENV = BASE / ".env"
CONFIG = CORE / "config.php"
DB_CONTAINER = "bms24_parity41-db-1"
DB_NAME = "paritymoodle"
DB_USER = "parityuser"
DB_HOST = "db"

def stop(reason: str):
    raise SystemExit("STOP: " + reason)

def run_checked(cmd):
    try:
        return subprocess.run(cmd, text=True, capture_output=True, check=True).stdout.strip()
    except (OSError, subprocess.CalledProcessError):
        stop("Docker health check failed; inspect status without printing environment variables")

def validate_wwwroot(value: str) -> str:
    # A proposed public name: no IPs, raw localhost, paths, query, non-HTTPS ports.
    if not re.fullmatch(r"https://[a-z0-9][a-z0-9.-]*\.bms24\.online", value):
        stop("wwwroot must be a simple HTTPS subdomain under bms24.online")
    parts = urlsplit(value)
    hostname = parts.hostname
    if parts.scheme != "https" or not hostname or parts.port is not None:
        stop("invalid HTTPS wwwroot")
    if hostname in {"test.bms24.online", "edu.bms24.online", "bms24.online",
                    "www.bms24.online"} or hostname.endswith("..bms24.online"):
        stop("wwwroot must be a NEW hostname, never the existing staging")
    return value

def read_env_password() -> str:
    if ENV.is_symlink() or not ENV.is_file() or stat.S_IMODE(ENV.stat().st_mode) != 0o600:
        stop("parity41/.env missing, symlink, or not mode 0600")
    lines = ENV.read_text(encoding="ascii").splitlines()
    parsed = {}
    for line in lines:
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        if "=" not in line:
            stop("unsupported .env format")
        key, value = line.split("=", 1)
        if key in parsed:
            stop("duplicate .env key")
        parsed[key] = value
    password = parsed.get("PARITY_DB_PASSWORD", "")
    root_password = parsed.get("PARITY_DB_ROOT_PASSWORD", "")
    if not re.fullmatch(r"[0-9a-fA-F]{64}", password):
        stop("expected generated 64-hex-character test DB password")
    if not re.fullmatch(r"[0-9a-fA-F]{64}", root_password):
        stop("expected generated test DB root password")
    return password

def check_baseline():
    if os.geteuid() != 0:
        stop("run as root on Timeweb staging VDS")
    for folder in (BASE, CORE, OLD):
        if folder.is_symlink() or not folder.is_dir():
            stop("unexpected or missing staging directory: " + str(folder))
    version = CORE / "version.php"
    if version.is_symlink() or not version.is_file():
        stop("Moodle Core version.php missing")
    version_text = version.read_text(encoding="utf-8", errors="replace")
    if not re.search(r"\$version\s*=\s*2022112822\.00\s*;", version_text):
        stop("expected Moodle 4.1.22 Core only")
    if not re.search(r"\$branch\s*=\s*['\"]401['\"]\s*;", version_text):
        stop("expected Moodle branch 401")
    compose = (BASE / "compose.yml").read_text(encoding="utf-8")
    for marker in ("name: bms24_parity41", "127.0.0.1:18081:80",
                   "bms24_parity41_db", "internal: true"):
        if marker not in compose:
            stop("unexpected isolated Compose configuration")
    health = run_checked([
        "docker", "inspect", DB_CONTAINER,
        "--format", "{{if .State.Health}}{{.State.Health.Status}}{{else}}absent{{end}}",
    ])
    if health != "healthy":
        stop("isolated MariaDB is not healthy")
    password = read_env_password()
    if CONFIG.is_symlink():
        stop("Moodle config.php is a symlink")
    return password

def php_quote(value: str) -> str:
    # Inputs are restricted to hostname or hex strings; no PHP injection.
    if "'" in value or "\\" in value or "\n" in value or "\r" in value:
        stop("invalid value for PHP literal")
    return "'" + value + "'"

def make_config(password: str, wwwroot: str) -> str:
    # Mirror the MariaDB Moodle 4.1 config-dist.php driver and DB options.
    return """<?php
// BMS24 parity41 isolated TEST instance. Do not reuse with production.
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'paritymoodle';
$CFG->dbuser = 'parityuser';
$CFG->dbpass = """ + php_quote(password) + """;
$CFG->prefix = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => false,
    'dbsocket' => false,
    'dbport' => '3306',
    'dbcollation' => 'utf8mb4_unicode_ci',
);
$CFG->wwwroot = """ + php_quote(wwwroot) + """;
$CFG->dirroot = '/var/www/html';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->sslproxy = true;
$CFG->noemailever = true;
$CFG->disableupdateautodeploy = true;
require_once(__DIR__ . '/lib/setup.php');
"""

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--create", action="store_true")
    parser.add_argument("--wwwroot")
    args = parser.parse_args()
    if args.create == args.check:
        stop("choose exactly one of --check or --create")
    if args.create and not args.wwwroot:
        stop("--create requires --wwwroot https://NEWNAME.bms24.online")
    if args.check and args.wwwroot:
        stop("--wwwroot is only for --create")
    password = check_baseline()
    if args.check:
        if CONFIG.exists():
            mode = stat.S_IMODE(CONFIG.stat().st_mode)
            print("STAGE1891_CONFIG_ALREADY_EXISTS mode={:04o} (contents hidden)".format(mode))
        else:
            print("STAGE1891_CONFIG_ABSENT (expected before --create)")
        print("STAGE1891_ISOLATION_BASELINE_OK")
        return
    url = validate_wwwroot(args.wwwroot)
    # Do not overwrite an existing Moodle config, even with a different URL.
    try:
        fd = os.open(str(CONFIG), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
    except FileExistsError:
        stop("config.php already exists; refusing overwrite")
    with os.fdopen(fd, "w", encoding="utf-8") as fp:
        os.fchmod(fp.fileno(), 0o640)
        os.fchown(fp.fileno(), 0, 33)  # www-data GID inside Moodle PHP image
        fp.write(make_config(password, url))
        fp.flush()
        os.fsync(fp.fileno())
    print("STAGE1891_PARITY41_CONFIG_CREATED")
    print("Location: /srv/bms24-staging/parity41/moodle/config.php")
    print("Permissions: root:www-data 0640")
    print("WWWROOT_PROPOSED: " + url)
    print("TEST_DATABASE: db / paritymoodle / parityuser")
    print("Credentials hidden. No Moodle webserver, cron or migration started.")
    print("NOTE: WWWROOT is only proposed; DNS and HTTPS are NOT verified or configured.")

if __name__ == "__main__":
    try:
        main()
    except (ValueError, UnicodeError):
        stop("invalid input or .env file")
