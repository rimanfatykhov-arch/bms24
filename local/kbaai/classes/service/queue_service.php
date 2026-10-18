<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();
class queue_service {
    public function queue($submissionid, $trigger = 'manual', $force = false) {
        global $DB;
        $adapter = new \local_kbaai\source\kbaelement_adapter(); $submission = $adapter->get_submission($submissionid);
        $instanceid = $adapter->get_instanceid($submission); $userid = $adapter->get_userid($submission); $target = $adapter->get_target($submission);
        if ($target['normalised'] === '' && empty($target['files'])) { throw new \moodle_exception('emptyanswer', 'local_kbaai'); }
        $cfg = (new \local_kbaai\repository\instance_repository())->get($instanceid);
        if (empty($cfg->enabled) && $trigger !== 'manual') { return 0; }
        if (!$force) {
            $existing = $DB->get_record('local_kbaai_assessment', array('submissionid'=>(int)$submissionid,'targethash'=>$target['hash'],'runno'=>1));
            if ($existing) { return (int)$existing->id; }
            $runno = 1;
        } else {
            $runno = 1 + (int)$DB->get_field_sql('SELECT COALESCE(MAX(runno),0) FROM {local_kbaai_assessment} WHERE submissionid = ? AND targethash = ?', array((int)$submissionid,$target['hash']));
        }
        $learning = $adapter->get_learning_context($instanceid); $maxgrade = $adapter->get_max_grade($instanceid);
        $rubric = (new \local_kbaai\service\rubric_service())->get_for_assessment($instanceid, $maxgrade);
        $criteria = $rubric['criteria'];
        $snapshot = array(
            'schema_version'=>'dev4','learning_context'=>$learning,
            'conversation_context'=>array('policy'=>'excluded_from_scoring','content_included'=>false),
            'assessment_target'=>array('type'=>'kbaelement_final_submission','submissionid'=>(int)$submissionid,'text'=>$target['text'],'files'=>$target['files'],'hash'=>$target['hash']),
            'criteria'=>$criteria,'max_grade'=>$maxgrade,
            'rubric'=>array('versionno'=>(int)$rubric['versionno'],'source'=>(string)$rubric['source'],'total'=>(float)$rubric['total']),
        );
        $now=time(); $record=(object)array(
            'kbaelementid'=>$instanceid,'submissionid'=>(int)$submissionid,'userid'=>$userid,'targethash'=>$target['hash'],'runno'=>$runno,'triggername'=>$trigger,
            'mode'=>$cfg->mode,'provider'=>$cfg->provider,'model'=>$cfg->model,'status'=>'queued','reviewstatus'=>'none','publishstatus'=>'hidden','maxscore'=>$maxgrade,
            'confidence'=>0,'requireshumanreview'=>1,'retrycount'=>0,'approvedby'=>0,'timequeued'=>$now,'timestarted'=>0,'timecompleted'=>0,'timeapproved'=>0,'timepublished'=>0,
            'timecreated'=>$now,'timemodified'=>$now,'inputsnapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        );
        $id=$DB->insert_record('local_kbaai_assessment',$record); (new \local_kbaai\repository\assessment_repository())->audit($id,'queued',0,array('trigger'=>$trigger));
        $task=new \local_kbaai\task\assess_submission(); $task->set_custom_data(array('assessmentid'=>$id)); \core\task\manager::queue_adhoc_task($task); return $id;
    }
    private function parse_criteria($rubricjson, $maxgrade) {
        $rubricjson=trim((string)$rubricjson); if ($rubricjson==='') { return array(array('id'=>'overall','title'=>get_string('defaultcriteriontitle','local_kbaai'),'description'=>get_string('defaultcriteriondescription','local_kbaai'),'maxscore'=>(float)$maxgrade)); }
        $decoded=json_decode($rubricjson,true); if (!is_array($decoded)) { throw new \moodle_exception('invalidrubric','local_kbaai'); }
        $criteria=array(); $sum=0.0; foreach ($decoded as $i=>$c) {
            if (!is_array($c)) { continue; } $id=isset($c['id'])?(string)$c['id']:'C'.($i+1); $mx=isset($c['maxscore'])?(float)$c['maxscore']:(isset($c['max_score'])?(float)$c['max_score']:0);
            if ($mx<=0) { throw new \moodle_exception('invalidrubric','local_kbaai'); } $sum+=$mx; $criteria[]=array('id'=>$id,'title'=>isset($c['title'])?(string)$c['title']:$id,'description'=>isset($c['description'])?(string)$c['description']:'','maxscore'=>$mx);
        }
        if (!$criteria || abs($sum-(float)$maxgrade)>0.01) { throw new \moodle_exception('rubricmaxmismatch','local_kbaai','',null,'Rubric total '.$sum.' != grade max '.$maxgrade); }
        return $criteria;
    }
}
