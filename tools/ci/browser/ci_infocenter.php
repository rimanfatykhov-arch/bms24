<?php
// CI-only helper for infocenter.php on the disposable Moodle (synthetic data only, issue #190). Phases:
//   setup <companyA> <companyB> -> a default organizing-board branch of company A with three divisions inserted out of
//                                  board order (one without metrics), a department and a position; weekly metrics bound
//                                  through the registry: main company metric (rising, plan, one missing week), position
//                                  (falling, direction down), department (single point), division (negative values and a
//                                  real zero; flat series; no values) and one unbound metric; company B gets its own
//                                  main metric. Earlier default branches of company A are recorded and un-flagged.
//   cleanup <companyA> <companyB> <prior default branch uuids, comma separated> -> removes every row created above and
//                                  restores the earlier default flags.
//   shell <owned|legacy>         -> switches the BMS24 shell mode of the disposable site.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');

$phase = $argv[2] ?? '';
$company = (string)($argv[3] ?? '');
$other = (string)($argv[4] ?? '');
set_exception_handler(static function (Throwable $error): void {
    $errorcode = $error instanceof moodle_exception ? (string)$error->errorcode : '';
    $code = preg_match('/^[a-zA-Z0-9_]+$/D', $errorcode) ? ' ' . $errorcode : '';
    // Keep database diagnostics and private fixture values out of the browser artifact.
    fwrite(STDERR, sprintf("CI infocenter fixture: %s%s in %s:%d\n",
        get_class($error), $code, basename($error->getFile()), $error->getLine()));
    exit(1);
});

const IC_BRANCH = '7c1c0000-0000-4000-8000-000000000001';
const IC_DIV1 = '7c1c0000-0000-4000-8000-000000000011';
const IC_DIV2 = '7c1c0000-0000-4000-8000-000000000012';
const IC_DIV3 = '7c1c0000-0000-4000-8000-000000000013';
const IC_DEPT = '7c1c0000-0000-4000-8000-000000000014';
const IC_POS = '7c1c0000-0000-4000-8000-000000000021';
// Metric uuid => [company key, code, name, unit, direction, bind kind, bind context key, primary].
const IC_METRICS = [
    '7c1c0000-0000-4000-8000-000000000101' => ['A', 'CIIC-M', 'CI revenue', 'RUB', 'up', 'company', 'A', 1],
    '7c1c0000-0000-4000-8000-000000000102' => ['A', 'CIIC-POS', 'CI defects', 'pcs', 'down', 'position', IC_POS, 0],
    '7c1c0000-0000-4000-8000-000000000103' => ['A', 'CIIC-DEP', 'CI single', 'pcs', 'up', 'orgnode', IC_DEPT, 0],
    '7c1c0000-0000-4000-8000-000000000104' => ['A', 'CIIC-NEG', 'CI balance', 'RUB', 'up', 'orgnode', IC_DIV2, 0],
    '7c1c0000-0000-4000-8000-000000000105' => ['A', 'CIIC-FLAT', 'CI flat', 'pcs', 'up', 'orgnode', IC_DIV2, 0],
    '7c1c0000-0000-4000-8000-000000000106' => ['A', 'CIIC-EMPTY', 'CI empty', 'pcs', 'up', 'orgnode', IC_DIV2, 0],
    '7c1c0000-0000-4000-8000-000000000107' => ['A', 'CIIC-LOOSE', 'CI unbound', 'pcs', 'up', '', '', 0],
    '7c1c0000-0000-4000-8000-000000000108' => ['B', 'CIIC-B', 'CI company B only', 'RUB', 'up', 'company', 'B', 1],
];

/** Monday of week $i counted from 2026-01-05 (weekly periods). */
function ic_week(int $i): string {
    return (new DateTimeImmutable('2026-01-05', new DateTimeZone('UTC')))->modify('+' . (7 * $i) . ' days')->format('Y-m-d');
}

function ic_series(string $code): array {
    $out = [];
    if ($code === 'CIIC-M') {
        for ($i = 0; $i <= 20; $i++) {
            if ($i === 15) { continue; } // One missing week: a gap, never a zero.
            $out[] = [ic_week($i), 100 + 5 * $i, 103 + 5 * $i];
        }
    } else if ($code === 'CIIC-POS') {
        foreach ([50, 48, 47, 45, 43, 41, 40, 38] as $i => $v) { $out[] = [ic_week(12 + $i), $v, null]; }
    } else if ($code === 'CIIC-DEP') {
        $out[] = [ic_week(19), 7, null];
    } else if ($code === 'CIIC-NEG') {
        foreach ([-6, -3, 0, 2, -1, 4] as $i => $v) { $out[] = [ic_week(14 + $i), $v, null]; }
    } else if ($code === 'CIIC-FLAT') {
        for ($i = 0; $i < 6; $i++) { $out[] = [ic_week(14 + $i), 1000, null]; }
    } else if ($code === 'CIIC-LOOSE' || $code === 'CIIC-B') {
        foreach ([3, 4, 6] as $i => $v) { $out[] = [ic_week(17 + $i), $v, null]; }
    }
    return $out;
}

