<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\learning_contour_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);

// company_context_service inside the aggregator is the server-side company boundary.
// A user cannot switch company context by changing only the URL UUID.
$data = learning_contour_service::context($uuid, (int)$USER->id, true);
$company = $data['company'];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/learning.php', ['uuid' => $uuid]));
$PAGE->set_title($data['can_manage']
    ? get_string('bms_learning_title', 'local_kbacompany')
    : get_string('bms_learning_my_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$shell = \local_kbacompany\local\bms_page_shell_service::begin(
    $uuid,
    (int)$USER->id,
    'learning',
    $data['can_manage']
        ? get_string('bms_learning_title', 'local_kbacompany')
        : get_string('bms_learning_my_title', 'local_kbacompany')
);

if ($data['can_manage']) {
    echo $OUTPUT->heading(get_string('bms_learning_title_for', 'local_kbacompany', format_string($company['name'])), 2);
    echo html_writer::tag('p', get_string('bms_learning_manager_intro', 'local_kbacompany'), ['class' => 'lead']);
} else {
    echo $OUTPUT->heading(get_string('bms_learning_my_title_for', 'local_kbacompany', format_string($company['name'])), 2);
    echo html_writer::tag('p', get_string('bms_learning_employee_intro', 'local_kbacompany'), ['class' => 'lead']);
}
echo $OUTPUT->notification(get_string('bms_learning_boundary_notice', 'local_kbacompany'), 'info');

if (empty($data['corporate']['available'])) {
    $message = get_string('bms_learning_corporate_unavailable', 'local_kbacompany');
    if (!empty($data['corporate']['error'])) {
        $message .= ' ' . s($data['corporate']['error']);
    }
    echo $OUTPUT->notification($message, 'warning');
}

$summary = $data['summary'];
if ($data['can_manage']) {
    $cards = [
        [get_string('bms_learning_employees', 'local_kbacompany'), $summary['employees'],
            get_string('bms_learning_employees_caption', 'local_kbacompany')],
        [get_string('bms_learning_training_employees', 'local_kbacompany'), $summary['training_employees'],
            get_string('bms_learning_training_employees_caption', 'local_kbacompany')],
        [get_string('bms_learning_completed_employees', 'local_kbacompany'), $summary['completed_employees'],
            get_string('bms_learning_completed_employees_caption', 'local_kbacompany')],
        [get_string('bms_learning_purchased_seats', 'local_kbacompany'), $summary['purchased_seats'],
            get_string('bms_learning_allocated_caption', 'local_kbacompany', $summary['allocated_seats'])],
        [get_string('bms_learning_free_seats', 'local_kbacompany'), $summary['free_seats'],
            get_string('bms_learning_free_seats_manager_caption', 'local_kbacompany')],
    ];
} else {
    $cards = [
        [get_string('bms_learning_my_in_progress', 'local_kbacompany'), $summary['my_active_company_courses'],
            get_string('bms_learning_my_in_progress_caption', 'local_kbacompany')],
        [get_string('bms_learning_my_not_started', 'local_kbacompany'), $summary['my_not_started_company_courses'],
            get_string('bms_learning_my_not_started_caption', 'local_kbacompany')],
        [get_string('bms_learning_my_completed', 'local_kbacompany'), $summary['my_completed_company_courses'],
            get_string('bms_learning_my_completed_caption', 'local_kbacompany')],
    ];
}

echo html_writer::start_div('row g-3 mb-4');
foreach ($cards as $card) {
    list($label, $value, $caption) = $card;
    $html = html_writer::tag('div', s($label), ['class' => 'text-uppercase text-muted small font-weight-bold']);
    $html .= html_writer::tag('div', (string)$value, ['class' => 'display-4 font-weight-bold mb-1']);
    $html .= html_writer::tag('div', s($caption), ['class' => 'text-muted']);
    echo html_writer::div(html_writer::div($html, 'card card-body h-100'), 'col-12 col-sm-6 col-xl-3');
}
echo html_writer::end_div();

if (!$data['can_manage'] && !empty($data['continue_url'])) {
    echo html_writer::div(
        html_writer::link($data['continue_url'], get_string('bms_learning_continue', 'local_kbacompany'), [
            'class' => 'btn btn-primary btn-lg',
        ]),
        'mb-4'
    );
}

if ($data['can_manage']) {
    echo $OUTPUT->heading(get_string('bms_learning_warnings_title', 'local_kbacompany'), 3);
    if (!$data['warnings'] && !$data['drift']) {
        echo $OUTPUT->notification(get_string('bms_learning_no_warnings', 'local_kbacompany'), 'success');
    } else {
        foreach ($data['warnings'] as $warning) {
            if ($warning['type'] === 'attention') {
                $text = get_string('bms_learning_attention_warning', 'local_kbacompany', (int)$warning['count']);
            } else {
                $coursename = trim((string)$warning['course_name']);
                $reason = trim((string)$warning['reason']);
                $text = get_string('bms_learning_license_warning', 'local_kbacompany', (object)[
                    'course' => $coursename !== '' ? $coursename : '—',
                    'reason' => $reason !== '' ? $reason : '—',
                ]);
            }
            echo $OUTPUT->notification($text, 'warning');
        }
        foreach ($data['drift'] as $drift) {
            echo $OUTPUT->notification(get_string('bms_learning_drift_warning', 'local_kbacompany', (object)[
                'course' => $drift['course_name'],
                'allocated' => $drift['allocated'],
                'enrolled' => $drift['enrolled'],
            ]), 'warning');
        }
        if ($data['drift']) {
            echo html_writer::tag('p', get_string('bms_learning_drift_note', 'local_kbacompany'), ['class' => 'text-muted small']);
        }
    }

    if (!empty($data['links'])) {
        echo $OUTPUT->heading(get_string('bms_learning_actions', 'local_kbacompany'), 3);
        echo html_writer::start_div('mb-4');
        $actions = [
            'company_courses' => 'bms_learning_open_company_courses',
            'progress' => 'bms_learning_open_progress',
            'licenses' => 'bms_learning_open_licenses',
            'dashboard' => 'bms_learning_open_corporate',
        ];
        foreach ($actions as $key => $stringkey) {
            if (!empty($data['links'][$key])) {
                echo html_writer::link($data['links'][$key], get_string($stringkey, 'local_kbacompany'),
                    ['class' => 'btn btn-outline-primary mr-2 mb-2']);
            }
        }
        echo html_writer::end_div();
    }

    echo $OUTPUT->heading(get_string('bms_learning_company_courses', 'local_kbacompany'), 3);
    if ($data['company_courses']) {
        $table = new html_table();
        $table->head = [
            get_string('course'),
            get_string('bms_learning_training_employees', 'local_kbacompany'),
            get_string('bms_learning_completed_employees', 'local_kbacompany'),
            get_string('bms_learning_purchased_seats', 'local_kbacompany'),
            get_string('bms_learning_allocated_seats', 'local_kbacompany'),
            get_string('bms_learning_free_seats', 'local_kbacompany'),
        ];
        foreach ($data['company_courses'] as $course) {
            $table->data[] = [
                html_writer::link($course['url'], format_string($course['name'])),
                (string)$course['team_training'],
                (string)$course['team_completed'],
                (string)$course['purchased_seats'],
                (string)$course['allocated_seats'],
                (string)$course['free_seats'],
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('bms_learning_no_company_courses', 'local_kbacompany'), 'info');
    }

    echo $OUTPUT->heading(get_string('bms_learning_team', 'local_kbacompany'), 3);
    if ($data['team']) {
        $table = new html_table();
        $table->head = [
            get_string('employee', 'local_kbacompany'),
            get_string('positionname', 'local_kbacompany'),
            get_string('status'),
            get_string('bms_learning_active_courses', 'local_kbacompany'),
            get_string('bms_learning_completed_courses', 'local_kbacompany'),
            get_string('actions'),
        ];
        foreach ($data['team'] as $employee) {
            $actions = '';
            if ($employee['corporate_url'] !== '') {
                $actions = html_writer::link($employee['corporate_url'], get_string('bms_learning_open_employee', 'local_kbacompany'),
                    ['class' => 'btn btn-sm btn-outline-primary']);
            }
            $table->data[] = [
                s($employee['full_name']),
                $employee['position'] !== '' ? s($employee['position']) : '—',
                s(get_string('bms_learning_status_' . $employee['status'], 'local_kbacompany')),
                (string)$employee['active_courses'],
                (string)$employee['completed_courses'],
                $actions,
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noemployees', 'local_kbacompany'), 'info');
    }
} else {
    echo $OUTPUT->heading(get_string('bms_learning_my_active_courses', 'local_kbacompany'), 3);
    $activeitems = [];
    foreach ($data['company_courses'] as $course) {
        if ($course['my_status'] !== 'in_progress') {
            continue;
        }
        $activeitems[] = html_writer::tag('li',
            html_writer::link($course['url'], format_string($course['name'])),
            ['class' => 'list-group-item']);
    }
    if ($activeitems) {
        echo html_writer::tag('ul', implode('', $activeitems), ['class' => 'list-group mb-4']);
    } else {
        echo $OUTPUT->notification(get_string('bms_learning_no_active_courses', 'local_kbacompany'), 'info');
    }

    echo $OUTPUT->heading(get_string('bms_learning_course_statuses', 'local_kbacompany'), 3);
    if ($data['company_courses']) {
        $table = new html_table();
        $table->head = [get_string('course'), get_string('status')];
        foreach ($data['company_courses'] as $course) {
            $status = get_string('bms_learning_status_' . $course['my_status'], 'local_kbacompany');
            $table->data[] = [
                html_writer::link($course['url'], format_string($course['name'])),
                s($status),
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('bms_learning_no_company_courses', 'local_kbacompany'), 'info');
    }

    if ($data['other_courses']) {
        echo $OUTPUT->heading(get_string('bms_learning_other_courses', 'local_kbacompany'), 3);
        echo html_writer::tag('p', get_string('bms_learning_other_courses_intro', 'local_kbacompany'), ['class' => 'text-muted']);
        $items = [];
        foreach ($data['other_courses'] as $course) {
            $items[] = html_writer::tag('li', html_writer::link($course['url'], format_string($course['name'])),
                ['class' => 'list-group-item']);
        }
        echo html_writer::tag('ul', implode('', $items), ['class' => 'list-group mb-4']);
    }
}

\local_kbacompany\local\bms_page_shell_service::finish($shell);
