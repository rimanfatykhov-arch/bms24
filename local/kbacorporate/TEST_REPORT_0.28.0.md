# Static test report — 0.28.0

- Version, XMLDB marker and upgrade savepoint match build `2026090286`.
- Additive schema introduces `local_kbacorp_bms_comp` and nullable `competenciesjson` only.
- Mapping writes validate company ownership and Moodle competency existence.
- Tool snapshots include deterministic competency metadata; legacy snapshots normalise missing mappings to an empty list.
- New audit items pin the published competency mapping as JSON.
- PHPUnit coverage added for mapping, publication, audit pinning and historical immutability.
- XML, language-key, string-reference, delimiter, archive-root and SHA-256 checks are part of the local release gate.
- PHP CLI and a Moodle test database are unavailable locally; target-server PHP lint, PHPUnit and browser acceptance remain required.
