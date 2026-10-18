# AI Decision Log — v5.0.35 RC1

Decision 1:
Authenticated Contact update events should synchronize identity/corporate roles,
but must not trigger Deal-driven course enrolment.

Decision 2:
Corporate roles remain MERGE-only to protect manually assigned rights.

Decision 3:
A delivery channel must reflect the actual implementation. Moodle Email / SMTP
is the only enabled outbound access transport until a genuine Bitrix24 mail
implementation exists.
