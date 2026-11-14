# PR100/101: background verifier fix and GPG fixture

2026-11-14, synthetic data only. Release100 candidate/package unchanged. No real
backup, DB import, deployment, maintenance, cron changes, staging changes or merge.

## Reproduction and fix

Original `verify_archive` reported exit0 on a valid encrypted **non-tar** payload
with matching ciphertext/plaintext hashes, while tar printed failure. Reproduced
on Linux in an isolated synthetic fixture. Therefore archive SHA alone was not
sufficient.

Fixed helper records process-substitution PIDs via explicit FDs, closes writers,
checks pipeline return and waits for each child explicitly. It fixes verify,
encrypt hash and restore hash paths. Source archive stays byte-identical.

Exact tested files:
- `tools/backup/stream-lib.sh` SHA256 `d0f866f38e8569cd1bb3be693781ac3137a30c9439e1f907db48ef7aa1608d88`
- `tools/backup/test-stream.sh` SHA256 `f00934e0bb17ee91e2c0011dc1213a840a2b65a2e9de296a746e415d87184ae7`

## Executed synthetic results

| Case | Result |
|---|---|
| Original encrypted non-tar, matching hashes | FAIL design: exit0 despite parser error |
| Fixed valid archive and exact extracted tree | PASS |
| Encrypted non-tar, matching hashes | PASS rejection |
| Wrong ciphertext hash | PASS rejection |
| Wrong plaintext hash | PASS rejection |
| Truncated ciphertext, recomputed ciphertext hash | PASS rejection |
| Background parser reads stream then exits23 | PASS rejection |
| Hash process emits digest then exits29 | PASS rejection |
| Source emits all ciphertext then exits27 | PASS rejection |
| Encrypt hash exits29; final archive not published | PASS rejection |
| Restore hash exits29 | PASS rejection |
| Existing output overwrite | PASS rejection |
| Nonempty restore destination | PASS rejection |
| Unavailable secret key | PASS rejection |

Suite exit0 with twelve negative controls detected. Synthetic test material on
the Linux test machine used a separate tmpfs directory, with gpg-agent shutdown
and fixture cleanup PASS. Existing containers/volumes were not touched.

## Cross-version GPG

Synthetic RSA2048 recipient, AES256, compression none; test tar10240bytes with a
text file and binary file. New empty keyrings were populated from the exported
synthetic secret key on both decryptors (not a warm original keyring).

| Encryption | Decryption | Verification | Result |
|---|---|---|---|
|2.2.20|2.4.4|Fixed verify/stream restore + exact tree|PASS|
|2.2.20|2.4.9|Full decrypt, exact tar bytes and extracted text/binary|PASS|
|2.4.4|2.4.9|Full decrypt, exact tar bytes and extracted text/binary|PASS|

2.2.20 used only synthetic stdin and public recipient via inherited pipe; no
keyring import, agent, random-seed file or disk output on the source system.
Explicit no-options/no-autostart/no-default-keyring/lock-never settings; no real
backup data or private key were supplied there. Option semantics were checked
against the [GnuPG manual](https://www.gnupg.org/documentation/manuals/gnupg24/gpg.1.html).

Synthetic 2.2.20 ciphertext SHA256:
`2397dc12a2939d322f5a09b8f74de3766b7880e73db47ce6dcdd56ff9ffe4a8a`.
Plain tar SHA256:
`0621aa581d2a11ef3a45a1493f146c8b511ad7a05220cceec3fc3a177e45743d`.

Initial local full-suite attempt exposed Git Bash `/dev/fd` incompatibility and
was NOT accepted as PASS. The exact library/test files above passed on Linux;
Windows2.4.9 cross-version verification used direct decrypt/extract. No security
check was removed to obtain a green suite.

## Limits and remaining gates

PASS is limited to this format, these versions and synthetic files. An exported
throwaway key is not proof of the owner's operational-key escrow/recovery.
Real capture/complete SQL restore/independent backup/read-back/ACL/rollback:
NOT RUN. Continuous quota/inode guards, watchdog, safe archive path/link validation
and production writer exclusion remain driver acceptance requirements. The helper
must not be treated as an accepted capture/deploy driver. Source account capacity
and owner storage also remain unresolved. Production release stays HOLD.

Reproduce using [fixture instructions](../../tools/backup/README.md).
Private local raw evidence IDs: verifier-final, cross-gpg, cross-local-result;
only sanitized statuses/hashes are published. No synthetic secret keys or logs
with infrastructure addresses are in Git.
