<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Department helpers for corporate structure and scoped filtering. */
class departments {
    public static function get_company_departments(int $companyid, bool $activeonly = true): array {
        global $DB;
        $conditions = ['companyid' => $companyid];
        if ($activeonly) {
            $conditions['active'] = 1;
        }
        return $DB->get_records('local_kbacorp_department', $conditions, 'name ASC');
    }

    public static function get_department(int $departmentid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacorp_department', ['id' => $departmentid], '*', MUST_EXIST);
    }

    public static function get_user_departments(int $companyid, int $userid, string $role = ''): array {
        global $DB;
        $params = ['companyid' => $companyid, 'userid' => $userid];
        $rolesql = '';
        if ($role !== '') {
            $rolesql = ' AND dm.deptrole = :deptrole';
            $params['deptrole'] = $role;
        }
        $sql = "SELECT d.*
                  FROM {local_kbacorp_department} d
                  JOIN {local_kbacorp_dept_member} dm ON dm.departmentid = d.id
                 WHERE d.companyid = :companyid
                   AND dm.userid = :userid
                   AND d.active = 1{$rolesql}
              ORDER BY d.name ASC";
        return $DB->get_records_sql($sql, $params);
    }

    public static function get_department_members(int $departmentid, string $role = ''): array {
        global $DB;
        $params = ['departmentid' => $departmentid];
        $rolesql = '';
        if ($role !== '') {
            $rolesql = ' AND dm.deptrole = :deptrole';
            $params['deptrole'] = $role;
        }
        $sql = "SELECT dm.id AS assignmentid, dm.deptrole, dm.timecreated,
                       u.id, u.firstname, u.lastname, u.email
                  FROM {local_kbacorp_dept_member} dm
                  JOIN {user} u ON u.id = dm.userid
                 WHERE dm.departmentid = :departmentid
                   AND u.deleted = 0{$rolesql}
              ORDER BY dm.deptrole DESC, u.lastname, u.firstname";
        return $DB->get_records_sql($sql, $params);
    }

    public static function get_department_names_for_users(int $companyid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal(array_values(array_map('intval', $userids)), SQL_PARAMS_NAMED, 'du');
        $params['companyid'] = $companyid;
        $sql = "SELECT dm.id, dm.userid, d.name
                  FROM {local_kbacorp_dept_member} dm
                  JOIN {local_kbacorp_department} d ON d.id = dm.departmentid
                 WHERE d.companyid = :companyid
                   AND d.active = 1
                   AND dm.deptrole = 'employee'
                   AND dm.userid {$insql}
              ORDER BY d.name ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            if (!isset($result[$row->userid])) {
                $result[$row->userid] = [];
            }
            $result[$row->userid][] = $row->name;
        }
        return $result;
    }

    public static function render_filter(int $companyid, int $viewerid, int $departmentid, string $path, array $extra = []): string {
        $allowed = access::get_allowed_departments($companyid, $viewerid);
        if (!$allowed) {
            return '';
        }
        $alllabel = access::has_full_company_access($companyid, $viewerid)
            ? get_string('allcompanyemployees', 'local_kbacorporate')
            : get_string('alldepartmentsavailable', 'local_kbacorporate');
        $options = [0 => $alllabel];
        foreach ($allowed as $department) {
            $options[(int)$department->id] = format_string($department->name);
        }
        if ($departmentid && !isset($options[$departmentid])) {
            $departmentid = 0;
        }
        $html = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new \moodle_url($path))->out(false),
            'class' => 'kbacorp-filters kbacorp-department-filter',
        ]);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
        foreach ($extra as $name => $value) {
            if ($name === 'companyid' || $name === 'departmentid' || $value === '' || $value === null) {
                continue;
            }
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        $html .= \html_writer::label(get_string('department', 'local_kbacorporate'), 'kbacorp-departmentid', false, ['class' => 'kbacorp-filter-label']);
        $html .= \html_writer::select($options, 'departmentid', $departmentid, false, ['id' => 'kbacorp-departmentid', 'class' => 'custom-select']);
        $html .= \html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
        $html .= \html_writer::end_tag('form');
        return $html;
    }
}
