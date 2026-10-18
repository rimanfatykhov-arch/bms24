# Test Report — 0.23.1.1

Build: `2026090255`

Local gate covers XML parsing, language parity and duplicates, PHP structural checks, version consistency, conflict markers and ZIP integrity. Browser acceptance and `php -l` remain required on the target Moodle server because PHP is unavailable in the build environment.

Acceptance checklist:

1. The top bar shows “BMS implementation” beside “My competencies” on the Moodle home page.
2. A manager with one available company opens its implementation workspace directly.
3. A multi-company administrator sees a company chooser when no company is selected.
4. Choosing a company opens only that tenant's audits, packages, requests and programs.
5. A validated company-scoped page links directly to the same company's implementation workspace.
6. Existing direct package selection and duplicate-request protection remain operational.
