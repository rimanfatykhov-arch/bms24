# PR57 remaining39 — triage for PR58

PR57 integrated source80f454bf01a3a925ca302fb3e46cf64b4585386d; actual MariaDB+Percona final JUnit

First reached failure only; later defects may emerge after fixture repair. Independent reviewer flagged four membership cases for compatibility clarification; lead preserves existing canonical policy without adding an adapter. One UI presentation-contract question remains outside this focused PR.

Seven targeted identities: five document tests, one employee sync test and one semantic document test. No assertion is dropped; five negative reviewer cases are added. Remaining32 identities are retained for subsequent work.

Classification: {'confirmed_fixture_or_contract': 34, 'canonical_membership_fixture': 4, 'needs_decision': 1}

| # | Test | First-failure classification | In PR58 |
|---|---|---|---|
|1|`local_kbacompany\battle_plan_indicator_service_test::test_historical_projection_uses_last_52_periods_relative_to_plan_week`|confirmed_fixture_or_contract|no|
|2|`local_kbacompany\battle_plan_management_overview_service_test::test_contract_is_schema_neutral_read_model`|confirmed_fixture_or_contract|no|
|3|`local_kbacompany\battle_plan_service_test::test_battle_plan_references_one_canonical_task_and_closes_after_execution`|confirmed_fixture_or_contract|no|
|4|`local_kbacompany\battle_plan_service_test::test_active_plan_accepts_scoped_manual_task_but_closed_plan_rejects_it`|confirmed_fixture_or_contract|no|
|5|`local_kbacompany\battle_plan_word_export_service_test::test_ooxml_contains_real_word_document_and_cyrillic_text`|confirmed_fixture_or_contract|no|
|6|`local_kbacompany\battle_plan_word_export_service_test::test_model_projects_previous_partial_week_and_current_target`|confirmed_fixture_or_contract|no|
|7|`local_kbacompany\bms_navigation_test::test_only_allowlisted_context_is_propagated`|confirmed_fixture_or_contract|no|
|8|`local_kbacompany\bms_page_shell_service_test::test_contract_versions_are_aligned`|confirmed_fixture_or_contract|no|
|9|`local_kbacompany\bms_page_shell_service_test::test_business_architecture_semantics_follow_bms_methodology`|confirmed_fixture_or_contract|no|
|10|`local_kbacompany\company_core_test::test_contract_declares_existing_source_without_duplication`|confirmed_fixture_or_contract|no|
|11|`local_kbacompany\company_core_test::test_permission_contract_centralises_company_role_policies`|confirmed_fixture_or_contract|no|
|12|`local_kbacompany\company_employee_sync_service_test::test_external_contact_replaces_email_like_phone_but_never_uses_email_as_phone`|confirmed_fixture_or_contract|yes|
|13|`local_kbacompany\deviation_service_test::test_accepted_task_does_not_close_deviation`|confirmed_fixture_or_contract|no|
|14|`local_kbacompany\deviation_service_test::test_recheck_and_explicit_closure_do_not_create_statistics`|confirmed_fixture_or_contract|no|
|15|`local_kbacompany\deviation_service_test::test_lowered_norm_cannot_be_used_as_improvement`|confirmed_fixture_or_contract|no|
|16|`local_kbacompany\deviation_service_test::test_regular_user_cannot_read_deviations`|confirmed_fixture_or_contract|no|
|17|`local_kbacompany\document_service_test::test_lifecycle_master_list_acknowledgement_and_semantic_document`|canonical_membership_fixture|yes|
|18|`local_kbacompany\document_service_test::test_new_effective_revision_supersedes_old_without_rewriting_history`|canonical_membership_fixture|yes|
|19|`local_kbacompany\document_service_test::test_form_record_is_bound_to_exact_effective_form_revision`|canonical_membership_fixture|yes|
|20|`local_kbacompany\document_service_test::test_stp_controlled_document_snapshots_published_process_model`|confirmed_fixture_or_contract|yes|
|21|`local_kbacompany\document_service_test::test_cross_company_document_is_not_resolved`|confirmed_fixture_or_contract|yes|
|22|`local_kbacompany\financial_statement_service_test::test_bdr_and_bdds_calculations_match_bms_finance_rules`|confirmed_fixture_or_contract|no|
|23|`local_kbacompany\indicator_registry_service_test::test_contract_keeps_one_canonical_indicator_registry`|confirmed_fixture_or_contract|no|
|24|`local_kbacompany\integration_event_service_test::test_event_outbox_lifecycle_is_independent_from_legacy_command_status`|confirmed_fixture_or_contract|no|
|25|`local_kbacompany\market_model_service_test::test_contract`|confirmed_fixture_or_contract|no|
|26|`local_kbacompany\ofs_service_test::test_ofs_projects_company_unit_function_products_and_internal_customer`|confirmed_fixture_or_contract|no|
|27|`local_kbacompany\process_model_service_test::test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic`|confirmed_fixture_or_contract|no|
|28|`local_kbacompany\process_service_test::test_process_hierarchy_publish_and_cross_functional_links`|confirmed_fixture_or_contract|no|
|29|`local_kbacompany\project_execution_service_test::test_management_planning_does_not_link_to_retired_editor`|confirmed_fixture_or_contract|no|
|30|`local_kbacompany\semantic_service_test::test_effective_document_is_a_writable_semantic_endpoint`|canonical_membership_fixture|yes|
|31|`local_kbacompany\task_batch_actions1842_test::test_batch_start_accept_and_stale_revisions`|confirmed_fixture_or_contract|no|
|32|`local_kbacompany\task_batch_actions1842_test::test_generated_tasks_cannot_be_bulk_reassigned_and_cross_company_task_is_not_updated`|confirmed_fixture_or_contract|no|
|33|`local_kbacompany\task_deadlines1843_test::test_bucket_filter_and_global_counts_are_separate_and_acl_scoped`|confirmed_fixture_or_contract|no|
|34|`local_kbacompany\task_kanban1846_test::test_editor_and_columns_render_without_raw_html_or_missing_tasks`|confirmed_fixture_or_contract|no|
|35|`local_kbacompany\task_kanban1850_test::test_page_scoped_grouping_and_full_counts_in_board_html`|confirmed_fixture_or_contract|no|
|36|`local_kbacompany\task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace`|needs_decision|no|
|37|`local_kbacompany\task_roles_workspace1840_test::test_compact_workspace_escapes_search_and_has_progressive_get_fallback`|confirmed_fixture_or_contract|no|
|38|`local_kbacompany\task_service_test::test_workspace_and_tasks_page_agree_on_open_and_overdue_tasks`|confirmed_fixture_or_contract|no|
|39|`local_kbacompany\task_service_test::test_workspace_open_tasks_remain_visible_after_completed_history`|confirmed_fixture_or_contract|no|

