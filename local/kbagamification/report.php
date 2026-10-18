<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
admin_externalpage_setup('local_kbagamification_report');
require_capability('local/kbagamification:viewreports', context_system::instance());

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$total = $DB->count_records('local_kbagam_txn');
$sql = "SELECT t.*, u.firstname, u.lastname, u.email
          FROM {local_kbagam_txn} t
          JOIN {user} u ON u.id = t.userid
      ORDER BY t.timecreated DESC, t.id DESC";
$transactions = $DB->get_records_sql($sql, [], $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transactions', 'local_kbagamification'));

echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/kbagamification/report.php'));
$table = new html_table();
$table->head = [
    get_string('date'),
    get_string('user'),
    get_string('currency', 'local_kbagamification'),
    get_string('amount', 'local_kbagamification'),
    get_string('balanceafter', 'local_kbagamification'),
    get_string('reason', 'local_kbagamification'),
];
foreach ($transactions as $transaction) {
    $userurl = new moodle_url('/local/kbagamification/index.php', ['userid' => $transaction->userid]);
    $name = fullname((object) ['firstname' => $transaction->firstname, 'lastname' => $transaction->lastname]);
    $table->data[] = [
        userdate($transaction->timecreated),
        html_writer::link($userurl, s($name)) . html_writer::empty_tag('br') . s($transaction->email),
        $transaction->currency === 'COIN' ? get_string('coins', 'local_kbagamification') : get_string('xp', 'local_kbagamification'),
        ($transaction->amount > 0 ? '+' : '') . (int) $transaction->amount,
        (int) $transaction->balanceafter,
        format_text($transaction->reason, FORMAT_PLAIN),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/kbagamification/report.php'));
echo $OUTPUT->footer();
