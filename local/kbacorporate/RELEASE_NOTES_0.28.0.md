# KBA Corporate Dashboard 0.28.0

This release connects the BMS implementation audit with the Moodle competency model.

## What changed

- A BMS criterion can now be linked to multiple competencies.
- Each link records the target level, priority and whether the competency is mandatory.
- The tool card shows linked competencies next to each criterion.
- Competency mappings are included in the tool working copy, version comparison and SHA-256 publication snapshot.
- A new audit copies the exact published mapping into its items; later edits do not rewrite historical audits.
- Audit and implementation-plan pages display their version-pinned competency requirements.

## Upgrade

Install the ZIP over the existing plugin and complete the Moodle database upgrade. Existing tools, criteria, audits, tasks and competency data are retained.

Build: `2026090286`.
