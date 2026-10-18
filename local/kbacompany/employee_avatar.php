<?php
// Upload own BMS24 photo through Moodle's filepicker and canonical user/icon storage.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gdlib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\form\bms_avatar_form;

require_login();
$systemcontext = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $systemcontext);
$companyuuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$userid = required_param('userid', PARAM_INT);
if ($userid !== (int)$USER->id) {
    throw new required_capability_exception($systemcontext, 'moodle/user:editownprofile', 'nopermissions', '');
}
company_context_service::context($companyuuid, $userid);
require_capability('moodle/user:editownprofile', context_user::instance($userid));
if (!empty($CFG->disableuserimages)) {
    throw new moodle_exception('bms_avatar_disabled', 'local_kbacompany');
}
$membership = optional_param('membership', '', PARAM_RAW_TRIMMED);
$model = employee_service::directory_record($companyuuid, $userid, $userid, $membership);
$membershipuuid = $model['employee']['membership_uuid'];
$returnurl = new moodle_url('/local/kbacompany/employee_view.php', [
    'uuid' => $companyuuid, 'userid' => $userid, 'membership' => $membershipuuid]);
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/kbacompany/employee_avatar.php', [
    'uuid' => $companyuuid, 'userid' => $userid, 'membership' => $membershipuuid]));
$PAGE->set_title(get_string('bms_avatar_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$form = new bms_avatar_form($PAGE->url);
$form->set_data((object)['uuid' => $companyuuid, 'userid' => $userid, 'membership' => $membershipuuid]);
if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    require_sesskey();
    // Never trust hidden values: they are bound by the URL and the current login.
    if ($data->uuid !== $companyuuid || (int)$data->userid !== $userid || $data->membership !== $membershipuuid) {
        throw new moodle_exception('invalidparameter');
    }
    $content = $form->get_file_content('avatar');
    if (!$content || strlen($content) > 2097152) {
        throw new moodle_exception('bms_avatar_invalid', 'local_kbacompany');
    }
    // Validate bytes, rather than filename/declared MIME alone.
    $imageinfo = @getimagesizefromstring($content);
    if ($imageinfo === false || empty($imageinfo[0]) || empty($imageinfo[1]) ||
            $imageinfo[0] > 6000 || $imageinfo[1] > 6000) {
        throw new moodle_exception('bms_avatar_invalid', 'local_kbacompany');
    }
    $temp = make_request_directory() . '/bms-user-icon';
    if (file_put_contents($temp, $content) === false) {
        throw new moodle_exception('bms_avatar_invalid', 'local_kbacompany');
    }
    $pictureid = process_new_icon(context_user::instance($userid), 'user', 'icon', 0, $temp);
    if (!$pictureid) {
        throw new moodle_exception('bms_avatar_invalid', 'local_kbacompany');
    }
    $DB->set_field('user', 'picture', $pictureid, ['id' => $userid]);
    redirect($returnurl, get_string('bms_avatar_saved', 'local_kbacompany'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}
$shell = bms_page_shell_service::begin($companyuuid, $userid, 'employees',
    get_string('bms_avatar_title', 'local_kbacompany'));
echo html_writer::tag('h2', get_string('bms_avatar_title', 'local_kbacompany'));
echo html_writer::tag('p', get_string('bms_avatar_help', 'local_kbacompany'), ['class' => 'text-muted']);
$form->display();
bms_page_shell_service::finish($shell);
