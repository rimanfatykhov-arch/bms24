<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 non-human position occupancy (A0_02 §3). People stay in posassign; an agent occupancy never touches
 * posassign, plannedcount, poscover, task.assigneeid, dutypositionuuid or history. Occupancy gives no rights by
 * itself: rights are grants, checked together with the occupancy.
 */
final class position_occupancy_service {
    public const MODES = ['agent', 'hybrid'];
    private const LIMITS = ['maxopenitems', 'maxrunsperday', 'maxcommandsperhour'];
    private const TRANSITIONS = [
        'approve' => [['proposed'], 'approved'],
        'activate' => [['approved', 'suspended'], 'active'],
        'suspend' => [['active'], 'suspended'],
        'end' => [['approved', 'active', 'suspended'], 'ended'],
        'revoke' => [['proposed', 'approved', 'active', 'suspended'], 'revoked'],
    ];

    /**
     * @param array $functions list of ['uuid' => string, 'revision' => int]; empty means nothing is allowed
     * @param array $limits maxopenitems, maxrunsperday, maxcommandsperhour (non-negative integers)
     */
    public static function propose(string $companyuuid, string $principaluuid, string $positionuuid, string $mode,
            array $functions, array $limits, int $supervisoruserid, int $effectivefrom, int $effectiveto,
            int $actorid): string {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $principaluuid = bmos_registry::principal($companyuuid, $principaluuid)->uuid;
        $positionuuid = bmos_registry::uuid_or_unavailable($positionuuid);
        if (!$DB->record_exists('local_kbacompany_position', ['companyuuid' => $companyuuid, 'uuid' => $positionuuid,
                'active' => 1])) {
            bmos_registry::fail('notavailable');
        }
        if (!in_array($mode, self::MODES, true) || $effectivefrom < 0 || $effectiveto < 0 ||
                ($effectiveto !== 0 && $effectiveto <= $effectivefrom)) {
            bmos_registry::fail('invalid');
        }
        // Invariant 2: an agent-mode post must have a supervising human who is a current member.
        if (($mode === 'agent' || $supervisoruserid !== 0) && !bmos_registry::is_member($companyuuid, $supervisoruserid)) {
            bmos_registry::fail('sponsor');
        }
        // Hybrid: the agent helps a person who currently holds the same post in posassign.
        if ($mode === 'hybrid' && !self::people($companyuuid, $positionuuid, time())) {
            bmos_registry::fail('state');
        }
        $allowed = self::functions($functions);
        $workload = self::limits($limits);
        $uuid = bmos_registry::new_uuid();
        return bmos_registry::locked([$principaluuid], function(int $now) use ($DB, $companyuuid, $principaluuid,
                $positionuuid, $mode, $allowed, $workload, $supervisoruserid, $effectivefrom, $effectiveto, $uuid,
                $actorid) {
            $principal = bmos_registry::principal($companyuuid, $principaluuid);
            if ((string)$principal->principaltype !== principal_registry_service::TYPE_AGENT ||
                    in_array((string)$principal->status, ['revoked', 'archived'], true)) {
                bmos_registry::fail('state');
            }
            $DB->insert_record(bmos_registry::OCCUPANCY, (object)['uuid' => $uuid, 'companyuuid' => $companyuuid,
                'revision' => 1, 'positionuuid' => $positionuuid, 'principaluuid' => $principaluuid,
                'occupancymode' => $mode, 'allowedfunctions' => bmos_registry::json($allowed),
                'workloadlimits' => bmos_registry::json($workload), 'supervisoruserid' => $supervisoruserid,
                'effectivefrom' => $effectivefrom, 'effectiveto' => $effectiveto, 'status' => 'proposed',
                'approvedby' => 0, 'approvedat' => 0, 'createdby' => $actorid, 'timecreated' => $now,
                'timemodified' => $now]);
            self::log($companyuuid, $uuid, 1, 'proposed', 'proposed', $actorid, ['positionuuid' => $positionuuid,
                'principaluuid' => $principaluuid, 'mode' => $mode], $now);
            return $uuid;
        }, false);
    }

