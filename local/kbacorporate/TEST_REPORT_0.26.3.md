# Static test report — 0.26.3

- Version and XMLDB versions match build `2026090274`.
- The release requires no database schema change and includes an additive upgrade savepoint.
- Board access is limited to assigned curators and authorised implementation administrators.
- Curator scope is enforced before task filtering and rendering.
- Search and company/curator filters operate only on already authorised task records.
- Task cards retain direct links to the controlled task workflow.
- Responsive CSS uses wrapping columns and introduces no mandatory page-level horizontal overflow.
- Russian and English language packs remain unique and in parity.
- `install.xml` parses successfully; referenced literal plugin strings are present.
- ZIP integrity and plugin root are validated during packaging.

The local build environment does not provide the PHP CLI, so PHP lint and browser verification remain required on the target Moodle server.
