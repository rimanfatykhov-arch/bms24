<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Form for a periodic economic indicator value. */
final class economic_value_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'companyid'] as $field) {
            $mform->addElement('hidden', $field, (int)($this->_customdata[$field] ?? 0));
            $mform->setType($field, PARAM_INT);
        }

        $mform->addElement('select', 'indicatorid', get_string('economicindicator', 'local_kbacorporate'),
            $this->_customdata['indicatoroptions']);
        $mform->setType('indicatorid', PARAM_INT);
        $mform->addRule('indicatorid', null, 'required', null, 'client');
        if (!empty($this->_customdata['freezeindicator'])) {
            $mform->freeze('indicatorid');
        }

        $mform->addElement('select', 'auditid', get_string('economicregistryaudit', 'local_kbacorporate'),
            $this->_customdata['auditoptions']);
        $mform->setType('auditid', PARAM_INT);

        $mform->addElement('header', 'periodheader', get_string('economicperiod', 'local_kbacorporate'));
        $mform->addElement('select', 'periodtype', get_string('economicperiodtype', 'local_kbacorporate'), [
            'month' => get_string('economicperiodmonth', 'local_kbacorporate'),
            'quarter' => get_string('economicperiodquarter', 'local_kbacorporate'),
            'year' => get_string('economicperiodyear', 'local_kbacorporate'),
            'custom' => get_string('economicperiodcustom', 'local_kbacorporate'),
        ]);
        $mform->addElement('date_selector', 'periodstart', get_string('periodstart', 'local_kbacorporate'));
        $mform->addElement('date_selector', 'periodend', get_string('periodend', 'local_kbacorporate'));
        $mform->addElement('static', 'periodnormalisation', '',
            get_string('economicperiodnormalisationhelp', 'local_kbacorporate'));

        $mform->addElement('header', 'valueheader', get_string('economicregistryvalue', 'local_kbacorporate'));
        $mform->addElement('select', 'scenario', get_string('economicscenario', 'local_kbacorporate'), [
            'actual' => get_string('economicscenarioactual', 'local_kbacorporate'),
            'plan' => get_string('economicscenarioplan', 'local_kbacorporate'),
            'forecast' => get_string('economicscenarioforecast', 'local_kbacorporate'),
        ]);
        $mform->addElement('text', 'value', get_string('economicvalue', 'local_kbacorporate'), ['size' => 30]);
        $mform->setType('value', PARAM_RAW_TRIMMED);
        $mform->addRule('value', null, 'required', null, 'client');
        $mform->addElement('select', 'currency', get_string('currency', 'local_kbacorporate'), [
            'RUB' => 'RUB — ₽', 'USD' => 'USD — $', 'EUR' => 'EUR — €', 'KZT' => 'KZT — ₸',
        ]);
        $mform->addElement('select', 'source', get_string('economicsource', 'local_kbacorporate'), [
            'manual' => get_string('sourcemanual', 'local_kbacorporate'),
            'erp' => get_string('sourceerp', 'local_kbacorporate'),
            'import' => get_string('sourceimport', 'local_kbacorporate'),
            'api' => get_string('sourceapi', 'local_kbacorporate'),
        ]);
        $mform->addElement('textarea', 'comment', get_string('comment'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addElement('advcheckbox', 'verified', get_string('economicverified', 'local_kbacorporate'));

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $companyid = (int)($data['companyid'] ?? 0);
        $indicatorid = (int)($data['indicatorid'] ?? 0);
        $valueid = (int)($data['id'] ?? 0);
        $number = \local_kbacorporate\local\economic_indicators::parse_decimal((string)($data['value'] ?? ''));
        if ($number === null) {
            $errors['value'] = get_string('economicinvalidnumber', 'local_kbacorporate');
        }
        if (($data['periodtype'] ?? '') === 'custom' &&
                (int)($data['periodend'] ?? 0) < (int)($data['periodstart'] ?? 0)) {
            $errors['periodend'] = get_string('periodendbeforestart', 'local_kbacorporate');
        }
        if ($indicatorid > 0) {
            $indicator = $DB->get_record('local_kbacorp_econ_ind', ['id' => $indicatorid]);
            if (!$indicator || ((int)$indicator->companyid !== 0 && (int)$indicator->companyid !== $companyid)) {
                $errors['indicatorid'] = get_string('companynotallowed', 'local_kbacorporate');
            }
        }
        $auditid = (int)($data['auditid'] ?? 0);
        if ($auditid > 0 && !$DB->record_exists('local_kbacorp_impl_audit', [
                'id' => $auditid, 'companyid' => $companyid])) {
            $errors['auditid'] = get_string('companynotallowed', 'local_kbacorporate');
        }
        if ($indicatorid > 0 && empty($errors['periodend'])) {
            try {
                $period = \local_kbacorporate\local\economic_indicators::normalise_period(
                    (string)$data['periodtype'], (int)$data['periodstart'], (int)$data['periodend']);
                $scenario = in_array((string)($data['scenario'] ?? ''),
                    \local_kbacorporate\local\economic_indicators::scenarios(), true)
                    ? (string)$data['scenario'] : 'actual';
                $duplicate = $DB->get_record('local_kbacorp_econ_val', [
                    'companyid' => $companyid,
                    'indicatorid' => $indicatorid,
                    'periodkey' => $period->periodkey,
                    'scenario' => $scenario,
                ]);
                if ($duplicate && (int)$duplicate->id !== $valueid) {
                    $errors['periodstart'] = get_string('economicperiodduplicate', 'local_kbacorporate');
                }
            } catch (\invalid_parameter_exception $exception) {
                $errors['periodend'] = get_string('periodendbeforestart', 'local_kbacorporate');
            }
        }
        return $errors;
    }
}
