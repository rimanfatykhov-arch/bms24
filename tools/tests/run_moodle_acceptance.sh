#!/usr/bin/env bash
# Disposable CI only. Does not accept external database hosts or deployment paths.
set -euo pipefail
: "${RUNNER_TEMP:?RUNNER_TEMP required}"
: "${TEST_ROOT:?TEST_ROOT required}"
: "${MOODLE_ROOT:?MOODLE_ROOT required}"
: "${CANDIDATE_ROOT:?CANDIDATE_ROOT required}"
: "${DBTYPE:?DBTYPE required}"
: "${DBHOST:?DBHOST required}"
: "${DBPORT:?DBPORT required}"
: "${DBUSER:?DBUSER required}"
: "${DBPASS:?ephemeral DBPASS required}"
test "$DBHOST" = 127.0.0.1
case "$DBTYPE" in mariadb|mysqli) ;; *) exit 2 ;; esac
runner=$(realpath "$RUNNER_TEMP")
target=$(realpath -m "$TEST_ROOT")
case "$target" in "$runner"/*) ;; *) echo 'TEST_ROOT must be below RUNNER_TEMP' >&2; exit 2 ;; esac
test "$target" != "$runner"
mkdir -p -- "$target"
export TEST_ROOT="$target"
php "$CANDIDATE_ROOT/tools/tests/moodle_acceptance.php" run
