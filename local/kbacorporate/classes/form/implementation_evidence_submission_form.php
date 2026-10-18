<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Submit one immutable version of an implementation-result evidence package. */
class implementation_evidence_submission_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'taskid', (int)$this->_customdata['taskid']);
        $mform->setType('taskid', PARAM_INT);

        $mform->addElement('textarea', 'summary',
            get_string('implevidencesummary', 'local_kbacorporate'), [
                'rows' => 6,
                'cols' => 80,
                'maxlength' => 20000,
                'placeholder' => get_string('implevidencesummaryplaceholder', 'local_kbacorporate'),
            ]);
        $mform->setType('summary', PARAM_TEXT);
        $mform->addRule('summary', null, 'required', null, 'client');

        $mform->addElement('url', 'externalurl',
            get_string('implevidenceexternalurl', 'local_kbacorporate'), [
                'size' => 80,
                'maxlength' => 1333,
                'placeholder' => 'https://',
            ], ['usefilepicker' => false]);
        $mform->setType('externalurl', PARAM_URL);

        $mform->addElement('filemanager', 'evidencefiles',
            get_string('implevidencefiles', 'local_kbacorporate'), null,
            \local_kbacorporate\local\implementation_evidence::file_options());

        $mform->addElement('advcheckbox', 'confirmaccuracy',
            get_string('implevidenceconfirmaccuracy', 'local_kbacorporate'));
        $mform->setType('confirmaccuracy', PARAM_BOOL);
        $mform->addRule('confirmaccuracy', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('implevidencesubmitbutton', 'local_kbacorporate'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string)($data['summary'] ?? '')) === '') {
            $errors['summary'] = get_string('implevidencesummaryrequired', 'local_kbacorporate');
        }
        if (empty($data['confirmaccuracy'])) {
            $errors['confirmaccuracy'] = get_string('implevidenceconfirmrequired', 'local_kbacorporate');
        }
        return $errors;
    }
}
