<?php
namespace local_kbacompany\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** First step only: preview an identity binding; this form never writes business data. */
class integration_mapping_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'uuid', $this->_customdata['companyuuid']);
        $mform->setType('uuid', PARAM_RAW_TRIMMED);
        $mform->addElement('select', 'provider', get_string('bms_int_provider', 'local_kbacompany'),
            ['odoo' => 'BMS ERP', 'bitrix24' => 'Битрикс24']);
        $mform->addElement('text', 'environment', get_string('bms_int_environment', 'local_kbacompany'),
            ['maxlength' => 64]);
        $mform->setType('environment', PARAM_RAW_TRIMMED);
        $mform->addRule('environment', null, 'required', null, 'client');
        $mform->addElement('static', 'envhelp', '', get_string('bms_int_environment_help', 'local_kbacompany'));
        $mform->addElement('autocomplete', 'target', get_string('bms_int_target', 'local_kbacompany'),
            $this->_customdata['targets']);
        $mform->setType('target', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'manualtarget', get_string('bms_int_manual_target', 'local_kbacompany'),
            ['maxlength' => 50]);
        $mform->setType('manualtarget', PARAM_RAW_TRIMMED);
        $mform->addElement('static', 'targethelp', '', get_string('bms_int_manual_target_help', 'local_kbacompany'));
        $mform->addElement('text', 'externalmodel', get_string('bms_int_external_model', 'local_kbacompany'),
            ['maxlength' => 64]);
        $mform->setType('externalmodel', PARAM_RAW_TRIMMED);
        $mform->addRule('externalmodel', null, 'required', null, 'client');
        $mform->addElement('static', 'modelhelp', '', get_string('bms_int_model_help', 'local_kbacompany'));
        $mform->addElement('text', 'externalid', get_string('bms_int_external_id', 'local_kbacompany'),
            ['maxlength' => 100]);
        $mform->setType('externalid', PARAM_RAW_TRIMMED);
        $mform->addRule('externalid', null, 'required', null, 'client');
        $mform->addElement('static', 'idhelp', '', get_string('bms_int_id_help', 'local_kbacompany'));
        $this->add_action_buttons(true, get_string('bms_int_preview', 'local_kbacompany'));
    }
}
