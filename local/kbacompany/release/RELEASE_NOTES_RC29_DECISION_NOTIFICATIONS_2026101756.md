# BMS24 build2026101756 — Уведомления по решениям

- Moodle scheduled task daily 08:30 (server time) scans latest protocol revisions.
- In-app notification and best-effort Moodle message to active company members assigned to decisions.
- Triggers: assigned/new revision in last 24h, one day before deadline, once after overdue (no daily repeated nag).
- Excludes completed/cancelled decisions. Deterministic notification claim prevents duplicates across cron executions.
- No task mutation and no automatic status changes. No new DB tables.
- Limitation: missed assignment window after prolonged cron outage; day boundaries use server time.
- Static QA only. Live Moodle NOT RUN.
