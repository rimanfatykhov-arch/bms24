<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\financial_statement_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = financial_statement_service::context($uuid, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/financial_statements.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('financialstatement_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    if ($action === 'savestatement') {
        $type = required_param('statementtype', PARAM_ALPHA);
        if (!in_array($type, financial_statement_service::TYPES, true)) {
            throw new moodle_exception('financialstatement_typeinvalid', 'local_kbacompany');
        }
        $values = [];
        $sources = [];
        foreach (financial_statement_service::input_cells($type) as $cell) {
            [$code, $bucket] = $cell;
            $values[$code][$bucket] = optional_param('amount_' . strtolower($code) . '_' . $bucket,
                '0', PARAM_RAW_TRIMMED);
            if (!array_key_exists($code, $sources)) {
                $sources[$code] = optional_param('source_' . strtolower($code), '', PARAM_RAW_TRIMMED);
            }
        }
        financial_statement_service::save_statement($uuid, (int)$USER->id, [
            'code' => required_param('code', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'statementtype' => $type,
            'scenariouuid' => required_param('scenariouuid', PARAM_RAW_TRIMMED),
            'centeruuid' => optional_param('centeruuid', '', PARAM_RAW_TRIMMED),
            'periodstart' => required_param('periodstart', PARAM_RAW_TRIMMED),
            'periodend' => required_param('periodend', PARAM_RAW_TRIMMED),
            'currency' => required_param('currency', PARAM_ALPHA),
            'sourceinfo' => optional_param('sourceinfo', '', PARAM_RAW_TRIMMED),
            'notes' => optional_param('notes', '', PARAM_RAW_TRIMMED),
        ], $values, $sources, $item, $revision);
    } else if ($action === 'publishstatement') {
        financial_statement_service::publish_statement($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'archivestatement') {
        financial_statement_service::archive_statement($uuid, (int)$USER->id, $item, $revision);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/financial_statements.php', ['uuid' => $uuid]),
        get_string('financialstatement_saved', 'local_kbacompany'));
}

$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$newtype = optional_param('newtype', 'bdr', PARAM_ALPHA);
if (!in_array($newtype, financial_statement_service::TYPES, true)) {
    $newtype = 'bdr';
}
$editing = null;
foreach ($data['statements'] as $candidate) {
    if ($candidate['uuid'] === $edituuid) {
        $editing = $candidate;
        break;
    }
}
$type = $editing ? $editing['statementtype'] : $newtype;

$scenariooptions = [];
$scenariolabels = [];
foreach ($data['scenarios'] as $row) {
    $label = $row['code'] . ' — ' . $row['name'] . ' (v' . $row['revision'] . ')';
    $scenariooptions[$row['uuid']] = $label;
    $scenariolabels[$row['uuid']] = $label;
}
$centeroptions = ['' => get_string('financialstatement_companywide', 'local_kbacompany')];
$centerlabels = ['' => get_string('financialstatement_companywide', 'local_kbacompany')];
foreach ($data['centers'] as $row) {
    $label = $row['code'] . ' — ' . $row['name'] . ' (v' . $row['revision'] . ')';
    $centeroptions[$row['uuid']] = $label;
    $centerlabels[$row['uuid']] = $label;
}

$hidden = static function(string $action, ?array $row = null) use ($uuid): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    if ($row) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'item', 'value' => $row['uuid']]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $row['revision']]);
    }
    return $html;
};
$money = static function(float $amount, string $currency): string {
    return format_float($amount, 2) . ' ' . s($currency);
};
$statuslabel = static function(string $status): string {
    return get_string('financialmodel_status_' . $status, 'local_kbacompany');
};
$summarylabel = static function(array $row) use ($money): string {
    if ($row['statementtype'] === 'bdr') {
        return get_string('financialstatement_summary_netprofit', 'local_kbacompany') . ': ' .
            $money((float)$row['summary']['netprofit'], $row['currency']);
    }
    if ($row['statementtype'] === 'bdds') {
        return get_string('financialstatement_summary_closingcash', 'local_kbacompany') . ': ' .
            $money((float)$row['summary']['closingcash'], $row['currency']);
    }
    return get_string('financialstatement_summary_balancegap', 'local_kbacompany') . ': ' .
        $money((float)$row['summary']['balancegap'], $row['currency']);
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('financialstatement_title', 'local_kbacompany'));
echo $OUTPUT->heading(get_string('financialstatement_title', 'local_kbacompany') . ': ' .
    format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('financialstatement_intro', 'local_kbacompany'), ['class' => 'lead']);
