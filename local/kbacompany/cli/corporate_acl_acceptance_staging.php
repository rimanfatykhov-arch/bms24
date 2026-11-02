<?php
// BMS24 Stage 1881.19 — read-only company access acceptance audit on staging.
// NO DATA WRITES. No automatic user creation, capability grants or role changes.

// CLI files live in the Moodle webroot; never allow direct web execution.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unknown] = cli_get_params([
    'help' => false,
    'companyuuid' => '',
    'outsideruserid' => 0,
    'outsiderusername' => '',
], ['h' => 'help']);
if ($unknown || $options['help'] || empty($options['companyuuid'])) {
    echo "Usage: php local/kbacompany/cli/corporate_acl_acceptance_staging.php --companyuuid=UUID [--outsideruserid=ID | --outsiderusername=NAME]\n";
    echo "Checks BMS/Corporate mapping, manager implementation capability and outsider denial (read-only).\n";
    exit($options['help'] && !$unknown ? 0 : 2);
}

$uuid = strtolower(trim((string)$options['companyuuid']));
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $uuid)) {
    cli_error('STOP: invalid company UUID.');
}
if (rtrim((string)$CFG->wwwroot, '/') !== 'https://test.bms24.online') {
    cli_error('STOP: this audit is permitted only on test.bms24.online.');
}
foreach (['local_kbacompany_company', 'local_kbacompany_member',
          'local_kbacorp_company', 'local_kbacorp_member'] as $table) {
    if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
        cli_error('STOP: missing required table ' . $table);
    }
}
$company = $DB->get_record('local_kbacompany_company', ['uuid' => $uuid]);
if (!$company || !(int)$company->active || !(int)$company->legacycompanyid) {
    cli_error('FAIL: canonical company inactive or corporate bridge missing.');
}
$companyid = (int)$company->legacycompanyid;
$legacy = $DB->get_record('local_kbacorp_company', ['id' => $companyid]);
if (!$legacy || !(int)$legacy->active) {
    cli_error('FAIL: linked legacy company missing or inactive.');
}
$canonicalbindings = $DB->get_records('local_kbacompany_company', ['legacycompanyid' => $companyid]);
if (count($canonicalbindings) !== 1 || !isset($canonicalbindings[$company->id])) {
    cli_error('FAIL: corporate company has ambiguous canonical mapping.');
}

$now = time();
$canonical = $DB->get_records('local_kbacompany_member', [
    'companyuuid' => $uuid,
    'active' => 1,
]);
$active = [];
foreach ($canonical as $m) {
    if ((int)$m->effectivefrom > $now ||
        ((int)$m->effectiveto > 0 && (int)$m->effectiveto < $now)) {
        continue;
    }
    $userid = (int)$m->userid;
    if (isset($active[$userid]) ||
        !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) {
        cli_error('FAIL: duplicate or invalid canonical member.');
    }
    $active[$userid] = $m;
}
if (!$active) {
    cli_error('FAIL: no active canonical members.');
}
$legacyrows = $DB->get_records('local_kbacorp_member', ['companyid' => $companyid]);
$legacyusers = [];
foreach ($legacyrows as $row) {
    $userid = (int)$row->userid;
    if (isset($legacyusers[$userid]) || !isset($active[$userid])) {
        cli_error('FAIL: unexpected or duplicate legacy company member.');
    }
    $legacyusers[$userid] = $row;
}
if (count($active) !== count($legacyusers)) {
    cli_error('FAIL: membership count differs between registries.');
}

