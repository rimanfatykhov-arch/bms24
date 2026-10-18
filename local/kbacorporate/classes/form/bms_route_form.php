<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class bms_route_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'companyid', 'criterionid', 'toolid'] as $field) {
            $mform->addElement('hidden', $field, (int)($this->_customdata[$field] ?? 0));
            $mform->setType($field, PARAM_INT);
        }
        $tools = [0 => get_string('none')];
        foreach ($this->_customdata['tools'] as $tool) { $tools[$tool->id] = format_string($tool->name); }
        $courses = [0 => get_string('none')];
        foreach ($this->_customdata['courses'] as $course) { $courses[$course->courseid] = format_string($course->fullname); }
        $mform->addElement('header', 'implementationroute',
            get_string('implementationrouteimplementation', 'local_kbacorporate'));
        $mform->addElement('static', 'routeprinciple', '',
            get_string('implementationrouteprinciple', 'local_kbacorporate'));
        $mform->addElement('select', 'actiontoolid',
            get_string('implementationtool', 'local_kbacorporate'), $tools);
        $mform->addRule('actiontoolid', get_string('implementationtoolrequired', 'local_kbacorporate'),
            'required', null, 'client');
        $mform->addHelpButton('actiontoolid', 'implementationtool', 'local_kbacorporate');
        $mform->addElement('textarea', 'expectedresult',
            get_string('implementationrouteexpectedresult', 'local_kbacorporate'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('expectedresult', PARAM_TEXT);
        $mform->addHelpButton('expectedresult', 'implementationrouteexpectedresult', 'local_kbacorporate');
        $mform->addElement('textarea', 'evidencecriteria',
            get_string('implementationrouteevidence', 'local_kbacorporate'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('evidencecriteria', PARAM_TEXT);
        $mform->addHelpButton('evidencecriteria', 'implementationrouteevidence', 'local_kbacorporate');

        $mform->addElement('header', 'learningroute',
            get_string('implementationroutelearning', 'local_kbacorporate'));
        $mform->addElement('select', 'courseid',
            get_string('implementationcourseoptional', 'local_kbacorporate'), $courses);
        $mform->addHelpButton('courseid', 'implementationcourseoptional', 'local_kbacorporate');
        $mform->addElement('select', 'priority', get_string('routepriority', 'local_kbacorporate'), [
            'critical' => get_string('gapprioritycritical', 'local_kbacorporate'),
            'core' => get_string('gapprioritycore', 'local_kbacorporate'),
            'additional' => get_string('gappriorityadditional', 'local_kbacorporate'),
        ]);
        $mform->addElement('textarea', 'note', get_string('routenote', 'local_kbacorporate'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('note', PARAM_TEXT);
        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate'));
        $mform->setDefault('active', 1);
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['actiontoolid'])) {
            $errors['actiontoolid'] = get_string('implementationtoolrequired', 'local_kbacorporate');
        }
        return $errors;
    }
}
