# v0.31.2.4 — Global Role Edit Context Hotfix

## Исправлено
- Устранён конфликт параметров `companyid` при редактировании глобального шаблона бизнес-роли.
- Контекст компании для возврата/навигации теперь передаётся как `returncompanyid` и не конфликтует с `companyid=0` глобальной роли в форме.
- POST формы глобальной роли больше не вызывает `require_full_company_access(0)`.
- Редактирование глобальной роли допускается без company-context для site administrator; при наличии return company контекст валидируется.
- Ссылки на business_role_edit обновлены на явный `returncompanyid`.

## Data / migration impact
- Схема БД не меняется.
- Данные ролей и профилей не мигрируются автоматически.
- Destructive changes отсутствуют.