$rolefields = ['membertype', 'isemployee', 'ismanager', 'isdepartmentmanager', 'ishr'];
$access = '\local_kbacorporate\local\access';
$context = context_system::instance();
$positives = 0;
$nonmanagers = 0;
$globalprivileged = 0;
foreach ($active as $userid => $member) {
    $legacyrow = $legacyusers[$userid];
    foreach ($rolefields as $field) {
        if ((string)$member->$field !== (string)$legacyrow->$field) {
            cli_error('FAIL: corporate roles differ from canonical membership.');
        }
    }
    $full = $access::has_full_company_access($companyid, $userid);
    $canmanage = $access::can_manage_company_implementation($companyid, $userid);
    if ((int)$member->ismanager || is_siteadmin($userid)) {
        if (!$full) {
            cli_error('FAIL: intended manager lacks company-wide visibility.');
        }
        if (!$canmanage) {
            cli_error('FAIL: manager has company mapping but lacks Moodle implementation capability.');
        }
        $visible = $access::get_allowed_companies($userid);
        if (!isset($visible[$companyid])) {
            cli_error('FAIL: target company not offered to authorised manager.');
        }
        $positives++;
    } else {
        $isglobal = has_capability('local/kbacorporate:viewallcompanies', $context, $userid);
        if ($isglobal) {
            // Globally privileged users are not valid negative-control subjects.
            $globalprivileged++;
        } else {
            if ($full || $canmanage) {
                cli_error('FAIL: regular non-manager has implementation access.');
            }
            $nonmanagers++;
        }
    }
}
if (!$positives) {
    cli_error('FAIL: no enabled authorised manager/site admin available for positive ACL testing.');
}
echo "PASS: matched company mappings and roles\n";
echo "PASS: implementation-access positive checks: $positives\n";
echo "PASS: regular nonmanager-denial checks: $nonmanagers\n";
if ($globalprivileged) {
    echo "INFO: globally privileged nonmanagers excluded from negative tests: $globalprivileged\n";
}

$outsiderid = (int)$options['outsideruserid'];
$outsiderusername = trim((string)$options['outsiderusername']);
if ($outsiderusername !== '') {
    if ($outsiderid !== 0 || !preg_match('/^[a-z0-9_.-]{3,100}$/', $outsiderusername)) {
        cli_error('STOP: specify either a valid outsiderusername or outsideruserid, not both.');
    }
    $nameduser = $DB->get_record('user', [
        'username' => $outsiderusername,
        'deleted' => 0,
        'suspended' => 0,
    ]);
    if (!$nameduser) {
        cli_error('ACCEPTANCE_PARTIAL: requested outsider username was not found as an active user on staging.');
    }
    $outsiderid = (int)$nameduser->id;
}
if ($outsiderid < 0) {
    cli_error('STOP: outsideruserid must be non-negative.');
}
if ($outsiderid === 0) {
    $users = $DB->get_recordset_select('user',
        'id > :guest AND deleted = 0 AND suspended = 0',
        ['guest' => 1], 'id ASC', 'id', 0, 500);
    try {
        foreach ($users as $u) {
            $candidate = (int)$u->id;
            if (isset($active[$candidate]) || is_siteadmin($candidate) ||
                has_capability('local/kbacorporate:viewallcompanies', $context, $candidate)) {
                continue;
            }
            $candidatecompanies = $access::get_allowed_companies($candidate);
            if (isset($candidatecompanies[$companyid])) {
                continue; // Not an unrelated user: might be a legitimate department manager.
            }
            $outsiderid = $candidate;
            break;
        }
    } finally {
        $users->close();
    }
} else {
    if (!$DB->record_exists('user', ['id' => $outsiderid, 'deleted' => 0, 'suspended' => 0]) ||
        isset($active[$outsiderid]) || is_siteadmin($outsiderid) ||
        has_capability('local/kbacorporate:viewallcompanies', $context, $outsiderid)) {
        cli_error('STOP: requested outsider is invalid, privileged or a company member.');
    }
    $requestedvisible = $access::get_allowed_companies($outsiderid);
    if (isset($requestedvisible[$companyid])) {
        cli_error('STOP: requested outsider has existing company visibility; choose an unrelated test user.');
    }
}
if (!$outsiderid) {
    echo "ACCEPTANCE_PARTIAL: no unrelated active user for negative ACL test.\n";
    echo "Create an ordinary test user via approved staging process and rerun --outsideruserid=ID.\n";
    exit(4);
}
$outsidervisible = $access::get_allowed_companies($outsiderid);
if ($access::has_full_company_access($companyid, $outsiderid) ||
    $access::can_manage_company_implementation($companyid, $outsiderid) ||
    isset($outsidervisible[$companyid])) {
    cli_error('FAIL: unrelated user can access target corporate company.');
}
echo "PASS: unrelated-user denial (user id: $outsiderid)\n";
echo "STAGE1881_ACL_ACCEPTANCE_OK: read-only PHP access checks passed.\n";