## Source-grounded diagnosis

### 1. local_kbacompany\battle_plan_indicator_service_test::test_historical_projection_uses_last_52_periods_relative_to_plan_week

Only two historical facts (2026-08-31 and 09-07) are seeded. Future facts must not count; trend sustained-shape classification explicitly requires three facts. The retained previous_fact assertion already proves historical lookup.

Evidence: local/kbacompany/tests/battle_plan_indicator_service_test.php:104; tests/battle_plan_indicator_service_test.php:66; classes/local/indicator_trend_service.php:15

Next: Seed >=3 pre-plan observations and retain future-noise/history assertions; do not relax cutoff.

### 2. local_kbacompany\battle_plan_management_overview_service_test::test_contract_is_schema_neutral_read_model

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/battle_plan_management_overview_service_test.php:11

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 3. local_kbacompany\battle_plan_service_test::test_battle_plan_references_one_canonical_task_and_closes_after_execution

create_manual_task inherits plan reviewer when reviewerid=0. Test submits task then closes plan without that reviewer accepting it; close correctly rejects open work.

Evidence: local/kbacompany/classes/local/battle_plan_service.php:70; local/kbacompany/classes/local/battle_plan_service.php:944; local/kbacompany/tests/battle_plan_service_test.php:122; classes/local/battle_plan_service.php:853; tests/battle_plan_service_test.php:118; classes/local/battle_plan_service.php:944

