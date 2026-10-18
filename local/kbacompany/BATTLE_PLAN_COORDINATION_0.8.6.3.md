# BMS 0.8.6.3 — Battle Plan Coordination

## Purpose

Add a small coordination layer between canonical weekly Battle Plans without granting cross-plan edit or approval authority.

## Contract

- one coordination issue links one source Battle Plan to one target Battle Plan in the same management week;
- source plan owner/reviewer (or an existing company management role) may raise the issue;
- target plan owner/reviewer (or an existing company management role) may agree or decline;
- observer remains read-only;
- agreement is not approval and never edits either Battle Plan, task, quota, indicator or task status;
- real plan changes are made through the normal Battle Plan owner/reviewer workflow and can be resubmitted for approval;
- records are company-scoped before fetch;
- history is retained as coordination records.

## Why

Management coordination aligns actions between people/units, but it must not replace vertical management. This release therefore records dependencies and agreements while preserving canonical ownership of Battle Plans and tasks.
