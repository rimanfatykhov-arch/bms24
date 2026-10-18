<?php
namespace local_kbaai\task;
defined('MOODLE_INTERNAL') || die();
class assess_submission extends \core\task\adhoc_task {
    public function get_name() { return get_string('taskassesssubmission','local_kbaai'); }
    public function execute() {
        $data=$this->get_custom_data(); $id=!empty($data->assessmentid)?(int)$data->assessmentid:0; if (!$id) { return; }
        global $DB; $a=$DB->get_record('local_kbaai_assessment',array('id'=>$id)); if (!$a) { return; }
        try { (new \local_kbaai\service\assessment_service())->process($id); }
        catch (\local_kbaai\provider\provider_exception $e) {
            $max=(int)get_config('local_kbaai','maxretries'); if ($max<=0) { $max=3; } $retry=(int)$a->retrycount+1;
            $a->retrycount=$retry; $a->lasterror=$e->getMessage(); $a->status='queued'; $a->timemodified=time(); $DB->update_record('local_kbaai_assessment',$a);
            if ($e->is_retryable() && $retry<$max) { throw $e; }
            (new \local_kbaai\service\assessment_service())->fail($id,$e->getMessage(),$retry,$e->get_provider_details());
        }
        catch (\Throwable $e) { (new \local_kbaai\service\assessment_service())->fail($id,$e->getMessage(),(int)$a->retrycount,array()); }
    }
}
