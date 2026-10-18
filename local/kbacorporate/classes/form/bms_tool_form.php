<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class bms_tool_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0); $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('text', 'code', get_string('bmstoolcode', 'local_kbacorporate'), ['maxlength' => 100]);
        $mform->setType('code', PARAM_TEXT); $mform->addRule('code', null, 'required', null, 'client');
        $mform->addElement('text', 'name', get_string('bmstoolname', 'local_kbacorporate'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT); $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('select', 'area', get_string('bmsarea', 'local_kbacorporate'), [
            'strategy' => get_string('bmsareastrategy', 'local_kbacorporate'),
            'finance' => get_string('bmsareafinance', 'local_kbacorporate'),
            'marketing' => get_string('bmsareamarketing', 'local_kbacorporate'),
            'operations' => get_string('bmsareaoperations', 'local_kbacorporate'),
            'people' => get_string('bmsareapeople', 'local_kbacorporate'),
            'management' => get_string('bmsareamanagement', 'local_kbacorporate'),
        ]);
        $scaleoptions = [];
        for ($score = 1; $score <= 10; $score++) {
            $scaleoptions[$score] = $score;
        }
        $mform->addElement('select', 'scalemax', get_string('bmsscalemax', 'local_kbacorporate'),
            $scaleoptions);
        $mform->setDefault('scalemax', 5);
        $thresholds = [];
        for ($score = 0; $score <= 10; $score++) {
            $thresholds[$score] = $score;
        }
        $mform->addElement('select', 'attentionthreshold',
            get_string('bmsattentionthreshold', 'local_kbacorporate'), $thresholds);
        $mform->setDefault('attentionthreshold', 3);
        $mform->addElement('select', 'indexmethod', get_string('bmsindexmethod', 'local_kbacorporate'), [
            \local_kbacorporate\local\implementation_audits::INDEX_WEIGHTED_VERIFIED =>
                get_string('bmsindexmethodweighted', 'local_kbacorporate'),
            \local_kbacorporate\local\implementation_audits::INDEX_AREA_AVERAGE =>
                get_string('bmsindexmethodareaaverage', 'local_kbacorporate'),
        ]);
        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 5, 'cols' => 70]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate'));
        $mform->setDefault('active', 1);
        if (!empty($this->_customdata['codefrozen'])) {
            $mform->freeze('code');
        }
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $companyid = (int)($data['companyid'] ?? 0);
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code !== '') {
            $params = ['companyid' => $companyid, 'code' => $code, 'id' => $id];
            if ($DB->record_exists_select('local_kbacorp_bms_tool',
                    'companyid = :companyid AND code = :code AND id <> :id', $params)) {
                $errors['code'] = get_string('bmstoolcodeduplicate', 'local_kbacorporate');
            }
        }
        if ((int)($data['attentionthreshold'] ?? 0) > (int)($data['scalemax'] ?? 0)) {
            $errors['attentionthreshold'] = get_string('bmsthresholdinvalid', 'local_kbacorporate');
        }
        return $errors;
    }
}
