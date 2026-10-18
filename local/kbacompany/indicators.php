<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\indicator_condition_service;
use local_kbacompany\local\indicator_condition_execution_service;
use local_kbacompany\local\indicator_event_service;
use local_kbacompany\local\indicator_management_exception_service;
use local_kbacompany\local\indicator_management_cycle_service;
use local_kbacompany\local\indicator_registry_service;
use local_kbacompany\local\indicator_trend_service;
use local_kbacompany\local\indicator_performance_status_service;
use local_kbacompany\local\product_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$tab = optional_param('tab', 'center', PARAM_ALPHA);
$horizon = optional_param('horizon', 13, PARAM_INT);
$historydate = optional_param('historydate', '', PARAM_RAW_TRIMMED);
if ($historydate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historydate) || !checkdate((int)substr($historydate,5,2), (int)substr($historydate,8,2), (int)substr($historydate,0,4)))) { $historydate = ''; }
if (!in_array($horizon, [13, 26, 52], true)) { $horizon = 13; }
$treeview = optional_param('treeview', 'organization', PARAM_ALPHA);
$statuuid = optional_param('stat', '', PARAM_RAW_TRIMMED);
$createindicator = optional_param('createindicator', 0, PARAM_BOOL);
$metricsearch = trim(optional_param('metricsearch', '', PARAM_RAW_TRIMMED));
$metricstatus = optional_param('metricstatus', 'active', PARAM_ALPHA);
if (!in_array($metricstatus, ['active', 'archived', 'all'], true)) { $metricstatus = 'active'; }
$trendfilter = optional_param('trendfilter', 'all', PARAM_ALPHA);
$exceptionseverity = optional_param('exceptionseverity', 'all', PARAM_ALPHA);
$exceptiongroup = optional_param('exceptiongroup', 'all', PARAM_ALPHA);
$exceptioncycle = optional_param('exceptioncycle', 'all', PARAM_ALPHA);
if (!in_array($tab, ['center', 'exceptions', 'trends', 'conditions', 'tree', 'all', 'edit', 'input', 'norms'], true)) { $tab = 'center'; }
if (!in_array($trendfilter, ['all', 'rising', 'falling', 'worsening', 'insufficient'], true)) { $trendfilter = 'all'; }
if ($exceptionseverity !== 'all' && !in_array($exceptionseverity, indicator_management_exception_service::SEVERITIES, true)) { $exceptionseverity = 'all'; }
if ($exceptiongroup !== 'all' && !in_array($exceptiongroup, indicator_management_exception_service::GROUPS, true)) { $exceptiongroup = 'all'; }
if ($exceptioncycle !== 'all' && !in_array($exceptioncycle, indicator_management_cycle_service::FILTERS, true)) { $exceptioncycle = 'all'; }
if (!in_array($treeview, ['organization', 'strategy'], true)) { $treeview = 'organization'; }

if ($tab === 'edit' && $statuuid !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid, 'mode'=>'edit', 'stat'=>$statuuid]));
}
$data = indicator_registry_service::context($uuid, (int)$USER->id, $statuuid);
$pageurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid, 'tab' => $tab,
    'treeview' => $treeview, 'stat' => $statuuid, 'horizon' => $horizon, 'trendfilter' => $trendfilter,
    'exceptionseverity' => $exceptionseverity, 'exceptiongroup' => $exceptiongroup, 'exceptioncycle' => $exceptioncycle,
    'metricsearch' => $metricsearch, 'metricstatus' => $metricstatus]);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'createindicator') {
            $created = product_service::create_statistic($uuid, (int)$USER->id, [
                'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
                'code' => required_param('code', PARAM_RAW_TRIMMED),
                'name' => required_param('name', PARAM_RAW_TRIMMED),
                'unit' => required_param('unit', PARAM_RAW_TRIMMED),
                'periodtype' => required_param('periodtype', PARAM_ALPHA),
                'direction' => required_param('direction', PARAM_ALPHA),
            ]);
            indicator_registry_service::save_passport($uuid, (int)$USER->id, $created, [
                'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
                'sourcekind' => required_param('sourcekind', PARAM_ALPHA),
                'sourceconfig' => optional_param('sourceconfig', '', PARAM_RAW_TRIMMED),
                'aggregation' => required_param('aggregation', PARAM_ALPHA),
            ]);
            indicator_registry_service::set_main_company_metric($uuid, (int)$USER->id, $created,
                (bool)optional_param('maincompanymetric', 0, PARAM_BOOL));
            $statuuid = $created;
            $tab = 'all';
        } else if ($action === 'metricprofile') {
            $statuuid = required_param('statuuid', PARAM_RAW_TRIMMED);
            if (!indicator_registry_service::is_metric_active($uuid, $statuuid)) {
                throw new moodle_exception('indicator_crud_archived_help', 'local_kbacompany');
            }
            product_service::update_statistic($uuid, (int)$USER->id, $statuuid, [
                'productuuid' => optional_param('productuuid', '', PARAM_RAW_TRIMMED),
                'code' => required_param('code', PARAM_RAW_TRIMMED),
                'name' => required_param('name', PARAM_RAW_TRIMMED),
                'unit' => required_param('unit', PARAM_RAW_TRIMMED),
                'periodtype' => required_param('periodtype', PARAM_ALPHA),
                'direction' => required_param('direction', PARAM_ALPHA),
            ]);
            indicator_registry_service::save_passport($uuid, (int)$USER->id, $statuuid, [
                'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
                'sourcekind' => required_param('sourcekind', PARAM_ALPHA),
                'sourceconfig' => optional_param('sourceconfig', '', PARAM_RAW_TRIMMED),
                'aggregation' => required_param('aggregation', PARAM_ALPHA),
            ]);
            indicator_registry_service::set_main_company_metric($uuid, (int)$USER->id, $statuuid,
                (bool)optional_param('maincompanymetric', 0, PARAM_BOOL));
            redirect(new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid, 'stat'=>$statuuid]));
        } else if ($action === 'archivemetric') {
            $statuuid = required_param('statuuid', PARAM_RAW_TRIMMED);
            indicator_registry_service::set_metric_status($uuid, (int)$USER->id, $statuuid, 'archived');
            $statuuid = '';
            $metricstatus = 'active';
            $tab = 'all';
        } else if ($action === 'restoremetric') {
            $statuuid = required_param('statuuid', PARAM_RAW_TRIMMED);
            indicator_registry_service::set_metric_status($uuid, (int)$USER->id, $statuuid, 'active');
            $metricstatus = 'active';
            $tab = 'all';
        } else if ($action === 'passport') {
            $statuuid = required_param('statuuid', PARAM_RAW_TRIMMED);
            indicator_registry_service::save_passport($uuid, (int)$USER->id, $statuuid, [
                'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
                'sourcekind' => required_param('sourcekind', PARAM_ALPHA),
                'sourceconfig' => optional_param('sourceconfig', '', PARAM_RAW_TRIMMED),
                'aggregation' => required_param('aggregation', PARAM_ALPHA),
            ]);
            $tab = 'all';
        } else if ($action === 'binding') {
            $rawcontext = required_param('bindingcontext', PARAM_RAW_TRIMMED);
            $parts = explode(':', $rawcontext, 2);
            indicator_registry_service::save_binding($uuid, (int)$USER->id, [
                'statuuid' => required_param('statuuid', PARAM_RAW_TRIMMED),
                'contextkind' => $parts[0] ?? '',
                'contextuuid' => $parts[1] ?? '',
                'isprimary' => optional_param('isprimary', 0, PARAM_INT),
                'critical' => optional_param('critical', 0, PARAM_INT),
                'weight' => optional_param('weight', '1', PARAM_RAW_TRIMMED),
                'sortorder' => optional_param('sortorder', 0, PARAM_INT),
            ], optional_param('binduuid', '', PARAM_RAW_TRIMMED));
            $tab = 'tree';
        } else if ($action === 'archivebinding') {
            indicator_registry_service::archive_binding($uuid, (int)$USER->id,
                required_param('binduuid', PARAM_RAW_TRIMMED));
            $tab = 'tree';
        } else if ($action === 'norm') {
            indicator_registry_service::save_norm($uuid, (int)$USER->id, [
                'binduuid' => required_param('binduuid', PARAM_RAW_TRIMMED),
                'normkind' => required_param('normkind', PARAM_ALPHA),
                'targetvalue' => optional_param('targetvalue', '', PARAM_RAW_TRIMMED),
                'minval' => optional_param('minval', '', PARAM_RAW_TRIMMED),
                'maxval' => optional_param('maxval', '', PARAM_RAW_TRIMMED),
                'effectivefrom' => required_param('effectivefrom', PARAM_RAW_TRIMMED),
                'effectiveto' => optional_param('effectiveto', '', PARAM_RAW_TRIMMED),
            ], optional_param('normuuid', '', PARAM_RAW_TRIMMED));
            $tab = 'norms';
        } else if ($action === 'archivenorm') {
            indicator_registry_service::archive_norm($uuid, (int)$USER->id,
                required_param('normuuid', PARAM_RAW_TRIMMED));
            $tab = 'norms';
        } else if ($action === 'conditionpolicy') {
            indicator_registry_service::save_condition_policy($uuid, (int)$USER->id, [
                'binduuid' => required_param('binduuid', PARAM_RAW_TRIMMED),
                'dangerdecline' => optional_param('dangerdecline', '', PARAM_RAW_TRIMMED),
                'dangerperiods' => optional_param('dangerperiods', 0, PARAM_INT),
                'affluencegrowth' => required_param('affluencegrowth', PARAM_RAW_TRIMMED),
                'powerlevel' => optional_param('powerlevel', '', PARAM_RAW_TRIMMED),
                'powerperiods' => optional_param('powerperiods', 0, PARAM_INT),
                'effectivefrom' => required_param('effectivefrom', PARAM_RAW_TRIMMED),
                'effectiveto' => optional_param('effectiveto', '', PARAM_RAW_TRIMMED),
            ], optional_param('policyuuid', '', PARAM_RAW_TRIMMED));
            $tab = 'conditions';
        } else if ($action === 'archiveconditionpolicy') {
            indicator_registry_service::archive_condition_policy($uuid, (int)$USER->id,
                required_param('policyuuid', PARAM_RAW_TRIMMED));
            $tab = 'conditions';
        } else if ($action === 'conditionconfirm') {
            indicator_condition_execution_service::confirm($uuid, (int)$USER->id,
                required_param('binduuid', PARAM_RAW_TRIMMED), required_param('condition', PARAM_ALPHA),
                optional_param('comment', '', PARAM_RAW_TRIMMED));
            $statuuid = optional_param('statuuid', '', PARAM_RAW_TRIMMED);
            $tab = 'conditions';
        } else if ($action === 'conditionexecute') {
            $result = indicator_condition_execution_service::confirm_to_execution($uuid, (int)$USER->id,
                required_param('binduuid', PARAM_RAW_TRIMMED), required_param('condition', PARAM_ALPHA),
                optional_param('comment', '', PARAM_RAW_TRIMMED), [
                    'title' => required_param('title', PARAM_RAW_TRIMMED),
                    'description' => optional_param('description', '', PARAM_RAW_TRIMMED),
                    'product' => required_param('product', PARAM_RAW_TRIMMED),
                    'stream' => optional_param('stream', 'change', PARAM_ALPHA),
                    'priority' => optional_param('priority', 'normal', PARAM_ALPHA),
                    'assigneeid' => required_param('assigneeid', PARAM_INT),
                    'reviewerid' => optional_param('reviewerid', 0, PARAM_INT),
                    'observerid' => optional_param('observerid', 0, PARAM_INT),
                    'startdate' => optional_param('startdate', '', PARAM_RAW_TRIMMED),
                    'duedate' => optional_param('duedate', '', PARAM_RAW_TRIMMED),
                    'planminutes' => optional_param('planminutes', 0, PARAM_INT),
                    'parentuuid' => '', 'contextkind' => '', 'contextuuid' => '', 'contextrevision' => 0,
                ], optional_param('planuuid', '', PARAM_RAW_TRIMMED));
            $statuuid = optional_param('statuuid', '', PARAM_RAW_TRIMMED);
            $tab = 'conditions';
        } else if ($action === 'value') {
            $statuuid = required_param('statuuid', PARAM_RAW_TRIMMED);
            product_service::record_value($uuid, (int)$USER->id, $statuuid,
                required_param('periodstart', PARAM_RAW_TRIMMED), required_param('kind', PARAM_ALPHA),
                required_param('amount', PARAM_RAW_TRIMMED), optional_param('reason', '', PARAM_RAW_TRIMMED));
            $tab = 'input';
        } else {
            throw new moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        redirect(new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid, 'tab' => $tab,
            'treeview' => $treeview, 'stat' => $statuuid, 'horizon' => $horizon]), get_string('indicator_saved', 'local_kbacompany'));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
        $data = indicator_registry_service::context($uuid, (int)$USER->id, $statuuid);
    }
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('indicator_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'products',
    get_string('indicator_title', 'local_kbacompany'));
echo \local_kbacompany\local\bms_workspace_ux_service::management_nav($uuid, $tab === 'exceptions' ? 'exceptions' : 'indicators');

$hidden = static function(string $action) use ($uuid): string {
    return html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
};
$btn = static function(string $text, string $class = 'btn btn-primary'): string {
    return html_writer::tag('button', $text, ['type' => 'submit', 'class' => $class]);
};
$metricmap = [];
foreach ($data['metrics'] as $metric) { $metricmap[$metric['uuid']] = $metric; }
$bindingmap = [];
foreach ($data['bindings'] as $binding) { $bindingmap[$binding['uuid']] = $binding; }
$normbybind = [];
foreach ($data['norms'] as $norm) {
    if (!isset($normbybind[$norm['binduuid']]) || (int)$norm['revision'] > (int)$normbybind[$norm['binduuid']]['revision']) {
        $normbybind[$norm['binduuid']] = $norm;
    }
}
$policybybind = [];
foreach ($data['condition_policies'] as $policy) {
    if (!isset($policybybind[$policy['binduuid']]) ||
            (int)$policy['revision'] > (int)$policybybind[$policy['binduuid']]['revision']) {
        $policybybind[$policy['binduuid']] = $policy;
    }
}

$sourceoptions = [
    'manual' => get_string('indicator_source_manual', 'local_kbacompany'),
    'formula' => get_string('indicator_source_formula', 'local_kbacompany'),
    'integration' => get_string('indicator_source_integration', 'local_kbacompany'),
    'checklist' => get_string('indicator_source_checklist', 'local_kbacompany'),
];
$aggregationoptions = [
    'direct' => get_string('indicator_agg_direct', 'local_kbacompany'),
    'sum' => get_string('indicator_agg_sum', 'local_kbacompany'),
    'average' => get_string('indicator_agg_average', 'local_kbacompany'),
    'ratio' => get_string('indicator_agg_ratio', 'local_kbacompany'),
    'last' => get_string('indicator_agg_last', 'local_kbacompany'),
    'formula' => get_string('indicator_agg_formula', 'local_kbacompany'),
];
$periodoptions = [
    'daily' => get_string('statdaily', 'local_kbacompany'),
    'weekly' => get_string('statweekly', 'local_kbacompany'),
    'monthly' => get_string('statmonthly', 'local_kbacompany'),
    'quarterly' => get_string('statquarterly', 'local_kbacompany'),
    'annual' => get_string('statannual', 'local_kbacompany'),
];
$directionoptions = [
    'up' => get_string('statup', 'local_kbacompany'),
    'down' => get_string('statdown', 'local_kbacompany'),
    'neutral' => get_string('statneutral', 'local_kbacompany'),
];

