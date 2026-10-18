<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
require_capability('local/kbacorporate:viewattendance', $context);

$companyid = required_param('companyid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$state = optional_param('state', '', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$validstates = ['', 'attended', 'partial', 'missed', 'upcoming', 'nodata'];
if (!in_array($state, $validstates, true)) {
    $state = '';
}

$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) { $userid = 0; }
$rows = \local_kbacorporate\local\bigbluebutton::is_available()
    ? \local_kbacorporate\local\bigbluebutton::get_company_rows($companyid, $visibleids) : [];

$filtered = [];
foreach ($rows as $row) {
    if ($courseid && (int)$row->courseid !== $courseid) {
        continue;
    }
    if ($userid && (int)$row->userid !== $userid) {
        continue;
    }
    if ($state !== '' && $row->state !== $state) {
        continue;
    }
    $filtered[] = $row;
}

$filename = clean_filename('KBA_' . $company->name . '_webinars_' . date('Y-m-d'));
$csv = new csv_export_writer();
$csv->set_filename($filename);
$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('fullname', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('bbbwebinar', 'local_kbacorporate'),
    get_string('group', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('bbbopenat', 'local_kbacorporate'),
    get_string('bbbcloseat', 'local_kbacorporate'),
    get_string('bbbtime', 'local_kbacorporate'),
    get_string('bbbscheduledduration', 'local_kbacorporate'),
    get_string('bbbcoverage', 'local_kbacorporate'),
    get_string('bbbfirstjoin', 'local_kbacorporate'),
    get_string('bbbjoincount', 'local_kbacorporate'),
    get_string('bbblateness', 'local_kbacorporate'),
    get_string('bbbislate', 'local_kbacorporate'),
    get_string('bbblastlogout', 'local_kbacorporate'),
    get_string('bbbearlyleave', 'local_kbacorporate'),
    get_string('bbbsessions', 'local_kbacorporate'),
    get_string('bbbrequirement', 'local_kbacorporate'),
    get_string('bbbthresholdmet', 'local_kbacorporate'),
    get_string('bbbchats', 'local_kbacorporate'),
    get_string('bbbtalks', 'local_kbacorporate'),
    get_string('bbbraisehand', 'local_kbacorporate'),
    get_string('bbbpollvotes', 'local_kbacorporate'),
    get_string('bbbemojis', 'local_kbacorporate'),
    get_string('bbbengagementevents', 'local_kbacorporate'),
]);

foreach ($filtered as $row) {
    $e = $row->engagement;
    $csv->add_data([
        $company->name,
        $row->fullname,
        $row->email,
        $row->position,
        $row->coursename,
        $row->webinarname,
        $row->groupname,
        get_string('bbbstate' . $row->state, 'local_kbacorporate'),
        $row->openingtime ? userdate($row->openingtime) : '',
        $row->closingtime ? userdate($row->closingtime) : '',
        $row->attended ? $row->minutes : '',
        $row->scheduledminutes === null ? '' : $row->scheduledminutes,
        $row->coveragepercent === null ? '' : $row->coveragepercent,
        $row->firstjoin ? userdate($row->firstjoin) : '',
        $row->joincount,
        $row->latenessminutes === null ? '' : $row->latenessminutes,
        $row->latenessminutes === null ? '' : ($row->late ? get_string('yes') : get_string('no')),
        $row->lastlogout ? userdate($row->lastlogout) : '',
        $row->earlyleaveminutes === null ? '' : $row->earlyleaveminutes,
        $row->sessions,
        $row->requiredminutes ?: '',
        $row->thresholdmet === null ? '' : ($row->thresholdmet ? get_string('yes') : get_string('no')),
        (int)$e['chats'],
        (int)$e['talks'],
        (int)$e['raisehand'],
        (int)$e['poll_votes'],
        (int)$e['emojis'],
        (int)$row->engagementtotal,
    ]);
}

$csv->download_file();
exit;
