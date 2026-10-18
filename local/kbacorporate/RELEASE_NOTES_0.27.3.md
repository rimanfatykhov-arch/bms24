# KBA Corporate Dashboard 0.27.3

This release makes changes to BMS audit tools safe and traceable.

- A legacy or new populated tool receives an initial published `v1` snapshot.
- Editing a tool or criterion creates an unpublished working draft.
- “Version history” shows the current version and a comparison of changed settings and criteria.
- Publishing requires a change note and creates the next immutable version with a SHA-256 hash.
- New audits copy the exact current published version; unpublished changes do not leak into them.
- Existing audits continue to show the version and criteria with which they were created.

Build: `2026090281`.

After installation, complete the Moodle upgrade and purge all caches. Open a company tool, choose “Version history”, edit one criterion, inspect the comparison, create an audit before publication, publish the draft, then create a second audit and verify their version labels differ.
