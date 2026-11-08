# Corporate 0.40.8 / build2026102517 — API 1.26

Сервис `implementation_program_result_projection` возвращает текущее состояние **последнего** подтверждения для задачи оплаченной программы после проверки доступа, канонического аудита и ограничения количества задач. Только чтение.

Публичный контракт: `\local_kbacorporate\local\bridge::get_implementation_program_result_statuses(int $companyid, int $actorid, int $programid): ?array`.

Возвращает `company_id`, `program_id`, `audit_id`, `complete`, `reason`, `tasks` (в каждой задаче: `external_id`, `section_code`, `task_status`, `result_state`, `evidence_state`, `evidence_version`, `submitted_at`, `reviewed_at`).

Никаких тел комментариев, доказательств, файлов, ссылок на доказательства, имён или служебных заметок. `complete=false` означает невозможность достоверного подсчёта. Статусы принятых подтверждений не смешиваются с задачами, закрытыми без пакета подтверждения.

Зависимости: предыдущие Corporate миграции до 0.40.7; BMS24 build1819 для отображения. Новых XMLDB-таблиц нет.
