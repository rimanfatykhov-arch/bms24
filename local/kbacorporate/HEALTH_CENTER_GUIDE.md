# KBA Corporate Dashboard — Integration Health Center

Version: 0.14.5  
Build: 2026081803  
Public API: 1.12

## Purpose
The Health Center is the technical control plane for Corporate Dashboard dependencies. It does not modify Moodle, Bitrix24, BigBlueButton or Custom Certificate data.

## Checks
- Moodle runtime and plugin disk/DB build alignment.
- Required Corporate Dashboard tables.
- KBA Connect pairing and remote connection status.
- Bitrix24 corporate-role schema through KBA Connect only.
- BigBlueButton tables/log integration.
- Custom Certificate registry availability.
- Corporate license engine.
- Public API schema/write safety and duplicate checks.

## History
Manual and scheduled runs are stored in `local_kbacorp_health_log`. Only technical aggregate diagnostics are stored; integration tokens and raw remote response bodies are removed.

## Scheduled task
`local_kbacorporate\task\run_health_check` runs daily at 06:35 server time.
