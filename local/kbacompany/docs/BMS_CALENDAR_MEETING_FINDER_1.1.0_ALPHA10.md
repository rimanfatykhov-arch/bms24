# BMS Calendar Meeting Finder — 1.1.0-alpha10

The finder is a read-only scheduling projection. It never becomes a source of truth.

Rules:
1. Company UUID remains the security boundary.
2. Employee selection is restricted to the already-authorised company directory.
3. BMS busy intervals come only from records already visible to the actor.
4. Only the signed-in user's own Moodle calendar participates; other users' private Moodle calendars are never read.
5. Suggested availability is advisory because private external/Moodle calendars can be unknown.
6. Create Meeting opens the existing Meeting workspace with prefilled values; canonical Meeting service performs all validation and writes.
7. No slot reservation, no hidden writes, no new calendar storage.
