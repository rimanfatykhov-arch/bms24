# AI Decision Log — v5.0.36.1.7

Catalog mapping tables are now part of the canonical install schema.
Every upgrade must tolerate historically evolved installations and add missing
fields idempotently before catalog business logic relies on them.
