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
    // ADR-0009 R1: saved daily reports are off until explicitly enabled; off keeps the read-only day summary.
    $settings->add(new admin_setting_configcheckbox(
        'local_kbacompany/dailyreports_enabled',
        get_string('dailyreports_enabled', 'local_kbacompany'),
        get_string('dailyreports_enabled_help', 'local_kbacompany'),
        0
    ));
    $ADMIN->add('localplugins', $settings);
}
