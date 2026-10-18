# RC28 build2026101463 — Work Schedule regression checkpoint

Base: RC28 build2026101462. No changes to task assignment, schedule rights or database schema.

Added PHPUnit tests `tests/employee_schedule_service_test.php`:
- 40/36/20 hour company-specific capacity and team total (96 hours);
- company isolation for a schedule lookup and rejection of cross-company schedule write;
- rejection of unsupported weekly norm;
- change of capacity without modifying tasks.

Static QA is not Moodle PHPUnit execution. Live Moodle/MySQL upgrade, overload task creation and permissions still NOT RUN. RC28 acceptance OPEN; FINAL NOT READY.
