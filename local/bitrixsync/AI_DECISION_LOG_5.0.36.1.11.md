# AI Decision Log — v5.0.36.1.11

Historical NOT NULL legacy columns remain part of production compatibility until a later
cleanup release. Current code dual-writes canonical and legacy mapping fields so new
records can be created safely without destructive schema changes.