$contextoptions = ['company:' . $uuid => get_string('indicator_context_company', 'local_kbacompany')];
foreach ($data['goals'] as $goal) {
    $contextoptions['strategygoal:' . $goal->uuid] = get_string('indicator_context_goal', 'local_kbacompany') .
        ': ' . $goal->code . ' — ' . $goal->name;
}
foreach ($data['nodes'] as $node) {
    $contextoptions['orgnode:' . $node->uuid] = get_string('indicator_context_orgnode', 'local_kbacompany') .
        ': ' . $node->code . ' — ' . $node->name;
}
foreach ($data['positions'] as $position) {
    $contextoptions['position:' . $position->uuid] = get_string('indicator_context_position', 'local_kbacompany') .
        ': ' . $position->code . ' — ' . $position->name;
}

$contextlabel = static function(array $binding) use ($data): string {
    if ($binding['contextkind'] === 'company') { return get_string('indicator_context_company', 'local_kbacompany'); }
    if ($binding['contextkind'] === 'strategygoal') {
        foreach ($data['goals'] as $row) { if ((string)$row->uuid === $binding['contextuuid']) { return $row->code . ' — ' . $row->name; } }
    }
    if ($binding['contextkind'] === 'orgnode') {
        foreach ($data['nodes'] as $row) { if ((string)$row->uuid === $binding['contextuuid']) { return $row->code . ' — ' . $row->name; } }
    }
    if ($binding['contextkind'] === 'position') {
        foreach ($data['positions'] as $row) { if ((string)$row->uuid === $binding['contextuuid']) { return $row->code . ' — ' . $row->name; } }
    }
    return get_string('indicator_unknown_context', 'local_kbacompany');
};

$metriclinks = static function(string $kind, string $contextuuid) use ($data, $metricmap): string {
    $items = [];
    foreach ($data['bindings'] as $binding) {
        if ($binding['contextkind'] !== $kind || $binding['contextuuid'] !== $contextuuid) { continue; }
        $metric = $metricmap[$binding['statuuid']] ?? null;
        if (!$metric) { continue; }
        $label = ($binding['isprimary'] ? '★ ' : '') . $metric['name'];
        $items[] = html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
            ['uuid' => $data['company']['uuid'], 'tab' => 'input', 'stat' => $metric['uuid']]), s($label));
    }
    return $items ? html_writer::alist($items, ['class' => 'kba-indicator-tree-metrics']) : '';
};

$seriescache = [];
$getseries = static function(string $metricuuid, int $limit = 52) use (&$seriescache, $uuid): array {
    $key = $metricuuid . ':' . $limit;
    if (!array_key_exists($key, $seriescache)) {
        $seriescache[$key] = indicator_registry_service::series_for_metric($uuid, $metricuuid, $limit);
    }
    return $seriescache[$key];
};

$normfor = static function(string $binduuid, string $period) use ($data): ?array {
    $best = null;
    foreach ($data['norms'] as $norm) {
        if ((string)$norm['binduuid'] !== $binduuid) { continue; }
        if ((string)$norm['effectivefrom'] > $period) { continue; }
        if ((string)$norm['effectiveto'] !== '' && (string)$norm['effectiveto'] < $period) { continue; }
        if ($best === null || (string)$norm['effectivefrom'] > (string)$best['effectivefrom']) { $best = $norm; }
    }
    return $best;
};

$normlabel = static function(?array $norm): string {
    if (!$norm) { return '—'; }
    if ((string)$norm['normkind'] === 'range') { return (string)$norm['minval'] . ' — ' . (string)$norm['maxval']; }
    return (string)$norm['targetvalue'];
};

$controlsnapshot = static function(array $metric, array $binding, array $series) use ($normfor, $normlabel, $data): array {
    $facts = [];
    foreach ($series as $row) {
        if ($row['fact'] !== null) { $facts[] = ['period' => $row['period'], 'value' => (float)$row['fact']]; }
    }
    $latest = $facts ? $facts[count($facts) - 1] : null;
    $previous = count($facts) > 1 ? $facts[count($facts) - 2] : null;
    $period = $latest ? (string)$latest['period'] : date('Y-m-d');
    $norm = $normfor((string)$binding['uuid'], $period);
    $trend = null;
    if ($latest && $previous && (float)$previous['value'] != 0.0) {
        $trend = (((float)$latest['value'] - (float)$previous['value']) / abs((float)$previous['value'])) * 100;
    }
    $analysis = indicator_trend_service::analyze($series, (string)$metric['direction']);
    $periodplan = null;
    if ($latest) {
        foreach ($series as $periodrow) {
            if ((string)($periodrow['period'] ?? '') === (string)$latest['period'] &&
                    array_key_exists('plan', $periodrow) && $periodrow['plan'] !== null) {
                $periodplan = (float)$periodrow['plan'];
                break;
            }
        }
    }
    $performance = indicator_performance_status_service::assess(
        $latest ? (float)$latest['value'] : null,
        $periodplan,
        $norm,
        (string)$metric['direction'],
        !empty($binding['critical']),
        (string)($analysis['quality'] ?? 'insufficient')
    );
    return [
        'fact' => $latest ? $latest['value'] : null,
        'plan' => $periodplan,
        'period' => $latest ? $latest['period'] : '',
        'previous' => $previous ? $previous['value'] : null,
        'trend' => $trend,
        'analysis' => $analysis,
        'events' => indicator_event_service::latest($series, (string)$metric['direction'], $data['normhistory'], (string)$binding['uuid']),
        'condition' => indicator_condition_service::analyze($series, (string)$metric['direction'],
            $data['normhistory'], $data['condition_policy_history'], (string)$binding['uuid']),
        'norm' => $norm,
        'normlabel' => $normlabel($norm),
        'performance' => $performance,
        'status' => (string)$performance['status'],
    ];
};

$trendmovementlabel = static function(string $movement): string {
    $map = [
        'sustained_up' => 'indicator_trend_sustained_up',
        'sustained_down' => 'indicator_trend_sustained_down',
        'unchanged' => 'indicator_trend_unchanged',
        'mixed' => 'indicator_trend_mixed',
        'insufficient' => 'indicator_trend_insufficient',
    ];
    return get_string($map[$movement] ?? 'indicator_trend_insufficient', 'local_kbacompany');
};
$trendqualitylabel = static function(string $quality): string {
    $map = [
        'improving' => 'indicator_trend_improving',
        'worsening' => 'indicator_trend_worsening',
        'stable' => 'indicator_trend_stable',
        'neutral' => 'indicator_trend_neutral',
        'insufficient' => 'indicator_trend_insufficient',
    ];
    return get_string($map[$quality] ?? 'indicator_trend_insufficient', 'local_kbacompany');
};
$eventlabel = static function(string $event): string {
    $map = [
        'reversal_up' => 'indicator_trend_reversal_up',
        'reversal_down' => 'indicator_trend_reversal_down',
        'new_max' => 'indicator_event_new_max',
        'new_min' => 'indicator_event_new_min',
        'out_of_norm' => 'indicator_event_out_of_norm',
        'back_in_norm' => 'indicator_event_back_in_norm',
    ];
    return isset($map[$event]) ? get_string($map[$event], 'local_kbacompany') : $event;
};
$eventlabels = static function(array $events) use ($eventlabel): string {
    if (!$events) { return get_string('indicator_trend_noevent', 'local_kbacompany'); }
    $labels = [];
    foreach ($events as $event) { $labels[] = $eventlabel((string)($event['type'] ?? '')); }
    return implode(', ', $labels);
};
$matchestrendfilter = static function(array $analysis, string $filter): bool {
    if ($filter === 'all') { return true; }
    if ($filter === 'rising') { return (string)$analysis['movement'] === 'sustained_up'; }
    if ($filter === 'falling') { return (string)$analysis['movement'] === 'sustained_down'; }
    if ($filter === 'worsening') { return (string)$analysis['quality'] === 'worsening'; }
    if ($filter === 'insufficient') { return (string)$analysis['movement'] === 'insufficient'; }
    return true;
};
$trendfilterhtml = static function(string $tabname) use ($uuid, $trendfilter): string {
    $items = [
        'all' => 'indicator_filter_all',
        'rising' => 'indicator_filter_rising',
        'falling' => 'indicator_filter_falling',
        'worsening' => 'indicator_filter_worsening',
        'insufficient' => 'indicator_filter_insufficient',
    ];
    $html = '';
    foreach ($items as $key => $langkey) {
        $html .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php', [
            'uuid'=>$uuid, 'tab'=>$tabname, 'trendfilter'=>$key
        ]), get_string($langkey, 'local_kbacompany'), [
            'class'=>'btn '.($trendfilter === $key ? 'btn-success' : 'btn-outline-secondary').' btn-sm mr-1 mb-2'
        ]);
    }
    return html_writer::div($html, 'kba-indicator-trend-filters mb-3');
};

$bestbindingformetric = static function(string $metricuuid) use ($data): ?array {
    $candidates = [];
    foreach ($data['bindings'] as $binding) {
        if ((string)$binding['statuuid'] === $metricuuid) { $candidates[] = $binding; }
    }
    if (!$candidates) { return null; }
    usort($candidates, static function(array $a, array $b): int {
        $cmp = (int)!empty($a['compatibility']) <=> (int)!empty($b['compatibility']);
        if ($cmp !== 0) { return $cmp; }
        $cmp = (int)$b['isprimary'] <=> (int)$a['isprimary'];
        if ($cmp !== 0) { return $cmp; }
        $cmp = (int)$b['critical'] <=> (int)$a['critical'];
        if ($cmp !== 0) { return $cmp; }
        $cmp = (float)$b['weight'] <=> (float)$a['weight'];
        if ($cmp !== 0) { return $cmp; }
        return (int)$a['sortorder'] <=> (int)$b['sortorder'];
    });
    return $candidates[0];
};

$conditionlabel = static function(string $condition): string {
    $map = [
        'nonexistence' => 'indicator_condition_nonexistence',
        'danger' => 'indicator_condition_danger',
        'emergency' => 'indicator_condition_emergency',
        'normal' => 'indicator_condition_normal',
        'affluence' => 'indicator_condition_affluence',
        'power' => 'indicator_condition_power',
        'powerchange' => 'indicator_condition_powerchange',
    ];
    return get_string($map[$condition] ?? 'indicator_condition_undetermined', 'local_kbacompany');
};
$conditionreasonlabel = static function(string $reason): string {
    $map = [
        'no_data'=>'indicator_condition_reason_no_data',
        'binding_required'=>'indicator_condition_reason_binding_required',
        'direction_not_supported'=>'indicator_condition_reason_direction_not_supported',
        'policy_missing'=>'indicator_condition_reason_policy_missing',
        'insufficient'=>'indicator_condition_reason_insufficient',
        'steepness_policy_required'=>'indicator_condition_reason_steepness_policy_required',
        'steep_decline'=>'indicator_condition_reason_steep_decline',
        'prolonged_emergency'=>'indicator_condition_reason_prolonged_emergency',
        'decline'=>'indicator_condition_reason_decline',
        'flat'=>'indicator_condition_reason_flat',
        'high_level_held'=>'indicator_condition_reason_high_level_held',
        'steep_growth'=>'indicator_condition_reason_steep_growth',
        'gradual_growth'=>'indicator_condition_reason_gradual_growth',
        'insufficient_normal_trend'=>'indicator_condition_reason_insufficient_normal_trend',
    ];
    return get_string($map[$reason] ?? 'indicator_condition_reason_insufficient', 'local_kbacompany');
};
$conditionactions = static function(string $condition): string {
    $keys = indicator_condition_service::action_keys($condition);
    if (!$keys) { return ''; }
    $items = [];
    foreach ($keys as $key) { $items[] = s(get_string($key, 'local_kbacompany')); }
    return html_writer::alist($items);
};

$sparkline = static function(array $series): string {
    $points = [];
    foreach ($series as $row) { if ($row['fact'] !== null) { $points[] = (float)$row['fact']; } }
    if (count($points) < 2) { return ''; }
    if (count($points) > 13) { $points = array_slice($points, -13); }
    $min = min($points); $max = max($points); if ($max == $min) { $max = $min + 1; }
    $w = 150; $h = 38; $pad = 3; $coords = [];
    $count = count($points);
    foreach ($points as $i => $value) {
        $x = $pad + $i * (($w - 2 * $pad) / max(1, $count - 1));
        $y = $h - $pad - (($value - $min) / ($max - $min)) * ($h - 2 * $pad);
        $coords[] = round($x, 1) . ',' . round($y, 1);
    }
    return '<svg class="kba-indicator-spark" viewBox="0 0 '.$w.' '.$h.'" aria-hidden="true"><polyline points="'.
        implode(' ', $coords).'" class="kba-indicator-spark-line" /></svg>';
};

$svgchart = static function(array $series): string {
    $rows = [];
    $allvalues = [];
    foreach ($series as $row) {
        if ($row['fact'] === null && $row['plan'] === null) { continue; }
        $rows[] = $row;
        if ($row['fact'] !== null) { $allvalues[] = (float)$row['fact']; }
        if ($row['plan'] !== null) { $allvalues[] = (float)$row['plan']; }
    }
    if (!$rows || !$allvalues) { return ''; }
    $min = min($allvalues); $max = max($allvalues); if ($max == $min) { $max = $min + 1; }
    $w = 760; $h = 220; $pad = 28; $count = count($rows);
    $build = static function(string $kind) use ($rows, $min, $max, $w, $h, $pad, $count): array {
        $coords = [];
        foreach ($rows as $i => $row) {
            if ($row[$kind] === null) { continue; }
            $x = $pad + ($count === 1 ? ($w - 2 * $pad) / 2 : $i * (($w - 2 * $pad) / ($count - 1)));
            $y = $h - $pad - (((float)$row[$kind] - $min) / ($max - $min)) * ($h - 2 * $pad);
            $coords[] = ['point' => round($x, 1).','.round($y, 1), 'x' => round($x, 1), 'y' => round($y, 1),
                'label' => $row['period'], 'value' => $row[$kind]];
        }
        return $coords;
    };
    $facts = $build('fact'); $plans = $build('plan');
    $svg = '<svg class="kba-indicator-chart" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="'.
        s(get_string('indicator_chart', 'local_kbacompany')).'">';
    $svg .= '<line x1="'.$pad.'" y1="'.($h-$pad).'" x2="'.($w-$pad).'" y2="'.($h-$pad).'" class="kba-indicator-axis" />';
    if ($plans) { $svg .= '<polyline points="'.implode(' ', array_column($plans, 'point')).'" class="kba-indicator-plan-line" />'; }
    if ($facts) { $svg .= '<polyline points="'.implode(' ', array_column($facts, 'point')).'" class="kba-indicator-fact-line" />'; }
    foreach ($plans as $point) {
        $svg .= '<circle cx="'.$point['x'].'" cy="'.$point['y'].'" r="5" class="kba-indicator-dot"><title>'.
            s(get_string('statplan', 'local_kbacompany').' — '.$point['label'].' — '.$point['value']).'</title></circle>';
    }
    foreach ($facts as $point) {
        $svg .= '<circle cx="'.$point['x'].'" cy="'.$point['y'].'" r="4" class="kba-indicator-dot"><title>'.
            s($point['label'].' — '.$point['value']).'</title></circle>';
    }
    $svg .= '</svg>';
    $legend = html_writer::span(get_string('statfact', 'local_kbacompany'), 'kba-indicator-legend-fact') .
        html_writer::span(get_string('statplan', 'local_kbacompany'), 'kba-indicator-legend-plan');
    return html_writer::div($legend, 'kba-indicator-legend') . $svg;
};

