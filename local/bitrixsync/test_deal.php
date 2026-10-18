<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/test_deal.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('test_deal_sync', 'local_bitrixsync'));
$PAGE->set_heading(get_string('pluginname', 'local_bitrixsync'));

$result = null;
$error = null;

if (data_submitted()) {
    require_sesskey();

    $dealid = required_param('dealid', PARAM_INT);
    $overrideuserid = optional_param('userid', 0, PARAM_INT);
    $mode = optional_param('mode', 'dryrun', PARAM_ALPHA);
    $confirmrun = optional_param('confirmrun', 0, PARAM_BOOL);

    $dryrun = ($mode !== 'run');

    if (!$dryrun && !$confirmrun) {
        $error = get_string('confirm_real_run_required', 'local_bitrixsync');
    } else {
        try {
            $flow = new \local_bitrixsync\services\deal_flow_service();

            $result = $flow->process(
                $dealid,
                $dryrun,
                true,
                $overrideuserid > 0 ? $overrideuserid : null
            );
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('test_deal_sync', 'local_bitrixsync'));

echo $OUTPUT->notification(
    get_string('test_deal_fullflow_warning', 'local_bitrixsync'),
    'info'
);

if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url,
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);

echo html_writer::start_div('form-group');
echo html_writer::tag(
    'label',
    get_string('deal_id', 'local_bitrixsync'),
    ['for' => 'dealid']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'dealid',
    'id' => 'dealid',
    'min' => '1',
    'required' => 'required',
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag(
    'label',
    get_string('optional_moodle_user_override', 'local_bitrixsync'),
    ['for' => 'userid']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'userid',
    'id' => 'userid',
    'min' => '1',
    'class' => 'form-control',
]);
echo html_writer::tag(
    'small',
    get_string('optional_moodle_user_override_desc', 'local_bitrixsync'),
    ['class' => 'form-text text-muted']
);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::select([
    'dryrun' => get_string('dry_run', 'local_bitrixsync'),
    'run' => get_string('real_run', 'local_bitrixsync'),
], 'mode', 'dryrun', false, ['class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('form-check mb-3');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'confirmrun',
    'id' => 'confirmrun',
    'value' => '1',
    'class' => 'form-check-input',
]);
echo html_writer::tag(
    'label',
    get_string('confirm_real_run_fullflow', 'local_bitrixsync'),
    ['for' => 'confirmrun', 'class' => 'form-check-label']
);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('run_test', 'local_bitrixsync'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if (is_array($result)) {
    echo $OUTPUT->heading(get_string('result', 'local_bitrixsync'), 3);

    $userresolution = $result['user_resolution'] ?? [];
    $proposed = $userresolution['proposed'] ?? null;

    $summary = new html_table();
    $summary->data = [
        [get_string('deal_run_id', 'local_bitrixsync'), (int)$result['dealrunid']],
        [get_string('deal_id', 'local_bitrixsync'), (int)$result['dealid']],
        [get_string('bitrix_company_id', 'local_bitrixsync'), (int)$result['context']['bitrixcompanyid']],
        [get_string('company_title', 'local_bitrixsync'), s($result['context']['companytitle']) ?: '—'],
        [get_string('bitrix_contact_id', 'local_bitrixsync'), (int)$result['context']['bitrixcontactid']],
        [get_string('user_resolution_status', 'local_bitrixsync'), s((string)($userresolution['status'] ?? '—'))],
        [get_string('moodle_user_id', 'local_bitrixsync'), !empty($result['userid']) ? (int)$result['userid'] : '—'],
        [get_string('contact_email', 'local_bitrixsync'), s((string)($userresolution['email'] ?? '')) ?: '—'],
        [get_string('product_rows', 'local_bitrixsync'), (int)$result['productrows']],
        [get_string('resolved_courses', 'local_bitrixsync'), (int)$result['resolved']],
        [get_string('enrolled_count', 'local_bitrixsync'), (int)$result['enrolled']],
        [get_string('already_count', 'local_bitrixsync'), (int)$result['already']],
        [get_string('failed_count', 'local_bitrixsync'), (int)$result['failed']],
        [get_string('unmapped_count', 'local_bitrixsync'), count($result['unmapped'])],
        [get_string('corporate_api_version', 'local_bitrixsync'), s($result['corporate']['api_release'])],
        [get_string('corporate_license_mode', 'local_bitrixsync'), s($result['corporate']['mode'])],
    ];

    if (is_array($proposed)) {
        $summary->data[] = [
            get_string('proposed_moodle_user', 'local_bitrixsync'),
            s(
                $proposed['firstname'] . ' ' .
                $proposed['lastname'] . ' / ' .
                $proposed['username']
            ),
        ];
    }

    echo html_writer::table($summary);

    if (!empty($result['crm']) && empty($result['crm']['dryrun'])) {
        echo $OUTPUT->heading(
            get_string('crm_writeback_result', 'local_bitrixsync'),
            3
        );

        $crmtable = new html_table();
        $crmtable->data = [
            [
                get_string('crm_fields', 'local_bitrixsync'),
                !empty($result['crm']['fields_ready'])
                    ? get_string('ok', 'local_bitrixsync')
                    : get_string('not_ready', 'local_bitrixsync'),
            ],
            [
                get_string('crm_company_writeback', 'local_bitrixsync'),
                s((string)($result['crm']['company']['status'] ?? '—')),
            ],
            [
                get_string('crm_contact_writeback', 'local_bitrixsync'),
                s((string)($result['crm']['contact']['status'] ?? '—')),
            ],
            [
                get_string('crm_deal_writeback', 'local_bitrixsync'),
                s((string)($result['crm']['deal']['status'] ?? '—')),
            ],
            [
                get_string('crm_deal_timeline', 'local_bitrixsync'),
                s((string)($result['crm']['timeline']['deal']['status'] ?? '—')) .
                    (!empty($result['crm']['timeline']['deal']['id'])
                        ? ' #' . (int)$result['crm']['timeline']['deal']['id']
                        : ''),
            ],
            [
                get_string('crm_company_timeline', 'local_bitrixsync'),
                s((string)($result['crm']['timeline']['company']['status'] ?? '—')) .
                    (!empty($result['crm']['timeline']['company']['id'])
                        ? ' #' . (int)$result['crm']['timeline']['company']['id']
                        : ''),
            ],
        ];

        echo html_writer::table($crmtable);

        if (!empty($result['crm']['errors'])) {
            echo $OUTPUT->notification(
                s(implode('; ', $result['crm']['errors'])),
                'warning'
            );
        }
    }

    $details = new html_table();
    $details->head = [
        get_string('product_row_id', 'local_bitrixsync'),
        get_string('bitrix_product_id', 'local_bitrixsync'),
        get_string('product_name', 'local_bitrixsync'),
        get_string('quantity', 'local_bitrixsync'),
        get_string('course_id', 'local_bitrixsync'),
        get_string('canonical_product_id', 'local_bitrixsync'),
        get_string('resolution_source', 'local_bitrixsync'),
        get_string('corporate_status', 'local_bitrixsync'),
        get_string('license_id', 'local_bitrixsync'),
        get_string('allocation_id', 'local_bitrixsync'),
        get_string('status'),
        get_string('error'),
    ];

    foreach ($result['items'] as $item) {
        $details->data[] = [
            (int)$item['productrowid'],
            (int)$item['productid'],
            s($item['productname']),
            s((string)$item['quantity']),
            $item['courseid'] ? (int)$item['courseid'] : '—',
            !empty($item['canonicalproductid'])
                ? (int)$item['canonicalproductid']
                : '—',
            !empty($item['resolutionsource'])
                ? s($item['resolutionsource'])
                : '—',
            s((string)($item['corporate']['status'] ?? '—')),
            !empty($item['corporate']['licenseid'])
                ? (int)$item['corporate']['licenseid']
                : '—',
            !empty($item['corporate']['allocationid'])
                ? (int)$item['corporate']['allocationid']
                : '—',
            s($item['status']),
            $item['error'] ? s($item['error']) : '—',
        ];
    }

    echo html_writer::table($details);
}

echo $OUTPUT->footer();
