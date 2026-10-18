<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/legacy_products.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('legacy_product_migration', 'local_bitrixsync'));
$PAGE->set_heading(get_string('pluginname', 'local_bitrixsync'));

$dealid = optional_param('dealid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$inspection = [];
$message = null;
$error = null;

$resolver = new \local_bitrixsync\services\legacy_product_resolver();
$productrows = new \local_bitrixsync\services\product_row_resolver();

if ($dealid > 0) {
    try {
        $rows = $productrows->resolve_products($dealid);

        foreach ($rows as $row) {
            $legacyproductid = (int)($row['PRODUCT_ID'] ?? 0);
            $legacyname = trim((string)($row['PRODUCT_NAME'] ?? ''));

            $inspection[] = $resolver->inspect(
                $legacyproductid,
                $legacyname
            );
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'save' && data_submitted()) {
    require_sesskey();

    $selected = optional_param_array('savealias', [], PARAM_INT);
    $saved = 0;

    try {
        foreach ($inspection as $item) {
            $legacyid = (int)$item['legacyproductid'];

            if (empty($selected[$legacyid])) {
                continue;
            }

            if ($item['status'] !== 'suggested') {
                continue;
            }

            $resolver->save_alias(
                $legacyid,
                (string)$item['legacyname'],
                (int)$item['courseid'],
                (int)$item['canonicalproductid'],
                'exact_name'
            );
            $saved++;
        }

        redirect(
            new moodle_url('/local/bitrixsync/legacy_products.php', [
                'dealid' => $dealid,
            ]),
            get_string('legacy_aliases_saved', 'local_bitrixsync', $saved),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'delete') {
    require_sesskey();
    $id = required_param('id', PARAM_INT);
    $resolver->delete_alias($id);

    redirect(
        new moodle_url('/local/bitrixsync/legacy_products.php', [
            'dealid' => $dealid,
        ]),
        get_string('legacy_alias_deleted', 'local_bitrixsync'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('legacy_product_migration', 'local_bitrixsync'));

echo $OUTPUT->notification(
    get_string('legacy_product_migration_notice', 'local_bitrixsync'),
    'info'
);

if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/bitrixsync/legacy_products.php'),
    'class' => 'form-inline mb-4',
]);
echo html_writer::tag(
    'label',
    get_string('deal_id', 'local_bitrixsync'),
    ['for' => 'dealid', 'class' => 'mr-2']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'dealid',
    'id' => 'dealid',
    'value' => $dealid > 0 ? $dealid : '',
    'min' => '1',
    'required' => 'required',
    'class' => 'form-control mr-2',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('inspect_deal_products', 'local_bitrixsync'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($inspection) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/bitrixsync/legacy_products.php', [
            'action' => 'save',
            'dealid' => $dealid,
        ]),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    $table = new html_table();
    $table->head = [
        get_string('save'),
        get_string('legacy_product_id', 'local_bitrixsync'),
        get_string('legacy_product_name', 'local_bitrixsync'),
        get_string('legacy_match_status', 'local_bitrixsync'),
        get_string('course_id', 'local_bitrixsync'),
        get_string('canonical_product_id', 'local_bitrixsync'),
        get_string('legacy_match_method', 'local_bitrixsync'),
        get_string('error'),
    ];

    foreach ($inspection as $item) {
        $canselect = $item['status'] === 'suggested';

        $checkbox = $canselect
            ? html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'savealias[' . (int)$item['legacyproductid'] . ']',
                'value' => '1',
                'checked' => 'checked',
            ])
            : '—';

        $table->data[] = [
            $checkbox,
            (int)$item['legacyproductid'],
            s($item['legacyname']),
            s($item['status']),
            !empty($item['courseid']) ? (int)$item['courseid'] : '—',
            !empty($item['canonicalproductid'])
                ? (int)$item['canonicalproductid']
                : '—',
            $item['matchmethod'] !== ''
                ? s($item['matchmethod'])
                : '—',
            $item['error'] ? s($item['error']) : '—',
        ];
    }

    echo html_writer::table($table);

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('save_suggested_aliases', 'local_bitrixsync'),
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('saved_legacy_aliases', 'local_bitrixsync'), 3);

$aliases = $DB->get_records(
    'local_bitrixsync_pralias',
    null,
    'id DESC',
    '*',
    0,
    200
);

$aliastable = new html_table();
$aliastable->head = [
    'ID',
    get_string('legacy_product_id', 'local_bitrixsync'),
    get_string('legacy_product_name', 'local_bitrixsync'),
    get_string('course_id', 'local_bitrixsync'),
    get_string('canonical_product_id', 'local_bitrixsync'),
    get_string('legacy_match_method', 'local_bitrixsync'),
    get_string('legacy_last_used', 'local_bitrixsync'),
    get_string('actions'),
];

foreach ($aliases as $alias) {
    $deleteurl = new moodle_url('/local/bitrixsync/legacy_products.php', [
        'action' => 'delete',
        'id' => $alias->id,
        'dealid' => $dealid,
        'sesskey' => sesskey(),
    ]);

    $aliastable->data[] = [
        (int)$alias->id,
        (int)$alias->legacy_product_id,
        s($alias->legacy_product_name),
        (int)$alias->courseid,
        (int)$alias->canonical_product_id,
        s($alias->match_method),
        $alias->last_used ? userdate((int)$alias->last_used) : '—',
        html_writer::link($deleteurl, get_string('delete')),
    ];
}

echo html_writer::table($aliastable);
echo $OUTPUT->footer();
