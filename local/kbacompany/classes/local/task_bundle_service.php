<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped task bundle templates. Stores only work definitions, never submissions or private messages. */
final class task_bundle_service {
    public const MAX_CHILDREN = 20;
    public const MAX_CHECKS = 50;

    private static function manager(string $company, int $actor): void {
        company_context_service::context($company, $actor);
        if (!task_service::can_manage($company, $actor)) {
            throw new \moodle_exception('taskcenter_permission', 'local_kbacompany');
        }
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** Strict date-only arithmetic avoids server timezone/DST surprises. */
    private static function parse_date(string $date): \DateTimeImmutable {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new \moodle_exception('taskcenter_date', 'local_kbacompany');
        }
        return $dt;
    }

    private static function offset(string $date, \DateTimeImmutable $anchor): ?int {
        if ($date === '') { return null; }
        $days = (int)$anchor->diff(self::parse_date($date))->format('%r%a');
        if ($days < -730 || $days > 1095) {
            throw new \moodle_exception('bms_bundle_date_range', 'local_kbacompany');
        }
        return $days;
    }

    public static function shifted_date(string $date, ?int $offset): string {
        if ($offset === null) { return ''; }
        if ($offset < -730 || $offset > 1095) {
            throw new \moodle_exception('bms_bundle_date_range', 'local_kbacompany');
        }
        return self::parse_date($date)->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private static function snapshot_task(\stdClass $task, \DateTimeImmutable $anchor): array {
        return [
            'title' => (string)$task->title,
            'description' => (string)$task->description,
            'product' => (string)$task->product,
            'stream' => (string)$task->stream,
            'priority' => (string)$task->priority,
            'original_assigneeid' => (int)$task->assigneeid,
            'start_offset' => self::offset((string)$task->startdate, $anchor),
            'due_offset' => self::offset((string)$task->duedate, $anchor),
            'planminutes' => (int)$task->planminutes,
        ];
    }

    private static function checks(string $company, string $taskuuid): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_taskcheck',
            ['companyuuid' => $company, 'taskuuid' => $taskuuid], 'sortorder ASC,id ASC', '*', 0, self::MAX_CHECKS + 1);
        if (count($rows) > self::MAX_CHECKS) {
            throw new \moodle_exception('bms_bundle_limit', 'local_kbacompany');
        }
        $result = [];
        foreach ($rows as $row) { $result[] = (string)$row->itemtext; }
        return $result;
    }

    /** Capture one task and its direct children; do not silently omit deeper descendants. */
    public static function capture(string $company, int $actor, string $taskuuid, string $title): string {
        global $DB;
        self::manager($company, $actor);
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \moodle_exception('taskcenter_invalid', 'local_kbacompany');
        }
        $source = $DB->get_record('local_kbacompany_task',
            ['companyuuid' => $company, 'uuid' => $taskuuid], '*', MUST_EXIST);
        if ($source->status === 'deleted') {
            throw new \moodle_exception('taskcenter_reference', 'local_kbacompany');
        }
        $anchor = self::parse_date($source->startdate !== '' ? (string)$source->startdate : gmdate('Y-m-d'));
        $children = array_values($DB->get_records_select('local_kbacompany_task',
            'companyuuid = :company AND parentuuid = :parent AND status <> :deleted',
            ['company' => $company, 'parent' => $taskuuid, 'deleted' => 'deleted'], 'id ASC', '*', 0, self::MAX_CHILDREN + 1));
        if (count($children) > self::MAX_CHILDREN) {
            throw new \moodle_exception('bms_bundle_limit', 'local_kbacompany');
        }
        $root = self::snapshot_task($source, $anchor);
        $root['checklist'] = self::checks($company, $taskuuid);
        $subtasks = [];
        foreach ($children as $child) {
            if ($DB->record_exists_select('local_kbacompany_task',
                'companyuuid = :company AND parentuuid = :parent AND status <> :deleted',
                ['company' => $company, 'parent' => $child->uuid, 'deleted' => 'deleted'])) {
                throw new \moodle_exception('bms_bundle_nested', 'local_kbacompany');
            }
            $item = self::snapshot_task($child, $anchor);
            $item['checklist'] = self::checks($company, (string)$child->uuid);
            $subtasks[] = $item;
        }
        $payload = json_encode(['version' => 1, 'root' => $root, 'subtasks' => $subtasks],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 60000) {
            throw new \moodle_exception('bms_bundle_limit', 'local_kbacompany');
        }
        $uuid = self::uuid();
        $now = time();
        $DB->insert_record('local_kbacompany_taskbundle', (object)[
            'companyuuid' => $company, 'uuid' => $uuid, 'title' => $title,
            'payload' => $payload, 'status' => 'active', 'createdby' => $actor,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $uuid;
    }

    public static function library(string $company, int $actor, int $page = 0): array {
        global $DB;
        self::manager($company, $actor);
        $page = max(0, min(10000, $page));
        $rows = $DB->get_records('local_kbacompany_taskbundle',
            ['companyuuid' => $company, 'status' => 'active'], 'id DESC', '*', $page * 25, 25);
        return array_values(array_map(static function($row) {
            $snapshot = json_decode((string)$row->payload, true);
            return ['uuid' => (string)$row->uuid, 'title' => (string)$row->title,
                'children' => is_array($snapshot['subtasks'] ?? null) ? count($snapshot['subtasks']) : 0,
                'created' => (int)$row->timecreated];
        }, $rows));
    }

    public static function get(string $company, int $actor, string $templateuuid): array {
        global $DB;
        self::manager($company, $actor);
        $row = $DB->get_record('local_kbacompany_taskbundle',
            ['companyuuid' => $company, 'uuid' => $templateuuid, 'status' => 'active'], '*', MUST_EXIST);
        $data = json_decode((string)$row->payload, true);
        if (!is_array($data) || ($data['version'] ?? 0) !== 1 || !is_array($data['root'] ?? null) ||
                !is_array($data['subtasks'] ?? null) || count($data['subtasks']) > self::MAX_CHILDREN) {
            throw new \moodle_exception('bms_bundle_invalid', 'local_kbacompany');
        }
        return ['row' => $row, 'snapshot' => $data];
    }

    public static function archive(string $company, int $actor, string $templateuuid): void {
        global $DB;
        self::manager($company, $actor);
        $row = $DB->get_record('local_kbacompany_taskbundle',
            ['companyuuid' => $company, 'uuid' => $templateuuid, 'status' => 'active'], '*', MUST_EXIST);
        $DB->update_record('local_kbacompany_taskbundle', (object)[
            'id' => $row->id, 'status' => 'archived', 'timemodified' => time(),
        ]);
    }

    /** Atomic launch: real canonical tasks, freshly unchecked checklist, exactly one receipt. */
    public static function launch(string $company, int $actor, string $templateuuid,
            string $requestkey, int $assigneeid, int $reviewerid, string $startdate,
            bool $originalassignees = false): string {
        global $DB;
        self::manager($company, $actor);
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestkey)) {
            throw new \moodle_exception('bms_bundle_invalid', 'local_kbacompany');
        }
        self::parse_date($startdate);
        $employees = organization_service::organization_context($company, $actor);
        $available = [];
        foreach ($employees['employees'] as $e) { $available[(int)$e['user_id']] = true; }
        if (empty($available[$assigneeid]) || ($reviewerid > 0 && empty($available[$reviewerid]))) {
            throw new \moodle_exception('taskcenter_employee', 'local_kbacompany');
        }
        $fingerprint = hash('sha256', json_encode([$templateuuid, $assigneeid, $reviewerid,
            $startdate, $originalassignees], JSON_THROW_ON_ERROR));
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskbundle:' . $company . ':' . $requestkey, 10);
        if (!$lock) { throw new \moodle_exception('taskcenter_lock', 'local_kbacompany'); }
        try {
            $prior = $DB->get_record('local_kbacompany_taskbrun',
                ['companyuuid' => $company, 'requestkey' => $requestkey]);
            if ($prior) {
                if ((string)$prior->templateuuid !== $templateuuid || (int)$prior->createdby !== $actor ||
                        !hash_equals((string)$prior->fingerprint, $fingerprint)) {
                    throw new \moodle_exception('bms_bundle_invalid', 'local_kbacompany');
                }
                return (string)$prior->parentuuid;
            }
            $data = self::get($company, $actor, $templateuuid)['snapshot'];
            $tx = $DB->start_delegated_transaction();
            try {
                $root = self::create_one($company, $actor, $data['root'], $startdate,
                    $assigneeid, $reviewerid, '', $available, false);
                foreach ($data['subtasks'] as $subtask) {
                    self::create_one($company, $actor, $subtask, $startdate,
                        $assigneeid, $reviewerid, $root, $available, $originalassignees);
                }
                $DB->insert_record('local_kbacompany_taskbrun', (object)[
                    'companyuuid' => $company, 'requestkey' => $requestkey,
                    'fingerprint' => $fingerprint, 'templateuuid' => $templateuuid, 'parentuuid' => $root,
                    'createdby' => $actor, 'timecreated' => time(),
                ]);
                $tx->allow_commit();
                return $root;
            } catch (\Throwable $e) { $tx->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    private static function create_one(string $company, int $actor, array $item, string $startdate,
            int $assigneeid, int $reviewerid, string $parentuuid, array $available,
            bool $originalassignees): string {
        if ($originalassignees && !empty($available[(int)($item['original_assigneeid'] ?? 0)])) {
            $assigneeid = (int)$item['original_assigneeid'];
        }
        $values = [
            'title' => (string)($item['title'] ?? ''),
            'description' => (string)($item['description'] ?? ''),
            'product' => (string)($item['product'] ?? ''),
            'stream' => (string)($item['stream'] ?? 'operate'),
            'priority' => (string)($item['priority'] ?? 'normal'),
            'assigneeid' => $assigneeid, 'reviewerid' => $reviewerid, 'observerid' => 0,
            'startdate' => self::shifted_date($startdate, $item['start_offset'] ?? null) ?: $startdate,
            'duedate' => self::shifted_date($startdate, $item['due_offset'] ?? null),
            'starttime' => '', 'duetime' => '',
            'planminutes' => max(0, min(525600, (int)($item['planminutes'] ?? 0))),
            'parentuuid' => $parentuuid,
        ];
        $taskuuid = task_service::create($company, $actor, $values);
        $checks = $item['checklist'] ?? [];
        if (!is_array($checks) || count($checks) > self::MAX_CHECKS) {
            throw new \moodle_exception('bms_bundle_invalid', 'local_kbacompany');
        }
        foreach ($checks as $text) {
            task_service::add_checklist($company, $actor, $taskuuid, (string)$text, $assigneeid);
        }
        return $taskuuid;
    }
}
