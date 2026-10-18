# AI Decision Log v0.34.0.1

## Problem

The customer-company manager could not see BMS implementation because customer operations and global BMS methodology administration shared one capability.

## Decision

Introduce a narrow customer-scoped capability rather than granting `manageimplementationaudits` to corporate managers.

## Rationale

Granting the old capability would also expose Framework Manager, global tools, criteria, mappings and JSON administration. The new access check combines capability plus full membership in the selected company.

## Additional decisions

- Preserve curator/admin-only access to the cross-company Kanban and portfolio analytics.
- Allow company managers to operate the company task list and evidence workflow.
- Hide links that would lead a scoped manager to global methodology pages.
- Grant economic-indicator access to the generated corporate manager role because economics is part of the customer BMS workspace.
- Make no data or schema migration; only role capability assignments are updated.

## Uncertainty / pilot focus

Role capability caching and existing customised role assignments must be verified on the pilot server after Moodle upgrade and cache purge.
