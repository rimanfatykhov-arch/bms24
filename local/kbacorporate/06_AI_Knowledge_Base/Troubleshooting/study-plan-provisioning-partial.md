# Study plan provisioning completed partially

## Symptom

KBA plan exists, but the result page reports that Moodle provisioning or verification is incomplete.

## Diagnostics

1. Confirm that Moodle competencies are enabled.
2. Check `moodle/competency:planmanage` and `moodle/competency:templatemanage` for the acting user.
3. Open «Интеграция учебных планов Moodle» and retry synchronisation.
4. Verify that the linked Moodle plan belongs to the same employee.
5. Compare the KBA plan item count with the Moodle plan competency count.

## Safety

Do not delete the KBA plan. The operation is retry-safe and stale links can be recovered through the stable KBA marker in the Moodle plan description.
