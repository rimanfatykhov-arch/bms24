# BMS24 RC29 — build 2026101516 — Semantic AI field context

- Server authoritative Russian field labels and descriptions for all seven BMS AI domains and their fields.
- Generic drafttext has per-domain business semantics, not a literal placeholder presented to model/users.
- AI drawer displays human-readable labels from server-owned button metadata.
- Companion local_kbaai build 2026101516 instructs model to honor field_context.field_label / field_help.
- No changes to business tables or canonical records. Proposal-only contract and ACL unchanged.
- PHP lint and JavaScript syntax checked; no live Moodle or provider calls completed.
