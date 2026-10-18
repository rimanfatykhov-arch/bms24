<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\local\reward_manager;
use local_kbagamification\local\navigation_manager;

$all = optional_param('all', 0, PARAM_BOOL);
$status = optional_param('status', '', PARAM_ALPHA);
require_login();
$context = context_system::instance();

if ($all) {
    require_capability('local/kbagamification:viewallorders', $context);
} else {
    require_capability('local/kbagamification:viewstore', $context);
}
$statuses = reward_manager::get_statuses();
if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
$orders = $all ? reward_manager::get_all_orders($status) : reward_manager::get_user_orders($USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbagamification/orders.php', ['all' => $all]));
$PAGE->set_pagelayout('standard');
$heading = $all ? get_string('allrewardorders', 'local_kbagamification') : get_string('myrewardorders', 'local_kbagamification');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

echo $OUTPUT->header();
if (!$all) {
    echo navigation_manager::render_user_nav((int) $USER->id, 'orders');
}
echo $OUTPUT->heading($heading);
if ($all) {
    $options = ['' => get_string('allstatuses', 'local_kbagamification')] + $statuses;
    $select = new single_select(new moodle_url('/local/kbagamification/orders.php', ['all' => 1]), 'status', $options, $status, null);
    echo $OUTPUT->render($select);
}
if (!$orders) {
    echo $OUTPUT->notification(get_string('norewardorders', 'local_kbagamification'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('ordernumber', 'local_kbagamification'), get_string('reward', 'local_kbagamification')];
    if ($all) {
        $table->head[] = get_string('user');
    }
    $table->head = array_merge($table->head, [get_string('ordercost', 'local_kbagamification'), get_string('orderstatus', 'local_kbagamification'), get_string('date'), get_string('actions')]);
    foreach ($orders as $order) {
        $row = [s($order->ordernumber), reward_manager::icon_glyph($order->rewardicon) . ' ' . format_string($order->rewardname)];
        if ($all) {
            $row[] = fullname($order) . '<br><small>' . s($order->email) . '</small>';
        }
        $row[] = (int) $order->costcoins . ' ' . get_string('coins', 'local_kbagamification');
        $row[] = $statuses[$order->status] ?? s($order->status);
        $row[] = userdate($order->timecreated);
        $row[] = html_writer::link(new moodle_url('/local/kbagamification/order_view.php', ['id' => $order->id]), get_string('view'));
        $table->data[] = $row;
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
