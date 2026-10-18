# Repository Setup (VS Code / Codex)

Recommended Git workspace:

```
bms24/
├── AGENTS.md                  # project instructions read by Codex
├── CODEX_FIRST_TASK.md        # paste into Codex once
├── README.md
├── .vscode/                   # tasks/extensions/settings
├── docs/                      # current project context
├── scripts/qa_local.py
├── local/kbacompany/           # source extracted from build1642 SOURCE ZIP
└── theme/kba/                 # optional existing host theme (legacy fallback)
```

`SOURCE_local_kbacompany_v1.1.0-rc29_build2026101642.zip` archive contains the top-level `kbacompany/` directory. Unzip at workspace root, then MOVE `kbacompany/` under `local/`. Do **not** nest `kbacompany/kbacompany` and do **not** copy source ZIP as active plugin.

Install official Codex extension through VS Code UI, sign in and open **repository root**. Root `AGENTS.md` is discovered automatically by Codex; no extra prompt loader or extension-specific config file is required. `.vscode/` recommended extensions and tasks are optional conveniences.

Review `docs/19_VSCODE_CODEX_WORKFLOW.md`, `.gitignore.example` and `CODEX_FIRST_TASK.md` before asking Codex to edit code.

Remote = private Git hosting with least privilege and explicit deployment approval. Exclude `config.php`, `.env*`, keys, secrets, customer data, `moodledata`, caches, backups and production dumps. Never automatically deploy from local edits or write directly via prod FTP.
