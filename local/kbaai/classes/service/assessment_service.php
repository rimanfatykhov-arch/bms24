<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();
class assessment_service {
    public function process($assessmentid) {
        global $DB;
        $repo=new \local_kbaai\repository\assessment_repository(); $a=$repo->get($assessmentid);
        if ($a->status==='completed' || $a->status==='failed') { return; }
        $a->status='processing'; $a->timestarted=time(); $a->timemodified=time(); $DB->update_record('local_kbaai_assessment',$a);
        $snapshot=json_decode($a->inputsnapshot,true); if (!is_array($snapshot)) { throw new \local_kbaai\provider\provider_exception('snapshot_invalid','Assessment snapshot is invalid.',false); }
        $cfg=(new \local_kbaai\repository\instance_repository())->get($a->kbaelementid);
        $provider=\local_kbaai\provider\provider_factory::create($a->provider);
        $attachmentreview=false;
        if (!empty($snapshot['assessment_target']['files'])) {
            $payloads=(new submission_file_service())->get_payloads($a->kbaelementid,$a->submissionid,$snapshot['assessment_target']['files']);
            $analysed=$provider->analyse_attachments($payloads,current_language());
            $snapshot['assessment_target']['files']=$analysed;
            foreach ($analysed as $file) {
                if (empty($file['analysis_status']) || !in_array($file['analysis_status'],array('analysed','mock'),true)) { $attachmentreview=true; }
            }
            $a->inputsnapshot=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $DB->update_record('local_kbaai_assessment',$a);
        }
        $request=new \local_kbaai\dto\assessment_request(array(
            'mode'=>$a->mode,'language'=>current_language(),'learningcontext'=>$snapshot['learning_context'],
            'assignmenttext'=>isset($snapshot['learning_context']['assignment'])?$snapshot['learning_context']['assignment']:'',
            'teacherinstructions'=>$cfg->teacherinstructions,'referenceanswer'=>$cfg->referenceanswer,'criteria'=>$snapshot['criteria'],
            'assessmenttarget'=>$snapshot['assessment_target'],'model'=>$a->model,
        ));
        $response=$provider->generate($request);
        $result=(new result_validator())->validate($response->result,$a->mode,$snapshot['criteria']);
        if ($attachmentreview) { $result['requires_human_review']=true; $result['review_reasons']=array_values(array_unique(array_merge($result['review_reasons'],array('attachment_not_analysed')))); }
        $scanner = new \local_kbaai\service\assessment_security();
        $security = $scanner->scan($snapshot['assessment_target']['text']);
        // Extracted attachment evidence is also student-authored and untrusted.
        // Scan OCR/text/image evidence for obvious prompt-injection markers, but
        // never execute instructions contained inside it.
        if (!empty($snapshot['assessment_target']['files']) && is_array($snapshot['assessment_target']['files'])) {
            foreach ($snapshot['assessment_target']['files'] as $securityfile) {
                if (!empty($securityfile['evidence'])) {
                    $security = array_merge($security, $scanner->scan((string)$securityfile['evidence']));
                }
            }
        }
        $security = array_values(array_unique($security));
        if ($security) { $result['security_flags']=array_values(array_unique(array_merge($result['security_flags'],$security))); $result['requires_human_review']=true; $result['review_reasons']=array_values(array_unique(array_merge($result['review_reasons'],array('security_flag')))); }
        $a->aiscore=$a->mode==='tutor'?null:(float)$result['score']; $a->maxscore=(float)$result['max_score']; $a->confidence=(int)$result['confidence'];
        $a->requireshumanreview=!empty($result['requires_human_review'])?1:0; $a->studentfeedback=(string)$result['student_feedback']; $a->teachernote=(string)$result['teacher_note'];
        $a->resultjson=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $a->providerrequestid=(string)$response->requestid; $a->status='completed'; $a->timecompleted=time(); $a->timemodified=time();
        $canpublish=($a->mode==='examiner' && $a->provider!=='mock' && !empty($cfg->autopublish) && !$a->requireshumanreview && $a->confidence >= (int)$cfg->confidencethreshold && empty($result['security_flags']) && !$attachmentreview);
        $a->reviewstatus=$canpublish?'approved':(($a->mode==='tutor')?'none':'needsreview'); $DB->update_record('local_kbaai_assessment',$a);
        $providermeta = !empty($response->metadata) && is_array($response->metadata) ? $response->metadata : array();
        $repo->audit($a->id,'completed',0,array(
            'confidence'=>$a->confidence,
            'provider_request_id'=>(string)$response->requestid,
            'provider_model_version'=>!empty($providermeta['model_version']) ? $providermeta['model_version'] : '',
            'provider_usage'=>!empty($providermeta['usage']) ? $providermeta['usage'] : array(),
            'provider_http_status'=>!empty($providermeta['httpstatus']) ? $providermeta['httpstatus'] : 0,
            'provider_duration'=>isset($providermeta['duration']) ? $providermeta['duration'] : null,
        ));
        if ($canpublish) { $a->finalscore=$a->aiscore; $DB->update_record('local_kbaai_assessment',$a); (new publication_service())->publish($a->id,0,true); }
    }
    public function fail($assessmentid, $message, $retrycount, array $details = array()) {
        global $DB;
        $a=$DB->get_record('local_kbaai_assessment',array('id'=>(int)$assessmentid), '*', MUST_EXIST);
        $a->status='failed';
        $a->lasterror=(string)$message;
        $a->retrycount=(int)$retrycount;
        $a->timemodified=time();
        $DB->update_record('local_kbaai_assessment',$a);
        $auditdetails=array('message'=>$message,'retrycount'=>(int)$retrycount);
        foreach (array('httpstatus','requestid','purpose','providercode','providermessage') as $key) {
            if (isset($details[$key]) && $details[$key] !== '') { $auditdetails[$key]=$details[$key]; }
        }
        (new \local_kbaai\repository\assessment_repository())->audit($a->id,'failed',0,$auditdetails);
    }
}
