<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin-owned BMS presentation boundary. Nothing here modifies user data or rights.
 * Disabled by default; company navigation is still resolved through bms_ui_service.
 */
final class bms24_shell_service {
    public const CONTRACT_VERSION = '1.0.0-beta.1';

    public static function is_enabled(): bool {
        return (string)get_config('local_kbacompany', 'bms24_shell_mode') === 'owned';
    }

    /** Must be called BEFORE Moodle output/page header. */
    public static function prepare_page(): void {
        global $PAGE;
        $PAGE->set_pagelayout('standard');
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/tokens.css'));
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/bms_ui/owned_shell.css'));
    }

    public static function render(array $model): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_kbacompany/bms24_app_shell', $model);
    }

    public static function fragment(string $name, array $data): string {
        global $OUTPUT;
        $names = ['badge', 'card', 'empty_state', 'stat_card', 'task_list',
            'tree_navigator', 'split_view'];
        if (!in_array($name, $names, true)) {
            throw new \coding_exception('Unsupported BMS24 fragment');
        }
        return $OUTPUT->render_from_template('local_kbacompany/bms24_' . $name, $data);
    }
}
