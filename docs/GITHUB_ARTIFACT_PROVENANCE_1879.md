# GitHub import artifact verification — build2026101879

The source bundle and INSTALL ZIP are distinct deliverables. Before publishing
new code to GitHub, prove that the exact source commit builds the reviewed ZIP.

**Read-only review from a trusted VDS with Git and Python 3.11+:**

```bash
python3 tools/github_import.py \
  --bundle BMS24_DEV_AI_REPOSITORY_build2026101879.bundle \
  --remote https://github.com/rimanfatykhov-arch/bms24.git \
  --install-zip INSTALL_local_kbacompany_1.1.0-rc29_build2026101879.zip \
  --approved-sha256 <SHA_FROM_SHA256SUMS>
```

The default command reports `PREPARED`, `candidate_verified: true`,
SHA-256 and the exact source/base commits without pushing. A mismatch fails
closed. Review the branch provenance and output before approving `--push`.

**Publish:** only after human approval, repeat the same command with `--push`.
The tool creates `import/build2026101879` without `--force` and does not
change `main`. Open a draft PR and run CI; merge and deployment remain separate
approvals.

**Trust boundaries:** the digest only verifies consistency between bytes
provided for review, not identity of the person who created them. For future
releases use artifact signing/attestations and an independently protected
release registry. Never place GitHub credentials, Moodle configs, customer
databases or .env files inside the Git bundle or ZIP.
