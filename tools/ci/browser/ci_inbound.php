<?php
/**
 * CI-only RC33 inbound helper (disposable Moodle in GitHub Actions, PR-3 / owner D4). Never run against a real site.
 *
 * Phases:
 *   setup                 enable REST + the inbound service and switch on THIS disposable site only; synthetic
 *                         principals, authors, threads, bindings and sender mappings for companies A and B
 *   state <label>         counts and rows the contract verifies (no tokens, no message text)
 *   switch <on|off>       the inbound_max_enabled kill switch
 *   revoke_sender         registry revocation of sender S1 (company A) under the company gate
 *   close_membership      ADR-0006 membership revocation of author A2 under the member lock and company gate
 *   n28_setup             N28 (COORD-D05): a task with observer TX, a Battle Plan with observer PX and a meeting with
 *                         participant MX in company A; each writer posts once
 *   n28_post <kind> <tag> one post by the writer of task|plan|meeting (object path on even tags, generic send on odd
 *                         tags; meetings always generic); prints {tag, path, outcome, message_id}
 *   n28_post_other <kind> one post by a remaining writer after the revocation (notification exclusion)
 *   n28_revoke <kind>     task_service::set_observer(0), battle_plan_service::set_observer(0), or the ADR-0006 closure
 *                         of the meeting participant (there is no participant removal API)
 * Each revocation records, after its commit, the highest message id of the revoked author: no import may publish a
 * message by that author with a higher id afterwards (linearization of the shared gate).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_CI_BROWSER') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
$core = realpath($argv[1] ?? '');
$phase = (string)($argv[2] ?? '');
$arg = (string)($argv[3] ?? '');
if (!$core || !is_file($core . '/config.php')) { exit(2); }
define('CLI_SCRIPT', true);
require $core . '/config.php';
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/externallib.php');
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || strpos($CFG->dbname, 'bmsci_') !== 0 ||
        strpos((string)realpath($CFG->dataroot), (string)realpath((string)getenv('RUNNER_TEMP'))) !== 0) {
    fwrite(STDERR, "Refused: not a disposable CI site.\n"); exit(2);
}

use local_kbacompany\local\battle_plan_discussion_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\meeting_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\max_inbound_registry_service as registry;
use local_kbacompany\local\max_inbound_service as inbound;

$evidence = rtrim((string)getenv('EVIDENCE'), '/');
$privatefile = $evidence . '/inbound.private.json';
$admin = get_admin();
\core\session\manager::set_user($admin);

function ci_inbound_write(string $path, array $value, bool $private = false): void {
    if (is_link($path)) { throw new RuntimeException('Evidence path is a link'); }
    umask(0077);
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json) !== strlen($json)) { throw new RuntimeException('Evidence write failed'); }
    if ($private) { chmod($path, 0600); }
}

function ci_inbound_private(string $path): array {
    if (!is_file($path) || is_link($path)) { throw new RuntimeException('Inbound fixture missing'); }
    return json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

function ci_inbound_user(string $label, string $nonce): int {
    global $CFG;
    $username = 'rc33inbound' . $nonce . strtolower($label);
    return (int)user_create_user((object)['username' => $username, 'password' => 'Rc33!' . bin2hex(random_bytes(18)) . 'a9',
        'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic',
        'lastname' => 'Inbound ' . $label, 'email' => $username . '@example.invalid', 'lang' => 'en', 'maildisplay' => 0],
        true, false);
}

function ci_n28_uuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' .
        substr($hex, 20);
}

/** N28: one post as $userid; never prints exception text, only a Moodle error code or the exception class. */
function ci_n28_post(array $n, string $kind, int $userid, string $path, string $body): array {
    global $DB;
    \core\session\manager::set_user(core_user::get_user($userid, '*', MUST_EXIST));
    try {
        if ($path === 'object' && $kind === 'task') {
            $uuid = task_service::post_discussion_message($n['company'], $userid, $n['task'], $body);
        } else if ($path === 'object' && $kind === 'plan') {
            $uuid = battle_plan_discussion_service::post($n['company'], $userid, $n['plan'], $body);
        } else {
            $uuid = communication_service::send_message($n['company'], $userid, $n['threads'][$kind], $body);
        }
        $outcome = 'ok';
        $id = (int)$DB->get_field('local_kbacompany_commmsg', 'id', ['uuid' => $uuid], MUST_EXIST);
    } catch (\moodle_exception $e) {
        $outcome = (string)$e->errorcode;
        $id = 0;
    } catch (\Throwable $e) {
        $outcome = 'class:' . get_class($e);
        $id = 0;
    }
    return ['path' => $path, 'outcome' => $outcome, 'message_id' => $id];
}

