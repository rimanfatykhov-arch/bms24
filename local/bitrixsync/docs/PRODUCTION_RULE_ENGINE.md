# KBA Connect v5.0.18 — Production Rule Engine

Transition:
- Deal 3761 remains controlled test only.
- Production decisions are driven by database rules.

Flow:

Bitrix24
 -> Rules
 -> Conditions
 -> Actions
 -> Queue
 -> Existing v5 Execution Engine

Core engine remains:
- deal_flow_service
- multi_course_engine
- corporate bridge
- CRM writeback

Rules only decide when execution starts.
