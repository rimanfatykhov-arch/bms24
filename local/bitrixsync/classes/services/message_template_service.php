<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

/**
 * Configurable templates restored from v0.4.6 and adapted to v5.
 */
class message_template_service {

    public function defaults(): array {
        return [
            // Access email — preserved with original v0.4.6 config names.
            'accesschannel' => 'moodle',
            'accesssendername' => 'Бизнес Академия',
            'accesssenderemail' => 'info@kzn.mba',
            'accessemailsubject' =>
                'Доступ к образовательной среде Бизнес Академии',
            'accessemailbody' =>
                "Здравствуйте, {firstname}!\n\n" .
                "Для вас создан доступ к образовательной среде Бизнес Академии.\n\n" .
                "Адрес для входа: {url}\n" .
                "Логин: {login}\n" .
                "Временный пароль: {password}\n\n" .
                "При первом входе система попросит вас установить новый пароль.\n\n" .
                "Курс: {course}\n\n" .
                "Желаем продуктивного обучения!\n" .
                "Бизнес Академия\n" .
                "https://бизнес-академия.рф/",

            // Common timeline block — original v0.4.6 names.
            'timelineenabled' => '1',
            'timeline_sync_header' => '🎓 Moodle — синхронизация',
            'timeline_sync_user' => '👤 Ученик: {fullname}',
            'timeline_sync_email' => '📧 Email: {email}',
            'timeline_sync_course' => '📚 Курс: {course} [ID {courseid}]',
            'timeline_sync_group' => '👥 Группа: {group} [ID {groupid}]',

            // Original event templates.
            'timeline_account_created' =>
                '✅ Создана учетная запись Moodle [User ID {userid}]',
            'timeline_course_enrolled' =>
                '✅ Пользователь зачислен на курс',
            'timeline_group_added' =>
                '✅ Пользователь добавлен в группу «{group}» [ID {groupid}]',
            'timeline_learning_started' =>
                "▶️ Ученик приступил к обучению\n" .
                "📈 Прогресс: {progress}%\n" .
                "🕒 Начало обучения: {startedat}",
            'timeline_course_completed' =>
                "🏁 Курс завершён\n" .
                "📈 Прогресс: {progress}%\n" .
                "🕒 Дата завершения: {completedat}",
            'timeline_access_sent' =>
                "🔐 Доступ ученику отправлен\n" .
                "📨 Канал: {channel}\n" .
                "🕒 Дата отправки: {accesssentat}\n" .
                "{activityline}",
            'timeline_sync_error' =>
                "⚠️ Ошибка синхронизации\n" .
                "🔧 Тип: {errortype}\n" .
                "❌ {error}",
            'timeline_first_login' =>
                "👋 Ученик впервые вошёл в Moodle\n" .
                "🕒 Первый вход: {firstloginat}",
            'timeline_progress_thresholds' => '25,50,75,100',
            'timeline_progress_threshold' =>
                "🎯 Достигнут порог: {threshold}%\n" .
                "📈 Текущий прогресс: {progress}%\n" .
                "✅ Выполнено активностей: {completedactivities} из {totalactivities}\n" .
                "🕒 Последняя активность: {lastactivity}",
            'timeline_webinar_attended' =>
                "🎥 Вебинар посещён\n" .
                "🖥️ Вебинар: {webinar}\n" .
                "🕒 Дата: {webinarat}\n" .
                "⏱️ Время участия: {webinarminutes} мин\n" .
                "📊 Посещено вебинаров: {webinarattended} из {webinartotal} ({webinarpercent}%)",

            // v5 whole-deal/company summaries actually used by crm_writeback_service.
            'timeline_v5_deal_summary' =>
                "KBA Connect v5 — синхронизация обучения\n\n" .
                "Компания: {company}\n" .
                "Moodle User ID: {userid}\n" .
                "Курсов в сделке: {productrows}\n" .
                "Курсов обработано: {processed}\n" .
                "Новых зачислений: {enrolled}\n" .
                "Уже было доступно: {already}\n" .
                "Ошибок: {failed}\n\n" .
                "Программы:\n{courses}",
            'timeline_v5_company_summary' =>
                "KBA Connect — корпоративное обучение\n\n" .
                "Компания: {company}\n" .
                "Corporate Company ID: {corporatecompanyid}\n" .
                "Активных программ: {activecoursecount}\n" .
                "Сотрудников: {employeecount}\n\n" .
                "Программы:\n{courses}",
        ];
    }

    public function get(string $key): string {
        $value = get_config('local_bitrixsync', $key);
        if ($value === false) {
            return (string)($this->defaults()[$key] ?? '');
        }
        return (string)$value;
    }

    public function save(array $values): void {
        $defaults = $this->defaults();

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            set_config(
                $key,
                is_bool($value) ? ($value ? 1 : 0) : $value,
                'local_bitrixsync'
            );
        }
    }

    public function reset_defaults(): void {
        foreach ($this->defaults() as $key => $value) {
            set_config(
                $key,
                $value,
                'local_bitrixsync'
            );
        }
    }

    public function render(string $key, array $context): string {
        return $this->render_text(
            $this->get($key),
            $context
        );
    }

    public function render_text(string $template, array $context): string {
        if ($template === '') {
            return '';
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $stringkey = (string)$key;
            $placeholder =
                substr($stringkey, 0, 1) === '{'
                    ? $stringkey
                    : '{' . $stringkey . '}';

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $replace[$placeholder] = (string)$value;
        }

        return trim(strtr($template, $replace));
    }

    public function deal_summary(
        array $result,
        array $context,
        array $courses
    ): string {
        $courselines = $courses
            ? '• ' . implode("\n• ", $courses)
            : '—';

        return $this->render(
            'timeline_v5_deal_summary',
            [
                'company' =>
                    (string)($context['companytitle'] ?? '') ?: '—',
                'userid' => (int)($result['userid'] ?? 0),
                'productrows' => (int)($result['productrows'] ?? 0),
                'processed' =>
                    (int)($result['enrolled'] ?? 0) +
                    (int)($result['already'] ?? 0),
                'enrolled' => (int)($result['enrolled'] ?? 0),
                'already' => (int)($result['already'] ?? 0),
                'failed' => (int)($result['failed'] ?? 0),
                'courses' => $courselines,
            ]
        );
    }

    public function company_summary(
        array $snapshot,
        array $context
    ): string {
        $activecourses = [];

        foreach (($snapshot['courses'] ?? []) as $course) {
            if (!empty($course['active'])) {
                $activecourses[] =
                    (string)($course['fullname'] ?? '');
            }
        }

        $courselines = $activecourses
            ? '• ' . implode("\n• ", $activecourses)
            : '—';

        return $this->render(
            'timeline_v5_company_summary',
            [
                'company' =>
                    (string)($snapshot['name'] ?? '')
                    ?: (string)($context['companytitle'] ?? ''),
                'corporatecompanyid' =>
                    (int)($snapshot['companyid'] ?? 0),
                'activecoursecount' =>
                    (int)($snapshot['activecoursecount'] ?? 0),
                'employeecount' =>
                    (int)($snapshot['employeecount'] ?? 0),
                'courses' => $courselines,
            ]
        );
    }
}
