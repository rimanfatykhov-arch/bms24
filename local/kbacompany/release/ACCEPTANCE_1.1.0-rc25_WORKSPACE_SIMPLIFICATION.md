# ACCEPTANCE — 1.1.0-rc25 Workspace Simplification

Goal: make the main "Workspace" page simpler and more understandable without changing domain behaviour.

## Expected result
1. Top of page shows Today first.
2. A single compact overview block shows Inbox / Open tasks / To review / Learning.
3. Overview counters are clickable and open the relevant canonical workspace.
4. My tasks remains visible as a separate actionable list.
5. Weekly execution remains visible for management users.
6. Duplicate four-card management pulse row is removed.
7. Architecture and company context move lower and remain available as reference information.
8. No DB migrations and no regressions in navigation.

## Static result
- Syntax PASS
- Version/savepoint updated PASS
- Packaging PASS

## Requires manual UI acceptance
- Desktop layout
- Tablet/mobile collapse
- Counter links
- Empty states
- Company without management_home access
