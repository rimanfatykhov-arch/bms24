<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class stage_selector {
    public static function render(
        array $snapshot,
        array $selected,
        string $fieldname = 'stages'
    ): string {
        $selectedmap = array_flip($selected);

        if (!$snapshot) {
            return \html_writer::tag(
                'div',
                'Список воронок ещё не загружен из Bitrix24.',
                ['class' => 'alert alert-warning']
            );
        }

        $html = '';

        foreach ($snapshot as $funnel) {
            $categoryid = (int)($funnel['category_id'] ?? 0);
            $name = (string)($funnel['name'] ?? '');
            $isdefault = (string)($funnel['is_default'] ?? 'N') === 'Y';

            $title = $name .
                ' — CATEGORY_ID=' . $categoryid .
                ($isdefault ? ' (основная)' : '');

            $html .= \html_writer::start_tag('div', [
                'class' => 'card mb-3',
            ]);
            $html .= \html_writer::start_tag('div', [
                'class' => 'card-body',
            ]);
            $html .= \html_writer::tag('h4', s($title));

            $html .= \html_writer::start_tag('table', [
                'class' => 'generaltable',
            ]);
            $html .= \html_writer::tag(
                'tr',
                \html_writer::tag('th', 'Использовать') .
                \html_writer::tag('th', 'Стадия Bitrix24') .
                \html_writer::tag('th', 'STAGE_ID') .
                \html_writer::tag('th', 'Семантика')
            );

            foreach (($funnel['stages'] ?? []) as $stage) {
                $id = (string)($stage['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $checked = isset($selectedmap[$id]);

                $checkbox = \html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => $fieldname . '[]',
                    'value' => $id,
                    'checked' => $checked ? 'checked' : null,
                ]);

                $html .= \html_writer::tag(
                    'tr',
                    \html_writer::tag('td', $checkbox) .
                    \html_writer::tag(
                        'td',
                        s((string)($stage['name'] ?? $id))
                    ) .
                    \html_writer::tag(
                        'td',
                        \html_writer::tag('code', s($id))
                    ) .
                    \html_writer::tag(
                        'td',
                        s((string)($stage['semantics'] ?? ''))
                    )
                );
            }

            $html .= \html_writer::end_tag('table');
            $html .= \html_writer::end_tag('div');
            $html .= \html_writer::end_tag('div');
        }

        return $html;
    }
}
