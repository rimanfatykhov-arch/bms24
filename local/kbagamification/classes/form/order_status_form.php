<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_kbagamification\local\reward_manager;

class order_status_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $order = $this->_customdata['order'];
        $mform->addElement('hidden', 'id', (int) $order->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('select', 'status', get_string('orderstatus', 'local_kbagamification'), reward_manager::get_statuses());
        $mform->setDefault('status', $order->status);
        $mform->addElement('textarea', 'admincomment', get_string('orderadmincomment', 'local_kbagamification'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('admincomment', PARAM_TEXT);
        $mform->setDefault('admincomment', $order->admincomment);
        $this->add_action_buttons(false, get_string('updateorderstatus', 'local_kbagamification'));
    }
}
