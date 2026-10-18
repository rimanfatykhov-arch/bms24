<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company values, culture indicators and employee motivation factors. */
class culture_motivation_service {
    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function can_manage(string $companyuuid, int $userid): bool {
        company_context_service::context($companyuuid, $userid);
        return has_capability('local/kbacompany:managepeople', \context_system::instance(), $userid);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:managepeople', 'nopermissions', '');
        }
    }

    public static function save_item(string $companyuuid, int $actorid, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $kind = (string)($data['kind'] ?? 'value');
        if (!in_array($kind, ['value', 'culture', 'motivation'], true)) {
            throw new \moodle_exception('culture_invalid_kind', 'local_kbacompany');
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || \core_text::strlen($title) > 255) {
            throw new \moodle_exception('culture_invalid_title', 'local_kbacompany');
        }
        $desired = max(1, min(5, (int)($data['desiredlevel'] ?? 3)));
        $now = time();
        $DB->insert_record('local_kbacompany_cultitem', (object)[
            'uuid' => self::uuid(), 'companyuuid' => $companyuuid, 'kind' => $kind,
            'title' => $title, 'description' => trim((string)($data['description'] ?? '')),
            'desiredlevel' => $desired, 'active' => 1, 'createdby' => $actorid,
            'modifiedby' => $actorid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    public static function save_score(string $companyuuid, int $userid, int $actorid, string $itemuuid,
            int $score, string $comment = ''): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        if (!$DB->record_exists('local_kbacompany_member',
                ['companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1])) {
            throw new \moodle_exception('bms_employee_not_found', 'local_kbacompany');
        }
        if (!$DB->record_exists('local_kbacompany_cultitem',
                ['companyuuid' => $companyuuid, 'uuid' => $itemuuid, 'active' => 1])) {
            throw new \moodle_exception('culture_item_not_found', 'local_kbacompany');
        }
        $score = max(1, min(5, $score));
        $existing = $DB->get_record('local_kbacompany_cultscore',
            ['companyuuid' => $companyuuid, 'userid' => $userid, 'itemuuid' => $itemuuid]);
        $now = time();
        if ($existing) {
            $existing->score = $score;
            $existing->comment = trim($comment);
            $existing->recordedby = $actorid;
            $existing->timemodified = $now;
            $DB->update_record('local_kbacompany_cultscore', $existing);
            return;
        }
        $DB->insert_record('local_kbacompany_cultscore', (object)[
            'companyuuid' => $companyuuid, 'userid' => $userid, 'itemuuid' => $itemuuid,
            'score' => $score, 'comment' => trim($comment), 'recordedby' => $actorid,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    public static function context(string $companyuuid, int $actorid, int $userid = 0): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actorid);
        $employeesctx = employee_service::employees_context($companyuuid, $actorid, false);
        $employees = $employeesctx['employees'];
        if ($userid <= 0 && $employees) { $userid = (int)$employees[0]['user_id']; }
        $employee = null;
        foreach ($employees as $row) {
            if ((int)$row['user_id'] === $userid) { $employee = $row; break; }
        }
        $items = array_values($DB->get_records('local_kbacompany_cultitem',
            ['companyuuid' => $companyuuid, 'active' => 1], 'kind, title'));
        $scores = [];
        if ($userid > 0) {
            foreach ($DB->get_records('local_kbacompany_cultscore',
                    ['companyuuid' => $companyuuid, 'userid' => $userid]) as $score) {
                $scores[(string)$score->itemuuid] = $score;
            }
        }
        $rows = [];
        $summary = ['value'=>0, 'culture'=>0, 'motivation'=>0, 'gaps'=>0, 'measured'=>0];
        foreach ($items as $item) {
            $summary[$item->kind]++;
            $score = $scores[$item->uuid] ?? null;
            $actual = $score ? (int)$score->score : null;
            $gap = $actual === null ? null : ((int)$item->desiredlevel - $actual);
            if ($actual !== null) { $summary['measured']++; }
            if ($gap !== null && $gap > 0) { $summary['gaps']++; }
            $rows[] = ['uuid'=>(string)$item->uuid, 'kind'=>(string)$item->kind,
                'title'=>(string)$item->title, 'description'=>(string)$item->description,
                'desired'=>(int)$item->desiredlevel, 'actual'=>$actual, 'gap'=>$gap,
                'comment'=>$score ? (string)$score->comment : ''];
        }
        return ['company'=>$company, 'employees'=>$employees, 'employee'=>$employee, 'userid'=>$userid,
            'rows'=>$rows, 'summary'=>$summary, 'can_manage'=>self::can_manage($companyuuid, $actorid)];
    }
}
