<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Administrator form for one corporate licence package. */
class license_form extends \moodleform {
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $license = $this->_customdata['license'] ?? null;

        $mform->addElement('hidden', 'id', $license ? (int)$license->id : 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);

        $courses = [];
        $records = $DB->get_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
            'fullname ASC',
            'id,fullname,shortname'
        );
        foreach ($records as $course) {
            $courses[$course->id] = format_string($course->fullname) . ' (' . s($course->shortname) . ')';
        }
        $mform->addElement('autocomplete', 'courseid', get_string('course', 'local_kbacorporate'), $courses);
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $groups = [0 => get_string('nogroup', 'local_kbacorporate')];
        $grouprecords = $DB->get_records('groups', [], 'name ASC', 'id,name,courseid');
        $courseshortnames = $DB->get_records_menu('course', [], '', 'id,shortname');
        foreach ($grouprecords as $group) {
            if (isset($courseshortnames[$group->courseid])) {
                $groups[$group->id] = $courseshortnames[$group->courseid] . ' — ' . format_string($group->name);
            }
        }
        $mform->addElement('autocomplete', 'groupid', get_string('group', 'local_kbacorporate'), $groups);
        $mform->setType('groupid', PARAM_INT);
        $mform->setDefault('groupid', 0);

        $mform->addElement('text', 'seats', get_string('licenseseats', 'local_kbacorporate'));
        $mform->setType('seats', PARAM_INT);
        $mform->setDefault('seats', 0);
        $mform->addHelpButton('seats', 'licenseseats', 'local_kbacorporate');
        $mform->addRule('seats', null, 'numeric', null, 'client');

        $dateoptions = ['optional' => true];
        $mform->addElement('date_selector', 'datefrom', get_string('licensestart', 'local_kbacorporate'), $dateoptions);
        $mform->addElement('date_selector', 'dateto', get_string('licenseend', 'local_kbacorporate'), $dateoptions);

        $mform->addElement('select', 'source', get_string('licensesource', 'local_kbacorporate'), [
            \local_kbacorporate\local\licenses::SOURCE_MANUAL => get_string('licensesource_manual', 'local_kbacorporate'),
            \local_kbacorporate\local\licenses::SOURCE_BITRIX => get_string('licensesource_bitrix', 'local_kbacorporate'),
            \local_kbacorporate\local\licenses::SOURCE_LEGACY => get_string('licensesource_legacy', 'local_kbacorporate'),
        ]);
        $mform->setDefault('source', \local_kbacorporate\local\licenses::SOURCE_MANUAL);

        $mform->addElement('text', 'externaldealid', get_string('licenseexternaldealid', 'local_kbacorporate'));
        $mform->setType('externaldealid', PARAM_TEXT);
        $mform->addElement('text', 'externalrowid', get_string('licenseexternalrowid', 'local_kbacorporate'));
        $mform->setType('externalrowid', PARAM_TEXT);

        $mform->addElement('select', 'status', get_string('status', 'local_kbacorporate'), [
            \local_kbacorporate\local\licenses::STATUS_ACTIVE => get_string('licensestatus_active', 'local_kbacorporate'),
            \local_kbacorporate\local\licenses::STATUS_INACTIVE => get_string('licensestatus_inactive', 'local_kbacorporate'),
            \local_kbacorporate\local\licenses::STATUS_CANCELLED => get_string('licensestatus_cancelled', 'local_kbacorporate'),
        ]);
        $mform->setDefault('status', \local_kbacorporate\local\licenses::STATUS_ACTIVE);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);
        $courseid = (int)($data['courseid'] ?? 0);
        $groupid = (int)($data['groupid'] ?? 0);
        if ($groupid > 0) {
            $group = $DB->get_record('groups', ['id' => $groupid], 'id,courseid');
            if (!$group || (int)$group->courseid !== $courseid) {
                $errors['groupid'] = get_string('bridgegroupcoursemismatch', 'local_kbacorporate');
            }
        }
        if ((int)($data['seats'] ?? 0) < 0) {
            $errors['seats'] = get_string('licenseinvalidseats', 'local_kbacorporate');
        }
        $datefrom = (int)($data['datefrom'] ?? 0);
        $dateto = (int)($data['dateto'] ?? 0);
        if ($datefrom && $dateto && $dateto < $datefrom) {
            $errors['dateto'] = get_string('licensedateinvalid', 'local_kbacorporate');
        }
        if (($data['source'] ?? '') === \local_kbacorporate\local\licenses::SOURCE_BITRIX &&
                trim((string)($data['externaldealid'] ?? '')) === '' &&
                trim((string)($data['externalrowid'] ?? '')) === '') {
            $errors['externaldealid'] = get_string('licenseexternalrefrequired', 'local_kbacorporate');
        }
        return $errors;
    }
}