// Header.
echo $OUTPUT->heading(get_string('indicator_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('indicator_intro', 'local_kbacompany'), ['class' => 'lead']);
if ($data['can_manage'] && $tab !== 'all' && $tab !== 'edit') {
    $crudactions = html_writer::link(new moodle_url('/local/kbacompany/indicator_record.php', [
        'uuid'=>$uuid, 'mode'=>'create'
    ]), '+ ' . get_string('indicator_create_metric', 'local_kbacompany'), ['class'=>'btn btn-success mr-2 mb-2']);
    $crudactions .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php', [
        'uuid'=>$uuid, 'tab'=>'all', 'metricstatus'=>'active'
    ]), get_string('indicator_manage_metrics', 'local_kbacompany'), ['class'=>'btn btn-outline-success mb-2']);
    echo html_writer::div($crudactions, 'bms-metric-global-actions mb-2');
}
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }

$tabs = [
    'center' => get_string('indicator_tab_center', 'local_kbacompany'),
    'exceptions' => get_string('indicator_tab_exceptions', 'local_kbacompany'),
    'trends' => get_string('indicator_tab_trends', 'local_kbacompany'),
    'conditions' => get_string('indicator_tab_conditions', 'local_kbacompany'),
    'tree' => get_string('indicator_tab_tree', 'local_kbacompany'),
    'all' => get_string('indicator_tab_all', 'local_kbacompany'),
    'input' => get_string('indicator_tab_input', 'local_kbacompany'),
    'norms' => get_string('indicator_tab_norms', 'local_kbacompany'),
];
$tabhtml = '';
foreach ($tabs as $key => $label) {
    // Keep selected metric when navigating related CRUD sections.
    $tabparams = ['uuid'=>$uuid, 'tab'=>$key];
    if ($statuuid !== '' && in_array($key, ['input', 'norms', 'tree'], true)) {
        $tabparams['stat'] = $statuuid;
    }
    $tabhtml .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php', $tabparams),
        $label, ['class' => 'btn ' . ($tab === $key ? 'btn-success' : 'btn-outline-secondary') . ' mr-1 mb-2']);
}
echo html_writer::div($tabhtml, 'mb-3');

// BMS CRUD-001: canonical record is the navigation hub for related operations.
if ($statuuid !== '' && in_array($tab, ['input', 'norms', 'tree'], true) && !empty($data['selected'])) {
    $recordurl = new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid, 'stat'=>$statuuid]);
    echo html_writer::div(html_writer::link($recordurl, '← '.get_string('indicator_crud_back_to_record', 'local_kbacompany'),
        ['class'=>'btn btn-outline-secondary mb-2']), 'mb-2');
}


if ($tab === 'center') {
    echo html_writer::tag('p', get_string('indicator_center_intro', 'local_kbacompany'), ['class' => 'text-muted']);

    $statuslabels = [
        'nodata' => get_string('metric_status_nodata', 'local_kbacompany'),
        'ok' => get_string('metric_status_ok', 'local_kbacompany'),
        'risk' => get_string('metric_status_risk', 'local_kbacompany'),
        'critical' => get_string('metric_status_critical', 'local_kbacompany'),
    ];
    $statusclasses = ['nodata'=>'muted','ok'=>'ok','risk'=>'risk','critical'=>'critical'];

    $bindingpriority = static function(array $a, array $b): int {
        $compatcmp = (int)empty($a['compatibility']) <=> (int)empty($b['compatibility']);
        if ($compatcmp !== 0) { return -$compatcmp; }
        foreach (['isprimary', 'critical'] as $field) {
            $cmp = (int)$b[$field] <=> (int)$a[$field];
            if ($cmp !== 0) { return $cmp; }
        }
        $cmp = (float)$b['weight'] <=> (float)$a['weight'];
        if ($cmp !== 0) { return $cmp; }
        return (int)$a['sortorder'] <=> (int)$b['sortorder'];
    };
    $buildrows = static function(array $bindings, int $limit) use ($bindingpriority, $metricmap, $getseries, $controlsnapshot): array {
        usort($bindings, $bindingpriority);
        $seen = []; $rows = [];
        foreach ($bindings as $binding) {
            $mid = (string)$binding['statuuid'];
            if (isset($seen[$mid]) || !isset($metricmap[$mid])) { continue; }
            $seen[$mid] = true;
            $metric = $metricmap[$mid];
            $series = $getseries($mid, 52);
            $rows[] = ['metric'=>$metric, 'binding'=>$binding, 'series'=>$series,
                'snapshot'=>$controlsnapshot($metric, $binding, $series)];
            if (count($rows) >= $limit) { break; }
        }
        return $rows;
    };
    $renderrows = static function(array $rows) use ($uuid, $sparkline, $statuslabels, $statusclasses, $trendqualitylabel, $trendmovementlabel, $conditionlabel): string {
        if (!$rows) { return html_writer::div(get_string('indicator_center_no_metrics', 'local_kbacompany'), 'text-muted'); }
        $html = '';
        foreach ($rows as $row) {
            $m = $row['metric']; $snap = $row['snapshot'];
            $fact = $snap['fact'] === null ? '—' : format_float((float)$snap['fact'], 2) . ' ' . s($m['unit']);
            $trend = '';
            if ($snap['trend'] !== null) {
                $trend = ($snap['trend'] > 0 ? '+' : '') . format_float((float)$snap['trend'], 1) . '%';
            }
            $title = html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
                ['uuid'=>$uuid,'tab'=>'input','stat'=>$m['uuid'],'horizon'=>13]), s($m['name']));
            $targetlabel = $snap['plan'] !== null ?
                get_string('metric_period_plan', 'local_kbacompany') . ': ' . format_float((float)$snap['plan'], 2) . ' ' . s($m['unit']) :
                get_string('indicator_norm', 'local_kbacompany') . ': ' . s($snap['normlabel']);
            $meta = html_writer::span(get_string('indicator_center_fact', 'local_kbacompany') . ': ' . $fact, 'kba-center-fact') .
                html_writer::span($targetlabel, 'kba-center-norm');
            if ($trend !== '') { $meta .= html_writer::span(get_string('indicator_trend', 'local_kbacompany') . ': ' . s($trend), 'kba-center-trend'); }
            $analysis = $snap['analysis'] ?? [];
            if (!empty($analysis) && ($analysis['quality'] ?? 'insufficient') !== 'insufficient') {
                $meta .= html_writer::span($trendmovementlabel((string)$analysis['movement']), 'kba-center-trend-shape');
                $meta .= html_writer::span($trendqualitylabel((string)$analysis['quality']),
                    'kba-center-trend-quality kba-trend-quality--'.s((string)$analysis['quality']));
            }
            $condition = $snap['condition'] ?? [];
            $conditionname = $conditionlabel((string)($condition['condition'] ?? ''));
            $meta .= html_writer::span(get_string('indicator_condition', 'local_kbacompany') . ': ' . s($conditionname),
                'kba-center-condition');
            $status = html_writer::span($statuslabels[$snap['status']], 'kba-center-status kba-center-status--'.$statusclasses[$snap['status']]);
            $html .= html_writer::div(
                html_writer::div($title . $status, 'kba-center-metric-head') .
                html_writer::div($sparkline($row['series']), 'kba-center-spark-wrap') .
                html_writer::div($meta, 'kba-center-metric-meta'), 'kba-center-metric');
        }
        return $html;
    };

    $nodebyuuid = []; $children = []; $positionsbynode = [];
    foreach ($data['nodes'] as $node) { $nodebyuuid[(string)$node->uuid] = $node; }
    foreach ($data['nodes'] as $node) {
        $parent = (string)$node->parentuuid;
        if ($parent !== '' && !isset($nodebyuuid[$parent])) { $parent = ''; }
        $children[$parent][] = (string)$node->uuid;
    }
    foreach ($data['positions'] as $position) { $positionsbynode[(string)$position->orgnodeuuid][] = (string)$position->uuid; }
    $descendants = static function(string $root) use (&$descendants, $children): array {
        $out = [$root];
        foreach ($children[$root] ?? [] as $child) { $out = array_merge($out, $descendants($child)); }
        return $out;
    };

    $companybindings = array_values(array_filter($data['bindings'], static function($b) use ($uuid): bool {
        return empty($b['compatibility']) && $b['contextkind'] === 'company' &&
            (string)$b['contextuuid'] === $uuid && !empty($b['isprimary']);
    }));
    $mainrows = $buildrows($companybindings, 50);

    $statuscounts = ['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
    foreach ($mainrows as $row) {
        $key = (string)($row['snapshot']['status'] ?? 'nodata');
        if (!isset($statuscounts[$key])) { $key = 'nodata'; }
        $statuscounts[$key]++;
    }
    $summary = '';
    foreach ([
        get_string('indicator_center_total', 'local_kbacompany') => count($mainrows),
        get_string('metric_status_ok', 'local_kbacompany') => $statuscounts['ok'],
        get_string('metric_status_risk', 'local_kbacompany') => $statuscounts['risk'],
        get_string('metric_status_critical', 'local_kbacompany') => $statuscounts['critical'],
        get_string('metric_status_nodata', 'local_kbacompany') => $statuscounts['nodata'],
    ] as $label => $value) {
        $summary .= html_writer::div(html_writer::tag('strong', (string)$value) . html_writer::tag('small', $label), 'kba-indicator-kpi');
    }
    echo html_writer::div($summary, 'kba-indicator-kpis kba-indicator-kpis--five');
    echo html_writer::div(get_string('indicator_center_status_hint', 'local_kbacompany'), 'alert alert-info');

    if ($mainrows) {
        echo $OUTPUT->heading(get_string('indicator_center_main_company', 'local_kbacompany'), 3);
        echo html_writer::start_div('kba-info-center-grid');
        echo html_writer::div(
            html_writer::tag('h4', s(format_string($data['company']['name'])), ['class'=>'kba-center-card-title']) .
            $renderrows($mainrows), 'kba-center-card');
        echo html_writer::end_div();
    } else {
        echo $OUTPUT->notification(get_string('indicator_center_no_main_company', 'local_kbacompany'), 'info');
    }

}


