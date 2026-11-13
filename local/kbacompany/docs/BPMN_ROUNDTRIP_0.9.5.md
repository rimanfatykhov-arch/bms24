# BMS24 0.9.5 — BPMN Round-trip & Layout Reset

- Browser file import for `.bpmn` / `.xml` into the existing bpmn-js editor.
- Existing server semantic guard remains authoritative: imported XML must exactly match canonical BMS nodes, flows, labels and lanes.
- New reset action removes only stored layout XML for the draft and regenerates layout from canonical `procelem/procflow`.
- No semantic import, no automatic task/RACI changes, no execution engine, no schema/ACL/privacy changes.
