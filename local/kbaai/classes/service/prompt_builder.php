<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();
class prompt_builder {
    public function build(\local_kbaai\dto\assessment_request $request) {
        global $CFG;
        $system = file_get_contents($CFG->dirroot . '/local/kbaai/prompts/system_v1.txt');
        $payload = array(
            'mode'=>$request->mode,
            'output_language'=>$request->language,
            'trusted_context'=>array(
                'learning_context'=>$request->learningcontext,
                'assignment'=>$request->assignmenttext,
                'teacher_instructions'=>$request->teacherinstructions,
                'reference_answer'=>$request->referenceanswer,
                'rubric'=>$request->criteria,
            ),
            'conversation_context'=>array('policy'=>'excluded_from_scoring','content_not_sent'=>true),
            'attachment_policy'=>array(
                'use_as_evidence_only_when_analysis_status'=>array('analysed','mock'),
                'never_infer_unanalysed_file_contents_from_filename'=>true,
                'unanalysed_official_attachment_requires_human_review'=>true,
            ),
            'assessment_target'=>$request->assessmenttarget,
            'output'=>'Return JSON only. Write student_feedback, teacher_note, strengths, improvements and criterion rationales in the requested output_language.'
        );
        return array('system'=>$system, 'user'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    }
}
