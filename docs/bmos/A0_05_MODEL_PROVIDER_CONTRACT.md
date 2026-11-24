# BMOS-A0. Model Provider Contract

**Статус: PROPOSED.** Код не написан, ключей нет, сетевых вызовов не было.

## 1. Путь

`Agent Runtime → ModelRouter → ModelProviderInterface → TimewebAIGatewayAdapter → https://api.timeweb.ai/v1`

Все звенья, кроме Runtime, — в `local_kbaai`. Причины:

- В `local_kbaai` уже есть фабрика провайдеров, `mock_provider`, Yandex-провайдер, профили с JSON-схемой и
  fail-closed нормализация (`bms_profile_registry::normalise`). Второй engine запрещён (MR3-D50, BMS-ARC-AI-001 §1.4).
- Существующий `provider_interface` построен вокруг сценариев (`generate(assessment_request)`,
  `generate_supervisor_reply`). Его не переписываем: Assessor и BMS Assistant продолжают работать как есть.
- Новый **модельный** интерфейс — нижний слой. Позже сценарные провайдеры могут пользоваться им (отдельное решение,
  вне A1–A3).

## 2. Интерфейс (PHP 7.4-совместимо, псевдокод)

```php
namespace local_kbaai\model;

interface model_provider_interface {
    /** Возможности по данным провайдера: модели, методы, параметры, лимиты контекста. Без сети в тестах. */
    public function list_capabilities(): capability_set;
    /** Чат-генерация. */
    public function generate(model_request $request): model_response;
    /** Строгий JSON по схеме. Если модель/метод не подтверждены — capability_missing, без «мягкого» парсинга. */
    public function generate_structured(model_request $request, array $jsonschema): model_response;
    /** Эмбеддинги — только если capability есть. */
    public function embed(embed_request $request): embed_response;
    /** Безопасная проверка доступности: без данных клиентов. */
    public function health_check(): health_status;
    /** Учёт расхода из ответа провайдера (токены, модель-ответчик, fallback). Не стоимость списания. */
    public function usage(model_response $response): usage_record;
    /** Оценка стоимости по версии прайса из конфигурации. Всегда помечена estimated. */
    public function estimate_cost(usage_record $usage, price_snapshot $prices): cost_estimate;
}
```

Имена в ТЗ (`listCapabilities`, `generateStructured`, `healthCheck`, `estimateCost`) соответствуют методам выше
в стиле Moodle (snake_case).

**`model_request`:** `profileid` + `profilerevision` (не имя модели напрямую), `messages[]` с ролями и пометкой
доверия (`trusted_instruction` только у системного профиля; всё остальное — `untrusted_data`), `maxoutputtokens`,
`timeoutms`, `correlationid`, `idempotencykey`, `companyuuid` (для квот, не передаётся провайдеру).

**`model_response`:** `text` или `structured`, `finishreason`, `requestedmodel`, `respondingmodel`,
`fallbackused` (из заголовка `x-timeweb-attempted-fallbacks`), `providerrequestid`, `usage` (input/output/cached
tokens, если провайдер их вернул), `latencyms`. Без сырого HTTP-ответа.

**Типизированные ошибки:** `auth_failed` (401/403), `insufficient_funds` (402), `model_unknown` (404),
`model_retired` (410), `provider_limit` (422 — лимит ключа), `rate_limited` (429), `timeout` (408/504),
`provider_unavailable` (5xx), `invalid_request` (400), `capability_missing`, `schema_violation`, `budget_denied`
(собственная проверка до вызова). У каждой — флаг `retryable`.

## 3. ModelRouter

- Профиль модели — данные: `modelprofile` (`id`, `revision`, `provider`, `model`, `method`, `structured` да/нет,
  `maxcontext`, `maxoutput`, `allowfallback`, `pricesnapshotid`, `status`). Хранится в конфигурации `local_kbaai`
  (таблица в A1, Q-04), задаётся администратором, не кодом.
