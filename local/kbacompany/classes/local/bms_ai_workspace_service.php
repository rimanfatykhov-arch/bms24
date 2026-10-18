<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Draft-only reusable assistant for record modules; no AI-controlled writes or personal-data harvesting. */
class bms_ai_workspace_service {
    public static function render(string $companyuuid, string $domain, string $label = '', bool $fieldonly = false): void {
        global $USER;
        $allowed = ['task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'];
        if (!in_array($domain, $allowed, true) ||
                !permission_service::can_manage_organization($companyuuid, (int)$USER->id)) { return; }
        $supported = bms_ai_bridge_service::supports_domain($domain);
        if (!$fieldonly) {
        echo \html_writer::start_div('card p-3 mb-3 bms-ai-field');
        echo \html_writer::tag('label', 'ИИ-ассистент — ' . s($label ?: $domain),
            ['for' => 'bms_ai_draft_' . $domain, 'class' => 'font-weight-bold']);
        echo \html_writer::tag('p', 'Черновик и рекомендации. Результат проверяет и сохраняет пользователь.',
            ['class' => 'small text-muted']);
        echo \html_writer::tag('textarea', '', ['id' => 'bms_ai_draft_' . $domain, 'rows' => 3,
            'maxlength' => 12000, 'class' => 'form-control mb-2',
            'placeholder' => 'Опишите задачу, ожидаемый результат и известные факты']);
        if ($supported) {
            echo \html_writer::tag('button', '✨ ' . get_string('bmsai_help', 'local_kbacompany'),
                ['type' => 'button', 'class' => 'btn btn-outline-secondary bms-ai-help',
                'data-field' => 'drafttext', 'data-target' => 'bms_ai_draft_' . $domain,
                'data-label' => bms_ai_bridge_service::field_meaning($domain, 'drafttext')[0]]);
        } else {
            echo \html_writer::tag('p', get_string('bmsai_unavailable', 'local_kbacompany'),
                ['class' => 'small text-muted mb-0']);
        }
        echo \html_writer::end_div();
        } else if (!$supported) {
            echo \html_writer::tag('p', get_string('bmsai_unavailable', 'local_kbacompany'),
                ['class' => 'small text-muted mb-2']);
        }
        if ($supported) {
            echo \html_writer::div('', 'bms-ai-drawer d-none',
                ['id' => 'bms-ai-panel', 'role' => 'dialog', 'aria-modal' => 'true']);
            bms_ai_ui_service::init($companyuuid, $domain, null);
        }
    }
    /** Field button must be called only for editable text fields in protected CRUD forms. */
    public static function field_button(string $companyuuid, string $domain, string $field, string $targetid): string {
        global $USER;
        if (!in_array($domain, ['ckp', 'metric', 'task', 'battleplan', 'position'], true) ||
                !in_array($field, self::allowed_fields($domain), true) ||
                !permission_service::can_manage_organization($companyuuid, (int)$USER->id) ||
                !bms_ai_bridge_service::supports_domain($domain)) {
            return '';
        }
        return \html_writer::tag('button', '✨ ' . get_string('bmsai_help', 'local_kbacompany'), [
            'type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm bms-ai-help mt-2',
            'data-field' => $field, 'data-target' => $targetid,
            'data-label' => bms_ai_bridge_service::field_meaning($domain, $field)[0],
        ]);
    }

    /** Explicit allowlist; never create AI actions for sensitive employee profile fields. */
    private static function allowed_fields(string $domain): array {
        switch ($domain) {
            case 'ckp': return ['name', 'acceptance'];
            case 'metric': return ['name', 'description'];
            case 'task': return ['title', 'product', 'description'];
            case 'battleplan': return ['weekgoal', 'weekoutcome'];
            case 'position': return ['name', 'purpose'];
            default: return [];
        }
    }
    /** Add button within a field group, keeping label/context accessible to the drawer. */
    public static function field_control(string $html, string $companyuuid, string $domain,
            string $field, string $targetid): string {
        $button = self::field_button($companyuuid, $domain, $field, $targetid);
        if ($button === '') { return $html; }
        $end = strrpos($html, '</div>');
        if ($end === false) { return $html; }
        return str_replace('class="bms-tui-control', 'class="bms-ai-field bms-tui-control',
            str_replace('class="bms-bpu-control', 'class="bms-ai-field bms-bpu-control',
                substr($html, 0, $end) . $button . substr($html, $end)));
    }

}
