<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_kbacorporate_uninstall() {
    global $DB;

    require_once(__DIR__ . '/../../../lib/accesslib.php');

    role_unassign_all(['component' => 'local_kbacorporate']);
    $roleid = (int)get_config('local_kbacorporate', 'corporatemanagerroleid');
    $created = (int)get_config('local_kbacorporate', 'corporatemanagerrolecreated');
    if ($created && $roleid && $DB->record_exists('role', ['id' => $roleid, 'shortname' => 'kbacorporatemanager'])) {
        delete_role($roleid);
    }
    unset_config('corporatemanagerroleid', 'local_kbacorporate');
    unset_config('corporatemanagerrolecreated', 'local_kbacorporate');
    $deptroleid = (int)get_config('local_kbacorporate', 'departmentmanagerroleid');
    $deptcreated = (int)get_config('local_kbacorporate', 'departmentmanagerrolecreated');
    if ($deptcreated && $deptroleid && $DB->record_exists('role', ['id' => $deptroleid, 'shortname' => 'kbadepartmentmanager'])) {
        delete_role($deptroleid);
    }
    unset_config('departmentmanagerroleid', 'local_kbacorporate');
    unset_config('departmentmanagerrolecreated', 'local_kbacorporate');
    return true;
}
