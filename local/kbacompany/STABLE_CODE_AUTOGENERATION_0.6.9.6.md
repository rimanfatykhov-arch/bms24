# Stable Code Autogeneration — BMS 0.6.9.6

## Decision

BMS Administrative Scale users no longer type Stable Code manually. The server allocates an immutable human-readable code once when the canonical object is first created.

Canonical identity remains UUID. Stable Code exists for people, documents, discussions, exports and UI traceability.

## Rules

1. Scope is `Company UUID + entity scope`.
2. Code is generated server-side only.
3. Code never changes when name/content/revision changes.
4. Code is never reused after archive.
5. Gaps are allowed if allocation succeeds but later business-record creation fails; non-reuse is more important than a gap-free sequence.
6. Existing codes are not rewritten.
7. When a generated prefix already exists historically, the next number starts above the maximum observed suffix for that Company + kind.
8. The counter does not replace UUID and must not be used as cross-system canonical identity.

## Administrative Scale prefixes

- Long-term Goal — `LTG`
- Goal — `GOAL`
- Organizational Policy — `POL`
- Plan — `PLN`
- Program — `PRG`
- Program Step — `STEP`
- Project — `PRJ`
- Decision / Order — `ORD`
- Task — `TSK`
- Ideal State — `IDL`
- Statistic — `STAT`
- Valuable Final Product — `CKP`

The service is intentionally reusable by future BMS domains, but 0.6.9.6 integrates it only with the Administrative Scale to avoid a disruptive mass migration of existing domain codes.