if ($tab === 'exceptions') {
    $exceptions = indicator_management_exception_service::context($uuid, (int)$USER->id,
        $exceptionseverity, $exceptiongroup, $exceptioncycle);
    echo html_writer::tag('p', get_string('indicator_exceptions_intro', 'local_kbacompany'), ['class'=>'text-muted']);
    echo html_writer::div(get_string('indicator_exceptions_rule', 'local_kbacompany'), 'alert alert-info');
    echo html_writer::div(get_string('indicator_management_cycle_rule', 'local_kbacompany'), 'alert alert-light border');

    $summaryhtml = '';
    foreach ([
        'total' => 'indicator_exceptions_total',
        'needs_decision' => 'indicator_management_cycle_needs_decision_count',
        'overdue' => 'indicator_exceptions_overdue',
        'in_progress' => 'indicator_management_cycle_in_progress_count',
        'followup_worsened' => 'indicator_management_cycle_worsened_count',
        'followup_improved' => 'indicator_management_cycle_improved_count',
    ] as $key => $langkey) {
        $summaryhtml .= html_writer::div(
            html_writer::tag('strong', (string)$exceptions['summary'][$key]) .
            html_writer::tag('small', get_string($langkey, 'local_kbacompany')),
            'kba-indicator-kpi');
    }
    echo html_writer::div($summaryhtml, 'kba-indicator-kpis mb-3');

    $severityoptions = [
        'all' => get_string('indicator_exception_filter_all', 'local_kbacompany'),
        'critical' => get_string('indicator_exception_severity_critical', 'local_kbacompany'),
        'high' => get_string('indicator_exception_severity_high', 'local_kbacompany'),
        'medium' => get_string('indicator_exception_severity_medium', 'local_kbacompany'),
    ];
    $groupoptions = ['all' => get_string('indicator_exception_filter_all', 'local_kbacompany')];
    foreach (indicator_management_exception_service::GROUPS as $groupcode) {
        $groupoptions[$groupcode] = get_string('indicator_exception_group_' . $groupcode, 'local_kbacompany');
    }
    $cycleoptions = ['all' => get_string('indicator_exception_filter_all', 'local_kbacompany')];
    foreach (indicator_management_cycle_service::FILTERS as $cyclecode) {
        $cycleoptions[$cyclecode] = get_string('indicator_management_cycle_' . $cyclecode, 'local_kbacompany');
    }
    echo html_writer::start_tag('form', ['method'=>'get','class'=>'form-inline mb-3']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'tab','value'=>'exceptions']);
    echo html_writer::label(get_string('indicator_exception_filter_severity', 'local_kbacompany'), 'exception_severity', false,
        ['class'=>'mr-2']);
    echo html_writer::select($severityoptions, 'exceptionseverity', $exceptionseverity, false,
        ['id'=>'exception_severity','class'=>'form-control mr-3']);
    echo html_writer::label(get_string('indicator_exception_filter_group', 'local_kbacompany'), 'exception_group', false,
        ['class'=>'mr-2']);
    echo html_writer::select($groupoptions, 'exceptiongroup', $exceptiongroup, false,
        ['id'=>'exception_group','class'=>'form-control mr-3']);
    echo html_writer::label(get_string('indicator_management_cycle_filter', 'local_kbacompany'), 'exception_cycle', false,
        ['class'=>'mr-2']);
    echo html_writer::select($cycleoptions, 'exceptioncycle', $exceptioncycle, false,
        ['id'=>'exception_cycle','class'=>'form-control mr-3']);
    echo html_writer::tag('button', get_string('indicator_exception_apply_filter', 'local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');

    $reasonlabel = static function(string $code): string {
        $key = 'indicator_exception_reason_' . $code;
        return get_string_manager()->string_exists($key, 'local_kbacompany') ?
            get_string($key, 'local_kbacompany') : $code;
    };
    $severitylabel = static function(string $severity): string {
        return get_string('indicator_exception_severity_' . $severity, 'local_kbacompany');
    };
    $severityclass = static function(string $severity): string {
        if ($severity === 'critical') { return 'badge badge-danger'; }
        if ($severity === 'high') { return 'badge badge-warning'; }
        return 'badge badge-secondary';
    };
    $cyclelabel = static function(string $status): string {
        $key = 'indicator_management_cycle_' . $status;
        return get_string_manager()->string_exists($key, 'local_kbacompany') ?
            get_string($key, 'local_kbacompany') : $status;
    };
    $taskstatuslabel = static function(string $status): string {
        $key = 'taskcenter_status_' . $status;
        return get_string_manager()->string_exists($key, 'local_kbacompany') ?
            get_string($key, 'local_kbacompany') : $status;
    };

    if ($exceptions['rows']) {
        $table = new html_table();
        $table->head = [
            get_string('indicator_exception_severity', 'local_kbacompany'),
            get_string('indicator_metric', 'local_kbacompany'),
            get_string('indicator_binding_context', 'local_kbacompany'),
            get_string('indicator_exception_reasons', 'local_kbacompany'),
            get_string('indicator_condition', 'local_kbacompany'),
            get_string('indicator_trend', 'local_kbacompany'),
            get_string('indicator_exception_fact_norm', 'local_kbacompany'),
            get_string('indicator_management_cycle', 'local_kbacompany'),
            get_string('indicator_actions', 'local_kbacompany'),
        ];
        foreach ($exceptions['rows'] as $row) {
            $metric = $row['metric'];
            $severitycell = html_writer::span(s($severitylabel((string)$row['severity'])),
                $severityclass((string)$row['severity']));
            $metriccell = html_writer::link(new moodle_url('/local/kbacompany/indicators.php', [
                'uuid'=>$uuid, 'tab'=>'input', 'stat'=>$metric['uuid'], 'horizon'=>13
            ]), s($metric['name']));
            if (!empty($row['binding']['critical'])) {
                $metriccell = html_writer::span('★ ', 'text-danger') . $metriccell;
            }
            $reasonitems = [];
            foreach ($row['reasons'] as $reason) {
                $reasonitems[] = html_writer::span(s($reasonlabel((string)$reason['code'])),
                    $severityclass((string)$reason['severity']) . ' mr-1 mb-1');
            }
            $conditioncell = $row['effective_condition'] !== '' ?
                s($conditionlabel((string)$row['effective_condition'])) : '—';
            if ($row['decision_uuid'] !== '') {
                $conditioncell .= html_writer::tag('small', ' · ' . get_string('indicator_exception_confirmed', 'local_kbacompany'),
                    ['class'=>'text-muted']);
            }
            $trendcell = s($trendmovementlabel((string)$row['trend']['movement']));
            if ((string)$row['trend']['quality'] !== 'neutral' && (string)$row['trend']['quality'] !== 'insufficient') {
                $trendcell .= html_writer::tag('small', ' · ' . s($trendqualitylabel((string)$row['trend']['quality'])),
                    ['class'=>'text-muted']);
            }
            $factlabel = $row['latest_fact'] ? format_float((float)$row['latest_fact']['value'], 2) . ' ' . (string)$metric['unit'] : '—';
            $normtext = $normlabel($row['norm']);
            $factnormcell = s($factlabel) . html_writer::tag('small', ' · ' . get_string('indicator_norm', 'local_kbacompany') . ': ' . s($normtext),
                ['class'=>'text-muted']);

            $cycle = $row['management_cycle'];
            $cyclestatus = (string)$cycle['status'];
            $cycleclass = in_array($cyclestatus, ['overdue', 'worsened', 'needs_decision'], true) ? 'badge badge-danger' :
                (in_array($cyclestatus, ['needs_execution', 'unchanged', 'awaiting_followup'], true) ? 'badge badge-warning' : 'badge badge-success');
            $cyclecell = html_writer::span(s($cyclelabel($cyclestatus)), $cycleclass);
            if (!empty($cycle['requires_new_decision'])) {
                $cyclecell .= html_writer::tag('small', get_string('indicator_management_cycle_new_decision_required', 'local_kbacompany'),
                    ['class'=>'d-block text-danger mt-1']);
            }
            $cycletask = $cycle['task'];
            if (!empty($cycletask['visible'])) {
                $taskline = html_writer::link($cycletask['url'], s((string)$cycletask['title']));
                if ((string)$cycletask['status'] !== '') {
                    $taskline .= ' · ' . s($taskstatuslabel((string)$cycletask['status']));
                }
                $cyclecell .= html_writer::tag('div', $taskline, ['class'=>'mt-1']);
                if ((int)$cycletask['checklist_total'] > 0) {
                    $progress = (object)[
                        'done'=>(int)$cycletask['checklist_done'], 'total'=>(int)$cycletask['checklist_total'],
                        'percent'=>format_float((float)$cycletask['checklist_percent'], 0),
                    ];
                    $cyclecell .= html_writer::tag('small', get_string('battleplan_indicator_task_checklist', 'local_kbacompany', $progress),
                        ['class'=>'d-block text-muted']);
                }
                if (trim((string)$cycletask['result']) !== '') {
                    $resultshort = core_text::substr(trim((string)$cycletask['result']), 0, 180);
                    $cyclecell .= html_writer::tag('small', s(get_string('indicator_management_cycle_task_result', 'local_kbacompany', $resultshort)),
                        ['class'=>'d-block text-muted']);
                }
            } else if (!empty($cycletask['exists'])) {
                $cyclecell .= html_writer::tag('small', get_string('indicator_exception_task_restricted', 'local_kbacompany'),
                    ['class'=>'d-block text-muted mt-1']);
            }
            $observation = $cycle['observation'];
            if ($observation['baseline_fact'] !== null && $observation['followup_fact'] !== null) {
                $assessmentkey = 'battleplan_indicator_after_action_' . (string)$observation['quality'];
                $assessment = get_string_manager()->string_exists($assessmentkey, 'local_kbacompany') ?
                    get_string($assessmentkey, 'local_kbacompany') : (string)$observation['quality'];
                $obs = (object)[
                    'before'=>format_float((float)$observation['baseline_fact'], 2) . ' ' . (string)$metric['unit'],
                    'after'=>format_float((float)$observation['followup_fact'], 2) . ' ' . (string)$metric['unit'],
                    'assessment'=>$assessment,
                ];
                $cyclecell .= html_writer::tag('small', s(get_string('battleplan_indicator_after_action', 'local_kbacompany', $obs)),
                    ['class'=>'d-block mt-1']);
                $cyclecell .= html_writer::tag('small', get_string('battleplan_indicator_after_action_note', 'local_kbacompany'),
                    ['class'=>'d-block text-muted']);
            }
            $planlinks = [];
            foreach ($cycle['plans']['current'] as $planrow) {
                $label = get_string('indicator_management_cycle_current_plan_link', 'local_kbacompany', (string)$planrow['employee']);
                if (!empty($planrow['task_in_plan'])) {
                    $label .= ' · ' . get_string('indicator_management_cycle_task_in_plan', 'local_kbacompany');
                }
                $planlinks[] = html_writer::link($planrow['url'], s($label));
            }
            if ($planlinks) {
                $cyclecell .= html_writer::tag('small', implode('<br>', $planlinks), ['class'=>'d-block mt-1']);
            } else if ((string)$row['binding']['contextkind'] === 'position') {
                $cyclecell .= html_writer::tag('small', get_string('indicator_management_cycle_no_accessible_current_plan', 'local_kbacompany'),
                    ['class'=>'d-block text-muted mt-1']);
            }
            $currentplanids = [];
            foreach ($cycle['plans']['current'] as $planrow) { $currentplanids[(string)$planrow['uuid']] = true; }
            $linkedplans = [];
            foreach ($cycle['plans']['linked'] as $planrow) {
                if (isset($currentplanids[(string)$planrow['uuid']])) { continue; }
                $linkedplans[] = html_writer::link($planrow['url'],
                    s(get_string('indicator_management_cycle_action_plan_link', 'local_kbacompany', (string)$planrow['period_start'])));
            }
            if ($linkedplans) {
                $cyclecell .= html_writer::tag('small', implode('<br>', $linkedplans), ['class'=>'d-block text-muted mt-1']);
            }

            $actions = html_writer::link(new moodle_url('/local/kbacompany/indicators.php', [
                'uuid'=>$uuid, 'tab'=>'conditions', 'stat'=>$metric['uuid']
            ]), get_string('indicator_exception_open_condition', 'local_kbacompany'), ['class'=>'btn btn-sm btn-outline-primary']);
            $table->data[] = [
                $severitycell,
                $metriccell,
                s((string)$row['context_label']),
                implode(' ', $reasonitems),
                $conditioncell,
                $trendcell,
                $factnormcell,
                $cyclecell,
                $actions,
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('indicator_exceptions_empty', 'local_kbacompany'), 'success');
    }
}

if ($tab === 'trends') {
    echo html_writer::tag('p', get_string('indicator_trends_intro', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::div(get_string('indicator_trend_rule', 'local_kbacompany'), 'alert alert-info');
    echo $trendfilterhtml('trends');

    $trendrows = [];
    $summary = ['improving'=>0, 'worsening'=>0, 'stable'=>0, 'reversal'=>0];
    foreach ($data['metrics'] as $metric) {
        $series = $getseries($metric['uuid'], 52);
        $analysis = indicator_trend_service::analyze($series, (string)$metric['direction']);
        if (!$matchestrendfilter($analysis, $trendfilter)) { continue; }
        $events = indicator_event_service::latest($series, (string)$metric['direction']);
        $lastfact = null;
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i]['fact'] !== null) { $lastfact = $series[$i]; break; }
        }
        $quality = (string)$analysis['quality'];
        if (isset($summary[$quality])) { $summary[$quality]++; }
        foreach ($events as $event) {
            if (strpos((string)$event['type'], 'reversal_') === 0) { $summary['reversal']++; break; }
        }
        $trendrows[] = ['metric'=>$metric, 'series'=>$series, 'analysis'=>$analysis, 'events'=>$events, 'lastfact'=>$lastfact];
    }

    $cards = '';
    foreach ([
        'improving'=>'indicator_trends_improving_count',
        'worsening'=>'indicator_trends_worsening_count',
        'stable'=>'indicator_trends_stable_count',
        'reversal'=>'indicator_trends_reversal_count',
    ] as $key=>$langkey) {
        $cards .= html_writer::div(html_writer::tag('small', get_string($langkey, 'local_kbacompany')) .
            html_writer::tag('strong', (string)$summary[$key]), 'kba-indicator-kpi kba-trend-summary--'.$key);
    }
    echo html_writer::div($cards, 'kba-indicator-kpis kba-indicator-kpis--four mb-3');

    $table = new html_table();
    $table->head = [
        get_string('indicator_metric', 'local_kbacompany'),
        get_string('indicator_last_fact', 'local_kbacompany'),
        get_string('indicator_trend_movement', 'local_kbacompany'),
        get_string('indicator_trend_quality', 'local_kbacompany'),
        get_string('indicator_trend_streak', 'local_kbacompany'),
        get_string('indicator_trend_event', 'local_kbacompany'),
    ];
    foreach ($trendrows as $row) {
        $metric = $row['metric']; $analysis = $row['analysis']; $lastfact = $row['lastfact'];
        $factlabel = $lastfact ? format_float((float)$lastfact['fact'], 2).' '.s($metric['unit']) : '—';
        $movement = $trendmovementlabel((string)$analysis['movement']);
        $pct = $analysis['percent'] === null ? '' : ' '.(($analysis['percent'] > 0) ? '+' : '').
            format_float((float)$analysis['percent'], 1).'%';
        $streak = (int)$analysis['streak'] > 1 ? get_string('indicator_trend_periods', 'local_kbacompany', (int)$analysis['streak']) : '—';
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacompany/indicators.php', [
                'uuid'=>$uuid, 'tab'=>'input', 'stat'=>$metric['uuid'], 'horizon'=>13
            ]), s($metric['name'])),
            $factlabel,
            s($movement.$pct),
            html_writer::span(s($trendqualitylabel((string)$analysis['quality'])),
                'kba-trend-badge kba-trend-quality--'.s((string)$analysis['quality'])),
            s($streak),
            s($eventlabels($row['events'])),
        ];
    }
    echo $trendrows ? html_writer::table($table) : $OUTPUT->notification(get_string('indicator_filter_empty', 'local_kbacompany'), 'info');
}


