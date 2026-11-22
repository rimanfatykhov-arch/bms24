<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0010: who reviews a daily report, who receives a copy and who may read the company's reports.
 *
 * The organisation chart has two separate entities:
 *  - a unit (local_kbacompany_orgnode.uuid); the next unit up is the applied reporting parent
 *    (local_kbacompany_orgreport.manageruuid, a unit uuid written by design_service) when that row exists,
 *    otherwise the layout parent (orgnode.parentuuid);
 *  - the managers of a unit (people): holders of an active, effective manager position of that unit, plus
 *    "manager" department roles of a Corporate-backed company.
 * The walk never treats a person as a unit. Every read is scoped to one company in SQL. Nothing here writes
 * tasks, positions or the organisation chart.
 */
final class daily_report_reviewer_service {
    public const MAX_LEVELS = 10;
    public const TOP_POLICIES = ['self', 'reviewer'];
    public const CC_POLICIES = ['none', 'upper'];
    /** Reviewer sources stored on a version; 'self_top' is the persisted self-review marker. */
    public const SOURCES = ['battleplan', 'orgstructure', 'top_reviewer', 'self_top', 'admin', 'none'];

    /** Site switch (admin_setting): self review at the top of the chart, owner decision 21.11.2026; default on. */
    public static function selftop_enabled(): bool {
        $value = get_config('local_kbacompany', 'dailyreports_selftop');
        return $value === false || $value === null || (string)$value === '' ? true : (bool)$value;
    }

    /** Site limit (admin_setting) for manual copy recipients of one version. */
    public static function cc_max(): int {
        $value = (int)get_config('local_kbacompany', 'dailyreports_cc_max');
        return $value > 0 ? min($value, 50) : 5;
    }

