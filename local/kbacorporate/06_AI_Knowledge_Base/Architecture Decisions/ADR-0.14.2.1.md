# ADR — v0.14.2.1

Canonical company membership is one row per `(companyid, userid)`.
Roles are independent flags `isemployee` and `ismanager`.
`membertype` remains only for compatibility with legacy code/data.
See `AI_DECISION_LOG.md` in the plugin root for full rationale.
