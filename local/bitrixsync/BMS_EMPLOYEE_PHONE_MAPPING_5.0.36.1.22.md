# BMS Employee Phone Mapping — 5.0.36.1.22

Flow: BMS employee -> Moodle User -> KBA Connect -> Bitrix Contact.

Rules:
1. EMAIL is always populated from the canonical Moodle/BMS email.
2. PHONE is populated only from a non-empty, non-email phone candidate.
3. Existing Bitrix contacts are updated when the desired valid phone differs.
4. An existing PHONE value that is itself an email is treated as invalid.
5. No course enrolment behavior is changed.
