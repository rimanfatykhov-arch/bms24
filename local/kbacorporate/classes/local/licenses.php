<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Corporate course licence, seat-allocation and contract analytics service.
 *
 * Commercial contract values are stored independently from Moodle enrolments.
 * BMS Connect may write through the public bridge; administrators may maintain
 * the same records manually. A seat allocation is never inferred solely from a
 * course enrolment, except for the one-off legacy migration performed on upgrade.
 */
class licenses {
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BITRIX = 'bitrix';
    public const SOURCE_LEGACY = 'legacy';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOCATION_ASSIGNED = 'assigned';
    public const ALLOCATION_STARTED = 'started';
    public const ALLOCATION_COMPLETED = 'completed';
    public const ALLOCATION_RELEASED = 'released';

    /** Return true when the two v0.12.0 tables are present. */
    public static function is_available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_kbacorp_license')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacorp_license_user'));
    }

    /** Normalise a persisted licence source. */
    public static function normalise_source(string $source): string {
        return in_array($source, [self::SOURCE_MANUAL, self::SOURCE_BITRIX, self::SOURCE_LEGACY], true)
            ? $source : self::SOURCE_MANUAL;
    }

    /** Normalise a persisted licence status. */
    public static function normalise_status(string $status): string {
        return in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_CANCELLED], true)
            ? $status : self::STATUS_ACTIVE;
    }

    /** Normalise a persisted allocation status. */
    public static function normalise_allocation_status(string $status): string {
        return in_array($status, [
            self::ALLOCATION_ASSIGNED,
            self::ALLOCATION_STARTED,
            self::ALLOCATION_COMPLETED,
            self::ALLOCATION_RELEASED,
        ], true) ? $status : self::ALLOCATION_ASSIGNED;
    }

    /**
     * Create or update a licence from an administrator form.
     *
     * @param \stdClass $data
     * @return int
     */
    public static function save_license(\stdClass $data): int {
        global $DB;

        $companyid = (int)($data->companyid ?? 0);
        $courseid = (int)($data->courseid ?? 0);
        $groupid = max(0, (int)($data->groupid ?? 0));
        self::validate_company_course_group($companyid, $courseid, $groupid);

        $datefrom = max(0, (int)($data->datefrom ?? 0));
        $dateto = max(0, (int)($data->dateto ?? 0));
        if ($datefrom && $dateto && $dateto < $datefrom) {
            throw new \moodle_exception('licensedateinvalid', 'local_kbacorporate');
        }

        $source = self::normalise_source((string)($data->source ?? self::SOURCE_MANUAL));
        $externaldealid = trim((string)($data->externaldealid ?? ''));
        $externalrowid = trim((string)($data->externalrowid ?? ''));
        $id = max(0, (int)($data->id ?? 0));

        if ($source === self::SOURCE_BITRIX) {
            if ($externaldealid === '' && $externalrowid === '') {
                throw new \moodle_exception('licenseexternalrefrequired', 'local_kbacorporate');
            }
            $duplicate = self::find_external_license(
                $companyid,
                $externaldealid,
                $externalrowid,
                $id,
                $courseid,
                $groupid
            );
            if ($duplicate) {
                throw new \moodle_exception('licenseduplicateexternalref', 'local_kbacorporate');
            }
        }

        // Every licence also keeps the company's corporate course catalogue active.
        repository::add_course($companyid, $courseid, $groupid);

        $now = time();
        $record = (object)[
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'seats' => max(0, (int)($data->seats ?? 0)),
            'datefrom' => $datefrom,
            'dateto' => $dateto,
            'source' => $source,
            'externaldealid' => $externaldealid,
            'externalrowid' => $externalrowid,
            'status' => self::normalise_status((string)($data->status ?? self::STATUS_ACTIVE)),
            'timemodified' => $now,
        ];

        if ($id) {
            $existing = $DB->get_record('local_kbacorp_license', ['id' => $id, 'companyid' => $companyid], '*', MUST_EXIST);
            if (((int)$existing->courseid !== $courseid || (int)$existing->groupid !== $groupid) &&
                    self::has_active_allocations((int)$existing->id)) {
                throw new \moodle_exception('licensecoursechangeblocked', 'local_kbacorporate');
            }
            $record->id = (int)$existing->id;
            $DB->update_record('local_kbacorp_license', $record);
            return (int)$record->id;
        }

        $record->timecreated = $now;
        return (int)$DB->insert_record('local_kbacorp_license', $record);
    }

    /**
     * Create/update an idempotent Bitrix24 licence package.
     *
     * @return array licence, created, changed, migratedallocations
     */
    public static function sync_external_license(
        int $companyid,
        int $courseid,
        int $groupid,
        int $seats,
        int $datefrom,
        int $dateto,
        string $externaldealid,
        string $externalrowid
    ): array {
        global $DB;

        self::validate_company_course_group($companyid, $courseid, $groupid);
        $externaldealid = trim($externaldealid);
        $externalrowid = trim($externalrowid);
        if ($externaldealid === '' && $externalrowid === '') {
            throw new \moodle_exception('licenseexternalrefrequired', 'local_kbacorporate');
        }
        if ($datefrom < 0 || $dateto < 0 || ($datefrom && $dateto && $dateto < $datefrom)) {
            throw new \moodle_exception('licensedateinvalid', 'local_kbacorporate');
        }

        repository::add_course($companyid, $courseid, $groupid);
        $existing = self::find_external_license(
            $companyid, $externaldealid, $externalrowid, 0, $courseid, $groupid
        );
        $now = time();
        $record = (object)[
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => max(0, $groupid),
            'seats' => max(0, $seats),
            'datefrom' => max(0, $datefrom),
            'dateto' => max(0, $dateto),
            'source' => self::SOURCE_BITRIX,
            'externaldealid' => $externaldealid,
            'externalrowid' => $externalrowid,
            'status' => self::STATUS_ACTIVE,
            'timemodified' => $now,
        ];
        $created = false;
        $changed = false;

        if ($existing) {
            if (((int)$existing->courseid !== $courseid || (int)$existing->groupid !== max(0, $groupid)) &&
                    self::has_active_allocations((int)$existing->id)) {
                throw new \moodle_exception('licensecoursechangeblocked', 'local_kbacorporate');
            }
            $record->id = (int)$existing->id;
            foreach (['courseid', 'groupid', 'seats', 'datefrom', 'dateto', 'source',
                         'externaldealid', 'externalrowid', 'status'] as $field) {
                if ((string)$existing->{$field} !== (string)$record->{$field}) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $DB->update_record('local_kbacorp_license', $record);
            }
            $licenseid = (int)$record->id;
        } else {
            $record->timecreated = $now;
            $licenseid = (int)$DB->insert_record('local_kbacorp_license', $record);
            $created = true;
            $changed = true;
        }

        // When a real contract arrives after an older course-only integration,
        // transfer existing active legacy seat allocations to the first package.
        $migrated = self::migrate_legacy_allocations($licenseid);
        if ($migrated > 0) {
            $changed = true;
        }

        return [
            'license' => self::get_license($licenseid, $companyid),
            'created' => $created,
            'changed' => $changed,
            'migratedallocations' => $migrated,
        ];
    }

    /** Find a Bitrix package by external deal/product-row reference. */
    public static function find_external_license(
        int $companyid,
        string $externaldealid,
        string $externalrowid,
        int $excludeid = 0,
        int $courseid = 0,
        int $groupid = 0
    ) {
        global $DB;

        $conditions = ['companyid = :companyid', 'source = :source'];
        $params = ['companyid' => $companyid, 'source' => self::SOURCE_BITRIX];
        if ($externalrowid !== '') {
            $conditions[] = 'externalrowid = :externalrowid';
            $params['externalrowid'] = $externalrowid;
            if ($externaldealid !== '') {
                $conditions[] = 'externaldealid = :externaldealid';
                $params['externaldealid'] = $externaldealid;
            }
        } else {
            $conditions[] = 'externaldealid = :externaldealid';
            $params['externaldealid'] = $externaldealid;
            // A Bitrix24 deal may contain several products. When Product Row ID
            // is unavailable, course/group complete the idempotency key.
            if ($courseid > 0) {
                $conditions[] = 'courseid = :courseid';
                $conditions[] = 'groupid = :groupid';
                $params['courseid'] = $courseid;
                $params['groupid'] = max(0, $groupid);
            }
        }
        if ($excludeid > 0) {
            $conditions[] = 'id <> :excludeid';
            $params['excludeid'] = $excludeid;
        }

        $records = $DB->get_records_select(
            'local_kbacorp_license',
            implode(' AND ', $conditions),
            $params,
            'id ASC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : false;
    }

    /** Return one joined and calculated licence row. */
    public static function get_license(int $licenseid, int $companyid = 0) {
        global $DB;

        $params = ['licenseid' => $licenseid];
        $companysql = '';
        if ($companyid > 0) {
            $companysql = ' AND l.companyid = :companyid';
            $params['companyid'] = $companyid;
        }
        $sql = "SELECT l.*, c.fullname, c.shortname, c.idnumber, c.visible,
                       g.name AS groupname
                  FROM {local_kbacorp_license} l
                  JOIN {course} c ON c.id = l.courseid
             LEFT JOIN {groups} g ON g.id = l.groupid
                 WHERE l.id = :licenseid{$companysql}";
        $license = $DB->get_record_sql($sql, $params);
        if (!$license) {
            return false;
        }
        return self::enrich($license);
    }

    /** Return all company licence packages with calculated usage. */
    public static function get_company_licenses(int $companyid, bool $includeinactive = true): array {
        global $DB;

        $statussql = $includeinactive ? '' : ' AND l.status = :activestatus';
        $params = ['companyid' => $companyid];
        if (!$includeinactive) {
            $params['activestatus'] = self::STATUS_ACTIVE;
        }
        $sql = "SELECT l.*, c.fullname, c.shortname, c.idnumber, c.visible,
                       g.name AS groupname
                  FROM {local_kbacorp_license} l
                  JOIN {course} c ON c.id = l.courseid
             LEFT JOIN {groups} g ON g.id = l.groupid
                 WHERE l.companyid = :companyid{$statussql}
              ORDER BY c.fullname ASC, l.groupid ASC, l.datefrom ASC, l.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $id => $row) {
            $rows[$id] = self::enrich($row);
        }
        return $rows;
    }

    /** Return licence packages matching one course/group mapping. */
    public static function get_course_licenses(int $companyid, int $courseid, int $groupid = 0): array {
        $result = [];
        foreach (self::get_company_licenses($companyid) as $license) {
            if ((int)$license->courseid === $courseid && (int)$license->groupid === $groupid) {
                $result[$license->id] = $license;
            }
        }
        return $result;
    }

    /** Add calculated allocation/capacity/date fields to a licence row. */
    public static function enrich(\stdClass $license): \stdClass {
        global $DB;

        $license->used = (int)$DB->count_records_select(
            'local_kbacorp_license_user',
            'licenseid = :licenseid AND status <> :released',
            ['licenseid' => (int)$license->id, 'released' => self::ALLOCATION_RELEASED]
        );
        $license->released = (int)$DB->count_records('local_kbacorp_license_user', [
            'licenseid' => (int)$license->id,
            'status' => self::ALLOCATION_RELEASED,
        ]);
        $license->unlimited = ((int)$license->seats === 0);
        $license->available = $license->unlimited ? null : max(0, (int)$license->seats - (int)$license->used);
        $license->overbooked = !$license->unlimited && (int)$license->used > (int)$license->seats;
        $license->utilizationpercent = $license->unlimited || (int)$license->seats <= 0
            ? null : round(((int)$license->used / (int)$license->seats) * 100, 1);

        $today = usergetmidnight(time());
        $license->daysleft = null;
        if ((int)$license->dateto > 0) {
            $license->daysleft = (int)floor(((int)$license->dateto - $today) / DAYSECS);
        }
        $warningdays = self::config_int('licenseexpiringdays', 30, 1, 3650);
        $criticaldays = self::config_int('licensecriticaldays', 7, 1, 3650);
        $license->expiring = (string)$license->status === self::STATUS_ACTIVE &&
            $license->daysleft !== null && $license->daysleft >= 0 && $license->daysleft <= $warningdays;
        $license->critical = $license->expiring && $license->daysleft <= $criticaldays;
        $license->effectivestatus = self::effective_status($license, $today);
        return $license;
    }

    /** Calculate the current effective status without overwriting contract history. */
    private static function effective_status(\stdClass $license, int $today): string {
        $stored = self::normalise_status((string)$license->status);
        if ($stored !== self::STATUS_ACTIVE) {
            return $stored;
        }
        if ((int)$license->dateto > 0 && (int)$license->dateto < $today) {
            return 'expired';
        }
        // Capacity problems take precedence over an upcoming start date: seats
        // can be reserved before the contract begins and must still be blocked.
        if (!empty($license->overbooked)) {
            return 'overbooked';
        }
        if (empty($license->unlimited) && (int)$license->available <= 0) {
            return 'full';
        }
        if ((int)$license->datefrom > 0 && (int)$license->datefrom > $today) {
            return 'upcoming';
        }
        return self::STATUS_ACTIVE;
    }

    /**
     * Aggregate company licence KPIs.
     *
     * Capacity KPIs describe the current contract portfolio: active, full,
     * overbooked and upcoming packages. Expired, inactive and cancelled package
     * history remains counted in the package/status counters but does not inflate
     * the company's currently purchased/free seat totals.
     */
    public static function summary(array $licenses): \stdClass {
        $summary = (object)[
            'licenses' => count($licenses),
            'activelicenses' => 0,
            'finitelicenses' => 0,
            'unlimitedlicenses' => 0,
            'totalseats' => 0,
            'used' => 0,
            'usedfinite' => 0,
            'usedunlimited' => 0,
            'free' => 0,
            'utilizationpercent' => null,
            'fulllicenses' => 0,
            'overbookedlicenses' => 0,
            'expiringlicenses' => 0,
            'expiringseats' => 0,
            'expiredlicenses' => 0,
            'upcominglicenses' => 0,
        ];
        foreach ($licenses as $license) {
            $effectivestatus = (string)$license->effectivestatus;
            $capacitycurrent = in_array($effectivestatus, [
                self::STATUS_ACTIVE,
                'full',
                'overbooked',
                'upcoming',
            ], true);

            if ($effectivestatus === 'full') {
                $summary->fulllicenses++;
            } else if ($effectivestatus === 'overbooked') {
                $summary->overbookedlicenses++;
            } else if ($effectivestatus === 'expired') {
                $summary->expiredlicenses++;
            } else if ($effectivestatus === 'upcoming') {
                $summary->upcominglicenses++;
            }

            if (!$capacitycurrent) {
                continue;
            }

            $summary->used += (int)$license->used;
            if ($effectivestatus !== 'upcoming') {
                $summary->activelicenses++;
            }
            if (!empty($license->unlimited)) {
                $summary->unlimitedlicenses++;
                $summary->usedunlimited += (int)$license->used;
            } else {
                $summary->finitelicenses++;
                $summary->totalseats += (int)$license->seats;
                $summary->usedfinite += (int)$license->used;
            }
            if (!empty($license->expiring)) {
                $summary->expiringlicenses++;
                if (!empty($license->seats)) {
                    $summary->expiringseats += (int)$license->seats;
                }
            }
        }
        if ($summary->totalseats > 0) {
            // Free seats are calculated across the finite contract portfolio.
            // This prevents an overbooked package from making total availability
            // look larger merely because another package still has unused seats.
            $summary->free = max(0, $summary->totalseats - $summary->usedfinite);
            $summary->utilizationpercent = round(($summary->usedfinite / $summary->totalseats) * 100, 1);
        }
        return $summary;
    }

    /** Ensure an unlimited compatibility licence exists for an old course mapping. */
    public static function ensure_legacy_license(int $companyid, int $courseid, int $groupid = 0): \stdClass {
        global $DB;

        self::validate_company_course_group($companyid, $courseid, $groupid);
        $existing = $DB->get_record('local_kbacorp_license', [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'source' => self::SOURCE_LEGACY,
        ]);
        if (!$existing) {
            $now = time();
            $id = $DB->insert_record('local_kbacorp_license', (object)[
                'companyid' => $companyid,
                'courseid' => $courseid,
                'groupid' => $groupid,
                'seats' => 0,
                'datefrom' => 0,
                'dateto' => 0,
                'source' => self::SOURCE_LEGACY,
                'externaldealid' => '',
                'externalrowid' => '',
                'status' => self::STATUS_ACTIVE,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return self::get_license((int)$id, $companyid);
        }
        if ((string)$existing->status !== self::STATUS_ACTIVE) {
            $existing->status = self::STATUS_ACTIVE;
            $existing->timemodified = time();
            $DB->update_record('local_kbacorp_license', $existing);
        }
        return self::get_license((int)$existing->id, $companyid);
    }

    /**
     * Assign a user to a specific licence. Duplicate course allocations are
     * prevented across all packages of the same company/course/group.
     *
     * @return array allocation, license, created, changed, existinglicenseid
     */
    public static function assign_user(int $licenseid, int $userid, int $assignedby = 0): array {
        global $DB;

        $license = self::get_license($licenseid);
        if (!$license) {
            throw new \moodle_exception('licensenotfound', 'local_kbacorporate');
        }
        self::validate_user($userid);
        if (!$DB->record_exists('local_kbacorp_member', [
            'companyid' => (int)$license->companyid,
            'userid' => $userid,
            'isemployee' => 1,
        ])) {
            repository::add_member((int)$license->companyid, $userid, 'employee');
        }

        $existingcourseallocation = self::get_active_course_allocation(
            (int)$license->companyid,
            (int)$license->courseid,
            (int)$license->groupid,
            $userid
        );
        if ($existingcourseallocation && (int)$existingcourseallocation->licenseid !== $licenseid) {
            return [
                'allocation' => $existingcourseallocation,
                'license' => self::get_license((int)$existingcourseallocation->licenseid),
                'created' => false,
                'changed' => false,
                'existinglicenseid' => (int)$existingcourseallocation->licenseid,
            ];
        }

        if (!in_array((string)$license->effectivestatus, [self::STATUS_ACTIVE, 'upcoming'], true)) {
            throw new \moodle_exception('licensecannotassignstatus', 'local_kbacorporate', '',
                self::status_label((string)$license->effectivestatus));
        }
        if (empty($license->unlimited) && (int)$license->used >= (int)$license->seats) {
            throw new \moodle_exception('licensefull', 'local_kbacorporate');
        }

        $existing = $DB->get_record('local_kbacorp_license_user', [
            'licenseid' => $licenseid,
            'userid' => $userid,
        ]);
        $now = time();
        if ($existing) {
            if ((string)$existing->status !== self::ALLOCATION_RELEASED) {
                return [
                    'allocation' => $existing,
                    'license' => self::get_license($licenseid),
                    'created' => false,
                    'changed' => false,
                    'existinglicenseid' => $licenseid,
                ];
            }
            $existing->status = self::ALLOCATION_ASSIGNED;
            $existing->assignedby = max(0, $assignedby);
            $existing->timeassigned = $now;
            $existing->timestarted = 0;
            $existing->timecompleted = 0;
            $existing->timereleased = 0;
            $existing->timemodified = $now;
            $DB->update_record('local_kbacorp_license_user', $existing);
            return [
                'allocation' => $existing,
                'license' => self::get_license($licenseid),
                'created' => false,
                'changed' => true,
                'existinglicenseid' => $licenseid,
            ];
        }

        $allocation = (object)[
            'licenseid' => $licenseid,
            'userid' => $userid,
            'status' => self::ALLOCATION_ASSIGNED,
            'assignedby' => max(0, $assignedby),
            'timeassigned' => $now,
            'timestarted' => 0,
            'timecompleted' => 0,
            'timereleased' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $allocation->id = (int)$DB->insert_record('local_kbacorp_license_user', $allocation);
        return [
            'allocation' => $allocation,
            'license' => self::get_license($licenseid),
            'created' => true,
            'changed' => true,
            'existinglicenseid' => $licenseid,
        ];
    }

    /** Assign the best available current/upcoming package, falling back to legacy unlimited. */
    public static function assign_best_available(
        int $companyid,
        int $courseid,
        int $groupid,
        int $userid,
        int $assignedby = 0
    ): array {
        self::validate_company_course_group($companyid, $courseid, $groupid);
        self::validate_user($userid);

        $existing = self::get_active_course_allocation($companyid, $courseid, $groupid, $userid);
        if ($existing) {
            return [
                'allocation' => $existing,
                'license' => self::get_license((int)$existing->licenseid),
                'created' => false,
                'changed' => false,
                'existinglicenseid' => (int)$existing->licenseid,
            ];
        }

        $candidates = array_values(self::get_course_licenses($companyid, $courseid, $groupid));
        $realpackages = array_values(array_filter($candidates, static function($license): bool {
            return (string)$license->source !== self::SOURCE_LEGACY;
        }));

        // Once a real commercial package exists, legacy unlimited compatibility
        // rows must never bypass its quantity or validity limits.
        $selectionpool = $realpackages ?: array_values(array_filter($candidates, static function($license): bool {
            return (string)$license->source === self::SOURCE_LEGACY;
        }));
        usort($selectionpool, [self::class, 'compare_assignment_candidates']);

        $hasfullpackage = false;
        foreach ($selectionpool as $license) {
            if (in_array((string)$license->effectivestatus, ['full', 'overbooked'], true)) {
                $hasfullpackage = true;
            }
            if (!in_array((string)$license->effectivestatus, [self::STATUS_ACTIVE, 'upcoming'], true)) {
                continue;
            }
            if (empty($license->unlimited) && (int)$license->used >= (int)$license->seats) {
                $hasfullpackage = true;
                continue;
            }
            return self::assign_user((int)$license->id, $userid, $assignedby);
        }

        if ($realpackages) {
            if ($hasfullpackage) {
                throw new \moodle_exception('licensefull', 'local_kbacorporate');
            }
            throw new \moodle_exception('licensenovalidpackage', 'local_kbacorporate');
        }

        $legacy = self::ensure_legacy_license($companyid, $courseid, $groupid);
        return self::assign_user((int)$legacy->id, $userid, $assignedby);
    }

    /** Candidate ordering: real current packages, real upcoming, then legacy. */
    public static function compare_assignment_candidates($a, $b): int {
        $today = usergetmidnight(time());
        $rank = static function($license) use ($today): array {
            $legacy = (string)$license->source === self::SOURCE_LEGACY ? 1 : 0;
            $upcoming = (int)$license->datefrom > $today ? 1 : 0;
            $unlimited = !empty($license->unlimited) ? 1 : 0;
            $end = (int)$license->dateto > 0 ? (int)$license->dateto : PHP_INT_MAX;
            return [$legacy, $upcoming, $unlimited, $end, (int)$license->id];
        };
        $arank = $rank($a);
        $brank = $rank($b);
        foreach ($arank as $index => $value) {
            if ($value === $brank[$index]) {
                continue;
            }
            return $value < $brank[$index] ? -1 : 1;
        }
        return 0;
    }

    /** Return an active allocation for the same company/course/group/user. */
    public static function get_active_course_allocation(
        int $companyid,
        int $courseid,
        int $groupid,
        int $userid
    ) {
        global $DB;

        $sql = "SELECT lu.*
                  FROM {local_kbacorp_license_user} lu
                  JOIN {local_kbacorp_license} l ON l.id = lu.licenseid
                 WHERE l.companyid = :companyid
                   AND l.courseid = :courseid
                   AND l.groupid = :groupid
                   AND lu.userid = :userid
                   AND lu.status <> :released
              ORDER BY lu.id ASC";
        $rows = $DB->get_records_sql($sql, [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'groupid' => max(0, $groupid),
            'userid' => $userid,
            'released' => self::ALLOCATION_RELEASED,
        ], 0, 1);
        return $rows ? reset($rows) : false;
    }

    /** Release a seat only while the learner has not started the course. */
    public static function release_user(int $licenseid, int $userid): array {
        global $DB;

        $license = self::get_license($licenseid);
        if (!$license) {
            throw new \moodle_exception('licensenotfound', 'local_kbacorporate');
        }
        $allocation = $DB->get_record('local_kbacorp_license_user', [
            'licenseid' => $licenseid,
            'userid' => $userid,
        ]);
        if (!$allocation || (string)$allocation->status === self::ALLOCATION_RELEASED) {
            return [
                'success' => true,
                'changed' => false,
                'released' => false,
                'license' => $license,
            ];
        }

        $learningstatus = self::learning_status($license, $userid);
        if (in_array($learningstatus, ['inprogress', 'completed'], true) ||
                in_array((string)$allocation->status, [self::ALLOCATION_STARTED, self::ALLOCATION_COMPLETED], true)) {
            throw new \moodle_exception('licensereleasenotallowed', 'local_kbacorporate');
        }

        $allocation->status = self::ALLOCATION_RELEASED;
        $allocation->timereleased = time();
        $allocation->timemodified = time();
        $DB->update_record('local_kbacorp_license_user', $allocation);
        return [
            'success' => true,
            'changed' => true,
            'released' => true,
            'allocation' => $allocation,
            'license' => self::get_license($licenseid),
        ];
    }

    /** Return joined licence allocations, respecting an optional visible-user scope. */
    public static function get_license_allocations(int $licenseid, ?array $visibleuserids = null): array {
        global $DB;

        $license = self::get_license($licenseid);
        if (!$license) {
            return [];
        }
        $params = ['licenseid' => $licenseid];
        $usersql = '';
        if ($visibleuserids !== null) {
            if (!$visibleuserids) {
                return [];
            }
            list($insql, $inparams) = $DB->get_in_or_equal(
                array_values(array_map('intval', $visibleuserids)),
                SQL_PARAMS_NAMED,
                'licuser'
            );
            $usersql = " AND u.id {$insql}";
            $params += $inparams;
        }
        $sql = "SELECT lu.*, u.firstname, u.lastname, u.email, u.deleted
                  FROM {local_kbacorp_license_user} lu
                  JOIN {user} u ON u.id = lu.userid
                 WHERE lu.licenseid = :licenseid{$usersql}
              ORDER BY u.lastname ASC, u.firstname ASC, lu.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $userids = [];
        foreach ($rows as $row) {
            $userids[] = (int)$row->userid;
        }
        $departmentnames = departments::get_department_names_for_users((int)$license->companyid, $userids);
        foreach ($rows as $id => $row) {
            $row->fullname = fullname($row);
            $row->departments = !empty($departmentnames[$row->userid])
                ? implode(', ', $departmentnames[$row->userid]) : '';
            $row->learningstatus = self::learning_status($license, (int)$row->userid);
            $row->effectivestatus = self::effective_allocation_status($row, $row->learningstatus);
            $rows[$id] = $row;
        }
        return $rows;
    }

    /** Return all licence allocations for one employee. */
    public static function get_user_licenses(int $companyid, int $userid): array {
        global $DB;

        $sql = "SELECT lu.id AS allocationid, lu.status AS allocationstatus,
                       lu.timeassigned, lu.timestarted, lu.timecompleted, lu.timereleased,
                       l.*, c.fullname, c.shortname, c.idnumber, c.visible,
                       g.name AS groupname
                  FROM {local_kbacorp_license_user} lu
                  JOIN {local_kbacorp_license} l ON l.id = lu.licenseid
                  JOIN {course} c ON c.id = l.courseid
             LEFT JOIN {groups} g ON g.id = l.groupid
                 WHERE l.companyid = :companyid AND lu.userid = :userid
              ORDER BY c.fullname ASC, l.datefrom ASC, l.id ASC";
        $rows = $DB->get_records_sql($sql, ['companyid' => $companyid, 'userid' => $userid]);
        foreach ($rows as $id => $row) {
            $row = self::enrich($row);
            $row->learningstatus = self::learning_status($row, $userid);
            $row->effectiveallocationstatus = self::effective_allocation_status(
                (object)['status' => $row->allocationstatus],
                $row->learningstatus
            );
            $rows[$id] = $row;
        }
        return $rows;
    }

    /** Determine the user's current Moodle state for the licence course. */
    public static function learning_status(\stdClass $license, int $userid): string {
        $rows = report::get_rows((int)$license->companyid, [$userid]);
        foreach ($rows as $row) {
            if ((int)$row->courseid !== (int)$license->courseid) {
                continue;
            }
            if ((int)$license->groupid !== 0 && (int)$row->groupid !== (int)$license->groupid) {
                continue;
            }
            return (string)$row->status;
        }
        return 'notenrolled';
    }

    /** Translate live Moodle state into the visible seat-allocation state. */
    private static function effective_allocation_status(\stdClass $allocation, string $learningstatus): string {
        if ((string)$allocation->status === self::ALLOCATION_RELEASED) {
            return self::ALLOCATION_RELEASED;
        }
        if ($learningstatus === 'completed') {
            return self::ALLOCATION_COMPLETED;
        }
        if ($learningstatus === 'inprogress') {
            return self::ALLOCATION_STARTED;
        }
        return self::ALLOCATION_ASSIGNED;
    }

    /** Soft-disable a licence, retaining contract and allocation history. */
    public static function deactivate(int $licenseid, int $companyid): void {
        global $DB;
        $license = $DB->get_record('local_kbacorp_license', [
            'id' => $licenseid,
            'companyid' => $companyid,
        ], '*', MUST_EXIST);
        if ((string)$license->status !== self::STATUS_INACTIVE) {
            $license->status = self::STATUS_INACTIVE;
            $license->timemodified = time();
            $DB->update_record('local_kbacorp_license', $license);
        }
    }

    /** Delete all licence data when its owning company is deleted. */
    public static function delete_company_data(int $companyid): void {
        global $DB;
        $licenseids = $DB->get_fieldset_select('local_kbacorp_license', 'id', 'companyid = :companyid', [
            'companyid' => $companyid,
        ]);
        if ($licenseids) {
            list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $licenseids), SQL_PARAMS_NAMED, 'dlicense');
            $DB->delete_records_select('local_kbacorp_license_user', "licenseid {$insql}", $params);
        }
        $DB->delete_records('local_kbacorp_license', ['companyid' => $companyid]);
    }

    /** Company-level licence/contract alerts. */
    public static function alerts(array $licenses): array {
        $alerts = [];
        $usagewarning = self::config_int('licenseusagewarningpercent', 80, 1, 100);
        foreach ($licenses as $license) {
            $severity = '';
            $type = '';
            $reason = '';
            if ((string)$license->effectivestatus === 'overbooked') {
                $severity = 'high';
                $type = 'overbooked';
                $reason = get_string('licensealertoverbooked', 'local_kbacorporate', (object)[
                    'course' => format_string($license->fullname),
                    'used' => (int)$license->used,
                    'seats' => (int)$license->seats,
                ]);
            } else if ((string)$license->effectivestatus === 'full') {
                $severity = 'high';
                $type = 'full';
                $reason = get_string('licensealertfull', 'local_kbacorporate', format_string($license->fullname));
            } else if ((string)$license->effectivestatus === 'expired') {
                $severity = 'medium';
                $type = 'expired';
                $reason = get_string('licensealertexpired', 'local_kbacorporate', format_string($license->fullname));
            } else if (!empty($license->critical)) {
                $severity = 'high';
                $type = 'expiring';
                $reason = get_string('licensealertexpiring', 'local_kbacorporate', (object)[
                    'course' => format_string($license->fullname),
                    'days' => (int)$license->daysleft,
                ]);
            } else if (!empty($license->expiring)) {
                $severity = 'medium';
                $type = 'expiring';
                $reason = get_string('licensealertexpiring', 'local_kbacorporate', (object)[
                    'course' => format_string($license->fullname),
                    'days' => (int)$license->daysleft,
                ]);
            } else if ((string)$license->effectivestatus === self::STATUS_ACTIVE &&
                    $license->utilizationpercent !== null &&
                    (float)$license->utilizationpercent >= $usagewarning) {
                $severity = 'low';
                $type = 'usage';
                $reason = get_string('licensealertusage', 'local_kbacorporate', (object)[
                    'course' => format_string($license->fullname),
                    'percent' => format_float($license->utilizationpercent, 1),
                ]);
            }
            if ($severity === '') {
                continue;
            }
            $alerts[] = (object)[
                'licenseid' => (int)$license->id,
                'courseid' => (int)$license->courseid,
                'coursename' => (string)$license->fullname,
                'groupid' => (int)$license->groupid,
                'type' => $type,
                'severity' => $severity,
                'reason' => $reason,
                'used' => (int)$license->used,
                'seats' => (int)$license->seats,
                'available' => $license->available,
                'daysleft' => $license->daysleft,
            ];
        }
        usort($alerts, static function($a, $b) {
            $ranks = ['high' => 3, 'medium' => 2, 'low' => 1];
            $rank = ($ranks[$b->severity] ?? 0) <=> ($ranks[$a->severity] ?? 0);
            if ($rank !== 0) {
                return $rank;
            }
            return strcasecmp((string)$a->coursename, (string)$b->coursename);
        });
        return $alerts;
    }

    /** Public, stable representation of a licence. */
    public static function to_public(\stdClass $license): array {
        return [
            'licenseid' => (int)$license->id,
            'companyid' => (int)$license->companyid,
            'courseid' => (int)$license->courseid,
            'groupid' => (int)$license->groupid,
            'coursename' => isset($license->fullname) ? (string)$license->fullname : '',
            'groupname' => isset($license->groupname) ? (string)$license->groupname : '',
            'seats' => (int)$license->seats,
            'unlimited' => !empty($license->unlimited),
            'used' => (int)$license->used,
            'available' => $license->available === null ? null : (int)$license->available,
            'utilizationpercent' => $license->utilizationpercent === null
                ? null : (float)$license->utilizationpercent,
            'datefrom' => (int)$license->datefrom,
            'dateto' => (int)$license->dateto,
            'source' => (string)$license->source,
            'externaldealid' => (string)$license->externaldealid,
            'externalrowid' => (string)$license->externalrowid,
            'status' => (string)$license->status,
            'effectivestatus' => (string)$license->effectivestatus,
            'daysleft' => $license->daysleft === null ? null : (int)$license->daysleft,
            'expiring' => !empty($license->expiring),
            'overbooked' => !empty($license->overbooked),
        ];
    }

    /**
     * Reconcile commercial seat allocation with the actual Moodle learning state
     * for one company course. This is read-only: it never changes licences,
     * allocations or enrolments.
     */
    public static function course_reconciliation(
        int $companyid,
        int $courseid,
        int $groupid = 0,
        ?array $visibleuserids = null
    ): \stdClass {
        $licenses = self::get_course_licenses($companyid, $courseid, $groupid);
        $licensesummary = self::summary($licenses);
        $reportrows = report::get_rows($companyid, $visibleuserids);

        $employees = [];
        $enrolled = [];
        $training = [];
        $completed = [];
        $courserows = [];
        foreach ($reportrows as $row) {
            if ((int)$row->courseid !== $courseid || (int)$row->groupid !== $groupid) {
                continue;
            }
            $courserows[(int)$row->userid] = $row;
            $employees[(int)$row->userid] = true;
            if (!empty($row->enrolled)) {
                $enrolled[(int)$row->userid] = true;
            }
            if (in_array((string)$row->status, ['inprogress', 'completed'], true)) {
                $training[(int)$row->userid] = true;
            }
            if ((string)$row->status === 'completed') {
                $completed[(int)$row->userid] = true;
            }
        }

        $allocated = [];
        $allocatedwithoutenrolment = [];
        $packages = [];
        foreach ($licenses as $license) {
            $packageallocations = [];
            foreach (self::get_license_allocations((int)$license->id, $visibleuserids) as $allocation) {
                if ((string)$allocation->effectivestatus === self::ALLOCATION_RELEASED) {
                    continue;
                }
                $userid = (int)$allocation->userid;
                $allocated[$userid] = true;
                if ((string)$allocation->learningstatus === 'notenrolled') {
                    $allocatedwithoutenrolment[$userid] = true;
                }
                $packageallocations[$userid] = true;
            }
            $packages[] = (object)[
                'licenseid' => (int)$license->id,
                'source' => (string)$license->source,
                'externaldealid' => (string)$license->externaldealid,
                'externalrowid' => (string)$license->externalrowid,
                'seats' => (int)$license->seats,
                'allocated' => count($packageallocations),
                'available' => $license->available === null ? null : (int)$license->available,
                'effectivestatus' => (string)$license->effectivestatus,
            ];
        }

        $enrolledwithoutallocation = [];
        foreach ($enrolled as $userid => $unused) {
            if (!isset($allocated[$userid])) {
                $enrolledwithoutallocation[$userid] = true;
            }
        }

        return (object)[
            'employees' => count($employees),
            'enrolled' => count($enrolled),
            'training' => count($training),
            'completed' => count($completed),
            'allocated' => count($allocated),
            'allocatedwithoutenrolment' => count($allocatedwithoutenrolment),
            'enrolledwithoutallocation' => count($enrolledwithoutallocation),
            'purchasedseats' => (int)$licensesummary->totalseats,
            'unlimitedpackages' => (int)$licensesummary->unlimitedlicenses,
            'freeseats' => (int)$licensesummary->free,
            'packages' => $packages,
        ];
    }

    /** Human-readable effective status. */
    public static function status_label(string $status): string {
        $key = 'licensestatus_' . $status;
        return get_string($key, 'local_kbacorporate');
    }

    /** Human-readable source. */
    public static function source_label(string $source): string {
        return get_string('licensesource_' . self::normalise_source($source), 'local_kbacorporate');
    }

    /** Human-readable allocation status. */
    public static function allocation_status_label(string $status): string {
        return get_string('licenseallocation_' . self::normalise_allocation_status($status), 'local_kbacorporate');
    }

    /** Transfer active allocations from matching legacy licences into one real package. */
    private static function migrate_legacy_allocations(int $targetlicenseid): int {
        global $DB;

        $target = $DB->get_record('local_kbacorp_license', ['id' => $targetlicenseid], '*', MUST_EXIST);
        if ((string)$target->source === self::SOURCE_LEGACY) {
            return 0;
        }
        $legacylicenses = $DB->get_records('local_kbacorp_license', [
            'companyid' => (int)$target->companyid,
            'courseid' => (int)$target->courseid,
            'groupid' => (int)$target->groupid,
            'source' => self::SOURCE_LEGACY,
        ], 'id ASC');
        $migrated = 0;
        foreach ($legacylicenses as $legacy) {
            $allocations = $DB->get_records_select(
                'local_kbacorp_license_user',
                'licenseid = :licenseid AND status <> :released',
                ['licenseid' => (int)$legacy->id, 'released' => self::ALLOCATION_RELEASED],
                'id ASC'
            );
            foreach ($allocations as $allocation) {
                $targetallocation = $DB->get_record('local_kbacorp_license_user', [
                    'licenseid' => $targetlicenseid,
                    'userid' => (int)$allocation->userid,
                ]);
                if ($targetallocation) {
                    $allocation->status = self::ALLOCATION_RELEASED;
                    $allocation->timereleased = time();
                    $allocation->timemodified = time();
                    $DB->update_record('local_kbacorp_license_user', $allocation);
                    continue;
                }
                $allocation->licenseid = $targetlicenseid;
                $allocation->timemodified = time();
                $DB->update_record('local_kbacorp_license_user', $allocation);
                $migrated++;
            }
            if (!$DB->record_exists_select('local_kbacorp_license_user',
                    'licenseid = :licenseid AND status <> :released', [
                        'licenseid' => (int)$legacy->id,
                        'released' => self::ALLOCATION_RELEASED,
                    ])) {
                $legacy->status = self::STATUS_INACTIVE;
                $legacy->timemodified = time();
                $DB->update_record('local_kbacorp_license', $legacy);
            }
        }
        return $migrated;
    }

    /** Return whether a package has a non-released seat allocation. */
    private static function has_active_allocations(int $licenseid): bool {
        global $DB;
        return $DB->record_exists_select(
            'local_kbacorp_license_user',
            'licenseid = :licenseid AND status <> :released',
            ['licenseid' => $licenseid, 'released' => self::ALLOCATION_RELEASED]
        );
    }

    /** Validate owner, course and optional group. */
    private static function validate_company_course_group(int $companyid, int $courseid, int $groupid): void {
        global $DB;
        if ($companyid <= 0 || !$DB->record_exists('local_kbacorp_company', ['id' => $companyid])) {
            throw new \moodle_exception('companynotfound', 'local_kbacorporate');
        }
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            throw new \moodle_exception('bridgecoursenotfound', 'local_kbacorporate', '', $courseid);
        }
        if ($groupid < 0) {
            throw new \moodle_exception('bridgeinvalidgroupid', 'local_kbacorporate');
        }
        if ($groupid > 0) {
            $group = $DB->get_record('groups', ['id' => $groupid]);
            if (!$group || (int)$group->courseid !== $courseid) {
                throw new \moodle_exception('bridgegroupcoursemismatch', 'local_kbacorporate');
            }
        }
    }

    /** Validate Moodle user. */
    private static function validate_user(int $userid): void {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('bridgeusernotfound', 'local_kbacorporate', '', $userid);
        }
    }

    /** Read one bounded integer setting. */
    private static function config_int(string $name, int $default, int $min, int $max): int {
        $value = get_config('local_kbacorporate', $name);
        if ($value === false || $value === null || $value === '') {
            $value = $default;
        }
        return max($min, min($max, (int)$value));
    }
}