Next: Perform explicit reviewer acceptance before close; add negative close-before-accept assertion.

### 4. local_kbacompany\battle_plan_service_test::test_active_plan_accepts_scoped_manual_task_but_closed_plan_rejects_it

Test creates an empty plan and immediately submits it. Runtime explicitly rejects empty submission before testing later manual task behavior.

Evidence: local/kbacompany/classes/local/battle_plan_service.php:70; local/kbacompany/classes/local/battle_plan_service.php:898; local/kbacompany/tests/battle_plan_service_test.php:294; tests/battle_plan_service_test.php:291; classes/local/battle_plan_service.php:898

Next: Seed valid baseline work before submit/approve, then test post-approval addition and closed-plan denial.

### 5. local_kbacompany\battle_plan_word_export_service_test::test_ooxml_contains_real_word_document_and_cyrillic_text

Handcrafted OOXML model omits labels.observer. Production model builder supplies it; exporter reads it directly.

Evidence: local/kbacompany/classes/local/battle_plan_word_export_service.php:383; local/kbacompany/tests/battle_plan_word_export_service_test.php:24; tests/battle_plan_word_export_service_test.php:16; classes/local/battle_plan_word_export_service.php:212; classes/local/battle_plan_word_export_service.php:383

Next: Construct the complete model including observer label; retain Cyrillic/OOXML assertions. Optional model validation is separate.

### 6. local_kbacompany\battle_plan_word_export_service_test::test_model_projects_previous_partial_week_and_current_target

Test hardcodes Russian output while actual Moodle runner language is English. Rendering is localized; expected assertion is not language-independent.

Evidence: local/kbacompany/tests/battle_plan_word_export_service_test.php:113; tests/battle_plan_word_export_service_test.php:113; tests/task_roles_workspace1840_test.php:107

Next: Use the owning get_string key or explicitly provision/select the intended language; retain escaping and semantic checks.

### 7. local_kbacompany\bms_navigation_test::test_only_allowlisted_context_is_propagated

Allowlist expectation only names week/battleplan. Management navigation now explicitly marks coordweek as week-context aware; this is a fixed allowlisted route, not arbitrary propagation.

Evidence: local/kbacompany/tests/bms_navigation_test.php:36; tests/bms_navigation_test.php:36; classes/local/bms_workspace_ux_service.php:231; classes/local/bms_workspace_ux_service.php:264

Next: Verify expanded route contract and include coordweek; keep invalid-calendar and unrelated-parameter rejection tests.

### 8. local_kbacompany\bms_page_shell_service_test::test_contract_versions_are_aligned

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/bms_page_shell_service_test.php:12

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 9. local_kbacompany\bms_page_shell_service_test::test_business_architecture_semantics_follow_bms_methodology

Exact old navigation/taxonomy array predates implemented metrics/informationcenter or coordweek/decisionsregistry entries. Failure is the added allowlisted entries, not an unauthorized URL.

Evidence: local/kbacompany/tests/bms_page_shell_service_test.php:44; tests/bms_page_shell_service_test.php:44; tests/project_execution_service_test.php:55; classes/local/bms_workspace_ux_service.php:248

Next: Reconcile explicit current information architecture; retain order, visibility, destination and retired-editor assertions.

### 10. local_kbacompany\company_core_test::test_contract_declares_existing_source_without_duplication

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/company_core_test.php:21

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 11. local_kbacompany\company_core_test::test_permission_contract_centralises_company_role_policies

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/company_core_test.php:79

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 12. local_kbacompany\company_employee_sync_service_test::test_external_contact_replaces_email_like_phone_but_never_uses_email_as_phone

Fixture inserts phone1='badphone@example.test' (21 characters) into Moodle's 20-character phone field; DB rejects before contact-sync code runs.

