<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\strategy_service;
use local_kbacompany\local\administrative_scale_service;
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\bms_ai_bridge_service;
use local_kbacompany\local\bms_collection_view_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = strategy_service::context($uuid, (int)$USER->id);
$adminscale = administrative_scale_service::context($uuid, (int)$USER->id);
$section = optional_param('section', 'overview', PARAM_ALPHA);
if (!in_array($section, ['overview', 'strategy', 'goals', 'map', 'setup'], true)) { $section = 'overview'; }
$selectedstrategyuuid = optional_param('strategy', '', PARAM_RAW_TRIMMED);
$selectedgoaluuid = optional_param('goal', '', PARAM_RAW_TRIMMED);
$view = bms_collection_view_service::normalise_view(optional_param('view', 'list', PARAM_ALPHA));
$q = optional_param('q', '', PARAM_RAW_TRIMMED);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('strategy_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item', '', PARAM_RAW_TRIMMED);
    $revision = optional_param('revision', 0, PARAM_INT);
    $redirectparams = ['uuid' => $uuid, 'section' => optional_param('returnsection', $section, PARAM_ALPHA)];

    if ($action === 'saveintent') {
        strategy_service::save_ownership_intent($uuid, (int)$USER->id, [
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'ownershippurpose' => required_param('ownershippurpose', PARAM_RAW_TRIMMED),
            'expectations' => optional_param('expectations', '', PARAM_RAW_TRIMMED),
            'constraints' => optional_param('constraints', '', PARAM_RAW_TRIMMED),
            'horizon' => optional_param('horizon', '', PARAM_RAW_TRIMMED),
            'longtermgoal' => required_param('longtermgoal', PARAM_RAW_TRIMMED),
        ], $item, $revision);
    } else if ($action === 'publishintent') {
        strategy_service::publish_ownership_intent($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'archiveintent') {
        strategy_service::archive_ownership_intent($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'savestrategy') {
        strategy_service::save_strategy($uuid, (int)$USER->id, [
            'ownerintentuuid' => required_param('ownerintentuuid', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'thesis' => required_param('thesis', PARAM_RAW_TRIMMED),
            'positioning' => optional_param('positioning', '', PARAM_RAW_TRIMMED),
            'horizonstart' => optional_param('horizonstart', '', PARAM_RAW_TRIMMED),
            'horizonend' => optional_param('horizonend', '', PARAM_RAW_TRIMMED),
            'businessmodeluuid' => optional_param('businessmodeluuid', '', PARAM_RAW_TRIMMED),
            'philosophyuuid' => optional_param('philosophyuuid', '', PARAM_RAW_TRIMMED),
            'marketuuid' => optional_param('marketuuid', '', PARAM_RAW_TRIMMED),
        ], $item, $revision);
    } else if ($action === 'publishstrategy') {
        strategy_service::publish_strategy($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'archivestrategy') {
        strategy_service::archive_strategy($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'savegoal') {
        $goal = strategy_service::save_goal($uuid, (int)$USER->id, [
            'strategyuuid' => required_param('strategyuuid', PARAM_RAW_TRIMMED),
            'name' => required_param('name', PARAM_RAW_TRIMMED),
            'perspective' => required_param('perspective', PARAM_ALPHA),
            'parentuuid' => optional_param('parentuuid', '', PARAM_RAW_TRIMMED),
            'outcome' => required_param('outcome', PARAM_RAW_TRIMMED),
            'targetdate' => optional_param('targetdate', '', PARAM_RAW_TRIMMED),
            'processuuid' => optional_param('processuuid', '', PARAM_RAW_TRIMMED),
            'ownerpositionuuid' => optional_param('ownerpositionuuid', '', PARAM_RAW_TRIMMED),
            'weight' => optional_param('weight', '', PARAM_RAW_TRIMMED),
            'sortorder' => optional_param('sortorder', 0, PARAM_INT),
        ], $item, $revision);
        $redirectparams['goal'] = $goal;
        $redirectparams['section'] = 'overview';
    } else if ($action === 'publishgoal') {
        strategy_service::publish_goal($uuid, (int)$USER->id, $item, $revision);
        $redirectparams['goal'] = $item;
        $redirectparams['section'] = 'overview';
    } else if ($action === 'archivegoal') {
        strategy_service::archive_goal($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'savelink') {
        strategy_service::save_causal_link($uuid, (int)$USER->id, [
            'strategyuuid' => required_param('strategyuuid', PARAM_RAW_TRIMMED),
            'fromgoaluuid' => required_param('fromgoaluuid', PARAM_RAW_TRIMMED),
            'togoaluuid' => required_param('togoaluuid', PARAM_RAW_TRIMMED),
            'relation' => required_param('relation', PARAM_ALPHA),
            'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
        ], $item, $revision);
    } else if ($action === 'publishlink') {
        strategy_service::publish_causal_link($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'archivelink') {
        strategy_service::archive_causal_link($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'bindindicator') {
        if (empty($data['bsc']['can_manage_indicators'])) {
            throw new moodle_exception('nopermissions', 'error');
        }
        $goaluuid = required_param('goaluuid', PARAM_RAW_TRIMMED);
        $binduuid = indicator_registry_service::save_binding($uuid, (int)$USER->id, [
            'statuuid' => required_param('statuuid', PARAM_RAW_TRIMMED),
            'contextkind' => 'strategygoal',
            'contextuuid' => $goaluuid,
            'isprimary' => optional_param('isprimary', 0, PARAM_BOOL),
            'critical' => optional_param('critical', 0, PARAM_BOOL),
            'weight' => optional_param('indicatorweight', '1', PARAM_RAW_TRIMMED),
            'sortorder' => optional_param('indicatorsortorder', 0, PARAM_INT),
        ]);
        $normkind = optional_param('normkind', 'target', PARAM_ALPHA);
        $targetvalue = optional_param('normtarget', '', PARAM_RAW_TRIMMED);
        $minval = optional_param('normmin', '', PARAM_RAW_TRIMMED);
        $maxval = optional_param('normmax', '', PARAM_RAW_TRIMMED);
        if ($targetvalue !== '' || $minval !== '' || $maxval !== '') {
            indicator_registry_service::save_norm($uuid, (int)$USER->id, [
                'binduuid' => $binduuid,
                'normkind' => $normkind,
                'targetvalue' => $targetvalue,
                'minval' => $minval,
                'maxval' => $maxval,
                'effectivefrom' => required_param('effectivefrom', PARAM_RAW_TRIMMED),
                'effectiveto' => optional_param('effectiveto', '', PARAM_RAW_TRIMMED),
            ]);
        }
        $redirectparams['goal'] = $goaluuid;
        $redirectparams['section'] = 'overview';
    } else if ($action === 'unbindindicator') {
        if (empty($data['bsc']['can_manage_indicators'])) {
            throw new moodle_exception('nopermissions', 'error');
        }
        indicator_registry_service::archive_binding($uuid, (int)$USER->id,
            required_param('binduuid', PARAM_RAW_TRIMMED));
        $redirectparams['goal'] = required_param('goaluuid', PARAM_RAW_TRIMMED);
        $redirectparams['section'] = 'overview';
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/strategy.php', $redirectparams),
        get_string('strategy_saved', 'local_kbacompany'));
}

$editkind = optional_param('editkind', '', PARAM_ALPHA);
$edituuid = optional_param('edituuid', '', PARAM_RAW_TRIMMED);
$datasets = [
    'intent' => $data['ownership_intents'],
    'strategy' => $data['strategies'],
    'goal' => $data['goals'],
    'link' => $data['causal_links'],
];
$editing = null;
if ($editkind !== '' && $edituuid !== '' && isset($datasets[$editkind])) {
    foreach ($datasets[$editkind] as $candidate) {
        if ($candidate['uuid'] === $edituuid) { $editing = $candidate; break; }
    }
}

$hidden = static function(string $action, ?array $row = null, string $returnsection = 'overview') use ($uuid): string {
    $html = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnsection', 'value' => $returnsection]);
    if ($row) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'item', 'value' => $row['uuid']]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $row['revision']]);
    }
    return $html;
};
$label = static function(string $key, string $id): string {
    return html_writer::label(get_string('strategy_' . $key, 'local_kbacompany'), $id, false,
        ['class' => 'font-weight-bold mt-2']);
};
$input = static function(string $name, string $value = '', string $type = 'text', bool $required = false): string {
    $attrs = ['type' => $type, 'id' => 'strategy_' . $name, 'name' => $name, 'value' => $value,
        'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::empty_tag('input', $attrs);
};
$textarea = static function(string $name, string $value = '', bool $required = false): string {
    $attrs = ['id' => 'strategy_' . $name, 'name' => $name, 'rows' => 3, 'class' => 'form-control'];
    if ($required) { $attrs['required'] = 'required'; }
    return html_writer::tag('textarea', s($value), $attrs);
};
$options = static function(array $rows, string $key = 'name'): array {
    $out = ['' => get_string('strategy_none', 'local_kbacompany')];
    foreach ($rows as $row) { $out[$row['uuid']] = $row[$key] ?? $row['uuid']; }
    return $out;
};
$echo_table = static function(array $head, array $rows): void {
    if (!$rows) {
        echo html_writer::tag('p', get_string('strategy_empty', 'local_kbacompany'), ['class' => 'text-muted']);
        return;
    }
    $table = new html_table();
    $table->head = $head;
    $table->data = $rows;
    echo html_writer::table($table);
};
$statusbadge = static function(string $health): string {
    $classes = [
        'green' => 'badge-success', 'yellow' => 'badge-warning', 'red' => 'badge-danger',
        'nodata' => 'badge-secondary', 'draft' => 'badge-light',
    ];
    return html_writer::tag('span', get_string('strategy_health_' . $health, 'local_kbacompany'),
        ['class' => 'badge ' . ($classes[$health] ?? 'badge-secondary')]);
};
$trendlabel = static function(array $trend): string {
    $quality = (string)($trend['quality'] ?? 'insufficient');
    $key = 'strategy_trend_' . $quality;
    return get_string($key, 'local_kbacompany');
};
$statusactions = static function(array $row, string $kind) use ($hidden, $uuid): string {
    $html = '';
    $targetsection = $kind === 'strategy' ? 'strategy' : ($kind === 'goal' ? 'goals' : ($kind === 'link' ? 'map' : 'setup'));
    if ($row['status'] === 'draft') {
        $html .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline mr-1']);
        $html .= $hidden('publish' . $kind, $row, $targetsection);
        $html .= html_writer::tag('button', get_string('strategy_publish', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']);
        $html .= html_writer::end_tag('form');
    }
    $html .= html_writer::link(new moodle_url('/local/kbacompany/strategy.php',
        ['uuid' => $uuid, 'section' => $targetsection, 'editkind' => $kind, 'edituuid' => $row['uuid']]),
        get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary mr-1']);
    $html .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
    $html .= $hidden('archive' . $kind, $row, $targetsection);
    $html .= html_writer::tag('button', get_string('strategy_archive', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
    $html .= html_writer::end_tag('form');
    return $html;
};

$intentname = [];
foreach ($data['ownership_intents'] as $r) { $intentname[$r['uuid']] = $r['name']; }
$strategyname = [];
foreach ($data['strategies'] as $r) { $strategyname[$r['uuid']] = $r['name']; }
$goalname = [];
foreach ($data['goals'] as $r) { $goalname[$r['uuid']] = $r['name']; }
$metricname = [];
foreach ($data['metrics'] as $r) { $metricname[$r['uuid']] = $r['name']; }
$processname = [];
foreach ($data['processes'] as $r) { $processname[$r['uuid']] = $r['name']; }
$positionname = [];
foreach ($data['positions'] as $r) { $positionname[$r['uuid']] = $r['name']; }

$availableStrategies = $data['published_strategies'] ?: $data['strategies'];
if ($selectedstrategyuuid === '' && $availableStrategies) { $selectedstrategyuuid = (string)$availableStrategies[0]['uuid']; }
$selectedstrategy = null;
foreach ($data['strategies'] as $row) {
    if ($row['uuid'] === $selectedstrategyuuid) { $selectedstrategy = $row; break; }
}
$visiblegoals = [];
foreach ($data['goals'] as $row) {
    if ($selectedstrategyuuid === '' || $row['strategyuuid'] === $selectedstrategyuuid) { $visiblegoals[] = $row; }
}
$selectedgoal = null;
foreach ($visiblegoals as $row) {
    if ($row['uuid'] === $selectedgoaluuid) { $selectedgoal = $row; break; }
}

$aistatus = bms_ai_bridge_service::status();
$aienabled = !empty($aistatus['available']);

$aibutton = static function(string $target, string $field, string $record = '', int $revision = 0) use ($aienabled): string {
    if (!$aienabled) {
        return html_writer::tag('span', get_string('bmsai_unavailable_short', 'local_kbacompany'),
            ['class' => 'badge badge-light text-muted']);
    }
    return html_writer::tag('button', '✨ ' . get_string('bmsai_help', 'local_kbacompany'), [
        'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary bms-ai-help bms-strategy-ai-help',
        'data-target' => $target, 'data-field' => $field, 'data-record' => $record, 'data-revision' => $revision,
    ]);
};
$aifield = static function(string $name, string $langkey, string $value, bool $required,
        string $helpkey, string $record = '', int $revision = 0) use ($textarea, $aibutton): string {
    $html = html_writer::start_div('card mb-3 bms-ai-field');
    $html .= html_writer::start_div('card-header d-flex justify-content-between align-items-center');
    $html .= html_writer::tag('label', get_string($langkey, 'local_kbacompany'), [
        'for' => 'strategy_' . $name, 'class' => 'font-weight-bold mb-0']);
    $html .= $aibutton('strategy_' . $name, $name, $record, $revision);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');
    $html .= $textarea($name, $value, $required);
    if ($helpkey !== '') {
        $html .= html_writer::tag('small', get_string($helpkey, 'local_kbacompany'), ['class' => 'form-text text-muted']);
    }
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();
    return $html;
};

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'strategy',
    get_string('strategy_title', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'strategy');
echo html_writer::link(new moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $uuid]),
    '← ' . get_string('backtocompany', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mb-3']);
echo $OUTPUT->heading(get_string('strategy_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2);
echo html_writer::tag('p', get_string('strategy_intro_v2', 'local_kbacompany'), ['class' => 'lead']);

// High-level navigation.
$nav = [];
foreach (['overview' => 'strategy_nav_overview', 'strategy' => 'strategy_nav_strategy', 'goals' => 'strategy_nav_goals', 'map' => 'strategy_nav_map'] as $key => $langkey) {
    $class = 'btn mr-2 mb-2 ' . ($section === $key ? 'btn-primary' : 'btn-outline-secondary');
    $nav[] = html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => $key,
        'strategy' => $selectedstrategyuuid]), get_string($langkey, 'local_kbacompany'), ['class' => $class]);
}
echo html_writer::div(implode('', $nav), 'mb-3');

// Strategy selector.
if ($availableStrategies) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body py-2']);
    echo html_writer::tag('strong', get_string('strategy_cycle_label', 'local_kbacompany') . ': ');
    foreach ($availableStrategies as $strategyrow) {
        $active = $strategyrow['uuid'] === $selectedstrategyuuid;
        $labeltext = $strategyrow['name'];
        if ($strategyrow['horizonstart'] !== '' || $strategyrow['horizonend'] !== '') {
            $labeltext .= ' · ' . trim($strategyrow['horizonstart'] . ' — ' . $strategyrow['horizonend'], ' —');
        }
        echo html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => $section,
            'strategy' => $strategyrow['uuid']]), s($labeltext), ['class' => 'badge ' . ($active ? 'badge-primary' : 'badge-light') . ' mr-2']);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if ($section === 'overview') {
    // Guided readiness overview.
    $readiness = [
        'owner' => count($data['published_owner_packages']) > 0,
        'philosophy' => count($data['published_philosophies']) > 0,
        'market' => count($data['published_markets']) > 0,
        'businessmodel' => count($data['published_business_models']) > 0,
        'strategy' => count($data['published_strategies']) > 0,
        'goals' => count($data['published_goals']) > 0,
    ];
    $readycount = count(array_filter($readiness));
    $percent = (int)round(($readycount / count($readiness)) * 100);
    echo html_writer::start_tag('div', ['class' => 'card mb-4']);
    echo html_writer::tag('div', get_string('strategy_readiness_title', 'local_kbacompany'), ['class' => 'card-header font-weight-bold']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('div', get_string('strategy_readiness_percent', 'local_kbacompany', $percent), ['class' => 'h4 mb-3']);
    echo html_writer::start_tag('div', ['class' => 'row']);
    foreach ($readiness as $key => $ok) {
        echo html_writer::start_tag('div', ['class' => 'col-lg-2 col-md-4 col-6 mb-2']);
        echo html_writer::div(($ok ? '✓ ' : '○ ') . get_string('strategy_readiness_' . $key, 'local_kbacompany'),
            'border rounded p-2 h-100 ' . ($ok ? 'bg-light' : ''));
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    if (!$readiness['owner']) {
        $nexturl = new moodle_url('/local/kbacompany/owner.php', ['uuid' => $uuid]);
        $nextlabel = get_string('strategy_next_owner', 'local_kbacompany');
    } else if (!$readiness['philosophy']) {
        $nexturl = new moodle_url('/local/kbacompany/philosophy.php', ['uuid' => $uuid]);
        $nextlabel = get_string('strategy_next_philosophy', 'local_kbacompany');
    } else if (!$readiness['market']) {
        $nexturl = new moodle_url('/local/kbacompany/market_assessment.php', ['uuid' => $uuid]);
        $nextlabel = get_string('strategy_next_market', 'local_kbacompany');
    } else if (!$readiness['strategy']) {
        $nexturl = new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'strategy']);
        $nextlabel = get_string('strategy_next_strategy', 'local_kbacompany');
    } else {
        $nexturl = new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'goals']);
        $nextlabel = get_string('strategy_next_goals', 'local_kbacompany');
    }
    echo html_writer::div(html_writer::tag('strong', get_string('strategy_next_step', 'local_kbacompany') . ': ') .
        html_writer::link($nexturl, $nextlabel), 'alert alert-info mb-0 mt-3');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // Platform links.
    $corecount = 0;
    foreach ($adminscale['core_kinds'] as $kind) { $corecount += (int)($adminscale['counts'][$kind] ?? 0); }
    $missingcore = count($adminscale['alignment']['missing_core']);
    echo html_writer::start_tag('div', ['class' => 'row mb-3']);
    echo html_writer::start_tag('div', ['class' => 'col-md-6 mb-2']);
    echo html_writer::div(html_writer::tag('h4', get_string('strategy_adminscale_card', 'local_kbacompany')) .
        html_writer::tag('p', get_string('strategy_adminscale_summary', 'local_kbacompany', (object)[
            'items' => $corecount, 'missing' => $missingcore,
        ]), ['class' => 'mb-2']) .
        html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid]),
            get_string('strategy_adminscale_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']), 'card-body');
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'col-md-6 mb-2']);
    echo html_writer::div(html_writer::tag('h4', get_string('strategy_execution_card', 'local_kbacompany')) .
        html_writer::tag('p', get_string('strategy_execution_desc', 'local_kbacompany'), ['class' => 'mb-2']) .
        html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid]),
            get_string('strategy_execution_open', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']), 'card-body');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo $OUTPUT->heading(get_string('strategy_bsc', 'local_kbacompany'), 3);
    if (!$visiblegoals) {
        echo html_writer::tag('p', get_string('strategy_bsc_empty_help', 'local_kbacompany'), ['class' => 'text-muted']);
    }
    echo html_writer::start_tag('div', ['class' => 'row mb-3']);
    foreach ($data['perspectives'] as $perspective) {
        $summary = ['total' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0, 'nodata' => 0, 'draft' => 0];
        foreach ($visiblegoals as $summarygoal) {
            if ($summarygoal['perspective'] !== $perspective) { continue; }
            $summary['total']++;
            $summaryhealth = $data['bsc']['goals'][$summarygoal['uuid']]['health'] ?? 'nodata';
            $summary[$summaryhealth]++;
        }
        echo html_writer::start_tag('div', ['class' => 'col-lg-3 col-md-6 mb-3']);
        echo html_writer::start_tag('div', ['class' => 'card h-100']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('h4', get_string('strategy_perspective_' . $perspective, 'local_kbacompany'));
        echo html_writer::tag('div', get_string('strategy_goals_count', 'local_kbacompany', $summary['total']), ['class' => 'mb-2']);
        echo $statusbadge('green') . ' ' . (int)$summary['green'] . ' &nbsp; ' .
            $statusbadge('yellow') . ' ' . (int)$summary['yellow'] . ' &nbsp; ' .
            $statusbadge('red') . ' ' . (int)$summary['red'];
        if ((int)$summary['nodata'] > 0) {
            echo html_writer::div($statusbadge('nodata') . ' ' . (int)$summary['nodata'], 'mt-2');
        }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');

    if ($data['can_manage'] && $data['published_strategies']) {
        echo html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'goals',
            'editkind' => 'goal']), '+ ' . get_string('strategy_newgoal', 'local_kbacompany'),
            ['class' => 'btn btn-primary mb-3']);
    }

    echo $OUTPUT->heading(get_string('strategy_goals_registry', 'local_kbacompany'), 3);
    $rows = [];
    foreach ($visiblegoals as $goal) {
        $model = $data['bsc']['goals'][$goal['uuid']] ?? ['health' => 'nodata', 'indicator_count' => 0,
            'in_target' => 0, 'assignees' => [], 'execution' => ['tasks' => 0, 'task_statuses' => []]];
        $assignees = [];
        foreach ($model['assignees'] as $person) { $assignees[] = $person['name']; }
        $taskstatuses = $model['execution']['task_statuses'];
        $tasksummary = get_string('strategy_execution_tasks_summary', 'local_kbacompany', (object)[
            'all' => (int)$model['execution']['tasks'],
            'done' => (int)($taskstatuses['done'] ?? 0),
        ]);
        $rows[] = [
            html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'overview',
                'strategy' => $selectedstrategyuuid, 'goal' => $goal['uuid']]), s($goal['code'])),
            s($goal['name']),
            get_string('strategy_perspective_' . $goal['perspective'], 'local_kbacompany'),
            s($positionname[$goal['ownerpositionuuid']] ?? '') . ($assignees ? html_writer::tag('small', '<br>' . s(implode(', ', $assignees))) : ''),
            $statusbadge($model['health']),
            get_string('strategy_indicators_in_target', 'local_kbacompany', (object)[
                'good' => (int)$model['in_target'], 'all' => (int)$model['indicator_count'],
            ]),
            s($tasksummary),
            s($goal['targetdate']),
        ];
    }
    $echo_table([
        get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
        get_string('strategy_perspective', 'local_kbacompany'), get_string('strategy_accountability', 'local_kbacompany'),
        get_string('strategy_health', 'local_kbacompany'), get_string('strategy_indicators', 'local_kbacompany'),
        get_string('strategy_execution', 'local_kbacompany'), get_string('strategy_targetdate', 'local_kbacompany'),
    ], $rows);

    if ($selectedgoal) {
        $model = $data['bsc']['goals'][$selectedgoal['uuid']] ?? null;
        echo html_writer::start_tag('div', ['class' => 'card mt-4']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::start_tag('div', ['class' => 'd-flex justify-content-between align-items-start flex-wrap']);
        echo html_writer::div(html_writer::tag('h3', s($selectedgoal['code'] . ' — ' . $selectedgoal['name'])) .
            html_writer::div($statusbadge($model['health']), 'mb-2'));
        if ($data['can_manage']) {
            echo html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'goals',
                'editkind' => 'goal', 'edituuid' => $selectedgoal['uuid']]), get_string('edit'),
                ['class' => 'btn btn-outline-secondary']);
        }
        echo html_writer::end_tag('div');
        echo html_writer::tag('p', format_text($selectedgoal['outcome'], FORMAT_PLAIN));
        echo html_writer::start_tag('div', ['class' => 'row']);
        $ownertext = $positionname[$selectedgoal['ownerpositionuuid']] ?? get_string('strategy_none', 'local_kbacompany');
        if ($model['assignees']) {
            $names = [];
            foreach ($model['assignees'] as $person) { $names[] = $person['name']; }
            $ownertext .= ' · ' . implode(', ', $names);
        }
        $facts = [
            ['strategy_accountability', $ownertext],
            ['strategy_targetdate', $selectedgoal['targetdate'] ?: '—'],
            ['strategy_processuuid', $processname[$selectedgoal['processuuid']] ?? '—'],
            ['strategy_status', $selectedgoal['status']],
        ];
        foreach ($facts as $fact) {
            echo html_writer::start_tag('div', ['class' => 'col-md-3 mb-2']);
            echo html_writer::div(html_writer::tag('small', get_string($fact[0], 'local_kbacompany'), ['class' => 'text-muted d-block']) .
                html_writer::tag('strong', s($fact[1])), 'border rounded p-2 h-100');
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div');

        echo $OUTPUT->heading(get_string('strategy_indicators', 'local_kbacompany'), 4);
        $indicatorrows = [];
        foreach ($model['indicators'] as $indicator) {
            $metric = $indicator['metric'];
            $norm = $indicator['norm'];
            $normtext = '—';
            if (($norm['normkind'] ?? '') === 'target' && $norm['targetvalue'] !== null) {
                $normtext = (string)$norm['targetvalue'] . ' ' . $metric['unit'];
            } else if (($norm['normkind'] ?? '') === 'range' && $norm['minval'] !== null && $norm['maxval'] !== null) {
                $normtext = (string)$norm['minval'] . '–' . (string)$norm['maxval'] . ' ' . $metric['unit'];
            }
            $facttext = $indicator['latest'] === null ? '—' : (string)$indicator['latest'] . ' ' . $metric['unit'];
            $actions = '';
            if ($data['can_manage'] && $data['bsc']['can_manage_indicators'] && empty($indicator['binding']['compatibility'])) {
                $actions .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline']);
                $actions .= $hidden('unbindindicator', null, 'overview');
                $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'binduuid', 'value' => $indicator['binding']['uuid']]);
                $actions .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'goaluuid', 'value' => $selectedgoal['uuid']]);
                $actions .= html_writer::tag('button', get_string('delete'), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
                $actions .= html_writer::end_tag('form');
            }
            $indicatorrows[] = [
                s($metric['code']), s($metric['name']), s($facttext), s($normtext),
                $statusbadge($indicator['health']), s($trendlabel($indicator['trend'])),
                !empty($indicator['binding']['critical']) ? get_string('yes') : get_string('no'),
                s((string)$indicator['binding']['weight']), $actions,
            ];
        }
        $echo_table([
            get_string('strategy_code', 'local_kbacompany'), get_string('strategy_indicator', 'local_kbacompany'),
            get_string('strategy_fact', 'local_kbacompany'), get_string('strategy_norm', 'local_kbacompany'),
            get_string('strategy_health', 'local_kbacompany'), get_string('strategy_trend', 'local_kbacompany'),
            get_string('strategy_critical', 'local_kbacompany'), get_string('strategy_weight_short', 'local_kbacompany'),
            get_string('actions'),
        ], $indicatorrows);

        if ($data['can_manage'] && $data['bsc']['can_manage_indicators'] && $selectedgoal['status'] === 'published') {
            echo html_writer::start_tag('details', ['class' => 'mt-3 mb-3']);
            echo html_writer::tag('summary', get_string('strategy_bind_indicator', 'local_kbacompany'), ['class' => 'font-weight-bold']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'border rounded p-3 mt-2']);
            echo $hidden('bindindicator', null, 'overview');
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'goaluuid', 'value' => $selectedgoal['uuid']]);
            echo $label('metricuuid', 'strategy_statuuid') . html_writer::select($options($data['metrics']), 'statuuid', '', false,
                ['id' => 'strategy_statuuid', 'class' => 'form-control', 'required' => 'required']);
            echo html_writer::start_tag('div', ['class' => 'row']);
            echo html_writer::start_tag('div', ['class' => 'col-md-3']);
            echo $label('weight', 'strategy_indicatorweight') . $input('indicatorweight', '1');
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'col-md-3 pt-4']);
            echo html_writer::checkbox('critical', 1, false, get_string('strategy_critical', 'local_kbacompany'));
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'col-md-3 pt-4']);
            echo html_writer::checkbox('isprimary', 1, false, get_string('strategy_primary_indicator', 'local_kbacompany'));
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            $normopts = ['target' => get_string('strategy_norm_target', 'local_kbacompany'),
                'range' => get_string('strategy_norm_range', 'local_kbacompany')];
            echo html_writer::label(get_string('strategy_norm_kind', 'local_kbacompany'), 'strategy_normkind', false,
                ['class' => 'font-weight-bold mt-2']);
            echo html_writer::select($normopts, 'normkind', 'target', false, ['id' => 'strategy_normkind', 'class' => 'form-control']);
            echo html_writer::start_tag('div', ['class' => 'row']);
            echo html_writer::start_tag('div', ['class' => 'col-md-4']);
            echo html_writer::label(get_string('strategy_targetvalue', 'local_kbacompany'), 'strategy_normtarget', false,
                ['class' => 'font-weight-bold mt-2']);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'normtarget', 'id' => 'strategy_normtarget', 'class' => 'form-control']);
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'col-md-4']);
            echo html_writer::label(get_string('strategy_norm_min', 'local_kbacompany'), 'strategy_normmin', false,
                ['class' => 'font-weight-bold mt-2']);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'normmin', 'id' => 'strategy_normmin', 'class' => 'form-control']);
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'col-md-4']);
            echo html_writer::label(get_string('strategy_norm_max', 'local_kbacompany'), 'strategy_normmax', false,
                ['class' => 'font-weight-bold mt-2']);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'normmax', 'id' => 'strategy_normmax', 'class' => 'form-control']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            echo html_writer::label(get_string('strategy_effectivefrom', 'local_kbacompany'), 'strategy_effectivefrom', false,
                ['class' => 'font-weight-bold mt-2']);
            echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'effectivefrom', 'id' => 'strategy_effectivefrom',
                'class' => 'form-control', 'value' => date('Y-m-d'), 'required' => 'required']);
            echo html_writer::tag('button', get_string('strategy_bind_indicator', 'local_kbacompany'),
                ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('details');
        }

        echo $OUTPUT->heading(get_string('strategy_execution', 'local_kbacompany'), 4);
        $execution = $model['execution'];
        echo html_writer::tag('p', get_string('strategy_execution_goal_summary', 'local_kbacompany', (object)[
            'roots' => (int)$execution['roots'], 'items' => (int)$execution['items'], 'tasks' => (int)$execution['tasks'],
            'done' => (int)($execution['task_statuses']['done'] ?? 0),
        ]));
        echo html_writer::link(new moodle_url('/local/kbacompany/administrative_scale.php', ['uuid' => $uuid]),
            get_string('strategy_adminscale_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2']);
        echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $uuid]),
            get_string('strategy_execution_open', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
}


