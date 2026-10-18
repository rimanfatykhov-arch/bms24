# KBA Connect v5.0.31.3 RC1

## Auto Moodle User Creation Control

- Contact hydration from linked deal contacts remains enabled.
- Automatic Moodle user creation is explicitly enabled during upgrade.
- The main KBA Connect settings page now exposes the auto-create switch.
- Production Control Center shows the current auto-create status and can enable it safely with sesskey protection.
- Existing Moodle users are still matched first by explicit mapping and then unique email.
- Duplicate creation protection remains in place.
- Intended test case: Deal 3761 / Bitrix Contact 12657.
