# BMS Calendar Day Planner — 1.1.0-alpha8

## Principle
The day planner is a presentation projection over already-authorised calendar events. It does not own schedules and does not bypass source-domain permissions.

## Conflict semantics
A conflict is a temporal overlap between displayed timed event blocks. Zero-duration items receive a minimum visual height for readability but do not contribute occupied minutes. Conflict state is not persisted.

## Quick create
The hourly `+` action opens the existing controlled Moodle manual-event editor with the selected local date/time prefilled. Normal target/capability checks still apply on save.
