# local_kbacompany 0.9.9 — IDEF0 Interconnections

Build: 2026100601
Status: ALPHA

Adds directed interconnections between direct sibling functions in an IDEF0 decomposition by reusing existing Semantic Core `Process -> supplies -> Process` relations. The relation is architecture-level and does not create an IDEF0 flow table, alter ICOM, write SIPOC/BPMN data, or introduce an execution engine. Creation and removal are manager-only, company-scoped and guarded by the current parent Process revision; removal archives only the semantic relation.
