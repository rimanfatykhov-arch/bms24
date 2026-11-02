# BMS24 0.7.0.6 — BMS Assistant 0.9.3 Compatibility Hotfix

Build `2026092215`.

Основа релиза — фактический серверный `local_kbacompany 0.7.0.5 build 2026092214`; более ранняя линия 0.6.9.11.x не накатывается поверх 0.7.x.

## Изменения

- bridge BMS Assistant поднят до contract 0.3 и проверяет `proposal_only`;
- поддержана readiness-проверка `local_kbaai 0.9.3` без внешнего AI-запроса;
- тестовый provider явно добавляет предупреждение в proposal;
- provider response проверяется до передачи в UI, а target domain/field/revision связываются с серверным запросом;
- AJAX endpoint всегда возвращает безопасный JSON и не раскрывает raw provider exception;
- schema, ACL, services.php, privacy model и Theme не изменены.

## Совместимость

Целевой companion: `local_kbaai 0.9.3 build 2026092213` или совместимый contract 0.1–0.3 с `proposal_only`. Существующие AI-сценарии Philosophy, Market, Owner и Strategy сохранены.

## Установка

Это upgrade поверх build `2026092214`. Использовать только INSTALL_ME ZIP. После установки завершить Moodle upgrade и очистить кэши.
