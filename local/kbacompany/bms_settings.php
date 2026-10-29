<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\entitlement_snapshot_service;
use local_kbacompany\local\workspace_mapping_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$tab = optional_param('tab', 'settings', PARAM_ALPHA);
$company = company_context_service::context($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $uuid, 'tab' => $tab]));
$PAGE->set_title($tab === 'plan' ? get_string('bms_plan_title', 'local_kbacompany') : get_string('bms_settings_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$title = $tab === 'plan' ? get_string('bms_plan_title', 'local_kbacompany') : get_string('bms_settings_title', 'local_kbacompany');
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings', $title);

if ($tab === 'plan') {
    echo $OUTPUT->heading(get_string('bms_plan_access_title', 'local_kbacompany'), 2, 'mb-1');
    echo html_writer::tag('p', get_string('bms_plan_access_desc', 'local_kbacompany'), ['class' => 'text-muted']);
    $workspace = workspace_mapping_service::status($uuid);
    $snapshot = entitlement_snapshot_service::diagnostics($uuid);
    $mode = (string)($workspace['entitlement_mode'] ?? '');
    $modelabel = get_string('bms_plan_unknown', 'local_kbacompany');
    if ($mode === workspace_mapping_service::MODE_LEGACY) {
        $modelabel = get_string('bms_plan_legacy', 'local_kbacompany');
    } else if ($mode === workspace_mapping_service::MODE_CLOUD) {
        $modelabel = get_string('bms_plan_cloud', 'local_kbacompany');
    } else if ($mode === workspace_mapping_service::MODE_SUSPENDED) {
        $modelabel = get_string('bms_plan_suspended', 'local_kbacompany');
    }
    $table = new html_table();
    $table->attributes['class'] = 'table table-sm';
    $table->data = [
        [get_string('bms_settings_company', 'local_kbacompany'), format_string($company['name'])],
        [get_string('bms_plan_mode', 'local_kbacompany'), $modelabel],
        [get_string('bms_plan_cloud_status', 'local_kbacompany'), s((string)($workspace['cloud_status'] ?? ''))],
        [get_string('bms_plan_snapshot', 'local_kbacompany'), s((string)($snapshot['state'] ?? 'none'))],
        [get_string('bms_plan_entitlements', 'local_kbacompany'), (string)($snapshot['entitlements_count'] ?? 0)],
    ];
    echo html_writer::div(html_writer::table($table), 'card card-body mb-3');
    echo html_writer::div(get_string('bms_plan_commercial_note', 'local_kbacompany'), 'alert alert-info');
} else {
    echo $OUTPUT->heading(get_string('bms_settings_title', 'local_kbacompany'), 2, 'mb-1');
    echo html_writer::tag('p', get_string('bms_settings_intro', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-lg-6 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('bms_settings_account', 'local_kbacompany'), ['class' => 'h5']);
    echo html_writer::tag('p', get_string('bms_settings_account_desc', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::link(new moodle_url('/user/preferences.php', ['userid' => $USER->id]),
        get_string('bms_settings_preferences', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2 mb-1']);
    echo html_writer::link(new moodle_url('/message/notificationpreferences.php'),
        get_string('bms_settings_notifications', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-1']);
    echo html_writer::link(new moodle_url('/local/kbacompany/calendar_notifications.php', ['uuid'=>$uuid]),
        \local_kbacompany\local\calendar_workspace_ui_service::text('calendar_notify_title'), ['class' => 'btn btn-outline-primary ml-2 mb-1']);
    echo html_writer::link(new moodle_url('/local/kbacompany/calendar_google.php', ['uuid'=>$uuid]),
        get_string('bms_google_title','local_kbacompany'), ['class'=>'btn btn-outline-primary ml-2 mb-1']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('col-lg-6 mb-3');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('bms_settings_people', 'local_kbacompany'), ['class' => 'h5']);
    echo html_writer::tag('p', get_string('bms_settings_people_desc', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::link(new moodle_url('/local/kbacompany/people.php', ['uuid' => $uuid]),
        get_string('people_title', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    if (\local_kbacompany\local\access_role_service::can_administer($uuid, (int)$USER->id)) {
        echo html_writer::start_div('col-lg-6 mb-3');
        echo html_writer::start_div('card card-body h-100');
        echo html_writer::tag('h3', get_string('bmsacl_title', 'local_kbacompany'), ['class' => 'h5']);
        echo html_writer::tag('p', get_string('bmsacl_intro', 'local_kbacompany'), ['class' => 'text-muted']);
        echo html_writer::link(new moodle_url('/local/kbacompany/access_roles.php', ['uuid' => $uuid]),
            get_string('bmsacl_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    if (\local_kbacompany\local\permission_service::can_manage_organization($uuid, (int)$USER->id)) {
        echo html_writer::start_div('col-lg-6 mb-3');
        echo html_writer::start_div('card card-body h-100');
        echo html_writer::tag('h3', get_string('bms_int_title', 'local_kbacompany'), ['class' => 'h5']);
        echo html_writer::tag('p', get_string('bms_int_intro', 'local_kbacompany'), ['class' => 'text-muted']);
        echo html_writer::link(new moodle_url('/local/kbacompany/integration_center.php', ['uuid' => $uuid]),
            get_string('bms_int_open_center', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

\local_kbacompany\local\bms_page_shell_service::finish($shell);
