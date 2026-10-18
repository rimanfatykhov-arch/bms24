<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_kbagamification\local\achievement_manager;

require_login();
admin_externalpage_setup('local_kbagamification_achievement_report');
require_capability('local/kbagamification:viewreports', context_system::instance());

$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$total = $DB->count_records('local_kbagam_userach');
$sql = "SELECT ua.id, ua.userid, ua.timeawarded, ua.source,
               u.firstname, u.lastname, u.email,
               a.name, a.icon, a.rarity
          FROM {local_kbagam_userach} ua
          JOIN {user} u ON u.id = ua.userid
          JOIN {local_kbagam_achievements} a ON a.id = ua.achievementid
      ORDER BY ua.timeawarded DESC, ua.id DESC";
$records = $DB->get_records_sql($sql, [], $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('achievementreport', 'local_kbagamification'));
echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/kbagamification/achievement_report.php'));

$table = new html_table();
$table->head = [
    get_string('date'),
    get_string('user'),
    get_string('achievement', 'local_kbagamification'),
    get_string('rarity', 'local_kbagamification'),
    get_string('source', 'local_kbagamification'),
];
foreach ($records as $record) {
    $userurl = new moodle_url('/local/kbagamification/index.php', ['userid' => $record->userid]);
    $user = (object) ['firstname' => $record->firstname, 'lastname' => $record->lastname];
    $table->data[] = [
        userdate($record->timeawarded),
        html_writer::link($userurl, s(fullname($user))) . html_writer::empty_tag('br') . s($record->email),
        achievement_manager::icon_glyph($record->icon) . ' ' . format_string($record->name),
        get_string('rarity_' . $record->rarity, 'local_kbagamification'),
        s($record->source),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/kbagamification/achievement_report.php'));
echo $OUTPUT->footer();
