<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_kbagamification\local\reward_manager;

class purchase_form extends \moodleform {
    protected function definition() {
        global $USER;
        $mform = $this->_form;
        $reward = $this->_customdata['reward'];
        $mform->addElement('hidden', 'rewardid', (int) $reward->id);
        $mform->setType('rewardid', PARAM_INT);
        if ($reward->rewardtype === reward_manager::TYPE_PHYSICAL) {
            $mform->addElement('header', 'deliveryheader', get_string('deliverydetails', 'local_kbagamification'));
            $mform->addElement('text', 'deliveryname', get_string('deliveryname', 'local_kbagamification'), ['size' => 50]);
            $mform->setType('deliveryname', PARAM_TEXT);
            $mform->setDefault('deliveryname', fullname($USER));
            $mform->addRule('deliveryname', null, 'required', null, 'client');
            $mform->addElement('text', 'deliveryphone', get_string('deliveryphone', 'local_kbagamification'), ['size' => 30]);
            $mform->setType('deliveryphone', PARAM_TEXT);
            $mform->addRule('deliveryphone', null, 'required', null, 'client');
            $mform->addElement('textarea', 'deliveryaddress', get_string('deliveryaddress', 'local_kbagamification'), ['rows' => 3, 'cols' => 60]);
            $mform->setType('deliveryaddress', PARAM_TEXT);
            $mform->addRule('deliveryaddress', null, 'required', null, 'client');
        }
        $mform->addElement('textarea', 'usercomment', get_string('ordercomment', 'local_kbagamification'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('usercomment', PARAM_TEXT);
        $this->add_action_buttons(true, get_string('confirmrewardorder', 'local_kbagamification'));
    }
}
