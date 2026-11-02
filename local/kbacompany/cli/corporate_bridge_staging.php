<?php
// BMS24 stage 1881.16: explicit compatibility bridge for one staging company.
// No Moodle core changes; dry-run by default. Never schedule as a cron task.

// CLI files live in the Moodle webroot; never allow direct web execution.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'companyuuid' => '',
    'apply' => false,
    'verify' => false,
], ['h' => 'help']);

if ($unrecognised || $options['help'] || trim((string)$options['companyuuid']) === '') {
    echo "Usage: php local/kbacompany/cli/corporate_bridge_staging.php --companyuuid=UUID [--apply|--verify]\n";
    echo "Default: read-only. --apply requires BMS24_STAGING_BRIDGE_ALLOW=1.\n";
    exit($options['help'] && !$unrecognised ? 0 : 2);
}

if ($options['apply'] && $options['verify']) {
    cli_error('STOP: --apply and --verify are mutually exclusive.');
}
$uuid = strtolower(trim((string)$options['companyuuid']));
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
    cli_error('Invalid company UUID (expected UUID v4).');
}
if ($options['apply']) {
    if (getenv('BMS24_STAGING_BRIDGE_ALLOW') !== '1') {
        cli_error('STOP: --apply requires BMS24_STAGING_BRIDGE_ALLOW=1 on staging.');
    }
    // Stronger safety invariant: never write on Moodle instances with a different public URL.
    if (rtrim((string)$CFG->wwwroot, '/') !== 'https://test.bms24.online' ||
        empty($CFG->sslproxy)) {
        cli_error('STOP: staging HTTPS configuration mismatch; refusing to write.');
    }
}

$tables = [
    'local_kbacompany_company', 'local_kbacompany_member',
    'local_kbacorp_company', 'local_kbacorp_member',
];
foreach ($tables as $table) {
    if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
        cli_error('STOP: required table is missing: ' . $table);
    }
}
$company = $DB->get_record('local_kbacompany_company', ['uuid' => $uuid]);
if (!$company || !(int)$company->active) {
    cli_error('STOP: canonical company does not exist or is inactive.');
}
$members = $DB->get_records('local_kbacompany_member', [
    'companyuuid' => $uuid, 'active' => 1,
]);
$now = time();
$eligiblemembers = [];
foreach ($members as $member) {
    if ((int)$member->effectivefrom > $now ||
        ((int)$member->effectiveto > 0 && (int)$member->effectiveto < $now)) {
        continue;
    }
    if (!$DB->record_exists('user', ['id' => (int)$member->userid, 'deleted' => 0])) {
        cli_error('STOP: membership points to a missing/deleted Moodle user.');
    }
    if (isset($eligiblemembers[(int)$member->userid])) {
        cli_error('STOP: duplicate active memberships for one user.');
    }
    $eligiblemembers[(int)$member->userid] = $member;
}
if (!$eligiblemembers) {
    cli_error('STOP: company has no active eligible members.');
}

$legacyid = (int)$company->legacycompanyid;
$legacy = $legacyid > 0 ? $DB->get_record('local_kbacorp_company', ['id' => $legacyid]) : false;
if ($legacyid && !$legacy) {
    cli_error('STOP: legacycompanyid refers to a missing corporate company.');
}
if ($legacy && !(int)$legacy->active) {
    cli_error('STOP: mapped corporate company is inactive.');
}
if (!$legacy && $DB->count_records('local_kbacorp_company') !== 0) {
    cli_error('STOP: unmapped corporate companies exist; manual reconciliation required.');
}
if ($legacy) {
    // Prevent two canonical companies from silently sharing one legacy tenant.
    $bindings = $DB->get_records('local_kbacompany_company', ['legacycompanyid' => $legacyid]);
    if (count($bindings) !== 1 || !isset($bindings[$company->id])) {
        cli_error('STOP: ambiguous legacycompanyid mapping across canonical companies.');
    }
} else {
    // The corporate schema stores CHAR(255), while canonical primaryactivity is TEXT.
    if (core_text::strlen((string)($company->primaryactivity ?? '')) > 255) {
        cli_error('STOP: canonical primaryactivity cannot fit in legacy CHAR(255).');
    }
}

