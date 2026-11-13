<?php
// Offline pure policy tests; do not simulate live DB/ACL or claim PHP 8.1 run.
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/bms_workspace_widget_service.php');
require_once(__DIR__ . '/../classes/local/bms_workspace_load_policy.php');
use local_kbacompany\local\bms_workspace_load_policy as Load;
use local_kbacompany\local\bms_workspace_widget_service as Widgets;
$count = 0;
function verify1806($condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $count++;
}
$all = Load::requirements(null);
foreach (['organization', 'processes', 'work', 'learning', 'management', 'legacy'] as $name) {
    verify1806($all[$name] === true, 'legacy caller retains full data: ' . $name);
}
$empty = Load::requirements([]);
foreach (['organization', 'processes', 'work', 'learning', 'management'] as $name) {
    verify1806($empty[$name] === false, 'empty visible cards must not hydrate: ' . $name);
}
$actions = Load::requirements(['actions']);
foreach (['organization', 'processes', 'work', 'learning', 'management'] as $name) {
    verify1806($actions[$name] === false, 'actions-only load has no heavy dependencies: ' . $name);
}
$today = Load::requirements(['today']);
verify1806(!$today['work'] && !$today['management'] && !$today['organization'], 'calendar-only');
$plan = Load::requirements(['plan']);
verify1806(!$plan['work'] && !$plan['management'], 'plan-only');
$arch = Load::requirements(['architecture']);
verify1806($arch['organization'] && $arch['processes'] && !$arch['work'], 'architecture dependencies');
$company = Load::requirements(['company']);
verify1806($company['organization'] && !$company['processes'], 'company dependencies');
$management = Load::requirements(['management']);
verify1806($management['management'] && !$management['work'] && !$management['organization'], 'manager dependencies');
$attention = Load::requirements(['attention']);
verify1806($attention['management'] && $attention['work'], 'attention includes both signals');
$tasks = Load::requirements(['tasks']);
verify1806($tasks['work'] && !$tasks['management'] && !$tasks['learning'], 'tasks-only');
$overview = Load::requirements(['overview']);
verify1806($overview['work'] && $overview['learning'] && !$overview['management'], 'overview requires learning');
$hidden = Load::requirements(['actions', 'evil', 'deleted-module']);
verify1806(!$hidden['work'] && !$hidden['management'] && !$hidden['organization'], 'unknown cards never activate loaders');
verify1806(Load::requirements(['architecture', 'architecture']) === $arch, 'duplicates ignored');
$everyone = Widgets::available(true);
$staff = Widgets::available(false);
verify1806(count($everyone) === 9 && count($staff) === 8, 'policy follows existing widget whitelist');
verify1806(!in_array('management', $staff, true), 'staff cannot select management card');
verify1806(Load::requirements(Widgets::defaults($everyone)['visible'])['management'], 'default manager loads management');
verify1806(!Load::requirements(Widgets::defaults($staff)['visible'])['legacy'], 'visibility list is opt-in');
echo "BMS1806: {$count} visibility dependency tests OK\n";