function ic_cleanup(string $company, string $other): void {
    global $DB;
    $stats = array_keys(IC_METRICS);
    foreach ([$company, $other] as $c) {
        if ($c === '') { continue; }
        [$in, $params] = $DB->get_in_or_equal($stats, SQL_PARAMS_NAMED);
        $params['c'] = $c;
        $DB->delete_records_select('local_kbacompany_statval', "companyuuid = :c AND statuuid $in", $params);
        $DB->delete_records_select('local_kbacompany_statbind', "companyuuid = :c AND statuuid $in", $params);
        $DB->delete_records_select('local_kbacompany_stat', "companyuuid = :c AND uuid $in", $params);
    }
    $DB->delete_records('local_kbacompany_position', ['companyuuid' => $company, 'uuid' => IC_POS]);
    foreach ([IC_DIV1, IC_DIV2, IC_DIV3, IC_DEPT] as $node) {
        $DB->delete_records('local_kbacompany_orgnode', ['companyuuid' => $company, 'uuid' => $node]);
    }
    $DB->delete_records('local_kbacompany_orgbranch', ['companyuuid' => $company, 'uuid' => IC_BRANCH]);
}

$out = ['phase' => $phase];
if ($phase === 'setup') {
    $now = time();
    ic_cleanup($company, $other);
    $prior = array_values($DB->get_fieldset_select('local_kbacompany_orgbranch', 'uuid',
        'companyuuid = :c AND isdefault = 1', ['c' => $company]));
    foreach ($prior as $branch) {
        $DB->set_field('local_kbacompany_orgbranch', 'isdefault', 0, ['companyuuid' => $company, 'uuid' => $branch]);
    }
    $DB->insert_record('local_kbacompany_orgbranch', (object)['companyuuid' => $company, 'uuid' => IC_BRANCH,
        'code' => 'CIIC', 'name' => 'CI information centre', 'isdefault' => 1, 'active' => 1, 'sortorder' => 0,
        'timecreated' => $now, 'timemodified' => $now]);
    // Inserted out of board order on purpose: the columns follow sortorder, not insertion.
    $nodes = [[IC_DIV2, '', 'division', 'CIIC 2', 'CI second division', 30, '#1777B5'],
        [IC_DIV3, '', 'division', 'CIIC 3', 'CI third division', 40, '#6B43B5'],
        [IC_DIV1, '', 'division', 'CIIC 1', 'CI first division', 20, '#0C6C52'],
        [IC_DEPT, IC_DIV1, 'department', 'CIIC 1.1', 'CI department', 10, '']];
    foreach ($nodes as [$uuid, $parent, $type, $code, $name, $sort, $color]) {
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => IC_BRANCH,
            'uuid' => $uuid, 'parentuuid' => $parent, 'code' => $code, 'nodetype' => $type, 'name' => $name,
            'sortorder' => $sort, 'color' => $color, 'sourcecomponent' => 'local_kbacompany', 'sourceentity' => 'ci_infocenter',
            'sourceid' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
    }
    $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => IC_BRANCH,
        'orgnodeuuid' => IC_DEPT, 'uuid' => IC_POS, 'code' => 'CIIC-P', 'name' => 'CI quality lead', 'purpose' => '',
        'ismanager' => 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
    $n = 0;
    $out['metrics'] = [];
    foreach (IC_METRICS as $uuid => [$key, $code, $name, $unit, $direction, $kind, $context, $primary]) {
        $c = $key === 'A' ? $company : $other;
        $DB->insert_record('local_kbacompany_stat', (object)['companyuuid' => $c, 'uuid' => $uuid, 'productuuid' => '',
            'code' => $code, 'name' => $name, 'unit' => $unit, 'periodtype' => 'weekly', 'direction' => $direction,
            'timecreated' => $now]);
        if ($kind !== '') {
            $n++;
            $DB->insert_record('local_kbacompany_statbind', (object)['companyuuid' => $c,
                'uuid' => sprintf('7c1c0000-0000-4000-8000-%012d', 200 + $n), 'revision' => 1, 'status' => 'active',
                'statuuid' => $uuid, 'contextkind' => $kind,
                'contextuuid' => $kind === 'company' ? $c : $context, 'contextrevision' => 0, 'isprimary' => $primary,
                'critical' => 0, 'weight' => 1, 'sortorder' => 0, 'createdby' => 2, 'timecreated' => $now - 3600]);
        }
        $series = ic_series($code);
        foreach ($series as [$period, $fact, $plan]) {
            foreach (['fact' => $fact, 'plan' => $plan] as $valuekind => $amount) {
                if ($amount === null) { continue; }
                $DB->insert_record('local_kbacompany_statval', (object)['companyuuid' => $c, 'statuuid' => $uuid,
                    'periodstart' => $period, 'kind' => $valuekind, 'amount' => $amount, 'revision' => 1,
                    'reason' => 'CI synthetic', 'writerid' => 2, 'timecreated' => $now]);
            }
        }
        $out['metrics'][$code] = ['uuid' => $uuid, 'company' => $key, 'name' => $name, 'unit' => $unit,
            'direction' => $direction, 'kind' => $kind,
            'periods' => array_column($series, 0), 'facts' => array_column($series, 1)];
    }
    $out['prior_defaults'] = $prior;
    $out['divisions'] = ['CIIC 1', 'CIIC 2', 'CIIC 3'];
} else if ($phase === 'cleanup') {
    ic_cleanup($company, $other);
    foreach (array_filter(explode(',', (string)($argv[5] ?? ''))) as $branch) {
        $DB->set_field('local_kbacompany_orgbranch', 'isdefault', 1, ['companyuuid' => $company, 'uuid' => $branch]);
    }
    $out['clean'] = !$DB->record_exists('local_kbacompany_orgbranch', ['companyuuid' => $company, 'uuid' => IC_BRANCH]);
} else if ($phase === 'shell' && in_array($argv[3] ?? '', ['owned', 'legacy'], true)) {
    set_config('bms24_shell_mode', $argv[3], 'local_kbacompany');
    $out['mode'] = get_config('local_kbacompany', 'bms24_shell_mode');
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
