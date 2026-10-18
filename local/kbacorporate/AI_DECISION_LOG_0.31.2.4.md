# AI DECISION LOG — v0.31.2.4

## Incident
Editing a global business-role template failed with `invalidrecord` because the request attempted to load `{local_kbacorp_company}` id 0.

## Root cause
`business_role_edit.php` used request parameter `companyid` for the return/navigation company, while `business_role_form` correctly used hidden `companyid=0` to represent the scope of a global role. On POST these two meanings collided; Moodle request resolution returned the form value 0, so access validation was executed against company id 0.

## Decision
Separate the two contracts:
- `companyid` inside the role form = actual role scope owner (0 for global template).
- `returncompanyid` = UI/navigation company context only.

The endpoint remains backward compatible with older GET links using `companyid` by reading it from the query string when `returncompanyid` is absent.
