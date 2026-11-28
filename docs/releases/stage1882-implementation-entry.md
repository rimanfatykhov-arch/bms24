# Stage 1882.0 — BMS Implementation empty company state

## Problem
The implementation entry route `local/kbacorporate/implementation.php` threw
`companynotallowed` when a legitimately authorised user had no eligible
corporate-company context. For staging users this was confusing.

## Change
- Move full-company implementation eligibility filtering into
  `local_kbacorporate\local\implementation_entry_service::manageable_companies()`.
- For users who already have the implementation capability but zero eligible
  companies, show a helpful information message instead of a fatal-looking
  exception. Link to BMS24 company list.
- Preserve hard capability checks and membership isolation:
  users without implementation permission are still denied;
  employee-only or department-only membership is not enough.
- Translate no-company guidance in Russian and English.
- Add PHPUnit tests for no-company, company-manager, employee views.

## Acceptance checklist
- [ ] Staging PHP syntax checks for modified code and both language files.
- [ ] PHPUnit tests: `local_kbacorporate\implementation_entry_service_test`.
- [ ] Signed-in permitted user without linked company sees no-company state.
- [ ] Company manager with a linked company is redirected to the corresponding
      implementation workspace as before.
- [ ] Regular employee without implementation capability still gets permission denied,
      never the manager's implementation workspace.
- [ ] No changes to Moodle Core, no DB migrations.

This branch is independent from the staging-only migration PR #17.
Do not merge to production before smoke + ACL testing.
