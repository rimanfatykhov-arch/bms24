<?php
// Company-scoped self-service profile editor. Never grants HR management permissions.
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../../user/lib.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\employee_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$companyuuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
company_context_service::context($companyuuid, (int)$USER->id);
$userid = required_param('userid', PARAM_INT);
if ($userid !== (int)$USER->id) {
    throw new required_capability_exception($context, 'moodle/user:editownprofile', 'nopermissions', '');
}
$usercontext = context_user::instance($userid);
require_capability('moodle/user:editownprofile', $usercontext);
$membership = optional_param('membership', '', PARAM_RAW_TRIMMED);
$model = employee_service::directory_record($companyuuid, $userid, $userid, $membership);
$profile = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$backurl = new moodle_url('/local/kbacompany/employee_view.php',
    ['uuid' => $companyuuid, 'userid' => $userid, 'membership' => $model['employee']['membership_uuid']]);
$values = ['firstname' => $profile->firstname, 'lastname' => $profile->lastname,
    'middlename' => (string)($profile->middlename ?? ''), 'phone' => $profile->phone1,
    'phone2' => (string)$profile->phone2, 'city' => (string)$profile->city,
    'description' => trim(strip_tags((string)$profile->description))];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $values = [
        'firstname' => trim(optional_param('firstname', '', PARAM_TEXT)),
        'lastname' => trim(optional_param('lastname', '', PARAM_TEXT)),
        'middlename' => trim(optional_param('middlename', '', PARAM_TEXT)),
        'phone2' => trim(optional_param('phone2', '', PARAM_TEXT)),
        'city' => trim(optional_param('city', '', PARAM_TEXT)),
        'description' => trim(optional_param('description', '', PARAM_TEXT)),
        'phone' => trim(optional_param('phone', '', PARAM_TEXT)),
    ];
    foreach (['firstname', 'lastname'] as $key) {
        if ($values[$key] === '' || core_text::strlen($values[$key]) > 100) {
            $errors[$key] = get_string('bms_self_profile_name_invalid', 'local_kbacompany');
        }
    }
    foreach (['middlename' => 100, 'phone2' => 100, 'city' => 120, 'description' => 2000] as $key => $max) {
        if (core_text::strlen($values[$key]) > $max) {
            $errors[$key] = get_string('bms_self_profile_field_invalid', 'local_kbacompany', $max);
        }
    }
    if (core_text::strlen($values['phone']) > 100) {
        $errors['phone'] = get_string('bms_self_profile_phone_invalid', 'local_kbacompany');
    }
    if (!$errors) {
        // Only own non-privileged Moodle account fields. No email, roles, company or memberships.
        $updated = (object)[
            'id' => $userid,
            'middlename' => $values['middlename'],
            'phone2' => $values['phone2'],
            'city' => $values['city'],
            'description' => $values['description'],
            'descriptionformat' => FORMAT_PLAIN,
            'firstname' => $values['firstname'],
            'lastname' => $values['lastname'],
            'phone1' => $values['phone'],
        ];
        user_update_user($updated, false, false);
        redirect($backurl, get_string('bms_self_profile_saved', 'local_kbacompany'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/employee_self_profile.php',
    ['uuid' => $companyuuid, 'userid' => $userid, 'membership' => $model['employee']['membership_uuid']]));
$PAGE->set_title(get_string('bms_self_profile_edit', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($companyuuid, $userid, 'employees',
    get_string('bms_self_profile_edit', 'local_kbacompany'));
echo html_writer::start_div('bms-ecu');
echo html_writer::div(html_writer::link($backurl, get_string('bms_employee_back_to_list', 'local_kbacompany'),
    ['class' => 'btn btn-outline-secondary']), 'mb-3');
echo html_writer::tag('h2', get_string('bms_self_profile_edit', 'local_kbacompany'));
echo html_writer::tag('p', get_string('bms_self_profile_note', 'local_kbacompany'), ['class'=>'text-muted']);
// Canonical Moodle picture, edited without leaving BMS24.
$avatarurl = new moodle_url('/local/kbacompany/employee_avatar.php',
    ['uuid' => $companyuuid, 'userid' => $userid, 'membership' => $model['employee']['membership_uuid']]);
echo html_writer::div($OUTPUT->user_picture($profile, ['size' => 100]) . ' ' . html_writer::link($avatarurl,
    get_string('bms_self_profile_photo_edit', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']), 'mb-3');
if ($errors) {
    echo html_writer::div(implode(' ', array_map('s', array_values($errors))), 'alert alert-danger', ['role'=>'alert']);
}
echo html_writer::start_tag('form', ['method'=>'post', 'action'=>$PAGE->url->out(false), 'class'=>'card p-4',
    'style'=>'max-width:720px']);
echo html_writer::empty_tag('input', ['type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()]);
foreach (['firstname'=>get_string('firstname'), 'lastname'=>get_string('lastname'),
    'middlename'=>get_string('bms_profile_middlename', 'local_kbacompany'),
    'phone'=>get_string('phone'),
    'phone2'=>get_string('bms_profile_phone2', 'local_kbacompany'),
    'city'=>get_string('city'),
    'description'=>get_string('description')] as $key=>$label) {
    echo html_writer::start_div('form-group');
    echo html_writer::label($label, 'bms-self-' . $key);
    if ($key === 'description') {
        echo html_writer::tag('textarea', s($values[$key]), ['id'=>'bms-self-' . $key,
            'name'=>$key, 'rows'=>4, 'maxlength'=>2000, 'class'=>'form-control']);
    } else {
        echo html_writer::empty_tag('input', ['id'=>'bms-self-' . $key, 'name'=>$key, 'type'=>'text',
            'value'=>$values[$key], 'class'=>'form-control', 'maxlength'=>($key === 'city' ? 120 : 100),
            'required'=>in_array($key, ['firstname', 'lastname'], true)]);
    }
    echo html_writer::end_div();
}
echo html_writer::div(html_writer::tag('button', get_string('savechanges'),
    ['class'=>'btn btn-success', 'type'=>'submit']) . ' ' .
    html_writer::link($backurl, get_string('cancel'), ['class'=>'btn btn-outline-secondary']), 'mt-3');
echo html_writer::end_tag('form');
echo html_writer::end_div();
bms_page_shell_service::finish($shell);