/** N28: the writer's current right, read in this process (task/plan write right, company access for the meeting). */
function ci_n28_right(array $n, string $kind): bool {
    $user = (int)$n['users'][['task' => 'TX', 'plan' => 'PX', 'meeting' => 'MX'][$kind]];
    if ($kind === 'task') { return task_service::can_post_discussion_for($n['company'], $user, $n['task']); }
    if ($kind === 'plan') { return battle_plan_service::can_post_discussion_for($n['company'], $user, $n['plan']); }
    try {
        company_context_service::context($n['company'], $user);
        return true;
    } catch (\moodle_exception $e) {
        return false;
    }
}

try {
    if ($phase === 'setup') {
        if (file_exists($privatefile)) { throw new RuntimeException('Inbound fixture already initialized'); }
        $actors = ci_inbound_private($evidence . '/actors.private.json');
        $context = context_system::instance();
        $nonce = bin2hex(random_bytes(4));
        set_config('enablewebservices', 1);
        set_config('webserviceprotocols', 'rest');
        set_config(inbound::CONFIG, 1, 'local_kbacompany');
        $service = $DB->get_record('external_services', ['shortname' => 'local_kbacompany_public_inbound_v1'], '*', MUST_EXIST);
        if ((int)$service->enabled !== 0 || (int)$service->restrictedusers !== 1) {
            throw new RuntimeException('Inbound service is not disabled and restricted after install');
        }
        $service->enabled = 1;
        $DB->update_record('external_services', $service);
        $role = create_role('RC33 inbound CI ' . $nonce, 'rc33inbound' . $nonce, 'Disposable CI integration accounts');
        assign_capability('local/kbacompany:importinbound', CAP_ALLOW, $role, $context->id);
        assign_capability('webservice/rest:use', CAP_ALLOW, $role, $context->id);
        $record = ['nonce' => $nonce, 'companies' => [], 'service' => (int)$service->id];
        foreach (['A', 'B'] as $tenant) {
            $company = (string)$actors['companies'][$tenant]['uuid'];
            employee_service::ensure_membership($company, (int)$admin->id, [], 'bms');
            $principal = ci_inbound_user('P' . $tenant, $nonce);
            role_assign($role, $principal, $context->id);
            $DB->insert_record('external_services_users', (object)['externalserviceid' => $service->id,
                'userid' => $principal, 'timecreated' => time()]);
            $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $principal, $context);
            $authors = [];
            foreach (['1', '2'] as $n) {
                $authors[$n] = ci_inbound_user('A' . $tenant . $n, $nonce);
                employee_service::ensure_membership($company, $authors[$n], [], 'bms');
            }
            $peer = ci_inbound_user('R' . $tenant, $nonce);
            employee_service::ensure_membership($company, $peer, [], 'bms');
            $conn = registry::create_connection($company, (int)$admin->id, 'synthetic-ci-bot-' . $nonce . '-' . $tenant,
                $principal);
            registry::set_connection_status($company, (int)$admin->id, $conn, 'enabled');
            $bindings = [];
            foreach (['1', '2'] as $n) {
                $thread = communication_service::create_thread($company, (int)$admin->id, ['threadtype' => 'group',
                    'title' => 'RC33 imported ' . $tenant . $n, 'participants' => [$authors[$n], $peer]]);
                $chat = '-71' . ($tenant === 'A' ? '1' : '2') . $n . '000000001';
                $binding = registry::bind_group($company, (int)$admin->id, $conn, $chat, $thread, 'ci1', time() - 3600);
                $sender = '51' . ($tenant === 'A' ? '1' : '2') . $n . '000000001';
                $senderuuid = registry::bind_sender($company, (int)$admin->id, $conn, $sender, $authors[$n], $binding,
                    'ci1', 'synthetic:ci-consent-' . $tenant . $n, time() - 3600);
                $bindings[$n] = ['thread' => $thread, 'binding' => $binding, 'chat' => $chat, 'sender' => $sender,
                    'sender_uuid' => $senderuuid, 'author' => $authors[$n]];
            }
            $record['companies'][$tenant] = ['uuid' => $company, 'principal' => $principal, 'token' => $token,
                'connection' => $conn, 'peer' => $peer, 'bindings' => $bindings];
        }
        ci_inbound_write($privatefile, $record, true);
        echo "PASS inbound fixture prepared\n";
        exit(0);
    }
    $fixture = ci_inbound_private($privatefile);
    $a = $fixture['companies']['A'];
    if ($phase === 'switch') {
        set_config(inbound::CONFIG, $arg === 'on' ? 1 : 0, 'local_kbacompany');
        echo "PASS switch $arg\n";
        exit(0);
    }
    if ($phase === 'revoke_sender' || $phase === 'close_membership') {
        $n = $phase === 'revoke_sender' ? '1' : '2';
        $author = (int)$a['bindings'][$n]['author'];
        $started = microtime(true);
        if ($phase === 'revoke_sender') {
            registry::revoke_sender($a['uuid'], (int)$admin->id, $a['bindings'][$n]['sender_uuid']);
        } else {
            employee_service::close_membership($a['uuid'], $author, (int)$admin->id);
        }
        // Read after the revocation committed: every import that committed before it is visible now.
        $max = (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_kbacompany_commmsg}
            WHERE companyuuid = :c AND authorid = :a', ['c' => $a['uuid'], 'a' => $author]);
        ci_inbound_write($evidence . '/inbound-' . $phase . '.json', ['binding' => $n, 'max_message_id' => $max,
            'started' => $started, 'committed' => microtime(true)]);
        echo "PASS $phase\n";
        exit(0);
    }
    $n28file = $evidence . '/inbound-n28.private.json';
    $writers = ['task' => 'TX', 'plan' => 'PX', 'meeting' => 'MX'];
    $others = ['task' => 'TA', 'plan' => 'PO', 'meeting' => 'ADMIN'];
    if ($phase === 'n28_setup') {
        if (file_exists($n28file)) { throw new RuntimeException('N28 fixture already initialized'); }
        $actors = ci_inbound_private($evidence . '/actors.private.json');
        $company = (string)$a['uuid'];
        $users = ['ADMIN' => (int)$admin->id];
        foreach (['TA', 'TX', 'PO', 'PX', 'MX'] as $label) {
            $uid = ci_inbound_user('N' . $label, (string)$fixture['nonce']);
            // Narrow Corporate membership as in ci_fixtures.php (organization employees), then the BMS membership.
            $DB->insert_record('local_kbacorp_member', (object)['companyid' => (int)$actors['companies']['A']['id'],
                'userid' => $uid, 'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
                'isdepartmentmanager' => 0, 'ishr' => 0, 'timecreated' => time(), 'timemodified' => time()]);
            employee_service::ensure_membership($company, $uid, [], 'bms');
            $users[$label] = $uid;
        }
        $n = ['company' => $company, 'users' => $users, 'threads' => []];
        $n['task'] = task_service::create($company, (int)$admin->id, ['title' => 'RC33 N28 task',
            'description' => 'Synthetic', 'product' => 'Synthetic result', 'assigneeid' => $users['TA'],
            'reviewerid' => 0, 'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '']);
        $revision = (int)$DB->get_field('local_kbacompany_task', 'revision', ['companyuuid' => $company, 'uuid' => $n['task']]);
        task_service::set_observer($company, (int)$admin->id, $n['task'], $revision, $users['TX']);
        $now = time();
        $position = ci_n28_uuid();
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company,
            'branchuuid' => ci_n28_uuid(), 'orgnodeuuid' => ci_n28_uuid(), 'uuid' => $position, 'code' => 'P-CI-N28',
            'name' => 'CI N28 position', 'purpose' => 'Synthetic results', 'ismanager' => 0, 'plannedcount' => 1,
            'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $company, 'positionuuid' => $position,
            'slotuuid' => '', 'userid' => $users['PO'], 'assignmenttype' => 'primary', 'effectivefrom' => $now - 60,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now]);
        $n['plan'] = battle_plan_service::create_plan($company, (int)$admin->id, $users['PO'], $position,
            battle_plan_service::week_start(gmdate('Y-m-d')), 0);
        battle_plan_service::set_observer($company, (int)$admin->id, $n['plan'], $users['PX']);
        $n['meeting'] = meeting_service::create($company, (int)$admin->id, ['title' => 'RC33 N28 meeting',
            'purpose' => 'Synthetic', 'participants' => [$users['MX']]]);
        $n['threads']['meeting'] = (string)$DB->get_field('local_kbacompany_commthread', 'uuid', ['companyuuid' => $company,
            'threadtype' => 'object', 'contextkind' => 'meeting', 'contextuuid' => $n['meeting']], MUST_EXIST);
        // Each writer posts once on the object path (task, plan) or into the meeting thread; this creates the threads.
        foreach ($writers as $kind => $label) {
            $first = ci_n28_post($n, $kind, $users[$label], $kind === 'meeting' ? 'generic' : 'object', 'N28 setup');
            if ($first['outcome'] !== 'ok') { throw new RuntimeException('N28 setup post refused'); }
        }
        $n['threads']['task'] = (string)$DB->get_field('local_kbacompany_commthread', 'uuid', ['companyuuid' => $company,
            'threadtype' => 'object', 'contextkind' => 'task', 'contextuuid' => $n['task']], MUST_EXIST);
        $n['threads']['plan'] = (string)$DB->get_field('local_kbacompany_commthread', 'uuid', ['companyuuid' => $company,
            'threadtype' => 'object', 'contextkind' => 'battleplan', 'contextuuid' => $n['plan']], MUST_EXIST);
        ci_inbound_write($n28file, $n, true);
        echo "PASS n28 fixture prepared\n";
        exit(0);
    }
    if (in_array($phase, ['n28_post', 'n28_post_other', 'n28_revoke'], true)) {
        if (!isset($writers[$arg])) { throw new RuntimeException('Invalid N28 kind'); }
        $n = ci_inbound_private($n28file);
        $writer = (int)$n['users'][$writers[$arg]];
        if ($phase === 'n28_post') {
            $tag = (string)($argv[4] ?? '');
            if (!preg_match('/^(pre|mid|post)\.(\d{1,2})$/', $tag, $m)) { throw new RuntimeException('Invalid N28 tag'); }
            $path = ($arg !== 'meeting' && ((int)$m[2]) % 2 === 0) ? 'object' : 'generic';
            echo json_encode(['tag' => $tag] + ci_n28_post($n, $arg, $writer, $path, 'N28 ' . $tag)) . "\n";
            exit(0);
        }
        if ($phase === 'n28_post_other') {
            $path = $arg === 'meeting' ? 'generic' : 'object';
            echo json_encode(['tag' => 'other'] + ci_n28_post($n, $arg, (int)$n['users'][$others[$arg]], $path,
                'N28 remaining writer')) . "\n";
            exit(0);
        }
        $started = microtime(true);
        if ($arg === 'task') {
            $revision = (int)$DB->get_field('local_kbacompany_task', 'revision', ['companyuuid' => $n['company'],
                'uuid' => $n['task']], MUST_EXIST);
            task_service::set_observer($n['company'], (int)$admin->id, $n['task'], $revision, 0);
        } else if ($arg === 'plan') {
            battle_plan_service::set_observer($n['company'], (int)$admin->id, $n['plan'], 0);
        } else {
            employee_service::close_membership($n['company'], $writer, (int)$admin->id);
        }
        // Read after the revocation committed: every post that committed before it is visible now.
        $max = (int)$DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_kbacompany_commmsg}
            WHERE companyuuid = :c AND threaduuid = :t AND authorid = :a',
            ['c' => $n['company'], 't' => $n['threads'][$arg], 'a' => $writer]);
        ci_inbound_write($evidence . '/inbound-n28_revoke_' . $arg . '.json', ['kind' => $arg, 'max_message_id' => $max,
            'started' => $started, 'committed' => microtime(true)]);
        echo "PASS n28_revoke $arg\n";
        exit(0);
    }
    if ($phase === 'state') {
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $arg)) { throw new RuntimeException('Invalid state label'); }
        $out = ['label' => $arg, 'companies' => []];
        foreach ($fixture['companies'] as $tenant => $c) {
            $bindings = [];
            foreach ($c['bindings'] as $n => $b) {
                $receipts = array_values(array_map(static function($r) use ($DB) {
                    return ['mid' => (string)$r->externalmessageid, 'outcome' => (string)$r->outcome,
                        'reason' => (string)$r->reason, 'message_id' => $r->messageuuid === '' ? 0 :
                            (int)$DB->get_field('local_kbacompany_commmsg', 'id', ['uuid' => $r->messageuuid]),
                        'message_author' => $r->messageuuid === '' ? 0 :
                            (int)$DB->get_field('local_kbacompany_commmsg', 'authorid', ['uuid' => $r->messageuuid])];
                }, $DB->get_records('local_kbacompany_inbreceipt', ['bindinguuid' => $b['binding']], 'id ASC')));
                $messages = $DB->get_records('local_kbacompany_commmsg', ['companyuuid' => $c['uuid'],
                    'threaduuid' => $b['thread']], 'id ASC', 'id, uuid, authorid');
                $notified = [];
                foreach ($messages as $m) {
                    $notified[(string)$m->uuid] = array_map('intval', $DB->get_fieldset_select('local_kbacompany_commnotif',
                        'userid', 'messageuuid = ?', [$m->uuid]));
                    sort($notified[(string)$m->uuid]);
                }
                $events = $DB->get_records_select('local_kbacompany_eventout', 'companyuuid = ? AND eventtype = ?',
                    [$c['uuid'], inbound::EVENT], 'id ASC', 'id, entityuuid, envelope');
                $bindings[$n] = ['receipts' => $receipts,
                    'messages' => array_values(array_map(static function($m) {
                        return ['id' => (int)$m->id, 'uuid' => (string)$m->uuid, 'author' => (int)$m->authorid];
                    }, $messages)),
                    'notified' => $notified, 'author' => (int)$b['author'], 'peer' => (int)$c['peer'],
                    'owner' => (int)$admin->id,
                    'holds' => $DB->count_records('local_kbacompany_inbhold', ['bindinguuid' => $b['binding']])];
                $bindings[$n]['events'] = array_values(array_map(static function($e) use ($b) {
                    return ['entity' => (string)$e->entityuuid,
                        'leaks_external_id' => strpos((string)$e->envelope, $b['chat']) !== false ||
                            strpos((string)$e->envelope, $b['sender']) !== false];
                }, array_filter($events, static function($e) use ($messages) {
                    foreach ($messages as $m) { if ((string)$m->uuid === (string)$e->entityuuid) { return true; } }
                    return false;
                })));
            }
            $out['companies'][$tenant] = ['bindings' => $bindings,
                'audit_create_dual_actor' => $DB->count_records_select('local_kbacompany_inbaudit',
                    "companyuuid = ? AND action = 'create' AND principaluserid = ? AND actoruserid <> principaluserid",
                    [$c['uuid'], $c['principal']]),
                'audit_create' => $DB->count_records('local_kbacompany_inbaudit', ['companyuuid' => $c['uuid'],
                    'action' => 'create'])];
        }
        if (is_file($n28file)) {
            $n = ci_inbound_private($n28file);
            $out['n28'] = [];
            foreach ($writers as $kind => $label) {
                $messages = $DB->get_records('local_kbacompany_commmsg', ['companyuuid' => $n['company'],
                    'threaduuid' => $n['threads'][$kind]], 'id ASC', 'id, uuid, authorid');
                $notified = [];
                foreach ($messages as $m) {
                    $notified[(string)(int)$m->id] = array_map('intval', $DB->get_fieldset_select(
                        'local_kbacompany_commnotif', 'userid', 'messageuuid = ?', [$m->uuid]));
                }
                $out['n28'][$kind] = ['writer' => (int)$n['users'][$label], 'other' => (int)$n['users'][$others[$kind]],
                    'right' => ci_n28_right($n, $kind),
                    'messages' => array_values(array_map(static function($m) {
                        return ['id' => (int)$m->id, 'author' => (int)$m->authorid];
                    }, $messages)), 'notified' => $notified];
            }
        }
        $out['gate_held'] = \local_kbacompany\local\communication_write_gate::is_held($a['uuid']);
        ci_inbound_write($evidence . '/inbound-state-' . $arg . '.json', $out);
        echo "PASS state $arg\n";
        exit(0);
    }
    throw new RuntimeException('Unknown phase');
} catch (Throwable $error) {
    // Never print exception text: it may contain SQL with synthetic credentials.
    fwrite(STDERR, 'FAIL inbound ' . $phase . ': ' . get_class($error) . "\n");
    exit(1);
}
