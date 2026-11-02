#!/usr/bin/env bash
# BMS24 Stage 1881.15 staging smoke test: read-only Moodle checks.
# Does not run cron, sync Bitrix24, invoke AI, or change Docker/production.
set -Eeuo pipefail
umask 077
BASE=/srv/bms24-staging/moodle-test
WRAPPER="$BASE/docker/bin/moodle-docker-compose"
REPORT_DIR=/srv/bms24-staging/reports
if (( EUID != 0 )) || [[ ! -r "$BASE/staging.env" || ! -x "$WRAPPER" ]]; then
    echo 'ERROR: expected root shell on the Timeweb staging VDS' >&2
    exit 1
fi
source "$BASE/staging.env"
cd "$BASE/docker"
mkdir -p "$REPORT_DIR"
chmod 700 "$REPORT_DIR"
REPORT="$REPORT_DIR/stage1881-smoke-$(date -u +%Y%m%dT%H%M%SZ).log"
run_checks() {
    local errors=0 file http result schema_log
    schema_log=$(mktemp)
    trap 'rm -f "$schema_log"' RETURN
    echo '=== STAGE 1881.15 — READ-ONLY SMOKE ==='
    echo '=== PLUGINS AND TEST DATA ==='
    if ! "$WRAPPER" exec -T webserver php -r '
        define("CLI_SCRIPT", true);
        require "config.php";
        $expected = [
            "local_kbacompany" => "2026101879",
            "theme_kba" => "2026092213",
            "local_kbaadmin" => "2026081100",
            "mod_kbaelement" => "2026102603",
            "local_kbaai" => "2026101516",
            "local_kbacorporate" => "2026102613",
            "local_kbagamification" => "2026081708",
            "local_bitrixsync" => "2026083634",
        ];
        $fail = false;
        foreach ($expected as $plugin => $version) {
            $actual = get_config($plugin, "version");
            $ok = (string)$actual === $version;
            echo ($ok ? "PASS" : "FAIL") . " plugin " . $plugin . " version=" . ($actual === false ? "ABSENT" : $actual) . PHP_EOL;
            $fail = $fail || !$ok;
        }
        $companies = $DB->count_records("local_kbacompany_company");
        $members = $DB->count_records("local_kbacompany_member");
        echo ($companies === 1 ? "PASS" : "FAIL") . " bms_companies=" . $companies . PHP_EOL;
        echo ($members === 1 ? "PASS" : "FAIL") . " bms_members=" . $members . PHP_EOL;
        $cron = get_config("core", "cron_enabled");
        $ok = (string)$cron === "0";
        echo ($ok ? "PASS" : "FAIL") . " staging_cron_disabled=" . ($ok ? "yes" : "no") . PHP_EOL;
        exit($fail || $companies !== 1 || $members !== 1 || !$ok ? 2 : 0);
    '; then
        errors=$((errors+1))
    fi
    echo '=== PLUGIN PHP SYNTAX ==='
    for file in local/kbacompany/version.php theme/kba/version.php \
                local/kbaadmin/version.php mod/kbaelement/version.php \
                local/kbaai/version.php local/kbacorporate/version.php \
                local/kbagamification/version.php local/bitrixsync/version.php
    do
        if "$WRAPPER" exec -T webserver php -l "$file" >/dev/null 2>&1; then
            echo "PASS php-lint $file"
        else
            echo "FAIL php-lint $file"
            errors=$((errors+1))
        fi
    done
    echo '=== DATABASE SCHEMA ==='
    if "$WRAPPER" exec -T webserver php admin/cli/check_database_schema.php >"$schema_log" 2>&1 \
       && grep -Fq 'Database structure is ok.' "$schema_log"; then
        echo 'PASS schema ok'
    else
        echo 'FAIL schema check (raw output retained only temporarily)'
        errors=$((errors+1))
    fi
    echo "XMLDB warning count: $(grep -c 'XMLDB has detected' "$schema_log" || true)"
    echo '=== HTTP (UNAUTHENTICATED ONLY) ==='
    http=$(curl --noproxy '*' -sS --max-time 10 -o /dev/null -w '%{http_code}' \
        'http://127.0.0.1:18080/local/kbacompany/index.php') || http=000
    case "$http" in
        200|302|303) echo "PASS HTTP=$http (login redirect does not validate UX)" ;;
        *) echo "FAIL HTTP=$http"; errors=$((errors+1)) ;;
    esac
    if (( errors == 0 )); then
        echo 'STAGE1881_SMOKE_OK — manual UX and integration testing still needed'
    else
        echo "STAGE1881_SMOKE_FAILED count=$errors"
        return 1
    fi
}
run_checks | tee "$REPORT"
echo "Report saved: $REPORT"
