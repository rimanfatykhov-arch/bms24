<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Canonical Company Philosophy, Decision Principles and Market Assessment domains. */
class company_context_domain_service {
    public const CONTRACT_VERSION = '0.6.9.11';
    public const STATES = ['draft', 'published', 'archived'];
    public const EVIDENCE_STATES = ['fact', 'needsdata', 'hypothesis', 'open'];

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('companycontext_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest(string $table, string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function exact(string $table, string $companyuuid, string $uuid, int $revision): ?\stdClass {
        global $DB;
        if ($revision > 0) {
            return $DB->get_record($table, [
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision,
            ]) ?: null;
        }
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function current_rows(string $table, string $companyuuid, bool $canmanage): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($grouped as $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') {
                continue;
            }
            if ($canmanage) {
                $out[] = $latest;
                continue;
            }
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') {
                    $out[] = $candidate;
                    break;
                }
            }
        }
        return $out;
    }

    private static function next_code(string $companyuuid, string $table, string $kind, string $prefix,
            string $uuid = ''): string {
        global $DB;
        if ($uuid !== '') {
            $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
                'revision DESC', 'stablecode', 0, 1);
            if ($rows) {
                $code = trim((string)reset($rows)->stablecode);
                if ($code !== '') {
                    return $code;
                }
            }
        }
        $minimum = 0;
        foreach ($DB->get_fieldset_select($table, 'stablecode',
                'companyuuid = :companyuuid AND stablecode <> :empty',
                ['companyuuid' => $companyuuid, 'empty' => '']) as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $matches)) {
                $minimum = max($minimum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, $kind, $prefix,
            static function(string $candidate) use ($DB, $table, $companyuuid): bool {
                return !$DB->record_exists($table, ['companyuuid' => $companyuuid, 'stablecode' => $candidate]);
            }, $minimum);
    }

    private static function save_versioned(string $table, string $companyuuid, int $userid, array $data,
            string $uuid, int $expectedrevision, array $fields, string $stablecode = ''): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock(
            'company_context_domain:' . $table . ':' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($table, $companyuuid, $uuid);
                if ((int)$old->revision !== $expectedrevision) {
                    self::fail('conflict');
                }
                if ($old->status === 'archived') {
                    self::fail('state');
                }
            } else if ($expectedrevision !== 0) {
                self::fail('conflict');
            }
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $old ? (string)$old->uuid : self::uuid(),
                'revision' => $old ? ((int)$old->revision + 1) : 1,
                'status' => 'draft',
            ];
            if ($stablecode !== '') {
                $row->stablecode = $stablecode;
            }
            foreach ($fields as $key => $definition) {
                [$max, $required] = $definition;
                $row->$key = self::text($data, $key, $max, $required);
            }
            $now = time();
            $row->createdby = $userid;
            $row->modifiedby = $userid;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record($table, $row);
            $tx->allow_commit();
            return (string)$row->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function publish_versioned(string $table, string $companyuuid, int $userid,
            string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock(
            'company_context_domain:' . $table . ':' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($table, $companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision || $old->status !== 'draft') {
                self::fail('state');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'published';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record($table, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function archive_versioned(string $table, string $companyuuid, int $userid,
            string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $old = self::latest($table, $companyuuid, $uuid);
        if ((int)$old->revision !== $expectedrevision || $old->status === 'archived') {
            self::fail('state');
        }
        $row = clone $old;
        unset($row->id);
        $row->revision = $expectedrevision + 1;
        $row->status = 'archived';
        $row->modifiedby = $userid;
        $row->timemodified = time();
        $DB->insert_record($table, $row);
    }

    private static function dto(\stdClass $row): array {
        $data = (array)$row;
        unset($data['id']);
        return $data;
    }

    public static function context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $manage = self::can_manage($companyuuid, $userid);
        return [
            'company' => $company,
            'can_manage' => $manage,
            'philosophies' => array_map([self::class, 'dto'], self::current_rows('local_kbacompany_philosophy', $companyuuid, $manage)),
            'principles' => array_map([self::class, 'dto'], self::current_rows('local_kbacompany_principle', $companyuuid, $manage)),
            'markets' => array_map([self::class, 'dto'], self::current_rows('local_kbacompany_market', $companyuuid, $manage)),
            'evidence_states' => self::EVIDENCE_STATES,
        ];
    }

    public static function philosophy(string $companyuuid, int $userid, string $uuid, int $revision = 0): array {
        company_context_service::context($companyuuid, $userid);
        $row = self::exact('local_kbacompany_philosophy', $companyuuid, $uuid, $revision);
        if (!$row) {
            self::fail('reference');
        }
        if (!self::can_manage($companyuuid, $userid) && $row->status !== 'published') {
            self::fail('reference');
        }
        return self::dto($row);
    }

    public static function published_philosophies(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_philosophy', [
                'companyuuid' => $companyuuid, 'status' => 'published'], 'uuid ASC, revision DESC') as $row) {
            if (!isset($grouped[$row->uuid])) {
                $grouped[$row->uuid] = self::dto($row);
            }
        }
        return array_values($grouped);
    }

    public static function save_philosophy(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $stable = self::next_code($companyuuid, 'local_kbacompany_philosophy', 'companycontext:philosophy', 'PHL', $uuid);
        return self::save_versioned('local_kbacompany_philosophy', $companyuuid, $userid, $data, $uuid, $revision, [
            'purpose' => [12000, true], 'mission' => [12000, true], 'vision' => [16000, true],
            'horizon' => [64, false], 'notes' => [12000, false],
        ], $stable);
    }

    public static function publish_philosophy(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_philosophy', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_philosophy(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::archive_versioned('local_kbacompany_philosophy', $companyuuid, $userid, $uuid, $revision);
    }

    public static function philosophy_history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            self::fail('reference');
        }
        return array_map([self::class, 'dto'], $DB->get_records('local_kbacompany_philosophy', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC'));
    }

    public static function principles(string $companyuuid, int $userid, string $philosophyuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $manage = self::can_manage($companyuuid, $userid);
        $rows = self::current_rows('local_kbacompany_principle', $companyuuid, $manage);
        $out = [];
        foreach ($rows as $row) {
            if ((string)$row->philosophyuuid === $philosophyuuid) {
                $out[] = self::dto($row);
            }
        }
        usort($out, static function(array $a, array $b): int {
            return ((int)$a['sortorder'] <=> (int)$b['sortorder']) ?: strcmp((string)$a['stablecode'], (string)$b['stablecode']);
        });
        return $out;
    }

    public static function save_principle(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $philosophy = self::text($data, 'philosophyuuid', 36, true);
        $philosophyrevision = (int)($data['philosophyrevision'] ?? 0);
        $root = self::exact('local_kbacompany_philosophy', $companyuuid, $philosophy, $philosophyrevision);
        if (!$root) {
            self::fail('reference');
        }
        $stable = self::next_code($companyuuid, 'local_kbacompany_principle', 'companycontext:principle', 'PRN', $uuid);
        $mapped = $data;
        $mapped['philosophyrevision'] = (string)$root->revision;
        $mapped['sortorder'] = (string)max(0, (int)($data['sortorder'] ?? 0));
        return self::save_versioned('local_kbacompany_principle', $companyuuid, $userid, $mapped, $uuid, $revision, [
            'philosophyuuid' => [36, true], 'philosophyrevision' => [10, true], 'title' => [255, true],
            'formulation' => [12000, true], 'rationale' => [12000, false], 'sortorder' => [10, false],
        ], $stable);
    }

    public static function publish_principle(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_principle', $companyuuid, $userid, $uuid, $revision);
    }

    public static function market(string $companyuuid, int $userid, string $uuid, int $revision = 0): array {
        company_context_service::context($companyuuid, $userid);
        $row = self::exact('local_kbacompany_market', $companyuuid, $uuid, $revision);
        if (!$row) {
            self::fail('reference');
        }
        if (!self::can_manage($companyuuid, $userid) && $row->status !== 'published') {
            self::fail('reference');
        }
        return self::dto($row);
    }

    public static function published_markets(string $companyuuid, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_market', [
                'companyuuid' => $companyuuid, 'status' => 'published'], 'uuid ASC, revision DESC') as $row) {
            if (!isset($grouped[$row->uuid])) {
                $grouped[$row->uuid] = self::dto($row);
            }
        }
        return array_values($grouped);
    }

    public static function save_market(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $stable = self::next_code($companyuuid, 'local_kbacompany_market', 'companycontext:market', 'MKT', $uuid);
        $mapped = $data;
        $mapped['code'] = $stable;
        $mapped['stablecode'] = $stable;
        return self::save_versioned('local_kbacompany_market', $companyuuid, $userid, $mapped, $uuid, $revision, [
            'code' => [50, true], 'name' => [255, true], 'geography' => [255, false], 'description' => [12000, false],
            'productclass' => [255, false], 'clientsegment' => [255, false], 'periodstart' => [10, false],
            'periodend' => [10, false], 'unit' => [64, false], 'totalsize' => [64, false],
            'availablesize' => [64, false], 'targetsize' => [64, false], 'totalcompanies' => [32, false],
            'positionrank' => [32, false], 'companiesabove' => [32, false], 'companiesbelow' => [32, false],
            'peersize' => [32, false], 'marketgrowth' => [32, false], 'companygrowth' => [32, false],
            'salesmarginbenchmark' => [64, false], 'evidencesummary' => [12000, false],
        ], $stable);
    }

    public static function publish_market(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_market', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_market(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::archive_versioned('local_kbacompany_market', $companyuuid, $userid, $uuid, $revision);
    }

    public static function market_history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            self::fail('reference');
        }
        return array_map([self::class, 'dto'], $DB->get_records('local_kbacompany_market', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC'));
    }

    public static function save_evidence(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $marketuuid = self::text($data, 'marketuuid', 36, true);
        $marketrevision = (int)($data['marketrevision'] ?? 0);
        if (!self::exact('local_kbacompany_market', $companyuuid, $marketuuid, $marketrevision)) {
            self::fail('reference');
        }
        $marker = self::text($data, 'marker', 12, true);
        if (!in_array($marker, self::EVIDENCE_STATES, true)) {
            self::fail('invalid');
        }
        $stable = self::next_code($companyuuid, 'local_kbacompany_marketev', 'companycontext:marketevidence', 'EVD', $uuid);
        $mapped = $data;
        $mapped['marketrevision'] = (string)$marketrevision;
        return self::save_versioned('local_kbacompany_marketev', $companyuuid, $userid, $mapped, $uuid, $revision, [
            'marketuuid' => [36, true], 'marketrevision' => [10, true], 'marker' => [12, true], 'title' => [255, true],
            'provider' => [64, false], 'sourceref' => [1000, false], 'period' => [64, false], 'value' => [255, false],
            'unit' => [64, false], 'note' => [12000, false], 'fetchedat' => [16, false],
        ], $stable);
    }

    public static function market_evidence(string $companyuuid, int $userid, string $marketuuid): array {
        company_context_service::context($companyuuid, $userid);
        $rows = self::current_rows('local_kbacompany_marketev', $companyuuid, self::can_manage($companyuuid, $userid));
        $out = [];
        foreach ($rows as $row) {
            if ((string)$row->marketuuid === $marketuuid) {
                $out[] = self::dto($row);
            }
        }
        return $out;
    }

    public static function publish_evidence(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_marketev', $companyuuid, $userid, $uuid, $revision);
    }
}