if ($section === 'strategy') {
    echo $OUTPUT->heading(get_string('strategy_guided_title', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('strategy_guided_help', 'local_kbacompany'), ['class' => 'text-muted']);

    // Canonical upstream context: Owner package, Philosophy, Market and Business Model are read-only here.
    echo html_writer::start_tag('div', ['class' => 'card mb-4']);
    echo html_writer::tag('div', get_string('strategy_context_title', 'local_kbacompany'), ['class' => 'card-header font-weight-bold']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::start_tag('div', ['class' => 'row']);
    $contextcards = [
        ['strategy_context_owner', count($data['published_owner_packages']), '/local/kbacompany/owner.php'],
        ['strategy_context_philosophy', count($data['published_philosophies']), '/local/kbacompany/philosophy.php'],
        ['strategy_context_market', count($data['published_markets']), '/local/kbacompany/market_assessment.php'],
        ['strategy_context_businessmodel', count($data['published_business_models']), '/local/kbacompany/business_model.php'],
    ];
    foreach ($contextcards as $card) {
        echo html_writer::start_tag('div', ['class' => 'col-lg-3 col-md-6 mb-2']);
        echo html_writer::start_tag('div', ['class' => 'border rounded p-3 h-100']);
        echo html_writer::tag('strong', get_string($card[0], 'local_kbacompany'), ['class' => 'd-block']);
        echo html_writer::tag('span', (string)$card[1], ['class' => 'h4 d-block mb-2']);
        echo html_writer::link(new moodle_url($card[2], ['uuid' => $uuid]), get_string('strategy_open_source', 'local_kbacompany'),
            ['class' => 'small']);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    echo html_writer::tag('div', get_string('strategy_context_readonly', 'local_kbacompany'), ['class' => 'alert alert-info mb-0 mt-2']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    echo $OUTPUT->heading(get_string('strategy_strategies', 'local_kbacompany'), 4);
    $rows = [];
    foreach ($data['strategies'] as $r) {
        $source = get_string('strategy_context_owner', 'local_kbacompany') . ' v' . (int)$r['ownerintentrevision'];
        $rows[] = [s($r['code']), s($r['name']), s($r['thesis']), s(trim($r['horizonstart'] . ' — ' . $r['horizonend'], ' —')),
            s($r['status']), $data['can_manage'] ? $statusactions($r, 'strategy') : ''];
    }
    $echo_table([get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
        get_string('strategy_thesis', 'local_kbacompany'), get_string('strategy_period', 'local_kbacompany'),
        get_string('strategy_status', 'local_kbacompany'), get_string('actions')], $rows);

    if ($data['can_manage']) {
        $r = $editkind === 'strategy' ? $editing : null;
        $owneropts = ['' => get_string('strategy_select_owner_package', 'local_kbacompany')];
        foreach ($data['published_owner_packages'] as $set) {
            $owneropts[$set['uuid']] = $set['label'];
        }
        // Legacy published intent remains selectable only for existing strategy compatibility.
        foreach ($data['published_ownership_intents'] as $legacy) {
            if (!isset($owneropts[$legacy['uuid']])) {
                $owneropts[$legacy['uuid']] = get_string('strategy_legacy_owner_context', 'local_kbacompany') . ': ' . $legacy['name'];
            }
        }
        if (!$owneropts || count($owneropts) === 1) {
            echo html_writer::div(get_string('strategy_owner_required', 'local_kbacompany'), 'alert alert-warning');
        } else {
            echo html_writer::start_tag('div', ['class' => 'card mb-4']);
            echo html_writer::tag('div', $r ? get_string('strategy_edit_strategy', 'local_kbacompany') : get_string('strategy_newstrategy', 'local_kbacompany'),
                ['class' => 'card-header font-weight-bold']);
            echo html_writer::start_tag('div', ['class' => 'card-body']);
            echo html_writer::tag('p', get_string('strategy_form_methodology', 'local_kbacompany'), ['class' => 'text-muted']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form']);
            echo $hidden('savestrategy', $r, 'strategy');
            echo $label('ownerintentuuid', 'strategy_ownerintentuuid') . html_writer::select($owneropts,
                'ownerintentuuid', $r['ownerintentuuid'] ?? '', false, ['id' => 'strategy_ownerintentuuid', 'class' => 'form-control']);
            echo html_writer::tag('small', get_string('strategy_owner_pin_help', 'local_kbacompany'), ['class' => 'form-text text-muted mb-2']);
            echo $label('name', 'strategy_name') . $input('name', $r['name'] ?? '', 'text', true);
            echo $aifield('thesis', 'strategy_thesis', $r['thesis'] ?? '', true,
                'strategy_thesis_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo $aifield('positioning', 'strategy_positioning', $r['positioning'] ?? '', false,
                'strategy_positioning_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo html_writer::start_tag('div', ['class' => 'row']);
            echo html_writer::start_tag('div', ['class' => 'col-md-6']);
            echo $label('horizonstart', 'strategy_horizonstart') . $input('horizonstart', $r['horizonstart'] ?? '', 'date');
            echo html_writer::end_tag('div');
            echo html_writer::start_tag('div', ['class' => 'col-md-6']);
            echo $label('horizonend', 'strategy_horizonend') . $input('horizonend', $r['horizonend'] ?? '', 'date');
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
            echo html_writer::tag('h5', get_string('strategy_pinned_context', 'local_kbacompany'), ['class' => 'mt-4']);
            $philosophyoptions = ['' => get_string('companycontext_none', 'local_kbacompany')];
            foreach ($data['published_philosophies'] as $row) {
                $philosophyoptions[$row['uuid']] = ($row['stablecode'] ?? '') . ' · v' . $row['revision'] . ' — ' . shorten_text($row['mission'], 80);
            }
            $marketoptions = ['' => get_string('companycontext_none', 'local_kbacompany')];
            foreach ($data['published_markets'] as $row) {
                $code = !empty($row['stablecode']) ? $row['stablecode'] : ($row['code'] ?? '');
                $marketoptions[$row['uuid']] = $code . ' · v' . $row['revision'] . ' — ' . $row['name'];
            }
            echo $label('philosophyuuid', 'strategy_philosophyuuid') . html_writer::select($philosophyoptions,
                'philosophyuuid', $r['philosophyuuid'] ?? '', false, ['id' => 'strategy_philosophyuuid', 'class' => 'form-control']);
            echo $label('marketuuid', 'strategy_marketuuid') . html_writer::select($marketoptions,
                'marketuuid', $r['marketuuid'] ?? '', false, ['id' => 'strategy_marketuuid', 'class' => 'form-control']);
            echo $label('businessmodeluuid', 'strategy_businessmodeluuid') . html_writer::select($options($data['published_business_models']),
                'businessmodeluuid', $r['businessmodeluuid'] ?? '', false, ['id' => 'strategy_businessmodeluuid', 'class' => 'form-control']);
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
        }
    }
}

if ($section === 'goals') {
    echo $OUTPUT->heading(get_string('strategy_goals_guided_title', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('strategy_goals_guided_help', 'local_kbacompany'), ['class' => 'text-muted']);
    if (!$data['published_strategies']) {
        echo html_writer::div(get_string('strategy_publish_strategy_first', 'local_kbacompany'), 'alert alert-warning');
    }
    $goalrows = bms_collection_view_service::filter_rows($visiblegoals, $q, ['code', 'name', 'perspective']);
    echo bms_collection_view_service::toolbar('/local/kbacompany/strategy.php', [
        'uuid'=>$uuid, 'section'=>'goals', 'strategy'=>$selectedstrategyuuid,
    ], $view, $q);

    if ($view === 'list') {
        $rows = [];
        foreach ($goalrows as $goal) {
            $model = $data['bsc']['goals'][$goal['uuid']] ?? ['health' => 'nodata', 'indicator_count' => 0, 'in_target' => 0, 'execution' => ['tasks' => 0]];
            $rows[] = [s($goal['code']), s($goal['name']), get_string('strategy_perspective_' . $goal['perspective'], 'local_kbacompany'),
                $statusbadge($model['health']), get_string('strategy_indicators_in_target', 'local_kbacompany', (object)['good' => (int)$model['in_target'], 'all' => (int)$model['indicator_count']]),
                s($positionname[$goal['ownerpositionuuid']] ?? '—'), s($goal['targetdate']), $data['can_manage'] ? $statusactions($goal, 'goal') : ''];
        }
        $echo_table([get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
            get_string('strategy_perspective', 'local_kbacompany'), get_string('strategy_health', 'local_kbacompany'),
            get_string('strategy_indicators', 'local_kbacompany'), get_string('strategy_accountability', 'local_kbacompany'),
            get_string('strategy_targetdate', 'local_kbacompany'), get_string('actions')], $rows);
    } else if ($view === 'kanban') {
        $columns = [];
        foreach ($data['perspectives'] as $perspective) {
            $columns[$perspective] = ['label'=>get_string('strategy_perspective_' . $perspective, 'local_kbacompany'), 'cards'=>[]];
        }
        foreach ($goalrows as $goal) {
            if (!isset($columns[$goal['perspective']])) { continue; }
            $model = $data['bsc']['goals'][$goal['uuid']] ?? ['health'=>'nodata','indicator_count'=>0,'in_target'=>0];
            $url = new moodle_url('/local/kbacompany/strategy.php', [
                'uuid'=>$uuid,'section'=>'goals','strategy'=>$selectedstrategyuuid,'goal'=>$goal['uuid'],'view'=>$view,'q'=>$q,
            ]);
            $columns[$goal['perspective']]['cards'][] = bms_collection_view_service::card(
                html_writer::link($url, s($goal['code'] . ' — ' . $goal['name'])),
                [get_string('strategy_health_' . $model['health'], 'local_kbacompany'), s($positionname[$goal['ownerpositionuuid']] ?? '—'), s($goal['targetdate'])],
                get_string('strategy_indicators_in_target', 'local_kbacompany', (object)['good'=>(int)$model['in_target'],'all'=>(int)$model['indicator_count']]));
        }
        echo bms_collection_view_service::kanban($columns);
    } else {
        $health = ['green'=>0,'yellow'=>0,'red'=>0,'nodata'=>0];
        $indicators = 0; $inTarget = 0;
        foreach ($goalrows as $goal) {
            $model = $data['bsc']['goals'][$goal['uuid']] ?? ['health'=>'nodata','indicator_count'=>0,'in_target'=>0];
            $h=(string)$model['health']; if (!isset($health[$h])) {$h='nodata';} $health[$h]++;
            $indicators += (int)$model['indicator_count']; $inTarget += (int)$model['in_target'];
        }
        echo bms_collection_view_service::analytics([
            ['label'=>get_string('bmsview_total','local_kbacompany'),'value'=>count($goalrows)],
            ['label'=>get_string('strategy_health_green','local_kbacompany'),'value'=>$health['green']],
            ['label'=>get_string('strategy_health_yellow','local_kbacompany'),'value'=>$health['yellow']],
            ['label'=>get_string('strategy_health_red','local_kbacompany'),'value'=>$health['red']],
            ['label'=>get_string('bmsview_nodata','local_kbacompany'),'value'=>$health['nodata']],
            ['label'=>get_string('strategy_indicators','local_kbacompany'),'value'=>$inTarget . ' / ' . $indicators],
        ]);
    }

    if ($data['can_manage'] && $data['published_strategies']) {
        $r = $editkind === 'goal' ? $editing : null;
        echo html_writer::start_tag('div', ['class' => 'card mb-4']);
        echo html_writer::tag('div', $r ? get_string('strategy_edit_goal', 'local_kbacompany') : get_string('strategy_newgoal', 'local_kbacompany'),
            ['class' => 'card-header font-weight-bold']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('p', get_string('strategy_goal_methodology', 'local_kbacompany'), ['class' => 'text-muted']);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form']) . $hidden('savegoal', $r, 'goals');
        echo $label('strategyuuid', 'strategy_strategyuuid') . html_writer::select($options($data['published_strategies']),
            'strategyuuid', $r['strategyuuid'] ?? $selectedstrategyuuid, false, ['id' => 'strategy_strategyuuid', 'class' => 'form-control']);
        echo $label('name', 'strategy_name') . $input('name', $r['name'] ?? '', 'text', true);
        $poptions = [];
        foreach ($data['perspectives'] as $p) { $poptions[$p] = get_string('strategy_perspective_' . $p, 'local_kbacompany'); }
        echo $label('perspective', 'strategy_perspective') . html_writer::select($poptions, 'perspective', $r['perspective'] ?? 'process', false,
            ['id' => 'strategy_perspective', 'class' => 'form-control']);
        echo $aifield('outcome', 'strategy_outcome', $r['outcome'] ?? '', true,
            'strategy_outcome_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
        echo $label('targetdate', 'strategy_targetdate') . $input('targetdate', $r['targetdate'] ?? '', 'date');
        echo $label('processuuid', 'strategy_processuuid') . html_writer::select($options($data['published_processes']), 'processuuid',
            $r['processuuid'] ?? '', false, ['id' => 'strategy_processuuid', 'class' => 'form-control']);
        echo $label('ownerpositionuuid', 'strategy_ownerpositionuuid') . html_writer::select($options($data['positions']), 'ownerpositionuuid',
            $r['ownerpositionuuid'] ?? '', false, ['id' => 'strategy_ownerpositionuuid', 'class' => 'form-control']);
        echo html_writer::tag('div', get_string('strategy_goal_indicator_note', 'local_kbacompany'), ['class' => 'alert alert-light mt-3']);
        echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
}

if ($section === 'map') {
    echo $OUTPUT->heading(get_string('strategy_map', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('strategy_map_help', 'local_kbacompany'), ['class' => 'text-muted']);
    foreach ($data['perspectives'] as $perspective) {
        echo html_writer::start_tag('div', ['class' => 'card mb-3']);
        echo html_writer::start_tag('div', ['class' => 'card-header']);
        echo html_writer::tag('strong', get_string('strategy_perspective_' . $perspective, 'local_kbacompany'));
        echo html_writer::end_tag('div');
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        $found = false;
        foreach ($visiblegoals as $goal) {
            if ($goal['perspective'] !== $perspective) { continue; }
            $found = true;
            $model = $data['bsc']['goals'][$goal['uuid']];
            echo html_writer::div($statusbadge($model['health']) . ' ' .
                html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'overview',
                    'strategy' => $selectedstrategyuuid, 'goal' => $goal['uuid']]), s($goal['code'] . ' — ' . $goal['name'])), 'mb-2');
        }
        if (!$found) { echo html_writer::tag('span', get_string('strategy_empty', 'local_kbacompany'), ['class' => 'text-muted']); }
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo $OUTPUT->heading(get_string('strategy_causal_links', 'local_kbacompany'), 4);
    $rows = [];
    foreach ($data['causal_links'] as $r) {
        if ($selectedstrategyuuid !== '' && $r['strategyuuid'] !== $selectedstrategyuuid) { continue; }
        $rows[] = [s($goalname[$r['fromgoaluuid']] ?? ''), get_string('strategy_relation_' . $r['relation'], 'local_kbacompany'),
            s($goalname[$r['togoaluuid']] ?? ''), s($r['description']), s($r['status']),
            $data['can_manage'] ? $statusactions($r, 'link') : ''];
    }
    $echo_table([get_string('strategy_fromgoal', 'local_kbacompany'), get_string('strategy_relation', 'local_kbacompany'),
        get_string('strategy_togoal', 'local_kbacompany'), get_string('strategy_description', 'local_kbacompany'),
        get_string('strategy_status', 'local_kbacompany'), get_string('actions')], $rows);
    if ($data['can_manage'] && count($data['published_goals']) >= 2) {
        echo html_writer::link(new moodle_url('/local/kbacompany/strategy.php', ['uuid' => $uuid, 'section' => 'map',
            'editkind' => 'link']), '+ ' . get_string('strategy_newlink', 'local_kbacompany'), ['class' => 'btn btn-primary']);
        if ($editkind === 'link') {
            $r = $editing;
            echo html_writer::start_tag('div', ['class' => 'card mt-3']);
            echo html_writer::tag('div', get_string('strategy_newlink', 'local_kbacompany'), ['class' => 'card-header font-weight-bold']);
            echo html_writer::start_tag('div', ['class' => 'card-body']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form']) . $hidden('savelink', $r, 'map');
            echo $label('strategyuuid', 'strategy_linkstrategyuuid') . html_writer::select($options($data['published_strategies']),
                'strategyuuid', $r['strategyuuid'] ?? $selectedstrategyuuid, false, ['id' => 'strategy_linkstrategyuuid', 'class' => 'form-control']);
            echo $label('fromgoal', 'strategy_fromgoal') . html_writer::select($options($data['published_goals']), 'fromgoaluuid',
                $r['fromgoaluuid'] ?? '', false, ['id' => 'strategy_fromgoal', 'class' => 'form-control']);
            $relopts = []; foreach ($data['causal_relations'] as $rel) { $relopts[$rel] = get_string('strategy_relation_' . $rel, 'local_kbacompany'); }
            echo $label('relation', 'strategy_relation') . html_writer::select($relopts, 'relation', $r['relation'] ?? 'drives', false,
                ['id' => 'strategy_relation', 'class' => 'form-control']);
            echo $label('togoal', 'strategy_togoal') . html_writer::select($options($data['published_goals']), 'togoaluuid',
                $r['togoaluuid'] ?? '', false, ['id' => 'strategy_togoal', 'class' => 'form-control']);
            echo $aifield('description', 'strategy_description', $r['description'] ?? '', false,
                '', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('div'); echo html_writer::end_tag('div');
        }
    }
}

if ($section === 'setup') {
    echo $OUTPUT->heading(get_string('strategy_setup_title', 'local_kbacompany'), 3);
    echo html_writer::tag('p', get_string('strategy_setup_help', 'local_kbacompany'), ['class' => 'text-muted']);

    echo $OUTPUT->heading(get_string('strategy_ownership', 'local_kbacompany'), 4);
    $rows = [];
    foreach ($data['ownership_intents'] as $r) {
        $rows[] = [s($r['code']), s($r['name']), s($r['longtermgoal']), s($r['horizon']), s($r['status']),
            $data['can_manage'] ? $statusactions($r, 'intent') : ''];
    }
    $echo_table([get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
        get_string('strategy_longtermgoal', 'local_kbacompany'), get_string('strategy_horizon', 'local_kbacompany'),
        get_string('strategy_status', 'local_kbacompany'), get_string('actions')], $rows);

    echo $OUTPUT->heading(get_string('strategy_strategies', 'local_kbacompany'), 4);
    $rows = [];
    foreach ($data['strategies'] as $r) {
        $rows[] = [s($r['code']), s($r['name']), s($intentname[$r['ownerintentuuid']] ?? ''), s($r['thesis']),
            s(trim($r['horizonstart'] . ' — ' . $r['horizonend'], ' —')), s($r['status']),
            $data['can_manage'] ? $statusactions($r, 'strategy') : ''];
    }
    $echo_table([get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
        get_string('strategy_ownerintentuuid', 'local_kbacompany'), get_string('strategy_thesis', 'local_kbacompany'),
        get_string('strategy_period', 'local_kbacompany'), get_string('strategy_status', 'local_kbacompany'), get_string('actions')], $rows);

    echo $OUTPUT->heading(get_string('strategy_goals_registry', 'local_kbacompany'), 4);
    $rows = [];
    foreach ($data['goals'] as $r) {
        $rows[] = [s($r['code']), s($r['name']), get_string('strategy_perspective_' . $r['perspective'], 'local_kbacompany'),
            s($positionname[$r['ownerpositionuuid']] ?? ''), s($r['targetdate']), s($r['status']),
            $data['can_manage'] ? $statusactions($r, 'goal') : ''];
    }
    $echo_table([get_string('strategy_code', 'local_kbacompany'), get_string('strategy_name', 'local_kbacompany'),
        get_string('strategy_perspective', 'local_kbacompany'), get_string('strategy_ownerpositionuuid', 'local_kbacompany'),
        get_string('strategy_targetdate', 'local_kbacompany'), get_string('strategy_status', 'local_kbacompany'), get_string('actions')], $rows);

    if ($data['can_manage']) {
        echo $OUTPUT->heading($editing ? get_string('strategy_edit', 'local_kbacompany') : get_string('strategy_create', 'local_kbacompany'), 3);

        if (!$editing || $editkind === 'intent') {
            $r = $editkind === 'intent' ? $editing : null;
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4 border rounded p-3']);
            echo $hidden('saveintent', $r, 'setup');
            echo html_writer::tag('h4', get_string('strategy_newintent', 'local_kbacompany'));
            echo html_writer::div(html_writer::tag('small', get_string('strategy_code_autogenerated', 'local_kbacompany'), ['class' => 'text-muted']) .
                ($r ? html_writer::tag('strong', s($r['code']), ['class' => 'd-block']) : ''), 'mb-2');
            foreach ([['name', true], ['horizon', false]] as $spec) {
                echo $label($spec[0], 'strategy_' . $spec[0]) . $input($spec[0], $r[$spec[0]] ?? '', 'text', $spec[1]);
            }
            foreach ([['ownershippurpose', true], ['expectations', false], ['constraints', false], ['longtermgoal', true]] as $spec) {
                echo $label($spec[0], 'strategy_' . $spec[0]) . $textarea($spec[0], $r[$spec[0]] ?? '', $spec[1]);
            }
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
        }

        if ((!$editing || $editkind === 'strategy') && $data['published_ownership_intents']) {
            $r = $editkind === 'strategy' ? $editing : null;
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4 border rounded p-3']) . $hidden('savestrategy', $r, 'setup');
            echo html_writer::tag('h4', get_string('strategy_newstrategy', 'local_kbacompany'));
            echo html_writer::div(html_writer::tag('small', get_string('strategy_code_autogenerated', 'local_kbacompany'), ['class' => 'text-muted']) .
                ($r ? html_writer::tag('strong', s($r['code']), ['class' => 'd-block']) : ''), 'mb-2');
            echo $label('ownerintentuuid', 'strategy_ownerintentuuid') . html_writer::select($options($data['published_ownership_intents']),
                'ownerintentuuid', $r['ownerintentuuid'] ?? '', false, ['id' => 'strategy_ownerintentuuid', 'class' => 'form-control']);
            echo $label('name', 'strategy_name') . $input('name', $r['name'] ?? '', 'text', true);
            echo $aifield('thesis', 'strategy_thesis', $r['thesis'] ?? '', true,
                'strategy_thesis_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo $aifield('positioning', 'strategy_positioning', $r['positioning'] ?? '', false,
                'strategy_positioning_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo $label('horizonstart', 'strategy_horizonstart') . $input('horizonstart', $r['horizonstart'] ?? '', 'date');
            echo $label('horizonend', 'strategy_horizonend') . $input('horizonend', $r['horizonend'] ?? '', 'date');
            echo $label('businessmodeluuid', 'strategy_businessmodeluuid') . html_writer::select($options($data['published_business_models']),
                'businessmodeluuid', $r['businessmodeluuid'] ?? '', false, ['id' => 'strategy_businessmodeluuid', 'class' => 'form-control']);
            $philosophyoptions = ['' => get_string('companycontext_none', 'local_kbacompany')];
            foreach ($data['published_philosophies'] as $row) {
                $philosophyoptions[$row['uuid']] = $row['stablecode'] . ' · v' . $row['revision'] . ' — ' . shorten_text($row['mission'], 80);
            }
            $marketoptions = ['' => get_string('companycontext_none', 'local_kbacompany')];
            foreach ($data['published_markets'] as $row) {
                $code = !empty($row['stablecode']) ? $row['stablecode'] : $row['code'];
                $marketoptions[$row['uuid']] = $code . ' · v' . $row['revision'] . ' — ' . $row['name'];
            }
            echo $label('philosophyuuid', 'strategy_philosophyuuid') . html_writer::select($philosophyoptions,
                'philosophyuuid', $r['philosophyuuid'] ?? '', false, ['id' => 'strategy_philosophyuuid', 'class' => 'form-control']);
            echo $label('marketuuid', 'strategy_marketuuid') . html_writer::select($marketoptions,
                'marketuuid', $r['marketuuid'] ?? '', false, ['id' => 'strategy_marketuuid', 'class' => 'form-control']);
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
        }

        if ((!$editing || $editkind === 'goal') && $data['published_strategies']) {
            $r = $editkind === 'goal' ? $editing : null;
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4 border rounded p-3']) . $hidden('savegoal', $r, 'overview');
            echo html_writer::tag('h4', get_string('strategy_newgoal', 'local_kbacompany'));
            echo html_writer::div(html_writer::tag('small', get_string('strategy_code_autogenerated', 'local_kbacompany'), ['class' => 'text-muted']) .
                ($r ? html_writer::tag('strong', s($r['code']), ['class' => 'd-block']) : ''), 'mb-2');
            echo $label('strategyuuid', 'strategy_strategyuuid') . html_writer::select($options($data['published_strategies']),
                'strategyuuid', $r['strategyuuid'] ?? $selectedstrategyuuid, false, ['id' => 'strategy_strategyuuid', 'class' => 'form-control']);
            echo $label('name', 'strategy_name') . $input('name', $r['name'] ?? '', 'text', true);
            $poptions = [];
            foreach ($data['perspectives'] as $p) { $poptions[$p] = get_string('strategy_perspective_' . $p, 'local_kbacompany'); }
            echo $label('perspective', 'strategy_perspective') . html_writer::select($poptions, 'perspective', $r['perspective'] ?? 'process', false,
                ['id' => 'strategy_perspective', 'class' => 'form-control']);
            echo $label('parentuuid', 'strategy_parentuuid') . html_writer::select($options($data['published_goals']), 'parentuuid',
                $r['parentuuid'] ?? '', false, ['id' => 'strategy_parentuuid', 'class' => 'form-control']);
            echo $aifield('outcome', 'strategy_outcome', $r['outcome'] ?? '', true,
                'strategy_outcome_help_guided', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo $label('targetdate', 'strategy_targetdate') . $input('targetdate', $r['targetdate'] ?? '', 'date');
            echo $label('processuuid', 'strategy_processuuid') . html_writer::select($options($data['published_processes']), 'processuuid',
                $r['processuuid'] ?? '', false, ['id' => 'strategy_processuuid', 'class' => 'form-control']);
            echo $label('ownerpositionuuid', 'strategy_ownerpositionuuid') . html_writer::select($options($data['positions']), 'ownerpositionuuid',
                $r['ownerpositionuuid'] ?? '', false, ['id' => 'strategy_ownerpositionuuid', 'class' => 'form-control']);
            echo $label('weight', 'strategy_weight') . $input('weight', $r['weight'] ?? '');
            echo $label('sortorder', 'strategy_sortorder') . $input('sortorder', (string)($r['sortorder'] ?? 0), 'number');
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
        }

        if ((!$editing || $editkind === 'link') && count($data['published_goals']) >= 2) {
            $r = $editkind === 'link' ? $editing : null;
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form mb-4 border rounded p-3']) . $hidden('savelink', $r, 'map');
            echo html_writer::tag('h4', get_string('strategy_newlink', 'local_kbacompany'));
            echo $label('strategyuuid', 'strategy_linkstrategyuuid') . html_writer::select($options($data['published_strategies']),
                'strategyuuid', $r['strategyuuid'] ?? $selectedstrategyuuid, false, ['id' => 'strategy_linkstrategyuuid', 'class' => 'form-control']);
            echo $label('fromgoal', 'strategy_fromgoal') . html_writer::select($options($data['published_goals']), 'fromgoaluuid',
                $r['fromgoaluuid'] ?? '', false, ['id' => 'strategy_fromgoal', 'class' => 'form-control']);
            $relopts = [];
            foreach ($data['causal_relations'] as $rel) { $relopts[$rel] = get_string('strategy_relation_' . $rel, 'local_kbacompany'); }
            echo $label('relation', 'strategy_relation') . html_writer::select($relopts, 'relation', $r['relation'] ?? 'drives', false,
                ['id' => 'strategy_relation', 'class' => 'form-control']);
            echo $label('togoal', 'strategy_togoal') . html_writer::select($options($data['published_goals']), 'togoaluuid',
                $r['togoaluuid'] ?? '', false, ['id' => 'strategy_togoal', 'class' => 'form-control']);
            echo $aifield('description', 'strategy_description', $r['description'] ?? '', false,
                '', $r['uuid'] ?? '', (int)($r['revision'] ?? 0));
            echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
            echo html_writer::end_tag('form');
        }
    }
}

if ($data['can_manage']) {
    echo html_writer::div('', 'bms-ai-drawer d-none', ['id' => 'bms-ai-panel', 'role' => 'dialog', 'aria-modal' => 'true']);
    \local_kbacompany\local\bms_ai_ui_service::init($uuid, 'strategy', null);
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