Evidence: local/kbacompany/tests/company_employee_sync_service_test.php:103; tests/company_employee_sync_service_test.php; moodle/lib/db/install.xml:user.phone1

Next: Use <=20-character email-like phone sentinel and preserve replacement/never-email-as-phone assertions. Root owns this change.

### 13. local_kbacompany\deviation_service_test::test_accepted_task_does_not_close_deviation

Fixture uses userdate(time(), '%Y-%m-%d') with Moodle fixday=true default: leading day zero is removed on days1–9. validate_period requires exact Y-m-d; fails before deviation business flow.

Evidence: local/kbacompany/classes/local/product_service.php:215; local/kbacompany/classes/local/deviation_service.php:89; local/kbacompany/classes/local/deviation_service.php:167; local/kbacompany/classes/local/deviation_service.php:467; local/kbacompany/tests/deviation_service_test.php:54; local/kbacompany/tests/deviation_service_test.php:61; tests/deviation_service_test.php:33; classes/local/deviation_service.php:89; classes/local/product_service.php:206; moodle/lib/moodlelib.php:2316

Next: Use canonical padded UTC date or explicitly disable fixday; retain period-boundary and all workflow assertions.

### 14. local_kbacompany\deviation_service_test::test_recheck_and_explicit_closure_do_not_create_statistics

Fixture uses userdate(time(), '%Y-%m-%d') with Moodle fixday=true default: leading day zero is removed on days1–9. validate_period requires exact Y-m-d; fails before deviation business flow.

Evidence: local/kbacompany/classes/local/product_service.php:215; local/kbacompany/classes/local/deviation_service.php:89; local/kbacompany/classes/local/deviation_service.php:167; local/kbacompany/classes/local/deviation_service.php:467; local/kbacompany/tests/deviation_service_test.php:54; local/kbacompany/tests/deviation_service_test.php:71; tests/deviation_service_test.php:33; classes/local/deviation_service.php:89; classes/local/product_service.php:206; moodle/lib/moodlelib.php:2316

Next: Use canonical padded UTC date or explicitly disable fixday; retain period-boundary and all workflow assertions.

### 15. local_kbacompany\deviation_service_test::test_lowered_norm_cannot_be_used_as_improvement

Fixture uses userdate(time(), '%Y-%m-%d') with Moodle fixday=true default: leading day zero is removed on days1–9. validate_period requires exact Y-m-d; fails before deviation business flow.

Evidence: local/kbacompany/classes/local/product_service.php:215; local/kbacompany/classes/local/deviation_service.php:89; local/kbacompany/classes/local/deviation_service.php:167; local/kbacompany/classes/local/deviation_service.php:467; local/kbacompany/tests/deviation_service_test.php:54; local/kbacompany/tests/deviation_service_test.php:84; tests/deviation_service_test.php:33; classes/local/deviation_service.php:89; classes/local/product_service.php:206; moodle/lib/moodlelib.php:2316

Next: Use canonical padded UTC date or explicitly disable fixday; retain period-boundary and all workflow assertions.

### 16. local_kbacompany\deviation_service_test::test_regular_user_cannot_read_deviations

Fixture uses userdate(time(), '%Y-%m-%d') with Moodle fixday=true default: leading day zero is removed on days1–9. validate_period requires exact Y-m-d; fails before deviation business flow.

Evidence: local/kbacompany/classes/local/product_service.php:215; local/kbacompany/classes/local/deviation_service.php:89; local/kbacompany/classes/local/deviation_service.php:167; local/kbacompany/classes/local/deviation_service.php:467; local/kbacompany/tests/deviation_service_test.php:54; local/kbacompany/tests/deviation_service_test.php:102; tests/deviation_service_test.php:33; classes/local/deviation_service.php:89; classes/local/product_service.php:206; moodle/lib/moodlelib.php:2316

Next: Use canonical padded UTC date or explicitly disable fixday; retain period-boundary and all workflow assertions.

