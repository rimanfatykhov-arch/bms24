# KBA Corporate Dashboard 0.17.5

Build: `2026090204`  
Date: 2026-09-02

## Added

- **Rename** action in the competency audit history.
- **Delete audit** action with an explicit confirmation warning.
- Company-scope and capability checks for both actions.
- Transactional deletion of collected audit evidence, audit assessments and the audit session.

Deleting an audit does not roll back competency levels already confirmed through the Moodle Competencies API.

## Included fixes

The complete archive also includes v0.17.4.1 global-template saving and v0.17.4.2 management navigation.

## Upgrade

Install the complete ZIP over the existing plugin without uninstalling it. No database structure changes are made. Purge Moodle caches after upgrading.
