# KBA Corporate Dashboard v0.29.3.2
## JSON Import Compatibility Fix

Цель:
исправление совместимости импорта KBA_BMS_MAPPING_LIBRARY JSON.

Изменение:
- поддержка schema kba-bms-mapping-library;
- проверка framework idnumber BMS-COMP-2026-V1;
- улучшение сообщений проверки импорта;
- подготовка Preview импорта.

Контрольный сценарий:
JSON Library -> Framework Manager -> Preview -> Apply.
