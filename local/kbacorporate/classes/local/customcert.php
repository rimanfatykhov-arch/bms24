<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Optional adapter for mod_customcert.
 *
 * The Corporate Dashboard intentionally keeps this integration read-only. It
 * reads issued certificate records and, for an authorised corporate manager,
 * delegates PDF generation to the installed Custom certificate plugin.
 */
class customcert {
    /**
     * Whether the Custom certificate plugin and the minimum tables are available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $DB;

        if (!\core_component::get_plugin_directory('mod', 'customcert')) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('customcert'))
            && $dbman->table_exists(new \xmldb_table('customcert_issues'));
    }

    /**
     * Return corporate certificate registry rows.
     *
     * Rows are created for employees currently in scope for an explicitly
     * assigned company course. A row is shown when the employee is currently
     * enrolled in the course or a certificate has already been issued. This
     * keeps historical issued documents visible after enrolment changes while
     * avoiding artificial "not issued" rows for unrelated users.
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_rows(int $companyid, ?array $userids = null): array {
        global $DB;

        if (!self::is_available()) {
            return [];
        }

        $employees = report::get_employees($companyid, $userids);
        if (!$employees) {
            return [];
        }

        $coursemaps = $DB->get_records_sql(
            "SELECT cc.id AS mappingid, cc.courseid, cc.groupid, c.fullname AS coursename, c.visible AS coursevisible
               FROM {local_kbacorp_course} cc
               JOIN {course} c ON c.id = cc.courseid
              WHERE cc.companyid = :companyid
                AND cc.active = 1
           ORDER BY c.fullname, cc.id",
            ['companyid' => $companyid]
        );
        if (!$coursemaps) {
            return [];
        }

        $courseids = [];
        foreach ($coursemaps as $map) {
            $courseids[(int)$map->courseid] = (int)$map->courseid;
        }

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'customcert']);
        if (!$moduleid) {
            return [];
        }

        list($courseinsql, $courseparams) = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED, 'ccourse');
        $sql = "SELECT cert.id AS customcertid, cert.course AS courseid, cert.name AS certificatename,
                       cert.templateid, cert.verifyany, cm.id AS cmid, cm.visible AS cmvisible
                  FROM {customcert} cert
                  JOIN {course_modules} cm ON cm.instance = cert.id AND cm.module = :moduleid
                 WHERE cert.course $courseinsql
              ORDER BY cert.course, cert.name, cert.id";
        $certificates = $DB->get_records_sql($sql, ['moduleid' => $moduleid] + $courseparams);
        if (!$certificates) {
            return [];
        }

        $certsbycourse = [];
        $certids = [];
        foreach ($certificates as $cert) {
            $certsbycourse[(int)$cert->courseid][] = $cert;
            $certids[(int)$cert->customcertid] = (int)$cert->customcertid;
        }

        $userids = array_map('intval', array_keys($employees));
        list($certinsql, $certparams) = $DB->get_in_or_equal(array_values($certids), SQL_PARAMS_NAMED, 'cert');
        list($userinsql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cuser');
        $issues = $DB->get_records_sql(
            "SELECT ci.*
               FROM {customcert_issues} ci
              WHERE ci.customcertid $certinsql
                AND ci.userid $userinsql
           ORDER BY ci.timecreated DESC, ci.id DESC",
            $certparams + $userparams
        );
        $latestissues = [];
        $issuecounts = [];
        foreach ($issues as $issue) {
            $key = (int)$issue->customcertid . ':' . (int)$issue->userid;
            $issuecounts[$key] = ($issuecounts[$key] ?? 0) + 1;
            if (!isset($latestissues[$key])) {
                $latestissues[$key] = $issue;
            }
        }

        $rows = [];
        $seen = [];
        foreach ($coursemaps as $map) {
            $courseid = (int)$map->courseid;
            if (empty($certsbycourse[$courseid])) {
                continue;
            }
            $coursecontext = \context_course::instance($courseid);
            foreach ($employees as $employee) {
                $userid = (int)$employee->id;
                if ((int)$map->groupid > 0 && !$DB->record_exists('groups_members', [
                    'groupid' => (int)$map->groupid,
                    'userid' => $userid,
                ])) {
                    continue;
                }

                $enrolled = is_enrolled($coursecontext, $employee, '', true);
                foreach ($certsbycourse[$courseid] as $cert) {
                    $dedupekey = $userid . ':' . (int)$cert->customcertid;
                    if (isset($seen[$dedupekey])) {
                        continue;
                    }
                    $issuekey = (int)$cert->customcertid . ':' . $userid;
                    $issue = $latestissues[$issuekey] ?? false;
                    if (!$enrolled && !$issue) {
                        continue;
                    }
                    $seen[$dedupekey] = true;

                    $contextid = \context_module::instance((int)$cert->cmid)->id;
                    $rows[] = (object)[
                        'userid' => $userid,
                        'fullname' => fullname($employee),
                        'email' => (string)$employee->email,
                        'position' => (string)($employee->position ?? ''),
                        'courseid' => $courseid,
                        'coursename' => (string)$map->coursename,
                        'groupid' => (int)$map->groupid,
                        'customcertid' => (int)$cert->customcertid,
                        'certificatename' => (string)$cert->certificatename,
                        'templateid' => (int)$cert->templateid,
                        'cmid' => (int)$cert->cmid,
                        'contextid' => (int)$contextid,
                        'enrolled' => (bool)$enrolled,
                        'issued' => (bool)$issue,
                        'issueid' => $issue ? (int)$issue->id : 0,
                        'code' => $issue ? (string)$issue->code : '',
                        'issuedat' => $issue ? (int)$issue->timecreated : 0,
                        'emailed' => $issue ? !empty($issue->emailed) : false,
                        'issuecount' => (int)($issuecounts[$issuekey] ?? 0),
                        'verifyany' => !empty($cert->verifyany),
                        'cmvisible' => !empty($cert->cmvisible),
                    ];
                }
            }
        }

        usort($rows, static function($a, $b) {
            $byname = strcasecmp($a->fullname, $b->fullname);
            if ($byname !== 0) {
                return $byname;
            }
            $bycourse = strcasecmp($a->coursename, $b->coursename);
            if ($bycourse !== 0) {
                return $bycourse;
            }
            return strcasecmp($a->certificatename, $b->certificatename);
        });
        return $rows;
    }

    /**
     * Return certificate rows for one company employee.
     *
     * @param int $companyid
     * @param int $userid
     * @return array
     */
    public static function get_user_rows(int $companyid, int $userid): array {
        return array_values(array_filter(self::get_company_rows($companyid), static function($row) use ($userid) {
            return (int)$row->userid === $userid;
        }));
    }

