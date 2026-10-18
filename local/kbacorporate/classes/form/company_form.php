<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class company_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('companyname', 'local_kbacorporate'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addElement('text', 'code', get_string('companycode', 'local_kbacorporate'), ['size' => 30]);
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addElement('text', 'bitrixcompanyid', get_string('bitrixcompanyid', 'local_kbacorporate'), ['size' => 30]);
        $mform->setType('bitrixcompanyid', PARAM_TEXT);
        $mform->addElement('text', 'primaryactivity', get_string('companyprimaryactivity', 'local_kbacorporate'),
            ['size' => 60, 'maxlength' => 255]);
        $mform->setType('primaryactivity', PARAM_TEXT);
        $mform->addHelpButton('primaryactivity', 'companyprimaryactivity', 'local_kbacorporate');
        $mform->addElement('text', 'foundedyear', get_string('companyfoundedyear', 'local_kbacorporate'),
            ['size' => 8, 'maxlength' => 4]);
        $mform->setType('foundedyear', PARAM_INT);
        $mform->addHelpButton('foundedyear', 'companyfoundedyear', 'local_kbacorporate');
        $mform->addElement('advcheckbox', 'active', get_string('active', 'local_kbacorporate'));
        $mform->setDefault('active', 1);

        $mform->addElement('header', 'trainingtimeheader', get_string('trainingplansettings', 'local_kbacorporate'));
        $mform->addElement('text', 'traininghoursday', get_string('traininghoursday', 'local_kbacorporate'), ['size' => 8]);
        $mform->setType('traininghoursday', PARAM_FLOAT);
        $mform->setDefault('traininghoursday', 1.5);
        $mform->addHelpButton('traininghoursday', 'traininghoursday', 'local_kbacorporate');

        $daylabels = [
            1 => get_string('weekday1', 'local_kbacorporate'),
            2 => get_string('weekday2', 'local_kbacorporate'),
            3 => get_string('weekday3', 'local_kbacorporate'),
            4 => get_string('weekday4', 'local_kbacorporate'),
            5 => get_string('weekday5', 'local_kbacorporate'),
            6 => get_string('weekday6', 'local_kbacorporate'),
            7 => get_string('weekday7', 'local_kbacorporate'),
        ];
        foreach ($daylabels as $day => $label) {
            $name = 'trainingday' . $day;
            $mform->addElement('advcheckbox', $name, $day === 1 ? get_string('trainingweekdays', 'local_kbacorporate') : '', $label);
            $mform->setDefault($name, $day <= 5 ? 1 : 0);
        }
        $mform->addElement('header', 'alertsheader', get_string('alertssettings', 'local_kbacorporate'));
        $mform->addElement('advcheckbox', 'alertsenabled', get_string('alertsenabled', 'local_kbacorporate'));
        $mform->setDefault('alertsenabled', 0);
        $mform->addHelpButton('alertsenabled', 'alertsenabled', 'local_kbacorporate');
        $mform->addElement('select', 'alertfrequency', get_string('alertfrequency', 'local_kbacorporate'), [
            'daily' => get_string('alertfrequency_daily', 'local_kbacorporate'),
            'weekly' => get_string('alertfrequency_weekly', 'local_kbacorporate'),
        ]);
        $mform->setDefault('alertfrequency', 'weekly');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $year = (int)($data['foundedyear'] ?? 0);
        if ($year !== 0 && ($year < 1800 || $year > (int)date('Y'))) {
            $errors['foundedyear'] = get_string('companyfoundedyearinvalid', 'local_kbacorporate', date('Y'));
        }
        return $errors;
    }
}