    public static function approve(string $companyuuid, string $occupancyuuid, int $expectedrevision, int $actorid): void {
        self::transition($companyuuid, $occupancyuuid, $expectedrevision, $actorid, 'approve');
    }

    public static function activate(string $companyuuid, string $occupancyuuid, int $expectedrevision, int $actorid): void {
        self::transition($companyuuid, $occupancyuuid, $expectedrevision, $actorid, 'activate');
    }

    public static function suspend(string $companyuuid, string $occupancyuuid, int $expectedrevision, int $actorid): void {
        self::transition($companyuuid, $occupancyuuid, $expectedrevision, $actorid, 'suspend');
    }

    public static function end(string $companyuuid, string $occupancyuuid, int $expectedrevision, int $actorid): void {
        self::transition($companyuuid, $occupancyuuid, $expectedrevision, $actorid, 'end');
    }

    public static function revoke(string $companyuuid, string $occupancyuuid, int $expectedrevision, int $actorid): void {
        self::transition($companyuuid, $occupancyuuid, $expectedrevision, $actorid, 'revoke');
    }

    /**
     * OccupancyView (A0_02 §3): human vacancy from plannedcount and current posassign, and agent functional coverage
     * from current active posoccup, returned separately. poscover is not read. Read only, for organisation managers.
     */
    public static function occupancy_view(string $companyuuid, string $positionuuid, int $actorid): array {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $position = $DB->get_record('local_kbacompany_position', ['companyuuid' => $companyuuid,
            'uuid' => bmos_registry::uuid_or_unavailable($positionuuid)], 'id, uuid, plannedcount');
        if (!$position) {
            bmos_registry::fail('notavailable');
        }
        $now = time();
        $people = self::people($companyuuid, (string)$position->uuid, $now);
        $agents = [];
        $covered = [];
        foreach ($DB->get_records_select(bmos_registry::OCCUPANCY,
                "companyuuid = :c AND positionuuid = :p AND status = 'active' AND effectivefrom <= :n1
                 AND (effectiveto = 0 OR effectiveto > :n2)",
                ['c' => $companyuuid, 'p' => $position->uuid, 'n1' => $now, 'n2' => $now], 'id ASC') as $row) {
            $functions = json_decode((string)$row->allowedfunctions, true) ?: [];
            foreach ($functions as $function) {
                $covered[$function['uuid'] . '#' . $function['revision']] = true;
            }
            $agents[] = ['occupancyuuid' => (string)$row->uuid, 'principaluuid' => (string)$row->principaluuid,
                'mode' => (string)$row->occupancymode, 'functions' => count($functions)];
        }
        $planned = (int)$position->plannedcount;
        return [
            'positionuuid' => (string)$position->uuid,
            'people' => ['plannedcount' => $planned, 'userids' => $people,
                'vacant' => max(0, $planned - count($people))],
            'agentcoverage' => ['functions' => count($covered), 'occupancies' => $agents],
        ];
    }

