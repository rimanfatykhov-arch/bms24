<?php
// CI-only helper for daily_reports_adr0010.py on the disposable Moodle (synthetic data only). Phases:
//   setup <company> <top> <lead> <employee> -> enables daily reports; organisation chart ROOT (top manages) > TEAM
//                                              (lead manages, employee works there); default company rules
//   state <company> <author> <day>           -> JSON: report, versions with reviewer source, decisions, copies, journal
//                                              self-review flags and copy notifications of that day (read-only)
//   teardown <company> <branch>              -> removes this run's synthetic chart (posassign, position, orgnode)
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
$phase = $argv[2] ?? '';
$company = (string)($argv[3] ?? '');
$out = ['phase' => $phase];
if ($phase === 'setup') {
    [$top, $lead, $employee] = [(int)($argv[4] ?? 0), (int)($argv[5] ?? 0), (int)($argv[6] ?? 0)];
    set_config('dailyreports_enabled', 1, 'local_kbacompany');
    $DB->delete_records('local_kbacompany_dreportset', ['companyuuid' => $company]);
    $branch = \core\uuid::generate();
    $node = static function(string $parent, string $code) use ($DB, $company, $branch): string {
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'uuid' => $uuid, 'parentuuid' => $parent, 'code' => $code . substr($uuid, 0, 6), 'nodetype' => 'department',
            'name' => 'CI ' . $code, 'sortorder' => 0, 'color' => '', 'sourcecomponent' => 'local_kbacompany',
            'sourceentity' => 'ci', 'sourceid' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        return $uuid;
    };
    $assign = static function(string $unit, int $userid, bool $manager) use ($DB, $company, $branch): void {
        $position = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'orgnodeuuid' => $unit, 'uuid' => $position, 'code' => 'CI' . substr($position, 0, 8), 'name' => 'CI position',
            'purpose' => '', 'ismanager' => $manager ? 1 : 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $company, 'positionuuid' => $position,
            'slotuuid' => '', 'userid' => $userid, 'assignmenttype' => 'primary', 'assignmentfte' => 1, 'effectivefrom' => 0,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
    };
    $root = $node('', 'ROOT');
    $team = $node($root, 'TEAM');
    $assign($root, $top, true);
    $assign($team, $lead, true);
    $assign($team, $employee, false);
    $out += ['enabled' => (bool)get_config('local_kbacompany', 'dailyreports_enabled'), 'branch' => $branch,
        'today' => \local_kbacompany\local\business_date_service::day()];
} else if ($phase === 'state') {
    $author = (int)($argv[4] ?? 0); $day = (string)($argv[5] ?? '');
    $report = $DB->get_record('local_kbacompany_dreport', ['companyuuid' => $company, 'userid' => $author, 'reportday' => $day]);
    $out['report'] = $report ? ['uuid' => (string)$report->uuid, 'status' => (string)$report->status] : null;
    $out['versions'] = []; $out['decisions'] = []; $out['copies'] = []; $out['journal'] = [];
    if ($report) {
        foreach ($DB->get_records('local_kbacompany_dreportver', ['companyuuid' => $company, 'reportuuid' => $report->uuid],
                'versionno ASC') as $v) {
            $out['versions'][] = ['uuid' => (string)$v->uuid, 'state' => (string)$v->state, 'done' => (string)$v->done,
                'reviewerid' => (int)$v->reviewerid, 'reviewersource' => (string)$v->reviewersource];
            foreach ($DB->get_records('local_kbacompany_dreportcc', ['companyuuid' => $company, 'versionuuid' => $v->uuid],
                    'id ASC') as $c) {
                $out['copies'][] = ['versionuuid' => (string)$v->uuid, 'userid' => (int)$c->userid, 'source' => (string)$c->source];
            }
        }
        foreach ($DB->get_records('local_kbacompany_dreportrev', ['companyuuid' => $company, 'reportuuid' => $report->uuid],
                'id ASC') as $r) {
            $out['decisions'][] = ['versionuuid' => (string)$r->versionuuid, 'decision' => (string)$r->decision,
                'rating' => (string)$r->rating, 'reviewerid' => (int)$r->reviewerid];
        }
        foreach ($DB->get_records_select('local_kbacompany_dreportlog',
                "companyuuid = :c AND reportuuid = :r AND action IN ('submitted', 'decided')",
                ['c' => $company, 'r' => $report->uuid], 'id ASC') as $l) {
            $details = json_decode((string)$l->details, true) ?: [];
            $out['journal'][] = ['action' => (string)$l->action, 'actorid' => (int)$l->actorid,
                'selfreview' => $details['selfreview'] ?? null, 'reviewersource' => $details['reviewersource'] ?? null];
        }
    }
    $out['copy_notifications'] = [];
    foreach ($DB->get_records('local_kbacompany_commnotif', ['companyuuid' => $company, 'kind' => 'dailyreport_copy'], 'id ASC')
            as $n) {
        if (strpos((string)$n->title, $day) !== false) {
            $out['copy_notifications'][] = ['userid' => (int)$n->userid];
        }
    }
} else if ($phase === 'teardown') {
    // Removes only the synthetic chart of this run, so later browser scenarios see the fixture as before.
    $branch = (string)($argv[4] ?? '');
    $positions = $branch === '' ? [] : $DB->get_fieldset_select('local_kbacompany_position', 'uuid',
        'companyuuid = :c AND branchuuid = :b', ['c' => $company, 'b' => $branch]);
    foreach ($positions as $position) {
        $DB->delete_records('local_kbacompany_posassign', ['companyuuid' => $company, 'positionuuid' => $position]);
    }
    if ($branch !== '') {
        $DB->delete_records('local_kbacompany_position', ['companyuuid' => $company, 'branchuuid' => $branch]);
        $DB->delete_records('local_kbacompany_orgnode', ['companyuuid' => $company, 'branchuuid' => $branch]);
    }
    $out['removed'] = count($positions);
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
