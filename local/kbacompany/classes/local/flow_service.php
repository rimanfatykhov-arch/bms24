<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/** Company product handoff design; no automatic task execution or result acceptance. */
class flow_service {
    public static function contract(): array {
        return ['version' => '0.2.6', 'objects' => ['ProductFlow'],
            'states' => ['draft', 'active', 'archived'], 'scope' => 'handoff_design',
            'rules' => ['explicit_publication', 'approved_function_snapshots', 'company_scope',
                'no_automatic_execution', 'no_automatic_competency_or_maturity_change']];
    }
    private static function fail(string $key): void {
        throw new \moodle_exception('flow_' . $key, 'local_kbacompany');
    }
    private static function row(string $table, string $company, string $uuid): \stdClass {
        global $DB;
        $r = $DB->get_record('local_kbacompany_' . $table, ['companyuuid' => $company, 'uuid' => $uuid]);
        if (!$r) { self::fail('reference'); }
        return $r;
    }
    private static function value(array $data, string $key, int $limit): string {
        $v = trim((string)($data[$key] ?? ''));
        if ($v === '' || \core_text::strlen($v) > $limit) { self::fail('invalid'); }
        return $v;
    }
    /** Both ends must have current approvals; their snapshots contain no employee assignment data. */
    private static function basis(string $company, int $actor, \stdClass $flow): array {
        global $DB;
        $result = [];
        foreach (['fromuuid', 'touuid'] as $field) {
            $preview = workflow_service::preview($company, $flow->$field, $actor);
            $a = $DB->get_records('local_kbacompany_wfapprove', ['companyuuid' => $company,
                'objectuuid' => $flow->$field, 'fingerprint' => $preview['fingerprint'], 'revoked' => 0], 'id DESC', '*', 0, 1);
            if (!$a) { self::fail('approval'); }
            $a = reset($a);
            $result[$field] = ['approval' => $a->uuid, 'fingerprint' => $a->fingerprint,
                'bundle' => $preview['bundle']];
        }
        if ($result['fromuuid']['bundle']['references']['product']->uuid !== $flow->productuuid) {
            self::fail('product');
        }
        return $result;
    }
    public static function command(string $company, int $actor, string $action, array $data): string {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        company_context_service::context($company, $actor);
        if (!cascade_service::can_manage($company, $actor)) { self::fail('permission'); }
        if (!empty($data['reason'])) { self::value($data, 'reason', 4000); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('cascade:' . $company, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $id = trim((string)($data['object'] ?? ''));
            $old = $id === '' ? null : self::row('flow', $company, $id);
            if ($old && (int)$old->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
            if ($action === 'save') {
                if ($old && $old->status !== 'draft') { self::fail('state'); }
                $new = $old ? clone $old : (object)['companyuuid' => $company,
                    'uuid' => self::uuid(), 'status' => 'draft', 'revision' => 0, 'snapshot' => '', 'timecreated' => time()];
                foreach (['code' => 50, 'name' => 255, 'fromuuid' => 36, 'touuid' => 36,
                        'productuuid' => 36, 'criteria' => 4000, 'handoff' => 4000] as $key => $limit) {
                    $new->$key = self::value($data, $key, $limit);
                }
                if ($new->fromuuid === $new->touuid) { self::fail('self'); }
                foreach (['fromuuid', 'touuid'] as $field) {
                    $f = self::row('acobj', $company, $new->$field);
                    if ($f->kind !== 'function' || $f->status !== 'draft') { self::fail('reference'); }
                }
                self::row('product', $company, $new->productuuid);
                $duplicate = $DB->get_record('local_kbacompany_flow', ['companyuuid' => $company, 'code' => $new->code]);
                if ($duplicate && $duplicate->uuid !== $new->uuid) { self::fail('duplicate'); }
            } else if ($action === 'publish' && $old && $old->status === 'draft') {
                $new = clone $old;
                $new->snapshot = json_encode(self::basis($company, $actor, $new), JSON_UNESCAPED_UNICODE);
                $new->status = 'active';
            } else if ($action === 'archive' && $old && $old->status !== 'archived') {
                self::value($data, 'reason', 4000);
                $new = clone $old;
                $new->status = 'archived';
            } else { self::fail('state'); }
            $new->revision++;
            $new->timemodified = time();
            if ($old) { $DB->update_record('local_kbacompany_flow', $new); }
            else { $DB->insert_record('local_kbacompany_flow', $new); }
            $DB->insert_record('local_kbacompany_aclog', (object)['companyuuid' => $company,
                'objectuuid' => $new->uuid, 'actorid' => $actor, 'action' => 'flow_' . $action,
                'oldvalue' => json_encode($old, JSON_UNESCAPED_UNICODE),
                'newvalue' => json_encode(['record' => $new, 'reason' => (string)($data['reason'] ?? '')], JSON_UNESCAPED_UNICODE),
                'timecreated' => time()]);
            $tx->allow_commit();
            return $new->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }
    private static function uuid(): string {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20);
    }
    public static function context(string $company, int $actor, string $selected = ''): array {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $context = company_context_service::context($company, $actor);
        $manager = cascade_service::can_manage($company, $actor);
        $records = [];
        foreach ($DB->get_records('local_kbacompany_flow', ['companyuuid' => $company], 'code ASC') as $r) {
            if (!$manager && $r->status !== 'active') { continue; }
            $r->basiscurrent = null;
            if ($r->status === 'active') {
                try {
                    $current = self::basis($company, $actor, $r);
                    $r->basiscurrent = json_encode($current, JSON_UNESCAPED_UNICODE) === $r->snapshot;
                } catch (\moodle_exception $e) { $r->basiscurrent = false; }
            }
            $records[$r->uuid] = $r;
        }
        if ($selected !== '' && !isset($records[$selected])) { self::fail('reference'); }
        return ['company' => $context, 'can_manage' => $manager, 'flows' => array_values($records),
            'selected' => $records[$selected] ?? null,
            'history' => $manager && $selected !== '' ? array_values($DB->get_records('local_kbacompany_aclog',
                ['companyuuid' => $company, 'objectuuid' => $selected], 'id DESC', '*', 0, 50)) : []];
    }
}
