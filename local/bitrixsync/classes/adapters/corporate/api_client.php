<?php
namespace local_bitrixsync\adapters\corporate;

defined('MOODLE_INTERNAL') || die();

/**
 * Safe facade over KBA Corporate Dashboard's documented Public API.
 *
 * KBA Connect must never read/write local_kbacorporate tables directly.
 * The supported integration boundary in Corporate Dashboard 0.13.0 is
 * \local_kbacorporate\local\bridge, API v1.9.
 */
class api_client {
    private const BRIDGE_CLASS = '\local_kbacorporate\local\bridge';
    private const REQUIRED_API_MAJOR = 1;
    private const REQUIRED_API_RELEASE = '1.9';
    private const ROLE_SCHEMA_API_RELEASE = '1.10';

    public function is_enabled(): bool {
        return (bool)get_config('local_bitrixsync', 'corporate_enable');
    }

    public function is_available(): bool {
        return class_exists(self::BRIDGE_CLASS);
    }

    public function contract(): array {
        if (!$this->is_available()) {
            return [
                'component' => 'local_kbacorporate',
                'api_version' => 0,
                'api_release' => '0',
                'methods' => [],
            ];
        }

        $class = self::BRIDGE_CLASS;
        $contract = $class::contract();
        return is_array($contract) ? $contract : [];
    }

    public function status(): array {
        if (!$this->is_available()) {
            return [
                'enabled' => false,
                'success' => false,
                'available' => false,
                'component' => 'local_kbacorporate',
                'api_version' => 0,
                'api_release' => '0',
                'writesafe' => false,
                'licenseavailable' => false,
            ];
        }

        $class = self::BRIDGE_CLASS;
        $status = $class::status();
        $status['available'] = true;
        return $status;
    }

    public function is_api_compatible(): bool {
        $contract = $this->contract();
        return (int)($contract['api_version'] ?? 0) === self::REQUIRED_API_MAJOR
            && version_compare((string)($contract['api_release'] ?? '0'), self::REQUIRED_API_RELEASE, '>=');
    }

    /** Corporate role schema contract introduced in Public API v1.10. */
    public function is_role_schema_compatible(): bool {
        $contract = $this->contract();
        return (int)($contract['api_version'] ?? 0) === self::REQUIRED_API_MAJOR
            && version_compare((string)($contract['api_release'] ?? '0'), self::ROLE_SCHEMA_API_RELEASE, '>=')
            && method_exists(self::BRIDGE_CLASS, 'get_bitrix_role_schema');
    }

    public function get_role_schema(): array {
        if (!$this->is_available()) {
            throw new \moodle_exception('corporate_not_available', 'local_bitrixsync');
        }
        if (!$this->is_role_schema_compatible()) {
            $contract = $this->contract();
            throw new \moodle_exception(
                'corporate_role_schema_api_incompatible',
                'local_bitrixsync',
                '',
                (string)($contract['api_release'] ?? '0')
            );
        }
        $class = self::BRIDGE_CLASS;
        $schema = $class::get_bitrix_role_schema();
        return is_array($schema) ? $schema : [];
    }

