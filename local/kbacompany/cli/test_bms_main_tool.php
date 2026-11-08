<?php
// Pure offline regression of the per-company navigation policy. No Moodle DB needed.
define('MOODLE_INTERNAL', true);
class moodle_exception extends Exception {
    public function __construct($errorcode, $module = '') { parent::__construct($errorcode); }
}
class moodle_url {
    private $url;
    public function __construct($path, $params = []) { $this->url = $path . '?' . http_build_query($params); }
    public function out($escape = true) { return $this->url; }
}
$GLOBALS['prefs'] = [];
function get_user_preferences($name, $default = null, $user = null) {
    return $GLOBALS['prefs'][(int)$user][$name] ?? $default;
}
function set_user_preference($name, $value, $user = null) {
    $GLOBALS['prefs'][(int)$user][$name] = $value;
}
function get_string($name, $component) { return $name; }
require_once(__DIR__ . '/../classes/local/bms_main_tool_service.php');
use local_kbacompany\local\bms_main_tool_service as S;
function check($good, $name) { if (!$good) { throw new Exception($name); } }
function groups($withmanager = true) {
    $items = [];
    foreach (['workspace', 'battleplan', 'tasks', 'communications', 'architecturecenter', 'people', 'calendar',
                 'recurring', 'learning', 'employees', 'management', 'personalcycle'] as $key) {
        if ($key === 'management' && !$withmanager) { continue; }
        $items[] = ['key' => $key, 'label' => ucfirst($key), 'url' => '/route/' . $key, 'active' => false];
    }
    return [['key' => 'workspace', 'label' => 'Work', 'items' => $items]];
}
$all = groups();
check(S::resolve_main('management', $all) === 'management', 'authorized management tool');
check(S::resolve_main('management', groups(false)) === 'workspace', 'removed permissions fallback');
check(S::resolve_main('https://evil.example', $all) === 'workspace', 'malformed key fallback');
check(count(S::main_options(groups(false))) === 6, 'allowed choices');
check(S::filter_favorites(['tasks', 'tasks', 'missing', 'workspace', 'battleplan', 'people', 'calendar', 'learning'], $all)
    === ['tasks', 'workspace', 'battleplan', 'people', 'calendar'], 'deduplication, whitelist and five limit');
S::save('company-a', 42, $all, 'management', ['tasks', 'people']);
check(S::current('company-a', 42, $all)['main'] === 'management', 'persistence');
check(S::current('company-b', 42, $all)['main'] === 'workspace', 'separate company');
check(S::current('company-a', 43, $all)['main'] === 'workspace', 'separate users');
check(S::current('company-a', 42, groups(false))['main'] === 'workspace', 'loss of role fallback');
check(S::main_url('company-a', groups(false), 42) === '/route/workspace', 'secure fallback destination');
$failed = false;
try { S::save('company-a', 42, groups(false), 'management', []); } catch (moodle_exception $e) { $failed = true; }
check($failed, 'server-side rejection of restricted tool');
$failed = false;
try { S::save('company-a', 42, $all, 'http://attacker', []); } catch (moodle_exception $e) { $failed = true; }
check($failed, 'server-side rejection of arbitrary URLs');
$personal = S::personalise_groups('company-a', 42, $all);
check($personal[0]['key'] === 'personal_main' && $personal[0]['items'][0]['key'] === 'management', 'main group');
check($personal[1]['key'] === 'personal_favorites' && count($personal[1]['items']) === 2, 'favorite group');
check(count(S::visible_routes($personal)) === count(S::visible_routes($all)), 'no route duplication in trusted catalog');
S::dismiss('company-b', 42);
check(S::current('company-b', 42, $all)['intro_dismissed'] === true, 'dismiss onboarding');
check(S::current('company-a', 43, $all)['intro_dismissed'] === false, 'intro user isolation');
echo "BMS1803: 15 scenarios OK\n";
