<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\order_status_form;
use local_kbagamification\local\reward_manager;
use local_kbagamification\local\navigation_manager;

$id = required_param('id', PARAM_INT);
require_login();
$context = context_system::instance();
$order = reward_manager::get_order($id);
$canmanage = has_capability('local/kbagamification:manageorders', $context);
if ((int) $order->userid !== (int) $USER->id && !has_capability('local/kbagamification:viewallorders', $context)) {
    throw new required_capability_exception($context, 'local/kbagamification:viewallorders', 'nopermissions', '');
}

$statuses = reward_manager::get_statuses();
$form = null;
if ($canmanage && $order->status !== reward_manager::STATUS_CANCELLED && $order->status !== reward_manager::STATUS_RECEIVED) {
    $form = new order_status_form(null, ['order' => $order]);
    if ($data = $form->get_data()) {
        try {
            $order = reward_manager::change_status($id, $data->status, $USER->id, $data->admincomment);
            redirect(new moodle_url('/local/kbagamification/order_view.php', ['id' => $id]), get_string('orderstatusupdated', 'local_kbagamification'));
        } catch (moodle_exception $e) {
            redirect(new moodle_url('/local/kbagamification/order_view.php', ['id' => $id]), $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbagamification/order_view.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('rewardorder', 'local_kbagamification') . ' ' . $order->ordernumber);
$PAGE->set_heading(get_string('rewardorder', 'local_kbagamification') . ' ' . $order->ordernumber);

echo $OUTPUT->header();
if ((int) $order->userid === (int) $USER->id) {
    echo navigation_manager::render_user_nav((int) $USER->id, 'orders');
}
echo $OUTPUT->heading(get_string('rewardorder', 'local_kbagamification') . ' ' . s($order->ordernumber));
$table = new html_table();
$table->data[] = [get_string('reward', 'local_kbagamification'), reward_manager::icon_glyph($order->rewardicon) . ' ' . format_string($order->rewardname)];
$table->data[] = [get_string('user'), fullname($order) . ' (' . s($order->email) . ')'];
$table->data[] = [get_string('ordercost', 'local_kbagamification'), (int) $order->costcoins . ' ' . get_string('coins', 'local_kbagamification')];
$table->data[] = [get_string('orderstatus', 'local_kbagamification'), $statuses[$order->status] ?? s($order->status)];
$table->data[] = [get_string('date'), userdate($order->timecreated)];
if ($order->rewardtype === reward_manager::TYPE_PHYSICAL) {
    $table->data[] = [get_string('deliveryname', 'local_kbagamification'), s($order->deliveryname)];
    $table->data[] = [get_string('deliveryphone', 'local_kbagamification'), s($order->deliveryphone)];
    $table->data[] = [get_string('deliveryaddress', 'local_kbagamification'), nl2br(s($order->deliveryaddress))];
}
if ($order->usercomment) {
    $table->data[] = [get_string('ordercomment', 'local_kbagamification'), nl2br(s($order->usercomment))];
}
if ($order->admincomment) {
    $table->data[] = [get_string('orderadmincomment', 'local_kbagamification'), nl2br(s($order->admincomment))];
}
echo html_writer::table($table);
if ($order->status === reward_manager::STATUS_CANCELLED) {
    echo $OUTPUT->notification(get_string('ordercancelledrefundnote', 'local_kbagamification'), 'info');
}
if ($form) {
    echo $OUTPUT->heading(get_string('manageorder', 'local_kbagamification'), 3);
    $form->display();
}
echo $OUTPUT->footer();