    /**
     * Registry summary.
     *
     * @param array $rows
     * @return \stdClass
     */
    public static function summary(array $rows): \stdClass {
        $issued = 0;
        $notissued = 0;
        $employees = [];
        $employeeswithissued = [];
        $activities = [];
        foreach ($rows as $row) {
            $employees[(int)$row->userid] = true;
            $activities[(int)$row->customcertid] = true;
            if (!empty($row->issued)) {
                $issued++;
                $employeeswithissued[(int)$row->userid] = true;
            } else {
                $notissued++;
            }
        }
        $total = $issued + $notissued;
        return (object)[
            'rows' => $total,
            'issued' => $issued,
            'notissued' => $notissued,
            'issuedpercent' => $total ? round(($issued / $total) * 100, 1) : null,
            'employees' => count($employees),
            'employeeswithissued' => count($employeeswithissued),
            'certificateactivities' => count($activities),
        ];
    }

    /**
     * Validate an issued certificate belongs to the requested corporate scope.
     *
     * @param int $companyid
     * @param int $issueid
     * @return \stdClass
     */
    public static function get_company_issue(int $companyid, int $issueid): \stdClass {
        global $DB;

        if (!self::is_available()) {
            throw new \moodle_exception('customcertnotavailable', 'local_kbacorporate');
        }

        $issue = $DB->get_record_sql(
            "SELECT ci.id AS issueid, ci.userid, ci.customcertid, ci.code, ci.timecreated AS issuedat,
                    ci.emailed, cert.course AS courseid, cert.name AS certificatename, cert.templateid,
                    c.fullname AS coursename, u.firstname, u.lastname, u.email
               FROM {customcert_issues} ci
               JOIN {customcert} cert ON cert.id = ci.customcertid
               JOIN {course} c ON c.id = cert.course
               JOIN {user} u ON u.id = ci.userid
              WHERE ci.id = :issueid
                AND u.deleted = 0",
            ['issueid' => $issueid],
            MUST_EXIST
        );

        if (!$DB->record_exists('local_kbacorp_member', [
            'companyid' => $companyid,
            'userid' => (int)$issue->userid,
            'isemployee' => 1,
        ])) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }

