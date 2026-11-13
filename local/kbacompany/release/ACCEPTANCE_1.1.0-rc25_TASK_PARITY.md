# RC25 build2026101355 — Task parity acceptance

Static: PHP lint PASS (see SHA256 bundle). PHPUnit: NOT RUN. Moodle live: NOT RUN.

1. User in company A: assigned open overdue task is present on Tasks.php and Workspace.
2. Counters of open and overdue tasks match Task Center.
3. Completed/cancelled task excluded from open.
4. Task status transition is reflected on both screens after reload.
5. Different-company task not accessible without permissions.

Automated PHPUnit covers scenario 1 and 2 using a canonical Task Center fixture. Scenarios 3-5 remain manual/extended automation acceptance. No final PASS asserted.
