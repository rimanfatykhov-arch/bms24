# AI DECISION LOG — v0.31.2.2

## Decision
Do not map a BMS domain to every direct child competency automatically for a business role. Domain membership is taxonomy; a role competency profile is a curated requirement set.

## Safety rules
- Selection is empty by default.
- Each selected child has independent target level, priority and mandatory flag.
- Existing concrete role requirements are preserved.
- Domain deletion requires explicit opt-in and a surviving concrete direct child.
- No automatic migration occurs during Moodle upgrade.

## Reason
The Owner role preview showed that BMS-D00 and BMS-D01 would expand to a much broader set than the intended role core. Selective migration prevents accidental over-specification of roles and polluted GAP/Learning Plans.
