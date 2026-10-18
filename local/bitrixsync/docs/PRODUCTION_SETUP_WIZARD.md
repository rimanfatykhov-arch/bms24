# KBA Connect v5.0.14.4 — Production Setup Wizard

The production CLI sequence is now available from Moodle administration.

## Open

Site administration
→ Plugins
→ Local plugins
→ KBA Connect
→ Production Setup Wizard

Direct URL:

`/local/bitrixsync/setup_wizard.php`

## Wizard steps

1. **Controlled Run Proof**
   - verifies the latest REAL Deal 3761 run;
   - requires 3/3 resolved, zero failures;
   - requires idempotency: zero new enrolments on repeat;
   - requires safe Corporate allocation state.

2. **Finalize Production**
   - replaces:
     `production_finalize.php --dealid=3761 --stage='C3:EXECUTING' --confirm=FINALIZE_3761`
   - provisions/checks CRM fields;
   - enables queue;
   - enables CRM writeback + timeline;
   - sets engine_mode=v5;
   - keeps inbound OFF.

3. **CRM Controlled Test**
   - proves Bitrix Company / Contact / Deal writeback;
   - proves timeline integration;
   - remains idempotent for Moodle and Corporate.

4. **Bitrix24 Event**
   - shows the generated v5 endpoint;
   - operator configures it in Bitrix24;
   - operator explicitly confirms configuration in the wizard.

5. **Automatic Inbound**
   - available only after successful CRM test and Bitrix confirmation;
   - enables the automatic v5 endpoint.

6. **Queue Smoke Test**
   - verifies the final automatic execution path.

## Safety

Every state-changing wizard action uses:
- Moodle capability `local/bitrixsync:manage`;
- Moodle sesskey;
- typed confirmation phrase;
- controlled Deal ID restriction.

The wizard includes **SAFE OFF**:
- inbound OFF;
- queue OFF;
- engine_mode=legacy;
- data and CRM fields preserved.