$expectedroles = static function(stdClass $m): array {
    return [
        'membertype' => (string)$m->membertype,
        'isemployee' => (int)$m->isemployee,
        'ismanager' => (int)$m->ismanager,
        'isdepartmentmanager' => (int)$m->isdepartmentmanager,
        'ishr' => (int)$m->ishr,
    ];
};
$existingmembers = [];
if ($legacy) {
    foreach ($DB->get_records('local_kbacorp_member', ['companyid' => $legacyid]) as $m) {
        if (isset($existingmembers[(int)$m->userid])) {
            cli_error('STOP: duplicate corporate memberships for one user.');
        }
        $existingmembers[(int)$m->userid] = $m;
    }
    foreach ($existingmembers as $userid => $m) {
        if (!isset($eligiblemembers[$userid])) {
            cli_error('STOP: corporate member not present in canonical active memberships.');
        }
        if ($expectedroles($m) !== $expectedroles($eligiblemembers[$userid])) {
            cli_error('STOP: membership role drift; never overwrite corporate permissions automatically.');
        }
    }
}
$newmembers = array_diff_key($eligiblemembers, $existingmembers);
printf("Company UUID: %s\nCanonical: %s (id=%d)\n", $uuid, $company->name, $company->id);
printf("Corporate mapping: %s\n", $legacy ? (string)$legacyid : 'MISSING');
printf("Canonical active members: %d; corporate members: %d; pending insertions: %d\n",
    count($eligiblemembers), count($existingmembers), count($newmembers));

if ($options['verify']) {
    if (!$legacy || $newmembers) {
        cli_error('VERIFY_FAILED: corporate mapping or memberships incomplete.');
    }
    foreach ($eligiblemembers as $member) {
        if (!(int)$member->ismanager) {
            continue;
        }
        if (!\local_kbacorporate\local\access::has_full_company_access($legacyid, (int)$member->userid)) {
            cli_error('VERIFY_FAILED: manager did not receive full legacy company access.');
        }
        $allowed = \local_kbacorporate\local\access::get_allowed_companies((int)$member->userid);
        if (!isset($allowed[$legacyid])) {
            cli_error('VERIFY_FAILED: corporate company not visible to intended manager.');
        }
    }
    echo "VERIFY_OK: company bridge and membership roles are consistent (read-only).\n";
    exit(0);
}

if (!$options['apply']) {
    echo "DRY_RUN_OK: no changes made. Run with --apply and explicit staging guard after backup/review.\n";
    exit(0);
}

$lock = core\lock\lock_config::get_lock_factory('local_kbacompany')
    ->get_lock('staging_corporate_bridge:' . $uuid, 10);
if (!$lock) {
    cli_error('STOP: could not acquire company lock.');
}
try {
    $current = $DB->get_record('local_kbacompany_company', ['id' => $company->id], '*', MUST_EXIST);
    if ((int)$current->legacycompanyid !== $legacyid || !(int)$current->active) {
        cli_error('STOP: company mapping changed since preflight.');
    }
    if (!$legacy && $DB->count_records('local_kbacorp_company') !== 0) {
        cli_error('STOP: legacy registry changed since preflight.');
    }
    if ($legacy) {
        $freshlegacy = $DB->get_records('local_kbacorp_member', ['companyid' => $legacyid]);
        if (count($freshlegacy) !== count($existingmembers)) {
            cli_error('STOP: legacy membership count changed since preflight.');
        }
        foreach ($freshlegacy as $legacyrow) {
            $uid = (int)$legacyrow->userid;
            if (!isset($existingmembers[$uid]) ||
                $expectedroles($legacyrow) !== $expectedroles($existingmembers[$uid])) {
                cli_error('STOP: legacy membership roles changed since preflight.');
            }
        }
    }
    $freshmembers = $DB->get_records('local_kbacompany_member', [
        'companyuuid' => $uuid, 'active' => 1,
    ]);
    if (count($freshmembers) !== count($members)) {
        cli_error('STOP: canonical membership count changed since preflight.');
    }
    foreach ($freshmembers as $id => $fresh) {
        if (!isset($members[$id]) || (int)$fresh->userid !== (int)$members[$id]->userid ||
            $expectedroles($fresh) !== $expectedroles($members[$id]) ||
            (int)$fresh->effectivefrom !== (int)$members[$id]->effectivefrom ||
            (int)$fresh->effectiveto !== (int)$members[$id]->effectiveto) {
            cli_error('STOP: canonical membership changed since preflight.');
        }
    }
    $transaction = $DB->start_delegated_transaction();
    if (!$legacy) {
        $legacyid = $DB->insert_record('local_kbacorp_company', (object)[
            'name' => (string)$company->name,
            'code' => (string)$company->code,
            'primaryactivity' => (string)($company->primaryactivity ?? ''),
            'foundedyear' => (int)$company->foundedyear,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('local_kbacompany_company', 'legacycompanyid', $legacyid, ['id' => $company->id]);
    }
    foreach ($newmembers as $member) {
        $DB->insert_record('local_kbacorp_member', (object)([
            'companyid' => $legacyid,
            'userid' => (int)$member->userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ] + $expectedroles($member)));
    }
    $transaction->allow_commit();
    echo "STAGING_CORPORATE_BRIDGE_OK: legacycompanyid=$legacyid; new_members=" . count($newmembers) . "\n";
} finally {
    $lock->release();
}
