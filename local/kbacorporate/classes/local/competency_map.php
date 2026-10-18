<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped employee x competency projection for managers. */
class competency_map {
    /** Protect exported text from spreadsheet formula injection. */
    public static function csv_value(string $value): string {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function build(int $companyid, int $viewerid, int $departmentid = 0): array {
        global $DB;

        $userids = access::get_visible_employee_ids($companyid, $viewerid, $departmentid);
        if (!$userids) {
            return ['employees' => [], 'rows' => [], 'summary' => self::summary([])];
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cmu');
        $users = $DB->get_records_select('user', "id {$insql} AND deleted = 0", $params, 'lastname, firstname');
        $departments = departments::get_department_names_for_users($companyid, array_keys($users));
        $rows = [];
        foreach ($users as $user) {
            $snapshot = competencies::get_user_snapshot((int)$user->id, $companyid, false);
            foreach ($snapshot['competencies'] as $competency) {
                if (($competency->targetsource ?? '') !== 'businessrole') {
                    continue;
                }
                $roleids = [];
                $rolenames = [];
                foreach ($competency->targetroles as $role) {
                    $roleids[] = (int)$role->id;
                    $rolenames[] = format_string($role->name);
                }
                $rows[] = (object)[
                    'userid' => (int)$user->id,
                    'employee' => fullname($user),
                    'departments' => $departments[$user->id] ?? [],
                    'roleids' => $roleids,
                    'roles' => $rolenames,
                    'competencyid' => (int)$competency->competencyid,
                    'competency' => format_string($competency->shortname),
                    'frameworkid' => (int)$competency->frameworkid,
                    'framework' => format_string($competency->frameworkname),
                    'currentlevel' => $competency->currentlevel,
                    'targetlevel' => $competency->targetlevel,
                    'gap' => $competency->gap,
                    'priority' => (string)($competency->rolepriority ?? ''),
                    'required' => !empty($competency->rolerequired),
                    'status' => (string)$competency->displaystatus,
                    'attention' => !empty($competency->attentionrequired),
                ];
            }
        }
        return ['employees' => $users, 'rows' => $rows, 'summary' => self::summary($rows)];
    }

    public static function filter(array $rows, int $roleid, int $frameworkid, string $status, string $query): array {
        $query = \core_text::strtolower(trim($query));
        return array_values(array_filter($rows, static function($row) use ($roleid, $frameworkid, $status, $query) {
            if ($roleid && !in_array($roleid, $row->roleids, true)) {
                return false;
            }
            if ($frameworkid && $row->frameworkid !== $frameworkid) {
                return false;
            }
            if ($status === 'attention' && !$row->attention) {
                return false;
            }
            if ($status === 'gap' && !($row->gap !== null && $row->gap > 0)) {
                return false;
            }
            if ($status === 'notassessed' && $row->currentlevel !== null) {
                return false;
            }
            if ($status === 'targetreached' && !($row->gap !== null && $row->gap === 0)) {
                return false;
            }
            if ($query !== '') {
                $haystack = \core_text::strtolower(implode(' ', [$row->employee, $row->competency,
                    $row->framework, implode(' ', $row->roles), implode(' ', $row->departments)]));
                if (\core_text::strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }

    public static function summary(array $rows): \stdClass {
        $employeeids = [];
        $notassessed = $gaps = $attention = $reached = 0;
        foreach ($rows as $row) {
            $employeeids[$row->userid] = true;
            $notassessed += $row->currentlevel === null ? 1 : 0;
            $gaps += $row->gap !== null && $row->gap > 0 ? 1 : 0;
            $attention += $row->attention ? 1 : 0;
            $reached += $row->gap !== null && $row->gap === 0 ? 1 : 0;
        }
        $requirements = count($rows);
        $assessed = max(0, $requirements - $notassessed);
        return (object)['employees' => count($employeeids), 'requirements' => $requirements,
            'notassessed' => $notassessed, 'assessed' => $assessed, 'gaps' => $gaps,
            'attention' => $attention, 'reached' => $reached,
            'assessmentcoverage' => $requirements ? (int)round(($assessed / $requirements) * 100) : 0,
            'targetreadiness' => $requirements ? (int)round(($reached / $requirements) * 100) : 0];
    }
}
