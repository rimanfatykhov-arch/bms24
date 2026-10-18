# Static test report — 0.26.0

- Version and XMLDB versions match build `2026090271`.
- The additive upgrade creates `local_kbacorp_impl_tasklog` with task/user foreign keys and history indexes.
- Transition permissions and source statuses are checked server-side.
- All transition POST actions require a valid Moodle session key.
- Assigned employees, curators and authorised managers retain the existing task access boundary.
- Status changes produce history records and isolated Moodle notifications with direct task links.
- Russian and English language packs remain unique and in parity.
- `install.xml` parses successfully; referenced literal plugin strings are present.
- ZIP integrity and plugin root are validated during packaging.

The local build environment does not provide the PHP CLI, so PHP lint and runtime browser verification remain required on the target Moodle server.
