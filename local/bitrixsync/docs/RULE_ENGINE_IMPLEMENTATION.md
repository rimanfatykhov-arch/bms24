# KBA Connect v5.0.17 — Rule Engine Implementation

Added:
- rule evaluation layer;
- stage based matching;
- action definition;
- Deal 3761 rule test.

Architecture:

Bitrix24 Stage
 -> Rule Engine
 -> Actions
 -> Existing v5 execution engine

The rule engine does not replace:
- deal_flow_service
- multi_course_engine
- corporate bridge
- CRM writeback

It decides when the existing engine should run.
