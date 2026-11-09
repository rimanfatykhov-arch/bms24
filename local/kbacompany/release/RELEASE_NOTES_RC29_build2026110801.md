# RC29 candidate 2026110801 — independent employee learning results

Course activity progress, assignment grade and native Moodle competency confirmation are displayed independently in the employee self card. Grades use native gradebook visibility, enrolment and module access checks; hidden/unreleased/blind/stale grades are not disclosed. Zero and scale grades are supported. Company requirements remain scoped; personal Moodle grades are not made visible to managers of other employees.

Scoped desktop widths prevent course details from collapsing to letter-sized columns; the existing mobile stacked table is retained.

Includes the personal competency review queue from 2026110601. A related-work shortcut is shown only for unambiguous first-attempt assignment evidence with native grading rights. Repeated reviews or later evidence retain the native competency-history fallback.

No schema/data migration; version savepoint only. Release remains 1.1.0-rc29.

Local verification at candidate preparation: 14 PHPUnit tests / 140 assertions; 533 PHP files linted under PHP 8.1; RU/EN parity, XML and static/workflow gates pass. Alexey browser shows progress100%, assignment85.00 after Anna saves, competency still awaiting review. These are synthetic demo records.

Acceptance and deployment evidence are recorded outside the install archive. Candidate is for stage41, not production approval. Back up test DB and existing plugin before update; rollback restores both plugin and pre-update DB together. Never downgrade by changing only version metadata.
