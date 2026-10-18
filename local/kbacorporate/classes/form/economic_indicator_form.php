<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Form for a company-specific economic indicator definition. */
final class economic_indicator_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', (int)$this->_customdata['companyid']);
        $mform->setType('companyid', PARAM_INT);

        $mform->addElement('text', 'code', get_string('economicregistrycode', 'local_kbacorporate'),
            ['maxlength' => 64, 'size' => 24]);
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addRule('code', null, 'required', null, 'client');
        $mform->addHelpButton('code', 'economicregistrycode', 'local_kbacorporate');

        $mform->addElement('text', 'name', get_string('economicregistryname', 'local_kbacorporate'),
            ['maxlength' => 255, 'size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('select', 'category', get_string('economicregistrycategory', 'local_kbacorporate'), [
            'finance' => get_string('economiccategoryfinance', 'local_kbacorporate'),
            'sales' => get_string('economiccategorysales', 'local_kbacorporate'),
            'operations' => get_string('economiccategoryoperations', 'local_kbacorporate'),
            'people' => get_string('economiccategorypeople', 'local_kbacorporate'),
            'clients' => get_string('economiccategoryclients', 'local_kbacorporate'),
        ]);

        $mform->addElement('select', 'valuetype', get_string('economicregistryvaluetype', 'local_kbacorporate'), [
            'money' => get_string('economictypemoney', 'local_kbacorporate'),
            'percent' => get_string('economictypepercent', 'local_kbacorporate'),
            'count' => get_string('economictypecount', 'local_kbacorporate'),
            'ratio' => get_string('economictyperatio', 'local_kbacorporate'),
            'days' => get_string('economictypedays', 'local_kbacorporate'),
        ]);
        $mform->addElement('text', 'unit', get_string('economicregistryunit', 'local_kbacorporate'),
            ['maxlength' => 30, 'size' => 16]);
        $mform->setType('unit', PARAM_TEXT);
        $mform->setDefault('unit', 'currency');
        $mform->addHelpButton('unit', 'economicregistryunit', 'local_kbacorporate');

        $mform->addElement('select', 'periodtype', get_string('economicregistryperiodicity', 'local_kbacorporate'), [
            'month' => get_string('economicperiodmonth', 'local_kbacorporate'),
            'quarter' => get_string('economicperiodquarter', 'local_kbacorporate'),
            'year' => get_string('economicperiodyear', 'local_kbacorporate'),
            'custom' => get_string('economicperiodcustom', 'local_kbacorporate'),
        ]);
        $mform->addElement('select', 'defaultsource', get_string('economicregistrydefaultsource', 'local_kbacorporate'), [
            'manual' => get_string('sourcemanual', 'local_kbacorporate'),
            'erp' => get_string('sourceerp', 'local_kbacorporate'),
            'import' => get_string('sourceimport', 'local_kbacorporate'),
            'api' => get_string('sourceapi', 'local_kbacorporate'),
        ]);
        $mform->addElement('select', 'direction', get_string('economicregistrydirection', 'local_kbacorporate'), [
            'up' => get_string('economicdirectionup', 'local_kbacorporate'),
            'down' => get_string('economicdirectiondown', 'local_kbacorporate'),
            'target' => get_string('economicdirectiontarget', 'local_kbacorporate'),
        ]);
        $mform->addElement('text', 'sortorder', get_string('sortorder'));
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 1000);
        $mform->addElement('advcheckbox', 'active', get_string('active'));
        $mform->setDefault('active', 1);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $companyid = (int)($data['companyid'] ?? 0);
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $code)) {
            $errors['code'] = get_string('economiccodeinvalid', 'local_kbacorporate');
        } else if ($code !== '' && $DB->record_exists_select('local_kbacorp_econ_ind',
                '(companyid = 0 OR companyid = :companyid) AND code = :code AND id <> :id',
                ['companyid' => $companyid, 'code' => $code, 'id' => $id])) {
            $errors['code'] = get_string('economiccodeduplicate', 'local_kbacorporate');
        }
        if (trim((string)($data['unit'] ?? '')) === '') {
            $errors['unit'] = get_string('required');
        }
        return $errors;
    }
}
