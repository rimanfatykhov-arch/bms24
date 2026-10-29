# RC28 — BMS24 role-based access acceptance

**Build:** 2026101460. **Live status: NOT RUN**.

| Test | Expected | Actual |
|---|---|---|
| Clean upgrade + 4 RBAC tables (MySQL 5.7) | PASS, no duplicate savepoint | NOT RUN |
| Company owner creates four role templates | Four roles, no automatic assignments | NOT RUN |
| CEO role assigned to verified Moodle ID | Cycles menu appears only for assignee company | NOT RUN |
| View-only user | List/details readable; POST create/edit/close/checkpoint denied | NOT RUN |
| Create-only user | Can view and create; cannot edit other cycles | NOT RUN |
| Edit-only user | Existing domain action flags still enforce owner/curator | NOT RUN |
| Employee no grant | Direct URL and POST blocked | NOT RUN |
| Role removed | Access revoked on next request | NOT RUN |
| Another company | No access to cycles/roles/grants/employee directory | NOT RUN |
| Private personnel/owner notes/integrations | Never opened by cycle role | NOT RUN |
| Site admin / existing owner | Existing authority preserved | NOT RUN |
| POST forged without sesskey | Denied | NOT RUN |
| Permission grant/change log | Actor, role, action and time recorded | NOT RUN |
| PHPUnit access_role_service_test | Test cases pass on Moodle runtime | NOT RUN |
| Upgrade rollback backup restore | Recovery verified | NOT RUN |

**Known scope limitation:** Only company-wide `management_cycle` actions view/create/edit are enforced by RBAC 2.0 in RC28. UI must not indicate other object families are active.
