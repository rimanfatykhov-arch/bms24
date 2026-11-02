<?php
// Pure isolated tests for RC29/1804 preferences. Moodle DB is NOT involved.
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception {
    public function __construct($code, $module = '') { parent::__construct($code); }
}
$GLOBALS['prefs'] = [];
function get_user_preferences($name, $default = null, $user = null) {
    return $GLOBALS['prefs'][(int)$user][$name] ?? $default;
}
function set_user_preference($name, $value, $user = null) {
    $GLOBALS['prefs'][(int)$user][$name] = $value;
}
function unset_user_preference($name, $user = null) {
    unset($GLOBALS['prefs'][(int)$user][$name]);
}
require_once(__DIR__ . '/../classes/local/bms_workspace_widget_service.php');
use local_kbacompany\local\bms_workspace_widget_service as W;
$count = 0;
function check($ok, $label) {
    global $count;
    if (!$ok) { throw new Exception('FAIL: ' . $label); }
    $count++;
}
function throws(callable $fn) {
    try { $fn(); } catch (moodle_exception $e) { return true; }
    return false;
}
$all = W::available(true);
$staff = W::available(false);
check(count($all) === 9 && count($staff) === 8, 'role-based availability');
check(!in_array('management', $staff, true), 'management widget never exposed to staff');
check(W::current('a', 1, $all)['visible'] === $all, 'fresh user default');
check(W::preference_key('a') !== W::preference_key('b'), 'company-scoped hashed key');
check(W::normalize('broken-json', $all)['order'] === $all, 'corrupt preference fallback');
check(W::normalize(['order' => ['today', 'evil', 'today'], 'hidden' => ['tasks', 'bad']], $all)['order'][0] === 'today',
    'sanitize duplicate and unknown keys');
check(!in_array('evil', W::normalize(['order' => ['evil'], 'hidden' => []], $all)['order'], true), 'unknown IDs removed');
$positions = [];
foreach ($all as $i => $key) { $positions[$key] = count($all) - $i; }
$layout = W::prepare($positions, ['tasks', 'plan'], $all, W::defaults($all));
check($layout['order'][0] === 'company' && $layout['order'][8] === 'today', 'reverse reorder');
check($layout['visible'] === ['tasks', 'plan'], 'visible list follows layout order');
check(count($layout['hidden']) === 7, 'cards hidden');
W::save('a', 1, $layout, $all);
check(W::current('a', 1, $all) === $layout, 'persistence');
check(W::current('b', 1, $all)['visible'] === $all, 'company isolation');
check(W::current('a', 2, $all)['visible'] === $all, 'user isolation');
check(W::current('a', 1, $staff)['order'] === array_values(array_diff($layout['order'], ['management'])),
    'revoke management capability');
check(throws(function () use ($all) { W::prepare(['sql' => 1], ['today'], $all, W::defaults($all)); }),
    'reject arbitrary widget IDs');
check(throws(function () use ($staff) { W::prepare(['today'=>1], ['management'], $staff, W::defaults($staff)); }),
    'reject unauthorized cards');
check(throws(function () use ($all) { W::prepare(['today'=>99], ['today'], $all, W::defaults($all)); }),
    'reject out-of-bound positions');
check(throws(function () use ($all) { W::prepare([], [], $all, W::defaults($all)); }),
    'reject fully hidden layout');
check(W::normalize(['order' => $all, 'hidden' => $all], $all)['visible'] === ['today'],
    'fail-safe for old fully hidden preferences');
$tie = W::prepare(['today'=>2,'plan'=>2], ['today','plan'], $all, W::defaults($all));
check(array_slice($tie['order'], 0, 2) === ['today','plan'], 'stable tie ordering');
W::reset('a', 1);
check(W::current('a', 1, $all)['visible'] === $all, 'reset restores default');
check(count(W::current('a', 1, $all)['order']) <= W::MAX_WIDGETS, 'fixed maximum');
echo "BMS1804: {$count} widget policy tests OK\n";
