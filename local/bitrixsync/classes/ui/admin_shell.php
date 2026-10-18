<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class admin_shell {
    public static function render(string $active): string {
        $items = [
            'dashboard' => [
                'label' => 'Dashboard',
                'url' => '/local/bitrixsync/admin_dashboard.php',
            ],
            'setup' => [
                'label' => 'Настройка',
                'url' => '/local/bitrixsync/setup_center.php',
            ],
            'bitrix' => [
                'label' => 'Bitrix24',
                'url' => '/local/bitrixsync/bitrix_connection.php',
            ],
            'fields' => [
                'label' => 'CRM поля',
                'url' => '/local/bitrixsync/crm_fields.php',
            ],
            'messages' => [
                'label' => 'Шаблоны сообщений',
                'url' => '/local/bitrixsync/message_templates.php',
            ],
            'courses' => [
                'label' => 'Курсы и товары',
                'url' => '/local/bitrixsync/course_mapping.php',
            ],
            'corporate' => [
                'label' => 'Corporate',
                'url' => '/local/bitrixsync/corporate_rules.php',
            ],
            'automation' => [
                'label' => 'Автоматизация',
                'url' => '/local/bitrixsync/automation_center.php',
            ],
            'history' => [
                'label' => 'История',
                'url' => '/local/bitrixsync/history.php',
            ],
            'diagnostics' => [
                'label' => 'Диагностика',
                'url' => '/local/bitrixsync/diagnostics_hub.php',
            ],
            'production' => [
                'label' => 'Production',
                'url' => '/local/bitrixsync/setup_wizard.php',
            ],
        ];

        $html = \html_writer::start_tag('div', [
            'class' => 'mb-4',
        ]);

        $html .= \html_writer::start_tag('div', [
            'class' => 'btn-group flex-wrap',
            'role' => 'group',
            'aria-label' => 'KBA Connect navigation',
        ]);

        foreach ($items as $key => $item) {
            $class = $key === $active
                ? 'btn btn-primary'
                : 'btn btn-outline-secondary';

            $html .= \html_writer::link(
                new \moodle_url($item['url']),
                $item['label'],
                ['class' => $class]
            );
        }

        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');

        return $html;
    }

    public static function status_badge(
        bool $ok,
        string $oklabel = 'OK',
        string $badlabel = 'Нужно настроить'
    ): string {
        return \html_writer::tag(
            'span',
            $ok ? $oklabel : $badlabel,
            [
                'class' => $ok
                    ? 'badge badge-success'
                    : 'badge badge-warning',
            ]
        );
    }

    public static function card(
        string $title,
        string $body,
        string $url,
        string $button = 'Открыть'
    ): string {
        $html = \html_writer::start_tag('div', [
            'class' => 'card h-100',
        ]);
        $html .= \html_writer::start_tag('div', [
            'class' => 'card-body',
        ]);
        $html .= \html_writer::tag('h4', $title, [
            'class' => 'card-title',
        ]);
        $html .= \html_writer::tag('div', $body, [
            'class' => 'card-text mb-3',
        ]);
        $html .= \html_writer::link(
            new \moodle_url($url),
            $button,
            ['class' => 'btn btn-outline-primary']
        );
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');

        return $html;
    }
}
