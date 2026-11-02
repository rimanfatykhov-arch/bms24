# DOCUMENTS_BMS_CRUD_0.9.0.4

Normative reference: BMS-UX-CRUD-001 v0.3 (Google Doc 1Hesm-dMT9Bu_RB7HKvYixnooBdHf_xf74HdJ0W98GuE).
Source screenshot: FireShot 091, page1: empty register and manual-code inline create form.

One canonical document_service remains the business owner. New document_ui_service
owns presentation only (no DB, files, transport or domain writes). document_request_service
is transient application-form metadata in the existing Moodle session, not a business engine.
The existing documents.php route dispatches list/create/detail/edit/action and the existing
source=stp/process entrypoint. GET/HEAD never write document records; mutation only POST/sesskey.
Legacy POST forms without the new session proof are rejected and must be reopened.

All new list/detail/subpanel selectors take Company UUID and apply it in SQL. List retrieves
metadata only, with 25-row paging. Reviewer visibility is expressed by company-scoped EXISTS,
not post-fetch PHP filtering. Related user names come through already scoped assignments;
ordinary readers' acknowledgements and records are constrained by actor in SQL.
No new persistent table/capability/service/task/AI/learning or ERP logic.

Content/review/history remain in existing canonical tables. Document headers have UUID + revision,
code is immutable; explicit old codes remain valid. Existing codecounter allocates DOC-xxxx.
The state graph and approvals are reused, with protected mutation transactions and stale-form checks.
Source snapshots remain immutable; no automatic Task completion, Indicator fact update, competency
attainment or canonical process update is performed by document/record actions.

BMS-UX-CRUD coverage: ListView, DetailView default after save, separate EditView, schema-driven
field groups, stable code read-only, human-related labels, status+owner header, secondary Actions,
confirmation, related subpanels with permission-aware tabs, history, progressive technical detail,
field-bound validation, keyboard focus and small-screen wrapping. AI assistance is optional
and was not added in this patch.

Historical master_list/context/semantic API shapes and document contract version0.3.3 are retained.
The compatibility API remains an unpaged historical API; the new UI does not call it for list pages.
New detailed history shows existing audit summaries, not a new generic audit/version engine.
