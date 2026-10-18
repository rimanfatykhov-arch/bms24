<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Create or edit one company-scoped business role. */
class business_role_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $companyid = (int)($this->_customdata['companyid'] ?? 0);
        $returncompanyid = (int)($this->_customdata['returncompanyid'] ?? $companyid);
        $scope = (string)($this->_customdata['scope'] ?? ($companyid === 0 ? 'global' : 'company'));

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('hidden', 'returncompanyid', $returncompanyid);
        $mform->setType('returncompanyid', PARAM_INT);
        $mform->addElement('hidden', 'scope', $scope);
        $mform->setType('scope', PARAM_ALPHA);

        $mform->addElement('text', 'code', get_string('businessrolecode', 'local_kbacorporate'), [
            'size' => 40,
            'maxlength' => 100,
        ]);
        $mform->setType('code', PARAM_TEXT);
        $mform->addRule('code', null, 'required', null, 'client');
        $mform->addHelpButton('code', 'businessrolecode', 'local_kbacorporate');

        $mform->addElement('text', 'name', get_string('businessrolename', 'local_kbacorporate'), [
            'size' => 60,
            'maxlength' => 255,
        ]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', get_string('description'), [
            'rows' => 5,
            'cols' => 70,
        ]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate'));
        $mform->setDefault('active', 1);

        if ($companyid === 0) {
            $mform->addElement('text', 'changenote', get_string('roletemplatechangenote', 'local_kbacorporate'), [
                'size' => 70,
                'maxlength' => 255,
            ]);
            $mform->setType('changenote', PARAM_TEXT);
            $mform->addHelpButton('changenote', 'roletemplatechangenote', 'local_kbacorporate');
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if (strpos($code, 'BMS-ROLE-') !== 0) {
            $errors['code'] = get_string('businessrolecodeinvalid', 'local_kbacorporate');
        }
        if ($code !== '' && $DB->record_exists_select(
                'local_kbacorp_busrole',
                'companyid = :companyid AND code = :code AND id <> :id',
                [
                    'companyid' => (int)($data['companyid'] ?? 0),
                    'code' => $code,
                    'id' => (int)($data['id'] ?? 0),
                ]
        )) {
            $errors['code'] = get_string('businessrolecodeduplicate', 'local_kbacorporate');
        }
        return $errors;
    }
}
