# BMS24 Development Automation Pack v1

Скопируйте содержимое пакета в корень репозитория `bms24`.

Основные команды:

```powershell
.\scripts\preflight.ps1
python scripts\build_release.py
```

Пакет добавляет:
- единый preflight;
- автоматическую сборку INSTALL/SOURCE Release Kit;
- feature workflow;
- шаблоны Codex Architect / Implementer / Reviewer;
- VS Code tasks;
- `.gitattributes` для нормализации LF/CRLF.

Важно: static QA не заменяет Moodle DB/browser/cron/security acceptance.
