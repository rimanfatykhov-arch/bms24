<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Add or edit one competency requirement in a business-role profile. */
class role_competency_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $roleid = (int)$this->_customdata['roleid'];
        $companyid = (int)$this->_customdata['companyid'];
        $competencyoptions = $this->_customdata['competencies'] ?? [];
        $editing = !empty($this->_customdata['editing']);
        $isglobal = !empty($this->_customdata['isglobal']);

        $mform->addElement('hidden', 'roleid', $roleid);
        $mform->setType('roleid', PARAM_INT);
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('hidden', 'rolecompetencyid', 0);
        $mform->setType('rolecompetencyid', PARAM_INT);

        $mform->addElement('autocomplete', 'competencyid', get_string('competencyname', 'local_kbacorporate'),
            $competencyoptions, ['multiple' => false]);
        $mform->setType('competencyid', PARAM_INT);
        $mform->addRule('competencyid', null, 'required', null, 'client');
        if ($editing) {
            $mform->freeze('competencyid');
        }

        $levels = [];
        for ($level = 0; $level <= 9; $level++) {
            $levels[$level] = (string)$level;
        }
        $mform->addElement('select', 'targetlevel', get_string('targetlevel', 'local_kbacorporate'), $levels);
        $mform->setType('targetlevel', PARAM_INT);
        $mform->addHelpButton('targetlevel', 'roletargetlevel', 'local_kbacorporate');

        $mform->addElement('select', 'priority', get_string('rolepriority', 'local_kbacorporate'), [
            'critical' => get_string('rolepriority_critical', 'local_kbacorporate'),
            'core' => get_string('rolepriority_core', 'local_kbacorporate'),
            'additional' => get_string('rolepriority_additional', 'local_kbacorporate'),
        ]);
        $mform->setType('priority', PARAM_ALPHA);
        $mform->setDefault('priority', 'core');

        $mform->addElement('advcheckbox', 'required', get_string('rolerequired', 'local_kbacorporate'));
        $mform->setDefault('required', 1);

        if ($isglobal) {
            $mform->addElement('text', 'changenote', get_string('roletemplatechangenote', 'local_kbacorporate'), [
                'size' => 70,
                'maxlength' => 255,
            ]);
            $mform->setType('changenote', PARAM_TEXT);
        }

        $this->add_action_buttons(true, $editing ? get_string('savechanges') :
            get_string('addrolecompetency', 'local_kbacorporate'));
    }
}
