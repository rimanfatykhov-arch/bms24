# Synthetic backup-stream tests (Linux Bash)

These helpers do not capture a database or stop any service. No production driver
is included. They require Linux Bash, GNU tar/coreutils and GPG; Git Bash `/dev/fd`
is not supported. Cross-version decrypt on Windows is tested separately with GPG.

Create a new **private, disposable** fixture directory on an authorized machine:

```sh
umask 077
fixture=$(mktemp -d)
BMS_FIXTURE_DIR="$fixture" bash tools/backup/test-stream.sh
```

The test creates a one-day, unprotected synthetic encryption key, artificial
files, ciphertext and logs **inside that directory**. No real keys or data.
The caller owns cleanup and must stop its synthetic gpg-agent before removing
only this exact resolved directory. Do not run in a production directory.
The script refuses a nonempty fixture directory. No automatic site/volume cleanup.

Expected: valid verification and byte-equal extraction, twelve rejected negatives,
`ALL PASS`, exit 0. Each negative runs in a subprocess; accepting any one fails
the suite. Tests cover non-tar plaintext with matching hashes, wrong ciphertext
and plaintext hashes, truncation, late archive-parser failure, hash failures,
upstream source failure, failed-encryption publication, overwrite, nonempty
destination and unavailable decryption key.

`verify_archive` waits for its archive parser PID; encrypt and restore wait for
every asynchronous hash PID. Pipeline status is captured before closing writer
FDs, and explicit waits occur even when a pipeline fails. A bare `wait` or
`PIPESTATUS` from a command substitution cannot replace these checks.

Limits: this fix is **not production acceptance**. `space_guard` remains a
filesystem-only check; account quota/inode/continuous guards belong in the reviewed
driver. `stream_restore` is for trusted archives in disposable isolation; archive
path/link prevalidation and authenticated-completion gating are required before
restoring real data or launching an application. Files extracted before a failed
final hash/MDC check remain untrusted and must never be promoted. No DB restore,
owner operational-key recovery, watchdog or live capture is tested here.
