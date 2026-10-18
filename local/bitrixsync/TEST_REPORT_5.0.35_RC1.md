# TEST REPORT — v5.0.35 RC1

## Static build checks
- PHP syntax checked
- version.php checked
- install.xml checked
- upgrade.php + xmldb function checked
- lang/en checked
- lang/ru checked
- ZIP integrity checked

## Production acceptance
1. Set Elvira Contact 12657 roles to employee + department_manager.
2. Bitrix24 sends ONCRMCONTACTUPDATE.
3. KBA Connect queues contact/profile_sync.
4. Moodle cron processes queue within one minute.
5. Queue item completes and payload contains role sync result.
6. Corporate Dashboard shows Elvira: Сотрудник + Руководитель подразделения.
7. Existing course enrolments are unchanged.
8. Message template UI shows only None / Moodle Email SMTP.
9. New access delivery Timeline says Moodle Email / SMTP, not Bitrix24.
