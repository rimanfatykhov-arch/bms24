# BMS Calendar Regular Management — 1.1.0-alpha16

## Scope

Calendar adds a read-only regular-management control panel over existing canonical Battle Plan and Management Cycle services.

The panel exposes the current management week, acceptance/readiness counters, missing/blocked Battle Plans, overdue tasks and decisions requiring attention. It provides links for period launch, meetings, execution control, readiness, weekly acceptance, and allowed Management Cycle start/close actions.

## Architecture

- No new calendar or management storage.
- No direct lifecycle mutation from Calendar.
- Battle Plan launch/transition stays in existing Battle Plan workspaces/services.
- Management Cycle start/close stays in `management_cycles.php` and canonical `management_cycle_template_service`.
- Company scope and management permissions are evaluated before the panel is built.
- Reader users do not receive the management control panel.

## Explicit non-goals

- No hidden auto-launch.
- No auto-close.
- No direct bulk status updates.
- No duplicate meeting/task/cycle model.
