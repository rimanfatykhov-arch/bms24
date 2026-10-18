<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$statuuid = optional_param('stat', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);
// Validate company access before processing any action or displaying data.
$data = product_service::context($uuid, (int)$USER->id, $statuuid);
$pageurl = new moodle_url('/local/kbacompany/products.php', ['uuid' => $uuid, 'stat' => $statuuid]);
$error = '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('productpostrequired', 'local_kbacompany');
    }
    require_sesskey();
    require_capability('local/kbacompany:manageorganization', $context);
    try {
        if ($action === 'product') {
            // Legacy POST remains compatible; canonical creation uses product_record.php.
            $target = explode(':', required_param('target', PARAM_RAW_TRIMMED), 2);
            $createduuid = product_service::create_product($uuid, (int)$USER->id, [
                'name' => required_param('name', PARAM_TEXT),
                'acceptance' => required_param('acceptance', PARAM_TEXT),
                'targettype' => $target[0], 'targetuuid' => $target[1] ?? '',
            ]);
            redirect(new moodle_url('/local/kbacompany/product_record.php',
                ['uuid' => $uuid, 'product' => $createduuid]), get_string('productsaved', 'local_kbacompany'));
        } else if ($action === 'statistic') {
            product_service::create_statistic($uuid, (int)$USER->id, [
                'productuuid' => required_param('productuuid', PARAM_RAW_TRIMMED),
                'code' => required_param('code', PARAM_TEXT), 'name' => required_param('name', PARAM_TEXT),
                'unit' => required_param('unit', PARAM_TEXT),
                'periodtype' => required_param('periodtype', PARAM_ALPHA),
                'direction' => required_param('direction', PARAM_ALPHA),
            ]);
        } else if ($action === 'value') {
            product_service::record_value($uuid, (int)$USER->id, $statuuid,
                required_param('periodstart', PARAM_RAW_TRIMMED), required_param('kind', PARAM_ALPHA),
                required_param('amount', PARAM_RAW_TRIMMED), optional_param('reason', '', PARAM_TEXT));
        } else {
            throw new moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        redirect($pageurl, get_string('productsaved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') {
            throw $e;
        }
        $error = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
// BMS page shell adapter selects the presentation layout.
$PAGE->set_title(get_string('productregistry', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'products', get_string('productregistry', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'));
echo html_writer::link(new moodle_url('/local/kbacompany/information_center.php', ['uuid' => $uuid]),
    get_string('productinfocenterlink', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo $OUTPUT->heading(get_string('productregistry', 'local_kbacompany') . ': ' . format_string($data['company']['name']));
echo html_writer::tag('p', get_string('productintro', 'local_kbacompany'), ['class' => 'lead']);
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}

// Target choices contain this company plus its active nodes and positions, across all branches.
$targets = [
    'company:' . $uuid => get_string('producttargetcompany', 'local_kbacompany') . ': ' . $data['company']['name'],
];
foreach (['node' => 'local_kbacompany_orgnode', 'position' => 'local_kbacompany_position'] as $type => $table) {
    foreach ($DB->get_records($table, ['companyuuid' => $uuid, 'active' => 1], 'code ASC') as $target) {
        $targets[$type . ':' . $target->uuid] = get_string('producttarget' . $type, 'local_kbacompany') .
            ': ' . $target->code . ' — ' . $target->name;
    }
}
$productoptions = [];
$table = new html_table();
$table->head = [get_string('productcode', 'local_kbacompany'), get_string('productname', 'local_kbacompany'),
    get_string('producttarget', 'local_kbacompany'), get_string('productacceptance', 'local_kbacompany'), get_string('actions')];
foreach ($data['products'] as $product) {
    if ((string)$product->status === 'active') {
        $productoptions[$product->uuid] = $product->code . ' — ' . $product->name;
    }
    $table->data[] = [s($product->code) . ' · ' . get_string((string)$product->status === 'archived' ?
        'productstatusarchived' : 'productstatusactive', 'local_kbacompany'), html_writer::link(new moodle_url('/local/kbacompany/product_record.php',
        ['uuid' => $uuid, 'product' => $product->uuid]), s($product->name)),
        s($targets[$product->targettype . ':' . $product->targetuuid] ?? get_string('producttargetunknown', 'local_kbacompany')),
        format_text($product->acceptance, FORMAT_PLAIN),
        html_writer::link(new moodle_url('/local/kbacompany/product_record.php',
            ['uuid' => $uuid, 'product' => $product->uuid]), get_string('view'),
            ['class' => 'btn btn-outline-secondary btn-sm']) .
        ($data['can_manage'] && (string)$product->status === 'active' ? ' ' . html_writer::link(new moodle_url('/local/kbacompany/product_record.php',
            ['uuid' => $uuid, 'product' => $product->uuid, 'mode' => 'edit']), get_string('edit'),
            ['class' => 'btn btn-outline-primary btn-sm']) : '')];
}
echo $OUTPUT->heading(get_string('productregistry', 'local_kbacompany'), 3);
echo $data['products'] ? html_writer::table($table) : $OUTPUT->notification(get_string('productempty', 'local_kbacompany'), 'info');

// Local rendering helpers; every form carries explicit company context and CSRF protection.
$startform = static function(string $action) use ($uuid, $statuuid): void {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
    foreach (['uuid' => $uuid, 'stat' => $statuuid, 'sesskey' => sesskey(), 'action' => $action] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
};
$input = static function(string $prefix, string $name, string $label, string $type = 'text',
        string $value = '', bool $required = true): void {
    $id = $prefix . '_' . $name;
    echo html_writer::label(get_string($label, 'local_kbacompany'), $id);
    $attrs = ['id' => $id, 'name' => $name, 'type' => $type, 'value' => $value];
    if ($required) {
        $attrs['required'] = 'required';
    }
    if ($type === 'number') {
        $attrs['step'] = '0.000001';
    }
    echo html_writer::empty_tag('input', $attrs);
};
$select = static function(string $prefix, string $name, string $label, array $options): void {
    $id = $prefix . '_' . $name;
    echo html_writer::label(get_string($label, 'local_kbacompany'), $id);
    echo html_writer::select($options, $name, '', false, ['id' => $id, 'required' => 'required']);
};
$endform = static function(string $label): void {
    echo html_writer::tag('button', get_string($label, 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
};
if ($data['can_manage']) {
    echo html_writer::link(new moodle_url('/local/kbacompany/product_record.php',
        ['uuid' => $uuid, 'mode' => 'create']), get_string('productcreate', 'local_kbacompany'),
        ['class' => 'btn btn-primary mb-4']);
}

echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]),
    get_string('bmsworkspace_indicators', 'local_kbacompany') . ' →', ['class' => 'btn btn-outline-primary mb-3']);
// The old statistics workflow is retained only as a legacy deep-link for existing users.
// The operational model now uses the canonical Indicators page.
if (optional_param('legacy', 0, PARAM_BOOL)) {
echo $OUTPUT->heading(get_string('statregistry', 'local_kbacompany'), 3);
$statoptions = [];
foreach ($data['statistics'] as $stat) {
    $statoptions[$stat->uuid] = $stat->code . ' — ' . $stat->name;
}
if ($statoptions) {
    $selector = new single_select(new moodle_url('/local/kbacompany/products.php', ['uuid' => $uuid]),
        'stat', $statoptions, $data['selected']->uuid, null);
    $selector->label = get_string('statselect', 'local_kbacompany');
    echo $OUTPUT->render($selector);
}
if ($data['can_manage'] && $productoptions) {
    echo html_writer::start_tag('details', ['class' => 'mb-4']);
    echo html_writer::tag('summary', get_string('statcreate', 'local_kbacompany'));
    $startform('statistic');
    $select('s', 'productuuid', 'productname', $productoptions);
    $input('s', 'code', 'productcode');
    $input('s', 'name', 'statname');
    $input('s', 'unit', 'statunit');
    $select('s', 'periodtype', 'statperiod', [
        'daily' => get_string('statdaily', 'local_kbacompany'),
        'weekly' => get_string('statweekly', 'local_kbacompany'),
        'monthly' => get_string('statmonthly', 'local_kbacompany')]);
    $select('s', 'direction', 'statdirection', [
        'up' => get_string('statup', 'local_kbacompany'),
        'down' => get_string('statdown', 'local_kbacompany'),
        'neutral' => get_string('statneutral', 'local_kbacompany')]);
    $endform('statcreate');
    echo html_writer::end_tag('details');
}
if ($data['selected']) {
    $stat = $data['selected'];
    echo $OUTPUT->heading(s($stat->name) . ' (' . s($stat->unit) . ')', 3);
    echo html_writer::tag('p', s($productoptions[$stat->productuuid] ?? '') . ' · ' .
        get_string('stat' . $stat->periodtype, 'local_kbacompany') . ' · ' .
        get_string('stat' . $stat->direction, 'local_kbacompany'));
    if ($data['can_manage']) {
        echo html_writer::start_tag('details', ['class' => 'mb-4']);
        echo html_writer::tag('summary', get_string('statrecord', 'local_kbacompany'));
        // Use selected UUID even when the page was opened without a stat parameter.
        $statuuid = $stat->uuid;
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4']);
        foreach (['uuid' => $uuid, 'stat' => $statuuid, 'sesskey' => sesskey(), 'action' => 'value'] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        $input('v', 'periodstart', 'statperiodstart', 'date');
        $select('v', 'kind', 'statkind', ['fact' => get_string('statfact', 'local_kbacompany'),
            'plan' => get_string('statplan', 'local_kbacompany')]);
        $input('v', 'amount', 'statamount', 'number');
        $input('v', 'reason', 'statreason', 'text', '', false);
        $endform('statrecord');
        echo html_writer::end_tag('details');
    }
    $table = new html_table();
    $table->head = [get_string('statperiodstart', 'local_kbacompany'), get_string('statfact', 'local_kbacompany'),
        get_string('statplan', 'local_kbacompany'), get_string('statdifference', 'local_kbacompany')];
    foreach (array_reverse($data['series']) as $row) {
        $difference = ($row['fact'] === null || $row['plan'] === null) ? null : (float)$row['fact'] - (float)$row['plan'];
        $table->data[] = [s($row['period']), $row['fact'] === null ? '—' : s($row['fact']),
            $row['plan'] === null ? '—' : s($row['plan']), $difference === null ? '—' : format_float($difference, 6)];
    }
    echo html_writer::tag('p', get_string('statwindow', 'local_kbacompany'));
    echo $data['series'] ? html_writer::table($table) : $OUTPUT->notification(get_string('statempty', 'local_kbacompany'), 'info');
    echo html_writer::start_tag('details');
    echo html_writer::tag('summary', get_string('stathistory', 'local_kbacompany'));
    $history = new html_table();
    $history->head = [get_string('statperiodstart', 'local_kbacompany'), get_string('statkind', 'local_kbacompany'),
        get_string('statamount', 'local_kbacompany'), get_string('statrevision', 'local_kbacompany'),
        get_string('statreason', 'local_kbacompany')];
    foreach ($data['history'] as $record) {
        $history->data[] = [s($record->periodstart), get_string('stat' . $record->kind, 'local_kbacompany'),
            s($record->amount), (int)$record->revision, s($record->reason ?? '')];
    }
    echo html_writer::table($history);
    echo html_writer::end_tag('details');
} else {
    echo $OUTPUT->notification(get_string('statempty', 'local_kbacompany'), 'info');
}
echo $OUTPUT->notification(get_string('productmethodnotice', 'local_kbacompany'), 'info');
}
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
