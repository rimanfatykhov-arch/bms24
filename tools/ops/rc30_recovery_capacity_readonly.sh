#!/bin/sh
# RC30 capacity preflight: READ-ONLY, recovery host ONLY, no network, no secrets.
# Usage on approved isolated recovery host:
#   sh tools/ops/rc30_recovery_capacity_readonly.sh /srv/bms69-recovery-20261111
# Does not prove quota or backup completeness. Never run on production.
set -eu
LC_ALL=C
export LC_ALL
root=${1:-}
if [ -z "$root" ] || [ ! -d "$root" ]; then
  echo 'ERROR: Pass existing approved recovery directory as argument' >&2
  exit 2
fi
root=$(cd "$root" && pwd -P)
if [ "$root" != /srv/bms69-recovery-20261111 ]; then
  echo 'ERROR: Refusing non-allowlisted path' >&2
  exit 2
fi
if [ -L "$root" ]; then
  echo 'ERROR: Symlink recovery path refused' >&2
  exit 2
fi
if [ "$(id -u)" -eq 0 ]; then
  echo 'ERROR: Run as an unprivileged recovery auditor, not root' >&2
  exit 2
fi
echo 'RC30_READ_ONLY_STORAGE_PREFLIGHT'
printf 'timestamp_utc='; date -u '+%Y-%m-%dT%H:%M:%SZ'
printf 'recovery_dir=%s\n' "$root"
echo 'filesystem_bytes:'
df -B1 -P "$root"
echo 'filesystem_inodes:'
df -i -P "$root"
echo 'recovery_directory_bytes:'
du -s -B1 "$root"
echo 'mount_metadata:'
if command -v findmnt >/dev/null 2>&1; then
  findmnt -T "$root" -no FSTYPE,OPTIONS 2>/dev/null || echo 'mount_metadata=NOT_VERIFIED'
else
  echo 'mount_metadata=NOT_VERIFIED'
fi
echo 'quota:'
if command -v quota >/dev/null 2>&1; then
  quota -s 2>&1 | sed -n '1,12p' || echo 'quota=NOT_VERIFIED'
else
  echo 'quota=NOT_VERIFIED'
fi
echo 'LIMITATIONS: df available bytes are not account quota; du is aggregate allocation, not coherent backup proof.'
echo 'LIMITATIONS: no backup manifest, encryption/key access, temp DDL peak, offsite or restore capacity verified.'
echo 'STATUS=MEASURED_ONLY_HOLD'