if ($tab === 'conditions') {
    $executionoptions = $data['can_manage'] ? indicator_condition_execution_service::execution_options($uuid, (int)$USER->id) :
        ['employees'=>[], 'battle_plans'=>[]];
    $conditionhistory = indicator_condition_execution_service::history($uuid, (int)$USER->id, $statuuid, 100);
    $conditionselectoptions = ['' => get_string('indicator_condition_choose', 'local_kbacompany')];
    foreach (indicator_condition_service::CONDITIONS as $conditioncode) {
        $conditionselectoptions[$conditioncode] = $conditionlabel($conditioncode);
    }
    $planselectoptions = ['' => get_string('indicator_condition_no_battle_plan', 'local_kbacompany')];
    foreach ($executionoptions['battle_plans'] as $planoption) {
        $planselectoptions[(string)$planoption['uuid']] = (string)$planoption['label'];
    }
    echo html_writer::tag('p', get_string('indicator_conditions_intro', 'local_kbacompany'), ['class'=>'text-muted']);
    echo html_writer::div(get_string('indicator_conditions_methodology', 'local_kbacompany'), 'alert alert-info');
    echo html_writer::div(get_string('indicator_condition_policy_hint', 'local_kbacompany'), 'alert alert-secondary');

    $conditionrows = [];
    foreach ($data['bindings'] as $binding) {
        if (!empty($binding['compatibility'])) { continue; }
        $metric = $metricmap[$binding['statuuid']] ?? null;
        if (!$metric) { continue; }
        $series = $getseries((string)$metric['uuid'], 52);
        $condition = indicator_condition_service::analyze($series, (string)$metric['direction'],
            $data['normhistory'], $data['condition_policy_history'], (string)$binding['uuid']);
        $conditionrows[] = ['metric'=>$metric, 'binding'=>$binding, 'condition'=>$condition,
            'policy'=>$policybybind[(string)$binding['uuid']] ?? null];
    }

    if ($data['can_manage'] && $conditionrows) {
        echo html_writer::start_tag('details', ['class'=>'card mb-3']);
        echo html_writer::tag('summary', get_string('indicator_condition_policy_add', 'local_kbacompany'), ['class'=>'card-header']);
        echo html_writer::start_div('card-body');
        $bindingoptions = [];
        foreach ($conditionrows as $row) {
            $bindingoptions[(string)$row['binding']['uuid']] = $row['metric']['name'].' — '.$contextlabel($row['binding']);
        }
        echo html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form']);
        echo $hidden('conditionpolicy');
        echo html_writer::label(get_string('indicator_binding', 'local_kbacompany'), 'condition_bind');
        echo html_writer::select($bindingoptions, 'binduuid', '', false,
            ['id'=>'condition_bind','class'=>'form-control mb-2','required'=>'required']);
        echo html_writer::label(get_string('indicator_condition_danger_decline', 'local_kbacompany'), 'condition_danger_decline');
        echo html_writer::empty_tag('input', ['type'=>'number','step'=>'0.001','min'=>'0.001','id'=>'condition_danger_decline',
            'name'=>'dangerdecline','class'=>'form-control mb-1']);
        echo html_writer::tag('small', get_string('indicator_condition_danger_decline_help', 'local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
        echo html_writer::label(get_string('indicator_condition_danger_periods', 'local_kbacompany'), 'condition_danger_periods');
        echo html_writer::empty_tag('input', ['type'=>'number','min'=>'0','max'=>'520','id'=>'condition_danger_periods',
            'name'=>'dangerperiods','value'=>'0','class'=>'form-control mb-1']);
        echo html_writer::tag('small', get_string('indicator_condition_danger_periods_help', 'local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
        echo html_writer::label(get_string('indicator_condition_affluence_growth', 'local_kbacompany'), 'condition_affluence');
        echo html_writer::empty_tag('input', ['type'=>'number','step'=>'0.001','min'=>'0.001','id'=>'condition_affluence',
            'name'=>'affluencegrowth','class'=>'form-control mb-1','required'=>'required']);
        echo html_writer::tag('small', get_string('indicator_condition_affluence_growth_help', 'local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
        echo html_writer::label(get_string('indicator_condition_power_level', 'local_kbacompany'), 'condition_power_level');
        echo html_writer::empty_tag('input', ['type'=>'number','step'=>'0.001','min'=>'100.001','id'=>'condition_power_level',
            'name'=>'powerlevel','class'=>'form-control mb-1']);
        echo html_writer::tag('small', get_string('indicator_condition_power_level_help', 'local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
        echo html_writer::label(get_string('indicator_condition_power_periods', 'local_kbacompany'), 'condition_power_periods');
        echo html_writer::empty_tag('input', ['type'=>'number','min'=>'0','max'=>'520','id'=>'condition_power_periods',
            'name'=>'powerperiods','value'=>'0','class'=>'form-control mb-1']);
        echo html_writer::tag('small', get_string('indicator_condition_power_periods_help', 'local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
        echo html_writer::label(get_string('indicator_norm_from', 'local_kbacompany'), 'condition_from');
        echo html_writer::empty_tag('input', ['type'=>'date','id'=>'condition_from','name'=>'effectivefrom',
            'class'=>'form-control mb-2','required'=>'required']);
        echo html_writer::label(get_string('indicator_norm_to', 'local_kbacompany'), 'condition_to');
        echo html_writer::empty_tag('input', ['type'=>'date','id'=>'condition_to','name'=>'effectiveto','class'=>'form-control mb-2']);
        echo $btn(get_string('indicator_condition_policy_add', 'local_kbacompany'));
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_tag('details');
    }

    $table = new html_table();
    $table->head = [get_string('indicator_metric','local_kbacompany'), get_string('indicator_binding_context','local_kbacompany'),
        get_string('indicator_condition_policy','local_kbacompany'), get_string('indicator_condition','local_kbacompany'),
        get_string('indicator_condition_action_title','local_kbacompany'), get_string('indicator_actions','local_kbacompany')];
    foreach ($conditionrows as $row) {
        $metric = $row['metric']; $binding = $row['binding']; $condition = $row['condition']; $policy = $row['policy'];
        $policytext = get_string('indicator_condition_policy_empty', 'local_kbacompany');
        if ($policy) {
            $parts = [];
            if ($policy['dangerdecline'] !== null && $policy['dangerdecline'] !== '') {
                $parts[] = get_string('indicator_condition_danger_decline','local_kbacompany').': '.format_float((float)$policy['dangerdecline'], 1).'%';
            }
            if ((int)$policy['dangerperiods'] > 0) {
                $parts[] = get_string('indicator_condition_danger_periods','local_kbacompany').': '.(int)$policy['dangerperiods'];
            }
            $parts[] = get_string('indicator_condition_affluence_growth','local_kbacompany').': '.format_float((float)$policy['affluencegrowth'], 1).'%';
            if ($policy['powerlevel'] !== null && $policy['powerlevel'] !== '') {
                $parts[] = get_string('indicator_condition_power_level','local_kbacompany').': '.format_float((float)$policy['powerlevel'], 1).'%';
                $parts[] = get_string('indicator_condition_power_periods','local_kbacompany').': '.(int)$policy['powerperiods'];
            }
            $parts[] = (string)$policy['effectivefrom'].((string)$policy['effectiveto'] !== '' ? ' — '.(string)$policy['effectiveto'] : '');
            $policytext = implode('; ', $parts);
        }
        $conditiontext = html_writer::tag('strong', s($conditionlabel((string)($condition['condition'] ?? ''))));
        $conditiontext .= html_writer::tag('div', s($conditionreasonlabel((string)($condition['reason'] ?? 'insufficient'))), ['class'=>'text-muted small']);
        $actionsuggest = (string)($condition['condition'] ?? '') !== '' ? $conditionactions((string)$condition['condition']) : '—';
        $manage = '';
        if ($data['can_manage'] && $policy) {
            $manage .= html_writer::start_tag('details', ['class'=>'mb-2']);
            $manage .= html_writer::tag('summary', get_string('indicator_condition_policy_edit','local_kbacompany'));
            $manage .= html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form mt-2']);
            $manage .= $hidden('conditionpolicy');
            $manage .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'binduuid','value'=>$binding['uuid']]);
            $manage .= html_writer::empty_tag('input', ['type'=>'hidden','name'=>'policyuuid','value'=>$policy['uuid']]);
            foreach ([
                ['dangerdecline','indicator_condition_danger_decline','number','0.001'],
                ['dangerperiods','indicator_condition_danger_periods','number','1'],
                ['affluencegrowth','indicator_condition_affluence_growth','number','0.001'],
                ['powerlevel','indicator_condition_power_level','number','0.001'],
                ['powerperiods','indicator_condition_power_periods','number','1'],
            ] as $field) {
                $manage .= html_writer::label(get_string($field[1],'local_kbacompany'), 'condition_'.$binding['uuid'].'_'.$field[0]);
                $attrs = ['type'=>$field[2],'step'=>$field[3],'id'=>'condition_'.$binding['uuid'].'_'.$field[0],
                    'name'=>$field[0],'value'=>$policy[$field[0]] === null ? '' : $policy[$field[0]],'class'=>'form-control mb-2'];
                if ($field[0] === 'affluencegrowth') { $attrs['required'] = 'required'; }
                $manage .= html_writer::empty_tag('input', $attrs);
            }
            $manage .= html_writer::label(get_string('indicator_norm_from','local_kbacompany'), 'condition_'.$binding['uuid'].'_from');
            $manage .= html_writer::empty_tag('input', ['type'=>'date','id'=>'condition_'.$binding['uuid'].'_from','name'=>'effectivefrom',
                'value'=>$policy['effectivefrom'],'class'=>'form-control mb-2','required'=>'required']);
            $manage .= html_writer::label(get_string('indicator_norm_to','local_kbacompany'), 'condition_'.$binding['uuid'].'_to');
            $manage .= html_writer::empty_tag('input', ['type'=>'date','id'=>'condition_'.$binding['uuid'].'_to','name'=>'effectiveto',
                'value'=>$policy['effectiveto'],'class'=>'form-control mb-2']);
            $manage .= $btn(get_string('indicator_condition_policy_edit','local_kbacompany'),'btn btn-sm btn-primary');
            $manage .= html_writer::end_tag('form').html_writer::end_tag('details');
            $manage .= html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden('archiveconditionpolicy').
                html_writer::empty_tag('input',['type'=>'hidden','name'=>'policyuuid','value'=>$policy['uuid']]).
                $btn(get_string('indicator_condition_policy_archive','local_kbacompany'),'btn btn-sm btn-outline-danger').html_writer::end_tag('form');
        }
        if ($data['can_manage']) {
            $defaultcondition = (string)($condition['condition'] ?? '');
            $actionkeys = $defaultcondition !== '' ? indicator_condition_service::action_keys($defaultcondition) : [];
            $defaulttitle = $actionkeys ? get_string($actionkeys[0], 'local_kbacompany') : '';
            $manage .= html_writer::start_tag('details', ['class'=>'mt-2']);
            $manage .= html_writer::tag('summary', get_string('indicator_condition_confirm_manage','local_kbacompany'));
            $manage .= html_writer::start_div('mt-2');
            $manage .= html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form mb-3']);
            $manage .= $hidden('conditionconfirm');
            $manage .= html_writer::empty_tag('input',['type'=>'hidden','name'=>'binduuid','value'=>$binding['uuid']]);
            $manage .= html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$metric['uuid']]);
            $manage .= html_writer::label(get_string('indicator_condition_confirmed_state','local_kbacompany'), 'confirm_'.$binding['uuid'].'_condition');
            $manage .= html_writer::select($conditionselectoptions,'condition',$defaultcondition,false,
                ['id'=>'confirm_'.$binding['uuid'].'_condition','class'=>'form-control mb-2','required'=>'required']);
            $manage .= html_writer::label(get_string('indicator_condition_manager_comment','local_kbacompany'), 'confirm_'.$binding['uuid'].'_comment');
            $manage .= html_writer::tag('textarea','',['id'=>'confirm_'.$binding['uuid'].'_comment','name'=>'comment','rows'=>2,'class'=>'form-control mb-2']);
            $manage .= html_writer::tag('small', get_string('indicator_condition_context_comment_help','local_kbacompany'), ['class'=>'form-text text-muted mb-2']);
            $manage .= $btn(get_string('indicator_condition_confirm_only','local_kbacompany'),'btn btn-sm btn-outline-primary');
            $manage .= html_writer::end_tag('form');

            if ($executionoptions['employees']) {
                $prioritydefault = in_array($defaultcondition, ['danger','emergency'], true) ? 'high' : 'normal';
                $manage .= html_writer::start_tag('form', ['method'=>'post','class'=>'kbacompany-form border-top pt-2']);
                $manage .= $hidden('conditionexecute');
                $manage .= html_writer::empty_tag('input',['type'=>'hidden','name'=>'binduuid','value'=>$binding['uuid']]);
                $manage .= html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$metric['uuid']]);
                $manage .= html_writer::empty_tag('input',['type'=>'hidden','name'=>'stream','value'=>'change']);
                $manage .= html_writer::label(get_string('indicator_condition_confirmed_state','local_kbacompany'), 'execute_'.$binding['uuid'].'_condition');
                $manage .= html_writer::select($conditionselectoptions,'condition',$defaultcondition,false,
                    ['id'=>'execute_'.$binding['uuid'].'_condition','class'=>'form-control mb-2','required'=>'required']);
                $manage .= html_writer::label(get_string('indicator_condition_manager_comment','local_kbacompany'), 'execute_'.$binding['uuid'].'_comment');
                $manage .= html_writer::tag('textarea','',['id'=>'execute_'.$binding['uuid'].'_comment','name'=>'comment','rows'=>2,'class'=>'form-control mb-2']);
                $manage .= html_writer::label(get_string('taskcenter_task','local_kbacompany'), 'execute_'.$binding['uuid'].'_title');
                $manage .= html_writer::empty_tag('input',['id'=>'execute_'.$binding['uuid'].'_title','name'=>'title','value'=>$defaulttitle,
                    'class'=>'form-control mb-2','required'=>'required']);
                $manage .= html_writer::label(get_string('taskcenter_product','local_kbacompany'), 'execute_'.$binding['uuid'].'_product');
                $manage .= html_writer::tag('textarea','',['id'=>'execute_'.$binding['uuid'].'_product','name'=>'product','rows'=>2,
                    'class'=>'form-control mb-2','required'=>'required','placeholder'=>get_string('indicator_condition_expected_product_placeholder','local_kbacompany')]);
                $manage .= html_writer::label(get_string('taskcenter_description','local_kbacompany'), 'execute_'.$binding['uuid'].'_description');
                $manage .= html_writer::tag('textarea','',['id'=>'execute_'.$binding['uuid'].'_description','name'=>'description','rows'=>2,'class'=>'form-control mb-2']);
                $manage .= html_writer::label(get_string('taskcenter_assignee','local_kbacompany'), 'execute_'.$binding['uuid'].'_assignee');
                $manage .= html_writer::select($executionoptions['employees'],'assigneeid',isset($executionoptions['employees'][(int)$USER->id])?(int)$USER->id:'',false,
                    ['id'=>'execute_'.$binding['uuid'].'_assignee','class'=>'form-control mb-2','required'=>'required']);
                $manage .= html_writer::label(get_string('taskcenter_reviewer','local_kbacompany'), 'execute_'.$binding['uuid'].'_reviewer');
                $manage .= html_writer::select([0=>get_string('taskcenter_noreviewer','local_kbacompany')] + $executionoptions['employees'],'reviewerid',0,false,
                    ['id'=>'execute_'.$binding['uuid'].'_reviewer','class'=>'form-control mb-2']);
                $manage .= html_writer::label(get_string('taskcenter_priority','local_kbacompany'), 'execute_'.$binding['uuid'].'_priority');
                $manage .= html_writer::select(['low'=>get_string('taskcenter_priority_low','local_kbacompany'),
                    'normal'=>get_string('taskcenter_priority_normal','local_kbacompany'),
                    'high'=>get_string('taskcenter_priority_high','local_kbacompany')], 'priority', $prioritydefault, false,
                    ['id'=>'execute_'.$binding['uuid'].'_priority','class'=>'form-control mb-2']);
                $manage .= html_writer::label(get_string('taskcenter_duedate','local_kbacompany'), 'execute_'.$binding['uuid'].'_due');
                $manage .= html_writer::empty_tag('input',['type'=>'date','id'=>'execute_'.$binding['uuid'].'_due','name'=>'duedate','class'=>'form-control mb-2']);
                $manage .= html_writer::label(get_string('indicator_condition_battle_plan','local_kbacompany'), 'execute_'.$binding['uuid'].'_plan');
                $manage .= html_writer::select($planselectoptions,'planuuid','',false,
                    ['id'=>'execute_'.$binding['uuid'].'_plan','class'=>'form-control mb-2']);
                $manage .= html_writer::tag('small',get_string('indicator_condition_battle_plan_help','local_kbacompany'),['class'=>'form-text text-muted mb-2']);
                $manage .= $btn(get_string('indicator_condition_create_task','local_kbacompany'),'btn btn-sm btn-success');
                $manage .= html_writer::end_tag('form');
            }
            $manage .= html_writer::end_div().html_writer::end_tag('details');
        }
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'input','stat'=>$metric['uuid']]), s($metric['name'])),
            s($contextlabel($binding)), s($policytext), $conditiontext, $actionsuggest, $manage,
        ];
    }
    echo $conditionrows ? html_writer::table($table) : $OUTPUT->notification(get_string('indicator_empty','local_kbacompany'), 'info');
    echo html_writer::div(get_string('indicator_condition_action_note','local_kbacompany'), 'alert alert-light mt-3');

    echo $OUTPUT->heading(get_string('indicator_condition_event_history','local_kbacompany'), 3);
    if (!$conditionhistory) {
        echo html_writer::tag('p', get_string('indicator_condition_event_history_empty','local_kbacompany'), ['class'=>'text-muted']);
    } else {
        $eventtable = new html_table();
        $eventtable->head = [get_string('indicator_condition_event_time','local_kbacompany'),
            get_string('indicator_metric','local_kbacompany'), get_string('indicator_binding_context','local_kbacompany'),
            get_string('indicator_condition_event_kind','local_kbacompany'), get_string('indicator_condition','local_kbacompany'),
            get_string('indicator_condition_manager_comment','local_kbacompany'), get_string('indicator_condition_execution','local_kbacompany')];
        foreach ($conditionhistory as $event) {
            $emetric = $metricmap[$event['stat_uuid']] ?? null;
            $ebinding = $bindingmap[$event['bind_uuid']] ?? null;
            $kindkey = 'indicator_condition_event_' . $event['event_kind'];
            $kindlabel = get_string_manager()->string_exists($kindkey, 'local_kbacompany') ?
                get_string($kindkey, 'local_kbacompany') : $event['event_kind'];
            $state = $conditionlabel($event['condition']);
            if ($event['analytic_condition'] !== '' && $event['analytic_condition'] !== $event['condition']) {
                $state .= ' · ' . get_string('indicator_condition_event_analytical_was','local_kbacompany',
                    $conditionlabel($event['analytic_condition']));
            }
            $execution = [];
            foreach ($event['tasks'] as $taskrow) {
                $tasklink = html_writer::link(new moodle_url('/local/kbacompany/tasks.php', [
                    'uuid'=>$uuid, 'task'=>$taskrow['uuid']
                ]), s($taskrow['title']));
                $plans = [];
                foreach ($taskrow['plans'] as $planrow) {
                    $plans[] = html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php', [
                        'uuid'=>$uuid, 'plan'=>$planrow['uuid']
                    ]), get_string('indicator_condition_open_battle_plan','local_kbacompany'));
                }
                $execution[] = $tasklink . ($plans ? ' · ' . implode(', ', $plans) : '');
            }
            $comment = $event['comment'] !== '' ? s($event['comment']) : '—';
            if ($event['actor_name'] !== '') {
                $comment .= html_writer::tag('div', s($event['actor_name']), ['class'=>'text-muted small']);
            }
            $eventtable->data[] = [
                s(userdate($event['time_created'])) . ($event['period_key'] !== '' ? html_writer::tag('div', s($event['period_key']), ['class'=>'text-muted small']) : ''),
                $emetric ? s($emetric['name']) : s($event['stat_uuid']),
                $ebinding ? s($contextlabel($ebinding)) : s($event['bind_uuid']),
                s($kindlabel), s($state), $comment, $execution ? implode('<br>', $execution) : '—',
            ];
        }
        echo html_writer::table($eventtable);
    }
}