    private static function transition(string $companyuuid, string $occupancyuuid, int $expectedrevision,
            int $actorid, string $action): void {
        global $DB;
        $companyuuid = bmos_registry::require_writer($companyuuid, $actorid);
        $first = self::load($companyuuid, $occupancyuuid);
        [$from, $target] = self::TRANSITIONS[$action];
        bmos_registry::locked([(string)$first->principaluuid], function(int $now) use ($DB, $companyuuid, $first,
                $expectedrevision, $actorid, $action, $from, $target) {
            $row = self::load($companyuuid, (string)$first->uuid);
            if ((int)$row->revision !== $expectedrevision) {
                bmos_registry::fail('conflict');
            }
            if (!in_array((string)$row->status, $from, true)) {
                bmos_registry::fail('state');
            }
            $principal = bmos_registry::principal($companyuuid, (string)$row->principaluuid);
            if (in_array($target, ['approved', 'active'], true)) {
                if (in_array((string)$principal->status, ['revoked', 'archived'], true)) {
                    bmos_registry::fail('state');
                }
                if ((string)$row->occupancymode === 'agent' &&
                        !bmos_registry::is_member($companyuuid, (int)$row->supervisoruserid)) {
                    bmos_registry::fail('sponsor');
                }
                if ($target === 'active' && (string)$row->occupancymode === 'hybrid' &&
                        !self::people($companyuuid, (string)$row->positionuuid, $now)) {
                    bmos_registry::fail('state');
                }
            }
            // Q-09: an agent-mode occupancy is approved by a second person, neither its creator nor the sponsor.
            if ($target === 'approved' && (string)$row->occupancymode === 'agent' &&
                    ($actorid === (int)$row->createdby || $actorid === (int)$principal->sponsoruserid)) {
                bmos_registry::fail('twoperson');
            }
            $update = (object)['id' => $row->id, 'status' => $target, 'revision' => (int)$row->revision + 1,
                'timemodified' => $now];
            if ($target === 'approved') {
                $update->approvedby = $actorid;
                $update->approvedat = $now;
            }
            $DB->update_record(bmos_registry::OCCUPANCY, $update);
            self::log($companyuuid, (string)$row->uuid, (int)$row->revision + 1, $action, $target, $actorid,
                ['from' => (string)$row->status], $now);
            bmos_registry::audit($companyuuid, (string)$row->principaluuid, 'occupancy_' . $action, $actorid,
                ['occupancyuuid' => (string)$row->uuid, 'to' => $target], $now);
        });
    }

    /** Current people of the post from posassign (read only). */
    private static function people(string $companyuuid, string $positionuuid, int $now): array {
        global $DB;
        $people = array_map('intval', $DB->get_fieldset_select('local_kbacompany_posassign', 'DISTINCT userid',
            'companyuuid = :c AND positionuuid = :p AND active = 1 AND userid > 0 AND effectivefrom <= :n1
             AND (effectiveto = 0 OR effectiveto > :n2)',
            ['c' => $companyuuid, 'p' => $positionuuid, 'n1' => $now, 'n2' => $now]));
        sort($people);
        return $people;
    }

    private static function load(string $companyuuid, string $occupancyuuid): \stdClass {
        global $DB;
        $row = $DB->get_record(bmos_registry::OCCUPANCY, ['companyuuid' => $companyuuid,
            'uuid' => bmos_registry::uuid_or_unavailable($occupancyuuid)]);
        if (!$row) {
            bmos_registry::fail('notavailable');
        }
        return $row;
    }

    private static function log(string $companyuuid, string $occupancyuuid, int $revision, string $event,
            string $status, int $actorid, array $details, int $now): void {
        global $DB;
        $DB->insert_record(bmos_registry::OCCUPANCYLOG, (object)['companyuuid' => $companyuuid,
            'occupancyuuid' => $occupancyuuid, 'revision' => $revision, 'eventname' => $event, 'status' => $status,
            'actoruserid' => $actorid, 'details' => bmos_registry::json($details), 'timecreated' => $now]);
    }

    private static function functions(array $functions): array {
        $clean = [];
        foreach ($functions as $function) {
            $uuid = is_array($function) && isset($function['uuid']) && is_string($function['uuid'])
                ? strtolower(trim($function['uuid'])) : '';
            $revision = is_array($function) && isset($function['revision']) ? $function['revision'] : 0;
            if (!bmos_registry::is_uuid($uuid) || !is_int($revision) || $revision < 1) {
                bmos_registry::fail('invalid');
            }
            $clean[$uuid . '#' . $revision] = ['uuid' => $uuid, 'revision' => $revision];
        }
        ksort($clean, SORT_STRING);
        return array_values($clean);
    }

    private static function limits(array $limits): array {
        $clean = [];
        foreach ($limits as $key => $value) {
            if (!in_array($key, self::LIMITS, true) || !is_int($value) || $value < 0) {
                bmos_registry::fail('invalid');
            }
            $clean[$key] = $value;
        }
        ksort($clean, SORT_STRING);
        return $clean;
    }
}
