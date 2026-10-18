# SYSTEM ARCHITECTURE — v0.33.4

## Поток данных

`UI / legacy audit snapshot / future ERP adapter → economic_indicators service → indicator/value tables → immutable change log → CSV / future analytics`.

## Авторитетные слои

- `local_kbacorp_econ_ind` — определение и стабильный код;
- `local_kbacorp_econ_val` — периодическое значение компании;
- `local_kbacorp_econ_log` — история операции;
- `local_kbacorp_impl_econ` — legacy compatibility snapshot.

## Изоляция

Каждый value имеет `companyid`. Indicator доступен, если `companyid=0` либо совпадает с текущей компанией. Audit link проверяется по company scope.

## Идемпотентность

- global seed: `(companyid=0, code)`;
- external upsert: `(companyid, indicatorid, periodkey, scenario)`;
- legacy migration: тот же составной ключ.
