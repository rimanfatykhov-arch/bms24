<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin-owned BMS presentation boundary. Nothing here modifies user data or rights.
 * Disabled by default; company navigation is still resolved through bms_ui_service.
 */
final class bms24_shell_service {
    public const CONTRACT_VERSION = '1.0.0-beta.4';
    public const OWNED_PAGE_LAYOUT = 'embedded';
    public const OWNED_BODY_CLASS = 'bms24-owned-page';

    public static function is_enabled(): bool {
        return (string)get_config('local_kbacompany', 'bms24_shell_mode') === 'owned';
    }

    /** Must be called BEFORE Moodle output/page header. */
    public static function prepare_page(): void {
        global $PAGE;
        // Owned BMS24 must render as an application, not as content nested inside
        // Moodle/KBA chrome. The core 'embedded' layout keeps Moodle bootstrap,
        // session, renderer, JS/CSS and security lifecycle while omitting the
        // host navigation/header/footer presentation. Legacy mode is untouched.
        $PAGE->set_pagelayout(self::OWNED_PAGE_LAYOUT);
        $PAGE->add_body_class(self::OWNED_BODY_CLASS);
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/tokens.css'));
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/owned_shell.css'));
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/ui_kit.css'));
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/task_center.css'));
    }

    public static function render(array $model): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_kbacompany/bms24_app_shell', $model);
    }

    public static function fragment(string $name, array $data): string {
        global $OUTPUT;
        $names = ['badge', 'card', 'empty_state', 'stat_card', 'task_list',
            'tree_navigator', 'split_view', 'ui_button', 'ui_status', 'ui_field',
            'ui_fieldgrid', 'ui_filterbar', 'ui_table', 'ui_tabs', 'ui_pagination', 'ui_alert',
            'task_header', 'task_tabs', 'task_toolbar', 'task_panel'];
        if (!in_array($name, $names, true)) {
            throw new \coding_exception('Unsupported BMS24 fragment');
        }
        return $OUTPUT->render_from_template('local_kbacompany/bms24_' . $name, $data);
    }
}
