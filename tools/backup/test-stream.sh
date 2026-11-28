#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C
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
  local name=$1 pattern; shift
  case "$name" in
    non_tar) pattern='^FAIL: verify pipeline=[0-9]+ archive=2$' ;;
    wrong_cipher_hash) pattern='^FAIL: ciphertext sha256 mismatch$' ;;
    wrong_plain_hash) pattern='^FAIL: plaintext sha256 mismatch$' ;;
    truncated) pattern="^FAIL: verify pipeline=2 archive=${truncated_tar_rc}$" ;;
    unavailable_key) pattern='^FAIL: verify pipeline=2 archive=2$' ;;
    background_exit23) pattern='^FAIL: verify pipeline=[0-9]+ archive=23$' ;;
    hash_exit29) pattern='^FAIL: verify pipeline=29 archive=0$' ;;
    source_exit27) pattern='^FAIL: restore pipeline=27 cipher_hash=0 plain_hash=0$' ;;
    encrypt_hash_exit29) pattern='^FAIL: encrypt pipeline=29 hash=29$' ;;
    restore_hash_exit29) pattern='^FAIL: restore pipeline=0 cipher_hash=29 plain_hash=29$' ;;
    overwrite|existing_*|dangling_*) pattern='^FAIL: output path already exists$' ;;
    collision_*) pattern='^FAIL: output path collision$' ;;
    nonempty_restore) pattern='^FAIL: destination must exist and be empty$' ;;
    *) printf 'FAIL unknown negative %s\n' "$name"; exit 1 ;;
  esac
  if ( "$@" ) >"$name.log" 2>&1; then printf 'FAIL accepted negative %s\n' "$name"; exit 1; fi
  grep -Eq "$pattern" "$name.log" || { printf 'FAIL wrong rejection reason %s\n' "$name"; cat "$name.log"; exit 1; }
  if [[ $name == unavailable_key ]]; then
    grep -Fq 'No secret key' "$name.log" || { echo 'FAIL wrong key rejection reason'; exit 1; }
  fi
  printf 'PASS negative %s\n'  "$name"
}
expect_fail non_tar verify_archive invalid.gpg "$(cat invalid.gpg.sha256)" "$(cat invalid.plain.sha)"
expect_fail wrong_cipher_hash verify_archive valid.gpg "$(printf '0%.0s' {1..64})" "$psha"
expect_fail wrong_plain_hash verify_archive valid.gpg "$csha" "$(printf '0%.0s' {1..64})"
# Remove only the final authenticated trailer, preserving the complete tar payload.
# A short header is not a valid decryption-negative: some GPG versions return 0
# without producing plaintext for an incomplete packet header.
valid_bytes=$(wc -c < valid.gpg)
(( valid_bytes > 16 )) || die 'valid encrypted fixture too short'
head -c "$((valid_bytes - 16))" valid.gpg > truncated.gpg
[[ $(wc -c < truncated.gpg) == "$((valid_bytes - 16))" ]] || die 'truncation fixture size mismatch'
# Isolate the intended failure: full tar bytes remain, but authenticated decrypt fails.
decrypt_rc=0
"${GPG_DEC[@]}" truncated.gpg > truncated.plain.tar 2> truncated.decrypt.log || decrypt_rc=$?
(( decrypt_rc == 2 )) || die "truncated fixture expected decrypt exit2, got $decrypt_rc"
# GPG versions may buffer all plaintext or emit a prefix before rejecting MDC.
# Check the emitted bytes, and independently pin the parser outcome for this fixture.
plain_bytes=$(wc -c < truncated.plain.tar)
(( plain_bytes <= $(wc -c < fixture.tar) )) || die 'truncated decrypt emitted excess data'
head -c "$plain_bytes" fixture.tar | cmp - truncated.plain.tar || die 'truncated decrypt plaintext differs from original prefix'
truncated_tar_rc=0
tar -tf truncated.plain.tar >/dev/null 2>truncated.tar.log || truncated_tar_rc=$?
[[ $truncated_tar_rc == 0 || $truncated_tar_rc == 2 ]] || die 'unexpected standalone truncated tar status'
[[ -s truncated.decrypt.log ]] || die 'truncated fixture missing decrypt diagnostic'
printf 'CONTROL tail16 truncation: decrypt=2 archive=%s plaintext_bytes=%s\n' "$truncated_tar_rc" "$plain_bytes"
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
# Each output/sidecar and partial is protected, including dangling links.
for slot in out out.partial out.sha256 out.sha256.partial plain plain.partial; do
  label=${slot//./_}
  dir=$(mktemp -d "$PWD/existing.XXXXXX")
  printf 'KEEP-%s\n' "$slot" > "$dir/$slot"
  before=$(sha256sum "$dir/$slot")
  expect_fail "existing_$label" encrypt_stream "$fpr" "$dir/out" "$dir/plain"
  [[ $(sha256sum "$dir/$slot") == "$before" && $(find "$dir" -mindepth 1 | wc -l) == 1 ]] || die 'existing sidecar changed'
  dir=$(mktemp -d "$PWD/dangling.XXXXXX")
  ln -s never-created "$dir/$slot"
  expect_fail "dangling_$label" encrypt_stream "$fpr" "$dir/out" "$dir/plain"
  [[ -L $dir/$slot && $(readlink "$dir/$slot") == never-created && ! -e $dir/never-created && $(find "$dir" -mindepth 1 | wc -l) == 1 ]] || die 'dangling link changed'
done
dir=$(mktemp -d "$PWD/collision.XXXXXX")
expect_fail collision_exact encrypt_stream "$fpr" "$dir/out" "$dir/out.sha256"
expect_fail collision_alias encrypt_stream "$fpr" "$dir/out" "$dir/./out.sha256"
[[ -z $(ls -A "$dir") ]] || die 'colliding outputs were created'
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