    /**
     * Safe MERGE-only role handoff for Dashboard API v1.10+.
     * If the Dashboard does not yet expose the merge endpoint, core enrolment
     * remains untouched and the role sync is reported as skipped.
     */
    public function merge_employee_roles(int $userid, int $companyid, array $roles): array {
        if (!$this->is_available() || !$this->is_role_schema_compatible()) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'corporate_role_api_unavailable',
            ];
        }
        $class = self::BRIDGE_CLASS;
        if (!method_exists($class, 'merge_employee_roles')) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'merge_employee_roles_not_exposed',
            ];
        }
        return $class::merge_employee_roles($userid, $companyid, array_values(array_unique($roles)));
    }

    public function assert_write_ready(): void {
        if (!$this->is_enabled()) {
            throw new \moodle_exception('corporate_disabled', 'local_bitrixsync');
        }
        if (!$this->is_available()) {
            throw new \moodle_exception('corporate_not_available', 'local_bitrixsync');
        }
        if (!$this->is_api_compatible()) {
            $contract = $this->contract();
            throw new \moodle_exception(
                'corporate_api_incompatible',
                'local_bitrixsync',
                '',
                (string)($contract['api_release'] ?? '0')
            );
        }

        $status = $this->status();
        if (empty($status['writesafe'])) {
            throw new \moodle_exception('corporate_not_writesafe', 'local_bitrixsync');
        }
        if (empty($status['licenseavailable'])) {
            throw new \moodle_exception('corporate_license_unavailable', 'local_bitrixsync');
        }
    }


    /** Employee/company sync does not consume a license seat by itself. */
    public function assert_employee_write_ready(): void {
        if (!$this->is_enabled()) {
            throw new \moodle_exception('corporate_disabled', 'local_bitrixsync');
        }
        if (!$this->is_available()) {
            throw new \moodle_exception('corporate_not_available', 'local_bitrixsync');
        }
        if (!$this->is_api_compatible()) {
            $contract = $this->contract();
            throw new \moodle_exception(
                'corporate_api_incompatible',
                'local_bitrixsync',
                '',
                (string)($contract['api_release'] ?? '0')
            );
        }
        $status = $this->status();
        if (empty($status['writesafe'])) {
            throw new \moodle_exception('corporate_not_writesafe', 'local_bitrixsync');
        }
    }

    public function sync_company(int $bitrixcompanyid, string $companytitle): array {
        $this->assert_write_ready();
        $class = self::BRIDGE_CLASS;
        return $class::sync_company($bitrixcompanyid, $companytitle);
    }

    public function sync_employee(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $bitrixcontactid = 0
    ): array {
        $this->assert_employee_write_ready();
        $class = self::BRIDGE_CLASS;
        return $class::sync_employee($userid, $bitrixcompanyid, $companytitle, $bitrixcontactid);
    }

    public function remove_employee(int $userid, int $bitrixcompanyid): array {
        $this->assert_employee_write_ready();
        $class = self::BRIDGE_CLASS;
        if (!method_exists($class, 'remove_employee')) {
            return ['success' => false, 'reason' => 'remove_employee_not_exposed'];
        }
        return $class::remove_employee($userid, $bitrixcompanyid);
    }

    public function sync_course_license(
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid,
        int $seats,
        int $datefrom = 0,
        int $dateto = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        $this->assert_write_ready();
        $class = self::BRIDGE_CLASS;
        return $class::sync_course_license(
            $bitrixcompanyid,
            $companytitle,
            $courseid,
            $groupid,
            $seats,
            $datefrom,
            $dateto,
            $externaldealid,
            $externalrowid
        );
    }

    public function assign_license_user(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        $this->assert_write_ready();
        $class = self::BRIDGE_CLASS;
        return $class::assign_license_user(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            $externaldealid,
            $externalrowid
        );
    }

    public function release_license_user(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid = 0,
        string $externaldealid = '',
        string $externalrowid = ''
    ): array {
        if (!$this->is_available() || !$this->is_api_compatible()) {
            return ['success' => false, 'released' => false];
        }
        $class = self::BRIDGE_CLASS;
        return $class::release_license_user(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            $externaldealid,
            $externalrowid
        );
    }


    public function get_license(
        int $bitrixcompanyid,
        string $externaldealid,
        string $externalrowid = '',
        int $courseid = 0,
        int $groupid = 0
    ): array {
        if (!$this->is_available() || !$this->is_api_compatible()) {
            return [
                'success' => false,
                'found' => false,
                'available' => false,
            ];
        }

        $class = self::BRIDGE_CLASS;
        return $class::get_license(
            $bitrixcompanyid,
            $externaldealid,
            $externalrowid,
            $courseid,
            $groupid
        );
    }

    public function get_company_snapshot(int $bitrixcompanyid): array {
        if (!$this->is_available() || !$this->is_api_compatible()) {
            return [
                'success' => false,
                'found' => false,
                'courses' => [],
                'coursecount' => 0,
                'activecoursecount' => 0,
                'employeecount' => 0,
            ];
        }

        $class = self::BRIDGE_CLASS;
        return $class::get_company_snapshot($bitrixcompanyid);
    }

    public function get_license_snapshot(int $bitrixcompanyid): array {
        if (!$this->is_available() || !$this->is_api_compatible()) {
            return [
                'success' => false,
                'found' => false,
                'available' => false,
                'licenses' => [],
            ];
        }
        $class = self::BRIDGE_CLASS;
        return $class::get_license_snapshot($bitrixcompanyid);
    }
}
