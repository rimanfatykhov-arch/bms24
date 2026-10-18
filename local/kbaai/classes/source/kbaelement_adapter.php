<?php
namespace local_kbaai\source;
defined('MOODLE_INTERNAL') || die();

class kbaelement_adapter implements source_interface {
    const INSTANCETABLE = 'kbaelement';
    const SUBMISSIONTABLE = 'kbaelement_submissions';

    private function columns($table) {
        global $DB;
        $manager = $DB->get_manager();
        if (!$manager->table_exists($table)) { return array(); }
        return $DB->get_columns($table);
    }

    private function select_field($configkey, array $columns, array $candidates, $required = false) {
        $override = trim((string)get_config('local_kbaai', $configkey));
        if ($override !== '' && isset($columns[$override])) { return $override; }
        foreach ($candidates as $candidate) { if (isset($columns[$candidate])) { return $candidate; } }
        return '';
    }

    public function get_mapping() {
        $ic = $this->columns(self::INSTANCETABLE); $sc = $this->columns(self::SUBMISSIONTABLE);
        return array(
            'sub_instance' => $this->select_field('subinstancefield', $sc, array('kbaelementid', 'kbaelement', 'instanceid')),
            'sub_user' => $this->select_field('subuserfield', $sc, array('userid', 'user_id')),
            'sub_answer' => $this->select_field('subanswerfield', $sc, array('onlinetext', 'answertext', 'answer', 'submissiontext', 'response', 'text')),
            'sub_modified' => $this->select_field('submodifiedfield', $sc, array('timemodified', 'modified', 'timeupdated', 'timesubmitted', 'timecreated')),
            'sub_revision' => $this->select_field('subrevisionfield', $sc, array('revisioncount', 'changecount', 'revision', 'version')),
            'sub_grade' => $this->select_field('subgradefield', $sc, array('grade', 'score')),
            'sub_feedback' => $this->select_field('subfeedbackfield', $sc, array('feedback', 'comment', 'teachercomment', 'gradecomment')),
            'sub_status' => $this->select_field('substatusfield', $sc, array('status')),
            'instance_content' => $this->select_field('instancecontentfield', $ic, array('content', 'material', 'learningmaterial', 'lessontext', 'intro')),
            'instance_task' => $this->select_field('instancetaskfield', $ic, array('assignmenttext', 'assignment', 'tasktext', 'task', 'question')),
            'instance_objectives' => $this->select_field('instanceobjectivesfield', $ic, array('objectives', 'learningobjectives', 'goals')),
            'instance_course' => $this->select_field('instancecoursefield', $ic, array('course')),
        );
    }

    public function diagnose() {
        global $DB;
        $manager = $DB->get_manager(); $mapping = $this->get_mapping();
        $errors = array(); $warnings = array();
        if (!$manager->table_exists(self::INSTANCETABLE)) { $errors[] = 'Table {' . self::INSTANCETABLE . '} not found.'; }
        if (!$manager->table_exists(self::SUBMISSIONTABLE)) { $errors[] = 'Table {' . self::SUBMISSIONTABLE . '} not found.'; }
        foreach (array('sub_instance', 'sub_user', 'sub_answer') as $key) {
            if (empty($mapping[$key])) { $errors[] = 'Required mapping is unresolved: ' . $key; }
        }
        foreach (array('instance_content', 'instance_task', 'instance_course') as $key) {
            if (empty($mapping[$key])) { $warnings[] = 'Optional/recommended mapping is unresolved: ' . $key; }
        }
        if (empty($mapping['sub_modified'])) { $warnings[] = 'Submission modified-time field not detected; ID order will be used.'; }
        return array(
            'ready' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'mapping' => $mapping,
            'instancecolumns' => array_keys($this->columns(self::INSTANCETABLE)),
            'submissioncolumns' => array_keys($this->columns(self::SUBMISSIONTABLE)),
        );
    }

    private function require_ready() {
        $d = $this->diagnose();
        if (!$d['ready']) { throw new \moodle_exception('mappingnotready', 'local_kbaai', '', implode(' ', $d['errors'])); }
        return $d['mapping'];
    }

    public function get_submission($submissionid) {
        global $DB;
        $this->require_ready();
        return $DB->get_record(self::SUBMISSIONTABLE, array('id' => (int)$submissionid), '*', MUST_EXIST);
    }

