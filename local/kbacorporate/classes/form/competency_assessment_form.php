<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Add one preliminary competency assessment to an audit. */
class competency_assessment_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $auditid = (int)$this->_customdata['auditid'];
        $competencies = $this->_customdata['competencies'] ?? [];

        $mform->addElement('hidden', 'id', $auditid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('autocomplete', 'competencyid', get_string('competencyname', 'local_kbacorporate'),
            $competencies, ['multiple' => false]);
        $mform->setType('competencyid', PARAM_INT);
        $mform->addRule('competencyid', null, 'required', null, 'client');
        $mform->addElement('select', 'method', get_string('auditmethod', 'local_kbacorporate'), [
            'self' => get_string('auditmethod_self', 'local_kbacorporate'),
            'test' => get_string('auditmethod_test', 'local_kbacorporate'),
            'interview' => get_string('auditmethod_interview', 'local_kbacorporate'),
            'practice' => get_string('auditmethod_practice', 'local_kbacorporate'),
            'expert' => get_string('auditmethod_expert', 'local_kbacorporate'),
            'ai' => get_string('auditmethod_ai', 'local_kbacorporate'),
        ]);
        $mform->setType('method', PARAM_ALPHA);
        $mform->setDefault('method', 'expert');
        $levels = [];
        for ($level = 0; $level <= 9; $level++) {
            $levels[$level] = (string)$level;
        }
        $mform->addElement('select', 'proposedlevel', get_string('auditproposedlevel', 'local_kbacorporate'), $levels);
        $mform->setType('proposedlevel', PARAM_INT);
        $mform->addElement('textarea', 'comment', get_string('auditcomment', 'local_kbacorporate'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addElement('static', 'pendingnote', '', get_string('auditpendingnote', 'local_kbacorporate'));
        $this->add_action_buttons(false, get_string('addassessment', 'local_kbacorporate'));
    }
}
