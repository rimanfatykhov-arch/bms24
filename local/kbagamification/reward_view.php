<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\purchase_form;
use local_kbagamification\local\engine;
use local_kbagamification\local\reward_manager;
use local_kbagamification\local\navigation_manager;

$id = required_param('id', PARAM_INT);
require_login();
$context = context_system::instance();
require_capability('local/kbagamification:viewstore', $context);
require_capability('local/kbagamification:purchase', $context);
if (!reward_manager::is_store_enabled()) {
    throw new moodle_exception('rewardstoredisabled', 'local_kbagamification');
}
$reward = reward_manager::get_reward($id);
$wallet = engine::get_wallet($USER->id);
$eligibility = reward_manager::can_purchase($reward, $USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbagamification/reward_view.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(format_string($reward->name));
$PAGE->set_heading(format_string($reward->name));

$form = null;
if ($eligibility['allowed']) {
    $form = new purchase_form(null, ['reward' => $reward]);
    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/kbagamification/store.php'));
    } else if ($data = $form->get_data()) {
        $order = reward_manager::purchase($id, $USER->id, (array) $data);
        redirect(new moodle_url('/local/kbagamification/order_view.php', ['id' => $order->id]), get_string('rewardordercreated', 'local_kbagamification', $order->ordernumber));
    }
}

echo $OUTPUT->header();
echo navigation_manager::render_user_nav((int) $USER->id, 'rewards');
echo html_writer::start_div('kba-reward-detail card p-4');
echo html_writer::tag('div', reward_manager::icon_glyph($reward->icon), ['class' => 'kba-reward-detail-icon']);
echo $OUTPUT->heading(format_string($reward->name), 2);
echo html_writer::tag('p', format_text($reward->description, FORMAT_PLAIN));
echo html_writer::tag('p', '<strong>' . get_string('rewardprice', 'local_kbagamification') . ':</strong> ' . (int) $reward->costcoins . ' ' . get_string('coins', 'local_kbagamification'));
echo html_writer::tag('p', '<strong>' . get_string('yourbalance', 'local_kbagamification') . ':</strong> ' . (int) $wallet->coins . ' ' . get_string('coins', 'local_kbagamification'));
if ((int) $reward->stock >= 0) {
    echo html_writer::tag('p', '<strong>' . get_string('rewardstock', 'local_kbagamification') . ':</strong> ' . (int) $reward->stock);
}
if (!$eligibility['allowed']) {
    echo $OUTPUT->notification($eligibility['reason'], 'warning');
}
echo html_writer::end_div();
if ($form) {
    echo $OUTPUT->heading(get_string('placeorder', 'local_kbagamification'), 3);
    $form->display();
}
echo $OUTPUT->footer();
