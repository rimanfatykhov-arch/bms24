# Static test report — 0.26.1

- Version and XMLDB versions match build `2026090272`.
- The additive upgrade adds only the nullable `resulturl` task field.
- Server-side transition validation blocks review submission without a result description.
- File submission uses Moodle draft areas and the existing protected task-evidence file area.
- Evidence download checks the same task access boundary used by the task page.
- Result URLs use Moodle URL validation and open with safe external-link attributes.
- The result URL is declared in privacy metadata and included in task export.
- Russian and English language packs remain unique and in parity.
- `install.xml` parses successfully; referenced literal plugin strings are present.
- ZIP integrity and plugin root are validated during packaging.

The local build environment does not provide the PHP CLI, so PHP lint and runtime browser verification remain required on the target Moodle server.
