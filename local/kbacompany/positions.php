<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\position_service as service;
use local_kbacompany\local\position_ui_service as ui;
use local_kbacompany\local\document_request_service as requests;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\position_indicator_assignment_service as position_indicators;
use local_kbacompany\local\position_operating_service;
use local_kbacompany\local\position_competency_profile_service;
use local_kbacompany\local\function_transfer_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
company_context_service::context($uuid, $actor);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) { throw new moodle_exception('pdu_method', 'local_kbacompany'); }
$positionuuid = optional_param('position', '', PARAM_RAW_TRIMMED);
$view = optional_param('view', $positionuuid === '' ? 'list' : 'detail', PARAM_ALPHA);
$legacyaction = optional_param('action', '', PARAM_ALPHA);
if ($method !== 'POST' && $legacyaction !== '') {
    // Old action URLs are navigation only. Never mutate from a GET link.
    if ($legacyaction === 'create') { redirect(ui::url($uuid, ['view' => 'create'])); }
    throw new moodle_exception('pdu_method', 'local_kbacompany');
}
if (!in_array($view, ['list', 'detail', 'create', 'edit', 'delete', 'restore'], true)) { throw new moodle_exception('pdu_not_found', 'local_kbacompany'); }
if ($method === 'POST' && !in_array($view, ['create', 'edit', 'delete', 'restore'], true) &&
        !($view === 'detail' && optional_param('tab', 'overview', PARAM_ALPHA) === 'indicators')) {
    throw new moodle_exception('pdu_expired', 'local_kbacompany');
}
$model = null; $extra = []; $values = []; $proof = []; $errors = [];
$tab = optional_param('tab', 'overview', PARAM_ALPHA);
if (!in_array($tab, ['overview', 'ckp', 'functions', 'indicators', 'competencies', 'authorities', 'processes', 'learning', 'staffing', 'history'], true)) { $tab = 'overview'; }
$page = optional_param('page', 0, PARAM_INT);
if (in_array($view, ['detail', 'edit'], true)) { $model = service::directory_record($uuid, $actor, $positionuuid); }
if (in_array($view, ['delete', 'restore'], true)) {
    $model = service::directory_lifecycle_preview($uuid, $actor, $positionuuid, $view);
    $reason = $view === 'restore' ? 'restore' : '';
    $confirmed = false;
    if ($method === 'POST') {
        require_sesskey();
        if (required_param('action', PARAM_ALPHA) !== $view) { throw new moodle_exception('pdu_method', 'local_kbacompany'); }
        $reason = required_param('reason', PARAM_ALPHAEXT);
        $confirmed = optional_param('confirmed', 0, PARAM_INT) === 1;
        $key = required_param('requestkey', PARAM_ALPHANUM);
        $request = requests::verify($key, $uuid, $actor, 'position:' . $positionuuid, 0, 'position_' . $view);
        $digest = requests::digest(['operation' => $view, 'reason' => $reason, 'confirmed' => $confirmed]);
        $destination = requests::replay($request, $digest);
        if ($destination !== null) { redirect(ui::url($uuid, $destination)); }
        try {
            service::directory_lifecycle($uuid, $actor, $positionuuid, $view, $request['expected'], $reason, $confirmed);
            $destination = $view === 'delete' ? ['view' => 'list', 'status' => 'active'] : ['position' => $positionuuid];
            requests::complete($key, $digest, $destination);
        } catch (moodle_exception $e) {
            if (!in_array($e->errorcode, ['pdu_busy', 'pdu_stale', 'pdl_in_use', 'pdl_reason_required', 'pdl_confirm_required'], true)) { throw $e; }
            $errors['_domain'] = ui::tr($e->errorcode);
        }
        if ($destination !== null) { redirect(ui::url($uuid, $destination), ui::tr($view === 'delete' ? 'pdl_deleted' : 'pdl_restored'), null, \core\output\notification::NOTIFY_SUCCESS); }
        $proof = ['requestkey' => $key];
        $model = service::directory_lifecycle_preview($uuid, $actor, $positionuuid, $view);
    } else {
        $proof = requests::issue($uuid, $actor, 'position:' . $positionuuid, 0, 'position_' . $view, $model['expected']);
    }
    $content = ui::lifecycle_form($uuid, $model, $proof, $errors, $reason, $confirmed);
} else if (in_array($view, ['create', 'edit'], true)) {
    if (!service::directory_can_manage($uuid, $actor)) { throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', ''); }
    $creating = $view === 'create';
    $values = $creating ? ['name' => '', 'purpose' => '', 'orgnodeuuid' => optional_param('node', '', PARAM_RAW_TRIMMED), 'ismanager' => '0', 'sortorder' => '0'] :
        array_intersect_key($model['position'], array_flip(['name', 'purpose', 'ismanager', 'sortorder']));
    $identity = $creating ? '' : 'position:' . $positionuuid;
    $unitq = optional_param('unitq', '', PARAM_TEXT);
    if ($method === 'POST') {
        require_sesskey();
        $action = required_param('action', PARAM_ALPHA);
        if (!in_array($action, ['save', 'unitsearch'], true) || (!$creating && $action === 'unitsearch')) { throw new moodle_exception('pdu_method', 'local_kbacompany'); }
        foreach (array_keys($values) as $key) { $values[$key] = optional_param($key, '', PARAM_RAW_TRIMMED); }
        // Read-only attributes are never silently accepted from crafted payloads.
        foreach (['code', 'orgnodeuuid', 'branchuuid', 'active'] as $key) {
            if (isset($_POST[$key])) { $values[$key] = optional_param($key, '', PARAM_RAW_TRIMMED); }
        }
        $key = required_param('requestkey', PARAM_ALPHANUM);
        $request = requests::verify($key, $uuid, $actor, $identity, 0, 'position_' . $view);
        $digest = requests::digest($values);
        $destination = requests::replay($request, $digest);
        if ($destination !== null) { redirect(ui::url($uuid, $destination)); }
        if ($action === 'save') {
            $errors = service::directory_form_errors($uuid, $actor, $values, $creating);
            if (!$errors) {
                try {
                    $saved = service::directory_save($uuid, $actor, $creating ? '' : $positionuuid, $values, $request['expected']);
                    $destination = ['position' => $saved];
                    requests::complete($key, $digest, $destination);
                } catch (moodle_exception $e) {
                    if (!in_array($e->errorcode, ['pdu_invalid', 'pdu_busy', 'pdu_stale', 'pdu_readonly', 'orgnoderequired', 'stablecode_lock'], true)) { throw $e; }
                    $errors['_domain'] = ui::tr($e->errorcode);
                }
                if ($destination !== null) { redirect(ui::url($uuid, $destination), ui::tr('pdu_saved'), null, \core\output\notification::NOTIFY_SUCCESS); }
            }
        }
        $proof = ['requestkey' => $key];
    } else {
        if ($model && !$model['editable']) { throw new moodle_exception('pdu_stale', 'local_kbacompany'); }
        $proof = requests::issue($uuid, $actor, $identity, 0, 'position_' . $view, $model['expected'] ?? '');
    }
    $units = $creating ? service::directory_units($uuid, $actor, $unitq, (string)($values['orgnodeuuid'] ?? '')) : ['options' => [], 'query' => '', 'total' => 0, 'limited' => false];
    $content = ui::form($uuid, $creating, $values, $errors, $proof, $units, $model);
} else if ($view === 'detail') {
    if ($tab === 'indicators') {
        if ($method === 'POST') {
            require_sesskey();
            $indicatoraction = required_param('indicatoraction', PARAM_ALPHA);
            if ($indicatoraction === 'save') {
                $binduuid = optional_param('binduuid', '', PARAM_RAW_TRIMMED);
                $data = [
                    'statuuid' => required_param('statuuid', PARAM_RAW_TRIMMED),
                    'isprimary' => optional_param('isprimary', 0, PARAM_INT),
                    'critical' => optional_param('critical', 0, PARAM_INT),
                    'weight' => optional_param('weight', '1', PARAM_RAW_TRIMMED),
                    'sortorder' => optional_param('sortorder', 0, PARAM_INT),
                    'normkind' => optional_param('normkind', '', PARAM_ALPHA),
                    'targetvalue' => optional_param('targetvalue', '', PARAM_RAW_TRIMMED),
                    'minval' => optional_param('minval', '', PARAM_RAW_TRIMMED),
                    'maxval' => optional_param('maxval', '', PARAM_RAW_TRIMMED),
                    'effectivefrom' => optional_param('effectivefrom', date('Y-m-d'), PARAM_RAW_TRIMMED),
                    'effectiveto' => optional_param('effectiveto', '', PARAM_RAW_TRIMMED),
                ];
                position_indicators::save($uuid, $actor, $positionuuid, $data, $binduuid);
                redirect(ui::url($uuid, ['position' => $positionuuid, 'tab' => 'indicators']),
                    get_string('positionindicator_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
            } else if ($indicatoraction === 'archive') {
                $binduuid = required_param('binduuid', PARAM_RAW_TRIMMED);
                position_indicators::archive($uuid, $actor, $positionuuid, $binduuid);
                redirect(ui::url($uuid, ['position' => $positionuuid, 'tab' => 'indicators']),
                    get_string('positionindicator_archived', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                throw new moodle_exception('pdu_method', 'local_kbacompany');
            }
        }
        $extra = position_indicators::context($uuid, $actor, $positionuuid);
    }
    if ($tab === 'overview' && !empty($model['position']['active'])) {
        $os = position_operating_service::context($uuid, $actor, $positionuuid);
        $competencies = position_competency_profile_service::context($uuid, $actor, $positionuuid);
        $metrics = position_indicators::context($uuid, $actor, $positionuuid);
        $staffing = service::directory_staffing($uuid, $actor, $positionuuid, 0);
        $processes = [];
        foreach ($os['functions'] as $function) {
            $processuuid = (string)($function['source_process_uuid'] ?? '');
            if ($processuuid === '') { continue; }
            if (!isset($processes[$processuuid])) {
                $processes[$processuuid] = ['uuid' => $processuuid, 'name' => (string)($function['source_process_name'] ?? ''), 'functions' => 0];
            }
            $processes[$processuuid]['functions']++;
        }
        $extra = [
            'summary' => [
                'ckp' => count($os['ckp']),
                'functions' => count($os['functions']),
                'metrics' => count($metrics['rows']),
                'competencies' => (int)$competencies['summary']['requirements'],
                'processes' => count($processes),
                'staffing' => (int)$staffing['total'],
            ],
        ];
    }
    if ($tab === 'ckp' && !empty($model['position']['active'])) {
        $os = position_operating_service::context($uuid, $actor, $positionuuid);
        $extra = ['rows' => $os['ckp'], 'can_manage' => $os['can_manage']];
    }
    if ($tab === 'functions') { $extra = service::directory_functions($uuid, $actor, $positionuuid, $page); }
    if ($tab === 'competencies' && !empty($model['position']['active'])) {
        $extra = position_competency_profile_service::context($uuid, $actor, $positionuuid);
    }
    if ($tab === 'authorities' && !empty($model['position']['active'])) {
        $authority = function_transfer_service::context($uuid, $actor);
        $rows = [];
        foreach ($authority['transfers'] as $transfer) {
            if (($transfer['source_position_uuid'] ?? '') === $positionuuid || ($transfer['target_position_uuid'] ?? '') === $positionuuid) {
                $rows[] = $transfer;
            }
        }
        $extra = ['rows' => $rows, 'can_manage' => $authority['can_manage']];
    }
    if ($tab === 'processes' && !empty($model['position']['active'])) {
        $os = position_operating_service::context($uuid, $actor, $positionuuid);
        $rows = [];
        foreach ($os['functions'] as $function) {
            $processuuid = (string)($function['source_process_uuid'] ?? '');
            if ($processuuid === '') { continue; }
            if (!isset($rows[$processuuid])) {
                $rows[$processuuid] = [
                    'uuid' => $processuuid,
                    'name' => (string)($function['source_process_name'] ?? ''),
                    'functions' => [],
                ];
            }
            $rows[$processuuid]['functions'][] = (string)$function['code'] . ' — ' . (string)$function['name'];
        }
        $extra = ['rows' => array_values($rows)];
    }
    if ($tab === 'learning' && !empty($model['position']['active'])) {
        $extra = position_competency_profile_service::context($uuid, $actor, $positionuuid);
    }
    if ($tab === 'staffing') { $extra = service::directory_staffing($uuid, $actor, $positionuuid, $page); }
    if ($tab === 'history') { $extra = service::directory_history($uuid, $actor, $positionuuid); }
    $content = ui::detail($uuid, $model, $tab, $extra);
} else {
    $filters = [];
    foreach (['q', 'status', 'manager', 'node'] as $key) { $filters[$key] = optional_param($key, '', PARAM_RAW_TRIMMED); }
    $model = service::directory_browse($uuid, $actor, $filters, $page);
    $units = service::directory_units($uuid, $actor, '', $model['filters']['node'], false);
    $content = ui::listing($uuid, $model, $units);
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid, ['view' => $view] + ($positionuuid !== '' ? ['position' => $positionuuid, 'tab' => $tab] : [])));
$PAGE->set_title(ui::tr('positiondirectory'));
$PAGE->set_heading(ui::tr('pluginname'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'positions', ui::tr('positiondirectory'));
echo html_writer::div($content, 'bms-pdu');
\local_kbacompany\local\bms_ai_workspace_service::render($uuid, 'position', 'Должности', in_array($view, ['create', 'edit'], true));
bms_page_shell_service::finish($shell);
