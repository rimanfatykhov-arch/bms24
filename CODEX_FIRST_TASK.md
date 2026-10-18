# Первый запрос к Codex — вставьте целиком в чат VS Code

Работаем в репозитории BMS24. Сначала прочитай AGENTS.md, docs/00_MASTER_CONTEXT.md, docs/16_CURRENT_RELEASE_RC29_1642.md, docs/17_UI_SHELL_AND_DESIGN_SYSTEM.md и docs/19_VSCODE_CODEX_WORKFLOW.md.

**СЕЙЧАС НЕ МЕНЯЙ КОД.** Выполни проверку репозитория: покажи git status, структуру каталогов, фактическую версию local/kbacompany/version.php, наличие всех BMS24 UI Kit/Task Center шаблонов и ключевых сервисов, используемую версию PHP и требования plugin->requires. Найди несовпадения между фактическим кодом и документацией.

Выполни `python scripts/qa_local.py` при наличии Python/PHP и отдельно покажи, какие проверки были невозможны. Проверь, не попали ли secrets/config.php/moodledata в Git; не выводи их содержимое. Составь короткий план перехода на VS Code/Codex: базовая ветка, первый безопасный W3.2 этап для регулярных задач, тесты и критерии приёмки.

Ответь по-русски. Для каждого вывода укажи подтверждение по файлам; не заявляй о production readiness и не делай deployment.