### 17. local_kbacompany\document_service_test::test_lifecycle_master_list_acknowledgement_and_semantic_document

Fixtures insert reviewer only into local_kbacorp_member. document_service::company_member queries canonical local_kbacompany_member. Runtime fails before review lifecycle. Need decide supported legacy membership adaptation vs canonical-fixture-only setup; do not bypass membership checks.

Evidence: local/kbacompany/classes/local/document_service.php:62; local/kbacompany/classes/local/document_service.php:423; local/kbacompany/classes/local/document_service.php:415; local/kbacompany/classes/local/document_service.php:1015; local/kbacompany/classes/local/document_service.php:416; local/kbacompany/tests/document_service_test.php:36; local/kbacompany/tests/document_service_test.php:52; tests/document_service_test.php:24; tests/semantic_service_test.php:134; classes/local/document_service.php:130; classes/local/document_service.php:423

Lead resolution: Preserve the existing canonical BMS reviewer boundary under ADR-0002. Add explicit active BMS membership to a positive fixture; do not grant review eligibility from Corporate or admin status. No runtime policy change or legacy adapter is introduced.

Next: Run the repaired positive lifecycle and negative membership cases on both databases; reconsider runtime behavior only if these tests expose a defect.

### 18. local_kbacompany\document_service_test::test_new_effective_revision_supersedes_old_without_rewriting_history

Fixtures insert reviewer only into local_kbacorp_member. document_service::company_member queries canonical local_kbacompany_member. Runtime fails before review lifecycle. Need decide supported legacy membership adaptation vs canonical-fixture-only setup; do not bypass membership checks.

Evidence: local/kbacompany/classes/local/document_service.php:62; local/kbacompany/classes/local/document_service.php:423; local/kbacompany/classes/local/document_service.php:415; local/kbacompany/classes/local/document_service.php:1015; local/kbacompany/classes/local/document_service.php:416; local/kbacompany/tests/document_service_test.php:36; local/kbacompany/tests/document_service_test.php:89; tests/document_service_test.php:24; tests/semantic_service_test.php:134; classes/local/document_service.php:130; classes/local/document_service.php:423

Lead resolution: Preserve the existing canonical BMS reviewer boundary under ADR-0002. Add explicit active BMS membership to a positive fixture; do not grant review eligibility from Corporate or admin status. No runtime policy change or legacy adapter is introduced.

Next: Run the repaired positive lifecycle and negative membership cases on both databases; reconsider runtime behavior only if these tests expose a defect.

### 19. local_kbacompany\document_service_test::test_form_record_is_bound_to_exact_effective_form_revision

Fixtures insert reviewer only into local_kbacorp_member. document_service::company_member queries canonical local_kbacompany_member. Runtime fails before review lifecycle. Need decide supported legacy membership adaptation vs canonical-fixture-only setup; do not bypass membership checks.

Evidence: local/kbacompany/classes/local/document_service.php:62; local/kbacompany/classes/local/document_service.php:423; local/kbacompany/classes/local/document_service.php:415; local/kbacompany/classes/local/document_service.php:1015; local/kbacompany/classes/local/document_service.php:416; local/kbacompany/tests/document_service_test.php:36; local/kbacompany/tests/document_service_test.php:115; tests/document_service_test.php:24; tests/semantic_service_test.php:134; classes/local/document_service.php:130; classes/local/document_service.php:423

Lead resolution: Preserve the existing canonical BMS reviewer boundary under ADR-0002. Add explicit active BMS membership to a positive fixture; do not grant review eligibility from Corporate or admin status. No runtime policy change or legacy adapter is introduced.

Next: Run the repaired positive lifecycle and negative membership cases on both databases; reconsider runtime behavior only if these tests expose a defect.

### 20. local_kbacompany\document_service_test::test_stp_controlled_document_snapshots_published_process_model

STP fixture reaches process publish without supplier/customer semantic links. Published SIPOC explicitly requires supplier/input/output/customer; runtime rejects missing two links.