    /**
     * Units of each person and managers of each unit, both in a deterministic order.
     * Units: the primary assignment first, then by assignment id. Managers: primary assignment first, then lowest user id.
     * @return array [units by userid => string[], managers by unit => int[]]
     */
    public static function graph(string $companyuuid, int $actor): array {
        global $DB;
        $now = time();
        $rows = $DB->get_records_sql("SELECT pa.id, pa.userid, pa.assignmenttype, p.orgnodeuuid, p.ismanager
                FROM {local_kbacompany_posassign} pa
                JOIN {local_kbacompany_position} p ON p.companyuuid = pa.companyuuid AND p.uuid = pa.positionuuid
               WHERE pa.companyuuid = :c AND pa.active = 1 AND p.active = 1 AND p.orgnodeuuid <> :empty
                 AND (pa.effectivefrom = 0 OR pa.effectivefrom <= :nowfrom)
                 AND (pa.effectiveto = 0 OR pa.effectiveto >= :nowto)
            ORDER BY pa.id ASC", ['c' => $companyuuid, 'empty' => '', 'nowfrom' => $now, 'nowto' => $now]);
        $units = []; $managers = [];
        foreach ($rows as $row) {
            $uid = (int)$row->userid;
            $unit = (string)$row->orgnodeuuid;
            $primary = (string)$row->assignmenttype === 'primary';
            $units[$uid][$unit] = max($units[$uid][$unit] ?? 0, $primary ? 2 : 1);
            if (!empty($row->ismanager)) {
                $managers[$unit][$uid] = max($managers[$unit][$uid] ?? 0, $primary ? 2 : 1);
            }
        }
        // Corporate-backed companies: department roles from the authorised organisation snapshot.
        try {
            $employees = organization_service::organization_context($companyuuid, $actor)['employees'] ?? [];
        } catch (\moodle_exception $e) {
            $employees = [];
        }
        foreach ($employees as $employee) {
            $uid = (int)$employee['user_id'];
            foreach ($employee['org_assignments'] ?? [] as $assignment) {
                $unit = (string)($assignment['org_unit_uuid'] ?? '');
                if ($unit === '') {
                    continue;
                }
                $units[$uid][$unit] = $units[$uid][$unit] ?? 1;
                if ((string)($assignment['role'] ?? '') === 'manager') {
                    $managers[$unit][$uid] = $managers[$unit][$uid] ?? 1;
                }
            }
        }
        $order = static function(array $weights): array {
            $keys = array_keys($weights);
            $position = array_flip($keys);
            usort($keys, static function($left, $right) use ($weights, $position): int {
                if ($weights[$left] !== $weights[$right]) {
                    return $weights[$right] <=> $weights[$left];
                }
                return is_int($left) && is_int($right) ? $left <=> $right : $position[$left] <=> $position[$right];
            });
            return $keys;
        };
        $outunits = [];
        foreach ($units as $uid => $weights) {
            $outunits[$uid] = array_map('strval', $order($weights));
        }
        $outmanagers = [];
        foreach ($managers as $unit => $weights) {
            $outmanagers[(string)$unit] = array_map('intval', $order($weights));
        }
        return [$outunits, $outmanagers];
    }

    /** Next unit up the reporting line, or '' at the top. Only an active unit of the same company is followed. */
    public static function reporting_parent(string $companyuuid, string $unit): string {
        global $DB;
        $report = $DB->get_record('local_kbacompany_orgreport', ['companyuuid' => $companyuuid, 'nodeuuid' => $unit],
            'id, manageruuid');
        $parent = $report ? (string)$report->manageruuid : (string)$DB->get_field('local_kbacompany_orgnode',
            'parentuuid', ['companyuuid' => $companyuuid, 'uuid' => $unit]);
        if ($parent === '' || $parent === $unit || !$DB->record_exists('local_kbacompany_orgnode',
                ['companyuuid' => $companyuuid, 'uuid' => $parent, 'active' => 1])) {
            return '';
        }
        return $parent;
    }

    /**
     * Eligible managers above the employee, nearest first: [['userid' => int, 'level' => int], ...].
     * At each unit the employee is not a manager of, its first eligible manager (primary, then lowest id) is taken;
     * a unit the employee manages is passed over (co-managers are peers, not superiors) and the walk goes up.
     * The author, deleted, suspended, revoked and foreign users are never returned.
     */
    public static function chain(string $companyuuid, int $employee, ?array $graph = null): array {
        [$unitsof, $managersof] = $graph ?? self::graph($companyuuid, $employee);
        $units = $unitsof[$employee] ?? [];
        $chain = []; $seen = []; $visited = [];
        for ($level = 0; $units && $level < self::MAX_LEVELS; $level++) {
            $next = [];
            foreach ($units as $unit) {
                if (isset($visited[$unit])) {
                    continue;
                }
                $visited[$unit] = true;
                $managers = $managersof[$unit] ?? [];
                if (!in_array($employee, $managers, true)) {
                    foreach ($managers as $manager) {
                        if (!isset($seen[$manager]) &&
                                daily_report_store_service::eligible_reviewer($companyuuid, $manager, $employee)) {
                            $chain[] = ['userid' => $manager, 'level' => $level];
                            $seen[$manager] = true;
                            break;
                        }
                    }
                }
                $parent = self::reporting_parent($companyuuid, $unit);
                if ($parent !== '' && !isset($visited[$parent])) {
                    $next[$parent] = $parent;
                }
            }
            $units = array_values($next);
        }
        return $chain;
    }

    /** Active member flagged as manager, or manager of at least one unit of the company. */
    public static function is_manager(string $companyuuid, int $actor, ?array $graph = null): bool {
        global $DB;
        if ($actor <= 0) {
            return false;
        }
        if ($DB->record_exists('local_kbacompany_member',
                ['companyuuid' => $companyuuid, 'userid' => $actor, 'active' => 1, 'ismanager' => 1])) {
            return true;
        }
        [, $managersof] = $graph ?? self::graph($companyuuid, $actor);
        foreach ($managersof as $managers) {
            if (in_array($actor, $managers, true)) {
                return true;
            }
        }
        return false;
    }

    /** Company review rules; safe defaults when the company never saved any. */
    public static function settings(string $companyuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_dreportset', ['companyuuid' => $companyuuid]);
        return $row ?: (object)['id' => 0, 'companyuuid' => $companyuuid, 'toppolicy' => 'self', 'topreviewerid' => 0,
            'ccpolicy' => 'none', 'revision' => 0, 'modifiedby' => 0, 'timecreated' => 0, 'timemodified' => 0];
    }

    /** A company administrator changes the review rules; optimistic revision, journaled. */
    public static function save_settings(string $companyuuid, int $actor, string $toppolicy, int $topreviewerid,
            string $ccpolicy, int $expectedrevision): \stdClass {
        global $DB;
        daily_report_store_service::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        if (!daily_report_review_service::is_admin($companyuuid, $actor)) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        if (!in_array($toppolicy, self::TOP_POLICIES, true) || !in_array($ccpolicy, self::CC_POLICIES, true)) {
            throw new \moodle_exception('invalidparameter');
        }
        if ($toppolicy === 'self') {
            $topreviewerid = 0;
        } else if (!daily_report_store_service::eligible_reviewer($companyuuid, $topreviewerid, 0)) {
            throw new \moodle_exception('dailyreports_settingsinvalid', 'local_kbacompany');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('dreportset:' . sha1($companyuuid), 10);
        if (!$lock) {
            throw new \moodle_exception('dailyreports_busy', 'local_kbacompany');
        }
        try {
            $current = self::settings($companyuuid);
            if ((int)$current->revision !== $expectedrevision) {
                throw new \moodle_exception('dailyreports_conflict', 'local_kbacompany');
            }
            $now = time();
            $tx = $DB->start_delegated_transaction();
            try {
                $row = (object)['companyuuid' => $companyuuid, 'toppolicy' => $toppolicy, 'topreviewerid' => $topreviewerid,
                    'ccpolicy' => $ccpolicy, 'revision' => (int)$current->revision + 1, 'modifiedby' => $actor,
                    'timemodified' => $now];
                if ((int)$current->id > 0) {
                    $row->id = (int)$current->id;
                    $DB->update_record('local_kbacompany_dreportset', $row);
                } else {
                    $row->timecreated = $now;
                    $row->id = $DB->insert_record('local_kbacompany_dreportset', $row);
                }
                $DB->insert_record('local_kbacompany_dreportlog', (object)['companyuuid' => $companyuuid,
                    'reportuuid' => '', 'versionuuid' => '', 'actorid' => $actor, 'action' => 'settings_changed',
                    'details' => json_encode(['toppolicy' => $toppolicy, 'topreviewerid' => $topreviewerid,
                        'ccpolicy' => $ccpolicy, 'revision' => (int)$row->revision], JSON_UNESCAPED_UNICODE),
                    'timecreated' => $now]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        return self::settings($companyuuid);
    }

    /**
     * Reviewer after the Battle Plan rule (ADR-0010 D1–D3) and the automatic copy (D5).
     * Order: nearest eligible manager up the chart → company top reviewer → the author, only when the author is at
     * the top (manages a unit or is flagged manager) and the site allows it → nobody ('none', an administrator assigns).
     * @return array ['reviewerid' => int, 'source' => string, 'cc' => int[]]
     */
    public static function resolve(string $companyuuid, int $employee, int $battleplanreviewer = 0): array {
        $graph = self::graph($companyuuid, $employee);
        $chain = self::chain($companyuuid, $employee, $graph);
        $settings = self::settings($companyuuid);
        if ($battleplanreviewer > 0) {
            $reviewer = $battleplanreviewer; $source = 'battleplan';
        } else if ($chain) {
            $reviewer = (int)$chain[0]['userid']; $source = 'orgstructure';
        } else if ((string)$settings->toppolicy === 'reviewer' &&
                daily_report_store_service::eligible_reviewer($companyuuid, (int)$settings->topreviewerid, $employee)) {
            $reviewer = (int)$settings->topreviewerid; $source = 'top_reviewer';
        } else if (self::selftop_enabled() && self::is_manager($companyuuid, $employee, $graph)) {
            $reviewer = $employee; $source = 'self_top';
        } else {
            $reviewer = 0; $source = 'none';
        }
        $cc = [];
        if ((string)$settings->ccpolicy === 'upper' && $reviewer > 0 && $source !== 'self_top') {
            $level = -1;
            foreach ($chain as $entry) {
                if ((int)$entry['userid'] === $reviewer) {
                    $level = (int)$entry['level'];
                }
            }
            foreach ($chain as $entry) {
                if ((int)$entry['level'] > $level && (int)$entry['userid'] !== $reviewer) {
                    $cc[] = (int)$entry['userid'];
                    break;
                }
            }
        }
        return ['reviewerid' => $reviewer, 'source' => $source, 'cc' => $cc];
    }

    /** Manual copy recipients: other active members of this company, unique, within the site limit. */
    public static function clean_cc(string $companyuuid, int $author, array $userids): array {
        $out = [];
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            if ($userid <= 0 || isset($out[$userid])) {
                continue;
            }
            if (!daily_report_store_service::eligible_reviewer($companyuuid, $userid, $author)) {
                throw new \moodle_exception('dailyreports_ccinvalid', 'local_kbacompany');
            }
            $out[$userid] = $userid;
        }
        if (count($out) > self::cc_max()) {
            throw new \moodle_exception('dailyreports_toomanycc', 'local_kbacompany');
        }
        return array_values($out);
    }

    /** People the author may put in copy (and an administrator may pick as top reviewer): other active members. */
    public static function people(string $companyuuid, int $actor, int $exclude): array {
        try {
            $employees = organization_service::organization_context($companyuuid, $actor)['employees'] ?? [];
        } catch (\moodle_exception $e) {
            $employees = [];
        }
        $out = [];
        foreach ($employees as $employee) {
            $id = (int)$employee['user_id'];
            if (daily_report_store_service::eligible_reviewer($companyuuid, $id, $exclude)) {
                $out[$id] = (string)$employee['full_name'];
            }
        }
        asort($out);
        return $out;
    }

    /** Copy rows of one version (frozen once the version is submitted). */
    public static function copies(string $companyuuid, string $versionuuid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_dreportcc',
            ['companyuuid' => $companyuuid, 'versionuuid' => $versionuuid], 'id ASC'));
    }
}
