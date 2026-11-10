<?php
// Stage 1825: read-only Corporate / BMS integration and Kanban acceptance.
// Run only with PHP CLI from an installed Moodle; never expose this as a web route.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacorporate\local\bridge;
use local_kbacorporate\local\implementation_board_query as board;
use local_kbacorporate\local\implementation_acceptance_metrics as metrics;

[$opt, $unused] = cli_get_params([
    'help' => false, 'companyid' => 0, 'actorid' => 0,
    'othercompanyid' => 0, 'otheractorid' => 0,
    'bmsuuid' => '', 'runs' => 10,
], ['h' => 'help']);
if ($opt['help']) {
    echo "Read-only stage 1825 acceptance.\n";
    echo "php local/kbacorporate/cli/acceptance_integration_1825.php --companyid=ID --actorid=ID \\\n";
    echo "  [--othercompanyid=ID --otheractorid=ID] [--bmsuuid=UUID] [--runs=10]\n";
    echo "Actor must be an assigned curator or authorised Corporate portfolio auditor.\n";
    exit(0);
}

$companyid = (int)$opt['companyid'];
$actorid = (int)$opt['actorid'];
$othercompanyid = (int)$opt['othercompanyid'];
$otheractorid = (int)$opt['otheractorid'];
$runs = (int)$opt['runs'];
$bmsuuid = trim((string)$opt['bmsuuid']);
if ($companyid < 1 || $actorid < 1 || $runs < 2 || $runs > 20 ||
        $othercompanyid < 0 || $otheractorid < 0 ||
        ($othercompanyid > 0 && $othercompanyid === $companyid)) {
    cli_error('Invalid options. Run with --help for usage.');
}
if (!$DB->record_exists('local_kbacorp_company', ['id' => $companyid, 'active' => 1]) ||
        !$DB->record_exists('user', ['id' => $actorid, 'deleted' => 0, 'suspended' => 0])) {
    cli_error('Active company and user are required.');
}
if ($othercompanyid > 0 && !$DB->record_exists('local_kbacorp_company',
        ['id' => $othercompanyid, 'active' => 1])) {
    cli_error('The second company is not active or does not exist.');
}
if ($otheractorid > 0 && !$DB->record_exists('user',
        ['id' => $otheractorid, 'deleted' => 0, 'suspended' => 0])) {
    cli_error('The second user is not active or does not exist.');
}

$checks = [];
$check = static function(string $name, string $status, string $details = '') use (&$checks): void {
    $checks[] = ['check' => $name, 'status' => $status, 'note' => $details];
};
$attempt = static function(string $name, callable $action) use ($check) {
    try {
        $passed = $action();
        $check($name, $passed === true ? 'PASS' : 'FAIL');
        return $passed === true;
    } catch (\Throwable $e) {
        // Do not print exception messages: Moodle DB errors can contain SQL and user data.
        $check($name, 'FAIL', 'Execution error: ' . get_class($e));
        return false;
    }
};

$allcurators = has_capability('local/kbacorporate:manageimplementationaudits',
    \context_system::instance(), $actorid);
$assigned = $DB->record_exists('local_kbacorp_impl_program',
    ['companyid' => $companyid, 'curatorid' => $actorid]);
if (!$assigned && !$allcurators) {
    cli_error('Actor has no curator portfolio or auditing rights. No checks executed.');
}

$attempt('Corporate bridge API >= 1.26', static function(): bool {
    return version_compare((string)bridge::API_RELEASE, '1.26', '>=');
});
$attempt('Corporate 0.40.13 installed', static function(): bool {
    return (int)get_config('local_kbacorporate', 'version') >= 2026102613;
});

$summary = null;
$attempt('Status totals match overall count', static function() use ($actorid, $allcurators,
        $companyid, &$summary): bool {
    $summary = board::summary($actorid, $allcurators, ['companyid' => $companyid]);
    return isset($summary['counts'], $summary['total']) &&
        array_sum($summary['counts']) === (int)$summary['total'] &&
        count($summary['counts']) === count(board::STATUSES);
});
$firsttaskid = 0;
if (is_array($summary)) {
    foreach (board::STATUSES as $status) {
        $count = (int)($summary['counts'][$status] ?? 0);
        $attempt('Kanban page and scope: ' . $status,
            static function() use ($actorid, $allcurators, $companyid, $count, $status,
                &$firsttaskid): bool {
                $filters = ['companyid' => $companyid];
                $first = board::column($actorid, $allcurators, $filters, $status, 0);
                $lastpage = board::page(999999, $count);
                $last = board::column($actorid, $allcurators, $filters, $status, $lastpage);
                if (count($first) > board::PAGE_SIZE || count($last) > board::PAGE_SIZE ||
                        count($first) !== min($count, board::PAGE_SIZE) ||
                        count($last) !== ($count === 0 ? 0 : $count - $lastpage * board::PAGE_SIZE)) {
                    return false;
                }
                $ids = [];
                foreach ($first as $task) {
                    if ((int)$task->companyid !== $companyid || (string)$task->status !== $status ||
                            isset($ids[(int)$task->id])) {
                        return false;
                    }
                    $ids[(int)$task->id] = true;
                    if ($firsttaskid === 0) { $firsttaskid = (int)$task->id; }
                }
                return true;
            });
    }
}

// Confirm the Corporate bridge projection under the *supplied user's* permissions.
$isemployee = $DB->record_exists('local_kbacorp_member',
    ['companyid' => $companyid, 'userid' => $actorid, 'isemployee' => 1]);
