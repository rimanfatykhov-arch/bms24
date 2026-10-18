<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();
class publication_service {
    public function publish($assessmentid, $approverid = 0, $automatic = false, $finalscore = null, $feedback = null) {
        global $DB;
        $repo=new \local_kbaai\repository\assessment_repository(); $a=$repo->get($assessmentid);
        if ($a->status!=='completed') { throw new \moodle_exception('assessmentnotcomplete','local_kbaai'); }
        $adapter=new \local_kbaai\source\kbaelement_adapter(); $submission=$adapter->get_submission($a->submissionid); $current=$adapter->get_target($submission);
        if (!hash_equals((string)$a->targethash,(string)$current['hash'])) { throw new \moodle_exception('staleassessment','local_kbaai'); }
        if ($a->mode==='tutor') { throw new \moodle_exception('tutornograde','local_kbaai'); }
        $score=$finalscore===null?(float)$a->aiscore:(float)$finalscore; $score=max(0,min((float)$a->maxscore,$score));
        $fb=$feedback===null?(string)$a->studentfeedback:(string)$feedback; $a->finalscore=$score;
        if ($automatic) { $a->gradingsource = 'ai_examiner'; }
        else if ($a->aiscore !== null && abs((float)$score - (float)$a->aiscore) <= 0.00001) { $a->gradingsource = 'ai_confirmed'; }
        else { $a->gradingsource = 'teacher_adjusted'; }
        $adapter->publish_grade($a,$fb); $a->studentfeedback=$fb; $a->reviewstatus='approved'; $a->publishstatus='published'; $a->approvedby=(int)$approverid; $a->timeapproved=time(); $a->timepublished=time(); $a->timemodified=time(); $DB->update_record('local_kbaai_assessment',$a);
        $repo->audit($a->id,$automatic?'autopublished':'approved_and_published',$approverid,array('score'=>$score,'ai_score'=>$a->aiscore,'grading_source'=>$a->gradingsource));
        $this->notify_student($a, $approverid, $automatic);
        return true;
    }


    /**
     * Send the standard KBA Element grading notification after an AI result is published.
     * The same assessment is notified only once to avoid duplicate bell notifications.
     */
    private function notify_student($assessment, $approverid, $automatic) {
        global $CFG, $DB;

        if ($DB->record_exists('local_kbaai_audit', array(
                'assessmentid' => (int)$assessment->id,
                'actionname' => 'student_notified'))) {
            return;
        }

        $locallib = $CFG->dirroot . '/mod/kbaelement/locallib.php';
        if (!is_readable($locallib)) {
            debugging('KBA AI: KBA Element locallib.php is unavailable; learner grade notification skipped.', DEBUG_DEVELOPER);
            return;
        }
        require_once($locallib);
        if (!function_exists('kbaelement_send_grade_notification')) {
            debugging('KBA AI: update KBA Element to 1.3.10+ to enable learner grade notifications.', DEBUG_DEVELOPER);
            return;
        }

        try {
            $submission = $DB->get_record('kbaelement_submissions', array('id' => (int)$assessment->submissionid), '*', MUST_EXIST);
            $element = $DB->get_record('kbaelement', array('id' => (int)$assessment->kbaelementid), '*', MUST_EXIST);
            $course = $DB->get_record('course', array('id' => (int)$element->course), '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('kbaelement', (int)$element->id, (int)$course->id, false, MUST_EXIST);
            $source = $automatic ? 'ai_examiner' : 'ai_approved';
            $messageid = kbaelement_send_grade_notification($submission, $element, $course, $cm, (int)$approverid, $source);
            if ($messageid !== false) {
                (new \local_kbaai\repository\assessment_repository())->audit(
                    (int)$assessment->id,
                    'student_notified',
                    (int)$approverid,
                    array('messageid' => (int)$messageid, 'source' => $source)
                );
            }
        } catch (\Throwable $e) {
            // A notification failure must never undo a published grade.
            debugging('KBA AI: learner grade notification failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
