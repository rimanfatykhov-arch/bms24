# DATA MODEL — v0.33.4

## `local_kbacorp_econ_ind`

Определение показателя. `companyid=0` означает глобальный системный каталог. Уникальный ключ: `(companyid, code)`.

Ключевые поля: `code`, `name`, `category`, `valuetype`, `unit`, `periodtype`, `defaultsource`, `direction`, `active`, `systemdefined`.

## `local_kbacorp_econ_val`

Периодическое значение компании. Уникальный ключ: `(companyid, indicatorid, periodkey, scenario)`.

Ключевые поля: `auditid`, `periodtype`, `periodstart`, `periodend`, `periodkey`, `scenario`, `value`, `currency`, `source`, `verified`.

Foreign keys:

- `companyid → local_kbacorp_company.id`;
- `indicatorid → local_kbacorp_econ_ind.id`.

`auditid` не имеет физического FK и проверяется сервисом, чтобы сохранить значение при изменении жизненного цикла аудита.

## `local_kbacorp_econ_log`

Неизменяемая история операций. Хранит `entitytype`, `entityid`, `action`, `actorid`, `oldjson`, `newjson`, `source`, `timecreated`.

## Legacy compatibility

`local_kbacorp_impl_econ` не удаляется. Его фиксированные поля отображаются в registry codes через mapping service.
