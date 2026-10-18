<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Attach a Moodle competency requirement to a BMS audit criterion. */
class bms_competency_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('hidden', 'toolid', (int)$this->_customdata['toolid']);
        $mform->setType('toolid', PARAM_INT);
        $mform->addElement('hidden', 'criterionid', (int)$this->_customdata['criterionid']);
        $mform->setType('criterionid', PARAM_INT);
        $mform->addElement('hidden', 'mappingid', (int)($this->_customdata['mappingid'] ?? 0));
        $mform->setType('mappingid', PARAM_INT);

        $mform->addElement('autocomplete', 'competencyid',
            get_string('competencyname', 'local_kbacorporate'), $this->_customdata['competencies'] ?? [],
            ['multiple' => false]);
        $mform->setType('competencyid', PARAM_INT);
        $mform->addRule('competencyid', null, 'required', null, 'client');

        $levels = [];
        for ($level = 0; $level <= 9; $level++) {
            $levels[$level] = (string)$level;
        }
        $mform->addElement('select', 'targetlevel', get_string('targetlevel', 'local_kbacorporate'), $levels);
        $mform->setType('targetlevel', PARAM_INT);
        $mform->setDefault('targetlevel', 1);
        $mform->addHelpButton('targetlevel', 'bmscompetencytargetlevel', 'local_kbacorporate');

        $mform->addElement('select', 'priority', get_string('rolepriority', 'local_kbacorporate'), [
            'critical' => get_string('rolepriority_critical', 'local_kbacorporate'),
            'core' => get_string('rolepriority_core', 'local_kbacorporate'),
            'additional' => get_string('rolepriority_additional', 'local_kbacorporate'),
        ]);
        $mform->setType('priority', PARAM_ALPHA);
        $mform->setDefault('priority', 'core');
        $mform->addElement('advcheckbox', 'required', get_string('bmscompetencyrequired', 'local_kbacorporate'));
        $mform->setDefault('required', 1);
        $this->add_action_buttons(true, get_string(!empty($this->_customdata['mappingid']) ? 'savechanges' : 'linkcompetency', 'local_kbacorporate'));
    }
}
