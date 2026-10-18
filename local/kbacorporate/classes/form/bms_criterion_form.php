<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class bms_criterion_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0); $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'toolid', (int)$this->_customdata['toolid']); $mform->setType('toolid', PARAM_INT);
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']); $mform->setType('companyid', PARAM_INT);
        $mform->addElement('text', 'code', get_string('bmscriterioncode', 'local_kbacorporate'), ['maxlength' => 100]);
        $mform->setType('code', PARAM_TEXT); $mform->addRule('code', null, 'required', null, 'client');
        $mform->addElement('text', 'name', get_string('bmscriterionname', 'local_kbacorporate'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT); $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'sectioncode', get_string('bmssectioncode', 'local_kbacorporate'),
            ['maxlength' => 50]);
        $mform->setType('sectioncode', PARAM_TEXT);
        $mform->addElement('text', 'sectionname', get_string('bmssectionname', 'local_kbacorporate'),
            ['size' => 60, 'maxlength' => 255]);
        $mform->setType('sectionname', PARAM_TEXT);
        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addElement('textarea', 'minlabel', get_string('bmsminimumleveldesc', 'local_kbacorporate'),
            ['rows' => 3, 'cols' => 70]);
        $mform->setType('minlabel', PARAM_TEXT);
        $mform->addElement('textarea', 'maxlabel', get_string('bmsmaximumleveldesc', 'local_kbacorporate'),
            ['rows' => 3, 'cols' => 70]);
        $mform->setType('maxlabel', PARAM_TEXT);
        $mform->addElement('textarea', 'evidencehint', get_string('bmsevidencehint', 'local_kbacorporate'), ['rows' => 3, 'cols' => 70]);
        $mform->setType('evidencehint', PARAM_TEXT);
        $scale = [-1 => get_string('targetscorenotset', 'local_kbacorporate')];
        for ($score = 0; $score <= (int)$this->_customdata['scalemax']; $score++) { $scale[$score] = $score; }
        $mform->addElement('select', 'targetscore', get_string('targetscore', 'local_kbacorporate'), $scale);
        $mform->setDefault('targetscore', -1);
        $mform->addElement('text', 'weight', get_string('bmscriterionweight', 'local_kbacorporate')); $mform->setType('weight', PARAM_INT);
        $mform->setDefault('weight', 1); $mform->addRule('weight', null, 'required', null, 'client');
        $mform->addElement('text', 'sortorder', get_string('sortorder')); $mform->setType('sortorder', PARAM_INT); $mform->setDefault('sortorder', 10);
        $mform->addElement('advcheckbox', 'required', get_string('bmscriterionrequired', 'local_kbacorporate')); $mform->setDefault('required', 1);
        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate')); $mform->setDefault('active', 1);
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $id = (int)($data['id'] ?? 0);
        $toolid = (int)($data['toolid'] ?? 0);
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code !== '' && $DB->record_exists_select('local_kbacorp_bms_crit',
                'toolid = :toolid AND code = :code AND id <> :id',
                ['toolid' => $toolid, 'code' => $code, 'id' => $id])) {
            $errors['code'] = get_string('bmscriterioncodeduplicate', 'local_kbacorporate');
        }
        if ((int)($data['weight'] ?? 0) < 1) {
            $errors['weight'] = get_string('bmscriterionweightinvalid', 'local_kbacorporate');
        }
        return $errors;
    }
}
