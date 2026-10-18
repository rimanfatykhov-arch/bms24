<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\local\engine;
use local_kbagamification\local\reward_manager;
use local_kbagamification\local\navigation_manager;

require_login();
$context = context_system::instance();
require_capability('local/kbagamification:viewstore', $context);
if (!reward_manager::is_store_enabled()) {
    throw new moodle_exception('rewardstoredisabled', 'local_kbagamification');
}

$wallet = engine::get_wallet($USER->id);
$rewards = reward_manager::get_rewards(true);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbagamification/store.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('rewardstore', 'local_kbagamification'));
$PAGE->set_heading(get_string('rewardstore', 'local_kbagamification'));

$items = [];
foreach ($rewards as $reward) {
    $eligibility = reward_manager::can_purchase($reward, $USER->id);
    $levelname = '';
    if ($reward->minlevelid) {
        $level = $DB->get_record('local_kbagam_levels', ['id' => $reward->minlevelid]);
        $levelname = $level ? format_string($level->name) : '';
    }
    $items[] = [
        'id' => (int) $reward->id,
        'name' => format_string($reward->name),
        'description' => format_text($reward->description, FORMAT_PLAIN),
        'icon' => reward_manager::icon_glyph($reward->icon),
        'type' => get_string('rewardtype_' . $reward->rewardtype, 'local_kbagamification'),
        'cost' => (int) $reward->costcoins,
        'stock' => (int) $reward->stock,
        'stocklabel' => (int) $reward->stock < 0 ? get_string('unlimited', 'local_kbagamification') : (string) (int) $reward->stock,
        'levelname' => $levelname,
        'haslevel' => $levelname !== '',
        'allowed' => $eligibility['allowed'],
        'blockedreason' => $eligibility['reason'],
        'url' => (new moodle_url('/local/kbagamification/reward_view.php', ['id' => $reward->id]))->out(false),
    ];
}
$data = [
    'coins' => (int) $wallet->coins,
    'items' => $items,
    'hasitems' => !empty($items),
    'ordersurl' => (new moodle_url('/local/kbagamification/orders.php'))->out(false),
];
echo $OUTPUT->header();
echo navigation_manager::render_user_nav((int) $USER->id, 'rewards');
echo $OUTPUT->render_from_template('local_kbagamification/reward_store', $data);
echo $OUTPUT->footer();
