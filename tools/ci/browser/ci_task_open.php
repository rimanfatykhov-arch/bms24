<?php
// CI-only helper for task_open.py on the disposable Moodle. Phases (synthetic data only):
//   rows <company> <task>              -> JSON: opened events of one task (actor, time), read-only
//   snapshot <company> <task>          -> JSON: business fields of the task + notification/message counts, read-only
//   readable <company> <task> <userid> -> JSON: canonical Task Center read access of one user, read-only
//   clear <company> <task>             -> deletes opened events of the synthetic task between cases
//   backdate <company> <task> <userid> <seconds> -> moves that user's opened events into the past (interval check)
//   shell <owned|legacy>               -> switches the BMS24 shell mode of the disposable site
//   lang <en|ru> <userid>              -> sets the user's language; for ru on this disposable site only, registers a minimal
//                                         ru langconfig (core strings fall back to English, plugin strings are the real RU file)
define('CLI_SCRIPT', true);
if (getenv('BMS_CI_BROWSER') !== '1') { fwrite(STDERR, "CI browser fixture only\n"); exit(2); }
require($argv[1] . '/config.php');
$phase = $argv[2] ?? '';
$company = (string)($argv[3] ?? ''); $task = (string)($argv[4] ?? '');
$where = ['companyuuid' => $company, 'taskuuid' => $task, 'action' => 'opened'];
$out = ['phase' => $phase];
if ($phase === 'rows') {
    $out['rows'] = array_values(array_map(static function($r) {
        return ['actorid' => (int)$r->actorid, 'timecreated' => (int)$r->timecreated,
            'oldstatus' => (string)$r->oldstatus, 'newstatus' => (string)$r->newstatus, 'details' => (string)$r->details];
    }, $DB->get_records('local_kbacompany_tasklog', $where, 'id ASC')));
} else if ($phase === 'snapshot') {
    $t = $DB->get_record('local_kbacompany_task', ['companyuuid' => $company, 'uuid' => $task], '*', MUST_EXIST);
    $fields = [];
    foreach (['status', 'assigneeid', 'reviewerid', 'observerid', 'revision', 'startdate', 'duedate', 'result',
            'factminutes', 'timemodified', 'dutypositionuuid'] as $f) {
        if (property_exists($t, $f)) { $fields[$f] = (string)$t->$f; }
    }
    $out['task'] = $fields;
    $out['business_log'] = $DB->count_records_select('local_kbacompany_tasklog',
        'companyuuid = :c AND taskuuid = :t AND action <> :o', ['c' => $company, 't' => $task, 'o' => 'opened']);
    $out['notifications'] = $DB->count_records('notifications') + $DB->count_records('messages')
        + $DB->count_records('local_kbacompany_commnotif');
} else if ($phase === 'readable') {
    $user = (int)($argv[5] ?? 0);
    \core\session\manager::set_user(\core_user::get_user($user, '*', MUST_EXIST));
    try {
        $out['readable'] = \local_kbacompany\local\task_service::readable_projection($company, $user, $task) !== null;
    } catch (moodle_exception $e) {
        $out['readable'] = false;
    }
} else if ($phase === 'clear') {
    $DB->delete_records('local_kbacompany_tasklog', $where);
} else if ($phase === 'backdate') {
    $DB->execute('UPDATE {local_kbacompany_tasklog} SET timecreated = timecreated - :s
        WHERE companyuuid = :c AND taskuuid = :t AND action = :a AND actorid = :u',
        ['s' => (int)($argv[6] ?? 0), 'c' => $company, 't' => $task, 'a' => 'opened', 'u' => (int)($argv[5] ?? 0)]);
} else if ($phase === 'lang' && in_array($argv[3] ?? '', ['en', 'ru'], true)) {
    $code = $argv[3]; $user = (int)($argv[4] ?? 0);
    if ($code === 'ru' && !get_string_manager()->translation_exists('ru', false)) {
        $dir = $CFG->dataroot . '/lang/ru';
        if (!is_dir($dir)) { mkdir($dir, $CFG->directorypermissions, true); }
        file_put_contents($dir . '/langconfig.php', "<?php
\$string['thislanguage'] = 'Русский';
\$string['parentlanguage'] = '';
");
        get_string_manager()->reset_caches();
    }
    $DB->set_field('user', 'lang', $code, ['id' => $user]);
    $out += ['lang' => $code, 'installed' => get_string_manager()->translation_exists($code, false),
        'sample' => get_string_manager()->get_string('taskcenter_history_opened', 'local_kbacompany', 'X', $code)];
} else if ($phase === 'shell' && in_array($argv[3] ?? '', ['owned', 'legacy'], true)) {
    set_config('bms24_shell_mode', $argv[3], 'local_kbacompany');
    $out['mode'] = get_config('local_kbacompany', 'bms24_shell_mode');
} else {
    fwrite(STDERR, "unknown phase\n"); exit(2);
}
echo json_encode($out) . PHP_EOL;
