# API CONTRACT — v0.33.4

## Статус

Internal contract only. Public Moodle external function is not exposed in this release.

## Идемпотентный вызов

`economic_indicators::upsert_external_value(companyid, indicatorcode, periodtype, periodstart, periodend, value, source, actorid, auditid, currency, comment, scenario)`.

## Стабильные поля будущего payload

```json
{
  "company_code": "COMP-001",
  "indicator_code": "REV",
  "period_type": "quarter",
  "period_start": "2026-01-01",
  "period_end": "2026-03-31",
  "scenario": "actual",
  "value": "35000000.00",
  "currency": "RUB",
  "source": "erp",
  "audit_code": null,
  "comment": "ERP closing data"
}
```

## Enum values

- period: `month`, `quarter`, `year`, `custom`;
- scenario: `actual`, `plan`, `forecast`;
- source: `manual`, `erp`, `import`, `api`.

## Ошибки

Неизвестный код, чужая компания/аудит, неверный период или нечисловое значение должны отклоняться. Версионирование публичного контракта будет закреплено в `v0.35.0`.
