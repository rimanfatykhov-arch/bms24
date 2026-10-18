# KBA Corporate Dashboard v0.29.4
## BMS Competency IO Import Wizard

Architecture correction:
- Existing `bms_competency_io.php` remains the primary JSON import/export entry point.
- Separate validator page is not used as user entry point.

Flow:
JSON Library → bms_competency_io → Preview → Apply.

This release preserves:
- Framework Manager;
- JSON export;
- BMS competency mappings;
- existing Moodle Form API import flow.
