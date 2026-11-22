<?php
// CI-only helper for plan_discussion.py on the disposable Moodle (synthetic data only). Phases:
//   plan <reviewerid>          -> creates once a weekly Battle Plan of EA in company A (position + assignment), reviewer =
//                                 the given synthetic user; prints {plan, week}; reuses it on repeat calls
//   snapshot <company> <plan>  -> JSON: business fields of the plan (read-only), to prove a message never changes it
//   thread <company> <plan>    -> JSON: canonical plan discussion thread uuid (read-only)
//   strings                    -> JSON: exact refusal texts (plan/company unavailable, thread permission, sesskey)
// Never used outside BMS_CI_BROWSER.
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
$phase = $argv[2] ?? '';
$out = ['phase' => $phase];
$evidence = rtrim((string)getenv('EVIDENCE'), '/');
if ($phase === 'plan') {
    $state = $evidence . '/plan-discussion-fixture.json';
    if (is_file($state)) {
        $out += json_decode((string)file_get_contents($state), true);
    } else {
        $f = json_decode((string)file_get_contents($evidence . '/actors.private.json'), true);
        $admin = get_admin();
        \core\session\manager::set_user($admin);
        $company = $f['companies']['A']['uuid'];
        $ea = (int)$f['actors']['EA']['userid'];
        $now = time();
        $hex = bin2hex(random_bytes(16));
        $position = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-8' .
            substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company,
            'branchuuid' => '30000000-0000-4000-8000-000000000002', 'orgnodeuuid' => '30000000-0000-4000-8000-000000000003',
            'uuid' => $position, 'code' => 'P-CI-PLAN', 'name' => 'CI plan position', 'purpose' => 'Synthetic results',
            'ismanager' => 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $company, 'positionuuid' => $position,
            'slotuuid' => '', 'userid' => $ea, 'assignmenttype' => 'primary', 'effectivefrom' => $now - 60,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $week = \local_kbacompany\local\battle_plan_service::week_start(gmdate('Y-m-d'));
        $plan = \local_kbacompany\local\battle_plan_service::create_plan($company, (int)$admin->id, $ea, $position,
            $week, (int)($argv[3] ?? 0));
        $record = ['plan' => $plan, 'week' => $week];
        file_put_contents($state, json_encode($record));
        $out += $record;
    }
} else if ($phase === 'snapshot') {
    $p = $DB->get_record('local_kbacompany_bplan', ['companyuuid' => $argv[3], 'uuid' => $argv[4]], '*', MUST_EXIST);
    $out['plan'] = ['status' => (string)$p->status, 'userid' => (int)$p->userid, 'reviewerid' => (int)$p->reviewerid,
        'observerid' => (int)$p->observerid, 'timemodified' => (int)$p->timemodified, 'approvedby' => (int)$p->approvedby,
        'submittedat' => (int)$p->submittedat, 'approvedat' => (int)$p->approvedat, 'closedat' => (int)$p->closedat];
    $out['reviews'] = $DB->count_records('local_kbacompany_bplanrev', ['companyuuid' => $argv[3], 'planuuid' => $argv[4]]);
} else if ($phase === 'thread') {
    $rows = $DB->get_records('local_kbacompany_commthread', ['companyuuid' => $argv[3], 'threadtype' => 'object',
        'contextkind' => 'battleplan', 'contextuuid' => $argv[4], 'status' => 'active'], 'id ASC', 'id, uuid', 0, 1);
    $out['thread'] = $rows ? (string)reset($rows)->uuid : '';
} else if ($phase === 'strings') {
    // Exact refusal texts in the site language of the disposable Moodle: the contract confirms WHY a request was
    // refused, not only that it was.
    $out['strings'] = ['plan_unavailable' => get_string('battleplan_reference', 'local_kbacompany'),
        'company_unavailable' => get_string('companynotavailable', 'local_kbacompany'),
        // The Center renders the non-disclosing object error after an inaccessible-object join.
        'thread_permission' => get_string('communication_contextunavailable', 'local_kbacompany'),
        'invalid_sesskey' => get_string('invalidsesskey', 'error')];
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
