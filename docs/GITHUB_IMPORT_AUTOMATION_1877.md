# BMS24 — GitHub import build2026101877 (non-destructive)

## Current state as verified November 2, 2026

- GitHub: `rimanfatykhov-arch/bms24` (private), default branch `main` currently has `local/kbacompany/version.php` at `2026101645`.
- GitHub already has `import/build2026101876`, which is a **placeholder/documentation branch**, not the latest BMS24 source.
- Offline BMS Dev AI source history includes releases `1871` through `1877` in a Git bundle.
- VDS deployment and GitHub Actions runs have **not** been confirmed.

## Import design

`tools/github_import.py` is a standard-library Python + Git 2.30+ tool. It never executes agent code or accesses Moodle. It creates a *two-parent commit* on `import/build2026101877`, with:

1. first parent = existing `import/build2026101876` (if present) or the existing `main` head;
2. second parent = `HEAD` from the verified source Git bundle;
3. tree = exact latest source tree from the bundle.

Thus the existing GitHub history and the earlier local source history are both retained, **without force-pushing or modifying `main`**. An existing 1877 import branch causes `HOLD` rather than overwrite.

### On a dedicated isolated developer/VDS machine, with GitHub auth configured

```sh
# Check only: clone into an ephemeral directory; no writes to GitHub.
python3 tools/github_import.py \
  --bundle BMS24_DEV_AI_REPOSITORY_build2026101877.bundle \
  --remote https://github.com/rimanfatykhov-arch/bms24.git

# After inspecting the report AND authorizing remote upload:
python3 tools/github_import.py \
  --bundle BMS24_DEV_AI_REPOSITORY_build2026101877.bundle \
  --remote https://github.com/rimanfatykhov-arch/bms24.git \
  --push
```

**No GitHub PAT, secret or password should appear in the URL or command line**. Configure a credential helper or a narrowly scoped GitHub App. `--push` does **not** deploy to a server or merge anything into `main`.

## Approval gate after import

1. Review the changes from the old `2026101645` repository to build1877. Run a secret scan and verify expected modules and third-party license files.
2. Run PHP **8.1** and JavaScript lint, XMLDB, BMS regression tests and packaging checks on GitHub Actions. A green workflow alone does not guarantee PHP/Moodle/MySQL compatibility.
3. Open a draft Pull Request **from** `import/build2026101877` **to** `main`, review additions/deletions and dependency license changes. Do not immediately merge.
4. Test the candidate on **isolated** Moodle staging with synthetic data. Complete role/ACL and DB schema acceptance and the RC30 checks.
5. A human owner explicitly approves the final merge and the separate staging/production deployment.

## Guardrails

- GitHub branch `main` is never modified by the importer.
- Source must have the expected plugin build number; tracked secrets, dumps, symlinks, and special filesystem entries are rejected.
- Existing import branches are never force-updated; use manual investigation if the branch already exists.
- The importer handles no GitHub token and publishes nothing unless `--push` is explicitly supplied.
- Keep customer and real Moodle data out of EU development environments.
- The **INSTALL ZIP** is for Moodle; the **source ZIP and Git bundle** are for development. Do not deploy source ZIP as a Moodle plugin.
