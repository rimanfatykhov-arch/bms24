# TEST REPORT — v5.0.34 RC1

## Static checks
- version.php: checked
- db/install.xml: retained
- db/upgrade.php + xmldb savepoint: retained/updated
- lang/en: retained
- lang/ru: retained
- PHP syntax: checked during build
- ZIP integrity: checked

## Required production checks
1. Corporate tab shows one connected Dashboard status.
2. Paired API 1.11 is shown from the active connection.
3. Bitrix health uses crm.contact.userfield.list.
4. Check schema.
5. Create field.
6. Recheck: field Ready, Multiple Yes, roles 4/4.
7. Repeat Create field: no duplicate.
