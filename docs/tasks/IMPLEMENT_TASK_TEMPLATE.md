# Codex Implementer

Implement only approved scope.

Rules:
- no Moodle core edits;
- preserve company isolation;
- preserve canonical source of truth;
- no duplicate domain mechanism;
- business logic stays out of theme;
- no DB/ACL/privacy change unless explicitly approved.

Before editing: list expected files and impacts.

After editing:
- run `.\scripts\preflight.ps1`;
- run focused tests;
- show `git diff --stat`;
- report PASS / NOT RUN;
- do not commit unless explicitly asked.