        $maps = $DB->get_records('local_kbacorp_course', [
            'companyid' => $companyid,
            'courseid' => (int)$issue->courseid,
            'active' => 1,
        ]);
        $allowed = false;
        foreach ($maps as $map) {
            if (!(int)$map->groupid || $DB->record_exists('groups_members', [
                'groupid' => (int)$map->groupid,
                'userid' => (int)$issue->userid,
            ])) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }

        $cm = get_coursemodule_from_instance('customcert', (int)$issue->customcertid, (int)$issue->courseid, false, MUST_EXIST);
        $issue->cmid = (int)$cm->id;
        $issue->fullname = fullname($DB->get_record('user', ['id' => (int)$issue->userid], '*', MUST_EXIST));
        return $issue;
    }

    /**
     * Generate an already-issued PDF using the installed mod_customcert API.
     *
     * Supports the Moodle 4.0 stable API and the newer service-based API.
     * The caller must validate corporate access before invoking this method.
     *
     * @param \stdClass $issue Record returned by get_company_issue().
     * @return void
     */
    public static function output_pdf(\stdClass $issue): void {
        global $DB;

        if (!self::is_available()) {
            throw new \moodle_exception('customcertnotavailable', 'local_kbacorporate');
        }
        if (!$DB->record_exists('customcert_issues', [
            'id' => (int)$issue->issueid,
            'userid' => (int)$issue->userid,
            'customcertid' => (int)$issue->customcertid,
        ])) {
            throw new \moodle_exception('certificateissuegone', 'local_kbacorporate');
        }

        \core\session\manager::write_close();

        // Newer Custom certificate releases use a PDF service and repository.
        if (class_exists('\\mod_customcert\\service\\pdf_generation_service')
                && class_exists('\\mod_customcert\\service\\template_repository')
                && class_exists('\\mod_customcert\\template')
                && method_exists('\\mod_customcert\\template', 'from_record')) {
            $repository = new \mod_customcert\service\template_repository();
            $record = $repository->get_by_id_or_fail((int)$issue->templateid);
            $template = \mod_customcert\template::from_record($record);
            $service = \mod_customcert\service\pdf_generation_service::create();
            $service->generate_pdf($template, false, (int)$issue->userid);
            exit;
        }

        // Moodle 4.0-era Custom certificate API.
        if (class_exists('\\mod_customcert\\template')) {
            $templaterecord = $DB->get_record('customcert_templates', ['id' => (int)$issue->templateid], '*', MUST_EXIST);
            $template = new \mod_customcert\template($templaterecord);
            if (method_exists($template, 'generate_pdf')) {
                $template->generate_pdf(false, (int)$issue->userid);
                exit;
            }
        }

        throw new \moodle_exception('customcertpdfapiunsupported', 'local_kbacorporate');
    }
}
