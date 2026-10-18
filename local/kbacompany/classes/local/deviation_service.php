<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** One deviation register: scoped user reports and approved-norm deviations, human-reviewed corrections. */
class deviation_service {
    public static function contract(): array {
        return ['version' => '0.9.0.9', 'scope' => 'company_manager_or_own_report',
            'states' => self::STATES,
            'rules' => ['approved_norm_snapshot_for_quantitative', 'manual_report_without_numeric_norm', 'report_is_not_confirmation', 'author_own_reports_only', 'manager_triage', 'canonical_tasks_no_copy', 'exact_decimal_comparison', 'cause_is_hypothesis',
                'accepted_tasks_do_not_close_deviation', 'recheck_required', 'explicit_manager_closure',
                'same_indicator_unit_period_type_and_limits', 'no_statval_or_competency_mutation'],
            'deferred' => ['automatic_detection', 'recurrence', 'notifications']];
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('dv_' . $key, 'local_kbacompany');
    }

    private static function authorise(string $company, int $actor): void {
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        if (!cascade_service::can_manage($company, $actor)) { self::fail('permission'); }
        deviation_schema::require_ready($actor);
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' .
            substr($h, 16, 4) . '-' . substr($h, 20);
    }

    private static function row(string $table, string $company, string $uuid): \stdClass {
        global $DB;
        $r = $DB->get_record($table, ['companyuuid' => $company, 'uuid' => $uuid]);
        if (!$r) { self::fail('reference'); }
        return $r;
    }

    private static function text(array $data, string $key): string {
        $value = trim((string)($data[$key] ?? ''));
        if ($value === '' || \core_text::strlen($value) > 4000) { self::fail('invalid'); }
        return $value;
    }

    /** Extract the exact norm and indicator from the immutable approved function bundle. */
    public static function norm(string $company, int $actor, string $approval, string $norm, bool $current = true): array {
        $company = company_context_service::normalise_uuid($company);
        self::authorise($company, $actor);
        $a = self::row('local_kbacompany_wfapprove', $company, $approval);
        if ($current && $a->revoked) { self::fail('revoked'); }
        $snapshot = json_decode($a->snapshot, true);
        $function = $snapshot['object'] ?? [];
        $stat = $snapshot['references']['stat'] ?? [];
        if (($function['kind'] ?? '') !== 'function' || empty($stat['uuid'])) { self::fail('reference'); }
        foreach ($snapshot['objects'] ?? [] as $obj) {
            if ($obj['uuid'] === $norm && $obj['kind'] === 'target' && $obj['parentuuid'] === $function['uuid']) {
                $payload = json_decode($obj['payload'], true);
                if ($payload['indicator'] !== $stat['uuid']) { self::fail('reference'); }
                return ['approval' => $a, 'object' => $obj, 'function' => $function, 'stat' => $stat, 'data' => $payload];
            }
        }
        self::fail('reference');
        return [];
    }

    /** Missing bounds remain unbounded; neutral indicators without bounds require exact equality. */
    public static function evaluate(string $actual, array $norm, string $direction): array {
        foreach ([$actual, $norm['amount'] ?? '', $norm['lower'] ?? null, $norm['upper'] ?? null] as $number) {
            if ($number !== null && !cascade_service::decimal_valid((string)$number)) { self::fail('invalid'); }
        }
        $lower = $norm['lower'] ?? null;
        $upper = $norm['upper'] ?? null;
        if ($direction === 'up') { $lower = $lower ?? $norm['amount']; }
        else if ($direction === 'down') { $upper = $upper ?? $norm['amount']; }
        else if ($direction === 'neutral') {
            if ($lower === null && $upper === null) { $lower = $upper = $norm['amount']; }
        } else { self::fail('invalid'); }
        $below = $lower !== null && cascade_service::decimal_compare($actual, (string)$lower) < 0;
        $above = $upper !== null && cascade_service::decimal_compare($actual, (string)$upper) > 0;
        return ['inrange' => !$below && !$above, 'side' => $below ? 'below' : ($above ? 'above' : 'within'),
            'lower' => $lower, 'upper' => $upper];
    }

    public static function observation_date(string $date, string $start, string $period): void {
        product_service::validate_period($date, 'daily');
        product_service::validate_period($start, $period);
        $offsets = ['daily' => '+1 day', 'weekly' => '+7 days', 'monthly' => '+1 month'];
        if (!isset($offsets[$period])) { self::fail('invalid'); }
        $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify($offsets[$period])->format('Y-m-d');
        if ($date < $start || $date >= $end) { self::fail('period'); }
    }

    /** A changed threshold cannot be passed off as improvement under the original criterion. */
    private static function comparable(array $before, array $after): bool {
        foreach (['uuid', 'unit', 'periodtype', 'direction'] as $field) {
            if ($before['stat'][$field] !== $after['stat'][$field]) { return false; }
        }
        if ($before['function']['uuid'] !== $after['function']['uuid']) { return false; }
        foreach (['amount', 'lower', 'upper'] as $field) {
            $a = $before['data'][$field] ?? null;
            $b = $after['data'][$field] ?? null;
            if ($a === null || $b === null) {
                if ($a !== $b) { return false; }
            } else if (cascade_service::decimal_compare((string)$a, (string)$b) !== 0) { return false; }
        }
        return true;
    }

