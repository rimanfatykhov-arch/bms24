<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Battle Plan coordination without changing another person's plan.
 *
 * A coordination request records a dependency/proposal between two canonical
 * Battle Plans. Agreement is not approval and never edits either plan or task.
 */
class battle_plan_coordination_service {
    public const STATUSES = ['open', 'agreed', 'declined'];

    private static function fail(string $key): void {
        throw new \moodle_exception('bpcoord_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function plan(string $companyuuid, string $planuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'uuid' => $planuuid,
        ]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    private static function can_read(\stdClass $plan, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor ||
            (int)$plan->observerid === $actor;
    }

    private static function can_coordinate_from(\stdClass $plan, int $actor, bool $canmanage): bool {
        // Observer is deliberately read-only.
        return $canmanage || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor;
    }

    private static function can_respond(\stdClass $plan, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor;
    }

    private static function item(string $companyuuid, string $planuuid, string $itemuuid): ?\stdClass {
        global $DB;
        if ($itemuuid === '') { return null; }
        $item = $DB->get_record('local_kbacompany_bplanitem', [
            'companyuuid' => $companyuuid, 'planuuid' => $planuuid, 'uuid' => $itemuuid,
        ]);
        if (!$item) { self::fail('item'); }
        return $item;
    }

    /** Create a proposal/dependency. It never edits either plan. */
    public static function create(string $companyuuid, int $actor, string $sourceplanuuid,
            string $targetplanuuid, string $summary, string $requestedchange = '',
            string $sourceitemuuid = '', string $targetitemuuid = ''): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $summary = trim($summary);
        $requestedchange = trim($requestedchange);
        if ($summary === '' || \core_text::strlen($summary) > 1000) { self::fail('summaryinvalid'); }
        if (\core_text::strlen($requestedchange) > 4000) { self::fail('changeinvalid'); }
        if ($sourceplanuuid === $targetplanuuid) { self::fail('sameplan'); }

        $canmanage = task_service::can_manage($companyuuid, $actor);
        $source = self::plan($companyuuid, $sourceplanuuid);
        $target = self::plan($companyuuid, $targetplanuuid);
        if (!self::can_coordinate_from($source, $actor, $canmanage)) { self::fail('permission'); }
        // Coordination is deliberately period-local in this release.
        if ((string)$source->periodstart !== (string)$target->periodstart ||
                (string)$source->periodend !== (string)$target->periodend) {
            self::fail('period');
        }
        self::item($companyuuid, $sourceplanuuid, trim($sourceitemuuid));
        self::item($companyuuid, $targetplanuuid, trim($targetitemuuid));

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('bpcoord:' . $companyuuid . ':' . $sourceplanuuid . ':' . $targetplanuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $uuid = self::uuid();
            $now = time();
            $DB->insert_record('local_kbacompany_bpcoord', (object)[
                'uuid' => $uuid, 'companyuuid' => $companyuuid,
                'sourceplanuuid' => $sourceplanuuid, 'sourceitemuuid' => trim($sourceitemuuid),
                'targetplanuuid' => $targetplanuuid, 'targetitemuuid' => trim($targetitemuuid),
                'summary' => $summary, 'requestedchange' => $requestedchange,
                'status' => 'open', 'createdby' => $actor, 'respondedby' => 0,
                'response' => '', 'timecreated' => $now, 'timeresponded' => 0,
            ]);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Agree or decline the proposal. No plan/task mutation occurs. */
    public static function respond(string $companyuuid, int $actor, string $uuid,
            string $decision, string $response = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $decision = trim($decision);
        $response = trim($response);
        if (!in_array($decision, ['agree', 'decline'], true)) { self::fail('decision'); }
        if ($decision === 'decline' && $response === '') { self::fail('responseinvalid'); }
        if (\core_text::strlen($response) > 4000) { self::fail('responseinvalid'); }
        $canmanage = task_service::can_manage($companyuuid, $actor);

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('bpcoord:' . $companyuuid . ':' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = $DB->get_record('local_kbacompany_bpcoord', ['companyuuid'=>$companyuuid, 'uuid'=>$uuid]);
            if (!$row) { self::fail('reference'); }
            if ((string)$row->status !== 'open') { self::fail('state'); }
            $target = self::plan($companyuuid, (string)$row->targetplanuuid);
            if (!self::can_respond($target, $actor, $canmanage)) { self::fail('permission'); }
            $row->status = $decision === 'agree' ? 'agreed' : 'declined';
            $row->respondedby = $actor;
            $row->response = $response;
            $row->timeresponded = time();
            $DB->update_record('local_kbacompany_bpcoord', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function user_name(int $userid): string {
        global $DB;
        $u = $DB->get_record('user', ['id'=>$userid], 'id,firstname,lastname');
        return $u ? trim((string)$u->firstname . ' ' . (string)$u->lastname) : (string)$userid;
    }

    private static function position_name(string $companyuuid, string $uuid): string {
        global $DB;
        return (string)($DB->get_field('local_kbacompany_position', 'name', [
            'companyuuid'=>$companyuuid, 'uuid'=>$uuid,
        ]) ?: $uuid);
    }

    /** Read model for one period. Only records connected to plans the actor may read are returned. */
    public static function context(string $companyuuid, int $actor, string $week = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $week = $week !== '' ? battle_plan_service::week_start($week) : battle_plan_service::week_start(gmdate('Y-m-d'));

        $allplans = $DB->get_records('local_kbacompany_bplan', [
            'companyuuid'=>$companyuuid, 'periodstart'=>$week,
        ], 'userid ASC, id ASC');
        $plansbyuuid = [];
        $readable = [];
        $coordinatable = [];
        foreach ($allplans as $plan) {
            $plansbyuuid[(string)$plan->uuid] = $plan;
            if (self::can_read($plan, $actor, $canmanage)) { $readable[(string)$plan->uuid] = $plan; }
            if (self::can_coordinate_from($plan, $actor, $canmanage)) { $coordinatable[(string)$plan->uuid] = $plan; }
        }

        // Target choices expose only plan labels for the same company/week, never task contents.
        $targets = [];
        foreach ($allplans as $plan) {
            $targets[(string)$plan->uuid] = self::user_name((int)$plan->userid) . ' · ' .
                self::position_name($companyuuid, (string)$plan->positionuuid);
        }
        $sources = [];
        foreach ($coordinatable as $plan) {
            $sources[(string)$plan->uuid] = self::user_name((int)$plan->userid) . ' · ' .
                self::position_name($companyuuid, (string)$plan->positionuuid);
        }

        $rows = [];
        foreach ($DB->get_records('local_kbacompany_bpcoord', [
            'companyuuid'=>$companyuuid,
        ], 'timecreated DESC, id DESC') as $row) {
            $source = $plansbyuuid[(string)$row->sourceplanuuid] ?? null;
            $target = $plansbyuuid[(string)$row->targetplanuuid] ?? null;
            // Old-period records are intentionally omitted from this period view.
            if (!$source || !$target) { continue; }
            $visible = isset($readable[(string)$source->uuid]) || isset($readable[(string)$target->uuid]) ||
                self::can_respond($target, $actor, $canmanage);
            if (!$visible) { continue; }
            $rows[] = [
                'uuid'=>(string)$row->uuid,
                'source_plan_uuid'=>(string)$row->sourceplanuuid,
                'source_label'=>$targets[(string)$row->sourceplanuuid] ?? (string)$row->sourceplanuuid,
                'target_plan_uuid'=>(string)$row->targetplanuuid,
                'target_label'=>$targets[(string)$row->targetplanuuid] ?? (string)$row->targetplanuuid,
                'summary'=>(string)$row->summary,
                'requested_change'=>(string)$row->requestedchange,
                'status'=>(string)$row->status,
                'response'=>(string)$row->response,
                'created_by'=>(int)$row->createdby,
                'created_by_name'=>self::user_name((int)$row->createdby),
                'responded_by'=>(int)$row->respondedby,
                'responded_by_name'=>(int)$row->respondedby > 0 ? self::user_name((int)$row->respondedby) : '',
                'time_created'=>(int)$row->timecreated,
                'time_responded'=>(int)$row->timeresponded,
                'can_respond'=>(string)$row->status === 'open' && self::can_respond($target, $actor, $canmanage),
            ];
        }
        return [
            'week_start'=>$week,
            'week_end'=>(new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d'),
            'plan_count'=>count($allplans),
            'sources'=>$sources,
            'targets'=>$targets,
            'rows'=>$rows,
            'can_create'=>!empty($sources) && count($targets) > 1,
        ];
    }
}