    public function list_submissions($instanceid, $limit = 100) {
        global $DB;
        $m = $this->require_ready();
        $sort = !empty($m['sub_modified']) ? $m['sub_modified'] . ' DESC, id DESC' : 'id DESC';
        return $DB->get_records(self::SUBMISSIONTABLE, array($m['sub_instance'] => (int)$instanceid), $sort, '*', 0, $limit);
    }

    public function get_target($submission) {
        $m = $this->require_ready();
        $text = isset($submission->{$m['sub_answer']}) ? (string)$submission->{$m['sub_answer']} : '';
        $normalised = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $normalised = preg_replace('/\r\n?/', "\n", $normalised);
        $normalised = preg_replace('/[ \t]+/', ' ', $normalised);
        $normalised = trim($normalised);
        $instanceid = (int)$submission->{$m['sub_instance']};
        $files = (new \local_kbaai\service\submission_file_service())->get_manifest($instanceid, (int)$submission->id);
        $fingerprint = array('text' => $normalised, 'files' => array());
        foreach ($files as $file) {
            $fingerprint['files'][] = array($file['filepath'], $file['filename'], $file['mimetype'], $file['size'], $file['contenthash']);
        }
        return array('text'=>$text, 'normalised'=>$normalised, 'files'=>$files,
            'hash'=>hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    }

    public function get_userid($submission) {
        $m = $this->require_ready();
        return (int)$submission->{$m['sub_user']};
    }

    public function get_instanceid($submission) {
        $m = $this->require_ready();
        return (int)$submission->{$m['sub_instance']};
    }

    public function get_learning_context($instanceid) {
        global $DB;
        $m = $this->require_ready();
        $instance = $DB->get_record(self::INSTANCETABLE, array('id' => (int)$instanceid), '*', MUST_EXIST);
        $material = !empty($m['instance_content']) ? (string)$instance->{$m['instance_content']} : '';
        // Some KBA Element instances keep a short lesson explanation in intro
        // while content is empty. Use intro only as a safe fallback/supplement.
        if (trim(strip_tags($material)) === '' && isset($instance->intro)) {
            $material = (string)$instance->intro;
        }
        return array(
            'source' => 'mod_kbaelement',
            'instanceid' => (int)$instanceid,
            'title' => isset($instance->name) ? (string)$instance->name : '',
            'learning_material' => $material,
            'learning_objectives' => !empty($m['instance_objectives']) ? (string)$instance->{$m['instance_objectives']} : '',
            'assignment' => !empty($m['instance_task']) ? (string)$instance->{$m['instance_task']} : '',
        );
    }

    public function get_courseid($instanceid) {
        global $DB;
        $m = $this->require_ready();
        if (empty($m['instance_course'])) { throw new \moodle_exception('coursefieldmissing', 'local_kbaai'); }
        return (int)$DB->get_field(self::INSTANCETABLE, $m['instance_course'], array('id' => (int)$instanceid), MUST_EXIST);
    }

    public function get_max_grade($instanceid) {
        global $DB;
        $courseid = $this->get_courseid($instanceid);
        $item = $DB->get_record('grade_items', array('courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => 'kbaelement', 'iteminstance' => (int)$instanceid, 'itemnumber' => 0));
        if ($item && isset($item->grademax) && (float)$item->grademax > 0) { return (float)$item->grademax; }
        throw new \moodle_exception('gradeitemmissing', 'local_kbaai');
    }

    public function publish_grade($assessment, $feedback) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $instanceid = (int)$assessment->kbaelementid; $courseid = $this->get_courseid($instanceid);
        $grade = array('userid' => (int)$assessment->userid, 'rawgrade' => (float)$assessment->finalscore,
            'feedback' => (string)$feedback, 'feedbackformat' => FORMAT_HTML);
        $result = grade_update('local_kbaai', $courseid, 'mod', 'kbaelement', $instanceid, 0, $grade, null);
        if ($result === GRADE_UPDATE_FAILED) { throw new \moodle_exception('gradepublishfailed', 'local_kbaai'); }

        $m = $this->get_mapping(); $updates = new \stdClass(); $updates->id = (int)$assessment->submissionid; $changed = false;
        if (!empty($m['sub_grade'])) { $updates->{$m['sub_grade']} = (float)$assessment->finalscore; $changed = true; }
        if (!empty($m['sub_feedback'])) { $updates->{$m['sub_feedback']} = (string)$feedback; $changed = true; }
        if ($changed) { $DB->update_record(self::SUBMISSIONTABLE, $updates); }
        return true;
    }
}
