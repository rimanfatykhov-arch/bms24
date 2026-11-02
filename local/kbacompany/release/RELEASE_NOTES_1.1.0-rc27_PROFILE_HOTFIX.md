# BMS24 1.1.0-rc27 / build2026101459 — Self profile inside BMS24

- Own employee card **Edit personal details** goes to `employee_self_profile.php` rendered in BMS24 App Shell.
- Only authenticated Moodle user can edit their own first name, last name and phone; Moodle `moodle/user:editownprofile` and company membership are checked server-side.
- Employee directory managers continue to use existing HR edit route for other employees. No HR capability is conferred on self-service users.
- Email, organization structure, membership, positions, ACL, synchronization IDs and credentials are not modified.
- Reuses core `user_update_user()` and Moodle session CSRF protection. No new tables.
- Test matrix: self edit / another person's link denial / CSRF / manager edit unchanged / company isolation / app shell fallback. Live Moodle acceptance NOT RUN.
