<?php
namespace local_kbagamification\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_kbagamification\local\reward_manager;

class reward_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('rewardname', 'local_kbagamification'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('textarea', 'description', get_string('rewarddescription', 'local_kbagamification'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addElement('select', 'rewardtype', get_string('rewardtype', 'local_kbagamification'), reward_manager::get_types());
        $mform->addElement('select', 'icon', get_string('rewardicon', 'local_kbagamification'), reward_manager::get_icons());
        $mform->addElement('text', 'sku', get_string('rewardsku', 'local_kbagamification'), ['size' => 30]);
        $mform->setType('sku', PARAM_TEXT);
        $mform->addElement('text', 'costcoins', get_string('rewardcostcoins', 'local_kbagamification'), ['size' => 12]);
        $mform->setType('costcoins', PARAM_INT);
        $mform->setDefault('costcoins', 500);
        $mform->addElement('text', 'stock', get_string('rewardstock', 'local_kbagamification'), ['size' => 12]);
        $mform->setType('stock', PARAM_INT);
        $mform->setDefault('stock', -1);
        $mform->addHelpButton('stock', 'rewardstock', 'local_kbagamification');
        $levels = [0 => get_string('nolevelrequirement', 'local_kbagamification')];
        foreach ($DB->get_records('local_kbagam_levels', ['enabled' => 1], 'minxp ASC, id ASC') as $level) {
            $levels[$level->id] = format_string($level->name) . ' (' . (int) $level->minxp . ' XP)';
        }
        $mform->addElement('select', 'minlevelid', get_string('rewardminlevel', 'local_kbagamification'), $levels);
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_kbagamification'));
        $mform->setDefault('enabled', 1);
        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_kbagamification'), ['size' => 10]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 10);
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if ((int) $data['costcoins'] < 0) {
            $errors['costcoins'] = get_string('rewardcostinvalid', 'local_kbagamification');
        }
        if ((int) $data['stock'] < -1) {
            $errors['stock'] = get_string('rewardstockinvalid', 'local_kbagamification');
        }
        if (!empty($data['minlevelid']) && !$DB->record_exists('local_kbagam_levels', ['id' => (int) $data['minlevelid']])) {
            $errors['minlevelid'] = get_string('invalidlevel', 'local_kbagamification');
        }
        return $errors;
    }
}
