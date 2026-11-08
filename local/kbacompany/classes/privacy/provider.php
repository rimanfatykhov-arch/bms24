<?php
namespace local_kbacompany\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/** Privacy provider for BMS company data, including staffing, execution and communications. */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_kbacompany_compfw', [
            'createdby' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_roledna', [
            'createdby' => 'privacy:metadata:peoplefoundation',
            'modifiedby' => 'privacy:metadata:peoplefoundation',
            'purpose' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_rolednacomp', [
            'createdby' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_empcomp', [
            'userid' => 'privacy:metadata:peoplefoundation',
            'evidence' => 'privacy:metadata:peoplefoundation',
            'evidenceurl' => 'privacy:metadata:peoplefoundation',
            'recordedby' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_assess', [
            'userid' => 'privacy:metadata:peoplefoundation',
            'assessorid' => 'privacy:metadata:peoplefoundation',
            'decidedby' => 'privacy:metadata:peoplefoundation',
            'decisionnote' => 'privacy:metadata:peoplefoundation',
            'createdby' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_assessitem', [
            'evidence' => 'privacy:metadata:peoplefoundation',
            'evidenceurl' => 'privacy:metadata:peoplefoundation',
            'rationale' => 'privacy:metadata:peoplefoundation',
        ], 'privacy:metadata:peoplefoundation');
        $collection->add_database_table('local_kbacompany_360cycle', [
            'userid' => 'privacy:metadata:competency360',
            'createdby' => 'privacy:metadata:competency360',
        ], 'privacy:metadata:competency360');
        $collection->add_database_table('local_kbacompany_360resp', [
            'respondentid' => 'privacy:metadata:competency360',
        ], 'privacy:metadata:competency360');
        $collection->add_database_table('local_kbacompany_360score', [
            'comment' => 'privacy:metadata:competency360',
        ], 'privacy:metadata:competency360');
        $collection->add_database_table('local_kbacompany_devplan', [
            'userid' => 'privacy:metadata:developmentplan',
            'createdby' => 'privacy:metadata:developmentplan',
            'modifiedby' => 'privacy:metadata:developmentplan',
        ], 'privacy:metadata:developmentplan');
        $collection->add_database_table('local_kbacompany_devitem', [
            'responsibleid' => 'privacy:metadata:developmentplan',
            'courseid' => 'privacy:metadata:developmentplan',
            'description' => 'privacy:metadata:developmentplan',
            'learningurl' => 'privacy:metadata:developmentplan',
            'evidence' => 'privacy:metadata:developmentplan',
            'createdby' => 'privacy:metadata:developmentplan',
            'modifiedby' => 'privacy:metadata:developmentplan',
        ], 'privacy:metadata:developmentplan');
        $collection->add_database_table('local_kbacompany_functxfer', [
            'sourceuserid' => 'privacy:metadata:functiontransfer',
            'targetuserid' => 'privacy:metadata:functiontransfer',
            'authorityscope' => 'privacy:metadata:functiontransfer',
            'handovernote' => 'privacy:metadata:functiontransfer',
            'authorizedby' => 'privacy:metadata:functiontransfer',
            'acceptedby' => 'privacy:metadata:functiontransfer',
            'revokedby' => 'privacy:metadata:functiontransfer',
            'createdby' => 'privacy:metadata:functiontransfer',
        ], 'privacy:metadata:functiontransfer');
        $collection->add_database_table('local_kbacompany_cultitem', [
            'description' => 'privacy:metadata:culture',
            'createdby' => 'privacy:metadata:culture',
            'modifiedby' => 'privacy:metadata:culture',
        ], 'privacy:metadata:culture');
        $collection->add_database_table('local_kbacompany_cultscore', [
            'userid' => 'privacy:metadata:culture',
            'comment' => 'privacy:metadata:culture',
            'recordedby' => 'privacy:metadata:culture',
        ], 'privacy:metadata:culture');
        $collection->add_database_table('local_kbacompany_bplanrev', [
            'comment' => 'privacy:metadata:battleplanreview',
            'actorid' => 'privacy:metadata:battleplanreview',
        ], 'privacy:metadata:battleplanreview');
        $collection->add_database_table('local_kbacompany_bpcoord', [
            'summary' => 'privacy:metadata:battleplancoordination',
            'requestedchange' => 'privacy:metadata:battleplancoordination',
            'createdby' => 'privacy:metadata:battleplancoordination',
            'respondedby' => 'privacy:metadata:battleplancoordination',
            'response' => 'privacy:metadata:battleplancoordination',
        ], 'privacy:metadata:battleplancoordination');
        $collection->add_database_table('local_kbacompany_a3review', [
            'title' => 'privacy:metadata:a3review',
            'problemcontext' => 'privacy:metadata:a3review',
            'currentstate' => 'privacy:metadata:a3review',
            'targetstate' => 'privacy:metadata:a3review',
            'gapanalysis' => 'privacy:metadata:a3review',
            'causeanalysis' => 'privacy:metadata:a3review',
            'countermeasures' => 'privacy:metadata:a3review',
            'conclusion' => 'privacy:metadata:a3review',
            'standardization' => 'privacy:metadata:a3review',
            'ownerid' => 'privacy:metadata:a3review',
            'createdby' => 'privacy:metadata:a3review',
        ], 'privacy:metadata:a3review');
        $collection->add_database_table('local_kbacompany_a3std', [
            'proposal' => 'privacy:metadata:a3standardization',
            'createdby' => 'privacy:metadata:a3standardization',
            'confirmedby' => 'privacy:metadata:a3standardization',
        ], 'privacy:metadata:a3standardization');
        $collection->add_database_table('local_kbacompany_mcycle', [
            'ownerid' => 'privacy:metadata:mcycle',
            'curatorid' => 'privacy:metadata:mcycle',
            'priority' => 'privacy:metadata:mcycle',
            'outcome' => 'privacy:metadata:mcycle',
            'createdby' => 'privacy:metadata:mcycle',
        ], 'privacy:metadata:mcycle');
        $collection->add_database_table('local_kbacompany_mcyclecp', [
            'result' => 'privacy:metadata:mcycle',
            'createdby' => 'privacy:metadata:mcycle',
        ], 'privacy:metadata:mcycle');
        $collection->add_database_table('local_kbacompany_posassign', [
            'companyuuid' => 'privacy:metadata:posassign:companyuuid',
            'positionuuid' => 'privacy:metadata:posassign:positionuuid',
            'slotuuid' => 'privacy:metadata:posassign:slotuuid',
            'userid' => 'privacy:metadata:posassign:userid',
            'assignmenttype' => 'privacy:metadata:posassign:assignmenttype',
            'assignmentfte' => 'privacy:metadata:posassign:assignmentfte',
            'effectivefrom' => 'privacy:metadata:posassign:effectivefrom',
            'effectiveto' => 'privacy:metadata:posassign:effectiveto',
            'active' => 'privacy:metadata:posassign:active',
        ], 'privacy:metadata:posassign');
        $collection->add_database_table('local_kbacompany_staffplan', [
            'approvedby' => 'privacy:metadata:staffplan:approvedby',
        ], 'privacy:metadata:staffplan');
        $collection->add_database_table('local_kbacompany_statval', [
            'writerid' => 'privacy:metadata:statval:writerid',
            'reason' => 'privacy:metadata:statval:reason',
        ], 'privacy:metadata:statval');
        $collection->add_database_table('local_kbacompany_aclog', [
            'actorid' => 'privacy:metadata:aclog:actorid',
            'oldvalue' => 'privacy:metadata:aclog:snapshot',
            'newvalue' => 'privacy:metadata:aclog:snapshot',
        ], 'privacy:metadata:aclog');
        foreach (['wfassign' => ['userid'], 'wfapprove' => ['approvedby'],
                'wfwork' => ['userid', 'reviewerid'], 'wfevidence' => ['userid', 'body', 'url']] as $table => $fields) {
            $metadata = [];
            foreach ($fields as $field) { $metadata[$field] = 'privacy:metadata:workflow'; }
            $collection->add_database_table('local_kbacompany_' . $table, $metadata, 'privacy:metadata:workflow');
        }
        foreach (['deviation' => ['createdby', 'analysedby', 'confirmedby', 'closedby', 'evidence', 'cause', 'title', 'description', 'requirement', 'location', 'criterion', 'basisref'],
                'devlink' => ['createdby', 'reason'], 'devcheck' => ['createdby', 'evidence']] as $table => $fields) {
            $metadata = [];
            foreach ($fields as $field) { $metadata[$field] = 'privacy:metadata:deviations'; }
            $collection->add_database_table('local_kbacompany_' . $table, $metadata, 'privacy:metadata:deviations');
        }
        $collection->add_database_table('local_kbacompany_policy', [
            'actorid' => 'privacy:metadata:policy:actorid',
            'reason' => 'privacy:metadata:policy:reason',
        ], 'privacy:metadata:policy');
        $collection->add_database_table('local_kbacompany_process', [
            'createdby' => 'privacy:metadata:processmodel:actorid',
        ], 'privacy:metadata:processmodel');
        $collection->add_database_table('local_kbacompany_procmodel', [
            'createdby' => 'privacy:metadata:processmodel:actorid',
            'modifiedby' => 'privacy:metadata:processmodel:actorid',
        ], 'privacy:metadata:processmodel');
        $collection->add_database_table('local_kbacompany_function', [
            'createdby' => 'privacy:metadata:functionmodel:actorid',
            'modifiedby' => 'privacy:metadata:functionmodel:actorid',
        ], 'privacy:metadata:functionmodel');
        $collection->add_database_table('local_kbacompany_doc', [
            'createdby' => 'privacy:metadata:documentcontrol:actorid',
            'modifiedby' => 'privacy:metadata:documentcontrol:actorid',
            'changereason' => 'privacy:metadata:documentcontrol',
            'filemanifest' => 'privacy:metadata:documentfiles',
        ], 'privacy:metadata:documentcontrol');
        $collection->add_database_table('local_kbacompany_docreview', [
            'reviewerid' => 'privacy:metadata:documentcontrol:actorid',
            'comment' => 'privacy:metadata:documentcontrol',
        ], 'privacy:metadata:documentcontrol');
        $collection->add_database_table('local_kbacompany_docack', [
            'userid' => 'privacy:metadata:documentcontrol:actorid',
            'requiredby' => 'privacy:metadata:documentcontrol:actorid',
        ], 'privacy:metadata:documentcontrol');
        $collection->add_database_table('local_kbacompany_record', [
            'createdby' => 'privacy:metadata:documentcontrol:actorid',
            'payload' => 'privacy:metadata:documentcontrol',
        ], 'privacy:metadata:documentcontrol');
        $collection->add_database_table('local_kbacompany_poswork', [
            'createdby' => 'privacy:metadata:positionos:actorid',
            'modifiedby' => 'privacy:metadata:positionos:actorid',
            'changereason' => 'privacy:metadata:positionos',
        ], 'privacy:metadata:positionos');
        foreach (['market', 'segment', 'offering', 'bizmodel', 'philosophy', 'principle', 'marketev'] as $table) {
            $collection->add_database_table('local_kbacompany_' . $table, [
                'createdby' => 'privacy:metadata:marketmodel:actorid',
                'modifiedby' => 'privacy:metadata:marketmodel:actorid',
            ], 'privacy:metadata:marketmodel');
        }
        $collection->add_database_table('local_kbacompany_bmcitem', [
            'createdby' => 'privacy:metadata:marketmodel:actorid',
            'modifiedby' => 'privacy:metadata:marketmodel:actorid',
            'formulation' => 'privacy:metadata:marketmodel',
            'evidencenote' => 'privacy:metadata:marketmodel',
            'evidencesource' => 'privacy:metadata:marketmodel',
            'testcriteria' => 'privacy:metadata:marketmodel',
        ], 'privacy:metadata:marketmodel');
        $collection->add_database_table('local_kbacompany_bmccomment', [
            'authoruserid' => 'privacy:metadata:marketmodel:actorid',
            'comment' => 'privacy:metadata:marketmodel',
        ], 'privacy:metadata:marketmodel');
        foreach (['bmcval', 'bmcgap'] as $table) {
            $collection->add_database_table('local_kbacompany_' . $table, [
                'createdby' => 'privacy:metadata:marketmodel:actorid',
                'modifiedby' => 'privacy:metadata:marketmodel:actorid',
            ], 'privacy:metadata:marketmodel');
        }
        foreach (['ownerintent', 'strategy', 'stratgoal', 'goallink'] as $table) {
            $collection->add_database_table('local_kbacompany_' . $table, [
                'createdby' => 'privacy:metadata:strategymodel:actorid',
                'modifiedby' => 'privacy:metadata:strategymodel:actorid',
            ], 'privacy:metadata:strategymodel');
        }
        foreach (['finscenario', 'fincenter', 'finstmt'] as $table) {
            $collection->add_database_table('local_kbacompany_' . $table, [
                'createdby' => 'privacy:metadata:financialmodel:actorid',
                'modifiedby' => 'privacy:metadata:financialmodel:actorid',
            ], 'privacy:metadata:financialmodel');
        }
        $collection->add_database_table('local_kbacompany_finline', [
            'createdby' => 'privacy:metadata:financialmodel:actorid',
            'sourceinfo' => 'privacy:metadata:financialmodel',
        ], 'privacy:metadata:financialmodel');
        $collection->add_database_table('local_kbacompany_finbudget', [
            'createdby' => 'privacy:metadata:budgeting:actorid',
            'modifiedby' => 'privacy:metadata:budgeting:actorid',
            'submittedby' => 'privacy:metadata:budgeting:actorid',
            'approvedby' => 'privacy:metadata:budgeting:actorid',
            'decisionnote' => 'privacy:metadata:budgeting',
        ], 'privacy:metadata:budgeting');
        $collection->add_database_table('local_kbacompany_finbudline', [
            'createdby' => 'privacy:metadata:budgeting:actorid',
            'sourceinfo' => 'privacy:metadata:budgeting',
        ], 'privacy:metadata:budgeting');
        $collection->add_database_table('local_kbacompany_finbudfact', [
            'reportedby' => 'privacy:metadata:budgeting:actorid',
            'sourceinfo' => 'privacy:metadata:budgeting',
        ], 'privacy:metadata:budgeting');
        $collection->add_database_table('local_kbacompany_resgate', [
            'createdby' => 'privacy:metadata:resourcegate:actorid',
            'modifiedby' => 'privacy:metadata:resourcegate:actorid',
            'submittedby' => 'privacy:metadata:resourcegate:actorid',
            'decidedby' => 'privacy:metadata:resourcegate:actorid',
            'decisionnote' => 'privacy:metadata:resourcegate',
        ], 'privacy:metadata:resourcegate');
        $collection->add_database_table('local_kbacompany_resitem', [
            'createdby' => 'privacy:metadata:resourcegate:actorid',
            'available' => 'privacy:metadata:resourcegate',
            'gap' => 'privacy:metadata:resourcegate',
            'evidence' => 'privacy:metadata:resourcegate',
            'evidenceurl' => 'privacy:metadata:resourcegate',
        ], 'privacy:metadata:resourcegate');
        $collection->add_database_table('local_kbacompany_ownerframe', [
            'owneruserid' => 'privacy:metadata:ownermodel:userid',
            'createdby' => 'privacy:metadata:ownermodel:userid',
            'modifiedby' => 'privacy:metadata:ownermodel:userid',
            'payload' => 'privacy:metadata:ownermodel:content',
        ], 'privacy:metadata:ownermodel');
        $collection->add_database_table('local_kbacompany_ownerfb', [
            'authoruserid' => 'privacy:metadata:ownerfeedback:userid',
            'comment' => 'privacy:metadata:ownerfeedback:content',
        ], 'privacy:metadata:ownerfeedback');
        $collection->add_database_table('local_kbacompany_adminitem', [
            'createdby' => 'privacy:metadata:adminscale:actorid',
            'modifiedby' => 'privacy:metadata:adminscale:actorid',
            'description' => 'privacy:metadata:adminscale',
            'situation' => 'privacy:metadata:adminscale',
            'plangoal' => 'privacy:metadata:adminscale',
            'expectedresult' => 'privacy:metadata:adminscale',
        ], 'privacy:metadata:adminscale');
        $collection->add_database_table('local_kbacompany_task', [
            'assigneeid' => 'privacy:metadata:taskcenter:userid',
            'creatorid' => 'privacy:metadata:taskcenter:userid',
            'reviewerid' => 'privacy:metadata:taskcenter:userid',
            'observerid' => 'privacy:metadata:taskcenter:userid',
            'description' => 'privacy:metadata:taskcenter:content',
            'product' => 'privacy:metadata:taskcenter:content',
            'result' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        $collection->add_database_table('local_kbacompany_taskcheck', [
            'assigneeid' => 'privacy:metadata:taskcenter:userid',
            'itemtext' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        $collection->add_database_table('local_kbacompany_tasklog', [
            'actorid' => 'privacy:metadata:taskcenter:userid',
            'details' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:bmsfiles');
        $collection->add_database_table('local_kbacompany_taskrec', [
            'assigneeid' => 'privacy:metadata:taskcenter:userid',
            'creatorid' => 'privacy:metadata:taskcenter:userid',
            'reviewerid' => 'privacy:metadata:taskcenter:userid',
            'description' => 'privacy:metadata:taskcenter:content',
            'product' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        foreach (['statmeta', 'statbind', 'statnorm', 'statpolicy'] as $table) {
            $collection->add_database_table('local_kbacompany_' . $table, [
                'createdby' => 'privacy:metadata:indicators:actorid',
            ], 'privacy:metadata:indicators');
        }
        $collection->add_database_table('local_kbacompany_stateevt', [
            'actorid' => 'privacy:metadata:indicators:actorid',
            'comment' => 'privacy:metadata:indicators:content',
        ], 'privacy:metadata:indicators');
        $collection->add_database_table('local_kbacompany_tasklibcat', [
            'creatorid' => 'privacy:metadata:taskcenter:userid',
            'title' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        $collection->add_database_table('local_kbacompany_tasklibtpl', [
            'creatorid' => 'privacy:metadata:taskcenter:userid',
            'payload' => 'privacy:metadata:taskcenter:content',
        ], 'privacy:metadata:taskcenter');
        $collection->add_database_table('local_kbacompany_bplan', [
            'userid' => 'privacy:metadata:battleplan:userid',
            'reviewerid' => 'privacy:metadata:battleplan:userid',
            'observerid' => 'privacy:metadata:battleplan:userid',
            'createdby' => 'privacy:metadata:battleplan:userid',
            'approvedby' => 'privacy:metadata:battleplan:userid',
        ], 'privacy:metadata:battleplan');
        $collection->add_database_table('local_kbacompany_tasksrc', [
            'createdby' => 'privacy:metadata:taskproviders:createdby',
        ], 'privacy:metadata:taskproviders');
        $collection->add_database_table('local_kbacompany_commthread', [
            'createdby' => 'privacy:metadata:communication:userid',
            'title' => 'privacy:metadata:communication:content',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commpart', [
            'userid' => 'privacy:metadata:communication:userid',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commmsg', [
            'authorid' => 'privacy:metadata:communication:userid',
            'body' => 'privacy:metadata:communication:content',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commrev', [
            'actorid' => 'privacy:metadata:communication:userid',
            'oldbody' => 'privacy:metadata:communication:content',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commread', [
            'userid' => 'privacy:metadata:communication:userid',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commmention', [
            'userid' => 'privacy:metadata:communication:userid',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commnotif', [
            'userid' => 'privacy:metadata:communication:userid',
            'title' => 'privacy:metadata:communication:content',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_commattach', [
            'refvalue' => 'privacy:metadata:communication:content',
            'label' => 'privacy:metadata:communication:content',
        ], 'privacy:metadata:communication');
        $collection->add_database_table('local_kbacompany_meeting', [
            'organizerid' => 'privacy:metadata:meetings:userid',
            'chairid' => 'privacy:metadata:meetings:userid',
            'secretaryid' => 'privacy:metadata:meetings:userid',
            'closedby' => 'privacy:metadata:meetings:userid',
            'purpose' => 'privacy:metadata:meetings:content',
            'resourceuuid' => 'privacy:metadata:meetings:content',
            'onlineurl' => 'privacy:metadata:meetings:content',
            'minutes' => 'privacy:metadata:meetings:content',
            'result' => 'privacy:metadata:meetings:content',
        ], 'privacy:metadata:meetings');
        $collection->add_database_table('local_kbacompany_meetpart', [
            'userid' => 'privacy:metadata:meetings:userid',
        ], 'privacy:metadata:meetings');
        $collection->add_database_table('local_kbacompany_meetagenda', [
            'ownerid' => 'privacy:metadata:meetings:userid',
            'expectedproduct' => 'privacy:metadata:meetings:content',
            'notes' => 'privacy:metadata:meetings:content',
        ], 'privacy:metadata:meetings');
        $collection->add_database_table('local_kbacompany_decision', [
            'ownerid' => 'privacy:metadata:meetings:userid',
            'proposedby' => 'privacy:metadata:meetings:userid',
            'decidedby' => 'privacy:metadata:meetings:userid',
            'statement' => 'privacy:metadata:meetings:content',
            'rationale' => 'privacy:metadata:meetings:content',
            'expectedresult' => 'privacy:metadata:meetings:content',
        ], 'privacy:metadata:meetings');
        $collection->add_database_table('local_kbacompany_ofstpl', [
            'createdby' => 'privacy:metadata:legacyimport:actorid',
            'reviewer' => 'privacy:metadata:legacyimport:actorid',
            'sourcefilename' => 'privacy:metadata:legacyimport:content',
            'sourcedriveid' => 'privacy:metadata:legacyimport:content',
        ], 'privacy:metadata:legacyimport');
        $collection->add_database_table('local_kbacompany_ofstplver', [
            'createdby' => 'privacy:metadata:legacyimport:actorid',
            'payload' => 'privacy:metadata:legacyimport:content',
            'analysis' => 'privacy:metadata:legacyimport:content',
        ], 'privacy:metadata:legacyimport');
        $collection->add_database_table('local_kbacompany_company', [
            'createdby' => 'privacy:metadata:bmscompanysync:userid',
            'modifiedby' => 'privacy:metadata:bmscompanysync:userid',
        ], 'privacy:metadata:bmscompanysync');
        $collection->add_database_table('local_kbacompany_member', [
            'userid' => 'privacy:metadata:bmscompanysync:userid',
        ], 'privacy:metadata:bmscompanysync');
        $collection->add_database_table('local_kbacompany_syncout', [
            'userid' => 'privacy:metadata:bmscompanysync:userid',
            'payload' => 'privacy:metadata:bmscompanysync:content',
            'lasterror' => 'privacy:metadata:bmscompanysync:content',
        ], 'privacy:metadata:bmscompanysync');
        $collection->add_database_table('local_kbacompany_extmap', [
            'companyuuid' => 'privacy:metadata:bmscompanysync:content',
            'environment' => 'privacy:metadata:bmscompanysync:content',
            'externalmodel' => 'privacy:metadata:bmscompanysync:content',
            'namespacekey' => 'privacy:metadata:bmscompanysync:content',
            'mappinguuid' => 'privacy:metadata:bmscompanysync:content',
            'externalkey' => 'privacy:metadata:bmscompanysync:content',
            'provider' => 'privacy:metadata:bmscompanysync:content',
            'entitykind' => 'privacy:metadata:bmscompanysync:content',
            'localref' => 'privacy:metadata:bmsint:localref',
            'externalid' => 'privacy:metadata:bmscompanysync:content',
        ], 'privacy:metadata:bmscompanysync');
        $collection->add_database_table('local_kbacompany_cloudmap', [
            'createdby' => 'privacy:metadata:bmscompanysync:userid',
            'modifiedby' => 'privacy:metadata:bmscompanysync:userid',
            'workspaceuuid' => 'privacy:metadata:bmscompanysync:content',
            'snapshotrevision' => 'privacy:metadata:bmscompanysync:content',
        ], 'privacy:metadata:bmscompanysync');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = 'SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND (EXISTS (SELECT 1 FROM {local_kbacompany_posassign} pa WHERE pa.userid = :userid1)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_staffplan} sp WHERE sp.approvedby = :userid2)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_statval} sv WHERE sv.writerid = :userid3)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_aclog} al WHERE al.actorid = :userid4)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_wfassign} wa WHERE wa.userid = :userid5)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_wfapprove} wp WHERE wp.approvedby = :userid6)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_wfwork} ww WHERE ww.userid = :userid7 OR ww.reviewerid = :userid8)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_wfevidence} we WHERE we.userid = :userid9)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_deviation} dv
                         WHERE dv.createdby = :userid10 OR dv.analysedby = :userid11 OR dv.closedby = :userid12 OR dv.confirmedby = :userid701)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_devlink} dl WHERE dl.createdby = :userid13)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_devcheck} dc WHERE dc.createdby = :userid14)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_policy} po WHERE po.actorid = :userid15)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_process} pr WHERE pr.createdby = :userid16)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_procmodel} pm
                         WHERE pm.createdby = :userid17 OR pm.modifiedby = :userid18)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_function} fn
                         WHERE fn.createdby = :userid19 OR fn.modifiedby = :userid20)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_doc} d
                         WHERE d.createdby = :userid21 OR d.modifiedby = :userid22)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_docreview} dr WHERE dr.reviewerid = :userid23)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_docack} da
                         WHERE da.userid = :userid24 OR da.requiredby = :userid25)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_record} rec WHERE rec.createdby = :userid26)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_poswork} pw
                         WHERE pw.createdby = :userid27 OR pw.modifiedby = :userid28)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_market} mm WHERE mm.createdby = :userid29 OR mm.modifiedby = :userid30)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_segment} ms WHERE ms.createdby = :userid31 OR ms.modifiedby = :userid32)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_offering} mo WHERE mo.createdby = :userid33 OR mo.modifiedby = :userid34)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bizmodel} mb WHERE mb.createdby = :userid35 OR mb.modifiedby = :userid36)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_ownerframe} ofr WHERE ofr.owneruserid = :userid79 OR ofr.createdby = :userid80 OR ofr.modifiedby = :userid81)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_ownerintent} oi WHERE oi.createdby = :userid37 OR oi.modifiedby = :userid38)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_strategy} st WHERE st.createdby = :userid39 OR st.modifiedby = :userid40)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_stratgoal} sg WHERE sg.createdby = :userid41 OR sg.modifiedby = :userid42)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_goallink} gl WHERE gl.createdby = :userid43 OR gl.modifiedby = :userid44)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_adminitem} ai WHERE ai.createdby = :userid45 OR ai.modifiedby = :userid46)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_task} tk
                         WHERE tk.assigneeid = :userid47 OR tk.creatorid = :userid48 OR tk.reviewerid = :userid49 OR tk.observerid = :userid77)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_taskcheck} tc WHERE tc.assigneeid = :userid50)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_tasklog} tl WHERE tl.actorid = :userid51)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bplan} bp
                         WHERE bp.userid = :userid52 OR bp.reviewerid = :userid53 OR bp.createdby = :userid54 OR bp.approvedby = :userid55 OR bp.observerid = :userid78)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_commthread} ct WHERE ct.createdby = :userid56)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_commpart} cp WHERE cp.userid = :userid57)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_commmsg} cm WHERE cm.authorid = :userid58)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_commmention} mn WHERE mn.userid = :userid59)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_commnotif} cn WHERE cn.userid = :userid60)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_tasksrc} ts WHERE ts.createdby = :userid61)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_ofstpl} ot
                         WHERE ot.createdby = :userid62 OR ot.reviewer = :userid63)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_ofstplver} ov WHERE ov.createdby = :userid64)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_meeting} mt
                         WHERE mt.organizerid = :userid65 OR mt.chairid = :userid66 OR mt.secretaryid = :userid67 OR mt.closedby = :userid68)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_meetpart} mp WHERE mp.userid = :userid69)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_meetagenda} ma WHERE ma.ownerid = :userid70)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_decision} md
                         WHERE md.ownerid = :userid71 OR md.proposedby = :userid72 OR md.decidedby = :userid73)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_taskrec} tr
                         WHERE tr.assigneeid = :userid74 OR tr.creatorid = :userid75 OR tr.reviewerid = :userid76)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_tasklibcat} lc WHERE lc.creatorid = :userid82)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_tasklibtpl} lt WHERE lt.creatorid = :userid83)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_statmeta} im WHERE im.createdby = :userid84)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_statbind} ib WHERE ib.createdby = :userid85)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_statnorm} inr WHERE inr.createdby = :userid86)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_statpolicy} isp WHERE isp.createdby = :userid87)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_stateevt} ise WHERE ise.actorid = :userid88)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_member} bm WHERE bm.userid = :userid89)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_syncout} so WHERE so.userid = :userid90)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_company} bc WHERE bc.createdby = :userid91 OR bc.modifiedby = :userid92)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finscenario} fs
                         WHERE fs.createdby = :userid93 OR fs.modifiedby = :userid94)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_fincenter} fc
                         WHERE fc.createdby = :userid95 OR fc.modifiedby = :userid96)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finstmt} fst
                         WHERE fst.createdby = :userid97 OR fst.modifiedby = :userid98)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finline} fl WHERE fl.createdby = :userid99)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finbudget} fb
                         WHERE fb.createdby = :userid100 OR fb.modifiedby = :userid101 OR fb.submittedby = :userid102 OR fb.approvedby = :userid103)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finbudline} fbl WHERE fbl.createdby = :userid104)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_finbudfact} fbf WHERE fbf.reportedby = :userid105)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_resgate} rg
                         WHERE rg.createdby = :userid106 OR rg.modifiedby = :userid107 OR rg.submittedby = :userid108 OR rg.decidedby = :userid109)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_resitem} ri WHERE ri.createdby = :userid110)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_cloudmap} cm
                         WHERE cm.createdby = :userid111 OR cm.modifiedby = :userid112)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_ownerfb} ofb WHERE ofb.authoruserid = :userid113)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bmcitem} bi WHERE bi.createdby = :userid114 OR bi.modifiedby = :userid115)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bmccomment} bcmt WHERE bcmt.authoruserid = :userid116)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bmcval} bv WHERE bv.createdby = :userid117 OR bv.modifiedby = :userid118)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bmcgap} bg WHERE bg.createdby = :userid119 OR bg.modifiedby = :userid120)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_philosophy} ph WHERE ph.createdby = :userid121 OR ph.modifiedby = :userid122)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_principle} prn WHERE prn.createdby = :userid123 OR prn.modifiedby = :userid124)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_marketev} mev WHERE mev.createdby = :userid125 OR mev.modifiedby = :userid126)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_empcomp} ec WHERE ec.userid = :userid127 OR ec.recordedby = :userid128)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_assess} ca WHERE ca.userid = :userid129 OR ca.assessorid = :userid130 OR ca.decidedby = :userid131 OR ca.createdby = :userid132)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_360cycle} c360 WHERE c360.userid = :userid133 OR c360.createdby = :userid134)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_360resp} r360 WHERE r360.respondentid = :userid135)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_devplan} dp WHERE dp.userid = :userid136 OR dp.createdby = :userid137 OR dp.modifiedby = :userid138)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_devitem} di WHERE di.responsibleid = :userid139 OR di.createdby = :userid140 OR di.modifiedby = :userid141)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_functxfer} ft WHERE ft.sourceuserid = :userid142 OR ft.targetuserid = :userid143 OR ft.authorizedby = :userid144 OR ft.acceptedby = :userid145 OR ft.revokedby = :userid146 OR ft.createdby = :userid147)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_mcycle} mc WHERE mc.ownerid = :userid148 OR mc.curatorid = :userid149 OR mc.createdby = :userid150)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_mcyclecp} mcp WHERE mcp.createdby = :userid151)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bplanrev} br WHERE br.actorid = :userid152)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_bpcoord} bc WHERE bc.createdby = :userid153 OR bc.respondedby = :userid154)
                     OR EXISTS (SELECT 1 FROM {local_kbacompany_a3review} a3 WHERE a3.ownerid = :userid155 OR a3.createdby = :userid156))';
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_SYSTEM,
            'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid, 'userid4' => $userid,
            'userid5' => $userid, 'userid6' => $userid, 'userid7' => $userid, 'userid8' => $userid, 'userid9' => $userid,
            'userid701'=>$userid, 'userid10' => $userid, 'userid11' => $userid, 'userid12' => $userid, 'userid13' => $userid,
            'userid14' => $userid, 'userid15' => $userid, 'userid16' => $userid, 'userid17' => $userid, 'userid18' => $userid,
            'userid19' => $userid, 'userid20' => $userid, 'userid21' => $userid, 'userid22' => $userid,
            'userid23' => $userid, 'userid24' => $userid, 'userid25' => $userid, 'userid26' => $userid,
            'userid27' => $userid, 'userid28' => $userid, 'userid29' => $userid, 'userid30' => $userid,
            'userid31' => $userid, 'userid32' => $userid, 'userid33' => $userid, 'userid34' => $userid,
            'userid35' => $userid, 'userid36' => $userid, 'userid37' => $userid, 'userid38' => $userid,
            'userid39' => $userid, 'userid40' => $userid, 'userid41' => $userid, 'userid42' => $userid,
            'userid43' => $userid, 'userid44' => $userid, 'userid45' => $userid, 'userid46' => $userid,
            'userid47' => $userid, 'userid48' => $userid, 'userid49' => $userid, 'userid50' => $userid,
            'userid51' => $userid, 'userid52' => $userid, 'userid53' => $userid, 'userid54' => $userid,
            'userid55' => $userid, 'userid56' => $userid, 'userid57' => $userid, 'userid58' => $userid,
            'userid59' => $userid, 'userid60' => $userid, 'userid61' => $userid,
            'userid62' => $userid, 'userid63' => $userid, 'userid64' => $userid,
            'userid65' => $userid, 'userid66' => $userid, 'userid67' => $userid, 'userid68' => $userid,
            'userid69' => $userid, 'userid70' => $userid, 'userid71' => $userid, 'userid72' => $userid,
            'userid73' => $userid, 'userid74' => $userid, 'userid75' => $userid, 'userid76' => $userid,
            'userid77' => $userid, 'userid78' => $userid, 'userid79' => $userid, 'userid80' => $userid, 'userid81' => $userid,
            'userid82' => $userid, 'userid83' => $userid, 'userid84' => $userid,
            'userid85' => $userid, 'userid86' => $userid, 'userid87' => $userid, 'userid88' => $userid,
            'userid89' => $userid, 'userid90' => $userid, 'userid91' => $userid, 'userid92' => $userid,
            'userid93' => $userid, 'userid94' => $userid, 'userid95' => $userid, 'userid96' => $userid,
            'userid97' => $userid, 'userid98' => $userid, 'userid99' => $userid,
            'userid100' => $userid, 'userid101' => $userid, 'userid102' => $userid, 'userid103' => $userid,
            'userid104' => $userid, 'userid105' => $userid, 'userid106' => $userid, 'userid107' => $userid,
            'userid108' => $userid, 'userid109' => $userid, 'userid110' => $userid,
            'userid111' => $userid, 'userid112' => $userid, 'userid113' => $userid,
            'userid114' => $userid, 'userid115' => $userid, 'userid116' => $userid,
            'userid117' => $userid, 'userid118' => $userid, 'userid119' => $userid, 'userid120' => $userid,
            'userid121' => $userid, 'userid122' => $userid, 'userid123' => $userid, 'userid124' => $userid,
            'userid125' => $userid, 'userid126' => $userid, 'userid127' => $userid, 'userid128' => $userid,
            'userid129' => $userid, 'userid130' => $userid, 'userid131' => $userid, 'userid132' => $userid,
            'userid133' => $userid, 'userid134' => $userid, 'userid135' => $userid,
            'userid136' => $userid, 'userid137' => $userid, 'userid138' => $userid, 'userid139' => $userid,
            'userid140' => $userid, 'userid141' => $userid, 'userid142' => $userid, 'userid143' => $userid,
            'userid144' => $userid, 'userid145' => $userid, 'userid146' => $userid, 'userid147' => $userid,
            'userid148' => $userid, 'userid149' => $userid, 'userid150' => $userid, 'userid151' => $userid,
            'userid152' => $userid, 'userid153' => $userid, 'userid154' => $userid,
            'userid155' => $userid, 'userid156' => $userid]);
        $contextlist->add_from_sql("SELECT c.id FROM {context} c
            WHERE c.contextlevel = :dcflevel AND EXISTS (SELECT f.id FROM {files} f
                WHERE f.contextid = c.id AND f.component = :dcfcomponent AND f.filearea = :dcfarea AND f.userid = :dcfuser)",
            ['dcflevel'=>CONTEXT_SYSTEM,'dcfcomponent'=>'local_kbacompany','dcfarea'=>'document_revision','dcfuser'=>$userid]);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $systemcontext = \context_system::instance();
        if (!in_array($systemcontext->id, $contextlist->get_contextids())) {
            return;
        }
        $assignments = $DB->get_records('local_kbacompany_posassign', [
            'userid' => $contextlist->get_user()->id,
        ], 'timecreated ASC');
        $data = [];
        foreach ($assignments as $assignment) {
            $data[] = (object)[
                'company_uuid' => $assignment->companyuuid,
                'position_uuid' => $assignment->positionuuid,
                'slot_uuid' => $assignment->slotuuid,
                'assignment_type' => $assignment->assignmenttype,
                'assignment_fte' => (float)$assignment->assignmentfte,
                'effective_from' => transform::datetime($assignment->effectivefrom),
                'effective_to' => $assignment->effectiveto ? transform::datetime($assignment->effectiveto) : null,
                'active' => transform::yesno($assignment->active),
            ];
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:positionassignments', 'local_kbacompany')], (object)['assignments' => $data]);
        $approvals = $DB->get_records('local_kbacompany_staffplan',
            ['approvedby' => $contextlist->get_user()->id], 'timeapproved ASC');
        $approvaldata = [];
        foreach ($approvals as $plan) {
            $approvaldata[] = (object)['company_uuid' => $plan->companyuuid, 'plan_uuid' => $plan->uuid,
                'plan_name' => $plan->name, 'approved_at' => transform::datetime($plan->timeapproved)];
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:staffplanapprovals', 'local_kbacompany')], (object)['approvals' => $approvaldata]);
        $values = $DB->get_records('local_kbacompany_statval',
            ['writerid' => $contextlist->get_user()->id], 'timecreated ASC');
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:statistics', 'local_kbacompany')], (object)['values' => array_values($values)]);
        $logs = $DB->get_records('local_kbacompany_aclog',
            ['actorid' => $contextlist->get_user()->id], 'id ASC');
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:cascade', 'local_kbacompany')], (object)['entries' => array_values($logs)]);
        $execution = [];
        foreach (['wfassign' => 'userid', 'wfapprove' => 'approvedby', 'wfevidence' => 'userid'] as $table => $field) {
            $execution[$table] = array_values($DB->get_records('local_kbacompany_' . $table,
                [$field => $contextlist->get_user()->id]));
        }
        $execution['work'] = array_values($DB->get_records_select('local_kbacompany_wfwork',
            'userid = :u OR reviewerid = :r', ['u' => $contextlist->get_user()->id, 'r' => $contextlist->get_user()->id]));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:workflow', 'local_kbacompany')], (object)$execution);
        $deviations = ['records' => array_values($DB->get_records_select('local_kbacompany_deviation',
            'createdby = :c OR analysedby = :a OR closedby = :b OR confirmedby = :confirmed', ['confirmed'=>$contextlist->get_user()->id,'c' => $contextlist->get_user()->id,
                'a' => $contextlist->get_user()->id, 'b' => $contextlist->get_user()->id]))];
        foreach (['devlink', 'devcheck'] as $table) {
            $deviations[$table] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['createdby' => $contextlist->get_user()->id]));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:deviations', 'local_kbacompany')], (object)$deviations);
        $policies = array_values($DB->get_records('local_kbacompany_policy',
            ['actorid' => $contextlist->get_user()->id], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:policy', 'local_kbacompany')], (object)['revisions' => $policies]);
        $processdata = [
            'process_revisions' => array_values($DB->get_records('local_kbacompany_process',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'model_created' => array_values($DB->get_records('local_kbacompany_procmodel',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'model_modified' => array_values($DB->get_records('local_kbacompany_procmodel',
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:processmodel', 'local_kbacompany')], (object)$processdata);
        $functiondata = [
            'created' => array_values($DB->get_records('local_kbacompany_function',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_function',
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:functionmodel', 'local_kbacompany')], (object)$functiondata);
        $documentdata = [
            'created_documents' => array_values($DB->get_records('local_kbacompany_doc',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified_documents' => array_values($DB->get_records('local_kbacompany_doc',
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'reviews' => array_values($DB->get_records('local_kbacompany_docreview',
                ['reviewerid' => $contextlist->get_user()->id], 'timecreated ASC')),
            'acknowledgements' => array_values($DB->get_records('local_kbacompany_docack',
                ['userid' => $contextlist->get_user()->id], 'timecreated ASC')),
            'acknowledgements_required_by_user' => array_values($DB->get_records('local_kbacompany_docack',
                ['requiredby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'records' => array_values($DB->get_records('local_kbacompany_record',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:documentcontrol', 'local_kbacompany')], (object)$documentdata);
        self::document_files_for_privacy((int)$contextlist->get_user()->id, false);
        $positionos = [
            'created' => array_values($DB->get_records('local_kbacompany_poswork',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_poswork',
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:positionos', 'local_kbacompany')], (object)$positionos);
        $marketmodel = [];
        foreach (['market', 'segment', 'offering', 'bizmodel', 'philosophy', 'principle', 'marketev'] as $table) {
            $marketmodel[$table . '_created'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
            $marketmodel[$table . '_modified'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC'));
        }
        $marketmodel['bmcitem_created'] = array_values($DB->get_records('local_kbacompany_bmcitem',
            ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
        $marketmodel['bmcitem_modified'] = array_values($DB->get_records('local_kbacompany_bmcitem',
            ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC'));
        $marketmodel['bmc_comments'] = array_values($DB->get_records('local_kbacompany_bmccomment',
            ['authoruserid' => $contextlist->get_user()->id], 'timecreated ASC'));
        foreach (['bmcval', 'bmcgap'] as $table) {
            $marketmodel[$table . '_created'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
            $marketmodel[$table . '_modified'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC'));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:marketmodel', 'local_kbacompany')], (object)$marketmodel);
        $strategymodel = [];
        foreach (['ownerintent', 'strategy', 'stratgoal', 'goallink'] as $table) {
            $strategymodel[$table . '_created'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
            $strategymodel[$table . '_modified'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC'));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:strategymodel', 'local_kbacompany')], (object)$strategymodel);
        $financialmodel = [];
        foreach (['finscenario', 'fincenter', 'finstmt'] as $table) {
            $financialmodel[$table . '_created'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
            $financialmodel[$table . '_modified'] = array_values($DB->get_records('local_kbacompany_' . $table,
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC'));
        }
        $financialmodel['finline_created'] = array_values($DB->get_records('local_kbacompany_finline',
            ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:financialmodel', 'local_kbacompany')], (object)$financialmodel);
        $budgeting = [
            'created' => array_values($DB->get_records('local_kbacompany_finbudget', ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_finbudget', ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'submitted' => array_values($DB->get_records('local_kbacompany_finbudget', ['submittedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'approved_or_rejected' => array_values($DB->get_records('local_kbacompany_finbudget', ['approvedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'lines_created' => array_values($DB->get_records('local_kbacompany_finbudline', ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'facts_reported' => array_values($DB->get_records('local_kbacompany_finbudfact', ['reportedby' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:budgeting', 'local_kbacompany')], (object)$budgeting);
        $resourcegate = [
            'created' => array_values($DB->get_records('local_kbacompany_resgate', ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_resgate', ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'submitted' => array_values($DB->get_records('local_kbacompany_resgate', ['submittedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'decided' => array_values($DB->get_records('local_kbacompany_resgate', ['decidedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'items_created' => array_values($DB->get_records('local_kbacompany_resitem', ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:resourcegate', 'local_kbacompany')], (object)$resourcegate);
        $ownermodel = [
            'owned' => array_values($DB->get_records('local_kbacompany_ownerframe', ['owneruserid' => $contextlist->get_user()->id], 'timecreated ASC')),
            'created' => array_values($DB->get_records('local_kbacompany_ownerframe', ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_ownerframe', ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
            'feedback' => array_values($DB->get_records('local_kbacompany_ownerfb', ['authoruserid' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:ownermodel', 'local_kbacompany')], (object)$ownermodel);
        $adminscale = [
            'created' => array_values($DB->get_records('local_kbacompany_adminitem',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'modified' => array_values($DB->get_records('local_kbacompany_adminitem',
                ['modifiedby' => $contextlist->get_user()->id], 'timemodified ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:adminscale', 'local_kbacompany')], (object)$adminscale);
        $indicators = [
            'passports' => array_values($DB->get_records('local_kbacompany_statmeta',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'bindings' => array_values($DB->get_records('local_kbacompany_statbind',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'norms' => array_values($DB->get_records('local_kbacompany_statnorm',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'condition_policies' => array_values($DB->get_records('local_kbacompany_statpolicy',
                ['createdby' => $contextlist->get_user()->id], 'timecreated ASC')),
            'condition_events' => array_values($DB->get_records('local_kbacompany_stateevt',
                ['actorid' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:indicators', 'local_kbacompany')], (object)$indicators);
        $taskcenter = [
            'tasks' => array_values($DB->get_records_select('local_kbacompany_task',
                'assigneeid = :a OR creatorid = :c OR reviewerid = :r OR observerid = :o',
                ['a' => $contextlist->get_user()->id, 'c' => $contextlist->get_user()->id, 'r' => $contextlist->get_user()->id,
                    'o' => $contextlist->get_user()->id])),
            'checklist' => array_values($DB->get_records('local_kbacompany_taskcheck',
                ['assigneeid' => $contextlist->get_user()->id])),
            'history' => array_values($DB->get_records('local_kbacompany_tasklog',
                ['actorid' => $contextlist->get_user()->id], 'timecreated ASC')),
            'recurring_definitions' => array_values($DB->get_records_select('local_kbacompany_taskrec',
                'assigneeid = :ra OR creatorid = :rc OR reviewerid = :rr',
                ['ra' => $contextlist->get_user()->id, 'rc' => $contextlist->get_user()->id,
                    'rr' => $contextlist->get_user()->id], 'timecreated ASC')),
            'library_categories' => array_values($DB->get_records('local_kbacompany_tasklibcat',
                ['creatorid' => $contextlist->get_user()->id], 'timecreated ASC')),
            'library_templates' => array_values($DB->get_records('local_kbacompany_tasklibtpl',
                ['creatorid' => $contextlist->get_user()->id], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:taskcenter', 'local_kbacompany')], (object)$taskcenter);
        foreach ($taskcenter['tasks'] as $taskrow) {
            writer::with_context($systemcontext)->export_area_files(
                [get_string('privacy:metadata:taskcenter', 'local_kbacompany'),
                    get_string('taskcenter_attachments', 'local_kbacompany'), (string)$taskrow->uuid],
                'local_kbacompany', 'task_attachment', (int)$taskrow->id);
            writer::with_context($systemcontext)->export_area_files(
                [get_string('privacy:metadata:taskcenter', 'local_kbacompany'),
                    get_string('taskcenter_resultattachments', 'local_kbacompany'), (string)$taskrow->uuid],
                'local_kbacompany', 'task_result', (int)$taskrow->id);
        }
        $taskproviders = array_values($DB->get_records('local_kbacompany_tasksrc',
            ['createdby' => $contextlist->get_user()->id], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:taskproviders', 'local_kbacompany')], (object)['bindings' => $taskproviders]);
        $battleplans = array_values($DB->get_records_select('local_kbacompany_bplan',
            'userid = :u OR reviewerid = :r OR createdby = :c OR approvedby = :a OR observerid = :o',
            ['u' => $contextlist->get_user()->id, 'r' => $contextlist->get_user()->id,
                'c' => $contextlist->get_user()->id, 'a' => $contextlist->get_user()->id,
                'o' => $contextlist->get_user()->id]));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:battleplan', 'local_kbacompany')], (object)['plans' => $battleplans]);
        $userid = (int)$contextlist->get_user()->id;
        $communication = [
            'created_threads' => array_values($DB->get_records('local_kbacompany_commthread', ['createdby' => $userid], 'timecreated ASC')),
            'participation' => array_values($DB->get_records('local_kbacompany_commpart', ['userid' => $userid], 'timecreated ASC')),
            'messages' => array_values($DB->get_records('local_kbacompany_commmsg', ['authorid' => $userid], 'timecreated ASC')),
            'revisions' => array_values($DB->get_records('local_kbacompany_commrev', ['actorid' => $userid], 'timecreated ASC')),
            'read_state' => array_values($DB->get_records('local_kbacompany_commread', ['userid' => $userid], 'timeread ASC')),
            'mentions' => array_values($DB->get_records('local_kbacompany_commmention', ['userid' => $userid], 'timecreated ASC')),
            'notifications' => array_values($DB->get_records('local_kbacompany_commnotif', ['userid' => $userid], 'timecreated ASC')),
            'attachments' => [],
        ];
        $messageuuids = array_map(static function($m): string { return (string)$m->uuid; }, $communication['messages']);
        if ($messageuuids) {
            $communication['attachments'] = array_values($DB->get_records_list('local_kbacompany_commattach',
                'messageuuid', $messageuuids, 'timecreated ASC'));
        }
        foreach ($communication['messages'] as $communicationmessage) {
            writer::with_context($systemcontext)->export_area_files(
                [get_string('privacy:metadata:communication', 'local_kbacompany'), 'files', (string)$communicationmessage->uuid],
                'local_kbacompany', 'communication_attachment', (int)$communicationmessage->id);
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:communication', 'local_kbacompany')], (object)$communication);
        $userid = (int)$contextlist->get_user()->id;
        $meetings = [
            'governed_meetings' => array_values($DB->get_records_select('local_kbacompany_meeting',
                'organizerid = :o OR chairid = :c OR secretaryid = :s OR closedby = :x',
                ['o' => $userid, 'c' => $userid, 's' => $userid, 'x' => $userid], 'timecreated ASC')),
            'participation' => array_values($DB->get_records('local_kbacompany_meetpart', ['userid' => $userid], 'timecreated ASC')),
            'agenda_ownership' => array_values($DB->get_records('local_kbacompany_meetagenda', ['ownerid' => $userid], 'timecreated ASC')),
            'decisions' => array_values($DB->get_records_select('local_kbacompany_decision',
                'ownerid = :o OR proposedby = :p OR decidedby = :d',
                ['o' => $userid, 'p' => $userid, 'd' => $userid], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:meetings', 'local_kbacompany')], (object)$meetings);
        $userid = (int)$contextlist->get_user()->id;
        $legacytemplates = [
            'created_templates' => array_values($DB->get_records('local_kbacompany_ofstpl', ['createdby' => $userid], 'timecreated ASC')),
            'reviewed_templates' => array_values($DB->get_records('local_kbacompany_ofstpl', ['reviewer' => $userid], 'timemodified ASC')),
            'created_versions' => array_values($DB->get_records('local_kbacompany_ofstplver', ['createdby' => $userid], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:metadata:legacyimport', 'local_kbacompany')], (object)$legacytemplates);
        $bmscompanysync = [
            'memberships' => array_values($DB->get_records('local_kbacompany_member', ['userid' => $userid], 'timecreated ASC')),
            'external_identities' => array_values($DB->get_records('local_kbacompany_extmap',
                ['entitykind' => 'user', 'localref' => (string)$userid], 'timecreated ASC')),
            'outbound_commands' => array_values($DB->get_records('local_kbacompany_syncout', ['userid' => $userid], 'timecreated ASC')),
            'cloud_workspace_mappings_created' => array_values($DB->get_records('local_kbacompany_cloudmap',
                ['createdby' => $userid], 'timecreated ASC')),
            'cloud_workspace_mappings_modified' => array_values($DB->get_records('local_kbacompany_cloudmap',
                ['modifiedby' => $userid], 'timemodified ASC')),
        ];
        $employeecompetencies = [
            'profile' => array_values($DB->get_records('local_kbacompany_empcomp',
                ['userid' => $contextlist->get_user()->id], 'recordedat ASC')),
            'recorded' => array_values($DB->get_records('local_kbacompany_empcomp',
                ['recordedby' => $contextlist->get_user()->id], 'recordedat ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:employeecompetencies', 'local_kbacompany')], (object)$employeecompetencies);
        $assessmentids = $DB->get_fieldset_select('local_kbacompany_assess', 'uuid',
            'userid = :userid OR assessorid = :assessor OR decidedby = :decider OR createdby = :creator',
            ['userid' => $userid, 'assessor' => $userid, 'decider' => $userid, 'creator' => $userid]);
        $assessmentdata = [
            'sessions_as_employee' => array_values($DB->get_records('local_kbacompany_assess', ['userid' => $userid], 'timecreated ASC')),
            'sessions_as_actor' => array_values($DB->get_records_select('local_kbacompany_assess',
                'assessorid = :a OR decidedby = :d OR createdby = :c', ['a' => $userid, 'd' => $userid, 'c' => $userid], 'timecreated ASC')),
            'items' => [],
        ];
        if ($assessmentids) {
            list($insql, $inparams) = $DB->get_in_or_equal($assessmentids, SQL_PARAMS_NAMED, 'assessment');
            $assessmentdata['items'] = array_values($DB->get_records_select('local_kbacompany_assessitem', 'assessmentuuid ' . $insql, $inparams, 'timecreated ASC'));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:competencyassessments', 'local_kbacompany')], (object)$assessmentdata);
        $cycleids = $DB->get_fieldset_select('local_kbacompany_360cycle', 'uuid',
            'userid = :userid OR createdby = :createdby', ['userid' => $userid, 'createdby' => $userid]);
        $respondentcycles = $DB->get_fieldset_select('local_kbacompany_360resp', 'cycleuuid', 'respondentid = :respondentid',
            ['respondentid' => $userid]);
        $allcycles = array_values(array_unique(array_merge($cycleids, $respondentcycles)));
        $feedback360 = [
            'cycles_as_subject_or_creator' => array_values($DB->get_records_select('local_kbacompany_360cycle',
                'userid = :u OR createdby = :c', ['u' => $userid, 'c' => $userid], 'timecreated ASC')),
            'respondent_assignments' => array_values($DB->get_records('local_kbacompany_360resp', ['respondentid' => $userid], 'timecreated ASC')),
            'scores' => [],
        ];
        if ($allcycles) {
            $responseids = $DB->get_fieldset_select('local_kbacompany_360resp', 'id', 'respondentid = :rid', ['rid' => $userid]);
            if ($responseids) {
                list($rsql, $rparams) = $DB->get_in_or_equal($responseids, SQL_PARAMS_NAMED, 'r360');
                $feedback360['scores'] = array_values($DB->get_records_select('local_kbacompany_360score', 'responseid ' . $rsql, $rparams, 'timecreated ASC'));
            }
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:competency360', 'local_kbacompany')], (object)$feedback360);
        $devplanuuids = $DB->get_fieldset_select('local_kbacompany_devplan', 'uuid',
            'userid = :u OR createdby = :c OR modifiedby = :m', ['u'=>$userid,'c'=>$userid,'m'=>$userid]);
        $development = [
            'plans' => array_values($DB->get_records_select('local_kbacompany_devplan',
                'userid = :u OR createdby = :c OR modifiedby = :m', ['u'=>$userid,'c'=>$userid,'m'=>$userid], 'timecreated ASC')),
            'items' => array_values($DB->get_records_select('local_kbacompany_devitem',
                'responsibleid = :r OR createdby = :c OR modifiedby = :m', ['r'=>$userid,'c'=>$userid,'m'=>$userid], 'timecreated ASC')),
        ];
        if ($devplanuuids) {
            list($dsql, $dparams) = $DB->get_in_or_equal($devplanuuids, SQL_PARAMS_NAMED, 'devplanexport');
            $development['items_in_subject_plans'] = array_values($DB->get_records_select('local_kbacompany_devitem', 'planuuid ' . $dsql, $dparams, 'timecreated ASC'));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:developmentplan', 'local_kbacompany')], (object)$development);
        $functiontransfers = array_values($DB->get_records_select('local_kbacompany_functxfer',
            'sourceuserid = :s OR targetuserid = :t OR authorizedby = :a OR acceptedby = :ac OR revokedby = :r OR createdby = :c',
            ['s'=>$userid,'t'=>$userid,'a'=>$userid,'ac'=>$userid,'r'=>$userid,'c'=>$userid], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:functiontransfer', 'local_kbacompany')], (object)['transfers' => $functiontransfers]);
        $culture = [
            'scores' => array_values($DB->get_records_select('local_kbacompany_cultscore',
                'userid = :u OR recordedby = :r', ['u'=>$userid,'r'=>$userid], 'timecreated ASC')),
            'items_created_or_modified' => array_values($DB->get_records_select('local_kbacompany_cultitem',
                'createdby = :c OR modifiedby = :m', ['c'=>$userid,'m'=>$userid], 'timecreated ASC')),
        ];
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:culture', 'local_kbacompany')], (object)$culture);
        $mcycleids = $DB->get_fieldset_select('local_kbacompany_mcycle', 'uuid',
            'ownerid = :o OR curatorid = :c OR createdby = :b', ['o'=>$userid,'c'=>$userid,'b'=>$userid]);
        $mcycledata = [
            'cycles' => array_values($DB->get_records_select('local_kbacompany_mcycle',
                'ownerid = :o OR curatorid = :c OR createdby = :b', ['o'=>$userid,'c'=>$userid,'b'=>$userid], 'timecreated ASC')),
            'checkpoints_created' => array_values($DB->get_records('local_kbacompany_mcyclecp', ['createdby'=>$userid], 'timecreated ASC')),
            'checkpoints_in_cycles' => [],
        ];
        if ($mcycleids) {
            list($mcsql, $mcparams) = $DB->get_in_or_equal($mcycleids, SQL_PARAMS_NAMED, 'mcycleexport');
            $mcycledata['checkpoints_in_cycles'] = array_values($DB->get_records_select('local_kbacompany_mcyclecp', 'cycleuuid ' . $mcsql, $mcparams, 'sequence ASC'));
        }
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:mcycle', 'local_kbacompany')], (object)$mcycledata);
        $battleplanreviews = array_values($DB->get_records('local_kbacompany_bplanrev',
            ['actorid'=>$userid], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:battleplanreview', 'local_kbacompany')], (object)['reviews'=>$battleplanreviews]);
        $battleplancoordination = array_values($DB->get_records_select('local_kbacompany_bpcoord',
            'createdby = :coordcreator OR respondedby = :coordresponder',
            ['coordcreator'=>$userid, 'coordresponder'=>$userid], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:battleplancoordination', 'local_kbacompany')],
            (object)['coordination'=>$battleplancoordination]);
        $a3reviews = array_values($DB->get_records_select('local_kbacompany_a3review',
            'ownerid = :a3owner OR createdby = :a3creator',
            ['a3owner'=>$userid, 'a3creator'=>$userid], 'timecreated ASC'));
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:a3review', 'local_kbacompany')], (object)['reviews'=>$a3reviews]);
        writer::with_context($systemcontext)->export_data(
            [get_string('privacy:path:bmscompanysync', 'local_kbacompany')], (object)$bmscompanysync);
    }

    /** Approved privacy operations discover user-owned scopes, then process files inside each company.
     * This is NOT a business endpoint and does not grant a user's current membership any extra access.
     * Deletion may remove a released file; it must never silently substitute a different file.
     */
    private static function document_files_for_privacy(?int $userid, bool $delete): void {
        global $DB;
        $context = \context_system::instance();
        $params = ['ctx'=>$context->id,'component'=>'local_kbacompany','area'=>'document_revision'];
        $where = 'f.contextid = :ctx AND f.component = :component AND f.filearea = :area';
        if ($userid !== null) { $where .= ' AND f.userid = :uid'; $params['uid'] = $userid; }
        // Scope discovery returns identities only, already limited to the approved data subject.
        $companies = $DB->get_records_sql("SELECT DISTINCT d.companyuuid FROM {local_kbacompany_doc} d
            JOIN {files} f ON f.itemid = d.id WHERE $where", $params);
        $fs = get_file_storage();
        foreach ($companies as $company) {
            $scoped = $params + ['company'=>$company->companyuuid];
            $rows = $DB->get_recordset_sql("SELECT f.id,d.id AS documentid,d.uuid,d.revision,d.filemanifest,
                f.filename,f.filepath FROM {files} f JOIN {local_kbacompany_doc} d ON d.id = f.itemid
                WHERE $where AND d.companyuuid = :company ORDER BY f.id", $scoped);
            try {
                foreach ($rows as $row) {
                    $file = $fs->get_file_by_id((int)$row->id);
                    if (!$file) { continue; }
                    if (!$delete) {
                        writer::with_context($context)->export_file([
                            get_string('privacy:metadata:documentcontrol','local_kbacompany'),
                            $company->companyuuid, $row->uuid, (string)$row->revision,
                            $file->get_contenthash()], $file);
                    } else {
                        // Erase the manifest only when deleting its current primary file.
                        $m = json_decode((string)$row->filemanifest, true);
                        if (is_array($m) && ($m['filename'] ?? '') === $row->filename &&
                                $row->filepath === '/' . $company->companyuuid . '/' . ($m['sha256'] ?? '') . '/') {
                            $DB->set_field('local_kbacompany_doc', 'filemanifest', null,
                                ['companyuuid'=>$company->companyuuid, 'id'=>$row->documentid]);
                        }
                        $file->delete();
                    }
                }
            } finally { $rows->close(); }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_SYSTEM) {
            $DB->delete_records('local_kbacompany_commrev');
            self::document_files_for_privacy(null, true);
            self::forget_workflow(null);
            $DB->set_field_select('local_kbacompany_aclog', 'oldvalue', null, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_aclog', 'newvalue', null, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_aclog', 'actorid', 0, 'actorid <> 0');
            $DB->delete_records('local_kbacompany_posassign');
            $DB->delete_records('local_kbacompany_empcomp');
            $DB->delete_records('local_kbacompany_assessitem');
            $DB->delete_records('local_kbacompany_assess');
            $DB->delete_records('local_kbacompany_360score');
            $DB->delete_records('local_kbacompany_360resp');
            $DB->delete_records('local_kbacompany_360cycle');
            $DB->delete_records('local_kbacompany_devitem');
            $DB->delete_records('local_kbacompany_devplan');
            $DB->delete_records('local_kbacompany_functxfer');
            $DB->delete_records('local_kbacompany_cultscore');
            $DB->delete_records('local_kbacompany_cultitem');
            $DB->delete_records('local_kbacompany_mcyclecp');
            $DB->delete_records('local_kbacompany_mcycle');
            $DB->delete_records('local_kbacompany_member');
            $DB->delete_records('local_kbacompany_syncout');
            $DB->delete_records_select('local_kbacompany_extmap', 'entitykind = :entitykind', ['entitykind' => 'user']);
            $DB->set_field_select('local_kbacompany_company', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_company', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_cloudmap', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_cloudmap', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_staffplan', 'approvedby', 0, 'approvedby <> 0');
            $DB->set_field_select('local_kbacompany_statval', 'reason', '', 'writerid <> 0');
            $DB->set_field_select('local_kbacompany_statval', 'writerid', 0, 'writerid <> 0');
            $DB->set_field_select('local_kbacompany_policy', 'reason', '', 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_policy', 'actorid', 0, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_process', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_procmodel', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_procmodel', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_function', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_function', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_doc', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_doc', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_doc', 'changereason', '', 'createdby = 0 AND modifiedby = 0');
            $DB->delete_records('local_kbacompany_docreview');
            $DB->delete_records('local_kbacompany_docack');
            $DB->set_field_select('local_kbacompany_record', 'payload', '', 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_record', 'payloadhash', hash('sha256', ''), 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_record', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_poswork', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_poswork', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_poswork', 'changereason', '', 'createdby = 0 AND modifiedby = 0');
            foreach (['market','segment','offering','bizmodel','philosophy','principle','marketev','ownerintent','strategy','stratgoal','goallink','finscenario','fincenter','finstmt'] as $table) {
                $DB->set_field_select('local_kbacompany_' . $table, 'createdby', 0, 'createdby <> 0');
                $DB->set_field_select('local_kbacompany_' . $table, 'modifiedby', 0, 'modifiedby <> 0');
            }
            $DB->set_field_select('local_kbacompany_bmcitem', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_bmcitem', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_bmccomment', 'comment', '', 'authoruserid <> 0');
            $DB->set_field_select('local_kbacompany_bmccomment', 'authoruserid', 0, 'authoruserid <> 0');
            foreach (['bmcval', 'bmcgap'] as $table) {
                $DB->set_field_select('local_kbacompany_' . $table, 'createdby', 0, 'createdby <> 0');
                $DB->set_field_select('local_kbacompany_' . $table, 'modifiedby', 0, 'modifiedby <> 0');
            }
            $DB->set_field_select('local_kbacompany_finline', 'createdby', 0, 'createdby <> 0');
            foreach (['createdby', 'modifiedby', 'submittedby', 'approvedby'] as $field) {
                $DB->set_field_select('local_kbacompany_finbudget', $field, 0, $field . ' <> 0');
            }
            $DB->set_field_select('local_kbacompany_finbudline', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_finbudfact', 'reportedby', 0, 'reportedby <> 0');
            $DB->set_field_select('local_kbacompany_resgate', 'decisionnote', '', 'decidedby <> 0');
            foreach (['createdby', 'modifiedby', 'submittedby', 'decidedby'] as $field) {
                $DB->set_field_select('local_kbacompany_resgate', $field, 0, $field . ' <> 0');
            }
            foreach (['available', 'gap', 'evidence', 'evidenceurl'] as $field) {
                $DB->set_field_select('local_kbacompany_resitem', $field, '', 'createdby <> 0');
            }
            $DB->set_field_select('local_kbacompany_resitem', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_ownerframe', 'owneruserid', 0, 'owneruserid <> 0');
            $DB->set_field_select('local_kbacompany_ownerframe', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_ownerframe', 'modifiedby', 0, 'modifiedby <> 0');
            $DB->set_field_select('local_kbacompany_ownerframe', 'payload', '{}', 'payload <> :emptyownerpayload', ['emptyownerpayload' => '{}']);
            $DB->set_field_select('local_kbacompany_ownerfb', 'comment', '', 'authoruserid <> 0');
            $DB->set_field_select('local_kbacompany_ownerfb', 'authoruserid', 0, 'authoruserid <> 0');
            $DB->set_field_select('local_kbacompany_adminitem', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_adminitem', 'modifiedby', 0, 'modifiedby <> 0');
            get_file_storage()->delete_area_files($context->id, 'local_kbacompany', 'task_attachment');
            get_file_storage()->delete_area_files($context->id, 'local_kbacompany', 'task_result');
            $DB->set_field_select('local_kbacompany_bplanrev', 'comment', '', 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_bplanrev', 'actorid', 0, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_bpcoord', 'summary', '', 'createdby <> 0 OR respondedby <> 0');
            $DB->set_field_select('local_kbacompany_bpcoord', 'requestedchange', '', 'createdby <> 0 OR respondedby <> 0');
            $DB->set_field_select('local_kbacompany_bpcoord', 'response', '', 'createdby <> 0 OR respondedby <> 0');
            $DB->set_field_select('local_kbacompany_bpcoord', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_bpcoord', 'respondedby', 0, 'respondedby <> 0');
            foreach (['title','problemcontext','currentstate','targetstate','gapanalysis','causeanalysis','countermeasures','conclusion','standardization'] as $field) {
                $DB->set_field_select('local_kbacompany_a3review', $field, '', 'ownerid <> 0 OR createdby <> 0');
            }
            $DB->set_field_select('local_kbacompany_a3review', 'ownerid', 0, 'ownerid <> 0');
            $DB->set_field_select('local_kbacompany_a3review', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_task', 'description', '', 'assigneeid <> 0 OR creatorid <> 0 OR reviewerid <> 0 OR observerid <> 0');
            $DB->set_field_select('local_kbacompany_task', 'result', '', 'assigneeid <> 0 OR creatorid <> 0 OR reviewerid <> 0 OR observerid <> 0');
            $DB->set_field_select('local_kbacompany_task', 'assigneeid', 0, 'assigneeid <> 0');
            $DB->set_field_select('local_kbacompany_task', 'creatorid', 0, 'creatorid <> 0');
            $DB->set_field_select('local_kbacompany_task', 'reviewerid', 0, 'reviewerid <> 0');
            $DB->set_field_select('local_kbacompany_task', 'observerid', 0, 'observerid <> 0');
            $DB->set_field_select('local_kbacompany_taskcheck', 'itemtext', '', 'assigneeid <> 0');
            $DB->set_field_select('local_kbacompany_taskcheck', 'assigneeid', 0, 'assigneeid <> 0');
            $DB->set_field_select('local_kbacompany_tasklog', 'details', null, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_tasklog', 'actorid', 0, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_taskrec', 'description', '', 'creatorid <> 0');
            $DB->set_field_select('local_kbacompany_taskrec', 'assigneeid', 0, 'assigneeid <> 0');
            $DB->set_field_select('local_kbacompany_taskrec', 'creatorid', 0, 'creatorid <> 0');
            $DB->set_field_select('local_kbacompany_taskrec', 'reviewerid', 0, 'reviewerid <> 0');
            foreach (['statmeta', 'statbind', 'statnorm', 'statpolicy'] as $table) {
                $DB->set_field_select('local_kbacompany_' . $table, 'createdby', 0, 'createdby <> 0');
            }
            $DB->set_field_select('local_kbacompany_stateevt', 'comment', '', 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_stateevt', 'actorid', 0, 'actorid <> 0');
            $DB->set_field_select('local_kbacompany_tasklibcat', 'creatorid', 0, 'creatorid <> 0');
            $DB->set_field_select('local_kbacompany_tasklibtpl', 'creatorid', 0, 'creatorid <> 0');
            foreach (['userid', 'reviewerid', 'observerid', 'createdby', 'approvedby'] as $field) {
                $DB->set_field_select('local_kbacompany_bplan', $field, 0, $field . ' <> 0');
            }
            $DB->set_field_select('local_kbacompany_tasksrc', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_ofstpl', 'createdby', 0, 'createdby <> 0');
            $DB->set_field_select('local_kbacompany_ofstpl', 'reviewer', 0, 'reviewer <> 0');
            $DB->set_field_select('local_kbacompany_ofstplver', 'createdby', 0, 'createdby <> 0');
            foreach (['decision', 'meetagenda', 'meetpart', 'meeting'] as $table) {
                $DB->delete_records('local_kbacompany_' . $table);
            }
            get_file_storage()->delete_area_files($context->id, 'local_kbacompany', 'communication_attachment');
            foreach (['commattach', 'commmention', 'commnotif', 'commread', 'commmsg', 'commpart', 'commthread'] as $table) {
                $DB->delete_records('local_kbacompany_' . $table);
            }
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $systemcontext = \context_system::instance();
        if (in_array($systemcontext->id, $contextlist->get_contextids())) {
            self::document_files_for_privacy((int)$contextlist->get_user()->id, true);
            self::forget_workflow((int)$contextlist->get_user()->id);
            $where = ['actorid' => $contextlist->get_user()->id];
            $DB->set_field('local_kbacompany_aclog', 'oldvalue', null, $where);
            $DB->set_field('local_kbacompany_aclog', 'newvalue', null, $where);
            $DB->set_field('local_kbacompany_aclog', 'actorid', 0, $where);
            $DB->delete_records('local_kbacompany_posassign', ['userid' => $contextlist->get_user()->id]);
            $DB->delete_records('local_kbacompany_empcomp', ['userid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_empcomp', 'recordedby', 0, ['recordedby' => $contextlist->get_user()->id]);
            $assessmentuuids = $DB->get_fieldset_select('local_kbacompany_assess', 'uuid', 'userid = :userid',
                ['userid' => $contextlist->get_user()->id]);
            if ($assessmentuuids) {
                list($insql, $inparams) = $DB->get_in_or_equal($assessmentuuids, SQL_PARAMS_NAMED, 'deleteassessment');
                $DB->delete_records_select('local_kbacompany_assessitem', 'assessmentuuid ' . $insql, $inparams);
            }
            $DB->delete_records('local_kbacompany_assess', ['userid' => $contextlist->get_user()->id]);
            foreach (['assessorid', 'decidedby', 'createdby'] as $field) {
                $DB->set_field('local_kbacompany_assess', $field, 0, [$field => $contextlist->get_user()->id]);
            }
            $subjectcycles = $DB->get_fieldset_select('local_kbacompany_360cycle', 'uuid', 'userid = :userid',
                ['userid' => $contextlist->get_user()->id]);
            if ($subjectcycles) {
                list($csql, $cparams) = $DB->get_in_or_equal($subjectcycles, SQL_PARAMS_NAMED, 'c360delete');
                $responseids = $DB->get_fieldset_select('local_kbacompany_360resp', 'id', 'cycleuuid ' . $csql, $cparams);
                if ($responseids) {
                    list($rsql, $rparams) = $DB->get_in_or_equal($responseids, SQL_PARAMS_NAMED, 'r360delete');
                    $DB->delete_records_select('local_kbacompany_360score', 'responseid ' . $rsql, $rparams);
                }
                $DB->delete_records_select('local_kbacompany_360resp', 'cycleuuid ' . $csql, $cparams);
                $DB->delete_records_select('local_kbacompany_360cycle', 'uuid ' . $csql, $cparams);
            }
            $responseids = $DB->get_fieldset_select('local_kbacompany_360resp', 'id', 'respondentid = :rid',
                ['rid' => $contextlist->get_user()->id]);
            if ($responseids) {
                list($rsql, $rparams) = $DB->get_in_or_equal($responseids, SQL_PARAMS_NAMED, 'r360own');
                $DB->delete_records_select('local_kbacompany_360score', 'responseid ' . $rsql, $rparams);
            }
            $DB->delete_records('local_kbacompany_360resp', ['respondentid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_360cycle', 'createdby', 0, ['createdby' => $contextlist->get_user()->id]);
            $devplans = $DB->get_fieldset_select('local_kbacompany_devplan', 'uuid', 'userid = :u', ['u'=>$contextlist->get_user()->id]);
            if ($devplans) {
                list($dsql, $dparams) = $DB->get_in_or_equal($devplans, SQL_PARAMS_NAMED, 'devdelete');
                $DB->delete_records_select('local_kbacompany_devitem', 'planuuid ' . $dsql, $dparams);
            }
            $DB->delete_records('local_kbacompany_devplan', ['userid' => $contextlist->get_user()->id]);
            foreach (['createdby','modifiedby'] as $field) { $DB->set_field('local_kbacompany_devplan', $field, 0, [$field => $contextlist->get_user()->id]); }
            $DB->set_field('local_kbacompany_devitem', 'responsibleid', 0, ['responsibleid' => $contextlist->get_user()->id]);
            foreach (['createdby','modifiedby'] as $field) { $DB->set_field('local_kbacompany_devitem', $field, 0, [$field => $contextlist->get_user()->id]); }
            $uid = (int)$contextlist->get_user()->id;
            foreach (['sourceuserid','targetuserid'] as $field) {
                $DB->set_field('local_kbacompany_functxfer', $field, 0, [$field => $uid]);
            }
            foreach (['authorizedby','acceptedby','revokedby','createdby'] as $field) {
                $DB->set_field('local_kbacompany_functxfer', $field, 0, [$field => $uid]);
            }
            $DB->delete_records('local_kbacompany_cultscore', ['userid' => $uid]);
            $DB->set_field('local_kbacompany_cultscore', 'recordedby', 0, ['recordedby' => $uid]);
            foreach (['createdby','modifiedby'] as $field) {
                $DB->set_field('local_kbacompany_cultitem', $field, 0, [$field => $uid]);
            }
            foreach (['ownerid','curatorid','createdby'] as $field) {
                $DB->set_field('local_kbacompany_mcycle', $field, 0, [$field => $uid]);
            }
            $DB->set_field('local_kbacompany_mcyclecp', 'createdby', 0, ['createdby' => $uid]);
            $DB->delete_records('local_kbacompany_member', ['userid' => $contextlist->get_user()->id]);
            $DB->delete_records('local_kbacompany_syncout', ['userid' => $contextlist->get_user()->id]);
            $DB->delete_records('local_kbacompany_extmap', ['entitykind' => 'user', 'localref' => (string)$contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_company', 'createdby', 0, ['createdby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_company', 'modifiedby', 0, ['modifiedby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_cloudmap', 'createdby', 0,
                ['createdby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_cloudmap', 'modifiedby', 0,
                ['modifiedby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_staffplan', 'approvedby', 0,
                ['approvedby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_statval', 'reason', '',
                ['writerid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_statval', 'writerid', 0,
                ['writerid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_policy', 'reason', '',
                ['actorid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_policy', 'actorid', 0,
                ['actorid' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_process', 'createdby', 0,
                ['createdby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_procmodel', 'createdby', 0,
                ['createdby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_procmodel', 'modifiedby', 0,
                ['modifiedby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_function', 'createdby', 0,
                ['createdby' => $contextlist->get_user()->id]);
            $DB->set_field('local_kbacompany_function', 'modifiedby', 0,
                ['modifiedby' => $contextlist->get_user()->id]);
            $userid = (int)$contextlist->get_user()->id;
            $DB->set_field('local_kbacompany_doc', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_doc', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->delete_records('local_kbacompany_docreview', ['reviewerid' => $userid]);
            $DB->delete_records('local_kbacompany_docack', ['userid' => $userid]);
            $DB->set_field('local_kbacompany_docack', 'requiredby', 0, ['requiredby' => $userid]);
            $records = $DB->get_records('local_kbacompany_record', ['createdby' => $userid]);
            foreach ($records as $record) {
                $record->createdby = 0;
                $record->payload = '';
                $record->payloadhash = hash('sha256', '');
                $DB->update_record('local_kbacompany_record', $record);
            }
            $DB->set_field('local_kbacompany_poswork', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_poswork', 'modifiedby', 0, ['modifiedby' => $userid]);
            foreach (['market', 'segment', 'offering', 'bizmodel', 'philosophy', 'principle', 'marketev', 'ownerintent', 'strategy', 'stratgoal', 'goallink', 'finscenario', 'fincenter', 'finstmt'] as $table) {
                $DB->set_field('local_kbacompany_' . $table, 'createdby', 0, ['createdby' => $userid]);
                $DB->set_field('local_kbacompany_' . $table, 'modifiedby', 0, ['modifiedby' => $userid]);
            }
            $DB->set_field('local_kbacompany_bmcitem', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_bmcitem', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->set_field('local_kbacompany_bmccomment', 'comment', '', ['authoruserid' => $userid]);
            $DB->set_field('local_kbacompany_bmccomment', 'authoruserid', 0, ['authoruserid' => $userid]);
            foreach (['bmcval', 'bmcgap'] as $table) {
                $DB->set_field('local_kbacompany_' . $table, 'createdby', 0, ['createdby' => $userid]);
                $DB->set_field('local_kbacompany_' . $table, 'modifiedby', 0, ['modifiedby' => $userid]);
            }
            $DB->set_field('local_kbacompany_finline', 'createdby', 0, ['createdby' => $userid]);
            foreach (['createdby', 'modifiedby', 'submittedby', 'approvedby'] as $field) {
                $DB->set_field('local_kbacompany_finbudget', $field, 0, [$field => $userid]);
            }
            $DB->set_field('local_kbacompany_finbudline', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_finbudfact', 'reportedby', 0, ['reportedby' => $userid]);
            $DB->set_field('local_kbacompany_resgate', 'decisionnote', '', ['decidedby' => $userid]);
            foreach (['createdby', 'modifiedby', 'submittedby', 'decidedby'] as $field) {
                $DB->set_field('local_kbacompany_resgate', $field, 0, [$field => $userid]);
            }
            foreach ($DB->get_records('local_kbacompany_resitem', ['createdby' => $userid]) as $resourceitem) {
                $resourceitem->createdby = 0;
                $resourceitem->available = '';
                $resourceitem->gap = '';
                $resourceitem->evidence = '';
                $resourceitem->evidenceurl = '';
                $DB->update_record('local_kbacompany_resitem', $resourceitem);
            }
            foreach ($DB->get_records('local_kbacompany_ownerframe', ['owneruserid' => $userid]) as $ownerframe) {
                $decoded = json_decode((string)$ownerframe->payload, true);
                $requirements = is_array($decoded) && isset($decoded['requirements']) && is_array($decoded['requirements']) ? $decoded['requirements'] : [];
                $ownerframe->owneruserid = 0; $ownerframe->createdby = 0; $ownerframe->modifiedby = 0;
                $ownerframe->payload = json_encode(['requirements' => $requirements], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $DB->update_record('local_kbacompany_ownerframe', $ownerframe);
            }
            $DB->set_field('local_kbacompany_ownerframe', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_ownerframe', 'modifiedby', 0, ['modifiedby' => $userid]);
            $DB->set_field('local_kbacompany_ownerfb', 'comment', '', ['authoruserid' => $userid]);
            $DB->set_field('local_kbacompany_ownerfb', 'authoruserid', 0, ['authoruserid' => $userid]);
            $DB->set_field('local_kbacompany_adminitem', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_adminitem', 'modifiedby', 0, ['modifiedby' => $userid]);
            $taskfs = get_file_storage();
            foreach ($DB->get_records('local_kbacompany_task', null, '', 'id') as $taskrow) {
                foreach (['task_attachment', 'task_result'] as $filearea) {
                    foreach ($taskfs->get_area_files($systemcontext->id, 'local_kbacompany', $filearea, (int)$taskrow->id,
                            'id ASC', false) as $taskfile) {
                        if ((int)$taskfile->get_userid() === $userid) { $taskfile->delete(); }
                    }
                }
            }
            foreach ($DB->get_records('local_kbacompany_task', ['assigneeid' => $userid]) as $task) {
                $task->assigneeid = 0; $task->result = ''; $DB->update_record('local_kbacompany_task', $task);
            }
            foreach ($DB->get_records('local_kbacompany_task', ['creatorid' => $userid]) as $task) {
                $task->creatorid = 0; $task->description = ''; $DB->update_record('local_kbacompany_task', $task);
            }
            $DB->set_field('local_kbacompany_task', 'reviewerid', 0, ['reviewerid' => $userid]);
            $DB->set_field('local_kbacompany_task', 'observerid', 0, ['observerid' => $userid]);
            $DB->set_field('local_kbacompany_taskcheck', 'assigneeid', 0, ['assigneeid' => $userid]);
            $DB->set_field('local_kbacompany_tasklog', 'details', null, ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_tasklog', 'actorid', 0, ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_taskrec', 'assigneeid', 0, ['assigneeid' => $userid]);
            foreach ($DB->get_records('local_kbacompany_taskrec', ['creatorid' => $userid]) as $definition) {
                $definition->creatorid = 0; $definition->description = ''; $DB->update_record('local_kbacompany_taskrec', $definition);
            }
            $DB->set_field('local_kbacompany_taskrec', 'reviewerid', 0, ['reviewerid' => $userid]);
            foreach (['statmeta', 'statbind', 'statnorm', 'statpolicy'] as $table) {
                $DB->set_field('local_kbacompany_' . $table, 'createdby', 0, ['createdby' => $userid]);
            }
            $DB->set_field('local_kbacompany_stateevt', 'comment', '', ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_stateevt', 'actorid', 0, ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_tasklibcat', 'creatorid', 0, ['creatorid' => $userid]);
            $DB->set_field('local_kbacompany_tasklibtpl', 'creatorid', 0, ['creatorid' => $userid]);
            foreach (['userid', 'reviewerid', 'observerid', 'createdby', 'approvedby'] as $field) {
                $DB->set_field('local_kbacompany_bplan', $field, 0, [$field => $userid]);
            }
            $DB->set_field('local_kbacompany_bplanrev', 'comment', '', ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_bplanrev', 'actorid', 0, ['actorid' => $userid]);
            $DB->set_field('local_kbacompany_bpcoord', 'summary', '', ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_bpcoord', 'requestedchange', '', ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_bpcoord', 'response', '', ['respondedby' => $userid]);
            $DB->set_field('local_kbacompany_bpcoord', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_bpcoord', 'respondedby', 0, ['respondedby' => $userid]);
            foreach ($DB->get_records_select('local_kbacompany_a3review', 'ownerid = :o OR createdby = :c', ['o'=>$userid,'c'=>$userid]) as $a3) {
                foreach (['title','problemcontext','currentstate','targetstate','gapanalysis','causeanalysis','countermeasures','conclusion','standardization'] as $field) { $a3->$field = ''; }
                if ((int)$a3->ownerid === $userid) { $a3->ownerid = 0; }
                if ((int)$a3->createdby === $userid) { $a3->createdby = 0; }
                $DB->update_record('local_kbacompany_a3review', $a3);
            }
            $DB->set_field('local_kbacompany_tasksrc', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_ofstpl', 'createdby', 0, ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_ofstpl', 'reviewer', 0, ['reviewer' => $userid]);
            $DB->set_field('local_kbacompany_ofstplver', 'createdby', 0, ['createdby' => $userid]);
            foreach (['organizerid', 'chairid', 'secretaryid', 'closedby'] as $field) {
                $DB->set_field('local_kbacompany_meeting', $field, 0, [$field => $userid]);
            }
            $DB->delete_records('local_kbacompany_meetpart', ['userid' => $userid]);
            $DB->set_field('local_kbacompany_meetagenda', 'ownerid', 0, ['ownerid' => $userid]);
            foreach (['ownerid', 'proposedby', 'decidedby'] as $field) {
                $DB->set_field('local_kbacompany_decision', $field, 0, [$field => $userid]);
            }
            $authored = $DB->get_records('local_kbacompany_commmsg', ['authorid' => $userid]);
            $DB->delete_records('local_kbacompany_commrev', ['actorid' => $userid]);
            $communicationfs = get_file_storage();
            foreach ($authored as $message) {
                $communicationfs->delete_area_files($systemcontext->id, 'local_kbacompany', 'communication_attachment', (int)$message->id);
                $DB->delete_records('local_kbacompany_commattach', ['companyuuid' => $message->companyuuid, 'messageuuid' => $message->uuid]);
                $DB->delete_records('local_kbacompany_commrev', ['companyuuid' => $message->companyuuid, 'messageuuid' => $message->uuid]);
                $message->authorid = 0; $message->body = ''; $message->status = 'withdrawn';
                $DB->update_record('local_kbacompany_commmsg', $message);
            }
            $DB->set_field('local_kbacompany_commthread', 'title', '', ['createdby' => $userid]);
            $DB->set_field('local_kbacompany_commthread', 'createdby', 0, ['createdby' => $userid]);
            $DB->delete_records('local_kbacompany_commmention', ['userid' => $userid]);
            $DB->delete_records('local_kbacompany_commnotif', ['userid' => $userid]);
            $DB->delete_records('local_kbacompany_commread', ['userid' => $userid]);
            $DB->delete_records('local_kbacompany_commpart', ['userid' => $userid]);
        }
    }
    /** Remove user references, authored evidence and snapshots identifying that user. */
    private static function forget_workflow(?int $userid): void {
        global $DB;
        foreach (['title','description','requirement','location','basisref'] as $column) {
            $DB->set_field('local_kbacompany_deviation',$column,'',$userid===null?[]:['createdby'=>$userid]);
        }
        $DB->set_field('local_kbacompany_deviation','criterion','',$userid===null?[]:['confirmedby'=>$userid]);
        foreach (['deviation' => ['confirmedby'=>null,'createdby' => 'evidence', 'analysedby' => 'cause', 'closedby' => null],
                'devlink' => ['createdby' => 'reason'], 'devcheck' => ['createdby' => 'evidence']] as $table => $fields) {
            foreach ($fields as $field => $content) {
                $where = $userid === null ? [] : [$field => $userid];
                if ($content !== null) { $DB->set_field('local_kbacompany_' . $table, $content, '', $where); }
                $DB->set_field('local_kbacompany_' . $table, $field, 0, $where);
            }
        }
        foreach (['wfassign' => ['userid'], 'wfapprove' => ['approvedby'],
                'wfwork' => ['userid', 'reviewerid'], 'wfevidence' => ['userid']] as $table => $fields) {
            foreach ($fields as $field) {
                $where = $userid === null ? [] : [$field => $userid];
                if ($table === 'wfevidence') {
                    $DB->set_field('local_kbacompany_' . $table, 'body', '', $where);
                    $DB->set_field('local_kbacompany_' . $table, 'url', '', $where);
                }
                $DB->set_field('local_kbacompany_' . $table, $field, 0, $where);
            }
        }
        $contains = static function($value) use (&$contains, $userid): bool {
            if (!is_array($value)) { return false; }
            foreach ($value as $key => $child) {
                if (in_array($key, ['userid', 'reviewerid', 'approvedby', 'createdby', 'analysedby', 'closedby', 'confirmedby', 'actorid'], true) &&
                        (int)$child === $userid) { return true; }
                if (is_array($child) && $contains($child)) { return true; }
            }
            return false;
        };
        $records = $DB->get_recordset_select('local_kbacompany_aclog',
            'action LIKE :prefix OR action LIKE :deviation OR action LIKE :policy',
            ['prefix' => 'wf_%', 'deviation' => 'dv_%', 'policy' => 'policy_%']);
        foreach ($records as $record) {
            if ($userid === null || $contains(json_decode($record->oldvalue ?? 'null', true)) ||
                    $contains(json_decode($record->newvalue ?? 'null', true))) {
                $DB->set_field('local_kbacompany_aclog', 'oldvalue', null, ['id' => $record->id]);
                $DB->set_field('local_kbacompany_aclog', 'newvalue', null, ['id' => $record->id]);
            }
        }
        $records->close();
    }
}
