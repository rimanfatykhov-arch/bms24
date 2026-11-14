#!/usr/bin/env bash
set -Eeuo pipefail
library=$(cd "$(dirname "$0")" && pwd)/stream-lib.sh
: "${BMS_FIXTURE_DIR:?Set a new private disposable fixture directory}"
[[ -d $BMS_FIXTURE_DIR && -z $(ls -A "$BMS_FIXTURE_DIR") ]] || exit 2
cd "$BMS_FIXTURE_DIR"
export GNUPGHOME="$PWD/synthetic-key"
mkdir -p "$GNUPGHOME" fixture
chmod 700 "$GNUPGHOME"
if [[ ! -f fingerprint ]]; then
  gpg --batch --pinentry-mode loopback --passphrase '' --quick-generate-key 'BMS synthetic fixture <fixture@example.invalid>' rsa2048 encr 1d
  gpg --with-colons --list-keys | awk -F: '$1=="fpr"{print $10;exit}' > fingerprint
fi
fpr=$(cat fingerprint)
gpg --batch --export "$fpr" > public.pgp
gpg --batch --pinentry-mode loopback --passphrase '' --export-secret-keys "$fpr" > synthetic-secret.pgp
printf 'Synthetic BMS fixture; no user data\n' > fixture/message.txt
printf '\000\001\377test\r\n' > fixture/binary.dat
tar -cf fixture.tar -C fixture .
printf 'this is not a tar archive\n' > invalid.txt
source "$library"
if [[ ! -f valid.gpg ]]; then encrypt_stream "$fpr" valid.gpg valid.plain.sha < fixture.tar; fi
if [[ ! -f invalid.gpg ]]; then encrypt_stream "$fpr" invalid.gpg invalid.plain.sha < invalid.txt; fi
csha=$(cat valid.gpg.sha256); psha=$(cat valid.plain.sha)
verify_archive valid.gpg "$csha" "$psha"
printf 'PASS valid archive\n'
expect_fail() {
  local name=$1; shift
  if ( "$@" ) >"$name.log" 2>&1; then printf 'FAIL accepted negative %s\n' "$name"; exit 1; fi
  printf 'PASS negative %s\n' "$name"
}
expect_fail non_tar verify_archive invalid.gpg "$(cat invalid.gpg.sha256)" "$(cat invalid.plain.sha)"
expect_fail wrong_cipher_hash verify_archive valid.gpg "$(printf '0%.0s' {1..64})" "$psha"
expect_fail wrong_plain_hash verify_archive valid.gpg "$csha" "$(printf '0%.0s' {1..64})"
head -c 100 valid.gpg > truncated.gpg
expect_fail truncated verify_archive truncated.gpg "$(sha256sum truncated.gpg | cut -d' ' -f1)" "$psha"
forced_tar_failure() { tar() { command tar "$@" >/dev/null; return 23; }; verify_archive valid.gpg "$csha" "$psha"; }
expect_fail background_exit23 forced_tar_failure
forced_hash_failure() { sha256sum() { command sha256sum "$@"; [[ $# != 0 ]] || return 29; }; verify_archive valid.gpg "$csha" "$psha"; }
expect_fail hash_exit29 forced_hash_failure
failed_source() { cat valid.gpg; return 27; }
badsource_dest=$(mktemp -d "$PWD/badsource.XXXXXX")
expect_fail source_exit27 stream_restore failed_source -- "$badsource_dest" "$csha" "$psha"
forced_encrypt_hash_failure() { sha256sum() { command sha256sum "$@"; [[ $# != 0 ]] || return 29; }; encrypt_stream "$fpr" hashfailure.gpg hashfailure.plain.sha < fixture.tar; }
expect_fail encrypt_hash_exit29 forced_encrypt_hash_failure
[[ ! -e hashfailure.gpg ]] || { echo 'FAIL published failed encryption'; exit 1; }
forced_restore_hash_failure() { sha256sum() { command sha256sum "$@"; [[ $# != 0 ]] || return 29; }; stream_restore cat valid.gpg -- "$hash_dest" "$csha" "$psha"; }
hash_dest=$(mktemp -d "$PWD/hashfailure.XXXXXX")
expect_fail restore_hash_exit29 forced_restore_hash_failure
expect_fail overwrite encrypt_stream "$fpr" valid.gpg valid.plain.sha
mkdir -p nonempty
printf occupied > nonempty/file
expect_fail nonempty_restore stream_restore cat valid.gpg -- nonempty "$csha" "$psha"
dest=$(mktemp -d "$PWD/restored.XXXXXX")
stream_restore cat valid.gpg -- "$dest" "$csha" "$psha"
diff -r fixture "$dest"
printf 'PASS restored tree and source checksum\n'
[[ $(sha256sum valid.gpg | cut -d' ' -f1) == "$csha" ]]
mkdir -p empty-key
wrong_key() { export GNUPGHOME="$PWD/empty-key"; verify_archive valid.gpg "$csha" "$psha"; }
expect_fail unavailable_key wrong_key
printf 'ALL PASS\n'
