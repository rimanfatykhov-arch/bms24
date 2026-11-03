#!/usr/bin/env bash
# Stage 1886: verify official Moodle 4.1.22 archive, then place it ONLY
# into the empty /srv/bms24-staging/parity41/moodle directory.
# No containers, SQL migrations, config.php or old staging modifications.
set -Eeuo pipefail
umask 077
export LC_ALL=C

BASE='/srv/bms24-staging/parity41'
DEST="$BASE/moodle"
PACKAGE='moodle-4.1.22.tgz'
CHECKSUM="$PACKAGE.sha256"
ORIGIN='https://packaging.moodle.org/stable401'
ARCHIVE_SOURCE=''
SHA_SOURCE=''

usage() {
    echo "Usage: bash $0 [--archive /path/$PACKAGE --sha256 /path/$CHECKSUM]"
}
if (($# > 0)); then
    if (($# != 4)) || [[ "$1" != '--archive' || "$3" != '--sha256' ]]; then
        usage >&2
        exit 2
    fi
    ARCHIVE_SOURCE="$2"
    SHA_SOURCE="$4"
fi

[[ $(id -u) == 0 ]] || { echo 'STOP: run as root on Timeweb' >&2; exit 2; }
[[ -f "$BASE/compose.yml" && -d "$DEST" && -d /srv/bms24-staging/moodle-test/moodle ]] ||
    { echo 'STOP: stage 1885 layout was not prepared' >&2; exit 2; }
[[ ! -L "$BASE" && ! -L "$DEST" ]] ||
    { echo 'STOP: refusing symlink target' >&2; exit 2; }
# Never overwrite, merge into, or delete existing site code.
if [[ -n "$(find "$DEST" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    echo 'STOP: parity41/moodle is not empty; refusing overwrite' >&2
    exit 3
fi
[[ -f /srv/bms24-staging/moodle-test/moodle/version.php ]] ||
    { echo 'STOP: existing staging reference missing' >&2; exit 2; }
command -v python3 >/dev/null
command -v sha256sum >/dev/null

TMP=$(mktemp -d "$BASE/.stage1886.XXXXXXXX")
cleanup() {
    if [[ -d "$TMP" && "$TMP" == "$BASE"/.stage1886.* ]]; then
        rm -rf -- "$TMP"
    fi
}
trap cleanup EXIT

if [[ -n "$ARCHIVE_SOURCE" ]]; then
    [[ -f "$ARCHIVE_SOURCE" && -f "$SHA_SOURCE" ]] ||
        { echo 'STOP: both offline archive and checksum files are required' >&2; exit 2; }
    cp -- "$ARCHIVE_SOURCE" "$TMP/$PACKAGE"
    cp -- "$SHA_SOURCE" "$TMP/$CHECKSUM"
else
    command -v curl >/dev/null || { echo 'STOP: curl missing' >&2; exit 2; }
    for file in "$CHECKSUM" "$PACKAGE"; do
        curl --fail --location --silent --show-error --retry 2 --connect-timeout 10 \
            --max-time 300 --proto '=https' --proto-redir '=https' \
            "$ORIGIN/$file" -o "$TMP/$file"
    done
fi

[[ -s "$TMP/$PACKAGE" && -s "$TMP/$CHECKSUM" ]] ||
    { echo 'STOP: empty download or SHA256 checksum' >&2; exit 3; }
grep -Fq "$PACKAGE" "$TMP/$CHECKSUM" ||
    { echo 'STOP: checksum filename mismatch' >&2; exit 3; }
expected=$(grep -Eo '[a-fA-F0-9]{64}' "$TMP/$CHECKSUM" | head -n 1 || true)
[[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] ||
    { echo 'STOP: no valid SHA256 digest found' >&2; exit 3; }
actual=$(sha256sum "$TMP/$PACKAGE")
actual="${actual%% *}"
if [[ "${actual,,}" != "${expected,,}" ]]; then
    echo 'STOP: SHA256 checksum mismatch, nothing extracted' >&2
    exit 3
fi
echo "STAGE1886_SHA256_OK $actual"

# Reject path traversal, links and special files before extraction.
mkdir "$TMP/unpack"
python3 - "$TMP/$PACKAGE" "$TMP/unpack" <<'PY'
import sys
import tarfile
from pathlib import PurePosixPath

archive, destination = sys.argv[1:]
count = 0
total = 0
with tarfile.open(archive, "r:gz") as tf:
    for entry in tf:
        count += 1
        total += entry.size if entry.isfile() else 0
        path = PurePosixPath(entry.name)
        if (
            count > 100000 or total > 1200 * 1024 * 1024
            or path.is_absolute() or ".." in path.parts
            or not path.parts or path.parts[0] != "moodle"
            or not (entry.isfile() or entry.isdir())
        ):
            raise SystemExit("STOP: unexpected archive entry: " + entry.name[:160])
    if count < 100:
        raise SystemExit("STOP: archive is unexpectedly small")
    tf.extractall(path=destination)
print("STAGE1886_ARCHIVE_STRUCTURE_OK files=" + str(count))
PY

SOURCE="$TMP/unpack/moodle"
[[ -s "$SOURCE/version.php" && -d "$SOURCE/lib" && -d "$SOURCE/admin" ]] ||
    { echo 'STOP: Moodle Core structure missing' >&2; exit 3; }
grep -Eq '^[[:space:]]*\$version[[:space:]]*=[[:space:]]*2022112822(\.00)?;' "$SOURCE/version.php" ||
    { echo 'STOP: package is not Moodle Core 4.1.22' >&2; exit 3; }
grep -Eq '^[[:space:]]*\$branch[[:space:]]*=[[:space:]]*.*401' "$SOURCE/version.php" ||
    { echo 'STOP: Moodle branch is not 401' >&2; exit 3; }

# Atomic final move within this filesystem; this is an EMPTY directory made in stage1885.
rmdir -- "$DEST"
mv -- "$SOURCE" "$DEST"
chmod 0755 "$DEST"
echo 'STAGE1886_MOODLE_4_1_22_CODE_READY'
echo "Destination: $DEST"
echo 'State: only Core files extracted; no Docker containers started; no DB or Moodle config created'