- Router: профиль → адаптер → проверка capability → вызов. Нет профиля или capability — отказ.
- Fallback: только если профиль разрешает и запасная модель подтверждена для того же метода и схемы. Для
  `generate_structured` и вызовов инструментов — fallback выключен по умолчанию (fail closed).

## 4. TimewebAIGatewayAdapter — факты из документации (24.11.2026)

Источник: https://timeweb.cloud/docs/ai-gateway, `/manage`, `/logs`, `/usage-statistics`, `/fallback`. Это заявления
провайдера, а не результаты тестов BMS.

| Вопрос | Что сказано | Следствие для адаптера |
|---|---|---|
| Совместимость | OpenAI-совместимый, `https://api.timeweb.ai/v1`; `chat/completions`, `responses` (только для моделей с рассуждениями), `models.list`, `embeddings` (`openai/text-embedding-3-large`) | Использовать `chat/completions`; методы — из capability, а не по умолчанию |
| Structured output / tools | В документации не описаны; в логах есть статус «вызов инструмента» | `generate_structured` = `capability_missing`, пока не подтверждено тестом на конкретной модели |
| Ключи | Отдельные ключи AI Gateway, срок 30/60/90 дней/год/бессрочно, показываются один раз, перегенерация и удаление вручную | `credentialref`; бессрочные ключи не использовать; ротация — процедура оператора |
| Лимиты | На ключ: дневной общий и по моделям, в рублях или токенах; сброс через 24 ч от первого расхода; превышение — 422 | Лимит ключа — страховка, не замена квотам BMS по компании/агенту |
| Расход и стоимость | **Нет API расхода/стоимости**; данные — в UI и CSV-выгрузке логов (до 10 000 записей); «сумма в логах может отличаться от фактических списаний» | Стоимость в BMS — `estimated`; `confirmed` — только сверкой (A0_06 §6) |
| Логи провайдера | Метаданные запроса, без текстов промпта и ответа, 90 дней | Не заменяет аудит BMS |
| Fallback | До двух запасных моделей на ключ; не срабатывает на 422 и после начала стрима; заголовок `x-timeweb-attempted-fallbacks` | Записывать `respondingmodel`; строгие профили — отдельный ключ без fallback |
| Ошибки | 400, 402, 404, 408/504, 410, 422, 429, 5xx | Таблица типизированных ошибок выше |
| Rate limit (RPM) | Не документирован | Собственный rate limit BMS обязателен |
| Резидентность и обработка данных | **На прочитанных страницах нет** | Блокер для клиентских данных (Q-12) |
| RAG, MCP, агенты | Gateway их не предоставляет | Агентность, инструменты, RAG — в BMOS |

## 5. Совместимость с KBA AI Assessor и BMS Assistant

- Assessor: `provider_interface`, `provider_factory`, таблицы `local_kbaai_*`, задачи `scan_submissions` и др. — без
  изменений.
- BMS Assistant: `bms_assistant_service`, `bms_profile_registry`, мост `bms_ai_bridge_service` — без изменений. Список
  `['mock','yandex']` в `status()` не расширяется ради агентов: агентам нужен свой путь через Router.
- Общие правила переносятся: системные правила только из профиля; данные — недоверенные; fail-closed нормализация;
  ограничения размера ввода и вывода.

## 6. Без хардкода

Модели, цены, лимиты, base URL, таймауты — конфигурация. В коде допустимы только значения по умолчанию «выключено»
и безопасные верхние пределы размеров. Ключи — только по `credentialref`.

## 7. Тестирование адаптера (A1)

`FakeModelProvider` с фикстурами: успех, 401, 402, 404, 410, 422, 429, 5xx, таймаут, fallback-заголовок, нарушение
схемы, пустые `usage`. Живой вызов Timeweb — не раньше A3, только с синтетическими данными, на ключе владельца с
минимальным лимитом, в изолированной среде, по отдельному разрешению (#76).
