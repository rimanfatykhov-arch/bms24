<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Economic indicator snapshot for an implementation audit. */
class implementation_economic_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        foreach (['auditid', 'companyid', 'id'] as $field) {
            $mform->addElement('hidden', $field, (int)($this->_customdata[$field] ?? 0));
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement('header', 'periodheader', get_string('economicperiod', 'local_kbacorporate'));
        $mform->addElement('select', 'periodtype', get_string('economicperiodtype', 'local_kbacorporate'), [
            'month' => get_string('economicperiodmonth', 'local_kbacorporate'),
            'quarter' => get_string('economicperiodquarter', 'local_kbacorporate'),
            'year' => get_string('economicperiodyear', 'local_kbacorporate'),
            'custom' => get_string('economicperiodcustom', 'local_kbacorporate'),
        ]);
        $mform->addElement('date_selector', 'periodstart', get_string('periodstart', 'local_kbacorporate'));
        $mform->addElement('date_selector', 'periodend', get_string('periodend', 'local_kbacorporate'));
        $mform->addElement('select', 'currency', get_string('currency', 'local_kbacorporate'), [
            'RUB' => 'RUB — ₽', 'USD' => 'USD — $', 'EUR' => 'EUR — €', 'KZT' => 'KZT — ₸',
        ]);

        $mform->addElement('header', 'financeheader', get_string('economicfinance', 'local_kbacorporate'));
        foreach (['revenue', 'grossprofit', 'operatingprofit', 'netprofit', 'payroll'] as $field) {
            $mform->addElement('text', $field, get_string('economic' . $field, 'local_kbacorporate'));
            $mform->setType($field, PARAM_RAW_TRIMMED);
        }
        $mform->addElement('header', 'taxheader', get_string('economictaxes', 'local_kbacorporate'));
        foreach (['profittax', 'vat', 'socialfunds', 'othertaxes'] as $field) {
            $mform->addElement('text', $field, get_string('economic' . $field, 'local_kbacorporate'));
            $mform->setType($field, PARAM_RAW_TRIMMED);
        }
        $mform->addElement('header', 'peopleheader', get_string('economicpeople', 'local_kbacorporate'));
        $mform->addElement('text', 'employees', get_string('economicemployees', 'local_kbacorporate'));
        $mform->setType('employees', PARAM_INT);
        $mform->addElement('text', 'averagesalary', get_string('economicaveragesalary', 'local_kbacorporate'));
        $mform->setType('averagesalary', PARAM_RAW_TRIMMED);

        $mform->addElement('header', 'evidenceheader', get_string('economicevidence', 'local_kbacorporate'));
        $mform->addElement('select', 'source', get_string('economicsource', 'local_kbacorporate'), [
            'manual' => get_string('sourcemanual', 'local_kbacorporate'),
            'file' => get_string('sourcefile', 'local_kbacorporate'),
            '1c' => '1С', 'odoo' => get_string('sourceodoo', 'local_kbacorporate'),
            'other' => get_string('sourceother', 'local_kbacorporate'),
        ]);
        $mform->addElement('filemanager', 'evidencefiles', get_string('economicfiles', 'local_kbacorporate'), null,
            ['subdirs' => 0, 'maxfiles' => 10, 'accepted_types' => ['.pdf', '.xlsx', '.xls', '.csv', '.jpg', '.png']]);
        $mform->addElement('textarea', 'comment', get_string('comment'), ['rows' => 4]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addElement('advcheckbox', 'verified', get_string('economicverified', 'local_kbacorporate'));
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ((int)$data['periodend'] < (int)$data['periodstart']) {
            $errors['periodend'] = get_string('periodendbeforestart', 'local_kbacorporate');
        }
        $nonnegative = ['revenue', 'profittax', 'vat', 'socialfunds', 'othertaxes',
            'payroll', 'averagesalary'];
        foreach (array_merge($nonnegative, ['grossprofit', 'operatingprofit', 'netprofit']) as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '' && \local_kbacorporate\local\implementation_audits::parse_decimal($value) === null) {
                $errors[$field] = get_string('economicinvalidnumber', 'local_kbacorporate');
            } else if ($value !== '' && in_array($field, $nonnegative, true)
                    && \local_kbacorporate\local\implementation_audits::parse_decimal($value) < 0) {
                $errors[$field] = get_string('economicnonnegative', 'local_kbacorporate');
            }
        }
        return $errors;
    }
}
