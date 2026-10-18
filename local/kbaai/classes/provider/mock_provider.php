<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();
class mock_provider implements provider_interface {
    public function get_id() { return 'mock'; }
    public function get_capabilities() { return array('structured_output'=>true,'attachments'=>true,'supervisor_assistant'=>true,'development_only'=>true); }
    public function test_connection() { return true; }
    public function analyse_attachments(array $attachments, $language='ru') {
        $out=array();
        foreach ($attachments as $a) {
            $r=$a; unset($r['content']);
            if ($a['kind']==='image' || $a['kind']==='text' || $a['kind']==='pdf' || $a['kind']==='docx' || $a['kind']==='xlsx') {
                $r['analysis_status']='mock';
                $r['evidence']=get_string('mockfileevidence','local_kbaai',$a['filename']);
            } else {
                $r['analysis_status']='unsupported'; $r['evidence']='';
            }
            $out[]=$r;
        }
        return $out;
    }
    public function generate_supervisor_reply(\local_kbaai\dto\supervisor_request $request) {
        $first = !empty($request->conversationmeta['first_assistant_reply']);
        $text = $first ? get_string('mocksupervisorreply','local_kbaai') : get_string('mocksupervisorreplycontinued','local_kbaai');
        $result=array('reply'=>$text,'requires_supervisor'=>false,'reason'=>'');
        return new \local_kbaai\dto\provider_response($result,'mock-supervisor-'.uniqid(),json_encode($result));
    }
    public function generate(\local_kbaai\dto\assessment_request $request) {
        $criteria=array(); $total=0.0; $max=0.0;
        $hasanswer=trim(strip_tags((string)$request->studentanswer))!=='' || !empty($request->assessmenttarget['files']);
        foreach ($request->criteria as $criterion) {
            $cmax=(float)$criterion['maxscore']; $score=$request->mode==='tutor'?null:($hasanswer?round($cmax*0.5,2):0.0);
            if ($score!==null) { $total+=$score; } $max+=$cmax;
            $criteria[]=array('id'=>$criterion['id'],'score'=>$score,'max_score'=>$cmax,'rationale'=>get_string('mockrationale','local_kbaai'),'evidence'=>array());
        }
        $result=array('schema_version'=>'1.0','mode'=>$request->mode,'score'=>$request->mode==='tutor'?null:$total,'max_score'=>$max,
            'confidence'=>50,'requires_human_review'=>true,'review_reasons'=>array('mock_provider'),'criteria'=>$criteria,
            'strengths'=>$hasanswer?array(get_string('mockstrength','local_kbaai')):array(),'improvements'=>array(get_string('mockimprovement','local_kbaai')),
            'student_feedback'=>get_string('mockstudentfeedback','local_kbaai'),'teacher_note'=>get_string('mockteachernote','local_kbaai'),'security_flags'=>array('mock_provider'));
        return new \local_kbaai\dto\provider_response($result,'mock-'.uniqid(),json_encode($result));
    }

    public function generate_bms_assistance(\local_kbaai\dto\bms_assistant_request $request) {
        $result = [
            'suggested_value' => $request->operation === 'draft' ? $request->draftvalue : '',
            'rationale' => get_string('bms_mock_rationale', 'local_kbaai'),
            'questions' => [], 'issues' => [], 'evidence_suggestions' => [],
            'warnings' => [get_string('bms_mock_warning', 'local_kbaai')],
        ];
        if ($request->operation === 'interview' ||
                ($request->operation === 'draft' && trim($request->draftvalue) === '')) {
            $result['questions'][] = get_string('bms_mock_question', 'local_kbaai');
        }
        return new \local_kbaai\dto\provider_response($result, 'mock-bms-' . uniqid(), json_encode($result));
    }
}
