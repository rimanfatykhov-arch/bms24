# PR86 independent QA/security review

Reviewed baseline:341159b1a677495f759078c5dd882ccae554d5f0. Two separate reviewers, QA and security, inspected the patch and existing evidence independently. They did not author fixes, modify servers, or provide human approval.

| Finding | Evidence | Resolution |
|---|---|---|
| P2 browser false green | QA loaded successful observations, changed three text flags to false and direct POST to500; original final assertions still passed | Extracted fail-closed contract; require text, selected-file feedback, direct303 plus unique rendered file/hash; negative mutation tests |
| Advisory CSS misclassification | opacity0 and escaped resource URL could receive FAST | All CSS now FULL pending a semantic allowlist; real-diff and bypass regression tests |
| Browser request containment incomplete | First context only; API downloads before origin check | Exact HTTP loopback origin checked before download, redirects off, routes on every context; no OS firewall claim |
| Evidence lacked terminal verdict | JSON written before final ACL loop | DefaultFAIL, terminalPASS only after contract/SQL/ACL checks |
| Assertions removable with Python-O | Not enabled in existing workflow, hardening limitation | Explicit require; contract negative controls run normally and with-O |

Re-review: QA confirmed original P2 resolved in code and tests; security found no blocking security issue. Each reviewer independently ran6 contract tests normally and6 with-O and diff whitespace check. Primary also ran5 classifier tests. All passed. Minor first-context scheme consistency was aligned to the same HTTP-origin condition as later contexts; explicit verify mode and strict scratch subdirectory guards are checked before browser activity.

Unchanged relative to reviewed baseline: original FULL workflows, moodle_acceptance.php, standard_browser_fixture.php, all product/Core/Corporate source and release policy. Existing62 focused PHPUnit and DB no-write/duplicate probes are retained.

These are independent AI reviews with bounded static/local scope. New browser behavior must pass MariaDB/Percona CI on the fixing commit. Do not transfer previous head runtime PASS to a new head. Current runtime results, exact SHA and owner approval card are recorded in PR86 after runs; human approval is still separate. No merge/deployment is authorized by this document.
