<?php
namespace local_kbaai\privacy;
defined('MOODLE_INTERNAL') || die();
class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection) {
        $collection->add_database_table('local_kbaai_assessment', array('userid'=>'privacy:metadata:assessment:userid','inputsnapshot'=>'privacy:metadata:assessment:inputsnapshot','studentfeedback'=>'privacy:metadata:assessment:studentfeedback','resultjson'=>'privacy:metadata:assessment:resultjson'), 'privacy:metadata:assessment');
        $collection->add_external_location_link('aiprovider', array('studentanswer'=>'privacy:metadata:aiprovider:studentanswer','learningcontext'=>'privacy:metadata:aiprovider:learningcontext','attachments'=>'privacy:metadata:aiprovider:attachments','conversation'=>'privacy:metadata:aiprovider:conversation'), 'privacy:metadata:aiprovider');
        return $collection;
    }
    public static function get_contexts_for_userid($userid) {
        $contextlist = new \core_privacy\local\request\contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_kbaai_assessment} a
                  JOIN {modules} m ON m.name = 'kbaelement'
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = a.kbaelementid
                  JOIN {context} ctx ON ctx.contextlevel = ? AND ctx.instanceid = cm.id
                 WHERE a.userid = ?";
        $contextlist->add_from_sql($sql, array(CONTEXT_MODULE, (int)$userid));
        return $contextlist;
    }
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
        global $DB; $userid=$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) { if ($context->contextlevel!==CONTEXT_MODULE) { continue; } $cm=get_coursemodule_from_id('kbaelement',$context->instanceid,0,false,IGNORE_MISSING); if (!$cm) { continue; }
            $records=$DB->get_records('local_kbaai_assessment',array('kbaelementid'=>$cm->instance,'userid'=>$userid)); foreach ($records as $r) { \core_privacy\local\request\writer::with_context($context)->export_data(array(get_string('pluginname','local_kbaai'),'assessment_'.$r->id),(object)$r); }
        }
    }
    public static function delete_data_for_all_users_in_context(\context $context) { global $DB; if ($context->contextlevel!==CONTEXT_MODULE) { return; } $cm=get_coursemodule_from_id('kbaelement',$context->instanceid,0,false,IGNORE_MISSING); if (!$cm) { return; } $ids=$DB->get_fieldset_select('local_kbaai_assessment','id','kbaelementid = ?',array($cm->instance)); if ($ids) { list($insql,$params)=$DB->get_in_or_equal($ids,SQL_PARAMS_QM); $DB->delete_records_select('local_kbaai_audit','assessmentid '.$insql,$params); } $DB->delete_records('local_kbaai_assessment',array('kbaelementid'=>$cm->instance)); }
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) { global $DB; $userid=$contextlist->get_user()->id; foreach ($contextlist->get_contexts() as $context) { if ($context->contextlevel!==CONTEXT_MODULE) { continue; } $cm=get_coursemodule_from_id('kbaelement',$context->instanceid,0,false,IGNORE_MISSING); if ($cm) { $ids=$DB->get_fieldset_select('local_kbaai_assessment','id','kbaelementid = ? AND userid = ?',array($cm->instance,$userid)); if ($ids) { list($insql,$params)=$DB->get_in_or_equal($ids,SQL_PARAMS_QM); $DB->delete_records_select('local_kbaai_audit','assessmentid '.$insql,$params); } $DB->delete_records('local_kbaai_assessment',array('kbaelementid'=>$cm->instance,'userid'=>$userid)); } } }
}
