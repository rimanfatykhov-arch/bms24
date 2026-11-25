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
    // ADR-0011 S6 (owner decision 25.11.2026): reminders to the author and, when overdue, to the reviewer. Off by
    // default; the operator turns them on. Times are HH:MM in each author's own time zone.
    $settings->add(new admin_setting_configcheckbox(
        'local_kbacompany/dailyreports_remind_enabled',
        get_string('dailyreports_remind_enabled', 'local_kbacompany'),
        get_string('dailyreports_remind_enabled_help', 'local_kbacompany'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbacompany/dailyreports_remind_time',
        get_string('dailyreports_remind_time', 'local_kbacompany'),
        get_string('dailyreports_remind_time_help', 'local_kbacompany'),
        '18:00',
        '/^([01]\d|2[0-3]):[0-5]\d$/'
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbacompany/dailyreports_overdue_time',
        get_string('dailyreports_overdue_time', 'local_kbacompany'),
        get_string('dailyreports_overdue_time_help', 'local_kbacompany'),
        '10:00',
        '/^([01]\d|2[0-3]):[0-5]\d$/'
    ));
    $ADMIN->add('localplugins', $settings);
}
