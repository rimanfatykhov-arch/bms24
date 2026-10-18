# Working with Codex in VS Code — BMS24

## Install and workspace

VS Code + official OpenAI Codex extension (verify publisher). Open the **Git repository root**, not just a file or the `local/kbacompany` subfolder. Codex reads root `AGENTS.md`. Git must contain only source/code/docs, no deployment credentials or user records. Use branch+PR/diff for each feature and keep tested baseline tagged.

Windows PowerShell examples (inside repo root):

```powershell
git status
Get-Content local/kbacompany/version.php | Select-String 'version|release|requires'
python scripts/qa_local.py
# after review: git switch -c feature/bms24-w3-recurring-ui
```

If Python is installed as `py`, use `py -3 scripts/qa_local.py` instead. Install compatible PHP CLI separately and put `php` in PATH. Do not make lint on a newer local PHP substitute for target PHP compatibility.

## Tasks in VS Code

**Terminal → Run Task → BMS24: local preflight** runs `python scripts/qa_local.py`. The script validates PHP syntax, RU/EN keys, XML parse and version; it does NOT run Moodle PHPUnit, install upgrades, cron, browser or ACL checks. Run meaningful Moodle integration suites on a **staging Moodle** with test DB and user fixtures.

## Suggested Codex cycle

1. Inspect current `git status`, last commit, `version.php`, relevant files.
2. Read exact domain docs and report concise planned diff; ask if architecture/permissions/data need changing.
3. Implement one task. Keep domain logic independent of presentation. Preserve default legacy UI for now.
4. Run static QA, relevant dedicated tests where environment permits; show `git diff --stat`, `git diff --check` and tests NOT RUN.
5. Human reviews changes. Commit to feature branch. Deploy to staging with backup, migration checklist, real-role tests, rollback; only then propose production.

## Git and credentials

Never `git add .` blindly on the production tree. Review `.gitignore.example`, exclude config.php, .env*, any credentials, backups, moodledata and real dumps. Use a private remote and least privilege. No automatic push or deployment without approval.

## Codex context is not a runtime environment

A context pack does not install Moodle. Place actual SOURCE ZIP code in local/kbacompany. To connect VS Code to staging use approved transfer/deployment workflows; never point Codex at production FTP with auto-write access.

## Docs

- Current release: `docs/16_CURRENT_RELEASE_RC29_1642.md`.
- UI: `docs/17_UI_SHELL_AND_DESIGN_SYSTEM.md`.
- Security: `docs/04_SECURITY_COMPANY_ISOLATION.md`.
- Release QA: `docs/05_RELEASE_QA_STANDARD.md`.
