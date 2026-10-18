<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/catalog.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('moodle_master_catalog', 'local_bitrixsync'));
$PAGE->set_heading(get_string('pluginname', 'local_bitrixsync'));

$action = optional_param('action', '', PARAM_ALPHA);
$result = null;
$error = null;
$schemarepair = [];
$schemarepairraw = (string)get_config(
    'local_bitrixsync',
    'catalog_manual_schema_repair_last'
);
if ($schemarepairraw !== '') {
    $decodedschemarepair = json_decode($schemarepairraw, true);
    if (is_array($decodedschemarepair)) {
        $schemarepair = $decodedschemarepair;
    }
}
$legacymigratedat = (int)get_config(
    'local_bitrixsync',
    'catalog_legacy_schema_migrated'
);
$bindingdebug = [];
$repairresult = [];
$dbwritedebug = [];
$dbwritedebugraw = (string)get_config(
    'local_bitrixsync',
    'category_mapping_db_debug_last'
);
if ($dbwritedebugraw !== '') {
    $decodeddbwritedebug = json_decode($dbwritedebugraw, true);
    if (is_array($decodeddbwritedebug)) {
        $dbwritedebug = $decodeddbwritedebug;
    }
}

$repairraw = (string)get_config(
    'local_bitrixsync',
    'catalog_category_mapping_repair_last'
);
if ($repairraw !== '') {
    $decodedrepair = json_decode($repairraw, true);
    if (is_array($decodedrepair)) {
        $repairresult = $decodedrepair;
    }
}

$bindingdebugraw = (string)get_config('local_bitrixsync', 'catalog_binding_debug_last');
if ($bindingdebugraw !== '') {
    $decodedbindingdebug = json_decode($bindingdebugraw, true);
    if (is_array($decodedbindingdebug)) {
        $bindingdebug = $decodedbindingdebug;
    }
}