if ($isemployee) {
    $attempt('Corporate bridge own-task projection', static function() use ($companyid, $actorid): bool {
        $rows = bridge::list_implementation_task_projections($companyid, $actorid,
            $actorid, 'mine', 25, 0);
        foreach ($rows as $row) {
            if ((int)($row['company_id'] ?? 0) !== $companyid ||
                    (int)($row['assignee_id'] ?? 0) !== $actorid ||
                    !preg_match('/^impltask:[1-9][0-9]*$/D', (string)($row['external_id'] ?? '')) ||
                    !empty($row['editable']) || isset($row['comments']) || isset($row['evidence'])) {
                return false;
            }
        }
        return true;
    });
} else {
    $check('Corporate bridge own-task projection', 'SKIP',
        'Assigned curator is not an employee member; provide an employee actor for this scenario.');
}
$maymanage = \local_kbacorporate\local\access::can_manage_company_implementation($companyid, $actorid);
if ($maymanage) {
    $attempt('Corporate bridge company-task projection', static function() use ($companyid, $actorid): bool {
        $rows = bridge::list_implementation_task_projections($companyid, $actorid,
            0, 'company', 25, 0);
        foreach ($rows as $row) {
            if ((int)($row['company_id'] ?? 0) !== $companyid ||
                    !empty($row['editable']) || isset($row['comments']) || isset($row['evidence'])) {
                return false;
            }
        }
        return true;
    });
} else {
    $check('Corporate bridge company-task projection', 'SKIP',
        'Curator does not have company-wide implementation-management rights.');
}

if ($firsttaskid > 0 && $othercompanyid > 0) {
    $attempt('Cross-company task ID isolation', static function() use ($othercompanyid,
            $actorid, $firsttaskid): bool {
        try {
            $found = bridge::get_implementation_task_projection($othercompanyid, $actorid, $firsttaskid);
            return $found === null;
        } catch (\moodle_exception $e) {
            return true; // ACL explicitly denied the cross-company lookup.
        }
    });
} else {
    $check('Cross-company task ID isolation', 'SKIP', 'Specify --othercompanyid; at least one visible task required.');
}

if ($otheractorid > 0 && $othercompanyid > 0) {
    $crossassigned = $DB->record_exists('local_kbacorp_impl_program',
        ['companyid' => $companyid, 'curatorid' => $otheractorid]);
    $crossadmin = has_capability('local/kbacorporate:manageimplementationaudits',
        \context_system::instance(), $otheractorid);
    if (!$crossassigned && !$crossadmin) {
        $attempt('Other curator cannot read this portfolio', static function() use ($companyid,
                $otheractorid): bool {
            try {
                $rows = board::summary($otheractorid, false, ['companyid' => $companyid]);
                return (int)$rows['total'] === 0;
            } catch (\moodle_exception $e) {
                return true;
            }
        });
    } else {
        $check('Other curator cannot read this portfolio', 'SKIP',
            'Second user already holds authorised access; use a user without this company assignment.');
    }
} else {
    $check('Other curator cannot read this portfolio', 'SKIP', 'Specify --otheractorid and --othercompanyid.');
}

if ($bmsuuid !== '') {
    $attempt('BMS24 source binding and company mapping', static function() use ($bmsuuid,
            $actorid, $companyid): bool {
        $providerclass = '\\local_kbacompany\\local\\task_provider\\kbacorporate_impl_task_provider';
        if (!class_exists($providerclass)) { return false; }
        $provider = new $providerclass();
        return $provider->available() && $provider->linked_company_id($bmsuuid, $actorid) === $companyid;
    });
} else {
    $check('BMS24 source binding and company mapping', 'SKIP', 'Specify --bmsuuid of the linked BMS company.');
}

$samples = [];
try {
    for ($n = 0; $n <= $runs; $n++) { // First run warms the cache and is not measured.
        $q0 = $DB->perf_get_queries();
        $start = microtime(true);
        $current = board::summary($actorid, $allcurators, ['companyid' => $companyid]);
        $visible = 0;
        foreach (board::STATUSES as $status) {
            $visible += count(board::column($actorid, $allcurators,
                ['companyid' => $companyid], $status, 0));
        }
        if ($n > 0) {
            $samples[] = ['ms' => (microtime(true) - $start) * 1000,
                'queries' => $DB->perf_get_queries() - $q0];
        }
        if ((int)$current['total'] < $visible) {
            throw new \RuntimeException('Aggregates inconsistent');
        }
    }
    $check('Read-only Kanban timing and counts', 'PASS');
} catch (\Throwable $e) {
    $check('Read-only Kanban timing and counts', 'FAIL', 'Execution error: ' . get_class($e));
}
$stats = metrics::aggregate($samples);
$stats['php_peak_memory_bytes'] = memory_get_peak_usage(true);
$stats['scope'] = 'Kanban server model only; not page HTML, browser rendering or full E2E';

$totals = ['PASS' => 0, 'FAIL' => 0, 'SKIP' => 0];
foreach ($checks as $item) { $totals[$item['status']]++; }
$report = [
    'stage' => '1825', 'generated_at_utc' => gmdate('c'), 'read_only' => true,
    'environment' => ['php' => PHP_VERSION, 'moodle' => (string)($CFG->release ?? ''),
        'database_family' => (string)$DB->get_dbfamily(),
        'corporate_build' => (int)get_config('local_kbacorporate', 'version'),
        'bms_build' => (int)get_config('local_kbacompany', 'version')],
    'checks' => $checks, 'totals' => $totals, 'benchmark' => $stats,
    'limitations' => ['Not a browser E2E test', 'No automatic writes or status transitions',
        'Manual curator submit/return/accept workflow and EXPLAIN still required'],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($totals['FAIL'] ? 2 : ($totals['SKIP'] ? 3 : 0));
