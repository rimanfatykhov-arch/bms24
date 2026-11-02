<?php
/** Pure PHP tests for reviewer queue quick-filter and departmental rollup. */
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/battle_plan_approval_queue_service.php');
use local_kbacompany\local\battle_plan_approval_queue_service as Queue;
$assert = static function($condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$entry = static function(int $id, string $uuid, string $name, bool $advisory): array {
    return ['row' => ['plan_uuid' => (string)$id,
        'orgnode' => ['uuid' => $uuid, 'name' => $name]],
        'assessment' => ['advisory' => $advisory], 'corrections' => []];
};
$details = [];
for ($i = 0; $i < 24; $i++) {
    $details[] = $entry($i + 1, $i % 3 === 0 ? 'dept-a' : 'dept-b', 'Продажи', $i % 4 === 0);
}
$all = Queue::summarize($details, 'all');
$assert($all['counts'] === ['all'=>24, 'ready'=>18, 'review'=>6], 'counts across all 24 plans');
$assert(count($all['rows']) === 24, 'no early paging');
$assert(count($all['units']) === 2, 'do not merge two orgnodes of same name');
$assert($all['units'][0]['all'] === 16 && $all['units'][1]['all'] === 8, 'dept grouping order');
$ready = Queue::summarize($details, 'ready');
$assert(count($ready['rows']) === 18 && $ready['counts'] === $all['counts'], 'ready filter leaves counts stable');
$assert($ready['units'] === $all['units'], 'ready filter leaves department table stable');
$review = Queue::summarize($details, 'review');
$assert(count($review['rows']) === 6, 'review filter');
$assert(count(array_slice($review['rows'], 0, 10)) === 6, 'paginate after filtering');
$invalid = Queue::summarize($details, 'approve');
$assert($invalid['filter'] === 'all', 'invalid filter resets to all');
$empty = Queue::summarize([], 'ready');
$assert($empty['counts']['all'] === 0 && !$empty['units'], 'empty queue');
$unassigned = Queue::summarize([$entry(1, '', '', false)]);
$assert(count($unassigned['units']) === 1 && $unassigned['units'][0]['uuid'] === '', 'no-unit grouping');
echo "approval queue filters and department rollup PASS\n";
