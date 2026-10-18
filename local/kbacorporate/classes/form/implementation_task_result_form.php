<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Employee submission of an implementation-task result and its evidence. */
class implementation_task_result_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', (int)$this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('textarea', 'completioncomment',
            get_string('implementationtaskresult', 'local_kbacorporate'),
            ['rows' => 5, 'cols' => 70, 'placeholder' => get_string(
                'implementationtaskresultplaceholder', 'local_kbacorporate')]);
        $mform->setType('completioncomment', PARAM_TEXT);
        $mform->addRule('completioncomment', null, 'required', null, 'client');
        $mform->addElement('url', 'resulturl',
            get_string('implementationtaskresulturl', 'local_kbacorporate'),
            ['size' => 70, 'maxlength' => 1333], ['usefilepicker' => false]);
        $mform->setType('resulturl', PARAM_URL);
        $mform->addElement('filemanager', 'evidencefiles',
            get_string('implementationtaskevidence', 'local_kbacorporate'), null,
            ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10]);
        $this->add_action_buttons(false, get_string('taskactionsubmit', 'local_kbacorporate'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string)($data['completioncomment'] ?? '')) === '') {
            $errors['completioncomment'] = get_string('taskresultrequired', 'local_kbacorporate');
        }
        return $errors;
    }
}
