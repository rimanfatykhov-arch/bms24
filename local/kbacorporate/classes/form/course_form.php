<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class course_form extends \moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $companyid = (int)$this->_customdata['companyid'];
        $mform->addElement('hidden', 'companyid', $companyid);
        $mform->setType('companyid', PARAM_INT);

        $courses = [];
        $records = $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], 'fullname ASC', 'id,fullname,shortname');
        foreach ($records as $course) {
            $courses[$course->id] = $course->fullname . ' (' . $course->shortname . ')';
        }
        $mform->addElement('autocomplete', 'courseid', get_string('course', 'local_kbacorporate'), $courses);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $groups = [0 => get_string('nogroup', 'local_kbacorporate')];
        $grouprecords = $DB->get_records('groups', [], 'name ASC', 'id,name,courseid');
        foreach ($grouprecords as $group) {
            $course = $DB->get_record('course', ['id' => $group->courseid], 'id,shortname');
            if ($course) {
                $groups[$group->id] = $course->shortname . ' — ' . $group->name;
            }
        }
        $mform->addElement('autocomplete', 'groupid', get_string('group', 'local_kbacorporate'), $groups);
        $mform->setDefault('groupid', 0);
        $this->add_action_buttons(false, get_string('addcourse', 'local_kbacorporate'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (!empty($data['groupid'])) {
            $group = $DB->get_record('groups', ['id' => (int)$data['groupid']]);
            if (!$group || (int)$group->courseid !== (int)$data['courseid']) {
                $errors['groupid'] = get_string('invaliddata', 'error');
            }
        }
        return $errors;
    }
}