if ($tab === 'tree') {
    $switch = html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
        ['uuid' => $uuid, 'tab' => 'tree', 'treeview' => 'organization']),
        get_string('indicator_tree_org', 'local_kbacompany'), ['class' => 'btn '.($treeview === 'organization' ? 'btn-success' : 'btn-outline-secondary').' mr-1']);
    $switch .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
        ['uuid' => $uuid, 'tab' => 'tree', 'treeview' => 'strategy']),
        get_string('indicator_tree_strategy', 'local_kbacompany'), ['class' => 'btn '.($treeview === 'strategy' ? 'btn-success' : 'btn-outline-secondary')]);
    echo html_writer::div($switch, 'mb-3');

    if ($treeview === 'strategy') {
        $perspectives = [
            'financial' => get_string('strategy_perspective_financial', 'local_kbacompany'),
            'customer' => get_string('strategy_perspective_customer', 'local_kbacompany'),
            'process' => get_string('strategy_perspective_process', 'local_kbacompany'),
            'learning' => get_string('strategy_perspective_learning', 'local_kbacompany'),
        ];
        foreach ($data['strategies'] as $strategy) {
            echo html_writer::start_div('card mb-3');
            echo html_writer::start_div('card-body');
            echo $OUTPUT->heading(s($strategy->name), 3);
            foreach ($perspectives as $pkey => $plabel) {
                $goals = array_filter($data['goals'], static function($goal) use ($strategy, $pkey): bool {
                    return (string)$goal->strategyuuid === (string)$strategy->uuid && (string)$goal->perspective === $pkey;
                });
                if (!$goals) { continue; }
                echo $OUTPUT->heading($plabel, 4);
                foreach ($goals as $goal) {
                    echo html_writer::start_div('kba-indicator-tree-row');
                    echo html_writer::tag('strong', s($goal->code . ' — ' . $goal->name));
                    echo $metriclinks('strategygoal', (string)$goal->uuid);
                    echo html_writer::end_div();
                }
            }
            echo html_writer::end_div(); echo html_writer::end_div();
        }
        if (!$data['strategies']) { echo $OUTPUT->notification(get_string('indicator_tree_no_strategy', 'local_kbacompany'), 'info'); }
    } else {
        $nodebyparent = [];
        $activenodes = [];
        foreach ($data['nodes'] as $node) { $activenodes[(string)$node->uuid] = true; }
        foreach ($data['nodes'] as $node) {
            $parent = (string)$node->parentuuid;
            if ($parent !== '' && !isset($activenodes[$parent])) { $parent = ''; }
            $nodebyparent[$parent][] = $node;
        }
        $positionsbynode = [];
        foreach ($data['positions'] as $position) { $positionsbynode[(string)$position->orgnodeuuid][] = $position; }
        $rendernode = function($node, int $level) use (&$rendernode, $nodebyparent, $positionsbynode, $metriclinks): string {
            $html = html_writer::start_div('kba-indicator-tree-node', ['style' => 'margin-left:'.($level * 22).'px']);
            $html .= html_writer::tag('div', '<strong>'.s($node->code.' — '.$node->name).'</strong>');
            $html .= $metriclinks('orgnode', (string)$node->uuid);
            foreach ($positionsbynode[(string)$node->uuid] ?? [] as $position) {
                $html .= html_writer::start_div('kba-indicator-tree-position', ['style' => 'margin-left:22px']);
                $html .= html_writer::tag('div', s($position->code.' — '.$position->name));
                $html .= $metriclinks('position', (string)$position->uuid);
                $html .= html_writer::end_div();
            }
            foreach ($nodebyparent[(string)$node->uuid] ?? [] as $child) { $html .= $rendernode($child, $level + 1); }
            $html .= html_writer::end_div();
            return $html;
        };
        echo html_writer::start_div('card'); echo html_writer::start_div('card-body');
        echo $OUTPUT->heading(format_string($data['company']['name']), 3);
        echo $metriclinks('company', $uuid);
        $roots = $nodebyparent[''] ?? [];
        foreach ($roots as $root) { echo $rendernode($root, 0); }
        if (!$roots) { echo $OUTPUT->notification(get_string('indicator_tree_no_org', 'local_kbacompany'), 'info'); }
        echo html_writer::end_div(); echo html_writer::end_div();
    }

    if ($data['can_manage'] && $data['metrics'] && $contextoptions) {
        echo html_writer::start_tag('details', ['class' => 'mt-4 card']);
        echo html_writer::tag('summary', get_string('indicator_add_binding', 'local_kbacompany'), ['class' => 'card-header']);
        echo html_writer::start_div('card-body');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form']); echo $hidden('binding');
        $metricopts=[]; foreach($data['metrics'] as $m){$metricopts[$m['uuid']]=$m['code'].' — '.$m['name'];}
        echo html_writer::label(get_string('indicator_metric', 'local_kbacompany'), 'bind_stat');
        echo html_writer::select($metricopts, 'statuuid', '', false, ['id'=>'bind_stat','class'=>'form-control mb-2','required'=>'required']);
        echo html_writer::label(get_string('indicator_binding_context', 'local_kbacompany'), 'bind_context');
        echo html_writer::select($contextoptions, 'bindingcontext', '', false, ['id'=>'bind_context','class'=>'form-control mb-2','required'=>'required']);
        echo html_writer::label(get_string('indicator_weight', 'local_kbacompany'), 'bind_weight');
        echo html_writer::empty_tag('input',['id'=>'bind_weight','name'=>'weight','value'=>'1','type'=>'number','step'=>'0.001','min'=>'0','class'=>'form-control mb-2']);
        echo html_writer::checkbox('isprimary',1,false,get_string('indicator_primary','local_kbacompany'),['class'=>'mr-2']);
        echo html_writer::checkbox('critical',1,false,get_string('indicator_critical','local_kbacompany'),['class'=>'mr-2']);
        echo $btn(get_string('indicator_add_binding', 'local_kbacompany'));
        echo html_writer::end_tag('form'); echo html_writer::end_div(); echo html_writer::end_tag('details');
    }

    if ($data['bindings']) {
        echo $OUTPUT->heading(get_string('indicator_current_bindings', 'local_kbacompany'), 3, 'mt-4');
        $table = new html_table();
        $table->head=[get_string('indicator_metric','local_kbacompany'),get_string('indicator_binding_context','local_kbacompany'),
            get_string('indicator_role','local_kbacompany'),get_string('indicator_actions','local_kbacompany')];
        foreach($data['bindings'] as $binding){
            $metric=$metricmap[$binding['statuuid']]??null; if(!$metric){continue;}
            $role=[]; if($binding['isprimary']){$role[]=get_string('indicator_primary','local_kbacompany');}
            if($binding['critical']){$role[]=get_string('indicator_critical','local_kbacompany');}
            $actions=$binding['compatibility']?get_string('indicator_compatibility_link','local_kbacompany'):'';
            if($data['can_manage'] && !$binding['compatibility']){
                $actions.=html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden('archivebinding').
                    html_writer::empty_tag('input',['type'=>'hidden','name'=>'binduuid','value'=>$binding['uuid']]).
                    $btn(get_string('indicator_archive_binding','local_kbacompany'),'btn btn-sm btn-outline-danger').html_writer::end_tag('form');
            }
            $table->data[]=[s($metric['code'].' — '.$metric['name']),s($contextlabel($binding)),s(implode(', ',$role) ?: '—'),$actions];
        }
        echo html_writer::table($table);
    }
}

if ($tab === 'all') {
    $activecount = 0; $archivedcount = 0; $maincount = 0;
    foreach ($data['metrics'] as $metric) {
        if (($metric['status'] ?? 'active') === 'archived') { $archivedcount++; } else { $activecount++; }
        if (!empty($metric['maincompany']) && ($metric['status'] ?? 'active') !== 'archived') { $maincount++; }
    }

    echo html_writer::start_div('bms-metric-crud');
    echo html_writer::start_div('bms-metric-crud-head');
    echo html_writer::div(
        html_writer::tag('h3', get_string('indicator_registry_title', 'local_kbacompany'), ['class'=>'mb-1']) .
        html_writer::tag('p', get_string('indicator_registry_help', 'local_kbacompany'), ['class'=>'text-muted mb-0']),
        'bms-metric-crud-headcopy');
    if ($data['can_manage']) {
        echo html_writer::link(new moodle_url('/local/kbacompany/indicator_record.php', [
            'uuid'=>$uuid, 'mode'=>'create'
        ]), '+ ' . get_string('indicator_create_metric', 'local_kbacompany'),
            ['class'=>'btn btn-success']);
    }
    echo html_writer::end_div();

    $summaryhtml = '';
    foreach ([
        get_string('indicator_crud_active', 'local_kbacompany') => $activecount,
        get_string('indicator_main_company_metric_short', 'local_kbacompany') => $maincount,
        get_string('indicator_crud_archived', 'local_kbacompany') => $archivedcount,
        get_string('indicator_crud_total', 'local_kbacompany') => count($data['metrics']),
    ] as $label=>$value) {
        $summaryhtml .= html_writer::div(html_writer::tag('strong', (string)$value) . html_writer::tag('small', $label),
            'bms-collection-metric');
    }
    echo html_writer::div($summaryhtml, 'bms-collection-analytics bms-metric-crud-summary');

    echo html_writer::start_tag('form', ['method'=>'get','class'=>'bms-metric-crud-filter mb-3']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'tab','value'=>'all']);
    echo html_writer::div(
        html_writer::label(get_string('indicator_crud_search', 'local_kbacompany'), 'metric-crud-search') .
        html_writer::empty_tag('input', ['type'=>'search','id'=>'metric-crud-search','name'=>'metricsearch',
            'value'=>$metricsearch,'class'=>'form-control','placeholder'=>get_string('indicator_crud_search_placeholder','local_kbacompany')]),
        'bms-metric-filter-field');
    echo html_writer::div(
        html_writer::label(get_string('indicator_crud_status', 'local_kbacompany'), 'metric-crud-status') .
        html_writer::select([
            'active'=>get_string('indicator_crud_status_active','local_kbacompany'),
            'archived'=>get_string('indicator_crud_status_archived','local_kbacompany'),
            'all'=>get_string('indicator_crud_status_all','local_kbacompany'),
        ], 'metricstatus', $metricstatus, false, ['id'=>'metric-crud-status','class'=>'form-control']),
        'bms-metric-filter-field');
    echo html_writer::div(
        html_writer::tag('button', get_string('indicator_crud_apply','local_kbacompany'), ['type'=>'submit','class'=>'btn btn-success']) .
        html_writer::link(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'all']),
            get_string('indicator_crud_reset','local_kbacompany'), ['class'=>'btn btn-outline-secondary']),
        'bms-metric-filter-actions');
    echo html_writer::end_tag('form');

    echo html_writer::div(get_string('indicator_crud_delete_rule','local_kbacompany'), 'alert alert-light border');

    $visiblemetrics = [];
    $needle = core_text::strtolower($metricsearch);
    foreach ($data['metrics'] as $metric) {
        $status = (string)($metric['status'] ?? 'active');
        if ($metricstatus !== 'all' && $status !== $metricstatus) { continue; }
        if ($needle !== '') {
            $haystack = core_text::strtolower((string)$metric['code'].' '.(string)$metric['name'].' '.(string)$metric['unit']);
            if (core_text::strpos($haystack, $needle) === false) { continue; }
        }
        $visiblemetrics[] = $metric;
    }

    if ($visiblemetrics) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable bms-metric-registry-table';
        $table->head=[get_string('productcode','local_kbacompany'),get_string('indicator_metric','local_kbacompany'),get_string('indicator_crud_management','local_kbacompany'),
            get_string('indicator_crud_period_unit','local_kbacompany'),get_string('indicator_main_company_metric_short','local_kbacompany'),
            get_string('indicator_links_count','local_kbacompany'),get_string('indicator_crud_status','local_kbacompany'),
            get_string('indicator_actions','local_kbacompany')];
        foreach($visiblemetrics as $metric){
            $count=0;foreach($data['bindings'] as $b){if($b['statuuid']===$metric['uuid']){$count++;}}
            $analysis = indicator_trend_service::analyze($getseries($metric['uuid'],52), (string)$metric['direction']);
            $bestbinding = $bestbindingformetric((string)$metric['uuid']);
            $conditionlabeltext = '—';
            if ($bestbinding && empty($bestbinding['compatibility'])) {
                $condition = indicator_condition_service::analyze($getseries($metric['uuid'],52), (string)$metric['direction'],
                    $data['normhistory'], $data['condition_policy_history'], (string)$bestbinding['uuid']);
                $conditionlabeltext = $conditionlabel((string)($condition['condition'] ?? ''));
            }
            $metriclabel = html_writer::link(new moodle_url('/local/kbacompany/indicator_record.php', ['uuid'=>$uuid,'stat'=>$metric['uuid']]),
                s($metric['name']), ['class'=>'font-weight-bold']);
            $management = html_writer::tag('div', get_string('stat'.$metric['direction'],'local_kbacompany')) .
                html_writer::tag('small', s($trendmovementlabel((string)$analysis['movement']).' · '.$conditionlabeltext), ['class'=>'text-muted']);
            $periodunit = s(get_string('stat'.$metric['periodtype'],'local_kbacompany').' · '.$metric['unit']);
            $status = (string)($metric['status'] ?? 'active');
            $statusbadge = html_writer::span(get_string($status === 'archived' ? 'indicator_crud_status_archived' : 'indicator_crud_status_active',
                'local_kbacompany'), 'badge '.($status === 'archived' ? 'badge-secondary' : 'badge-success'));
            $actions = html_writer::link(new moodle_url('/local/kbacompany/indicator_record.php',[
                'uuid'=>$uuid,'stat'=>$metric['uuid']
            ]), get_string('indicator_crud_open','local_kbacompany'), ['class'=>'btn btn-sm btn-outline-success mr-1']);
            if ($status !== 'archived') {
                $actions .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php',[
                    'uuid'=>$uuid,'tab'=>'input','stat'=>$metric['uuid']]), get_string('indicator_crud_record_fact','local_kbacompany'),
                    ['class'=>'btn btn-sm btn-outline-secondary mr-1']);
            }
            if ($data['can_manage'] && $status !== 'archived') {
                $actions .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
                    ['uuid'=>$uuid,'tab'=>'edit','stat'=>$metric['uuid']]), get_string('edit'),
                    ['class'=>'btn btn-sm btn-outline-primary mr-1']);
            }
            if ($data['can_manage']) {
                $op = $status === 'archived' ? 'restoremetric' : 'archivemetric';
                $label = get_string($status === 'archived' ? 'indicator_crud_restore' : 'indicator_crud_archive','local_kbacompany');
                $class = $status === 'archived' ? 'btn btn-sm btn-outline-success' : 'btn btn-sm btn-outline-danger';
                $confirm = get_string($status === 'archived' ? 'indicator_crud_restore_confirm' : 'indicator_crud_archive_confirm','local_kbacompany');
                $actions .= html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden($op).
                    html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$metric['uuid']]).
                    html_writer::tag('button',$label,['type'=>'submit','class'=>$class,'onclick'=>'return confirm('.json_encode($confirm).');']).
                    html_writer::end_tag('form');
            }
            $table->data[]=[s($metric['code']),$metriclabel,$management,$periodunit,!empty($metric['maincompany'])?'★':'—',(int)$count,$statusbadge,$actions];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('indicator_crud_empty','local_kbacompany'),'info');
    }


    echo html_writer::end_div();
}

