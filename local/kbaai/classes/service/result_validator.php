<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();
class result_validator {
    public function validate(array $result, $mode, array $criteria) {
        foreach (array('confidence','requires_human_review','criteria','strengths','improvements','student_feedback','teacher_note') as $field) {
            if (!array_key_exists($field, $result)) { throw new \local_kbaai\provider\provider_exception('schema_missing_' . $field, 'AI JSON is missing field: ' . $field, true); }
        }
        $confidence=(int)$result['confidence']; if ($confidence<0 || $confidence>100) { throw new \local_kbaai\provider\provider_exception('schema_confidence','Confidence must be 0..100.',true); }
        if (!is_array($result['criteria'])) { throw new \local_kbaai\provider\provider_exception('schema_criteria','Criteria must be an array.',true); }
        $returned=array(); foreach ($result['criteria'] as $item) { if (!is_array($item)||!isset($item['id'])) { throw new \local_kbaai\provider\provider_exception('schema_criterion','Invalid criterion result.',true); } $returned[(string)$item['id']]=$item; }
        $normalised=array(); $total=0.0; $maxtotal=0.0;
        foreach ($criteria as $criterion) {
            $id=(string)$criterion['id']; if (!isset($returned[$id])) { throw new \local_kbaai\provider\provider_exception('schema_missing_criterion','Missing criterion: '.$id,true); }
            $item=$returned[$id]; $max=(float)$criterion['maxscore']; $score=null;
            if ($mode!=='tutor') { if (!isset($item['score'])||!is_numeric($item['score'])) { throw new \local_kbaai\provider\provider_exception('schema_score','Criterion score must be numeric: '.$id,true); } $score=(float)$item['score']; if ($score<0||$score>$max) { throw new \local_kbaai\provider\provider_exception('score_range','Criterion score is outside allowed range: '.$id,true); } $total+=$score; }
            $maxtotal+=$max; $normalised[]=array('id'=>$id,'score'=>$score,'max_score'=>$max,'rationale'=>isset($item['rationale'])?(string)$item['rationale']:'','evidence'=>isset($item['evidence'])&&is_array($item['evidence'])?array_values($item['evidence']):array());
        }
        $result['mode']=$mode; $result['criteria']=$normalised; $result['score']=$mode==='tutor'?null:$total; $result['max_score']=$maxtotal; $result['confidence']=$confidence;
        $result['requires_human_review']=!empty($result['requires_human_review']); $result['review_reasons']=isset($result['review_reasons'])&&is_array($result['review_reasons'])?array_values($result['review_reasons']):array();
        $result['strengths']=is_array($result['strengths'])?array_values($result['strengths']):array(); $result['improvements']=is_array($result['improvements'])?array_values($result['improvements']):array();
        $result['security_flags']=isset($result['security_flags'])&&is_array($result['security_flags'])?array_values($result['security_flags']):array(); return $result;
    }
}