if ($action === 'manualschemarepair') {
    require_sesskey();

    try {
        $schemarepair = (
            new \local_bitrixsync\services\legacy_catalog_schema_repair_service()
        )->run();

        // Refresh DB diagnostics after the schema change.
        $dbwritedebug = (
            new \local_bitrixsync\services\category_mapping_db_diagnostic_service()
        )->run([8, 9, 10]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'dbmappingdebug') {
    require_sesskey();

    try {
        $dbwritedebug = (
            new \local_bitrixsync\services\category_mapping_db_diagnostic_service()
        )->run([8, 9, 10]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'repaircategories') {
    require_sesskey();

    try {
        $repairresult = (
            new \local_bitrixsync\services\catalog_category_mapping_repair_service()
        )->run();

        // Refresh diagnostics immediately after repair.
        $bindingdebug = (
            new \local_bitrixsync\services\catalog_binding_diagnostic_service()
        )->run();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'debug') {
    require_sesskey();

    try {
        $bindingdebug = (
            new \local_bitrixsync\services\catalog_binding_diagnostic_service()
        )->run();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'dryrun') {
    require_sesskey();

    try {
        $service = new \local_bitrixsync\services\moodle_master_catalog();
        $result = $service->sync(true);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'sync' && data_submitted()) {
    require_sesskey();

    $confirm = optional_param('confirm', 0, PARAM_BOOL);

    if (!$confirm) {
        $error = get_string('catalog_sync_confirm_required', 'local_bitrixsync');
    } else {
        try {
            $service = new \local_bitrixsync\services\moodle_master_catalog();
            $result = $service->sync(false);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('moodle_master_catalog', 'local_bitrixsync'));

echo $OUTPUT->notification(
    get_string('moodle_master_catalog_notice', 'local_bitrixsync'),
    'info'
);

if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}

$moodlecategories = (int)$DB->count_records('course_categories');
$moodlecourses = (int)$DB->count_records_select('course', 'id <> ?', [SITEID]);
$mappedcategories = 0;
try {
    $catmaptable = new xmldb_table('local_bitrixsync_catmap');
    $dbman = $DB->get_manager();

    if ($dbman->table_exists($catmaptable) &&
            $dbman->field_exists($catmaptable, new xmldb_field('bitrix_section_id'))) {
        $mappedcategories = (int)$DB->count_records_select(
            'local_bitrixsync_catmap',
            'bitrix_section_id IS NOT NULL AND bitrix_section_id > 0'
        );
    }
} catch (\Throwable $ignored) {
    // Summary must never make the whole Catalog page unavailable.
    $mappedcategories = 0;
}
$mappedcourses = (int)$DB->count_records('local_bitrixsync_productmap', ['active' => 1]);
$lastsync = (int)get_config('local_bitrixsync', 'moodle_catalog_last_sync');
$mode = (string)get_config('local_bitrixsync', 'catalog_master_api_mode');
$iblockid = (int)get_config('local_bitrixsync', 'catalog_master_iblock_id');

$summary = new html_table();
$summary->data = [
    [get_string('catalog_source', 'local_bitrixsync'), get_string('catalog_source_moodle', 'local_bitrixsync')],
    [get_string('moodle_categories', 'local_bitrixsync'), (string)$moodlecategories],
    [get_string('moodle_courses', 'local_bitrixsync'), (string)$moodlecourses],
    [get_string('bitrix_sections_bound', 'local_bitrixsync'), (string)$mappedcategories],
    [get_string('bitrix_products_bound', 'local_bitrixsync'), (string)$mappedcourses],
    [get_string('catalog_master_api_mode', 'local_bitrixsync'), $mode !== '' ? s($mode) : '—'],
    [get_string('catalog_iblock_id', 'local_bitrixsync'), $iblockid > 0 ? (string)$iblockid : '—'],
    [get_string('catalog_last_sync', 'local_bitrixsync'), $lastsync ? userdate($lastsync) : '—'],
];

echo html_writer::table($summary);

echo html_writer::start_div('mb-3');
echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php', [
        'action' => 'dryrun',
        'sesskey' => sesskey(),
    ]),
    get_string('catalog_dry_run', 'local_bitrixsync'),
    ['class' => 'btn btn-secondary mr-2']
);
echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php', [
        'action' => 'debug',
        'sesskey' => sesskey(),
    ]),
    'Диагностика привязки товаров',
    ['class' => 'btn btn-warning ml-2']
);
echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php', [
        'action' => 'repaircategories',
        'sesskey' => sesskey(),
    ]),
    'Восстановить связи категорий',
    [
        'class' => 'btn btn-danger ml-2',
        'onclick' => "return confirm('Восстановить локальные связи только с уже существующими разделами Bitrix24? Новые разделы создаваться не будут.');",
    ]
);
echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php', [
        'action' => 'dbmappingdebug',
        'sesskey' => sesskey(),
    ]),
    'DB диагностика category mapping',
    ['class' => 'btn btn-secondary ml-2']
);
echo html_writer::link(
    new moodle_url('/local/bitrixsync/catalog.php', [
        'action' => 'manualschemarepair',
        'sesskey' => sesskey(),
    ]),
    'Обновить структуру таблицы mapping',
    [
        'class' => 'btn btn-dark ml-2',
        'onclick' => "return confirm('Добавить canonical mapping-поля и скопировать legacy sectionid/parentcategoryid/lastsync? Старые поля и данные удаляться не будут.');",
    ]
);
echo html_writer::end_div();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/bitrixsync/catalog.php', ['action' => 'sync']),
    'class' => 'mb-4',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::start_div('form-check mb-3');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'confirm',
    'id' => 'confirmcatalogsync',
    'value' => '1',
    'class' => 'form-check-input',
]);
echo html_writer::tag(
    'label',
    get_string('catalog_sync_confirm', 'local_bitrixsync'),
    ['for' => 'confirmcatalogsync', 'class' => 'form-check-label']
);
echo html_writer::end_div();
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('sync_moodle_to_bitrix', 'local_bitrixsync'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if (!empty($schemarepair)) {
    echo $OUTPUT->heading('Manual Legacy Catalog Schema Repair', 3);

    echo $OUTPUT->notification(
        !empty($schemarepair['success'])
            ? 'Структура mapping обновлена. Старые legacy-поля сохранены.'
            : 'Schema repair завершился с ошибками. Смотрите детали ниже.',
        !empty($schemarepair['success']) ? 'success' : 'error'
    );

    foreach (['catmap' => 'Category mapping', 'productmap' => 'Product mapping'] as $key => $label) {
        $section = is_array($schemarepair[$key] ?? null)
            ? $schemarepair[$key]
            : [];

        echo html_writer::tag('h4', $label, ['class' => 'mt-3']);

        $table = new html_table();
        $table->data = [
            ['Таблица существует', !empty($section['table_exists']) ? 'Да' : 'Нет'],
            ['Поля до', implode(', ', (array)($section['before'] ?? [])) ?: '—'],
            ['Добавлено', implode(', ', (array)($section['added_fields'] ?? [])) ?: '—'],
            ['Поля после', implode(', ', (array)($section['after'] ?? [])) ?: '—'],
        ];
        echo html_writer::table($table);

        if (!empty($section['copied'])) {
            echo html_writer::tag(
                'pre',
                s(json_encode(
                    $section['copied'],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ))
            );
        }
    }

    if (!empty($schemarepair['errors'])) {
        echo html_writer::tag('h4', 'Ошибки Schema Repair', ['class' => 'mt-3']);
        echo html_writer::tag(
            'pre',
            s(json_encode(
                $schemarepair['errors'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ))
        );
    }

    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', 'Полный Schema Repair JSON');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $schemarepair,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
        ['class' => 'mt-3']
    );
    echo html_writer::end_tag('details');
}

if (!empty($dbwritedebug)) {
    echo $OUTPUT->heading('DB диагностика category mapping', 3);

    $summary = new html_table();
    $summary->data = [
        ['Таблица существует', !empty($dbwritedebug['table_exists']) ? 'Да' : 'Нет'],
        ['Колонок', count((array)($dbwritedebug['columns'] ?? []))],
        ['Индексов', count((array)($dbwritedebug['indexes'] ?? []))],
        ['Дубликатов', count((array)($dbwritedebug['duplicates'] ?? []))],
        ['Probe checks', count((array)($dbwritedebug['probe'] ?? []))],
    ];
    echo html_writer::table($summary);

    echo html_writer::tag('h4', 'Колонки', ['class' => 'mt-3']);
    $columnstable = new html_table();
    $columnstable->head = [
        'Поле',
        'Тип',
        'Длина',
        'NOT NULL',
        'Default',
        'Auto Increment',
    ];
    foreach ((array)($dbwritedebug['columns'] ?? []) as $name => $column) {
        $columnstable->data[] = [
            s((string)$name),
            s((string)($column['type'] ?? '')),
            (int)($column['max_length'] ?? 0),
            !empty($column['not_null']) ? 'Да' : 'Нет',
            array_key_exists('default', $column)
                ? s(var_export($column['default'], true))
                : '—',
            !empty($column['auto_increment']) ? 'Да' : 'Нет',
        ];
    }
    echo html_writer::table($columnstable);

    echo html_writer::tag('h4', 'Индексы', ['class' => 'mt-3']);
    $indextable = new html_table();
    $indextable->head = ['Индекс', 'Unique', 'Поля'];
    foreach ((array)($dbwritedebug['indexes'] ?? []) as $name => $index) {
        $indextable->data[] = [
            s((string)$name),
            !empty($index['unique']) ? 'Да' : 'Нет',
            s(implode(', ', (array)($index['columns'] ?? []))),
        ];
    }
    echo html_writer::table($indextable);

    echo html_writer::tag('h4', 'Категории 8 / 9 / 10', ['class' => 'mt-3']);
    $rowstable = new html_table();
    $rowstable->head = [
        'Category ID',
        'Название',
        'Mapping rows',
        'Текущие данные',
    ];
    foreach ((array)($dbwritedebug['rows'] ?? []) as $item) {
        $mappingrows = (array)($item['mapping_rows'] ?? []);
        $rowstable->data[] = [
            (int)($item['categoryid'] ?? 0),
            s((string)($item['category']['name'] ?? '')),
            count($mappingrows),
            html_writer::tag(
                'pre',
                s(json_encode(
                    $mappingrows,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ))
            ),
        ];
    }
    echo html_writer::table($rowstable);

    echo html_writer::tag('h4', 'Rollback-only DB probe', ['class' => 'mt-3']);
    $probetable = new html_table();
    $probetable->head = [
        'Category ID',
        'Статус',
        'Причина',
        'Error code',
        'Debug info',
    ];
    foreach ((array)($dbwritedebug['probe'] ?? []) as $probe) {
        $probetable->data[] = [
            (int)($probe['categoryid'] ?? 0),
            s((string)($probe['status'] ?? '')),
            s((string)($probe['reason'] ?? $probe['message'] ?? '')),
            s((string)($probe['errorcode'] ?? '')),
            html_writer::tag('pre', s((string)($probe['debuginfo'] ?? ''))),
        ];
    }
    echo html_writer::table($probetable);

    if (!empty($dbwritedebug['duplicates'])) {
        echo html_writer::tag('h4', 'Найденные дубли', ['class' => 'mt-3']);
        echo html_writer::tag(
            'pre',
            s(json_encode(
                $dbwritedebug['duplicates'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ))
        );
    }

    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', 'Полный DB Mapping Debug JSON');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $dbwritedebug,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
        ['class' => 'mt-3']
    );
    echo html_writer::end_tag('details');

    $columns = (array)($dbwritedebug['columns'] ?? []);
    $legacyrequired = [];
    foreach (['sectionid', 'parentcategoryid', 'lastsync'] as $legacyfield) {
        if (!empty($columns[$legacyfield]['not_null'])) {
            $legacyrequired[] = $legacyfield;
        }
    }

    if (!empty($legacyrequired)) {
        echo $OUTPUT->notification(
            'Обнаружена legacy-схема с обязательными полями: '
            . implode(', ', $legacyrequired)
            . '. v5.0.36.1.11 использует dual-write для совместимости.',
            'info'
        );
    }
}

if (!empty($repairresult)) {
    echo $OUTPUT->heading('Восстановление связей категорий', 3);

    $repairsummary = new html_table();
    $repairsummary->data = [
        ['Время', !empty($repairresult['time'])
            ? userdate((int)$repairresult['time']) : '—'],
        ['iblockId', (int)($repairresult['iblockid'] ?? 0)],
        ['Категорий', (int)($repairresult['total'] ?? 0)],
        ['Уже связаны', (int)($repairresult['already_bound'] ?? 0)],
        ['Восстановлено', (int)($repairresult['repaired'] ?? 0)],
        ['Не найдено', (int)($repairresult['not_found'] ?? 0)],
        ['Неоднозначно', (int)($repairresult['ambiguous'] ?? 0)],
        ['Ошибок', (int)($repairresult['errors'] ?? 0)],
    ];
    echo html_writer::table($repairsummary);

    $repairtable = new html_table();
    $repairtable->head = [
        'Moodle Category ID',
        'Название',
        'Bitrix Section ID',
        'Как найдено',
        'Статус',
        'Ошибка',
    ];

    foreach ((array)($repairresult['items'] ?? []) as $item) {
        $repairtable->data[] = [
            (int)($item['categoryid'] ?? 0),
            s((string)($item['name'] ?? '')),
            (int)($item['sectionid'] ?? 0),
            s((string)($item['matched_by'] ?? '')),
            s((string)($item['status'] ?? '')),
            s((string)($item['error'] ?? '')),
        ];
    }

    echo html_writer::table($repairtable);
    echo $OUTPUT->notification(
        'После восстановления связей запустите обычную синхронизацию каталога, '
        . 'чтобы курсы получили восстановленные Bitrix Section ID.',
        'info'
    );
}

if (is_array($result)) {
    echo $OUTPUT->heading(
        $result['dryrun']
            ? get_string('catalog_dry_run_result', 'local_bitrixsync')
            : get_string('catalog_sync_result', 'local_bitrixsync'),
        3
    );

    $resulttable = new html_table();
    $resulttable->data = [
        [get_string('catalog_master_api_mode', 'local_bitrixsync'), s($result['mode'])],
        [get_string('catalog_iblock_id', 'local_bitrixsync'), (int)$result['iblockid']],
        [get_string('categories_create', 'local_bitrixsync'), (int)$result['categories']['create']],
        [get_string('categories_update', 'local_bitrixsync'), (int)$result['categories']['update']],
        [get_string('categories_errors', 'local_bitrixsync'), (int)$result['categories']['errors']],
        [get_string('courses_create', 'local_bitrixsync'), (int)$result['courses']['create']],
        [get_string('courses_update', 'local_bitrixsync'), (int)$result['courses']['update']],
        [get_string('courses_errors', 'local_bitrixsync'), (int)$result['courses']['errors']],
    ];
    echo html_writer::table($resulttable);

    $details = new html_table();
    $details->head = [
        get_string('entity_type', 'local_bitrixsync'),
        get_string('moodle_id', 'local_bitrixsync'),
        get_string('name'),
        get_string('catalog_action', 'local_bitrixsync'),
        get_string('bitrix_id', 'local_bitrixsync'),
        get_string('status'),
        get_string('error'),
    ];

    foreach ($result['items'] as $item) {
        $details->data[] = [
            $item['type'] === 'category'
                ? get_string('category')
                : get_string('course'),
            (int)$item['moodleid'],
            s($item['name']),
            s($item['action']),
            $item['bitrixid'] ? (int)$item['bitrixid'] : '—',
            s($item['status']),
            $item['error'] ? s($item['error']) : '—',
        ];
    }

    echo html_writer::table($details);
}

if (!empty($bindingdebug)) {
    echo $OUTPUT->heading('Отладка привязки Moodle → Bitrix24', 3);

    $dbgctx = is_array($bindingdebug['context'] ?? null)
        ? $bindingdebug['context']
        : [];
    $dbgsummary = is_array($bindingdebug['summary'] ?? null)
        ? $bindingdebug['summary']
        : [];

    $debugsummary = new html_table();
    $debugsummary->data = [
        ['Время проверки', !empty($bindingdebug['time'])
            ? userdate((int)$bindingdebug['time']) : '—'],
        ['API mode', s((string)($dbgctx['mode'] ?? '—'))],
        ['Catalog ID', (string)($dbgctx['catalogid'] ?? '—')],
        ['Expected iblockId', (string)($dbgctx['iblockid'] ?? '—')],
        ['Курсов проверено', (string)($dbgsummary['total'] ?? 0)],
        ['OK', (string)($dbgsummary['ok'] ?? 0)],
        ['Warning', (string)($dbgsummary['warning'] ?? 0)],
        ['Error', (string)($dbgsummary['error'] ?? 0)],
    ];
    echo html_writer::table($debugsummary);

    $debugtable = new html_table();
    $debugtable->head = [
        'Course ID',
        'Курс',
        'Category ID',
        'Expected Section',
        'Product ID',
        'Map iblock',
        'Map section',
        'Live iblock',
        'Live section',
        'Статус',
        'Причина',
    ];

    foreach ((array)($bindingdebug['rows'] ?? []) as $row) {
        $status = (string)($row['status'] ?? '');
        $statuslabel = strtoupper($status);
        if ($status === 'ok') {
            $statuslabel = '✅ OK';
        } else if ($status === 'warning') {
            $statuslabel = '⚠ WARNING';
        } else {
            $statuslabel = '❌ ERROR';
        }

        $debugtable->data[] = [
            (int)($row['courseid'] ?? 0),
            s((string)($row['course'] ?? '')),
            (int)($row['categoryid'] ?? 0),
            (int)($row['expected_sectionid'] ?? 0),
            (int)($row['map_productid'] ?? 0),
            (int)($row['map_iblockid'] ?? 0),
            (int)($row['map_sectionid'] ?? 0),
            (int)($row['live_iblockid'] ?? 0),
            (int)($row['live_sectionid'] ?? 0),
            $statuslabel,
            s((string)($row['reason'] ?? '')),
        ];
    }

    echo html_writer::table($debugtable);

    echo html_writer::tag('h4', 'Фактические связи категорий в KBA Connect', ['class' => 'mt-4']);
    $catmappingtable = new html_table();
    $catmappingtable->head = [
        'Moodle Category ID',
        'Название',
        'Bitrix Section ID',
        'iblockId',
        'Статус',
        'Ошибка',
    ];
    foreach ((array)($bindingdebug['category_mappings'] ?? []) as $mapping) {
        $catmappingtable->data[] = [
            (int)($mapping['categoryid'] ?? 0),
            s((string)($mapping['name'] ?? '')),
            (int)($mapping['sectionid'] ?? 0),
            (int)($mapping['iblockid'] ?? 0),
            s((string)($mapping['status'] ?? '')),
            s((string)($mapping['error'] ?? '')),
        ];
    }
    echo html_writer::table($catmappingtable);

    echo html_writer::tag('h4', 'Фактические связи курсов в KBA Connect', ['class' => 'mt-4']);
    $prodmaptable = new html_table();
    $prodmaptable->head = [
        'Course ID',
        'Product ID',
        'Category ID',
        'Saved Section ID',
        'iblockId',
        'Статус',
        'Ошибка',
    ];
    foreach ((array)($bindingdebug['product_mappings'] ?? []) as $mapping) {
        $prodmaptable->data[] = [
            (int)($mapping['courseid'] ?? 0),
            (int)($mapping['productid'] ?? 0),
            (int)($mapping['categoryid'] ?? 0),
            (int)($mapping['sectionid'] ?? 0),
            (int)($mapping['iblockid'] ?? 0),
            s((string)($mapping['status'] ?? '')),
            s((string)($mapping['error'] ?? '')),
        ];
    }
    echo html_writer::table($prodmaptable);

    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', 'Полный Catalog Binding Debug JSON');
    echo html_writer::tag(
        'pre',
        s(json_encode(
            $bindingdebug,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
        ['class' => 'mt-3']
    );
    echo html_writer::end_tag('details');
}

echo $OUTPUT->heading(get_string('courses_binding', 'local_bitrixsync'), 3);

$coursesql = "SELECT c.id,
                     c.fullname,
                     c.shortname,
                     c.visible,
                     cc.name AS categoryname,
                     pm.bitrix_product_id,
                     pm.status,
                     pm.last_sync,
                     pm.error
                FROM {course} c
           LEFT JOIN {course_categories} cc ON cc.id = c.category
           LEFT JOIN {local_bitrixsync_productmap} pm ON pm.courseid = c.id AND pm.active = 1
               WHERE c.id <> :siteid
            ORDER BY cc.sortorder ASC, c.sortorder ASC, c.id ASC";

$courserecords = $DB->get_records_sql($coursesql, ['siteid' => SITEID]);

$coursetable = new html_table();
$coursetable->head = [
    'Moodle ID',
    get_string('course'),
    get_string('shortnamecourse'),
    get_string('category'),
    'Bitrix Product ID',
    get_string('status'),
    get_string('catalog_last_sync', 'local_bitrixsync'),
];

foreach ($courserecords as $course) {
    $coursetable->data[] = [
        (int)$course->id,
        format_string($course->fullname),
        s($course->shortname),
        s($course->categoryname),
        $course->bitrix_product_id ? (int)$course->bitrix_product_id : '—',
        $course->status ? s($course->status) : get_string('not_published', 'local_bitrixsync'),
        $course->last_sync ? userdate((int)$course->last_sync) : '—',
    ];
}

echo html_writer::table($coursetable);

echo $OUTPUT->footer();