Evidence: local/kbacompany/classes/local/process_model_service.php:1036; local/kbacompany/tests/document_service_test.php:170; tests/document_service_test.php:170; classes/local/process_model_service.php:43; classes/local/process_model_service.php:962

Next: Create scoped supplier/customer semantic links; keep snapshot-of-published-model assertion and negative missing-link tests.

### 21. local_kbacompany\document_service_test::test_cross_company_document_is_not_resolved

Read path intentionally returns generic document_notavailable for missing/foreign documents; test expects older document_reference. Actual integrated access test verifies no cross-company read/write.

Evidence: local/kbacompany/tests/document_service_test.php:193; tests/document_service_test.php:193; classes/local/document_service.php:1043; tests/rc30_integrated_access_test.php

Next: Expect public non-disclosing code and retain foreign-document non-resolution plus nonmutation checks.

### 22. local_kbacompany\financial_statement_service_test::test_bdr_and_bdds_calculations_match_bms_finance_rules

Fixture arithmetic is 1000-300-200+50-20-30=500, matching runtime. Expected netprofit450 is arithmetically inconsistent with its input values.

Evidence: local/kbacompany/tests/financial_statement_service_test.php:32; tests/financial_statement_service_test.php:25; classes/local/financial_statement_service.php:198

Next: Correct expectation to500 with intermediate PBT/NP assertions; preserve BDR/BDDS distinction.

### 23. local_kbacompany\indicator_registry_service_test::test_contract_keeps_one_canonical_indicator_registry

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/indicator_registry_service_test.php:14

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 24. local_kbacompany\integration_event_service_test::test_event_outbox_lifecycle_is_independent_from_legacy_command_status

fail_event increments attempts, retry_event does not erase history, complete_event increments again. One failure + one completion gives2 total attempts, while test expects1.

Evidence: local/kbacompany/tests/integration_event_service_test.php:78; tests/integration_event_service_test.php:68; classes/local/integration_event_service.php:192; classes/local/integration_event_service.php:208; classes/local/integration_event_service.php:214

Next: Assert0→1 after failure→1 after retry→2 after completion; preserve independent legacy-command status assertions.

### 25. local_kbacompany\market_model_service_test::test_contract

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/market_model_service_test.php:8

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 26. local_kbacompany\ofs_service_test::test_ofs_projects_company_unit_function_products_and_internal_customer

OFS now declares processes_available=true; test still expects false after checking valid product/function/interface projection.

Evidence: local/kbacompany/tests/ofs_service_test.php:73; tests/ofs_service_test.php:73; classes/local/ofs_service.php:345

Next: Align availability contract with implemented process subsystem; retain all preceding projection checks.

### 27. local_kbacompany\process_model_service_test::test_complete_sipoc_bpmn_model_publishes_and_activity_becomes_semantic

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/process_model_service_test.php:85

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 28. local_kbacompany\process_service_test::test_process_hierarchy_publish_and_cross_functional_links

Failure is an obsolete literal contract version, not a failed behavioral assertion. JUnit expected/actual version diff must be reviewed with the owning service contract; preserve all later semantic assertions.

Evidence: local/kbacompany/tests/process_service_test.php:56

Next: Align the declared version assertion to the actual approved component contract and rerun the entire method; do not delete version/semantic checks.

### 29. local_kbacompany\project_execution_service_test::test_management_planning_does_not_link_to_retired_editor

Exact old navigation/taxonomy array predates implemented metrics/informationcenter or coordweek/decisionsregistry entries. Failure is the added allowlisted entries, not an unauthorized URL.

Evidence: local/kbacompany/tests/project_execution_service_test.php:55; tests/bms_page_shell_service_test.php:44; tests/project_execution_service_test.php:55; classes/local/bms_workspace_ux_service.php:248

Next: Reconcile explicit current information architecture; retain order, visibility, destination and retired-editor assertions.

### 30. local_kbacompany\semantic_service_test::test_effective_document_is_a_writable_semantic_endpoint

