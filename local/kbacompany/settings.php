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
    // ADR-0010 (owner decision 21.11.2026): self review only at the top of the organisation chart; on by decision.
    $settings->add(new admin_setting_configcheckbox(
        'local_kbacompany/dailyreports_selftop',
        get_string('dailyreports_selftop', 'local_kbacompany'),
        get_string('dailyreports_selftop_help', 'local_kbacompany'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbacompany/dailyreports_cc_max',
        get_string('dailyreports_cc_max', 'local_kbacompany'),
        get_string('dailyreports_cc_max_help', 'local_kbacompany'),
        5,
        PARAM_INT
    ));
    // DOC-W2: the Office workspace (read-only overview over existing documents) is off until explicitly enabled.
    $settings->add(new admin_setting_configcheckbox(
        'local_kbacompany/office_workspace_enabled',
        get_string('officews_enabled', 'local_kbacompany'),
        get_string('officews_enabled_help', 'local_kbacompany'),
        0
    ));
    // BMOS-A1.1: work principal registries are off until explicitly enabled; off keeps the product as before BMOS.
    $settings->add(new admin_setting_configcheckbox(
        'local_kbacompany/bmos_enabled',
        get_string('bmos_enabled', 'local_kbacompany'),
        get_string('bmos_enabled_help', 'local_kbacompany'),
        0
    ));
    $ADMIN->add('localplugins', $settings);
}
