<?php
namespace local_kbacorporate\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_kbacorp_member', [
            'companyid' => 'privacy:metadata:local_kbacorp_member:companyid',
            'userid' => 'privacy:metadata:local_kbacorp_member:userid',
            'membertype' => 'privacy:metadata:local_kbacorp_member:membertype',
            'isemployee' => 'privacy:metadata:local_kbacorp_member:isemployee',
            'ismanager' => 'privacy:metadata:local_kbacorp_member:ismanager',
            'isdepartmentmanager' => 'privacy:metadata:local_kbacorp_member:isdepartmentmanager',
            'ishr' => 'privacy:metadata:local_kbacorp_member:ishr',
            'timecreated' => 'privacy:metadata:local_kbacorp_member:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_member:timemodified',
        ], 'privacy:metadata:local_kbacorp_member');
        $collection->add_database_table('local_kbacorp_dept_member', [
            'departmentid' => 'privacy:metadata:local_kbacorp_dept_member:departmentid',
            'userid' => 'privacy:metadata:local_kbacorp_dept_member:userid',
            'deptrole' => 'privacy:metadata:local_kbacorp_dept_member:deptrole',
            'timecreated' => 'privacy:metadata:local_kbacorp_dept_member:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_dept_member:timemodified',
        ], 'privacy:metadata:local_kbacorp_dept_member');
        $collection->add_database_table('local_kbacorp_license_user', [
            'licenseid' => 'privacy:metadata:local_kbacorp_license_user:licenseid',
            'userid' => 'privacy:metadata:local_kbacorp_license_user:userid',
            'status' => 'privacy:metadata:local_kbacorp_license_user:status',
            'assignedby' => 'privacy:metadata:local_kbacorp_license_user:assignedby',
            'timeassigned' => 'privacy:metadata:local_kbacorp_license_user:timeassigned',
            'timestarted' => 'privacy:metadata:local_kbacorp_license_user:timestarted',
            'timecompleted' => 'privacy:metadata:local_kbacorp_license_user:timecompleted',
            'timereleased' => 'privacy:metadata:local_kbacorp_license_user:timereleased',
        ], 'privacy:metadata:local_kbacorp_license_user');
        $collection->add_database_table('local_kbacorp_member_busrole', [
            'companyid' => 'privacy:metadata:local_kbacorp_member_busrole:companyid',
            'userid' => 'privacy:metadata:local_kbacorp_member_busrole:userid',
            'roleid' => 'privacy:metadata:local_kbacorp_member_busrole:roleid',
            'source' => 'privacy:metadata:local_kbacorp_member_busrole:source',
            'externalref' => 'privacy:metadata:local_kbacorp_member_busrole:externalref',
            'active' => 'privacy:metadata:local_kbacorp_member_busrole:active',
            'effectivefrom' => 'privacy:metadata:local_kbacorp_member_busrole:effectivefrom',
            'effectiveto' => 'privacy:metadata:local_kbacorp_member_busrole:effectiveto',
            'timecreated' => 'privacy:metadata:local_kbacorp_member_busrole:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_member_busrole:timemodified',
        ], 'privacy:metadata:local_kbacorp_member_busrole');
        $collection->add_database_table('local_kbacorp_extmap', [
            'companyid' => 'privacy:metadata:local_kbacorp_extmap_user:companyid',
            'system' => 'privacy:metadata:local_kbacorp_extmap_user:system',
            'entitytype' => 'privacy:metadata:local_kbacorp_extmap_user:entitytype',
            'localentity' => 'privacy:metadata:local_kbacorp_extmap_user:localentity',
            'localid' => 'privacy:metadata:local_kbacorp_extmap_user:localid',
            'externalid' => 'privacy:metadata:local_kbacorp_extmap_user:externalid',
            'externalcode' => 'privacy:metadata:local_kbacorp_extmap_user:externalcode',
            'status' => 'privacy:metadata:local_kbacorp_extmap_user:status',
            'metadata' => 'privacy:metadata:local_kbacorp_extmap_user:metadata',
            'lastsync' => 'privacy:metadata:local_kbacorp_extmap_user:lastsync',
            'timecreated' => 'privacy:metadata:local_kbacorp_extmap_user:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_extmap_user:timemodified',
        ], 'privacy:metadata:local_kbacorp_extmap_user');
        $collection->add_database_table('local_kbacorp_erp_log', [
            'companyid' => 'privacy:metadata:local_kbacorp_erp_log:companyid',
            'system' => 'privacy:metadata:local_kbacorp_erp_log:system',
            'entitytype' => 'privacy:metadata:local_kbacorp_erp_log:entitytype',
            'externalidhash' => 'privacy:metadata:local_kbacorp_erp_log:externalidhash',
            'idempotencyhash' => 'privacy:metadata:local_kbacorp_erp_log:idempotencyhash',
            'status' => 'privacy:metadata:local_kbacorp_erp_log:status',
            'durationms' => 'privacy:metadata:local_kbacorp_erp_log:durationms',
            'errorcode' => 'privacy:metadata:local_kbacorp_erp_log:errorcode',
            'timecreated' => 'privacy:metadata:local_kbacorp_erp_log:timecreated',
        ], 'privacy:metadata:local_kbacorp_erp_log');
        $collection->add_database_table('local_kbacorp_audit', [
            'companyid' => 'privacy:metadata:local_kbacorp_audit:companyid',
            'userid' => 'privacy:metadata:local_kbacorp_audit:userid',
            'title' => 'privacy:metadata:local_kbacorp_audit:title',
            'description' => 'privacy:metadata:local_kbacorp_audit:description',
            'audittype' => 'privacy:metadata:local_kbacorp_audit:audittype',
            'status' => 'privacy:metadata:local_kbacorp_audit:status',
            'createdby' => 'privacy:metadata:local_kbacorp_audit:createdby',
            'completedat' => 'privacy:metadata:local_kbacorp_audit:completedat',
            'timecreated' => 'privacy:metadata:local_kbacorp_audit:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_audit:timemodified',
        ], 'privacy:metadata:local_kbacorp_audit');
        $collection->add_database_table('local_kbacorp_audit_item', [
            'auditid' => 'privacy:metadata:local_kbacorp_audit_item:auditid',
            'competencyid' => 'privacy:metadata:local_kbacorp_audit_item:competencyid',
            'method' => 'privacy:metadata:local_kbacorp_audit_item:method',
            'proposedlevel' => 'privacy:metadata:local_kbacorp_audit_item:proposedlevel',
            'targetlevel' => 'privacy:metadata:local_kbacorp_audit_item:targetlevel',
            'priority' => 'privacy:metadata:local_kbacorp_audit_item:priority',
            'required' => 'privacy:metadata:local_kbacorp_audit_item:required',
            'source' => 'privacy:metadata:local_kbacorp_audit_item:source',
            'rolesnapshot' => 'privacy:metadata:local_kbacorp_audit_item:rolesnapshot',
            'status' => 'privacy:metadata:local_kbacorp_audit_item:status',
            'assessorid' => 'privacy:metadata:local_kbacorp_audit_item:assessorid',
            'reviewerid' => 'privacy:metadata:local_kbacorp_audit_item:reviewerid',
            'comment' => 'privacy:metadata:local_kbacorp_audit_item:comment',
            'evidenceurl' => 'privacy:metadata:local_kbacorp_audit_item:evidenceurl',
            'timeassessed' => 'privacy:metadata:local_kbacorp_audit_item:timeassessed',
            'timeconfirmed' => 'privacy:metadata:local_kbacorp_audit_item:timeconfirmed',
            'timecreated' => 'privacy:metadata:local_kbacorp_audit_item:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_audit_item:timemodified',
        ], 'privacy:metadata:local_kbacorp_audit_item');
        $collection->add_database_table('local_kbacorp_audit_ev', [
            'audititemid' => 'privacy:metadata:local_kbacorp_audit_ev:audititemid',
            'sourcetype' => 'privacy:metadata:local_kbacorp_audit_ev:sourcetype',
            'sourcecomponent' => 'privacy:metadata:local_kbacorp_audit_ev:sourcecomponent',
            'sourceitemid' => 'privacy:metadata:local_kbacorp_audit_ev:sourceitemid',
            'title' => 'privacy:metadata:local_kbacorp_audit_ev:title',
            'resultvalue' => 'privacy:metadata:local_kbacorp_audit_ev:resultvalue',
            'proposedlevel' => 'privacy:metadata:local_kbacorp_audit_ev:proposedlevel',
            'url' => 'privacy:metadata:local_kbacorp_audit_ev:url',
            'details' => 'privacy:metadata:local_kbacorp_audit_ev:details',
            'timeobserved' => 'privacy:metadata:local_kbacorp_audit_ev:timeobserved',
            'timecreated' => 'privacy:metadata:local_kbacorp_audit_ev:timecreated',
        ], 'privacy:metadata:local_kbacorp_audit_ev');
        $collection->add_database_table('local_kbacorp_rolever', [
            'templateid' => 'privacy:metadata:local_kbacorp_rolever:templateid',
            'version' => 'privacy:metadata:local_kbacorp_rolever:version',
            'name' => 'privacy:metadata:local_kbacorp_rolever:name',
            'changenote' => 'privacy:metadata:local_kbacorp_rolever:changenote',
            'createdby' => 'privacy:metadata:local_kbacorp_rolever:createdby',
            'timecreated' => 'privacy:metadata:local_kbacorp_rolever:timecreated',
        ], 'privacy:metadata:local_kbacorp_rolever');
        $collection->add_database_table('local_kbacorp_devplan', [
            'companyid' => 'privacy:metadata:local_kbacorp_devplan:companyid',
            'userid' => 'privacy:metadata:local_kbacorp_devplan:userid',
            'title' => 'privacy:metadata:local_kbacorp_devplan:title',
            'objective' => 'privacy:metadata:local_kbacorp_devplan:objective',
            'mentorid' => 'privacy:metadata:local_kbacorp_devplan:mentorid',
            'status' => 'privacy:metadata:local_kbacorp_devplan:status',
            'createdby' => 'privacy:metadata:local_kbacorp_devplan:createdby',
            'submittedby' => 'privacy:metadata:local_kbacorp_devplan:submittedby',
            'timesubmitted' => 'privacy:metadata:local_kbacorp_devplan:timesubmitted',
            'timeacknowledged' => 'privacy:metadata:local_kbacorp_devplan:timeacknowledged',
            'employeecomment' => 'privacy:metadata:local_kbacorp_devplan:employeecomment',
            'lastremindersent' => 'privacy:metadata:local_kbacorp_devplan:lastremindersent',
            'followupauditid' => 'privacy:metadata:local_kbacorp_devplan:followupauditid',
            'moodleplanid' => 'privacy:metadata:local_kbacorp_devplan:moodleplanid',
            'startdate' => 'privacy:metadata:local_kbacorp_devplan:startdate',
            'targetdate' => 'privacy:metadata:local_kbacorp_devplan:targetdate',
            'reviewdate' => 'privacy:metadata:local_kbacorp_devplan:reviewdate',
        ], 'privacy:metadata:local_kbacorp_devplan');
        $collection->add_database_table('local_kbacorp_devitem', [
            'planid' => 'privacy:metadata:local_kbacorp_devitem:planid',
            'competencyid' => 'privacy:metadata:local_kbacorp_devitem:competencyid',
            'courseid' => 'privacy:metadata:local_kbacorp_devitem:courseid',
            'currentlevel' => 'privacy:metadata:local_kbacorp_devitem:currentlevel',
            'targetlevel' => 'privacy:metadata:local_kbacorp_devitem:targetlevel',
            'priority' => 'privacy:metadata:local_kbacorp_devitem:priority',
            'required' => 'privacy:metadata:local_kbacorp_devitem:required',
            'goal' => 'privacy:metadata:local_kbacorp_devitem:goal',
            'practicaltask' => 'privacy:metadata:local_kbacorp_devitem:practicaltask',
            'evidencecriteria' => 'privacy:metadata:local_kbacorp_devitem:evidencecriteria',
            'mentorid' => 'privacy:metadata:local_kbacorp_devitem:mentorid',
            'checkpointdate' => 'privacy:metadata:local_kbacorp_devitem:checkpointdate',
            'checkpointcomment' => 'privacy:metadata:local_kbacorp_devitem:checkpointcomment',
            'evidenceurl' => 'privacy:metadata:local_kbacorp_devitem:evidenceurl',
            'evidencecomment' => 'privacy:metadata:local_kbacorp_devitem:evidencecomment',
            'evidencesubmittedby' => 'privacy:metadata:local_kbacorp_devitem:evidencesubmittedby',
            'timeevidencesubmitted' => 'privacy:metadata:local_kbacorp_devitem:timeevidencesubmitted',
            'reviewedby' => 'privacy:metadata:local_kbacorp_devitem:reviewedby',
            'timereviewed' => 'privacy:metadata:local_kbacorp_devitem:timereviewed',
            'reviewcomment' => 'privacy:metadata:local_kbacorp_devitem:reviewcomment',
            'status' => 'privacy:metadata:local_kbacorp_devitem:status',
            'duedate' => 'privacy:metadata:local_kbacorp_devitem:duedate',
        ], 'privacy:metadata:local_kbacorp_devitem');
        $collection->add_database_table('local_kbacorp_devlog', [
            'planid' => 'privacy:metadata:local_kbacorp_devlog:planid',
            'itemid' => 'privacy:metadata:local_kbacorp_devlog:itemid',
            'actorid' => 'privacy:metadata:local_kbacorp_devlog:actorid',
            'eventtype' => 'privacy:metadata:local_kbacorp_devlog:eventtype',
            'fromstatus' => 'privacy:metadata:local_kbacorp_devlog:fromstatus',
            'tostatus' => 'privacy:metadata:local_kbacorp_devlog:tostatus',
            'comment' => 'privacy:metadata:local_kbacorp_devlog:comment',
            'detailsjson' => 'privacy:metadata:local_kbacorp_devlog:detailsjson',
            'timecreated' => 'privacy:metadata:local_kbacorp_devlog:timecreated',
        ], 'privacy:metadata:local_kbacorp_devlog');
        $collection->add_database_table('local_kbacorp_impl_audit', [
            'companyid' => 'privacy:metadata:local_kbacorp_impl_audit:companyid',
            'toolid' => 'privacy:metadata:local_kbacorp_impl_audit:toolid',
            'toolversionid' => 'privacy:metadata:local_kbacorp_impl_audit:toolversionid',
            'toolversionlabel' => 'privacy:metadata:local_kbacorp_impl_audit:toolversionlabel',
            'title' => 'privacy:metadata:local_kbacorp_impl_audit:title',
            'audittype' => 'privacy:metadata:local_kbacorp_impl_audit:audittype',
            'status' => 'privacy:metadata:local_kbacorp_impl_audit:status',
            'auditorid' => 'privacy:metadata:local_kbacorp_impl_audit:auditorid',
            'confirmedby' => 'privacy:metadata:local_kbacorp_impl_audit:confirmedby',
            'scalemax' => 'privacy:metadata:local_kbacorp_impl_audit:scalemax',
            'attentionthreshold' => 'privacy:metadata:local_kbacorp_impl_audit:attentionthreshold',
            'indexmethod' => 'privacy:metadata:local_kbacorp_impl_audit:indexmethod',
            'indexvalue' => 'privacy:metadata:local_kbacorp_impl_audit:indexvalue',
            'coverage' => 'privacy:metadata:local_kbacorp_impl_audit:coverage',
            'auditdate' => 'privacy:metadata:local_kbacorp_impl_audit:auditdate',
        ], 'privacy:metadata:local_kbacorp_impl_audit');
        $collection->add_database_table('local_kbacorp_bms_toolver', [
            'toolid' => 'privacy:metadata:local_kbacorp_bms_toolver:toolid',
            'versionlabel' => 'privacy:metadata:local_kbacorp_bms_toolver:versionlabel',
            'changenote' => 'privacy:metadata:local_kbacorp_bms_toolver:changenote',
            'publishedby' => 'privacy:metadata:local_kbacorp_bms_toolver:publishedby',
            'publishedat' => 'privacy:metadata:local_kbacorp_bms_toolver:publishedat',
        ], 'privacy:metadata:local_kbacorp_bms_toolver');
        $collection->add_database_table('local_kbacorp_impl_item', [
            'auditid' => 'privacy:metadata:local_kbacorp_impl_item:auditid',
            'criterionname' => 'privacy:metadata:local_kbacorp_impl_item:criterionname',
            'sectioncode' => 'privacy:metadata:local_kbacorp_impl_item:sectioncode',
            'sectionname' => 'privacy:metadata:local_kbacorp_impl_item:sectionname',
            'minlabel' => 'privacy:metadata:local_kbacorp_impl_item:minlabel',
            'maxlabel' => 'privacy:metadata:local_kbacorp_impl_item:maxlabel',
            'targetscore' => 'privacy:metadata:local_kbacorp_impl_item:targetscore',
            'score' => 'privacy:metadata:local_kbacorp_impl_item:score',
            'evidencestatus' => 'privacy:metadata:local_kbacorp_impl_item:evidencestatus',
            'evidenceurl' => 'privacy:metadata:local_kbacorp_impl_item:evidenceurl',
            'comment' => 'privacy:metadata:local_kbacorp_impl_item:comment',
            'assessorid' => 'privacy:metadata:local_kbacorp_impl_item:assessorid',
            'verifierid' => 'privacy:metadata:local_kbacorp_impl_item:verifierid',
        ], 'privacy:metadata:local_kbacorp_impl_item');
        $collection->add_database_table('local_kbacorp_impl_task', [
            'title' => 'privacy:metadata:local_kbacorp_impl_task:title',
            'ownerid' => 'privacy:metadata:local_kbacorp_impl_task:ownerid',
            'expectedresult' => 'privacy:metadata:local_kbacorp_impl_task:expectedresult',
            'completioncomment' => 'privacy:metadata:local_kbacorp_impl_task:completioncomment',
            'resulturl' => 'privacy:metadata:local_kbacorp_impl_task:resulturl',
            'createdby' => 'privacy:metadata:local_kbacorp_impl_task:createdby',
            'modifiedby' => 'privacy:metadata:local_kbacorp_impl_task:modifiedby',
        ], 'privacy:metadata:local_kbacorp_impl_task');
        $collection->add_database_table('local_kbacorp_impl_tasklog', [
            'taskid' => 'privacy:metadata:local_kbacorp_impl_tasklog:taskid',
            'userid' => 'privacy:metadata:local_kbacorp_impl_tasklog:userid',
            'fromstatus' => 'privacy:metadata:local_kbacorp_impl_tasklog:fromstatus',
            'tostatus' => 'privacy:metadata:local_kbacorp_impl_tasklog:tostatus',
            'eventtype' => 'privacy:metadata:local_kbacorp_impl_tasklog:eventtype',
            'comment' => 'privacy:metadata:local_kbacorp_impl_tasklog:comment',
            'detailsjson' => 'privacy:metadata:local_kbacorp_impl_tasklog:detailsjson',
            'timecreated' => 'privacy:metadata:local_kbacorp_impl_tasklog:timecreated',
        ], 'privacy:metadata:local_kbacorp_impl_tasklog');
        $collection->add_database_table('local_kbacorp_impl_evid', [
            'taskid' => 'privacy:metadata:local_kbacorp_impl_evid:taskid',
            'versionno' => 'privacy:metadata:local_kbacorp_impl_evid:versionno',
            'status' => 'privacy:metadata:local_kbacorp_impl_evid:status',
            'summary' => 'privacy:metadata:local_kbacorp_impl_evid:summary',
            'externalurl' => 'privacy:metadata:local_kbacorp_impl_evid:externalurl',
            'submitterid' => 'privacy:metadata:local_kbacorp_impl_evid:submitterid',
            'submittedat' => 'privacy:metadata:local_kbacorp_impl_evid:submittedat',
            'reviewerid' => 'privacy:metadata:local_kbacorp_impl_evid:reviewerid',
            'reviewedat' => 'privacy:metadata:local_kbacorp_impl_evid:reviewedat',
            'reviewcomment' => 'privacy:metadata:local_kbacorp_impl_evid:reviewcomment',
            'supersedesid' => 'privacy:metadata:local_kbacorp_impl_evid:supersedesid',
            'createdby' => 'privacy:metadata:local_kbacorp_impl_evid:createdby',
            'modifiedby' => 'privacy:metadata:local_kbacorp_impl_evid:modifiedby',
            'timecreated' => 'privacy:metadata:local_kbacorp_impl_evid:timecreated',
            'timemodified' => 'privacy:metadata:local_kbacorp_impl_evid:timemodified',
        ], 'privacy:metadata:local_kbacorp_impl_evid');
        $collection->add_database_table('local_kbacorp_impl_evlog', [
            'evidenceid' => 'privacy:metadata:local_kbacorp_impl_evlog:evidenceid',
            'taskid' => 'privacy:metadata:local_kbacorp_impl_evlog:taskid',
            'userid' => 'privacy:metadata:local_kbacorp_impl_evlog:userid',
            'action' => 'privacy:metadata:local_kbacorp_impl_evlog:action',
            'fromstatus' => 'privacy:metadata:local_kbacorp_impl_evlog:fromstatus',
            'tostatus' => 'privacy:metadata:local_kbacorp_impl_evlog:tostatus',
            'comment' => 'privacy:metadata:local_kbacorp_impl_evlog:comment',
            'detailsjson' => 'privacy:metadata:local_kbacorp_impl_evlog:detailsjson',
            'timecreated' => 'privacy:metadata:local_kbacorp_impl_evlog:timecreated',
        ], 'privacy:metadata:local_kbacorp_impl_evlog');
        $collection->add_database_table('local_kbacorp_impl_check', [
            'taskid' => 'privacy:metadata:local_kbacorp_impl_check:taskid',
            'title' => 'privacy:metadata:local_kbacorp_impl_check:title',
            'isdone' => 'privacy:metadata:local_kbacorp_impl_check:isdone',
            'createdby' => 'privacy:metadata:local_kbacorp_impl_check:createdby',
            'completedby' => 'privacy:metadata:local_kbacorp_impl_check:completedby',
            'completedat' => 'privacy:metadata:local_kbacorp_impl_check:completedat',
        ], 'privacy:metadata:local_kbacorp_impl_check');
        $collection->add_database_table('local_kbacorp_impl_comment', [
            'taskid' => 'privacy:metadata:local_kbacorp_impl_comment:taskid',
            'userid' => 'privacy:metadata:local_kbacorp_impl_comment:userid',
            'message' => 'privacy:metadata:local_kbacorp_impl_comment:message',
            'messageformat' => 'privacy:metadata:local_kbacorp_impl_comment:messageformat',
            'timecreated' => 'privacy:metadata:local_kbacorp_impl_comment:timecreated',
        ], 'privacy:metadata:local_kbacorp_impl_comment');
        $collection->add_database_table('local_kbacorp_impl_read', [
            'taskid' => 'privacy:metadata:local_kbacorp_impl_read:taskid',
            'userid' => 'privacy:metadata:local_kbacorp_impl_read:userid',
            'lastreadtime' => 'privacy:metadata:local_kbacorp_impl_read:lastreadtime',
        ], 'privacy:metadata:local_kbacorp_impl_read');
        $collection->add_database_table('local_kbacorp_impl_econ', [
            'auditid' => 'privacy:metadata:local_kbacorp_impl_econ:auditid',
            'companyid' => 'privacy:metadata:local_kbacorp_impl_econ:companyid',
            'source' => 'privacy:metadata:local_kbacorp_impl_econ:source',
            'comment' => 'privacy:metadata:local_kbacorp_impl_econ:comment',
            'verifiedby' => 'privacy:metadata:local_kbacorp_impl_econ:verifiedby',
            'createdby' => 'privacy:metadata:local_kbacorp_impl_econ:createdby',
            'modifiedby' => 'privacy:metadata:local_kbacorp_impl_econ:modifiedby',
        ], 'privacy:metadata:local_kbacorp_impl_econ');
        $collection->add_database_table('local_kbacorp_econ_ind', [
            'companyid' => 'privacy:metadata:local_kbacorp_econ_ind:companyid',
            'createdby' => 'privacy:metadata:local_kbacorp_econ_ind:createdby',
            'modifiedby' => 'privacy:metadata:local_kbacorp_econ_ind:modifiedby',
        ], 'privacy:metadata:local_kbacorp_econ_ind');
        $collection->add_database_table('local_kbacorp_econ_val', [
            'companyid' => 'privacy:metadata:local_kbacorp_econ_val:companyid',
            'auditid' => 'privacy:metadata:local_kbacorp_econ_val:auditid',
            'source' => 'privacy:metadata:local_kbacorp_econ_val:source',
            'scenario' => 'privacy:metadata:local_kbacorp_econ_val:scenario',
            'comment' => 'privacy:metadata:local_kbacorp_econ_val:comment',
            'verifiedby' => 'privacy:metadata:local_kbacorp_econ_val:verifiedby',
            'createdby' => 'privacy:metadata:local_kbacorp_econ_val:createdby',
            'modifiedby' => 'privacy:metadata:local_kbacorp_econ_val:modifiedby',
        ], 'privacy:metadata:local_kbacorp_econ_val');
        $collection->add_database_table('local_kbacorp_econ_log', [
            'actorid' => 'privacy:metadata:local_kbacorp_econ_log:actorid',
            'oldjson' => 'privacy:metadata:local_kbacorp_econ_log:oldjson',
            'newjson' => 'privacy:metadata:local_kbacorp_econ_log:newjson',
        ], 'privacy:metadata:local_kbacorp_econ_log');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_member} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_devplan}
              WHERE userid = :userid OR createdby = :creatorid OR submittedby = :submitterid
                 OR mentorid = :mentorid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid,
                'creatorid' => $userid, 'submitterid' => $userid, 'mentorid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_devitem}
              WHERE mentorid = :mentorid OR evidencesubmittedby = :submitterid OR reviewedby = :reviewerid',
            ['contextid' => \context_system::instance()->id, 'mentorid' => $userid,
                'submitterid' => $userid, 'reviewerid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_devlog} WHERE actorid = :actorid',
            ['contextid' => \context_system::instance()->id, 'actorid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_dept_member} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_license_user} WHERE userid = :userid OR assignedby = :assignedby',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid, 'assignedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_member_busrole} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT :contextid FROM {local_kbacorp_extmap} WHERE localentity = :localentity AND localid = :userid",
            ['contextid' => \context_system::instance()->id, 'localentity' => 'user', 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid
               FROM {local_kbacorp_audit} a
          LEFT JOIN {local_kbacorp_audit_item} i ON i.auditid = a.id
              WHERE a.userid = :subjectid OR a.createdby = :creatorid
                 OR i.assessorid = :assessorid OR i.reviewerid = :reviewerid',
            ['contextid' => \context_system::instance()->id, 'subjectid' => $userid, 'creatorid' => $userid,
                'assessorid' => $userid, 'reviewerid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_rolever} WHERE createdby = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_bms_toolver} WHERE publishedby = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_audit}
              WHERE auditorid = :auditorid OR confirmedby = :confirmedby',
            ['contextid' => \context_system::instance()->id, 'auditorid' => $userid, 'confirmedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_item}
              WHERE assessorid = :assessorid OR verifierid = :verifierid',
            ['contextid' => \context_system::instance()->id, 'assessorid' => $userid, 'verifierid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_task}
              WHERE ownerid = :ownerid OR createdby = :createdby OR modifiedby = :modifiedby',
            ['contextid' => \context_system::instance()->id, 'ownerid' => $userid,
                'createdby' => $userid, 'modifiedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_comment} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_tasklog} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_evid}
              WHERE submitterid = :submitterid OR reviewerid = :reviewerid
                 OR createdby = :createdby OR modifiedby = :modifiedby',
            ['contextid' => \context_system::instance()->id,
                'submitterid' => $userid, 'reviewerid' => $userid,
                'createdby' => $userid, 'modifiedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_evlog} WHERE userid = :userid',
            ['contextid' => \context_system::instance()->id, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_check}
              WHERE createdby = :createdby OR completedby = :completedby',
            ['contextid' => \context_system::instance()->id, 'createdby' => $userid, 'completedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_impl_econ}
              WHERE verifiedby = :verifiedby OR createdby = :createdby OR modifiedby = :modifiedby',
            ['contextid' => \context_system::instance()->id, 'verifiedby' => $userid,
                'createdby' => $userid, 'modifiedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_econ_ind}
              WHERE createdby = :createdby OR modifiedby = :modifiedby',
            ['contextid' => \context_system::instance()->id,
                'createdby' => $userid, 'modifiedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_econ_val}
              WHERE verifiedby = :verifiedby OR createdby = :createdby OR modifiedby = :modifiedby',
            ['contextid' => \context_system::instance()->id, 'verifiedby' => $userid,
                'createdby' => $userid, 'modifiedby' => $userid]
        );
        $contextlist->add_from_sql(
            'SELECT :contextid FROM {local_kbacorp_econ_log} WHERE actorid = :actorid',
            ['contextid' => \context_system::instance()->id, 'actorid' => $userid]
        );
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        $records = $DB->get_records_sql(
            "SELECT m.*, c.name AS companyname
               FROM {local_kbacorp_member} m
               JOIN {local_kbacorp_company} c ON c.id = m.companyid
              WHERE m.userid = :userid",
            ['userid' => $userid]
        );
        $data = [];
        foreach ($records as $record) {
            $data[] = (object)[
                'company' => $record->companyname,
                'type' => $record->membertype,
                'isemployee' => (int)$record->isemployee,
                'ismanager' => (int)$record->ismanager,
                'isdepartmentmanager' => (int)$record->isdepartmentmanager,
                'ishr' => (int)$record->ishr,
                'created' => transform::datetime($record->timecreated),
            ];
        }
        $departmentrecords = $DB->get_records_sql(
            "SELECT dm.*, d.name AS departmentname, c.name AS companyname
               FROM {local_kbacorp_dept_member} dm
               JOIN {local_kbacorp_department} d ON d.id = dm.departmentid
               JOIN {local_kbacorp_company} c ON c.id = d.companyid
              WHERE dm.userid = :userid",
            ['userid' => $userid]
        );
        $departmentdata = [];
        foreach ($departmentrecords as $record) {
            $departmentdata[] = (object)[
                'company' => $record->companyname,
                'department' => $record->departmentname,
                'role' => $record->deptrole,
                'created' => transform::datetime($record->timecreated),
            ];
        }
        $licenserecords = $DB->get_records_sql(
            "SELECT lu.*, l.courseid, l.datefrom, l.dateto, c.fullname AS coursename, co.name AS companyname
               FROM {local_kbacorp_license_user} lu
               JOIN {local_kbacorp_license} l ON l.id = lu.licenseid
               JOIN {course} c ON c.id = l.courseid
               JOIN {local_kbacorp_company} co ON co.id = l.companyid
              WHERE lu.userid = :userid",
            ['userid' => $userid]
        );
        $licensedata = [];
        foreach ($licenserecords as $record) {
            $licensedata[] = (object)[
                'company' => $record->companyname,
                'course' => $record->coursename,
                'status' => $record->status,
                'assigned' => transform::datetime($record->timeassigned),
                'started' => $record->timestarted ? transform::datetime($record->timestarted) : null,
                'completed' => $record->timecompleted ? transform::datetime($record->timecompleted) : null,
                'released' => $record->timereleased ? transform::datetime($record->timereleased) : null,
            ];
        }
        $assignedrecords = $DB->get_records_sql(
            "SELECT lu.id, lu.userid, lu.status, lu.timeassigned, l.courseid,
                    c.fullname AS coursename, co.name AS companyname,
                    u.firstname, u.lastname, u.email
               FROM {local_kbacorp_license_user} lu
               JOIN {local_kbacorp_license} l ON l.id = lu.licenseid
               JOIN {course} c ON c.id = l.courseid
               JOIN {local_kbacorp_company} co ON co.id = l.companyid
               JOIN {user} u ON u.id = lu.userid
              WHERE lu.assignedby = :assignedby",
            ['assignedby' => $userid]
        );
        $assigneddata = [];
        foreach ($assignedrecords as $record) {
            $assigneddata[] = (object)[
                'company' => $record->companyname,
                'course' => $record->coursename,
                'employee' => fullname($record),
                'employeeemail' => $record->email,
                'status' => $record->status,
                'assigned' => transform::datetime($record->timeassigned),
            ];
        }
        $businessrolerecords = $DB->get_records_sql(
            "SELECT ur.*, r.code AS rolecode, r.name AS rolename, c.name AS companyname
               FROM {local_kbacorp_member_busrole} ur
               JOIN {local_kbacorp_busrole} r ON r.id = ur.roleid
               JOIN {local_kbacorp_company} c ON c.id = ur.companyid
              WHERE ur.userid = :userid",
            ['userid' => $userid]
        );
        $businessroledata = [];
        foreach ($businessrolerecords as $record) {
            $businessroledata[] = (object)[
                'company' => $record->companyname,
                'rolecode' => $record->rolecode,
                'role' => $record->rolename,
                'source' => $record->source,
                'externalref' => $record->externalref,
                'active' => (int)$record->active,
                'effectivefrom' => $record->effectivefrom ? transform::datetime($record->effectivefrom) : null,
                'effectiveto' => $record->effectiveto ? transform::datetime($record->effectiveto) : null,
            ];
        }
        $externalmappingrecords = $DB->get_records_sql(
            "SELECT em.*
               FROM {local_kbacorp_extmap} em
              WHERE (em.localentity = :userentity AND em.localid = :userid)
                 OR (em.localentity = :assignmententity AND EXISTS (
                        SELECT 1
                          FROM {local_kbacorp_member_busrole} ur
                         WHERE ur.id = em.localid AND ur.userid = :assignmentuserid
                    ))
           ORDER BY em.system, em.entitytype, em.externalid",
            ['userentity' => 'user', 'userid' => $userid,
                'assignmententity' => 'business_role_assignment', 'assignmentuserid' => $userid]
        );
        $externalmappingdata = [];
        foreach ($externalmappingrecords as $record) {
            $externalmappingdata[] = (object)[
                'companyid' => (int)$record->companyid,
                'system' => $record->system,
                'entitytype' => $record->entitytype,
                'externalid' => $record->externalid,
                'externalcode' => $record->externalcode,
                'status' => $record->status,
                'metadata' => $record->metadata,
                'lastsync' => $record->lastsync ? transform::datetime($record->lastsync) : null,
            ];
        }
        $auditrecords = $DB->get_records_sql(
            "SELECT a.*, co.name AS companyname
               FROM {local_kbacorp_audit} a
               JOIN {local_kbacorp_company} co ON co.id = a.companyid
              WHERE a.userid = :subjectid OR a.createdby = :creatorid
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_audit_item} i
                             WHERE i.auditid = a.id
                               AND (i.assessorid = :assessorid OR i.reviewerid = :reviewerid))
           ORDER BY a.timecreated",
            ['subjectid' => $userid, 'creatorid' => $userid, 'assessorid' => $userid, 'reviewerid' => $userid]
        );
        $auditsessions = [];
        foreach ($auditrecords as $record) {
            $auditsessions[] = (object)[
                'company' => $record->companyname,
                'title' => $record->title,
                'description' => $record->description,
                'type' => $record->audittype,
                'status' => $record->status,
                'created' => transform::datetime($record->timecreated),
                'completed' => $record->completedat ? transform::datetime($record->completedat) : null,
            ];
        }
        $audititemrecords = $DB->get_records_sql(
            "SELECT i.*, a.title AS audittitle, c.shortname AS competency, co.name AS companyname
               FROM {local_kbacorp_audit_item} i
               JOIN {local_kbacorp_audit} a ON a.id = i.auditid
               JOIN {local_kbacorp_company} co ON co.id = a.companyid
               JOIN {competency} c ON c.id = i.competencyid
              WHERE a.userid = :subjectid OR a.createdby = :creatorid
                 OR i.assessorid = :assessorid OR i.reviewerid = :reviewerid
           ORDER BY i.timecreated",
            ['subjectid' => $userid, 'creatorid' => $userid, 'assessorid' => $userid, 'reviewerid' => $userid]
        );
        $auditassessments = [];
        foreach ($audititemrecords as $record) {
            $auditassessments[] = (object)[
                'company' => $record->companyname,
                'audit' => $record->audittitle,
                'competency' => $record->competency,
                'method' => $record->method,
                'proposedlevel' => $record->proposedlevel,
                'targetlevel' => $record->targetlevel,
                'priority' => $record->priority,
                'required' => (int)$record->required,
                'source' => $record->source,
                'rolesnapshot' => $record->rolesnapshot,
                'assessmentstatus' => $record->status,
                'comment' => $record->comment,
                'evidenceurl' => $record->evidenceurl,
                'assessed' => $record->timeassessed ? transform::datetime($record->timeassessed) : null,
                'confirmed' => $record->timeconfirmed ? transform::datetime($record->timeconfirmed) : null,
            ];
        }
        $auditevidencerecords = $DB->get_records_sql(
            "SELECT e.*, a.title AS audittitle, c.shortname AS competency, co.name AS companyname
               FROM {local_kbacorp_audit_ev} e
               JOIN {local_kbacorp_audit_item} i ON i.id = e.audititemid
               JOIN {local_kbacorp_audit} a ON a.id = i.auditid
               JOIN {local_kbacorp_company} co ON co.id = a.companyid
               JOIN {competency} c ON c.id = i.competencyid
              WHERE a.userid = :evidencesubjectid OR a.createdby = :evidencecreatorid
                 OR i.assessorid = :evidenceassessorid OR i.reviewerid = :evidencereviewerid
           ORDER BY e.timecreated",
            ['evidencesubjectid' => $userid, 'evidencecreatorid' => $userid,
                'evidenceassessorid' => $userid, 'evidencereviewerid' => $userid]
        );
        $auditevidence = [];
        foreach ($auditevidencerecords as $record) {
            $auditevidence[] = (object)[
                'company' => $record->companyname,
                'audit' => $record->audittitle,
                'competency' => $record->competency,
                'sourcetype' => $record->sourcetype,
                'sourcecomponent' => $record->sourcecomponent,
                'sourceitemid' => (int)$record->sourceitemid,
                'title' => $record->title,
                'resultvalue' => $record->resultvalue,
                'proposedlevel' => $record->proposedlevel,
                'url' => $record->url,
                'details' => $record->details,
                'observed' => $record->timeobserved ? transform::datetime($record->timeobserved) : null,
            ];
        }
        $auditdata = (object)[
            'sessions' => $auditsessions,
            'assessments' => $auditassessments,
            'evidence' => $auditevidence,
        ];
        $templateversionrecords = $DB->get_records('local_kbacorp_rolever', ['createdby' => $userid], 'timecreated');
        $templateversiondata = [];
        foreach ($templateversionrecords as $record) {
            $templateversiondata[] = (object)[
                'templateid' => (int)$record->templateid,
                'version' => (int)$record->version,
                'name' => $record->name,
                'changenote' => $record->changenote,
                'created' => transform::datetime($record->timecreated),
            ];
        }
        $bmstoolversionrecords = $DB->get_records('local_kbacorp_bms_toolver',
            ['publishedby' => $userid], 'publishedat');
        $bmstoolversiondata = [];
        foreach ($bmstoolversionrecords as $record) {
            $bmstoolversiondata[] = (object)[
                'toolid' => (int)$record->toolid,
                'version' => $record->versionlabel,
                'changenote' => $record->changenote,
                'published' => transform::datetime($record->publishedat),
            ];
        }
        $developmentrecords = $DB->get_records_sql(
            "SELECT p.*, co.name AS companyname
               FROM {local_kbacorp_devplan} p
               JOIN {local_kbacorp_company} co ON co.id = p.companyid
              WHERE p.userid = :subjectid OR p.createdby = :creatorid OR p.submittedby = :submitterid
                 OR p.mentorid = :mentorid
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_devitem} pi
                             WHERE pi.planid = p.id AND (pi.mentorid = :itemmentorid
                                OR pi.evidencesubmittedby = :evidencesubmitterid
                                OR pi.reviewedby = :reviewerid))
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_devlog} pl
                             WHERE pl.planid = p.id AND pl.actorid = :historyactorid)
           ORDER BY p.timecreated",
            ['subjectid' => $userid, 'creatorid' => $userid, 'submitterid' => $userid,
                'mentorid' => $userid, 'itemmentorid' => $userid,
                'evidencesubmitterid' => $userid, 'reviewerid' => $userid, 'historyactorid' => $userid]
        );
        $developmentdata = [];
        foreach ($developmentrecords as $record) {
            $items = $DB->get_records_sql(
                "SELECT i.*, c.shortname AS competency, crs.fullname AS course
                   FROM {local_kbacorp_devitem} i
                   JOIN {competency} c ON c.id = i.competencyid
              LEFT JOIN {course} crs ON crs.id = i.courseid
                  WHERE i.planid = :planid ORDER BY i.id",
                ['planid' => $record->id]
            );
            $history = $DB->get_records('local_kbacorp_devlog', ['planid' => $record->id], 'timecreated, id');
            $developmentdata[] = (object)[
                'company' => $record->companyname,
                'title' => $record->title,
                'objective' => $record->objective,
                'mentorid' => (int)$record->mentorid,
                'status' => $record->status,
                'startdate' => transform::datetime($record->startdate),
                'targetdate' => transform::datetime($record->targetdate),
                'reviewdate' => $record->reviewdate ? transform::datetime($record->reviewdate) : null,
                'items' => array_values($items),
                'history' => array_values($history),
            ];
        }
        $implementationrecords = $DB->get_records_sql(
            "SELECT a.*, t.name AS toolname, co.name AS companyname
               FROM {local_kbacorp_impl_audit} a
               JOIN {local_kbacorp_bms_tool} t ON t.id = a.toolid
               JOIN {local_kbacorp_company} co ON co.id = a.companyid
              WHERE a.auditorid = :auditorid OR a.confirmedby = :confirmedby
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_item} i
                             WHERE i.auditid = a.id
                               AND (i.assessorid = :assessorid OR i.verifierid = :verifierid))
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} it
                             WHERE it.auditid = a.id
                               AND (it.ownerid = :taskowner OR it.createdby = :taskcreator
                                    OR it.modifiedby = :taskmodifier))
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} ct
                             JOIN {local_kbacorp_impl_comment} ic ON ic.taskid = ct.id
                            WHERE ct.auditid = a.id AND ic.userid = :commenter)
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} lt
                             JOIN {local_kbacorp_impl_tasklog} il ON il.taskid = lt.id
                            WHERE lt.auditid = a.id AND il.userid = :transitionactor)
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} et
                             JOIN {local_kbacorp_impl_evid} ev ON ev.taskid = et.id
                            WHERE et.auditid = a.id
                              AND (ev.submitterid = :evidencesubmitter
                                   OR ev.reviewerid = :evidencereviewer
                                   OR ev.createdby = :evidencecreator
                                   OR ev.modifiedby = :evidencemodifier))
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} elt
                             JOIN {local_kbacorp_impl_evlog} el ON el.taskid = elt.id
                            WHERE elt.auditid = a.id AND el.userid = :evidencelogactor)
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_task} kt
                             JOIN {local_kbacorp_impl_check} ck ON ck.taskid = kt.id
                            WHERE kt.auditid = a.id
                              AND (ck.createdby = :checkcreator OR ck.completedby = :checkcompleter))
                 OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_econ} ie
                             WHERE ie.auditid = a.id
                               AND (ie.verifiedby = :econverifier OR ie.createdby = :econcreator
                                    OR ie.modifiedby = :econmodifier))
           ORDER BY a.auditdate, a.id",
            ['auditorid' => $userid, 'confirmedby' => $userid,
                'assessorid' => $userid, 'verifierid' => $userid,
                'taskowner' => $userid, 'taskcreator' => $userid, 'taskmodifier' => $userid,
                'commenter' => $userid, 'transitionactor' => $userid,
                'evidencesubmitter' => $userid, 'evidencereviewer' => $userid,
                'evidencecreator' => $userid, 'evidencemodifier' => $userid,
                'evidencelogactor' => $userid,
                'checkcreator' => $userid, 'checkcompleter' => $userid,
                'econverifier' => $userid, 'econcreator' => $userid, 'econmodifier' => $userid]
        );
        $implementationdata = [];
        foreach ($implementationrecords as $record) {
            $tasks = $DB->get_records_select('local_kbacorp_impl_task',
                'auditid = :auditid AND (ownerid = :userid OR createdby = :userid2 OR modifiedby = :userid3 ' .
                    'OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_comment} pc ' .
                    'WHERE pc.taskid = {local_kbacorp_impl_task}.id AND pc.userid = :commenter) ' .
                    'OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_tasklog} pl ' .
                    'WHERE pl.taskid = {local_kbacorp_impl_task}.id AND pl.userid = :transitionactor) ' .
                    'OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} pe ' .
                    'WHERE pe.taskid = {local_kbacorp_impl_task}.id ' .
                    'AND (pe.submitterid = :evidencesubmitter OR pe.reviewerid = :evidencereviewer ' .
                    'OR pe.createdby = :evidencecreator OR pe.modifiedby = :evidencemodifier)) ' .
                    'OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_evlog} pel ' .
                    'WHERE pel.taskid = {local_kbacorp_impl_task}.id AND pel.userid = :evidencelogactor) ' .
                    'OR EXISTS (SELECT 1 FROM {local_kbacorp_impl_check} pk ' .
                    'WHERE pk.taskid = {local_kbacorp_impl_task}.id ' .
                    'AND (pk.createdby = :checkcreator OR pk.completedby = :checkcompleter)))',
                ['auditid' => $record->id, 'userid' => $userid, 'userid2' => $userid, 'userid3' => $userid,
                    'commenter' => $userid, 'transitionactor' => $userid,
                    'evidencesubmitter' => $userid, 'evidencereviewer' => $userid,
                    'evidencecreator' => $userid, 'evidencemodifier' => $userid,
                    'evidencelogactor' => $userid,
                    'checkcreator' => $userid, 'checkcompleter' => $userid],
                'id', 'title, status, expectedresult, completioncomment, resulturl, startdate, duedate, completedat');
            foreach ($tasks as $taskid => $task) {
                $task->discussion = array_values($DB->get_records('local_kbacorp_impl_comment', [
                    'taskid' => $taskid, 'userid' => $userid,
                ], 'timecreated ASC', 'message, timecreated'));
                $task->statushistory = array_values($DB->get_records('local_kbacorp_impl_tasklog', [
                    'taskid' => $taskid, 'userid' => $userid,
                ], 'timecreated ASC', 'fromstatus, tostatus, eventtype, comment, detailsjson, timecreated'));
                $task->evidenceversions = array_values($DB->get_records_select(
                    'local_kbacorp_impl_evid',
                    'taskid = :taskid AND (submitterid = :submitterid OR reviewerid = :reviewerid ' .
                        'OR createdby = :createdby OR modifiedby = :modifiedby)',
                    ['taskid' => $taskid, 'submitterid' => $userid, 'reviewerid' => $userid,
                        'createdby' => $userid, 'modifiedby' => $userid],
                    'versionno ASC',
                    'id, versionno, status, summary, externalurl, submitterid, submittedat, ' .
                        'reviewerid, reviewedat, reviewcomment, timecreated, timemodified'
                ));
                foreach ($task->evidenceversions as $evidenceversion) {
                    $evidenceversion->files = array_map(static function($file) {
                        return $file->get_filename();
                    }, \local_kbacorporate\local\implementation_evidence::get_files(
                        (int)$evidenceversion->id));
                    unset($evidenceversion->id);
                }
                $task->evidencehistory = array_values($DB->get_records(
                    'local_kbacorp_impl_evlog',
                    ['taskid' => $taskid, 'userid' => $userid],
                    'timecreated ASC',
                    'action, fromstatus, tostatus, comment, detailsjson, timecreated'
                ));
                $task->checklist = array_values($DB->get_records_select('local_kbacorp_impl_check',
                    'taskid = :taskid AND (createdby = :createdby OR completedby = :completedby)',
                    ['taskid' => $taskid, 'createdby' => $userid, 'completedby' => $userid],
                    'sortorder ASC, id ASC', 'title, isdone, completedat'));
            }
            $economic = $DB->get_record_select('local_kbacorp_impl_econ',
                'auditid = :auditid AND (verifiedby = :userid OR createdby = :userid2 OR modifiedby = :userid3)',
                ['auditid' => $record->id, 'userid' => $userid, 'userid2' => $userid, 'userid3' => $userid],
                'periodtype, periodstart, periodend, currency, revenue, grossprofit, operatingprofit, netprofit, ' .
                'profittax, vat, socialfunds, othertaxes, payroll, employees, averagesalary, source, comment, verified');
            $implementationdata[] = (object)[
                'company' => $record->companyname,
                'tool' => $record->toolname,
                'title' => $record->title,
                'type' => $record->audittype,
                'status' => $record->status,
                'auditdate' => transform::datetime($record->auditdate),
                'bmsindex' => $record->indexvalue,
                'evidencecoverage' => $record->coverage,
                'tasks' => array_values($tasks),
                'economicindicators' => $economic ?: null,
            ];
        }
        $economicregistrydata = (object)[
            'indicatorsauthored' => array_values($DB->get_records_select(
                'local_kbacorp_econ_ind',
                'createdby = :createdby OR modifiedby = :modifiedby',
                ['createdby' => $userid, 'modifiedby' => $userid],
                'timemodified DESC',
                'companyid, code, name, description, category, valuetype, unit, periodtype, active, timecreated, timemodified'
            )),
            'valuesauthored' => array_values($DB->get_records_select(
                'local_kbacorp_econ_val',
                'verifiedby = :verifiedby OR createdby = :createdby OR modifiedby = :modifiedby',
                ['verifiedby' => $userid, 'createdby' => $userid, 'modifiedby' => $userid],
                'timemodified DESC',
                'companyid, indicatorid, auditid, periodtype, periodstart, periodend, periodkey, scenario, value, currency, source, comment, verified, timecreated, timemodified'
            )),
            'changesauthored' => array_values($DB->get_records('local_kbacorp_econ_log',
                ['actorid' => $userid], 'timecreated DESC',
                'companyid, entitytype, entityid, action, oldjson, newjson, source, timecreated')),
        ];
        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_kbacorporate')],
            (object)[
                'memberships' => $data,
                'departments' => $departmentdata,
                'licences' => $licensedata,
                'licenceassignmentsmade' => $assigneddata,
                'businessroles' => $businessroledata,
                'externalmappings' => $externalmappingdata,
                'competencyaudits' => $auditdata,
                'templateversionsauthored' => $templateversiondata,
                'bmstoolversionsauthored' => $bmstoolversiondata,
                'developmentplans' => $developmentdata,
                'implementationaudits' => $implementationdata,
                'economicindicatorregistry' => $economicregistrydata,
            ]
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            role_unassign_all(['component' => 'local_kbacorporate']);
            $DB->delete_records('local_kbacorp_audit_ev');
            $DB->delete_records('local_kbacorp_audit_item');
            $DB->delete_records('local_kbacorp_audit');
            $DB->delete_records('local_kbacorp_devlog');
            $DB->delete_records('local_kbacorp_devitem');
            $DB->delete_records('local_kbacorp_devplan');
            $DB->set_field('local_kbacorp_impl_item', 'assessorid', 0);
            $DB->set_field('local_kbacorp_impl_item', 'verifierid', 0);
            $DB->set_field('local_kbacorp_impl_audit', 'auditorid', 0);
            $DB->set_field('local_kbacorp_impl_audit', 'confirmedby', 0);
            $DB->set_field('local_kbacorp_bms_toolver', 'publishedby', 0);
            $DB->set_field('local_kbacorp_impl_task', 'ownerid', 0);
            $DB->set_field('local_kbacorp_impl_task', 'createdby', 0);
            $DB->set_field('local_kbacorp_impl_task', 'modifiedby', 0);
            $DB->set_field('local_kbacorp_impl_evid', 'submitterid', 0);
            $DB->set_field('local_kbacorp_impl_evid', 'reviewerid', 0);
            $DB->set_field('local_kbacorp_impl_evid', 'createdby', 0);
            $DB->set_field('local_kbacorp_impl_evid', 'modifiedby', 0);
            $DB->delete_records('local_kbacorp_impl_evlog');
            $DB->delete_records('local_kbacorp_impl_comment');
            $DB->delete_records('local_kbacorp_impl_read');
            $DB->delete_records('local_kbacorp_impl_tasklog');
            $DB->set_field('local_kbacorp_impl_check', 'createdby', 0);
            $DB->set_field('local_kbacorp_impl_check', 'completedby', 0);
            $DB->set_field('local_kbacorp_impl_econ', 'verifiedby', 0);
            $DB->set_field('local_kbacorp_impl_econ', 'createdby', 0);
            $DB->set_field('local_kbacorp_impl_econ', 'modifiedby', 0);
            $DB->set_field('local_kbacorp_econ_ind', 'createdby', 0);
            $DB->set_field('local_kbacorp_econ_ind', 'modifiedby', 0);
            $DB->set_field('local_kbacorp_econ_val', 'verifiedby', 0);
            $DB->set_field('local_kbacorp_econ_val', 'createdby', 0);
            $DB->set_field('local_kbacorp_econ_val', 'modifiedby', 0);
            self::anonymise_economic_history_user_references(null);
            $DB->set_field('local_kbacorp_econ_log', 'actorid', 0);
            $DB->delete_records_select('local_kbacorp_extmap',
                'localentity = :userentity OR localentity = :assignmententity',
                ['userentity' => 'user', 'assignmententity' => 'business_role_assignment']);
            $DB->delete_records('local_kbacorp_member_busrole');
            $DB->delete_records('local_kbacorp_license_user');
            $DB->delete_records('local_kbacorp_dept_member');
            $DB->delete_records('local_kbacorp_member');
            $DB->set_field('local_kbacorp_rolever', 'createdby', 0);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            $userid = $contextlist->get_user()->id;
            role_unassign_all(['component' => 'local_kbacorporate', 'userid' => $userid]);
            self::delete_or_anonymise_audit_data([$userid]);
            self::delete_or_anonymise_development_plan_data([$userid]);
            self::anonymise_implementation_audit_actors([$userid]);
            self::anonymise_kanban_history_owner_references([$userid]);
            self::anonymise_economic_history_user_references([$userid]);
            $DB->set_field('local_kbacorp_impl_task', 'ownerid', 0, ['ownerid' => $userid]);
            $DB->set_field('local_kbacorp_impl_task', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacorp_impl_task', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->set_field('local_kbacorp_impl_evid', 'submitterid', 0, ['submitterid' => $userid]);
            $DB->set_field('local_kbacorp_impl_evid', 'reviewerid', 0, ['reviewerid' => $userid]);
            $DB->set_field('local_kbacorp_impl_evid', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacorp_impl_evid', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->delete_records('local_kbacorp_impl_evlog', ['userid' => $userid]);
            $DB->delete_records('local_kbacorp_impl_comment', ['userid' => $userid]);
            $DB->delete_records('local_kbacorp_impl_read', ['userid' => $userid]);
            $DB->delete_records('local_kbacorp_impl_tasklog', ['userid' => $userid]);
            $DB->set_field('local_kbacorp_impl_check', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacorp_impl_check', 'completedby', 0, ['completedby' => $userid]);
            $DB->set_field('local_kbacorp_impl_econ', 'verifiedby', 0, ['verifiedby' => $userid]);
            $DB->set_field('local_kbacorp_impl_econ', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacorp_impl_econ', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->set_field('local_kbacorp_rolever', 'createdby', 0, ['createdby' => $userid]);
            self::delete_external_user_mappings([$userid]);
            $DB->delete_records('local_kbacorp_member_busrole', ['userid' => $userid]);
            $DB->set_field('local_kbacorp_license_user', 'assignedby', 0, ['assignedby' => $userid]);
            $DB->delete_records('local_kbacorp_license_user', ['userid' => $userid]);
            $DB->delete_records('local_kbacorp_dept_member', ['userid' => $userid]);
            $DB->delete_records('local_kbacorp_member', ['userid' => $userid]);
        }
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_member}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_dept_member}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_license_user}', []);
        $userlist->add_from_sql('assignedby', 'SELECT assignedby FROM {local_kbacorp_license_user} WHERE assignedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_member_busrole}', []);
        $userlist->add_from_sql(
            'localid',
            'SELECT localid FROM {local_kbacorp_extmap} WHERE localentity = :localentity',
            ['localentity' => 'user']
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT ur.userid
               FROM {local_kbacorp_extmap} em
               JOIN {local_kbacorp_member_busrole} ur ON ur.id = em.localid
              WHERE em.localentity = :localentity",
            ['localentity' => 'business_role_assignment']
        );
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_audit}', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_audit} WHERE createdby > 0', []);
        $userlist->add_from_sql('assessorid', 'SELECT assessorid FROM {local_kbacorp_audit_item} WHERE assessorid > 0', []);
        $userlist->add_from_sql('reviewerid', 'SELECT reviewerid FROM {local_kbacorp_audit_item} WHERE reviewerid > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_rolever} WHERE createdby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_devplan}', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_devplan} WHERE createdby > 0', []);
        $userlist->add_from_sql('submittedby', 'SELECT submittedby FROM {local_kbacorp_devplan} WHERE submittedby > 0', []);
        $userlist->add_from_sql('mentorid', 'SELECT mentorid FROM {local_kbacorp_devplan} WHERE mentorid > 0', []);
        $userlist->add_from_sql('mentorid', 'SELECT mentorid FROM {local_kbacorp_devitem} WHERE mentorid > 0', []);
        $userlist->add_from_sql('evidencesubmittedby', 'SELECT evidencesubmittedby FROM {local_kbacorp_devitem} WHERE evidencesubmittedby > 0', []);
        $userlist->add_from_sql('reviewedby', 'SELECT reviewedby FROM {local_kbacorp_devitem} WHERE reviewedby > 0', []);
        $userlist->add_from_sql('actorid', 'SELECT actorid FROM {local_kbacorp_devlog} WHERE actorid > 0', []);
        $userlist->add_from_sql('auditorid', 'SELECT auditorid FROM {local_kbacorp_impl_audit} WHERE auditorid > 0', []);
        $userlist->add_from_sql('confirmedby', 'SELECT confirmedby FROM {local_kbacorp_impl_audit} WHERE confirmedby > 0', []);
        $userlist->add_from_sql('publishedby', 'SELECT publishedby FROM {local_kbacorp_bms_toolver} WHERE publishedby > 0', []);
        $userlist->add_from_sql('assessorid', 'SELECT assessorid FROM {local_kbacorp_impl_item} WHERE assessorid > 0', []);
        $userlist->add_from_sql('verifierid', 'SELECT verifierid FROM {local_kbacorp_impl_item} WHERE verifierid > 0', []);
        $userlist->add_from_sql('ownerid', 'SELECT ownerid FROM {local_kbacorp_impl_task} WHERE ownerid > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_impl_task} WHERE createdby > 0', []);
        $userlist->add_from_sql('modifiedby', 'SELECT modifiedby FROM {local_kbacorp_impl_task} WHERE modifiedby > 0', []);
        $userlist->add_from_sql('submitterid', 'SELECT submitterid FROM {local_kbacorp_impl_evid} WHERE submitterid > 0', []);
        $userlist->add_from_sql('reviewerid', 'SELECT reviewerid FROM {local_kbacorp_impl_evid} WHERE reviewerid > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_impl_evid} WHERE createdby > 0', []);
        $userlist->add_from_sql('modifiedby', 'SELECT modifiedby FROM {local_kbacorp_impl_evid} WHERE modifiedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_impl_evlog} WHERE userid > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_impl_comment}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_impl_read}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_kbacorp_impl_tasklog}', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_impl_check} WHERE createdby > 0', []);
        $userlist->add_from_sql('completedby', 'SELECT completedby FROM {local_kbacorp_impl_check} WHERE completedby > 0', []);
        $userlist->add_from_sql('verifiedby', 'SELECT verifiedby FROM {local_kbacorp_impl_econ} WHERE verifiedby > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_impl_econ} WHERE createdby > 0', []);
        $userlist->add_from_sql('modifiedby', 'SELECT modifiedby FROM {local_kbacorp_impl_econ} WHERE modifiedby > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_econ_ind} WHERE createdby > 0', []);
        $userlist->add_from_sql('modifiedby', 'SELECT modifiedby FROM {local_kbacorp_econ_ind} WHERE modifiedby > 0', []);
        $userlist->add_from_sql('verifiedby', 'SELECT verifiedby FROM {local_kbacorp_econ_val} WHERE verifiedby > 0', []);
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_kbacorp_econ_val} WHERE createdby > 0', []);
        $userlist->add_from_sql('modifiedby', 'SELECT modifiedby FROM {local_kbacorp_econ_val} WHERE modifiedby > 0', []);
        $userlist->add_from_sql('actorid', 'SELECT actorid FROM {local_kbacorp_econ_log} WHERE actorid > 0', []);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        foreach ($userids as $userid) {
            role_unassign_all(['component' => 'local_kbacorporate', 'userid' => $userid]);
        }
        self::delete_or_anonymise_audit_data($userids);
        self::delete_or_anonymise_development_plan_data($userids);
        self::anonymise_implementation_audit_actors($userids);
        self::anonymise_kanban_history_owner_references($userids);
        self::anonymise_economic_history_user_references($userids);
        self::delete_external_user_mappings($userids);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('local_kbacorp_rolever', 'createdby', 0, "createdby $insql", $params);
        $DB->delete_records_select('local_kbacorp_member_busrole', "userid $insql", $params);
        $DB->set_field_select('local_kbacorp_license_user', 'assignedby', 0, "assignedby $insql", $params);
        $DB->delete_records_select('local_kbacorp_license_user', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_dept_member', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_member', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_impl_comment', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_impl_read', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_impl_tasklog', "userid $insql", $params);
        $DB->delete_records_select('local_kbacorp_impl_evlog', "userid $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'submitterid', 0, "submitterid $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'reviewerid', 0, "reviewerid $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'createdby', 0, "createdby $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'modifiedby', 0, "modifiedby $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_check', 'createdby', 0, "createdby $insql", $params);
        $DB->set_field_select('local_kbacorp_impl_check', 'completedby', 0, "completedby $insql", $params);
    }

    /** Preserve company audit history while removing personal actor references. */
    private static function anonymise_implementation_audit_actors(array $userids): void {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'implactor');
        $DB->set_field_select('local_kbacorp_impl_audit', 'auditorid', 0, "auditorid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_audit', 'confirmedby', 0, "confirmedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_bms_toolver', 'publishedby', 0, "publishedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_item', 'assessorid', 0, "assessorid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_item', 'verifierid', 0, "verifierid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_task', 'ownerid', 0, "ownerid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_task', 'createdby', 0, "createdby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_task', 'modifiedby', 0, "modifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'submitterid', 0, "submitterid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'reviewerid', 0, "reviewerid {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'createdby', 0, "createdby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_evid', 'modifiedby', 0, "modifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_econ', 'verifiedby', 0, "verifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_econ', 'createdby', 0, "createdby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_impl_econ', 'modifiedby', 0, "modifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_ind', 'createdby', 0, "createdby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_ind', 'modifiedby', 0, "modifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_val', 'verifiedby', 0, "verifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_val', 'createdby', 0, "createdby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_val', 'modifiedby', 0, "modifiedby {$insql}", $params);
        $DB->set_field_select('local_kbacorp_econ_log', 'actorid', 0, "actorid {$insql}", $params);
    }

    /** Remove deleted user IDs from Kanban before/after payloads while preserving the audit event. */
    private static function anonymise_kanban_history_owner_references(array $userids): void {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return;
        }
        $table = new \xmldb_table('local_kbacorp_impl_tasklog');
        $field = new \xmldb_field('detailsjson');
        if (!$DB->get_manager()->table_exists($table) ||
                !$DB->get_manager()->field_exists($table, $field)) {
            return;
        }

        $lookup = array_fill_keys($userids, true);
        $records = $DB->get_records_select(
            'local_kbacorp_impl_tasklog',
            'eventtype = :eventtype AND detailsjson IS NOT NULL',
            ['eventtype' => 'kanban'],
            '',
            'id, detailsjson'
        );
        foreach ($records as $record) {
            $details = json_decode((string)$record->detailsjson, true);
            if (!is_array($details)) {
                continue;
            }
            $changed = false;
            foreach (['ownerfrom', 'ownerto'] as $key) {
                if (isset($details[$key]) && isset($lookup[(int)$details[$key]])) {
                    $details[$key] = 0;
                    $changed = true;
                }
            }
            if ($changed) {
                $DB->set_field(
                    'local_kbacorp_impl_tasklog',
                    'detailsjson',
                    json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ['id' => (int)$record->id]
                );
            }
        }
    }

    /**
     * Remove verifier/user IDs embedded in immutable economic-history JSON payloads.
     *
     * @param array|null $userids Specific user IDs, or null to anonymise all positive IDs.
     */
    private static function anonymise_economic_history_user_references(?array $userids): void {
        global $DB;

        $table = new \xmldb_table('local_kbacorp_econ_log');
        if (!$DB->get_manager()->table_exists($table)) {
            return;
        }
        $lookup = null;
        if ($userids !== null) {
            $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
            if (!$userids) {
                return;
            }
            $lookup = array_fill_keys($userids, true);
        }
        $records = $DB->get_records_select(
            'local_kbacorp_econ_log',
            'oldjson IS NOT NULL OR newjson IS NOT NULL',
            [],
            '',
            'id, oldjson, newjson'
        );
        $personalkeys = ['verifiedby', 'createdby', 'modifiedby', 'actorid'];
        $scrub = static function(&$value, ?array $lookup) use (&$scrub, $personalkeys): bool {
            if (!is_array($value)) {
                return false;
            }
            $changed = false;
            foreach ($value as $key => &$item) {
                if (in_array((string)$key, $personalkeys, true) && is_numeric($item)) {
                    $userid = (int)$item;
                    if ($userid > 0 && ($lookup === null || isset($lookup[$userid]))) {
                        $item = 0;
                        $changed = true;
                    }
                } else if (is_array($item) && $scrub($item, $lookup)) {
                    $changed = true;
                }
            }
            unset($item);
            return $changed;
        };
        foreach ($records as $record) {
            $updates = [];
            foreach (['oldjson', 'newjson'] as $field) {
                if (empty($record->{$field})) {
                    continue;
                }
                $payload = json_decode((string)$record->{$field}, true);
                if (!is_array($payload) || !$scrub($payload, $lookup)) {
                    continue;
                }
                $updates[$field] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($updates) {
                $updates['id'] = (int)$record->id;
                $DB->update_record('local_kbacorp_econ_log', (object)$updates);
            }
        }
    }

    /** Remove direct user mappings and mappings owned by the user's business-role assignments. */
    private static function delete_external_user_mappings(array $userids): void {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'extuser');
        $assignmentids = $DB->get_fieldset_select(
            'local_kbacorp_member_busrole', 'id', "userid {$usersql}", $userparams
        );
        if ($assignmentids) {
            [$assignmentsql, $assignmentparams] = $DB->get_in_or_equal(
                array_map('intval', $assignmentids), SQL_PARAMS_NAMED, 'extroleassignment'
            );
            $assignmentparams['assignmententity'] = 'business_role_assignment';
            $DB->delete_records_select('local_kbacorp_extmap',
                "localentity = :assignmententity AND localid {$assignmentsql}", $assignmentparams);
        }
        $userparams['userentity'] = 'user';
        $DB->delete_records_select('local_kbacorp_extmap',
            "localentity = :userentity AND localid {$usersql}", $userparams);
    }

    /** Delete subject audits and anonymise the same users when they only acted as audit staff. */
    private static function delete_or_anonymise_audit_data(array $userids): void {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return;
        }
        [$subjectsql, $subjectparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'auditsubject');
        $auditids = $DB->get_fieldset_select('local_kbacorp_audit', 'id', "userid {$subjectsql}", $subjectparams);
        if ($auditids) {
            [$auditsql, $auditparams] = $DB->get_in_or_equal(
                array_map('intval', $auditids), SQL_PARAMS_NAMED, 'privacyaudit'
            );
            $itemids = $DB->get_fieldset_select(
                'local_kbacorp_audit_item', 'id', "auditid {$auditsql}", $auditparams
            );
            if ($itemids) {
                [$itemsql, $itemparams] = $DB->get_in_or_equal(
                    array_map('intval', $itemids), SQL_PARAMS_NAMED, 'privacyaudititem'
                );
                $DB->delete_records_select('local_kbacorp_audit_ev', "audititemid {$itemsql}", $itemparams);
            }
            $DB->delete_records_select('local_kbacorp_audit_item', "auditid {$auditsql}", $auditparams);
        }
        $DB->delete_records_select('local_kbacorp_audit', "userid {$subjectsql}", $subjectparams);

        [$creatorsql, $creatorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'auditcreator');
        $DB->set_field_select('local_kbacorp_audit', 'createdby', 0, "createdby {$creatorsql}", $creatorparams);
        [$assessorsql, $assessorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'auditassessor');
        $DB->set_field_select('local_kbacorp_audit_item', 'assessorid', 0,
            "assessorid {$assessorsql}", $assessorparams);
        [$reviewersql, $reviewerparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'auditreviewer');
        $DB->set_field_select('local_kbacorp_audit_item', 'reviewerid', 0,
            "reviewerid {$reviewersql}", $reviewerparams);
    }

    /** Delete plans owned by subjects and anonymise users who only created plans. */
    private static function delete_or_anonymise_development_plan_data(array $userids): void {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids || !$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_devplan'))) {
            return;
        }
        [$subjectsql, $subjectparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'devsubject');
        $planids = $DB->get_fieldset_select('local_kbacorp_devplan', 'id', "userid {$subjectsql}", $subjectparams);
        if ($planids) {
            [$plansql, $planparams] = $DB->get_in_or_equal(array_map('intval', $planids), SQL_PARAMS_NAMED, 'privacydevplan');
            if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_devlog'))) {
                $DB->delete_records_select('local_kbacorp_devlog', "planid {$plansql}", $planparams);
            }
            $DB->delete_records_select('local_kbacorp_devitem', "planid {$plansql}", $planparams);
        }
        $DB->delete_records_select('local_kbacorp_devplan', "userid {$subjectsql}", $subjectparams);
        [$actorsql, $actorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'devactor');
        $DB->set_field_select('local_kbacorp_devplan', 'mentorid', 0, "mentorid {$actorsql}", $actorparams);
        $DB->set_field_select('local_kbacorp_devitem', 'mentorid', 0, "mentorid {$actorsql}", $actorparams);
        $DB->set_field_select('local_kbacorp_devitem', 'evidencesubmittedby', 0,
            "evidencesubmittedby {$actorsql}", $actorparams);
        $DB->set_field_select('local_kbacorp_devitem', 'reviewedby', 0, "reviewedby {$actorsql}", $actorparams);
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_devlog'))) {
            $DB->set_field_select('local_kbacorp_devlog', 'actorid', 0, "actorid {$actorsql}", $actorparams);
        }
        [$creatorsql, $creatorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'devcreator');
        $DB->set_field_select('local_kbacorp_devplan', 'createdby', 0, "createdby {$creatorsql}", $creatorparams);
        [$submittersql, $submitterparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'devsubmitter');
        $DB->set_field_select('local_kbacorp_devplan', 'submittedby', 0,
            "submittedby {$submittersql}", $submitterparams);
    }
}
