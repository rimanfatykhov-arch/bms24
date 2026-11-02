# BMS Calendar — Team Workload 1.1.0-alpha9

Build 2026100705.

Добавлена permission-scoped проекция загрузки сотрудников поверх уже доступных BMS Calendar events. Другие пользовательские Moodle calendars не читаются. Для manager/owner/hr доступен фильтр по активному сотруднику компании; обычный пользователь видит только свою загрузку. Task assignee, visible Meeting participants и Battle Plan owner используются как canonical employee attribution. Private meetings не становятся видимыми руководителю автоматически.

No schema/ACL/privacy changes.
