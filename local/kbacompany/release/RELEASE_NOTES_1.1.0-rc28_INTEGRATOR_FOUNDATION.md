# RC28 build2026101466 — Integrator foundation and seat inventory

Based on 2026101465. Added an inert company-scoped `Интегратор BMS24` role template. No Moodle site-administrator role or permissions are granted, and no existing users are assigned automatically. Added a company-scoped, read-only count of unique active Moodle user IDs merging canonical and legacy company memberships.

Subscription seat limit, invitation reservations, sending emails, invitation acceptance, and registration are **NOT IMPLEMENTED** and intentionally shown as unconfigured. Academy course seats are independent. No database schema change. PHPUnit and live Moodle acceptance NOT RUN.

Next step: verified Cloud seat entitlement and separate invitation registry with token hashing, TTL, email verification, and company-scoped POST/CSRF controls.