The semantic fixture uses the site administrator as reviewer without canonical BMS membership. Admin management privilege does not itself satisfy document reviewer eligibility.

Evidence: local/kbacompany/classes/local/document_service.php:62; local/kbacompany/classes/local/document_service.php:423; local/kbacompany/classes/local/document_service.php:415; local/kbacompany/classes/local/document_service.php:1015; local/kbacompany/classes/local/document_service.php:416; local/kbacompany/tests/semantic_service_test.php:134; tests/document_service_test.php:24; tests/semantic_service_test.php:134; classes/local/document_service.php:130; classes/local/document_service.php:423

Lead resolution: Preserve the existing canonical BMS reviewer boundary under ADR-0002. Add explicit active BMS membership to a positive fixture; do not grant review eligibility from Corporate or admin status. No runtime policy change or legacy adapter is introduced.

Next: Run the repaired positive lifecycle and negative membership cases on both databases; reconsider runtime behavior only if these tests expose a defect.

### 31. local_kbacompany\task_batch_actions1842_test::test_batch_start_accept_and_stale_revisions

Fixture passes site administrator as task reviewer but only worker/replacement are company employees. require_employee correctly rejects unlisted reviewer; admin privilege is not membership.

Evidence: local/kbacompany/classes/local/task_service.php:68; local/kbacompany/classes/local/task_service.php:197; local/kbacompany/classes/local/task_service.php:550; local/kbacompany/classes/local/task_service.php:590; local/kbacompany/tests/task_batch_actions1842_test.php:38; local/kbacompany/tests/task_batch_actions1842_test.php:60; tests/task_batch_actions1842_test.php:60; classes/local/task_service.php:550; classes/local/task_service.php:197

Next: Create an eligible reviewer membership and keep stale-revision/batch acceptance tests.

### 32. local_kbacompany\task_batch_actions1842_test::test_generated_tasks_cannot_be_bulk_reassigned_and_cross_company_task_is_not_updated

Cross-company fixture creates an Other-company task assigned to admin without employee membership in that Other company. Fails at creation before the isolation assertion.

Evidence: local/kbacompany/classes/local/task_service.php:68; local/kbacompany/classes/local/task_service.php:197; local/kbacompany/classes/local/task_service.php:546; local/kbacompany/classes/local/task_service.php:590; local/kbacompany/tests/task_batch_actions1842_test.php:38; local/kbacompany/tests/task_batch_actions1842_test.php:99; tests/task_batch_actions1842_test.php:99; tests/task_deadlines1843_test.php; classes/local/task_service.php:546

Next: Seed legitimate target-company employee then assert original scope cannot mutate/read foreign task; do not remove validation.

### 33. local_kbacompany\task_deadlines1843_test::test_bucket_filter_and_global_counts_are_separate_and_acl_scoped

Cross-company fixture creates an Other-company task assigned to admin without employee membership in that Other company. Fails at creation before the isolation assertion.

Evidence: local/kbacompany/classes/local/task_service.php:68; local/kbacompany/classes/local/task_service.php:197; local/kbacompany/classes/local/task_service.php:546; local/kbacompany/classes/local/task_service.php:590; local/kbacompany/tests/task_deadlines1843_test.php:35; local/kbacompany/tests/task_deadlines1843_test.php:74; tests/task_batch_actions1842_test.php:99; tests/task_deadlines1843_test.php; classes/local/task_service.php:546

Next: Seed legitimate target-company employee then assert original scope cannot mutate/read foreign task; do not remove validation.

### 34. local_kbacompany\task_kanban1846_test::test_editor_and_columns_render_without_raw_html_or_missing_tasks

Kanban renderer fixture passes 'test-company', not a valid UUID/existing authorized company. New renderer path reaches strict company boundary; validation error occurs before HTML assertions.

