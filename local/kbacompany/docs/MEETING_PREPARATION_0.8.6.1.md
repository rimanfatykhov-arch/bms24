# BMS 0.8.6.1 — Meeting Preparation

Checkpoint meetings now receive a read-only preparation context from canonical BMS data.

The preparation view shows:
- previous-week Battle Plans and plan/fact summary;
- canonical position indicators with previous fact, current fact, quota/norm and trend;
- unfinished tasks from the previous Battle Plan;
- current-week Battle Plans;
- a suggested meeting agenda covering plan/fact, indicators, commitments, current plan and coordination.

Architecture rules:
- no new Task, KPI, Battle Plan or Meeting storage;
- quota/norm is read from the existing Indicator/Battle Plan projection;
- unfinished commitments are existing canonical tasks referenced by the previous Battle Plan;
- the suggested agenda is a read model only and does not create decisions or tasks;
- company scope and existing management permissions remain mandatory.
