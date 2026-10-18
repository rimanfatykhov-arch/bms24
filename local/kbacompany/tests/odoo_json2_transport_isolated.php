<?php
// Isolated transport contract checks; does not bootstrap Moodle or call network.
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception { public function __construct($a='', $b='', $c='', $d=null){ parent::__construct((string)$a); } }
class coding_exception extends Exception {}
const PARAM_TEXT = 'text';
function clean_param($v, $t){ return trim(strip_tags((string)$v)); }
require_once(__DIR__ . '/../classes/local/odoo_json2_transport.php');
use local_kbacompany\local\odoo_json2_transport;

$requests = [];
$requester = function($url, $headers, $body) use (&$requests) {
    $requests[] = [$url, $headers, $body];
    if (strpos($url, '/res.users/context_get') !== false) {
        return ['status'=>200, 'body'=>json_encode(['uid'=>7,'lang'=>'en_US','tz'=>'UTC','unsafe'=>'drop'])];
    }
    return ['status'=>200, 'body'=>json_encode([['id'=>42,'name'=>'Demo Company']])];
};
$r = odoo_json2_transport::probe('https://demo.odoo.test', 'secret-key', 'demo', '42', $requester);
assert($r['remote_verified'] === true);
assert($r['writes_performed'] === 0);
assert($r['company']['id'] === 42);
assert(!isset($r['odoo_user_context']['unsafe']));
assert(count($requests) === 2);
foreach ($requests as $req) {
    assert(strpos($req[0], 'https://demo.odoo.test/json/2/') === 0);
    assert(strpos(implode("\n", $req[1]), 'Authorization: bearer secret-key') !== false);
}
$readreq = function($url, $headers, $body) {
    assert(strpos($url, '/x.bms.metric/read') !== false);
    $payload = json_decode($body, true);
    assert($payload['ids'] === [77]);
    assert(in_array('x_actual', $payload['fields'], true));
    return ['status'=>200, 'body'=>json_encode([['id'=>77,'x_actual'=>'12.50','write_date'=>'2026-09-26 08:00:00']])];
};
$record = odoo_json2_transport::read_record_fields('https://demo.odoo.test','secret-key','demo',
    'x.bms.metric','77',['x_actual','write_date'],$readreq);
assert($record['id'] === 77);
assert($record['x_actual'] === '12.50');
try { odoo_json2_transport::read_record_fields('https://demo.odoo.test','x','','bad/model','77',['x_actual'],$readreq); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::read_record_fields('https://demo.odoo.test','x','','x.bms.metric','abc',['x_actual'],$readreq); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::read_record_fields('https://demo.odoo.test','x','','x.bms.metric','77',['bad.field'],$readreq); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::probe('http://demo.odoo.test', 'x', '', '42', $requester); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::probe('https://user:pass@demo.odoo.test', 'x', '', '42', $requester); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::probe('https://demo.odoo.test', '', '', '42', $requester); assert(false); } catch (moodle_exception $e) {}
try { odoo_json2_transport::probe('https://demo.odoo.test', 'x', '', 'abc', $requester); assert(false); } catch (moodle_exception $e) {}
$bad = function(){ return ['status'=>401,'body'=>'{}']; };
try { odoo_json2_transport::probe('https://demo.odoo.test','x','','42',$bad); assert(false); } catch (moodle_exception $e) {}
echo "ODOO_JSON2_TRANSPORT_ISOLATED_PASS\n";
