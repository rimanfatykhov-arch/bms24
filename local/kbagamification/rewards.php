<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_kbagamification\local\reward_manager;

require_login();
admin_externalpage_setup('local_kbagamification_rewards');
require_capability('local/kbagamification:managecatalog', context_system::instance());

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && confirm_sesskey()) {
    if ($DB->record_exists('local_kbagam_orders', ['rewardid' => $delete])) {
        redirect(new moodle_url('/local/kbagamification/rewards.php'), get_string('rewardcannotdelete', 'local_kbagamification'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $DB->delete_records('local_kbagam_rewards', ['id' => $delete]);
    redirect(new moodle_url('/local/kbagamification/rewards.php'), get_string('rewarddeleted', 'local_kbagamification'));
}

$rewards = reward_manager::get_rewards(false);
$PAGE->set_title(get_string('rewardcatalog', 'local_kbagamification'));
$PAGE->set_heading(get_string('rewardcatalog', 'local_kbagamification'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rewardcatalog', 'local_kbagamification'));
echo $OUTPUT->notification(get_string('rewardcatalognote', 'local_kbagamification'), 'info');
echo $OUTPUT->single_button(new moodle_url('/local/kbagamification/reward_edit.php'), get_string('addreward', 'local_kbagamification'), 'get');

$table = new html_table();
$table->head = [get_string('reward', 'local_kbagamification'), get_string('rewardtype', 'local_kbagamification'), get_string('rewardcostcoins', 'local_kbagamification'), get_string('rewardstock', 'local_kbagamification'), get_string('rewardminlevel', 'local_kbagamification'), get_string('enabled', 'local_kbagamification'), get_string('actions')];
foreach ($rewards as $reward) {
    $level = '';
    if ($reward->minlevelid) {
        $levelrec = $DB->get_record('local_kbagam_levels', ['id' => $reward->minlevelid]);
        $level = $levelrec ? format_string($levelrec->name) : '-';
    }
    $edit = html_writer::link(new moodle_url('/local/kbagamification/reward_edit.php', ['id' => $reward->id]), get_string('edit'));
    $deleteurl = new moodle_url('/local/kbagamification/rewards.php', ['delete' => $reward->id, 'sesskey' => sesskey()]);
    $del = html_writer::link($deleteurl, get_string('delete'), ['onclick' => "return confirm('" . addslashes_js(get_string('confirmdeletereward', 'local_kbagamification')) . "');"]);
    $table->data[] = [
        reward_manager::icon_glyph($reward->icon) . ' ' . format_string($reward->name),
        get_string('rewardtype_' . $reward->rewardtype, 'local_kbagamification'),
        (int) $reward->costcoins . ' ' . get_string('coins', 'local_kbagamification'),
        ((int) $reward->stock < 0 ? get_string('unlimited', 'local_kbagamification') : (int) $reward->stock),
        $level ?: get_string('nolevelrequirement', 'local_kbagamification'),
        $reward->enabled ? get_string('yes') : get_string('no'),
        $edit . ' | ' . $del,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