if ($tab === 'edit') {
    echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid'=>$uuid,'tab'=>'all']),
        '← '.get_string('indicator_registry_title','local_kbacompany'), ['class'=>'btn btn-outline-secondary mb-3']);
    if (!$data['selected']) {
        echo $OUTPUT->notification(get_string('indicator_crud_empty','local_kbacompany'), 'warning');
    }
    if ($data['selected']) {
        $m = $data['selected'];
        $selectedstatus = (string)($m['status'] ?? 'active');
        echo html_writer::start_div('card mt-4 bms-metric-editor-card', ['id'=>'metric-editor']);
        $headmeta = html_writer::span(s($m['code']), 'badge badge-light mr-2') .
            html_writer::span(get_string($selectedstatus === 'archived' ? 'indicator_crud_status_archived' : 'indicator_crud_status_active',
                'local_kbacompany'), 'badge '.($selectedstatus === 'archived' ? 'badge-secondary' : 'badge-success'));
        if (!empty($m['maincompany'])) {
            $headmeta .= html_writer::span(get_string('indicator_main_company_metric','local_kbacompany'), 'badge badge-success ml-2');
        }
        $discussionurl = new moodle_url('/local/kbacompany/communication_center.php', [
            'uuid'=>$uuid, 'tab'=>'discussions', 'objectkind'=>'statistic', 'objectuuid'=>$m['uuid'],
        ]);
        $discussion = html_writer::link($discussionurl, get_string('communication_history','local_kbacompany'),
            ['class'=>'btn btn-outline-primary btn-sm mt-2']);
        echo html_writer::div(html_writer::tag('h3', s($m['name']), ['class'=>'mb-1']) . $headmeta .
            html_writer::tag('p', get_string('indicator_full_edit_hint','local_kbacompany'), ['class'=>'text-muted mb-0']) .
            $discussion, 'card-header');
        echo html_writer::start_div('card-body');

        $cardnav = '';
        foreach ([
            ['edit','indicator_card_basic','metric-editor'],
            ['norms','indicator_card_norms',''],
            ['input','indicator_card_facts',''],
            ['tree','indicator_card_bindings',''],
            ['edit','indicator_card_formula','metric-formula'],
            ['input','indicator_card_history','metric-history'],
        ] as $nav) {
            $urlargs=['uuid'=>$uuid,'tab'=>$nav[0],'stat'=>$m['uuid']];
            $cardnav .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php',$urlargs,$nav[2]),
                get_string($nav[1],'local_kbacompany'), ['class'=>'btn '.($nav[1]==='indicator_card_basic'?'btn-success':'btn-outline-secondary').' btn-sm mr-1 mb-2']);
        }
        echo html_writer::div($cardnav,'mb-3');

        if ($selectedstatus === 'archived') {
            echo html_writer::div(get_string('indicator_crud_archived_help','local_kbacompany'),'alert alert-warning');
            if ($data['can_manage']) {
                echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden('restoremetric').
                    html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$m['uuid']]).
                    $btn(get_string('indicator_crud_restore','local_kbacompany'),'btn btn-success').html_writer::end_tag('form');
            }
        } else if ($data['can_manage']) {
            echo html_writer::start_tag('form',['method'=>'post','class'=>'kbacompany-form bms-metric-editor-form']);
            echo $hidden('metricprofile');
            echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$m['uuid']]);
            $productopts=['' => get_string('indicator_product_none','local_kbacompany')];
            foreach($data['products'] as $p){$productopts[$p->uuid]=$p->code.' — '.$p->name;}

            $summarymeta = html_writer::span(s($m['unit']), 'bms-metric-summary-chip') .
                html_writer::span(s(get_string('stat'.$m['periodtype'],'local_kbacompany')), 'bms-metric-summary-chip') .
                html_writer::span(s(get_string('stat'.$m['direction'],'local_kbacompany')), 'bms-metric-summary-chip');
            echo html_writer::div($summarymeta, 'bms-metric-editor-summary');

            echo html_writer::start_div('bms-metric-editor-section');
            echo html_writer::tag('h4', get_string('indicator_editor_identity','local_kbacompany'), ['class'=>'bms-metric-editor-section-title']);
            echo html_writer::tag('p', get_string('indicator_editor_identity_help','local_kbacompany'), ['class'=>'text-muted bms-metric-editor-section-help']);
            echo html_writer::start_div('bms-metric-identity-grid');
            echo html_writer::div(html_writer::label(get_string('productcode','local_kbacompany'),'metric_code') .
                html_writer::empty_tag('input',['id'=>'metric_code','name'=>'code','value'=>$m['code'],'class'=>'form-control','required'=>'required']), 'bms-metric-form-field');
            echo html_writer::div(html_writer::label(get_string('statname','local_kbacompany'),'metric_name') .
                html_writer::empty_tag('input',['id'=>'metric_name','name'=>'name','value'=>$m['name'],'class'=>'form-control','required'=>'required']), 'bms-metric-form-field');
            echo html_writer::div(html_writer::label(get_string('statunit','local_kbacompany'),'metric_unit') .
                html_writer::empty_tag('input',['id'=>'metric_unit','name'=>'unit','value'=>$m['unit'],'class'=>'form-control','required'=>'required']), 'bms-metric-form-field');
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::start_div('bms-metric-editor-section');
            echo html_writer::tag('h4', get_string('indicator_editor_management','local_kbacompany'), ['class'=>'bms-metric-editor-section-title']);
            echo html_writer::tag('p', get_string('indicator_editor_management_help','local_kbacompany'), ['class'=>'text-muted bms-metric-editor-section-help']);
            echo html_writer::start_div('bms-metric-management-grid');
            echo html_writer::div(html_writer::label(get_string('statperiod','local_kbacompany'),'metric_period') .
                html_writer::select($periodoptions,'periodtype',$m['periodtype'],false,['id'=>'metric_period','class'=>'form-control']), 'bms-metric-form-field');
            echo html_writer::div(html_writer::label(get_string('statdirection','local_kbacompany'),'metric_direction') .
                html_writer::select($directionoptions,'direction',$m['direction'],false,['id'=>'metric_direction','class'=>'form-control']), 'bms-metric-form-field');
            echo html_writer::div(html_writer::label(get_string('indicator_product_optional','local_kbacompany'),'metric_product') .
                html_writer::select($productopts,'productuuid',$m['productuuid'],false,['id'=>'metric_product','class'=>'form-control']), 'bms-metric-form-field');
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::start_div('bms-metric-editor-section');
            echo html_writer::tag('h4', get_string('indicator_editor_method','local_kbacompany'), ['class'=>'bms-metric-editor-section-title']);
            echo html_writer::tag('p', get_string('indicator_editor_method_help','local_kbacompany'), ['class'=>'text-muted bms-metric-editor-section-help']);
            echo html_writer::label(get_string('indicator_description','local_kbacompany'),'metric_desc');
            echo html_writer::tag('textarea',s($m['description']),['id'=>'metric_desc','name'=>'description','rows'=>5,'class'=>'form-control']);
            echo html_writer::end_div();

            echo html_writer::start_div('bms-metric-editor-section bms-metric-role-section');
            echo html_writer::tag('h4', get_string('indicator_editor_role','local_kbacompany'), ['class'=>'bms-metric-editor-section-title']);
            echo html_writer::div(
                html_writer::checkbox('maincompanymetric',1,!empty($m['maincompany']),
                    get_string('indicator_main_company_metric','local_kbacompany')) .
                html_writer::tag('small',get_string('indicator_main_company_metric_help','local_kbacompany'),['class'=>'text-muted d-block mt-1']),
                'bms-metric-primary-toggle');
            echo html_writer::end_div();

            echo html_writer::start_tag('details',['class'=>'bms-metric-editor-section bms-metric-advanced','id'=>'metric-formula']);
            echo html_writer::tag('summary',
                html_writer::span(get_string('indicator_card_formula','local_kbacompany'), 'bms-metric-editor-section-title') .
                html_writer::span(get_string('indicator_editor_source_help','local_kbacompany'), 'text-muted small d-block mt-1'));
            echo html_writer::start_div('bms-metric-management-grid mt-3');
            echo html_writer::div(html_writer::label(get_string('indicator_source','local_kbacompany'),'metric_source') .
                html_writer::select($sourceoptions,'sourcekind',$m['sourcekind'],false,['id'=>'metric_source','class'=>'form-control']), 'bms-metric-form-field');
            echo html_writer::div(html_writer::label(get_string('indicator_aggregation','local_kbacompany'),'metric_agg') .
                html_writer::select($aggregationoptions,'aggregation',$m['aggregation'],false,['id'=>'metric_agg','class'=>'form-control']), 'bms-metric-form-field');
            echo html_writer::end_div();
            echo html_writer::div(
                html_writer::label(get_string('indicator_source_config','local_kbacompany'),'metric_sourcecfg') .
                html_writer::tag('textarea',s($m['sourceconfig']),['id'=>'metric_sourcecfg','name'=>'sourceconfig','rows'=>4,'class'=>'form-control']),
                'bms-metric-source-config');
            echo html_writer::end_tag('details');

            echo html_writer::div(
                html_writer::div(get_string('indicator_editor_save_help','local_kbacompany'), 'text-muted small bms-metric-editor-savehelp') .
                html_writer::div(
                    $btn(get_string('savechanges'),'btn btn-success') .
                    html_writer::link(new moodle_url('/local/kbacompany/indicator_record.php',['uuid'=>$uuid,'stat'=>$m['uuid']]),
                        get_string('cancel'),['class'=>'btn btn-outline-secondary']),
                    'bms-metric-editor-actionbuttons'),
                'bms-metric-editor-actions');
            echo html_writer::end_tag('form');
        } else {
            echo html_writer::tag('p', get_string('indicator_readonly_profile','local_kbacompany'), ['class'=>'text-muted']);
        }
        echo html_writer::end_div(); echo html_writer::end_div();
    }
}

