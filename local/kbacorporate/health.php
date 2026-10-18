<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompanies', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/health.php'));
$PAGE->set_title(get_string('healthcenter', 'local_kbacorporate'));
$PAGE->set_heading(get_string('healthcenter', 'local_kbacorporate'));

$run = optional_param('run', 0, PARAM_BOOL);
if ($run && confirm_sesskey()) {
    $result = \local_kbacorporate\local\health::run('manual', true);
} else {
    $result = \local_kbacorporate\local\health::check(true);
}
$history = \local_kbacorporate\local\health::history(20);

$statuslabels = [
    'healthy' => get_string('health_healthy', 'local_kbacorporate'),
    'warning' => get_string('health_warning', 'local_kbacorporate'),
    'critical' => get_string('health_critical', 'local_kbacorporate'),
];

$componentlabels = [
    'moodle' => get_string('healthcomponent_moodle', 'local_kbacorporate'),
    'database' => get_string('healthcomponent_database', 'local_kbacorporate'),
    'kbaconnect' => get_string('healthcomponent_kbaconnect', 'local_kbacorporate'),
    'bitrix24' => get_string('healthcomponent_bitrix', 'local_kbacorporate'),
    'bigbluebutton' => get_string('healthcomponent_bbb', 'local_kbacorporate'),
    'customcert' => get_string('healthcomponent_customcert', 'local_kbacorporate'),
    'licenses' => get_string('healthcomponent_licenses', 'local_kbacorporate'),
    'api' => get_string('healthcomponent_api', 'local_kbacorporate'),
];

function local_kbacorporate_health_badge($status, $labels) {
    $class = $status === 'healthy' ? 'badge-success' : ($status === 'critical' ? 'badge-danger' : 'badge-warning');
    return html_writer::span(s($labels[$status] ?? $status), 'badge ' . $class);
}

function local_kbacorporate_health_details($details) {
    if (empty($details)) {
        return '';
    }
    $parts = [];
    foreach ($details as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? get_string('yes') : get_string('no');
        } else if (is_array($value)) {
            $simple = true;
            foreach ($value as $v) {
                if (is_array($v) || is_object($v)) { $simple = false; break; }
            }
            $value = $simple ? implode(', ', array_map(function($k, $v) {
                if (is_bool($v)) $v = $v ? 'yes' : 'no';
                return is_string($k) ? $k . '=' . $v : $v;
            }, array_keys($value), array_values($value))) : '';
        } else if (is_object($value)) {
            $value = '';
        }
        if ($value !== '' && $value !== null) {
            $parts[] = html_writer::tag('div', html_writer::tag('strong', s($key) . ': ') . s((string)$value));
        }
    }
    return implode('', $parts);
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('healthcenter', 'local_kbacorporate'), 2);

echo html_writer::start_div('mb-3');
echo html_writer::tag('h3', get_string('healthoverallstatus', 'local_kbacorporate') . ': ' . local_kbacorporate_health_badge($result['status'], $statuslabels));
echo html_writer::tag('p', get_string('healthsummary', 'local_kbacorporate', (object)['warnings' => $result['warnings'], 'errors' => $result['errors']]));
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/health.php', ['run' => 1, 'sesskey' => sesskey()]), get_string('healthrunbutton', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

$table = new html_table();
$table->head = [get_string('healthcomponent', 'local_kbacorporate'), get_string('healthstatus', 'local_kbacorporate'), get_string('healthmessage', 'local_kbacorporate'), get_string('healthdetails', 'local_kbacorporate')];
foreach ($result['components'] as $key => $component) {
    $table->data[] = [
        s($componentlabels[$key] ?? $key),
        local_kbacorporate_health_badge($component['status'], $statuslabels),
        s($component['message']),
        local_kbacorporate_health_details($component['details'] ?? []),
    ];
}
echo html_writer::table($table);

echo html_writer::tag('h3', get_string('healthhistory', 'local_kbacorporate'), ['class' => 'mt-4']);
if (!$history) {
    echo $OUTPUT->notification(get_string('healthhistoryempty', 'local_kbacorporate'), 'info');
} else {
    $historytable = new html_table();
    $historytable->head = [get_string('date'), get_string('healthsource', 'local_kbacorporate'), get_string('healthstatus', 'local_kbacorporate'), get_string('healthwarnings', 'local_kbacorporate'), get_string('healtherrors', 'local_kbacorporate')];
    foreach ($history as $row) {
        $historytable->data[] = [
            userdate((int)$row->timecreated),
            s($row->runsource),
            local_kbacorporate_health_badge($row->status, $statuslabels),
            (int)$row->warnings,
            (int)$row->errors,
        ];
    }
    echo html_writer::table($historytable);
}

echo $OUTPUT->footer();
