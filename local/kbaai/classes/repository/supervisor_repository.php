<?php
namespace local_kbaai\repository;
defined('MOODLE_INTERNAL') || die();
class supervisor_repository {
    public function get($courseid) {
        global $DB;
        $r = $DB->get_record('local_kbaai_supervisor', array('courseid'=>(int)$courseid));
        if ($r) { return $r; }
        $now=time();
        $r=(object)array('courseid'=>(int)$courseid,'enabled'=>1,'away'=>0,'supervisoruserid'=>0,
            'instructions'=>'','timecreated'=>$now,'timemodified'=>$now);
        $r->id=$DB->insert_record('local_kbaai_supervisor',$r);
        return $r;
    }
    public function save($courseid, $away, $userid, $instructions='') {
        global $DB;
        $r=$this->get($courseid); $r->enabled=1; $r->away=$away?1:0;
        if ($away) { $r->supervisoruserid=(int)$userid; }
        $r->instructions=(string)$instructions; $r->timemodified=time();
        $DB->update_record('local_kbaai_supervisor',$r); return $r;
    }
    public function pending_escalations($courseid) {
        global $DB;
        return $DB->get_records('local_kbaai_supreply', array('courseid'=>(int)$courseid,'requiressupervisor'=>1), 'timecreated DESC');
    }
}
