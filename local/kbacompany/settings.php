<?php
// BMS24 owned presentation is opt-in until end-to-end acceptance.
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_kbacompany_ui',
        get_string('bms24_ui_settings', 'local_kbacompany'));
    $settings->add(new admin_setting_configselect(
        'local_kbacompany/bms24_shell_mode',
        get_string('bms24_shell_mode', 'local_kbacompany'),
        get_string('bms24_shell_mode_help', 'local_kbacompany'),
        'legacy',
        [
            'legacy' => get_string('bms24_shell_legacy', 'local_kbacompany'),
            'owned' => get_string('bms24_shell_owned', 'local_kbacompany'),
        ]
    ));
    $ADMIN->add('localplugins', $settings);
}
