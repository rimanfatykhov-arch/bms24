# KBA Connect v5.0.15 — Admin Control Center

## Цель

Возвращаем удобство v0.4.6 поверх нового production ядра v5.

## Разделы

- Dashboard
- Bitrix24 Connection
- CRM Fields
- Webhooks
- Automation Rules
- Production Wizard
- REST Diagnostics
- Execution History

## Принцип

Старый интерфейс v0.4.6:
- field_manager
- field_catalog
- rule_manager
- funnel_manager
- health_checker

используется как UX-модель.

Новое ядро:
- deal_flow_service
- multi_course_engine
- corporate_bridge
- queue
- production activation

остается без изменений.

## Безопасность

Admin Control Center не включает inbound автоматически.
Все production действия проходят через Production Wizard.
