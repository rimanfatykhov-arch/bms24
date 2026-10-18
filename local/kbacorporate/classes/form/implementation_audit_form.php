<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class implementation_audit_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $mform->addElement('hidden', 'companyid', $companyid); $mform->setType('companyid', PARAM_INT);
        $options = ['' => get_string('choosebmstool', 'local_kbacorporate')];
        foreach (\local_kbacorporate\local\implementation_audits::get_selectable_tools($companyid, true) as $tool) {
            $options[(int)$tool->id] = format_string($tool->name) . ' (' . s($tool->code) . ')';
        }
        $mform->addElement('select', 'toolid', get_string('bmstool', 'local_kbacorporate'), $options);
        $mform->setType('toolid', PARAM_INT);
        $mform->addRule('toolid', null, 'required', null, 'client');
        if (!empty($this->_customdata['defaulttoolid']) &&
                isset($options[(int)$this->_customdata['defaulttoolid']])) {
            $mform->setDefault('toolid', (int)$this->_customdata['defaulttoolid']);
        }
        $mform->addElement('text', 'title', get_string('bmsaudittitle', 'local_kbacorporate'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT); $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('select', 'audittype', get_string('bmsaudittype', 'local_kbacorporate'), [
            'baseline' => get_string('bmsauditbaseline', 'local_kbacorporate'),
            'progress' => get_string('bmsauditprogress', 'local_kbacorporate'),
            'final' => get_string('bmsauditfinal', 'local_kbacorporate'),
            'monitoring' => get_string('bmsauditmonitoring', 'local_kbacorporate'),
        ]);
        $mform->addElement('date_selector', 'auditdate', get_string('bmsauditdate', 'local_kbacorporate'));
        $this->add_action_buttons(true, get_string('createaudit', 'local_kbacorporate'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $companyid = (int)($data['companyid'] ?? 0);
        $toolid = (int)($data['toolid'] ?? 0);
        $allowed = [];
        foreach (\local_kbacorporate\local\implementation_audits::get_selectable_tools($companyid, true) as $tool) {
            $allowed[(int)$tool->id] = true;
        }
        if (!$toolid || empty($allowed[$toolid])) {
            $errors['toolid'] = get_string('bmstoolselectionrequired', 'local_kbacorporate');
        }
        return $errors;
    }
}
