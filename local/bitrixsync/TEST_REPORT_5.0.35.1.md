# TEST REPORT — v5.0.35.1

Static checks:
- PHP syntax checked
- version/install/upgrade checked
- lang/en + lang/ru retained
- ZIP integrity checked

Production acceptance:
1. Elvira Contact 12657 has employee + department_manager.
2. Save Contact in Bitrix24 to emit ONCRMCONTACTUPDATE.
3. Cron processes contact/profile_sync.
4. Corporate page shows:
   - Bitrix Company ID = 973
   - Corporate Company ID = 1
5. Result = merged.
6. KBA Corporate Dashboard shows:
   - Сотрудник
   - Руководитель подразделения
7. Existing enrolments remain unchanged.