if ($tab === 'input') {
    $metricopts=[];foreach($data['metrics'] as $m){$metricopts[$m['uuid']]=$m['code'].' — '.$m['name'];}
    if($metricopts){
        $selector=new single_select(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'input']),'stat',$metricopts,$data['selected']['uuid']??'',null);
        $selector->label=get_string('statselect','local_kbacompany');echo $OUTPUT->render($selector);
    }
    if($data['selected']){
        $m=$data['selected'];echo $OUTPUT->heading(s($m['name']),3);
        echo html_writer::tag('p',s($m['unit']).' · '.get_string('stat'.$m['periodtype'],'local_kbacompany').' · '.get_string('stat'.$m['direction'],'local_kbacompany'));
        if($m['description']!==''){echo html_writer::tag('p',format_text($m['description'],FORMAT_PLAIN));}
        $series=$getseries($m['uuid'],52);$latest=$series?end($series):null;
        $facts=[];foreach($series as $r){if($r['fact']!==null){$facts[]=(float)$r['fact'];}}
        $lastfact=$latest&&$latest['fact']!==null?$latest['fact']:null;$lastplan=$latest&&$latest['plan']!==null?$latest['plan']:null;
        $metricbindings=[];foreach($data['bindings'] as $b){if($b['statuuid']===$m['uuid']){$metricbindings[]=$b;}}
        usort($metricbindings,static function($a,$b){$c=(int)empty($b['compatibility'])<=>(int)empty($a['compatibility']);if($c!==0){return $c;}$c=(int)$b['isprimary']<=>(int)$a['isprimary'];if($c!==0){return $c;}return (int)$b['critical']<=>(int)$a['critical'];});
        $primarybinding=$metricbindings?$metricbindings[0]:null;
        $currentsnapshot=$primarybinding?$controlsnapshot($m,$primarybinding,$series):['norm'=>null,'normlabel'=>'—','trend'=>null,'status'=>'nodata','performance'=>['status'=>'nodata','reason'=>'no_target','source'=>'','variance'=>null,'variance_percent'=>null],'analysis'=>indicator_trend_service::analyze($series,(string)$m['direction']),'events'=>indicator_event_service::latest($series,(string)$m['direction']),'condition'=>indicator_condition_service::analyze($series,(string)$m['direction'])];
        $cards='';
        $cards.=html_writer::div(html_writer::tag('small',get_string('indicator_last_fact','local_kbacompany')).html_writer::tag('strong',$lastfact===null?'—':s($lastfact).' '.s($m['unit'])),'kba-indicator-kpi');
        $cards.=html_writer::div(html_writer::tag('small',get_string('indicator_last_plan','local_kbacompany')).html_writer::tag('strong',$lastplan===null?'—':s($lastplan).' '.s($m['unit'])),'kba-indicator-kpi');
        $cards.=html_writer::div(html_writer::tag('small',get_string('indicator_norm','local_kbacompany')).html_writer::tag('strong',s($currentsnapshot['normlabel'])),'kba-indicator-kpi');
        $trendlabel=$currentsnapshot['trend']===null?'—':(($currentsnapshot['trend']>0?'+':'').format_float((float)$currentsnapshot['trend'],1).'%');
        $cards.=html_writer::div(html_writer::tag('small',get_string('indicator_trend','local_kbacompany')).html_writer::tag('strong',s($trendlabel)),'kba-indicator-kpi');
        $statuskey='metric_status_'.(string)($currentsnapshot['status']??'nodata');
        $cards.=html_writer::div(html_writer::tag('small',get_string('metric_period_status','local_kbacompany')).html_writer::tag('strong',s(get_string($statuskey,'local_kbacompany'))),'kba-indicator-kpi');
        $currentcondition=$currentsnapshot['condition'] ?? indicator_condition_service::analyze($series,(string)$m['direction']);
        $cards.=html_writer::div(html_writer::tag('small',get_string('indicator_condition','local_kbacompany')).
            html_writer::tag('strong',s($conditionlabel((string)($currentcondition['condition'] ?? '')))),'kba-indicator-kpi');
        echo html_writer::div($cards,'kba-indicator-kpis');
        echo html_writer::div(get_string('metric_status_rule','local_kbacompany'), 'alert alert-light border small');
        $analysis=$currentsnapshot['analysis'] ?? indicator_trend_service::analyze($series,(string)$m['direction']);
        $trenddetail=html_writer::span(get_string('indicator_trend_movement','local_kbacompany').': '.s($trendmovementlabel((string)$analysis['movement'])), 'mr-3');
        $trenddetail.=html_writer::span(get_string('indicator_trend_quality','local_kbacompany').': '.s($trendqualitylabel((string)$analysis['quality'])), 'mr-3');
        if((int)$analysis['streak']>1){$trenddetail.=html_writer::span(get_string('indicator_trend_streak','local_kbacompany').': '.s(get_string('indicator_trend_periods','local_kbacompany',(int)$analysis['streak'])), 'mr-3');}
        echo html_writer::div($trenddetail,'card card-body kba-indicator-trend-detail mb-2');
        $events=$currentsnapshot['events'] ?? indicator_event_service::latest($series,(string)$m['direction']);
        echo html_writer::div(html_writer::tag('strong',get_string('indicator_events_title','local_kbacompany')).
            html_writer::tag('div',s($eventlabels($events)),['class'=>'mt-1']),'card card-body kba-indicator-events mb-3');
        $conditionbody = html_writer::tag('strong', get_string('indicator_condition','local_kbacompany').': '.
            s($conditionlabel((string)($currentcondition['condition'] ?? ''))));
        $conditionbody .= html_writer::tag('div', s($conditionreasonlabel((string)($currentcondition['reason'] ?? 'insufficient'))),
            ['class'=>'text-muted mt-1']);
        if (!empty($currentcondition['condition'])) {
            $conditionbody .= html_writer::tag('h5', get_string('indicator_condition_action_title','local_kbacompany'), ['class'=>'mt-3']);
            $conditionbody .= $conditionactions((string)$currentcondition['condition']);
        }
        echo html_writer::div($conditionbody, 'card card-body kba-indicator-condition mb-3');
        $hbuttons='';foreach([13,26,52] as $periods){$hbuttons.=html_writer::link(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'input','stat'=>$m['uuid'],'horizon'=>$periods]),$periods.' '.get_string('indicator_periods_short','local_kbacompany'),['class'=>'btn '.($horizon===$periods?'btn-success':'btn-outline-secondary').' btn-sm mr-1']);}
        echo html_writer::div($hbuttons,'mb-2');
        $chartseries=count($series)>$horizon?array_slice($series,-$horizon):$series;
        $chart=$svgchart($chartseries);if($chart!==''){echo html_writer::div($chart,'card card-body mb-3');}
        if(count(array_filter($chartseries, static function($r){return $r['fact']!==null || $r['plan']!==null;}))===1){
            echo html_writer::div(get_string('metric_single_point_hint','local_kbacompany'),'alert alert-info small');
        }
        if($metricbindings){
            echo html_writer::start_div('card card-body mb-3');
            echo html_writer::tag('strong',get_string('indicator_business_contexts','local_kbacompany'));
            $items=[];foreach($metricbindings as $binding){$label=$contextlabel($binding);$url=null;if($binding['contextkind']==='position'){$url=new moodle_url('/local/kbacompany/position_os.php',['uuid'=>$uuid,'position'=>$binding['contextuuid']]);}else if($binding['contextkind']==='strategygoal'){$url=new moodle_url('/local/kbacompany/strategy.php',['uuid'=>$uuid]);}else if($binding['contextkind']==='orgnode'){$url=new moodle_url('/local/kbacompany/organization.php',['uuid'=>$uuid]);}else if($binding['contextkind']==='company'){$url=new moodle_url('/local/kbacompany/bms_workspace.php',['uuid'=>$uuid]);}$items[]=$url?html_writer::link($url,s($label)):s($label);}
            echo html_writer::alist($items);
            echo html_writer::end_div();
        }
        if($data['can_manage']){
            echo html_writer::start_tag('details',['class'=>'card mb-3']);echo html_writer::tag('summary',get_string('statrecord','local_kbacompany'),['class'=>'card-header']);echo html_writer::start_div('card-body');
            echo html_writer::start_tag('form',['method'=>'post','class'=>'kbacompany-form']);echo $hidden('value');echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'statuuid','value'=>$m['uuid']]);
            echo html_writer::div(get_string('metric_input_help', 'local_kbacompany'), 'alert alert-info small');
            echo html_writer::label(get_string('statperiodstart','local_kbacompany'),'value_period');echo html_writer::empty_tag('input',['type'=>'date','id'=>'value_period','name'=>'periodstart','class'=>'form-control mb-2','required'=>'required','value'=>date('Y-m-d')]);
            echo html_writer::label(get_string('statkind','local_kbacompany'),'value_kind');echo html_writer::select(['fact'=>get_string('statfact','local_kbacompany'),'plan'=>get_string('statplan','local_kbacompany')],'kind','fact',false,['id'=>'value_kind','class'=>'form-control mb-2']);
            echo html_writer::label(get_string('statamount','local_kbacompany'),'value_amount');echo html_writer::empty_tag('input',['type'=>'number','step'=>'0.000001','id'=>'value_amount','name'=>'amount','class'=>'form-control mb-2','required'=>'required']);
            echo html_writer::label(get_string('statreason','local_kbacompany'),'value_reason');echo html_writer::empty_tag('input',['id'=>'value_reason','name'=>'reason','class'=>'form-control mb-2']);
            echo html_writer::div(get_string('metric_correction_help', 'local_kbacompany'),'text-muted small mb-2');
            echo $btn(get_string('statrecord','local_kbacompany'));echo html_writer::end_tag('form');echo html_writer::end_div();echo html_writer::end_tag('details');
        }
        $table=new html_table();$table->head=[get_string('statperiodstart','local_kbacompany'),get_string('statplan','local_kbacompany'),get_string('statfact','local_kbacompany'),get_string('statdifference','local_kbacompany'),get_string('metric_period_status','local_kbacompany')];
        foreach(array_reverse($series) as $r){
            $diff=($r['fact']===null||$r['plan']===null)?null:(float)$r['fact']-(float)$r['plan'];
            $rownorm=$primarybinding?$normfor((string)$primarybinding['uuid'],(string)$r['period']):null;
            $rowstatus=indicator_performance_status_service::assess(
                $r['fact']===null?null:(float)$r['fact'],
                $r['plan']===null?null:(float)$r['plan'],
                $rownorm,
                (string)$m['direction'],
                $primarybinding?!empty($primarybinding['critical']):false,
                'insufficient'
            );
            $statuslabel=get_string('metric_status_'.(string)$rowstatus['status'],'local_kbacompany');
            $table->data[]=[s($r['period']),$r['plan']===null?'—':s($r['plan']),$r['fact']===null?'—':s($r['fact']),$diff===null?'—':format_float($diff,2),s($statuslabel)];
        }
        echo $series?html_writer::table($table):$OUTPUT->notification(get_string('statempty','local_kbacompany'),'info');
        // Immutable per-metric revision log. Always scope by company and metric; never use a cross-company lookup.
        $logwhere = 'companyuuid = :company AND statuuid = :metric';
        $logparams = ['company'=>$uuid, 'metric'=>$m['uuid']];
        if ($historydate !== '') { $logwhere .= ' AND periodstart = :period'; $logparams['period'] = $historydate; }
        $logrows = $DB->get_records_select('local_kbacompany_statval', $logwhere, $logparams,
            'periodstart DESC, kind ASC, revision DESC, id DESC', '*', 0, 100);
        $filterurl = new moodle_url('/local/kbacompany/indicators.php');
        $filter = html_writer::start_tag('form', ['method'=>'get','action'=>$filterurl->out(false),'class'=>'form-inline mb-3']);
        foreach (['uuid'=>$uuid,'tab'=>'input','stat'=>$m['uuid']] as $field=>$value) {
            $filter .= html_writer::empty_tag('input',['type'=>'hidden','name'=>$field,'value'=>$value]);
        }
        $filter .= html_writer::label(get_string('metric_history_filter','local_kbacompany'),'metric_history_date',false,['class'=>'mr-2']);
        $filter .= html_writer::empty_tag('input',['type'=>'date','name'=>'historydate','id'=>'metric_history_date',
            'value'=>$historydate,'class'=>'form-control mr-2']);
        $filter .= html_writer::tag('button',get_string('metric_history_apply','local_kbacompany'),
            ['type'=>'submit','class'=>'btn btn-outline-secondary mr-2']);
        $filter .= html_writer::link(new moodle_url('/local/kbacompany/indicators.php',
            ['uuid'=>$uuid,'tab'=>'input','stat'=>$m['uuid']]),get_string('metric_history_reset','local_kbacompany'));
        $filter .= html_writer::end_tag('form');
        $historybody = $filter;
        if ($logrows) {
            $writerids = [];
            foreach ($logrows as $logrow) { if ((int)$logrow->writerid > 0) { $writerids[(int)$logrow->writerid] = (int)$logrow->writerid; } }
            $writers = $writerids ? $DB->get_records_list('user','id',array_values($writerids),'','id,firstname,lastname') : [];
            $historytable = new html_table();
            $historytable->head = [get_string('statperiodstart','local_kbacompany'),
                get_string('statkind','local_kbacompany'),get_string('metric_history_change','local_kbacompany'),
                get_string('metric_history_author','local_kbacompany'),get_string('metric_history_date','local_kbacompany'),
                get_string('statreason','local_kbacompany')];
            $previous = [];
            foreach ($logrows as $logrow) {
                $key = $logrow->periodstart.'|'.$logrow->kind;
                // Records are sorted newest revision first. The next row supplies its predecessor value.
                if (!isset($previous[$key])) { $previous[$key] = []; }
                $previous[$key][] = $logrow;
            }
            foreach ($logrows as $logrow) {
                $key = $logrow->periodstart.'|'.$logrow->kind;
                $change = format_float((float)$logrow->amount,2);
                $position = array_search($logrow,$previous[$key],true);
                if ($position !== false && isset($previous[$key][$position+1])) {
                    $change = format_float((float)$previous[$key][$position+1]->amount,2).' → '.$change;
                } else if ((int)$logrow->revision > 1) {
                    // Previous revision might be outside the 100-row window.
                    $old = $DB->get_record_select('local_kbacompany_statval',
                        'companyuuid=:c AND statuuid=:s AND periodstart=:p AND kind=:k AND revision=:r',
                        ['c'=>$uuid,'s'=>$m['uuid'],'p'=>$logrow->periodstart,
                         'k'=>$logrow->kind,'r'=>(int)$logrow->revision-1],'id,amount');
                    if ($old) { $change = format_float((float)$old->amount,2).' → '.$change; }
                }
                $writer = isset($writers[(int)$logrow->writerid]) ? fullname($writers[(int)$logrow->writerid]) : '—';
                $kind = ($logrow->kind==='fact') ? get_string('statfact','local_kbacompany') : get_string('statplan','local_kbacompany');
                $historytable->data[] = [s($logrow->periodstart),s($kind),s($change),s($writer),
                    userdate((int)$logrow->timecreated, get_string('strftimedatetimeshort','langconfig')),
                    s(trim((string)$logrow->reason))];
            }
            $historybody .= html_writer::table($historytable);
            if (count($logrows) === 100) { $historybody .= html_writer::div(get_string('metric_history_limit','local_kbacompany'),'text-muted small'); }
        } else { $historybody .= html_writer::div(get_string('metric_history_empty','local_kbacompany'),'text-muted'); }
        // Explicit History action must open the history panel even when it is normally collapsed.
        $historyopen = optional_param('history', 0, PARAM_BOOL);
        $historyattrs = ['class'=>'card mb-3','id'=>'metric-history'];
        if ($historyopen) { $historyattrs['open'] = 'open'; }
        echo html_writer::start_tag('details', $historyattrs);
        echo html_writer::tag('summary',get_string('metric_history_title','local_kbacompany'),['class'=>'card-header']);
        echo html_writer::div($historybody,'card-body');
        echo html_writer::end_tag('details');

    } else {echo $OUTPUT->notification(get_string('indicator_empty','local_kbacompany'),'info');}
}

if ($tab === 'norms') {
    if($data['can_manage']){
        $editablebindings=[];foreach($data['bindings'] as $b){if(empty($b['compatibility'])){$m=$metricmap[$b['statuuid']]??null;if($m){$editablebindings[$b['uuid']]=$m['name'].' — '.$contextlabel($b);}}}
        if($editablebindings){
            echo html_writer::start_tag('details',['class'=>'card mb-3']);echo html_writer::tag('summary',get_string('indicator_add_norm','local_kbacompany'),['class'=>'card-header']);echo html_writer::start_div('card-body');
            echo html_writer::start_tag('form',['method'=>'post','class'=>'kbacompany-form']);echo $hidden('norm');
            echo html_writer::label(get_string('indicator_binding','local_kbacompany'),'norm_bind');echo html_writer::select($editablebindings,'binduuid','',false,['id'=>'norm_bind','class'=>'form-control mb-2','required'=>'required']);
            echo html_writer::label(get_string('indicator_norm_kind','local_kbacompany'),'norm_kind');echo html_writer::select(['target'=>get_string('indicator_norm_target','local_kbacompany'),'range'=>get_string('indicator_norm_range','local_kbacompany')],'normkind','target',false,['id'=>'norm_kind','class'=>'form-control mb-2']);
            foreach([['targetvalue','indicator_norm_target_value'],['minval','indicator_norm_min'],['maxval','indicator_norm_max']] as $f){echo html_writer::label(get_string($f[1],'local_kbacompany'),'norm_'.$f[0]);echo html_writer::empty_tag('input',['type'=>'number','step'=>'0.000001','id'=>'norm_'.$f[0],'name'=>$f[0],'class'=>'form-control mb-2']);}
            echo html_writer::label(get_string('indicator_norm_from','local_kbacompany'),'norm_from');echo html_writer::empty_tag('input',['type'=>'date','id'=>'norm_from','name'=>'effectivefrom','class'=>'form-control mb-2','required'=>'required']);
            echo html_writer::label(get_string('indicator_norm_to','local_kbacompany'),'norm_to');echo html_writer::empty_tag('input',['type'=>'date','id'=>'norm_to','name'=>'effectiveto','class'=>'form-control mb-2']);
            echo $btn(get_string('indicator_add_norm','local_kbacompany'));echo html_writer::end_tag('form');echo html_writer::end_div();echo html_writer::end_tag('details');
        }
    }
    $table=new html_table();$table->head=[get_string('indicator_metric','local_kbacompany'),get_string('indicator_binding_context','local_kbacompany'),get_string('indicator_norm','local_kbacompany'),get_string('indicator_norm_period','local_kbacompany'),get_string('indicator_actions','local_kbacompany')];
    foreach($data['norms'] as $norm){$binding=$bindingmap[$norm['binduuid']]??null;if(!$binding){continue;}$metric=$metricmap[$binding['statuuid']]??null;if(!$metric){continue;}$value=$norm['normkind']==='range'?s($norm['minval'].' — '.$norm['maxval']):s($norm['targetvalue']);$actions='';if($data['can_manage']){$actions=html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden('archivenorm').html_writer::empty_tag('input',['type'=>'hidden','name'=>'normuuid','value'=>$norm['uuid']]).$btn(get_string('indicator_archive_norm','local_kbacompany'),'btn btn-sm btn-outline-danger').html_writer::end_tag('form');}$table->data[]=[s($metric['name']),s($contextlabel($binding)),$value,s($norm['effectivefrom'].($norm['effectiveto']!==''?' — '.$norm['effectiveto']:'')),$actions];}
    echo $data['norms']?html_writer::table($table):$OUTPUT->notification(get_string('indicator_norm_empty','local_kbacompany'),'info');
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
