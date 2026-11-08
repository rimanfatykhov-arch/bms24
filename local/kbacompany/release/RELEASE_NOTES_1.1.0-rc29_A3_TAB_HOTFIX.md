# RC29 hotfix build2026101474 — Management Cycle 2.0 correction A3 tab

Root cause: `management_cycles.php` accepts tab values `overview/checkpoints/execution/a3/history`, but read `tab` using `PARAM_ALPHA`, stripping the digit in `a3` to `a`; whitelist rejects it as `invalidparameter` (line 22).

Fix: `optional_param('tab', 'overview', PARAM_ALPHANUM)` preserves the digit; explicit existing tab whitelist remains. All existing links, RBAC, company context, cycle data and actions unchanged. No DB schema changes.

Acceptance: (1) Open a management cycle, click Correction: URL `tab=a3`, A3 panel loads; (2) click other cycle tabs; (3) invalid `tab=a3extra` still rejected; (4) cross-company permissions remain enforced. Static regression added; real Moodle acceptance NOT RUN.
