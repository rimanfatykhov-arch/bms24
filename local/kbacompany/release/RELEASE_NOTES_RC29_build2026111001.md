# BMS2026111001 — ADR-0004 historical schema repair

Based on PR65 head4e5482df3d5bd8fd4cc604c070ed8728e7c0fc17. Corporate2026110901 unchanged.
Owner approved fixed fresh precision on2026-11-10. New reachable checkpoint1001;
old migration history and install.xml remain unchanged. Runtime identity changes
because this candidate adds DDL; it is not interchangeable with0906.

Repair missing adminitem.starttime/duetime and11 DECIMAL scale-zero columns.
Validate the entire13-field group and all non-NULL DECIMAL values before any group DDL.
Only known legacy/final definitions are accepted; exact SQL DECIMAL bounds, no float,
rounding, clipping or data replacement. Require strict SQL mode and InnoDB.
Postconditions precede savepoint; partial DDL is retryable, not automatically rolled back.

Migration must run with all writers quiesced: strict mode does not eliminate the
preflight/write race. No production/staging access or deployment in this PR.
No authorization, Corporate, Moodle Core or domain rule changes.
Full isolated MariaDB/Percona acceptance and exact-ID regression comparison required.
RC30 remains HOLD pending remaining regression, real-stack and operational gates.
