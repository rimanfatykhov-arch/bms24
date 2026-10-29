<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Immutable company policy and management-directive revisions. */
class policy_service {
    public const STATES = ['draft', 'published', 'withdrawn'];
    public const KINDS = ['policy', 'directive'];
    public const PAGE_SIZE = 25;
    public const FIELD_LIMITS = ['title'=>255, 'purpose'=>4000, 'basis'=>4000, 'reason'=>4000, 'documenturl'=>2000];

    /** Existing Company/capability gate. Assignment to an owner never grants access. */
    public static function access(string $company, int $actor): array {
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $context = company_context_service::context($company, $actor);
        return ['company'=>$context, 'can_manage'=>cascade_service::can_manage($company, $actor)];
    }

    public static function contract(): array {
        return ['version' => '0.2.8.0', 'objects' => ['Policy', 'ManagementDirective'],
            'states' => ['draft', 'published', 'withdrawn'], 'scope' => 'company_governance',
            'rules' => ['append_only_revisions', 'explicit_publication', 'explicit_withdrawal',
                'company_scope', 'no_automatic_execution', 'no_automatic_maturity_change']];
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('policy_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) .
            '-8' . substr($h, 17, 3) . '-' . substr($h, 20);
    }

    private static function value(array $data, string $key, int $limit, bool $required = true): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $limit) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function date(array $data, string $key, bool $required = false): string {
        $value = self::value($data, $key, 10, $required);
        if ($value === '') { return ''; }
        $parts = array_map('intval', explode('-', $value));
        if (count($parts) !== 3 || sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]) !== $value ||
                !checkdate($parts[1], $parts[2], $parts[0])) {
            self::fail('date');
        }
        return $value;
    }

    private static function latest(string $company, string $uuid): \stdClass {
        global $DB;
        $records = $DB->get_records('local_kbacompany_policy',
            ['companyuuid' => $company, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$records) { self::fail('reference'); }
        return reset($records);
    }

    private static function assert_latest(\stdClass $record, array $data): void {
        if ((int)$record->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
    }

    private static function reference(string $company, string $type, string $uuid, bool $owner): void {
        global $DB;
        if ($uuid === '') { self::fail('reference'); }
        if ($type === 'node') {
            $record = $DB->get_record('local_kbacompany_orgnode',
                ['companyuuid' => $company, 'uuid' => $uuid, 'active' => 1]);
        } else if ($type === 'position') {
            $record = $DB->get_record('local_kbacompany_position',
                ['companyuuid' => $company, 'uuid' => $uuid, 'active' => 1]);
        } else if (!$owner && $type === 'branch') {
            $record = $DB->get_record('local_kbacompany_orgbranch',
                ['companyuuid' => $company, 'uuid' => $uuid, 'active' => 1]);
        } else {
            self::fail('reference');
        }
        if (!$record) { self::fail('reference'); }
    }

    private static function normalized(string $company, array $data): array {
        $kind = self::value($data, 'kind', 12);
        $scopetype = self::value($data, 'scopetype', 12);
        $ownertype = self::value($data, 'ownertype', 12);
        if (!in_array($kind, ['policy', 'directive'], true) ||
                !in_array($scopetype, ['company', 'branch', 'node', 'position'], true) ||
                !in_array($ownertype, ['node', 'position'], true)) {
            self::fail('invalid');
        }
        $scopeuuid = self::value($data, 'scopeuuid', 36, false);
        if ($scopetype === 'company') {
            if ($scopeuuid !== '') { self::fail('reference'); }
        } else {
            self::reference($company, $scopetype, $scopeuuid, false);
        }
        $owneruuid = self::value($data, 'owneruuid', 36);
        self::reference($company, $ownertype, $owneruuid, true);
        $effectivefrom = self::date($data, 'effectivefrom', false);
        $effectiveto = self::date($data, 'effectiveto', false);
        if ($effectiveto !== '' && ($effectivefrom === '' || $effectiveto < $effectivefrom)) {
            self::fail('date');
        }
        $documenturl = self::value($data, 'documenturl', 2000, false);
        if ($documenturl !== '' && !self::safe_url($documenturl)) {
            self::fail('url');
        }
        return ['kind' => $kind, 'code' => self::value($data, 'code', 50),
            'title' => self::value($data, 'title', 255), 'purpose' => self::value($data, 'purpose', 4000),
            'scopetype' => $scopetype, 'scopeuuid' => $scopeuuid, 'ownertype' => $ownertype,
            'owneruuid' => $owneruuid, 'effectivefrom' => $effectivefrom, 'effectiveto' => $effectiveto,
            'documenturl' => $documenturl, 'basis' => self::value($data, 'basis', 4000)];
    }

    private static function duplicate_code(string $company, string $uuid, string $code): void {
        global $DB;
        if ($DB->record_exists_select('local_kbacompany_policy',
                'companyuuid = :c AND code = :code AND uuid <> :object',
                ['c'=>$company, 'code'=>$code, 'object'=>$uuid])) { self::fail('duplicate'); }
    }

    private static function allocate_code(string $company, string $kind): string {
        global $DB;
        $prefix = $kind === 'directive' ? 'DIR' : 'POL';
        $minimum = 0;
        $rows = $DB->get_recordset_sql('SELECT id, code FROM {local_kbacompany_policy}
            WHERE companyuuid = :company AND '.$DB->sql_like('code', ':prefix', false),
            ['company'=>$company, 'prefix'=>$prefix.'-%']);
        try {
            foreach ($rows as $row) {
                if (preg_match('/^'.$prefix.'-([0-9]+)$/i', $row->code, $m)) {
                    $minimum = max($minimum, (int)$m[1]);
                }
            }
        } finally { $rows->close(); }
        return stable_code_service::next($company, 'policy:'.$kind, $prefix,
            static function(string $candidate) use ($company): bool {
                global $DB;
                return !$DB->record_exists('local_kbacompany_policy', ['companyuuid'=>$company, 'code'=>$candidate]);
            }, $minimum);
    }

    /** Field-level errors used by the form AND by command validation. */
    public static function validation_errors(string $company, int $actor, array $data): array {
        $access = self::access($company, $actor);
        if (!$access['can_manage']) { self::fail('permission'); }
        $errors = [];
        foreach (self::FIELD_LIMITS as $key=>$limit) {
            $value = trim((string)($data[$key] ?? ''));
            if (in_array($key, ['title','purpose','basis'], true) && $value === '') {
                $errors[$key] = get_string('pcu_required', 'local_kbacompany');
            } else if (\core_text::strlen($value) > $limit) {
                $errors[$key] = get_string('pcu_toolong', 'local_kbacompany', $limit);
            }
        }
        if (!in_array($data['kind'] ?? '', self::KINDS, true)) { $errors['kind'] = get_string('policy_invalid', 'local_kbacompany'); }
        if (!in_array($data['scopetype'] ?? '', ['company','branch','node','position'], true)) {
            $errors['scoperef'] = get_string('policy_reference', 'local_kbacompany');
        } else {
            try {
                if ($data['scopetype'] === 'company') {
                    if (trim((string)($data['scopeuuid'] ?? '')) !== '') { self::fail('reference'); }
                } else { self::reference($company, $data['scopetype'], trim((string)($data['scopeuuid'] ?? '')), false); }
            } catch (\moodle_exception $e) { $errors['scoperef'] = $e->getMessage(); }
        }
        try { self::reference($company, (string)($data['ownertype'] ?? ''), trim((string)($data['owneruuid'] ?? '')), true); }
        catch (\moodle_exception $e) { $errors['ownerref'] = $e->getMessage(); }
        foreach (['effectivefrom','effectiveto'] as $key) {
            try { self::date($data, $key); } catch (\moodle_exception $e) { $errors[$key] = $e->getMessage(); }
        }
        if (!isset($errors['effectivefrom']) && !isset($errors['effectiveto']) && !empty($data['effectiveto']) &&
                (empty($data['effectivefrom']) || $data['effectiveto'] < $data['effectivefrom'])) {
            $errors['effectiveto'] = get_string('policy_date', 'local_kbacompany');
        }
        $url = trim((string)($data['documenturl'] ?? ''));
        if ($url !== '' && !self::safe_url($url)) { $errors['documenturl'] = get_string('policy_url', 'local_kbacompany'); }
        return $errors;
    }

    /** Displaying a legacy URL must not introduce javascript/data schemes. No remote fetch. */
    public static function safe_url(string $url): bool {
        return (bool)filter_var($url, FILTER_VALIDATE_URL) &&
            in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true) &&
            parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
    }

    /** Every command appends a revision; published content is never updated in place. */
    public static function command(string $company, int $actor, string $action, array $data): string {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        company_context_service::context($company, $actor);
        if (!cascade_service::can_manage($company, $actor)) { self::fail('permission'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('policy:' . $company, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $uuid = trim((string)($data['object'] ?? ''));
            $old = $uuid === '' ? null : self::latest($company, $uuid);
            if ($old) { self::assert_latest($old, $data); }
            $reason = self::value($data, 'reason', 4000, false);
            if ($action === 'save' && (!$old || $old->status === 'draft')) {
                if ($old && isset($data['code']) && trim((string)$data['code']) !== $old->code) { self::fail('codeimmutable'); }
                if ($old && isset($data['kind']) && $data['kind'] !== $old->kind) { self::fail('kindimmutable'); }
                $errors = self::validation_errors($company, $actor, $data);
                if ($errors) { self::fail('invalid'); }
                // Explicit new codes remain supported for existing internal callers; the UI never sends one.
                $data['code'] = $old ? $old->code : (trim((string)($data['code'] ?? '')) !== '' ?
                    trim((string)$data['code']) : self::allocate_code($company, (string)$data['kind']));
                $values = self::normalized($company, $data);
                $uuid = $old ? $old->uuid : self::uuid();
                self::duplicate_code($company, $uuid, $values['code']);
                $new = (object)array_merge($values, ['companyuuid' => $company, 'uuid' => $uuid,
                    'revision' => $old ? (int)$old->revision + 1 : 1, 'status' => 'draft',
                    'reason' => $reason, 'actorid' => $actor, 'timecreated' => time()]);
            } else if ($action === 'publish' && $old && $old->status === 'draft') {
                if ($old->documenturl === '' || $old->effectivefrom === '') { self::fail('publishrequired'); }
                $errors = self::validation_errors($company, $actor, (array)$old);
                if ($errors) { self::fail('invalid'); }
                if ($reason === '') { self::fail('actionreason'); }
                $new = clone $old;
                unset($new->id);
                $new->revision++;
                $new->status = 'published';
                $new->reason = $reason;
                $new->actorid = $actor;
                $new->timecreated = time();
            } else if ($action === 'newversion' && $old && $old->status === 'published') {
                if ($reason === '') { self::fail('actionreason'); }
                $new = clone $old;
                unset($new->id);
                $new->revision++;
                $new->status = 'draft';
                $new->reason = $reason;
                $new->actorid = $actor;
                $new->timecreated = time();
            } else if ($action === 'withdraw' && $old && $old->status === 'published') {
                if ($reason === '') { self::fail('actionreason'); }
                $new = clone $old;
                unset($new->id);
                $new->revision++;
                $new->status = 'withdrawn';
                $new->reason = $reason;
                $new->actorid = $actor;
                $new->timecreated = time();
            } else {
                self::fail('state');
            }
            $DB->insert_record('local_kbacompany_policy', $new);
            $DB->insert_record('local_kbacompany_aclog', (object)['companyuuid' => $company,
                'objectuuid' => $new->uuid, 'actorid' => $actor, 'action' => 'policy_' . $action,
                'oldvalue' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                'newvalue' => json_encode(['revision' => $new->revision, 'status' => $new->status,
                    'reason' => $reason], JSON_UNESCAPED_UNICODE), 'timecreated' => time()]);
            $tx->allow_commit();
            return $new->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** SQL visibility, including current published revision during preparation of a new draft. */
    private static function visibility(bool $manager, bool $current = true): string {
        if ($manager) {
            return $current ? 'NOT EXISTS (SELECT n.id FROM {local_kbacompany_policy} n
                WHERE n.companyuuid = p.companyuuid AND n.uuid = p.uuid AND n.revision > p.revision)' : '1=1';
        }
        // A withdrawal closes reader access. Draft text/reasons are never fetched for readers.
        $where = "p.status = 'published' AND NOT EXISTS (SELECT w.id FROM {local_kbacompany_policy} w
            WHERE w.companyuuid = p.companyuuid AND w.uuid = p.uuid AND w.status = 'withdrawn' AND w.revision > p.revision)";
        if ($current) {
            $where .= " AND NOT EXISTS (SELECT n.id FROM {local_kbacompany_policy} n
                WHERE n.companyuuid = p.companyuuid AND n.uuid = p.uuid AND n.status = 'published' AND n.revision > p.revision)";
        }
        return $where;
    }

    /** Compatibility API keeps its shape; selection/visibility now happen in SQL, not PHP. */
    public static function context(string $company, int $actor, string $selected = ''): array {
        global $DB;
        $access = self::access($company, $actor);
        $where = self::visibility($access['can_manage']);
        $records = array_values($DB->get_records_sql("SELECT p.* FROM {local_kbacompany_policy} p
            WHERE p.companyuuid = :company AND $where ORDER BY p.kind, p.code, p.uuid", ['company'=>$company]));
        $record = null; $history = [];
        if ($selected !== '') {
            $record = self::read_record($company, $actor, $selected);
            $visible = self::visibility($access['can_manage'], false);
            $history = array_values($DB->get_records_sql("SELECT p.* FROM {local_kbacompany_policy} p
                WHERE p.companyuuid = :company AND p.uuid = :object AND $visible ORDER BY p.revision DESC",
                ['company'=>$company, 'object'=>$selected]));
        }
        return $access + ['records'=>$records, 'selected'=>$record, 'history'=>$history];
    }

    public static function read_record(string $company, int $actor, string $object, int $revision = 0): \stdClass {
        global $DB;
        $access = self::access($company, $actor);
        $where = self::visibility($access['can_manage'], $revision <= 0);
        $params = ['company'=>$company, 'object'=>$object];
        if ($revision > 0) { $where .= ' AND p.revision = :revision'; $params['revision'] = $revision; }
        $rows = $DB->get_records_sql("SELECT p.* FROM {local_kbacompany_policy} p
            WHERE p.companyuuid = :company AND p.uuid = :object AND $where ORDER BY p.revision DESC", $params, 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    public static function filters(array $data): array {
        return ['q'=>\core_text::substr(trim((string)($data['q'] ?? '')), 0, 200),
            'kind'=>in_array($data['kind'] ?? '', self::KINDS, true) ? $data['kind'] : '',
            'status'=>in_array($data['status'] ?? '', self::STATES, true) ? $data['status'] : '',
            'ownerref'=>\core_text::substr(trim((string)($data['ownerref'] ?? '')), 0, 60)];
    }

    /** Metadata-only, one row per object. No revision bodies loaded for a list. */
    public static function browse(string $company, int $actor, array $input = [], int $page = 0): array {
        global $DB;
        $access = self::access($company, $actor); $filters = self::filters($input);
        $where = 'p.companyuuid = :company AND '.self::visibility($access['can_manage']);
        $params = ['company'=>$company];
        foreach (['kind','status'] as $key) {
            if ($filters[$key] !== '') { $where .= " AND p.$key = :$key"; $params[$key] = $filters[$key]; }
        }
        if ($filters['q'] !== '') {
            $where .= ' AND ('.$DB->sql_like('p.code', ':codeq', false).' OR '.$DB->sql_like('p.title', ':titleq', false).')';
            $params['codeq'] = '%'.$DB->sql_like_escape($filters['q']).'%'; $params['titleq'] = $params['codeq'];
        }
        if ($filters['ownerref'] !== '') {
            $ref = explode('|', $filters['ownerref'], 2);
            if (count($ref) === 2 && in_array($ref[0], ['node','position'], true)) {
                $where .= ' AND p.ownertype = :ownertype AND p.owneruuid = :owneruuid';
                $params['ownertype'] = $ref[0]; $params['owneruuid'] = $ref[1];
            } else { $where .= ' AND 1=0'; }
        }
        $total = $DB->count_records_sql("SELECT COUNT(p.id) FROM {local_kbacompany_policy} p WHERE $where", $params);
        $page = max(0, min($page, max(0, (int)ceil($total/self::PAGE_SIZE)-1)));
        $rows = array_values($DB->get_records_sql("SELECT p.id,p.uuid,p.code,p.title,p.kind,p.status,p.revision,
            p.scopetype,p.scopeuuid,p.ownertype,p.owneruuid,p.effectivefrom,p.effectiveto,p.timecreated
            FROM {local_kbacompany_policy} p WHERE $where ORDER BY p.timecreated DESC,p.id DESC",
            $params, $page*self::PAGE_SIZE, self::PAGE_SIZE));
        return $access + ['rows'=>$rows,'filters'=>$filters,'page'=>$page,'pagesize'=>self::PAGE_SIZE,'total'=>$total,
            'labels'=>self::labels($company, $rows)];
    }

    /** Only references already reached through an authorized policy result are resolved. */
    private static function labels(string $company, array $records): array {
        global $DB;
        $refs = ['branch'=>[], 'node'=>[], 'position'=>[]]; $labels = [];
        foreach ($records as $row) {
            if (isset($refs[$row->scopetype]) && $row->scopeuuid !== '') { $refs[$row->scopetype][$row->scopeuuid] = $row->scopeuuid; }
            if (isset($refs[$row->ownertype]) && $row->owneruuid !== '') { $refs[$row->ownertype][$row->owneruuid] = $row->owneruuid; }
        }
        foreach (['branch'=>'orgbranch','node'=>'orgnode','position'=>'position'] as $type=>$suffix) {
            if (!$refs[$type]) { continue; }
            list($insql, $params) = $DB->get_in_or_equal(array_values($refs[$type]), SQL_PARAMS_NAMED, 'ref');
            $params['company'] = $company;
            $rows = $DB->get_records_sql("SELECT id,uuid,code,name,active FROM {local_kbacompany_$suffix}
                WHERE companyuuid = :company AND uuid $insql", $params);
            foreach ($rows as $row) {
                $labels[$type.'|'.$row->uuid] = get_string('policy_scope_'.$type, 'local_kbacompany').': '.
                    trim($row->code.' · '.$row->name, ' ·').(!$row->active ? ' · '.get_string('pcu_inactive', 'local_kbacompany') : '');
            }
        }
        return $labels;
    }

    /** Company-scoped search. Typed references replace incompatible type/id dropdown pairs. */
    public static function reference_options(string $company, int $actor, string $target, string $query = '', string $object = ''): array {
        global $DB;
        $access = self::access($company, $actor);
        if (!in_array($target, ['scope','owner'], true) || !$access['can_manage']) { self::fail('permission'); }
        if ($object !== '') { self::read_record($company, $actor, $object); }
        $query = \core_text::substr(trim($query), 0, 200);
        $out = [];
        $types = $target === 'scope' ? ['branch'=>'orgbranch','node'=>'orgnode','position'=>'position'] : ['position'=>'position','node'=>'orgnode'];
        foreach ($types as $type=>$suffix) {
            $where = 'companyuuid = :company AND active = 1'; $params = ['company'=>$company];
            if ($query !== '') {
                $where .= ' AND ('.$DB->sql_like('code', ':codeq', false).' OR '.$DB->sql_like('name', ':nameq', false).')';
                $params['codeq'] = '%'.$DB->sql_like_escape($query).'%'; $params['nameq'] = $params['codeq'];
            }
            $rows = $DB->get_records_sql("SELECT id,uuid,code,name FROM {local_kbacompany_$suffix} WHERE $where ORDER BY code,name,id", $params, 0, 40);
            foreach ($rows as $row) {
                $out[] = ['value'=>$type.'|'.$row->uuid, 'label'=>get_string('policy_scope_'.$type,'local_kbacompany').': '.trim($row->code.' · '.$row->name, ' ·')];
            }
        }
        return $out;
    }

    /** Record model; current and historical snapshots are read-only. */
    public static function record(string $company, int $actor, string $object, int $revision = 0,
            string $tab = 'overview', int $page = 0): array {
        global $DB;
        $access = self::access($company, $actor);
        $current = self::read_record($company, $actor, $object);
        $selected = $revision > 0 && $revision !== (int)$current->revision ? self::read_record($company, $actor, $object, $revision) : $current;
        $published = null;
        if ($current->status !== 'withdrawn') {
            $rows = $DB->get_records_sql("SELECT p.* FROM {local_kbacompany_policy} p
                WHERE p.companyuuid = :company AND p.uuid = :object AND ".self::visibility(false)." ORDER BY p.revision DESC",
                ['company'=>$company,'object'=>$object], 0, 1);
            $published = $rows ? reset($rows) : null;
        }
        $actions = ['edit'=>false,'publish'=>false,'newversion'=>false,'withdraw'=>false];
        if ($access['can_manage'] && (int)$selected->revision === (int)$current->revision) {
            $actions = ['edit'=>$current->status === 'draft','publish'=>$current->status === 'draft',
                'newversion'=>$current->status === 'published','withdraw'=>$current->status === 'published'];
        }
        $history = []; $total = 0; $page = max(0, $page); $links = []; $linktotal = 0;
        if ($tab === 'revisions' || $tab === 'history') {
            // Readers get only published snapshots; management-only aclog never leaves the domain.
            $where = 'p.companyuuid = :company AND p.uuid = :object AND '.self::visibility($access['can_manage'], false);
            $params = ['company'=>$company,'object'=>$object];
            $total = $DB->count_records_sql("SELECT COUNT(p.id) FROM {local_kbacompany_policy} p WHERE $where", $params);
            $page = min($page, max(0,(int)ceil($total/self::PAGE_SIZE)-1));
            $history = array_values($DB->get_records_sql("SELECT p.id,p.uuid,p.revision,p.status,p.reason,p.actorid,p.timecreated,
                u.firstname,u.lastname FROM {local_kbacompany_policy} p LEFT JOIN {user} u ON u.id = p.actorid
                WHERE $where ORDER BY p.revision DESC", $params, $page*self::PAGE_SIZE, self::PAGE_SIZE));
        }
        if ($tab === 'links') {
            // Reuse the Administrative Scale domain. Never migrate/copy its source-pinned records.
            $manage = administrative_scale_service::can_manage($company, $actor);
            $visible = $manage ? 'NOT EXISTS (SELECT n.id FROM {local_kbacompany_adminitem} n
                WHERE n.companyuuid = a.companyuuid AND n.uuid = a.uuid AND n.revision > a.revision)' :
                "a.status = 'published' AND NOT EXISTS (SELECT n.id FROM {local_kbacompany_adminitem} n
                WHERE n.companyuuid = a.companyuuid AND n.uuid = a.uuid AND n.revision > a.revision AND n.status IN ('published','archived'))";
            $where = "a.companyuuid = :company AND a.sourceuuid = :object AND a.sourcekind IN ('policy','directive') AND $visible";
            $params = ['company'=>$company,'object'=>$object];
            $linktotal = $DB->count_records_sql("SELECT COUNT(a.id) FROM {local_kbacompany_adminitem} a WHERE $where", $params);
            $page = min($page, max(0,(int)ceil($linktotal/self::PAGE_SIZE)-1));
            $links = array_values($DB->get_records_sql("SELECT a.id,a.uuid,a.code,a.name AS title,a.kind,a.status,a.revision,a.sourcerevision
                FROM {local_kbacompany_adminitem} a WHERE $where ORDER BY a.code,a.id", $params, $page*self::PAGE_SIZE, self::PAGE_SIZE));
        }
        return $access + ['selected'=>$selected,'current'=>$current,'published'=>$published,'actions'=>$actions,
            'labels'=>self::labels($company, [$selected]), 'history'=>$history, 'total'=>$total,
            'page'=>$page,'pagesize'=>self::PAGE_SIZE,'links'=>$links,'linktotal'=>$linktotal];
    }

    /** UI date marker only, not an automatic lifecycle transition. Dates are inclusive. */
    public static function period_state(\stdClass $row, string $today): string {
        if ($row->status === 'withdrawn') { return 'withdrawn'; }
        if ($row->status !== 'published') { return 'draft'; }
        if ($row->effectivefrom === '') { return 'undated'; }
        if ($row->effectivefrom > $today) { return 'scheduled'; }
        if ($row->effectiveto !== '' && $row->effectiveto < $today) { return 'expired'; }
        return 'current';
    }
}
