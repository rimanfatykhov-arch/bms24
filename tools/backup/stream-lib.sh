#!/usr/bin/env bash
# PR100 backup/restore stream helpers. PRIVATE. Nothing here runs on its own; functions are called by the
# reviewed capture/restore drivers. No secrets in argv; recipient is a public-key fingerprint only.
# Invariants:
#   * no plaintext tar/SQL is written to disk anywhere (only streams);
#   * every pipeline is fail-closed (pipefail + explicit PIPESTATUS check), output goes to *.partial and is
#     renamed only after all stages succeed;
#   * ciphertext SHA256 is computed while writing and re-read from disk (read-back) before a set is ACCEPTED;
#   * restore never consumes or modifies the source archive.
set -Eeuo pipefail
umask 077

die() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

# gpg options: public-key encryption, no compression (sizes stay predictable, no reliance on ratio), no TTY.
GPG_ENC=(gpg --batch --no-tty --quiet --trust-model always --compress-algo none --cipher-algo AES256)
GPG_DEC=(gpg --batch --no-tty --quiet --decrypt)

# encrypt_stream <recipient_fpr> <out.gpg> <plain_sha_file>   (reads plaintext on stdin)
# Writes <out.gpg>.partial, records plaintext SHA256 (of the exact stream) and ciphertext SHA256.
encrypt_stream() {
  local fpr=$1 out=$2 psha=$3
  [[ $fpr =~ ^[0-9A-F]{40}$ ]] || die 'recipient must be a 40-hex fingerprint'
  [[ ! -e $out && ! -e $out.partial ]] || die "refusing to overwrite $out"
  local hash_fd hash_pid pipe_rc=0 hash_rc=0
  exec {hash_fd}> >(sha256sum | cut -d' ' -f1 > "$psha.partial")
  hash_pid=$!
  tee /dev/fd/"$hash_fd" \
    | "${GPG_ENC[@]}" --recipient "$fpr" --encrypt \
    | tee "$out.partial" | sha256sum | cut -d' ' -f1 > "$out.sha256.partial" || pipe_rc=$?
  exec {hash_fd}>&-
  wait "$hash_pid" || hash_rc=$?
  (( pipe_rc == 0 && hash_rc == 0 )) || die "encrypt pipeline=$pipe_rc hash=$hash_rc"
  [[ -s $psha.partial && -s $out.sha256.partial ]] || die 'missing digest'
  # read-back: the bytes on disk must equal what the pipeline hashed
  [[ $(sha256sum "$out.partial" | cut -d' ' -f1) == $(cat "$out.sha256.partial") ]] || die 'read-back mismatch'
  mv -n "$out.partial" "$out"; mv -n "$out.sha256.partial" "$out.sha256"; mv -n "$psha.partial" "$psha"
}

# verify_archive <in.gpg> <expected_cipher_sha> <expected_plain_sha>
# Full decrypt + full tar listing; proves the ciphertext decrypts with the recovery key and the tar is complete.
verify_archive() (
  local in=$1 csha=$2 psha=$3 tmp tar_fd tar_pid pipe_rc=0 tar_rc=0 got_p
  [[ $(sha256sum "$in" | cut -d' ' -f1) == "$csha" ]] || die 'ciphertext sha256 mismatch'
  tmp=$(mktemp -d) || die 'temporary metadata directory failed'
  trap 'rm -f -- "$tmp/plain.sha"; rmdir -- "$tmp"' EXIT
  exec {tar_fd}> >(tar -tf - > /dev/null)
  tar_pid=$!
  "${GPG_DEC[@]}" "$in" | tee /dev/fd/"$tar_fd" | sha256sum | cut -d' ' -f1 > "$tmp/plain.sha" || pipe_rc=$?
  exec {tar_fd}>&-
  wait "$tar_pid" || tar_rc=$?
  (( pipe_rc == 0 && tar_rc == 0 )) || die "verify pipeline=$pipe_rc archive=$tar_rc"
  got_p=$(cat "$tmp/plain.sha")
  [[ $got_p =~ ^[0-9a-f]{64}$ && $got_p == "$psha" ]] || die 'plaintext sha256 mismatch'
)

# stream_restore <source_cmd...> -- <dest_dir> <expected_cipher_sha> <expected_plain_sha>
# Source is a command that writes the ciphertext to stdout (e.g. `ssh prod cat /path/x.gpg` or `cat x.gpg`).
# The archive itself is only read. dest_dir must be new and empty.
stream_restore() (
  local -a src=(); while [[ $1 != -- ]]; do src+=("$1"); shift; done; shift
  local dest=$1 csha=$2 psha=$3 tmp cfd pfd cpid ppid pipe_rc=0 crc=0 prc=0
  [[ -d $dest && -z $(ls -A "$dest") ]] || die 'destination must exist and be empty'
  tmp=$(mktemp -d) || die 'temporary metadata directory failed'
  trap 'rm -f -- "$tmp/c" "$tmp/p"; rmdir -- "$tmp"' EXIT
  exec {cfd}> >(sha256sum | cut -d' ' -f1 > "$tmp/c")
  cpid=$!
  exec {pfd}> >(sha256sum | cut -d' ' -f1 > "$tmp/p")
  ppid=$!
  "${src[@]}" | tee /dev/fd/"$cfd" \
    | "${GPG_DEC[@]}" | tee /dev/fd/"$pfd" \
    | tar -xpf - --numeric-owner -C "$dest" || pipe_rc=$?
  exec {cfd}>&-
  exec {pfd}>&-
  wait "$cpid" || crc=$?
  wait "$ppid" || prc=$?
  (( pipe_rc == 0 && crc == 0 && prc == 0 )) || die "restore pipeline=$pipe_rc cipher_hash=$crc plain_hash=$prc"
  [[ $(cat "$tmp/c") == "$csha" ]] || die 'received ciphertext sha256 mismatch'
  [[ $(cat "$tmp/p") == "$psha" ]] || die 'restored plaintext sha256 mismatch'
)

# space_guard <path> <min_free_bytes>  -- stop before a stage would cut into the reserve
space_guard() {
  local free; free=$(df -PB1 "$1" | awk 'NR==2{print $4}')
  (( free >= $2 )) || die "free $free < required $2 on $1"
}