    private static function tasks(string $company, string $deviation, bool $accepted): array {
        global $DB;
        $tasks = [];
        foreach ($DB->get_records('local_kbacompany_devlink',
                ['companyuuid' => $company, 'deviationuuid' => $deviation, 'active' => 1], 'taskuuid ASC') as $link) {
            if (($link->taskengine ?? 'workflow') === 'bms') {
                $task = self::row('local_kbacompany_task', $company, $link->taskuuid);
                if ($accepted && ($task->status !== 'done' || trim((string)$task->result) === '')) { self::fail('unfinished'); }
                $tasks[] = ['uuid'=>$task->uuid,'revision'=>(int)$task->revision,'status'=>$task->status,'engine'=>'bms'];
            } else {
                $task = self::row('local_kbacompany_wfwork', $company, $link->taskuuid);
                if ($task->kind !== 'task' || ($accepted && $task->status !== 'accepted')) { self::fail('unfinished'); }
                $tasks[] = ['uuid'=>$task->uuid,'revision'=>(int)$task->revision,'status'=>$task->status];
            }
        }
        if ($accepted && !$tasks) { self::fail('unfinished'); }
        return $tasks;
    }

    private static function task_hash(array $tasks): string {
        return hash('sha256', json_encode($tasks));
    }

    private static function log(string $company, string $uuid, int $actor, string $action, $old, $new,
            string $reason = '', string $relateduuid = ''): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)['companyuuid' => $company,
            'objectuuid' => $uuid, 'action' => 'dv_' . $action, 'actorid' => $actor,
            'oldvalue' => json_encode($old, JSON_UNESCAPED_UNICODE),
            'newvalue' => json_encode(['record' => $new, 'reason' => $reason, 'relateduuid' => $relateduuid],
                JSON_UNESCAPED_UNICODE), 'timecreated' => time()]);
    }

    private static function quantitative_command(string $company, int $actor, string $action, array $data): string {
        global $DB;
        self::authorise($company, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('cascade:' . $company, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $target=trim((string)($data['object']??''));
            if($action!=='create'){self::row('local_kbacompany_deviation',$company,$target);}
            $tasklocks=self::lock_tasks($company,$target,$action,$data);
            $transaction = $DB->start_delegated_transaction();
            $now = time();
            $uuid = trim((string)($data['object'] ?? ''));
            $old = $action === 'create' ? null : self::row('local_kbacompany_deviation', $company, $uuid);
            if ($old && ((int)$old->revision !== (int)($data['revision'] ?? 0) ||
                    in_array($old->status, ['closed', 'cancelled'], true))) { self::fail('state'); }
            $reason = '';
            $relateduuid = '';
            if ($action === 'create' || $action === 'check') {
                $basis = self::norm($company, $actor, (string)($data['approval'] ?? ''), (string)($data['norm'] ?? ''));
                $actual = self::text($data, 'actual');
                $observed = self::text($data, 'observedon');
                self::observation_date($observed, $basis['data']['periodstart'], $basis['stat']['periodtype']);
                if ($observed > userdate($now, '%Y-%m-%d')) { self::fail('futuredate'); }
                $evaluation = self::evaluate($actual, $basis['data'], $basis['stat']['direction']);
                $evidence = self::text($data, 'evidence');
            }
            if ($action === 'create') {
                if ($evaluation['inrange']) { self::fail('notdeviation'); }
                $uuid = self::uuid();
                $new = (object)['companyuuid' => $company, 'uuid' => $uuid, 'approvaluuid' => $basis['approval']->uuid,
                    'normuuid' => $basis['object']['uuid'], 'actual' => $actual, 'observedon' => $observed,
                    'entrytype'=>'quantitative','code'=>self::allocate_code($company),'title'=>$basis['function']['name'].' / '.$basis['object']['name'],
                    'evidence' => $evidence, 'cause' => '', 'status' => 'open', 'revision' => 1,
                    'createdby' => $actor, 'analysedby' => 0, 'closedby' => 0, 'timecreated' => $now, 'timemodified' => $now];
                $new->id = $DB->insert_record('local_kbacompany_deviation', $new);
            } else {
                $new = clone $old;
                if ($action === 'analyze') {
                    $new->cause = self::text($data, 'cause');
                    $new->analysedby = $actor;
                    $new->status = self::tasks($company, $uuid, false) ? 'correcting' : 'analyzed';
                } else if ($action === 'link' || $action === 'unlink') {
                    if (trim((string)$old->cause) === '') { self::fail('analysisrequired'); }
                    $taskuuid = self::text($data, 'task');
                    $engine = (string)($data['taskengine'] ?? 'workflow');
                    if (!in_array($engine, ['workflow','bms'], true)) { self::fail('reference'); }
                    $reason = self::text($data, 'reason');
                    self::change_task_link($company,$actor,$uuid,$taskuuid,$action,$reason,$engine);
                    $relateduuid = $taskuuid;
                    $new->status = self::tasks($company, $uuid, false) ? 'correcting' : 'analyzed';
                } else if ($action === 'check') {
                    if (trim((string)$old->cause) === '') { self::fail('analysisrequired'); }
                    $original = self::norm($company, $actor, $old->approvaluuid, $old->normuuid, false);
                    if (!self::comparable($original, $basis)) { self::fail('notcomparable'); }
                    $previous = $DB->get_records('local_kbacompany_devcheck',
                        ['companyuuid' => $company, 'deviationuuid' => $uuid], 'id DESC', '*', 0, 1);
                    $last = $previous ? reset($previous) : null;
                    if ($observed < $old->observedon || ($last && $observed < $last->observedon)) { self::fail('chronology'); }
                    $tasks = self::tasks($company, $uuid, true);
                    self::check_chronology($company,$old,$observed,$tasks);
                    $check = (object)['companyuuid' => $company, 'uuid' => self::uuid(), 'deviationuuid' => $uuid,
                        'approvaluuid' => $basis['approval']->uuid, 'normuuid' => $basis['object']['uuid'],
                        'actual' => $actual, 'observedon' => $observed, 'evidence' => $evidence,
                        'tasksfingerprint' => self::task_hash($tasks), 'inrange' => (int)$evaluation['inrange'],
                        'createdby' => $actor, 'timecreated' => $now];
                    $DB->insert_record('local_kbacompany_devcheck', $check);
                    $relateduuid = $check->uuid;
                    $new->status = $evaluation['inrange'] ? 'verified' : 'correcting';
                } else if ($action === 'close') {
                    if ($old->status !== 'verified') { self::fail('checkrequired'); }
                    if (trim((string)$old->cause) === '') { self::fail('analysisrequired'); }
                    $reason = self::text($data, 'reason');
                    $checks = $DB->get_records('local_kbacompany_devcheck',
                        ['companyuuid' => $company, 'deviationuuid' => $uuid], 'id DESC', '*', 0, 1);
                    $last = $checks ? reset($checks) : null;
                    if (!$last || !$last->inrange || trim((string)$last->evidence) === '' ||
                            !hash_equals($last->tasksfingerprint, self::task_hash(self::tasks($company, $uuid, true)))) {
                        self::fail('checkrequired');
                    }
                    self::norm($company, $actor, $last->approvaluuid, $last->normuuid);
                    $new->status = 'closed';
                    $new->closedby = $actor;
                } else if ($action === 'cancel') {
                    $reason = self::text($data, 'reason');
                    $new->status = 'cancelled';
                    $new->closedby = $actor;
                } else { self::fail('invalid'); }
                $new->revision++;
                $new->timemodified = $now;
                $DB->update_record('local_kbacompany_deviation', $new);
            }
            self::log($company, $uuid, $actor, $action, $old, $new, $reason, $relateduuid);
            $transaction->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally { foreach(array_reverse($tasklocks??[]) as $tasklock){$tasklock->release();} $lock->release(); }
    }

    public static function context(string $company, int $actor, string $selected = ''): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;
        self::authorise($company, $actor);
        // Compatibility API remains manager-scoped; the new daily UI uses browse() pagination.
        $deviations = array_values($DB->get_records('local_kbacompany_deviation',['companyuuid'=>$company],'id DESC'));
        $current = $selected === '' ? null : self::row('local_kbacompany_deviation', $company, $selected);
        return ['company' => company_context_service::context($company, $actor), 'deviations' => $deviations,
            'selected' => $current, 'checks' => $current ? array_values($DB->get_records('local_kbacompany_devcheck',
                ['companyuuid' => $company, 'deviationuuid' => $selected], 'id DESC')) : [],
            'links' => $current ? array_values($DB->get_records('local_kbacompany_devlink',
                ['companyuuid' => $company, 'deviationuuid' => $selected], 'id ASC')) : [],
            'history' => $current ? array_values($DB->get_records('local_kbacompany_aclog',
                ['companyuuid' => $company, 'objectuuid' => $selected], 'id DESC', '*', 0, 50)) : []];
    }
    public const STATES = ['reported','open','analyzed','correcting','verified','closed','cancelled','rejected'];

    /** Reporting never grants company-wide management or access to another person's report. */
    public static function can_report(string $company, int $actor): bool {
        $company = company_context_service::normalise_uuid($company);
        global $DB;
        company_context_service::context($company, $actor);
        if (is_siteadmin($actor)) { return true; }
        if (!has_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor)) { return false; }
        return $DB->record_exists_sql("SELECT m.id FROM {local_kbacompany_member} m JOIN {user} u ON u.id=m.userid
            WHERE m.companyuuid=:c AND m.userid=:a AND m.active=1 AND u.deleted=0 AND u.suspended=0", ['c'=>$company,'a'=>$actor]);
    }

    public static function access(string $company, int $actor): array {
        $company = company_context_service::normalise_uuid($company);
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $context = company_context_service::context($company, $actor);
        $manager = cascade_service::can_manage($company, $actor);
        $reporter = self::can_report($company, $actor);
        if (!$reporter && !$manager) { self::fail('permission'); }
        deviation_schema::require_ready($actor);
        return ['company'=>$context,'can_manage'=>$manager,'can_report'=>$reporter,'actor'=>$actor];
    }

    private static function read_conditions(string $company, int $actor): array {
        $access = self::access($company, $actor);
        $where = 'd.companyuuid=:dc'; $params=['dc'=>$company];
        if (!$access['can_manage']) { $where .= " AND d.entrytype='manual' AND d.createdby=:da"; $params['da']=$actor; }
        return [$where,$params,$access];
    }

    public static function read_record(string $company, int $actor, string $uuid): \stdClass {
        $company = company_context_service::normalise_uuid($company);
        global $DB;
        list($where,$params) = self::read_conditions($company,$actor);
        $row=$DB->get_record_sql("SELECT d.* FROM {local_kbacompany_deviation} d WHERE $where AND d.uuid=:du",$params+['du'=>$uuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    /** Metadata-only SQL list, Company + access + filters before count/paging. */
    public static function browse(string $company, int $actor, array $filters=[], int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;
        list($where,$params,$access)=self::read_conditions($company,$actor);
        $f=['q'=>trim((string)($filters['q']??'')),'status'=>(string)($filters['status']??''),
            'entrytype'=>(string)($filters['entrytype']??''),'scope'=>(string)($filters['scope']??'all')];
        if (\core_text::strlen($f['q'])>200 || ($f['status']!==''&&!in_array($f['status'],self::STATES,true)) ||
                !in_array($f['entrytype'],['','manual','quantitative'],true) || !in_array($f['scope'],['all','my','pending','overdue'],true)) { self::fail('invalid'); }
        if ($f['scope']==='my' && $access['can_manage']) { $where.=' AND d.createdby=:mine';$params['mine']=$actor; }
        if ($f['scope']==='pending') { $where.=" AND d.status='reported'"; }
        if ($f['scope']==='overdue') { $where.=" AND d.duedate<>'' AND d.duedate<:today AND d.status NOT IN ('closed','cancelled','rejected')";$params['today']=userdate(time(),'%Y-%m-%d'); }
        foreach (['status','entrytype'] as $key) { if ($f[$key]!=='') { $where.=" AND d.$key=:$key";$params[$key]=$f[$key]; } }
        if ($f['q']!=='') { $where.=' AND ('.$DB->sql_like('d.title',':qt',false).' OR '.$DB->sql_like('d.code',':qc',false).')';$params['qt']='%'.$DB->sql_like_escape($f['q']).'%';$params['qc']=$params['qt']; }
        $total=$DB->count_records_sql("SELECT COUNT(d.id) FROM {local_kbacompany_deviation} d WHERE $where",$params);
        $size=25;$page=max(0,min($page,max(0,(int)ceil($total/$size)-1)));
        $rows=$DB->get_records_sql("SELECT d.id,d.uuid,d.code,d.title,d.entrytype,d.status,d.observedon,d.duedate,d.createdby,d.timemodified,
            d.approvaluuid,d.normuuid,p.name AS ownername
            FROM {local_kbacompany_deviation} d LEFT JOIN {local_kbacompany_position} p ON p.companyuuid=d.companyuuid AND p.uuid=d.ownerpositionuuid
            WHERE $where ORDER BY d.timemodified DESC,d.id DESC",$params,$page*$size,$size);
        foreach ($rows as $r) { if ($r->title==='') { $r->title=get_string($r->entrytype==='manual'?'dvu_manual':'dvu_quantitative','local_kbacompany'); } }
        return $access+['rows'=>array_values($rows),'total'=>$total,'page'=>$page,'pagesize'=>$size,'filters'=>$f];
    }

    public static function actions(\stdClass $row, int $actor, bool $manager): array {
        $manual=$row->entrytype==='manual';$pending=$manual && $row->status==='reported';
        $active=!in_array($row->status,['reported','closed','cancelled','rejected'],true);
        return ['editreport'=>$pending && (int)$row->createdby===$actor,
            'confirm'=>$pending&&$manager,'reject'=>$pending&&$manager,
            'analyze'=>$active&&$manager,'link'=>$active&&$manager&&trim((string)$row->cause)!=='',
            'unlink'=>$active&&$manager&&trim((string)$row->cause)!=='',
            'check'=>$active&&$manager&&trim((string)$row->cause)!=='',
            'close'=>$row->status==='verified'&&$manager,'cancel'=>$active&&$manager];
    }

    /** Effective managed document is an optional exact reference, not copied contents. */
    public static function document_options(string $company, int $actor, string $query='', int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;
        self::access($company,$actor);
        if (\core_text::strlen($query)>200) { self::fail('invalid'); }
        // Effective revisions are readable to all authorised Company members in document_service.
        $where="companyuuid=:c AND status='effective'";$p=['c'=>$company];
        if ($query!=='') { $where.=' AND ('.$DB->sql_like('title',':qt',false).' OR '.$DB->sql_like('code',':qc',false).')';$p['qt']='%'.$DB->sql_like_escape($query).'%';$p['qc']=$p['qt']; }
        $rows=$DB->get_records_select('local_kbacompany_doc',$where,$p,'title ASC,id ASC','id,uuid,revision,title,code',max(0,$page)*40,40);
        $out=[];foreach($rows as $r){$out[]=['value'=>$r->uuid.'|'.$r->revision,'label'=>$r->code.' · '.$r->title.' · '.$r->revision];}return $out;
    }

    private static function validate_basis(string $company, string $value): ?string {
        global $DB;
        if ($value==='') { return null; }
        $parts=explode('|',$value);
        if (count($parts)!==2 || !ctype_digit($parts[1])) { self::fail('reference'); }
        $doc=$DB->get_record('local_kbacompany_doc',['companyuuid'=>$company,'uuid'=>$parts[0],'revision'=>(int)$parts[1],'status'=>'effective'],'id,uuid,revision');
        if (!$doc) { self::fail('reference'); }
        return json_encode(['kind'=>'document','uuid'=>$doc->uuid,'revision'=>(int)$doc->revision]);
    }

    public static function form_errors(string $company, int $actor, string $action, array $data): array {
        $company = company_context_service::normalise_uuid($company);
        self::access($company,$actor);$errors=[];
        $required=['report'=>['title','description','location','observedon'],'editreport'=>['title','description','location','observedon'],
            'confirm'=>['criterion','reason'],'reject'=>['reason'],'analyze'=>['cause'],'link'=>['task','reason'],
            'unlink'=>['task','reason'],'check'=>['observedon','evidence'],'close'=>['reason'],'cancel'=>['reason'],
            'create'=>['approval','norm','actual','observedon','evidence']];
        foreach($required[$action]??[] as $key){if(trim((string)($data[$key]??''))===''){$errors[$key]=get_string('dvu_required','local_kbacompany');}}
        foreach(['title'=>255,'location'=>255,'description'=>4000,'requirement'=>4000,'evidence'=>4000,'criterion'=>4000,'reason'=>4000,'cause'=>4000,'documentref'=>80,'ownerpositionuuid'=>36] as $key=>$max){if(\core_text::strlen((string)($data[$key]??''))>$max){$errors[$key]=get_string('dvu_toolong','local_kbacompany',$max);}}
        foreach(['observedon','duedate'] as $key){$value=(string)($data[$key]??'');if($value===''){continue;}$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==$value){$errors[$key]=get_string('dvu_date_error','local_kbacompany');}else if($key==='observedon'&&$value>userdate(time(),'%Y-%m-%d')){$errors[$key]=get_string('dv_futuredate','local_kbacompany');}}
        if(in_array($action,['report','editreport'],true) && !isset($errors['documentref'])){
            $ref=trim((string)($data['documentref']??''));$unchanged=false;
            if($action==='editreport' && !empty($data['object'])){
                $old=self::read_record($company,$actor,(string)$data['object']);$pin=json_decode((string)$old->basisref,true);
                $unchanged=$pin && $ref===($pin['uuid'].'|'.$pin['revision']);
            }
            if(!$unchanged){try{self::validate_basis($company,$ref);}catch(\moodle_exception $e){$errors['documentref']=get_string('dv_reference','local_kbacompany');}}
        }
        if($action==='confirm' && !isset($errors['ownerpositionuuid'])){
            global $DB;$position=trim((string)($data['ownerpositionuuid']??''));
            if($position!==''&&!$DB->record_exists('local_kbacompany_position',['companyuuid'=>$company,'uuid'=>$position,'active'=>1])){$errors['ownerpositionuuid']=get_string('dv_reference','local_kbacompany');}
            if(!empty($data['object'])&&!empty($data['duedate'])&&!isset($errors['duedate'])){
                $old=self::read_record($company,$actor,(string)$data['object']);
                if($data['duedate']<$old->observedon){$errors['duedate']=get_string('dv_chronology','local_kbacompany');}
            }
        }
        if($action==='check' && isset($data['outcome']) && !empty($data['object'])){
            $old=self::read_record($company,$actor,(string)$data['object']);
            if($old->entrytype==='manual'&&!in_array($data['outcome'],['pass','fail'],true)){$errors['outcome']=get_string('dvu_required','local_kbacompany');}
        }
        return $errors;
    }

    /** Bound approval list; optional search/paging instead of loading the whole execution workspace. */
    public static function norm_options(string $company,int $actor,string $query='',int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;self::authorise($company,$actor);
        $where='a.companyuuid=:c AND a.revoked=0';$p=['c'=>$company];
        if(\core_text::strlen($query)>200){self::fail('invalid');}
        if($query!==''){$where.=' AND '.$DB->sql_like('o.name',':q',false);$p['q']='%'.$DB->sql_like_escape($query).'%';}
        $sql="SELECT a.* FROM {local_kbacompany_wfapprove} a JOIN {local_kbacompany_acobj} o ON o.companyuuid=a.companyuuid AND o.uuid=a.objectuuid
              WHERE $where AND o.kind='function' ORDER BY a.id DESC";
        $rows=$DB->get_records_sql($sql,$p,max(0,$page)*25,25);$options=[];
        foreach($rows as $a){$snap=json_decode($a->snapshot,true);foreach($snap['objects']??[] as $obj){if(($obj['kind']??'')!=='target'||($obj['parentuuid']??'')!==($snap['object']['uuid']??'')){continue;}$n=json_decode($obj['payload'],true);$options[]=['value'=>$a->uuid.':'.$obj['uuid'],'label'=>($snap['object']['name']??'').' / '.$obj['name'].' / '.($n['periodstart']??'').' / '.($n['amount']??'')];}}
        return $options;
    }

    public static function position_options(string $company,int $actor,string $query='',int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;self::authorise($company,$actor);if(\core_text::strlen($query)>200){self::fail('invalid');}
        $p=['c'=>$company];$w='companyuuid=:c AND active=1';if($query!==''){$w.=' AND '.$DB->sql_like('name',':q',false);$p['q']='%'.$DB->sql_like_escape($query).'%';}
        $out=[];foreach($DB->get_records_select('local_kbacompany_position',$w,$p,'name ASC,id ASC','id,uuid,name',max(0,$page)*40,40) as $r){$out[]=['value'=>$r->uuid,'label'=>$r->name];}return $out;
    }

    public static function task_options(string $company,int $actor,string $query='',int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;self::authorise($company,$actor);list($w,$p)=task_service::readable_query_conditions($company,$actor);
        if(\core_text::strlen($query)>200){self::fail('invalid');}
        $w.=" AND t.status NOT IN ('cancelled','deleted')";if($query!==''){$w.=' AND '.$DB->sql_like('t.title',':q',false);$p['q']='%'.$DB->sql_like_escape($query).'%';}
        $out=[];foreach($DB->get_records_sql("SELECT t.id,t.uuid,t.title,t.status FROM {local_kbacompany_task} t WHERE $w ORDER BY t.title,t.id",$p,max(0,$page)*40,40) as $r){$out[]=['value'=>$r->uuid,'label'=>$r->title.' · '.get_string('taskcenter_status_'.$r->status,'local_kbacompany')];}return $out;
    }

    public static function record(string $company,int $actor,string $uuid,string $tab='overview',int $page=0,int $legacypage=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;$access=self::access($company,$actor);$row=self::read_record($company,$actor,$uuid);
        $out=$access+['selected'=>$row,'actions'=>self::actions($row,$actor,$access['can_manage']),'links'=>[],'checks'=>[],'history'=>[],
            'total'=>0,'page'=>0,'legacy_total'=>0,'legacy_page'=>0,'pagesize'=>25,'basis'=>null,'document'=>null,'ownername'=>'','authorname'=>''];
        if($row->entrytype==='quantitative'){$out['basis']=self::norm($company,$actor,(string)$row->approvaluuid,(string)$row->normuuid,false);}
        if($row->ownerpositionuuid!==''){$out['ownername']=(string)$DB->get_field('local_kbacompany_position','name',['companyuuid'=>$company,'uuid'=>$row->ownerpositionuuid]);}
        $author=$DB->get_record_sql('SELECT u.id,u.firstname,u.lastname FROM {user} u WHERE u.id=:a AND EXISTS (SELECT m.id FROM {local_kbacompany_member} m WHERE m.companyuuid=:c AND m.userid=u.id)', ['c'=>$company,'a'=>$row->createdby]);
        $out['authorname']=$author?fullname($author):get_string('dvu_author_unavailable','local_kbacompany');
        if($row->basisref){$ref=json_decode($row->basisref,true);if(($ref['kind']??'')==='document'){
            // Do not reveal a historic document to a reporter after its visibility changes.
            $w=['companyuuid'=>$company,'uuid'=>$ref['uuid'],'revision'=>(int)$ref['revision']];if(!$access['can_manage']){$w['status']='effective';}
            $out['document']=$DB->get_record('local_kbacompany_doc',$w,'id,uuid,revision,title,code,status')?:null;
        }}
        if($tab==='history'){
            $where='companyuuid=:c AND objectuuid=:d AND '.$DB->sql_like('action',':prefix');$p=['c'=>$company,'d'=>$uuid,'prefix'=>'dv\_%'];
            $out['total']=$DB->count_records_select('local_kbacompany_aclog',$where,$p);$out['page']=max(0,min($page,max(0,(int)ceil($out['total']/25)-1)));
            $entries=$DB->get_records_select('local_kbacompany_aclog',$where,$p,'id DESC','id,actorid,action,oldvalue,newvalue,timecreated',$out['page']*25,25);
            foreach($entries as $e){$data=json_decode($e->newvalue,true)?:[];$person=$DB->get_record_sql('SELECT u.id,u.firstname,u.lastname FROM {user} u WHERE u.id=:a AND EXISTS (SELECT m.id FROM {local_kbacompany_member} m WHERE m.companyuuid=:c AND m.userid=u.id)',['a'=>$e->actorid,'c'=>$company]);
                $out['history'][]=['action'=>$e->action,'actorid'=>$e->actorid,'actorname'=>$person?fullname($person):get_string('dvu_author_unavailable','local_kbacompany'),'timecreated'=>$e->timecreated,'reason'=>$data['reason']??''];}
        }else if($tab==='checks'){
            $w=['companyuuid'=>$company,'deviationuuid'=>$uuid];$out['total']=$DB->count_records('local_kbacompany_devcheck',$w);$out['page']=max(0,min($page,max(0,(int)ceil($out['total']/25)-1)));
            $out['checks']=array_values($DB->get_records('local_kbacompany_devcheck',$w,'id DESC','*',$out['page']*25,25));
        }else if($tab==='tasks'){
            list($tw,$tp)=task_service::readable_query_conditions($company,$actor);
            $base="FROM {local_kbacompany_devlink} l JOIN {local_kbacompany_task} t ON t.companyuuid=l.companyuuid AND t.uuid=l.taskuuid
                WHERE l.companyuuid=:lc AND l.deviationuuid=:ld AND l.taskengine='bms' AND $tw";
            $p=$tp+['lc'=>$company,'ld'=>$uuid];$out['total']=$DB->count_records_sql('SELECT COUNT(l.id) '.$base,$p);$out['page']=max(0,min($page,max(0,(int)ceil($out['total']/25)-1)));
            $out['links']=array_values($DB->get_records_sql('SELECT l.id,l.taskuuid,l.taskengine,l.active,t.title,t.status,t.duedate,t.product '.$base.' ORDER BY l.id DESC',$p,$out['page']*25,25));
            if($access['can_manage']){
                $legacybase="FROM {local_kbacompany_devlink} l JOIN {local_kbacompany_wfwork} t ON t.companyuuid=l.companyuuid AND t.uuid=l.taskuuid
                    WHERE l.companyuuid=:c AND l.deviationuuid=:d AND l.taskengine='workflow'";
                $lp=['c'=>$company,'d'=>$uuid];
                $out['legacy_total']=$DB->count_records_sql('SELECT COUNT(l.id) '.$legacybase,$lp);
                $out['legacy_page']=max(0,min($legacypage,max(0,(int)ceil($out['legacy_total']/25)-1)));
                $legacy=$DB->get_records_sql("SELECT l.id,l.taskuuid,l.taskengine,l.active,t.name AS title,t.status,'' AS duedate,'' AS product ".$legacybase.' ORDER BY l.id DESC',$lp,$out['legacy_page']*25,25);
                $out['legacy_links']=array_values($legacy);
            }
        }
        return $out;
    }

    public static function command(string $company,int $actor,string $action,array $data): string {
        $company = company_context_service::normalise_uuid($company);
        $access=self::access($company,$actor);
        if($action==='report') { if(!$access['can_report']){self::fail('permission');}return self::manual_command($company,$actor,$action,$data); }
        if($action==='create'){return self::quantitative_command($company,$actor,$action,$data);}
        $row=self::read_record($company,$actor,trim((string)($data['object']??'')));
        if($row->entrytype==='manual'){return self::manual_command($company,$actor,$action,$data);}
        return self::quantitative_command($company,$actor,$action,$data);
    }

    private static function allocate_code(string $company): string {
        global $DB;
        return stable_code_service::next($company,'deviation','DEV',static function($candidate)use($DB,$company){
            return !$DB->record_exists('local_kbacompany_deviation',['companyuuid'=>$company,'code'=>$candidate]);
        });
    }

    private static function manual_command(string $company,int $actor,string $action,array $data): string {
        global $DB;$access=self::access($company,$actor);
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('cascade:'.$company,10);if(!$lock){self::fail('lock');}
        try{
            $target=trim((string)($data['object']??''));
            if($action!=='report'){self::read_record($company,$actor,$target);}
            $tasklocks=self::lock_tasks($company,$target,$action,$data);
            $tx=$DB->start_delegated_transaction();$now=time();$uuid=trim((string)($data['object']??''));$reason='';$related='';
            $old=$action==='report'?null:self::read_record($company,$actor,$uuid);
            if($old && ((int)$old->revision!==(int)($data['revision']??0)||empty(self::actions($old,$actor,$access['can_manage'])[$action]))){self::fail('state');}
            if(self::form_errors($company,$actor,$action,$data)){self::fail('invalid');}
            if($action==='report'||$action==='editreport'){
                if($action==='report'&&$uuid!==''){self::fail('reference');}
                $new=$old?clone $old:(object)['companyuuid'=>$company,'uuid'=>self::uuid(),'entrytype'=>'manual','code'=>self::allocate_code($company),
                    'approvaluuid'=>null,'normuuid'=>null,'actual'=>null,'cause'=>'','criterion'=>'','ownerpositionuuid'=>'','duedate'=>'',
                    'status'=>'reported','revision'=>0,'createdby'=>$actor,'analysedby'=>0,'confirmedby'=>0,'closedby'=>0,'timecreated'=>$now];
                foreach(['title','description','requirement','location','observedon','evidence'] as $key){$new->$key=trim((string)($data[$key]??''));}
                $reference=trim((string)($data['documentref']??''));$previous=$old?json_decode((string)$old->basisref,true):null;
                $unchanged=$previous&&$reference===($previous['uuid'].'|'.$previous['revision']);
                $new->basisref=$unchanged?$old->basisref:self::validate_basis($company,$reference);$uuid=$new->uuid;
            }else{
                $new=clone $old;
                if($action==='confirm'){
                    $new->criterion=self::text($data,'criterion');$reason=self::text($data,'reason');
                    $new->ownerpositionuuid=trim((string)($data['ownerpositionuuid']??''));
                    if($new->ownerpositionuuid!==''&&!$DB->record_exists('local_kbacompany_position',['companyuuid'=>$company,'uuid'=>$new->ownerpositionuuid,'active'=>1])){self::fail('reference');}
                    $new->duedate=trim((string)($data['duedate']??''));
                    if($new->duedate!==''&&$new->duedate<$old->observedon){self::fail('chronology');}
                    $new->status='open';$new->confirmedby=$actor;
                }else if($action==='reject'||$action==='cancel'){
                    $reason=self::text($data,'reason');$new->status=$action==='reject'?'rejected':'cancelled';$new->closedby=$actor;
                }else if($action==='analyze'){
                    $new->cause=self::text($data,'cause');$new->analysedby=$actor;$new->status=self::tasks($company,$uuid,false)?'correcting':'analyzed';
                }else if($action==='link'||$action==='unlink'){
                    $reason=self::text($data,'reason');$related=self::text($data,'task');
                    self::change_task_link($company,$actor,$uuid,$related,$action,$reason,'bms');
                    $new->status=self::tasks($company,$uuid,false)?'correcting':'analyzed';
                }else if($action==='check'){
                    $date=self::text($data,'observedon');$evidence=self::text($data,'evidence');$outcome=(string)($data['outcome']??'');
                    if(!in_array($outcome,['pass','fail'],true)){self::fail('invalid');}
                    $tasks=self::tasks($company,$uuid,true);self::check_chronology($company,$old,$date,$tasks);
                    $check=(object)['companyuuid'=>$company,'uuid'=>self::uuid(),'deviationuuid'=>$uuid,'approvaluuid'=>null,'normuuid'=>null,'actual'=>null,
                        'observedon'=>$date,'evidence'=>$evidence,'tasksfingerprint'=>self::manual_check_hash($old,$tasks),'inrange'=>(int)($outcome==='pass'),'createdby'=>$actor,'timecreated'=>$now];
                    $DB->insert_record('local_kbacompany_devcheck',$check);$related=$check->uuid;$new->status=$outcome==='pass'?'verified':'correcting';
                }else if($action==='close'){
                    $reason=self::text($data,'reason');$last=self::last_check($company,$uuid);$tasks=self::tasks($company,$uuid,true);
                    if(!$last||!$last->inrange||trim((string)$last->evidence)===''||!hash_equals($last->tasksfingerprint,self::manual_check_hash($old,$tasks))){self::fail('checkrequired');}
                    $new->status='closed';$new->closedby=$actor;
                }else{self::fail('invalid');}
            }
            $new->revision++;$new->timemodified=$now;
            if($old){$DB->update_record('local_kbacompany_deviation',$new);}else{$new->id=$DB->insert_record('local_kbacompany_deviation',$new);}
            self::log($company,$uuid,$actor,$action,$old,$new,$reason,$related);$tx->allow_commit();return $uuid;
        }catch(\Throwable $e){if(isset($tx)){$tx->rollback($e);}throw $e;}finally{foreach(array_reverse($tasklocks??[]) as $tasklock){$tasklock->release();}$lock->release();}
    }

    private static function manual_check_hash(\stdClass $row,array $tasks): string {
        return hash('sha256',json_encode(['criterion'=>$row->criterion,'cause'=>$row->cause,'tasks'=>$tasks],JSON_UNESCAPED_UNICODE));
    }
    private static function last_check(string $company,string $uuid): ?\stdClass {
        global $DB;$rows=$DB->get_records('local_kbacompany_devcheck',['companyuuid'=>$company,'deviationuuid'=>$uuid],'id DESC','*',0,1);return $rows?reset($rows):null;
    }
    private static function check_chronology(string $company,\stdClass $row,string $date,array $tasks): void {
        $last=self::last_check($company,$row->uuid);
        if($date<$row->observedon||($last&&$date<$last->observedon)){self::fail('chronology');}
        foreach($tasks as $t){$task=self::row(isset($t['engine'])&&$t['engine']==='bms'?'local_kbacompany_task':'local_kbacompany_wfwork',$company,$t['uuid']);
            $accepted=isset($t['engine'])&&$t['engine']==='bms'?(int)$task->completedat:(int)$task->timemodified;
            if($accepted<=0||$date<userdate($accepted,'%Y-%m-%d')){self::fail('chronology');}}
    }
    private static function change_task_link(string $company,int $actor,string $uuid,string $taskuuid,string $action,string $reason,string $engine): void {
        global $DB;
        $where=['companyuuid'=>$company,'deviationuuid'=>$uuid,'taskuuid'=>$taskuuid];$link=$DB->get_record('local_kbacompany_devlink',$where);
        if($link&&$link->taskengine!==$engine){self::fail('reference');}
        if($action==='link'){
            if($engine==='bms'){$task=task_service::readable_projection($company,$actor,$taskuuid);if(!$task||in_array($task['status'],['cancelled','deleted'],true)){self::fail('reference');}}
            else{$task=self::row('local_kbacompany_wfwork',$company,$taskuuid);if($task->kind!=='task'||$task->status==='cancelled'){self::fail('reference');}}
        }
        if($action==='link'){
            if($link&&$link->active){self::fail('duplicate');}
            if($link){$link->active=1;$DB->update_record('local_kbacompany_devlink',$link);}
            else{$DB->insert_record('local_kbacompany_devlink',(object)($where+['uuid'=>self::uuid(),'taskengine'=>$engine,'reason'=>$reason,'createdby'=>$actor,'active'=>1,'timecreated'=>time()]));}
        }else{if(!$link||!$link->active){self::fail('reference');}$link->active=0;$DB->update_record('local_kbacompany_devlink',$link);}
    }

    /** Acquire fixed-order canonical task locks before the transaction; the legacy workflow shares cascade lock. */
    private static function lock_tasks(string $company,string $uuid,string $action,array $data): array {
        global $DB;$ids=[];$locks=[];
        if(!in_array($action,['link','unlink','check','close'],true)){return [];}
        foreach($DB->get_records('local_kbacompany_devlink',['companyuuid'=>$company,'deviationuuid'=>$uuid,'taskengine'=>'bms','active'=>1],'taskuuid ASC','id,taskuuid') as $l){$ids[$l->taskuuid]=true;}
        if(in_array($action,['link','unlink'],true)&&($data['taskengine']??'bms')==='bms'){$t=trim((string)($data['task']??''));if($t!==''&&strlen($t)<=36){$ids[$t]=true;}}
        ksort($ids);
        try{foreach(array_keys($ids) as $id){$l=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:'.$company.':'.$id,10);if(!$l){self::fail('lock');}$locks[]=$l;}return $locks;}
        catch(\Throwable $e){foreach(array_reverse($locks) as $l){$l->release();}throw $e;}
    }

    public static function linked_options(string $company,int $actor,string $uuid,string $query='',int $page=0): array {
        $company = company_context_service::normalise_uuid($company);
        global $DB;self::authorise($company,$actor);self::read_record($company,$actor,$uuid);
        if(\core_text::strlen($query)>200){self::fail('invalid');}
        $w='l.companyuuid=:c AND l.deviationuuid=:d AND l.active=1';$p=['c'=>$company,'d'=>$uuid];
        if($query!==''){$w.=' AND ('.$DB->sql_like('t.title',':qt',false).' OR '.$DB->sql_like('w.name',':qw',false).')';$p['qt']='%'.$DB->sql_like_escape($query).'%';$p['qw']=$p['qt'];}
        $sql="SELECT l.id,l.taskuuid,l.taskengine,t.title,w.name FROM {local_kbacompany_devlink} l
            LEFT JOIN {local_kbacompany_task} t ON t.companyuuid=l.companyuuid AND t.uuid=l.taskuuid AND l.taskengine='bms' AND t.status<>'deleted'
            LEFT JOIN {local_kbacompany_wfwork} w ON w.companyuuid=l.companyuuid AND w.uuid=l.taskuuid AND l.taskengine='workflow'
            WHERE $w ORDER BY l.id DESC";
        $out=[];foreach($DB->get_records_sql($sql,$p,max(0,$page)*40,40) as $r){$out[]=['value'=>$r->taskengine.'|'.$r->taskuuid,'label'=>$r->title?:($r->name?:get_string('dvu_reference_unavailable','local_kbacompany'))];}return $out;
    }
}
