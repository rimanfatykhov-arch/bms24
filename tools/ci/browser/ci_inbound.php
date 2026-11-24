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

use local_kbacompany\local\communication_service;
use local_kbacompany\local\employee_service;
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