Evidence: local/kbacompany/classes/local/company_context_service.php:36; local/kbacompany/classes/local/company_context_service.php:64; local/kbacompany/classes/local/access_role_service.php:127; local/kbacompany/classes/local/task_service.php:2412; local/kbacompany/classes/local/task_ui_service.php:950; local/kbacompany/tests/task_kanban1846_test.php:100; tests/task_kanban1846_test.php:100; tests/task_kanban1850_test.php:105; classes/local/company_context_service.php:34

Next: Use generated authorized company context and valid IDs, keep escaping/page-count/board assertions.

### 35. local_kbacompany\task_kanban1850_test::test_page_scoped_grouping_and_full_counts_in_board_html

Kanban renderer fixture passes 'test-company', not a valid UUID/existing authorized company. New renderer path reaches strict company boundary; validation error occurs before HTML assertions.

Evidence: local/kbacompany/classes/local/company_context_service.php:36; local/kbacompany/classes/local/company_context_service.php:64; local/kbacompany/classes/local/access_role_service.php:127; local/kbacompany/classes/local/task_service.php:2414; local/kbacompany/classes/local/task_ui_service.php:950; local/kbacompany/tests/task_kanban1850_test.php:105; tests/task_kanban1846_test.php:100; tests/task_kanban1850_test.php:105; classes/local/company_context_service.php:34

Next: Use generated authorized company context and valid IDs, keep escaping/page-count/board assertions.

### 36. local_kbacompany\task_manager_workspace1853_test::test_non_manager_never_receives_manager_workspace

Manager workspace data is guarded (model.can_manage=false returns restricted note), but toolbar hidden form retains display=manager. Failure is parameter retention, not demonstrated privileged data access. Decide UI contract: normalize display before toolbar, or permit non-actionable retained request state.

Evidence: local/kbacompany/tests/task_manager_workspace1853_test.php:58; tests/task_manager_workspace1853_test.php:58; classes/local/task_ui_service.php:814

Next: Do not weaken data/ACL assertions. Review route enforcement and choose consistent safe display normalization; test both URL/form and absence of manager data.

### 37. local_kbacompany\task_roles_workspace1840_test::test_compact_workspace_escapes_search_and_has_progressive_get_fallback

Test hardcodes Russian output while actual Moodle runner language is English. Rendering is localized; expected assertion is not language-independent.

Evidence: local/kbacompany/tests/task_roles_workspace1840_test.php:107; tests/battle_plan_word_export_service_test.php:113; tests/task_roles_workspace1840_test.php:107

Next: Use the owning get_string key or explicitly provision/select the intended language; retain escaping and semantic checks.

### 38. local_kbacompany\task_service_test::test_workspace_and_tasks_page_agree_on_open_and_overdue_tasks

Task fixture default startdate2026-09-14 is combined with overridden due2026-01-01. Date validation correctly rejects deadline before start, before workspace history behavior is exercised.

Evidence: local/kbacompany/classes/local/task_service.php:68; local/kbacompany/classes/local/task_service.php:575; local/kbacompany/classes/local/task_service.php:590; local/kbacompany/tests/task_service_test.php:34; local/kbacompany/tests/task_service_test.php:187; tests/task_service_test.php:34; tests/task_service_test.php:187; tests/task_service_test.php:210; classes/local/task_service.php:575

Next: Generate internally valid overdue dates and matching/empty startdate; retain count consistency and completed-history pagination assertions.

### 39. local_kbacompany\task_service_test::test_workspace_open_tasks_remain_visible_after_completed_history

Task fixture default startdate2026-09-14 is combined with overridden due2026-01-01. Date validation correctly rejects deadline before start, before workspace history behavior is exercised.

Evidence: local/kbacompany/classes/local/task_service.php:68; local/kbacompany/classes/local/task_service.php:575; local/kbacompany/classes/local/task_service.php:590; local/kbacompany/tests/task_service_test.php:34; local/kbacompany/tests/task_service_test.php:210; tests/task_service_test.php:34; tests/task_service_test.php:187; tests/task_service_test.php:210; classes/local/task_service.php:575

Next: Generate internally valid overdue dates and matching/empty startdate; retain count consistency and completed-history pagination assertions.
