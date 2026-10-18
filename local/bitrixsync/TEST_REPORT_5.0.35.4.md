# TEST REPORT — v5.0.35.4

Static:
- Full PHP syntax check passed.
- Moodle ZIP root verified.
- version/install/upgrade verified.
- ZIP integrity verified.

Production check:
1. Open KBA Connect -> Corporate.
2. Contact ID 12657.
3. Click "Проверить синхронизацию ролей".
4. Expected: no TypeError.
5. Capture:
   - Role read status
   - Role field
   - Raw Bitrix values
   - Roles from Bitrix
   - Corporate Company ID
   - Role merge status/reason/error
   - Full JSON