echo $OUTPUT->notification(get_string('financialstatement_scope_notice', 'local_kbacompany'), 'info');
echo html_writer::link(new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid]),
    get_string('financialstatement_back_model', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2 mb-3']);
echo html_writer::link(new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid]),
    get_string('budgeting_open', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-3']);

if (!$data['scenarios']) {
    echo $OUTPUT->notification(get_string('financialstatement_need_scenario', 'local_kbacompany'), 'warning');
}

// Registry.
echo $OUTPUT->heading(get_string('financialstatement_registry', 'local_kbacompany'), 3);
if ($data['statements']) {
    $table = new html_table();
    $table->head = [get_string('financialmodel_code', 'local_kbacompany'),
        get_string('financialmodel_name', 'local_kbacompany'),
        get_string('financialstatement_type', 'local_kbacompany'),
        get_string('financialmodel_scenarios', 'local_kbacompany'),
        get_string('financialstatement_scope', 'local_kbacompany'),
        get_string('financialstatement_period', 'local_kbacompany'),
        get_string('financialmodel_status', 'local_kbacompany'),
        get_string('financialstatement_keyresult', 'local_kbacompany'), get_string('actions')];
    foreach ($data['statements'] as $row) {
        $actions = '';
        if ($data['can_manage'] && $row['status'] === 'draft') {
            $actions .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-1']) .
                $hidden('publishstatement', $row) . html_writer::tag('button',
                    get_string('financialmodel_publish', 'local_kbacompany'),
                    ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']) . html_writer::end_tag('form');
        }
        if ($data['can_manage']) {
            $actions .= html_writer::link(new moodle_url('/local/kbacompany/financial_statements.php',
                ['uuid' => $uuid, 'edituuid' => $row['uuid']]), get_string('edit'),
                ['class' => 'btn btn-sm btn-outline-secondary mr-1']);
            $actions .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']) .
                $hidden('archivestatement', $row) . html_writer::tag('button',
                    get_string('financialmodel_archive', 'local_kbacompany'),
                    ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']) . html_writer::end_tag('form');
        }
        $table->data[] = [s($row['code']), s($row['name']),
            s(get_string('financialstatement_type_' . $row['statementtype'], 'local_kbacompany')),
            s($scenariolabels[$row['scenariouuid']] ?? $row['scenariouuid']),
            s($centerlabels[$row['centeruuid']] ?? $row['centeruuid']),
            s($row['periodstart'] . ' — ' . $row['periodend']),
            s($statuslabel($row['status']) . ' · v' . $row['revision']),
            s($summarylabel($row)), $actions];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', get_string('financialstatement_empty', 'local_kbacompany'), ['class' => 'text-muted']);
}

if ($data['can_manage'] && $data['scenarios']) {
    echo html_writer::start_div('mb-3');
    foreach (financial_statement_service::TYPES as $t) {
        echo html_writer::link(new moodle_url('/local/kbacompany/financial_statements.php',
            ['uuid' => $uuid, 'newtype' => $t]), get_string('financialstatement_new_' . $t, 'local_kbacompany'),
            ['class' => 'btn btn-outline-primary mr-2 mb-2']);
    }
    echo html_writer::end_div();

    $statement = $editing ?: [
        'code' => '', 'name' => '', 'statementtype' => $type,
        'scenariouuid' => array_key_first($scenariooptions), 'centeruuid' => '',
        'periodstart' => date('Y-m-01'), 'periodend' => date('Y-m-t'), 'currency' => 'RUB',
        'sourceinfo' => '', 'notes' => '', 'values' => [], 'sources' => [],
    ];
    $type = $statement['statementtype'];
    $calc = financial_statement_service::calculate($type, $statement['values']);
    $displayvalues = $calc['values'];

    echo $OUTPUT->heading($editing ? get_string('financialstatement_edit', 'local_kbacompany') :
        get_string('financialstatement_new_' . $type, 'local_kbacompany'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-5']);
    echo $hidden('savestatement', $editing);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'statementtype', 'value' => $type]);

    $field = static function(string $labelkey, string $control): string {
        return html_writer::tag('div', html_writer::tag('label', get_string($labelkey, 'local_kbacompany'),
            ['class' => 'font-weight-bold']) . $control, ['class' => 'form-group']);
    };
    $input = static function(string $name, string $value = '', string $itype = 'text', bool $required = false): string {
        $attrs = ['type' => $itype, 'name' => $name, 'value' => $value, 'class' => 'form-control'];
        if ($required) { $attrs['required'] = 'required'; }
        return html_writer::empty_tag('input', $attrs);
    };
    $textarea = static function(string $name, string $value = '', int $rows = 3): string {
        return html_writer::tag('textarea', s($value), ['name' => $name, 'rows' => $rows, 'class' => 'form-control']);
    };

    echo $field('financialmodel_code', $input('code', $statement['code'], 'text', true));
    echo $field('financialmodel_name', $input('name', $statement['name'], 'text', true));
    echo $field('financialmodel_scenarios', html_writer::select($scenariooptions, 'scenariouuid',
        $statement['scenariouuid'], false, ['class' => 'form-control', 'required' => 'required']));
    echo $field('financialstatement_scope', html_writer::select($centeroptions, 'centeruuid',
        $statement['centeruuid'], false, ['class' => 'form-control']));
    echo $field('financialmodel_periodstart', $input('periodstart', $statement['periodstart'], 'date', true));
    echo $field('financialmodel_periodend', $input('periodend', $statement['periodend'], 'date', true));
    echo $field('financialmodel_currency', $input('currency', $statement['currency'], 'text', true));
    echo $field('financialstatement_source', $textarea('sourceinfo', $statement['sourceinfo'], 2));
    echo $field('financialstatement_notes', $textarea('notes', $statement['notes'], 2));

    $grid = new html_table();
    $grid->attributes['class'] = 'generaltable table-sm';
    if ($type === 'bdds') {
        $grid->head = [get_string('financialstatement_line', 'local_kbacompany')];
        for ($b = 1; $b <= financial_statement_service::BDDS_BUCKETS; $b++) {
            $grid->head[] = get_string('financialstatement_week', 'local_kbacompany', $b);
        }
        $grid->head[] = get_string('financialstatement_source', 'local_kbacompany');
    } else {
        $grid->head = [get_string('financialstatement_line', 'local_kbacompany'),
            get_string('financialstatement_amount', 'local_kbacompany'),
            get_string('financialstatement_source', 'local_kbacompany')];
    }
    foreach (financial_statement_service::template($type) as $row) {
        $cells = [s(get_string($row['labelkey'], 'local_kbacompany'))];
        $buckets = financial_statement_service::bucket_count($type);
        for ($b = 1; $b <= $buckets; $b++) {
            $editable = $row['kind'] === 'input' || ($row['kind'] === 'opening' && $b === 1);
            $value = isset($displayvalues[$row['code']][$b]) ? (float)$displayvalues[$row['code']][$b] : 0.0;
            if ($editable) {
                $raw = isset($statement['values'][$row['code']][$b]) ? (string)$statement['values'][$row['code']][$b] : '0';
                $cells[] = html_writer::empty_tag('input', ['type' => 'number', 'step' => '0.01',
                    'name' => 'amount_' . strtolower($row['code']) . '_' . $b, 'value' => $raw,
                    'class' => 'form-control form-control-sm']);
            } else if ($row['kind'] === 'percent') {
                $cells[] = s(format_float($value, 2) . '%');
            } else {
                $cells[] = s(format_float($value, 2));
            }
        }
        if ($row['kind'] === 'input' || $row['kind'] === 'opening') {
            $cells[] = html_writer::empty_tag('input', ['type' => 'text',
                'name' => 'source_' . strtolower($row['code']),
                'value' => $statement['sources'][$row['code']] ?? '', 'class' => 'form-control form-control-sm']);
        } else {
            $cells[] = '—';
        }
        $grid->data[] = $cells;
    }
    echo html_writer::table($grid);
    if ($type === 'balance' && abs((float)$calc['summary']['balancegap']) > 0.01) {
        echo $OUTPUT->notification(get_string('financialstatement_balance_warning', 'local_kbacompany',
            format_float((float)$calc['summary']['balancegap'], 2)), 'warning');
    }
    if ($type === 'bdds' && (int)$calc['summary']['negativebuckets'] > 0) {
        echo $OUTPUT->notification(get_string('financialstatement_cashgap_warning', 'local_kbacompany',
            (int)$calc['summary']['negativebuckets']), 'warning');
    }
    echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
