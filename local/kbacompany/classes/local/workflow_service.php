<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Explicit, company-scoped execution of approved cascade snapshots. */
class workflow_service {
    public static function contract(): array {
        return ['version' => '0.2.5.3', 'scope' => 'cascade_execution_mvp',
            'recurrence' => ['weekly', 'monthly', 'explicit_draft_creation', 'checkpoint_acknowledgement'],
            'objects' => ['RecurringCycle', 'FunctionAssignment', 'ApprovalSnapshot', 'OperationalPlan', 'Task', 'Evidence'],
            'task_states' => ['draft', 'open', 'doing', 'review', 'returned', 'accepted', 'cancelled'],
            'plan_states' => ['draft', 'active', 'closed', 'cancelled'],
            'rules' => ['explicit_assignment', 'immutable_approval_snapshot', 'separate_reviewer',
                'no_automatic_competency_or_maturity_change', 'text_and_url_evidence', 'no_bpmn_execution'],
            'deferred' => ['scheduled_assignments', 'multiple_primary_executors', 'file_evidence',
                'cron_recurrence', 'escalation', 'org_design_versions', 'pdf_export']];
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) .
            '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('wf_' . $key, 'local_kbacompany');
    }

    private static function row(string $table, string $company, string $uuid): \stdClass {
        global $DB;
        $r = $DB->get_record($table, ['companyuuid' => $company, 'uuid' => $uuid]);
        if (!$r) { self::fail('reference'); }
        return $r;
    }

    private static function text(array $data, string $key, int $length = 4000): string {
        $value = trim((string)($data[$key] ?? ''));
        if ($value === '' || \core_text::strlen($value) > $length) { self::fail('invalid'); }
        return $value;
    }

    private static function employee(array $company, int $id): void {
        global $DB;
        $m = legacy_company_adapter::membership((int)$company['compatibility']['source_id'], $id);
        if (!$m || empty($m->isemployee) || !$DB->record_exists('user',
                ['id' => $id, 'deleted' => 0, 'suspended' => 0])) { self::fail('employee'); }
    }

    /** Capture normative dependencies, including organizational labels, without live person data. */
    public static function bundle(string $company, string $uuid, int $actor): array {
        global $DB;
        company_context_service::context($company, $actor);
        $main = self::row('local_kbacompany_acobj', $company, $uuid);
        if ($main->status !== 'draft') { self::fail('reference'); }
        $records = [];
        $add = static function($r) use (&$records, $company): void {
            while ($r) {
                if (isset($records[$r->uuid])) { break; }
                if ($r->status !== 'draft') { self::fail('reference'); }
                $records[$r->uuid] = $r;
                $r = $r->parentuuid === '' ? null : self::row('local_kbacompany_acobj', $company, $r->parentuuid);
            }
        };
        $add($main);
        $references = [];
        $links = [];
        if ($main->kind === 'function') {
            $p = json_decode($main->payload, true);
            $result = $DB->get_record('local_kbacompany_acobj',
                ['companyuuid' => $company, 'parentuuid' => $uuid, 'kind' => 'result', 'status' => 'draft']);
            if (!$result || empty($p['mainstat'])) { self::fail('incomplete'); }
            $add($result);
            $rp = json_decode($result->payload, true);
            $stat = self::row('local_kbacompany_stat', $company, $p['mainstat']);
            if (empty($rp['product']) || $stat->productuuid !== $rp['product']) { self::fail('incomplete'); }
            $references['stat'] = $stat;
            $references['product'] = self::row('local_kbacompany_product', $company, $rp['product']);
            $references['node'] = self::row('local_kbacompany_orgnode', $company, $p['node']);
            if (!$references['node']->active) { self::fail('reference'); }
            if (!empty($p['position'])) {
                $references['position'] = self::row('local_kbacompany_position', $company, $p['position']);
                if (!$references['position']->active) { self::fail('reference'); }
            }
            foreach ($DB->get_records('local_kbacompany_acobj',
                    ['companyuuid' => $company, 'parentuuid' => $uuid, 'kind' => 'target', 'status' => 'draft'], 'uuid ASC') as $t) {
                $add($t);
            }
            $links = array_values($DB->get_records('local_kbacompany_aclink',
                ['companyuuid' => $company, 'functionuuid' => $uuid], 'planuuid ASC'));
            if (!$links) { self::fail('incomplete'); }
            foreach ($links as $link) { $add(self::row('local_kbacompany_acobj', $company, $link->planuuid)); }
        }
        ksort($records);
        return ['object' => $main, 'objects' => array_values($records), 'references' => $references, 'links' => $links];
    }

    private static function fingerprint(array $bundle): string {
        return hash('sha256', json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function preview(string $company, string $object, int $actor): array {
        $bundle = self::bundle($company, $object, $actor);
        return ['bundle' => $bundle, 'fingerprint' => self::fingerprint($bundle)];
    }

    private static function approval(string $company, string $uuid, int $actor): \stdClass {
        global $DB;
        $fingerprint = self::fingerprint(self::bundle($company, $uuid, $actor));
        $records = $DB->get_records('local_kbacompany_wfapprove',
            ['companyuuid' => $company, 'objectuuid' => $uuid, 'fingerprint' => $fingerprint, 'revoked' => 0], 'id DESC', '*', 0, 1);
        if (!$records) { self::fail('approvalrequired'); }
        return reset($records);
    }

    private static function assignment(string $company, string $function): \stdClass {
        global $DB;
        $r = $DB->get_record('local_kbacompany_wfassign',
            ['companyuuid' => $company, 'functionuuid' => $function, 'timeended' => 0]);
        if (!$r) { self::fail('assignmentrequired'); }
        return $r;
    }

    private static function revision(\stdClass $work, array $data): void {
        if ((int)$work->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
    }

    /** All commands share the cascade lock; changes and audit log commit together. */
    public static function command(string $uuid, int $actor, string $action, array $data): string {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $company = company_context_service::context($uuid, $actor);
        $manager = cascade_service::can_manage($uuid, $actor);
        if (!in_array($action, ['start', 'submit', 'accept', 'return'], true) && !$manager) {
            self::fail('permission');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('cascade:' . $uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            $now = time();
            if (!empty($data['reason'])) { self::text($data, 'reason'); }
            $old = null;
            $new = null;
            $target = (string)($data['object'] ?? '');
            if ($action === 'repeat') {
                $target = self::repeat_plan($uuid, $company, $actor, $data);
                $new = self::row('local_kbacompany_wfwork', $uuid, $target);
            } else if ($action === 'checkpoint') {
                $old = self::row('local_kbacompany_wfrepeat', $uuid, $target);
                if ($old->checkedat) { self::fail('state'); }
                self::text($data, 'reason');
                $new = clone $old;
                $new->checkedat = $now;
                $DB->update_record('local_kbacompany_wfrepeat', $new);
            } else if ($action === 'approve') {
                $bundle = self::bundle($uuid, $target, $actor);
                if ((int)$bundle['object']->revision !== (int)($data['revision'] ?? 0)) { self::fail('conflict'); }
                $hash = self::fingerprint($bundle);
                if (!hash_equals($hash, (string)($data['fingerprint'] ?? ''))) { self::fail('conflict'); }
                if ($DB->record_exists('local_kbacompany_wfapprove',
                        ['companyuuid' => $uuid, 'objectuuid' => $target, 'fingerprint' => $hash, 'revoked' => 0])) {
                    self::fail('alreadyapproved');
                }
                $new = (object)['companyuuid' => $uuid, 'uuid' => self::uuid(), 'objectuuid' => $target,
                    'revision' => $bundle['object']->revision, 'fingerprint' => $hash,
                    'snapshot' => json_encode($bundle, JSON_UNESCAPED_UNICODE), 'approvedby' => $actor,
                    'timecreated' => $now, 'revoked' => 0];
                $DB->insert_record('local_kbacompany_wfapprove', $new);
            } else if ($action === 'revoke') {
                self::text($data, 'reason');
                $old = self::row('local_kbacompany_wfapprove', $uuid, $target);
                if ($old->revoked) { self::fail('state'); }
                $new = clone $old;
                $new->revoked = 1;
                $DB->update_record('local_kbacompany_wfapprove', $new);
            } else if ($action === 'assign') {
                $function = self::row('local_kbacompany_acobj', $uuid, $target);
                if ($function->kind !== 'function' || $function->status !== 'draft') { self::fail('reference'); }
                $employee = (int)($data['userid'] ?? 0);
                self::employee($company, $employee);
                if ($DB->record_exists('local_kbacompany_wfassign',
                        ['companyuuid' => $uuid, 'functionuuid' => $target, 'timeended' => 0])) { self::fail('assignmentexists'); }
                $new = (object)['companyuuid' => $uuid, 'uuid' => self::uuid(), 'functionuuid' => $target,
                    'userid' => $employee, 'timecreated' => $now, 'timeended' => 0];
                $DB->insert_record('local_kbacompany_wfassign', $new);
            } else if ($action === 'unassign') {
                $old = self::row('local_kbacompany_wfassign', $uuid, $target);
                if ($old->timeended) { self::fail('state'); }
                if ($DB->record_exists_select('local_kbacompany_wfwork',
                        'companyuuid = :c AND assignmentuuid = :a AND status NOT IN (:s1, :s2)',
                        ['c' => $uuid, 'a' => $target, 's1' => 'accepted', 's2' => 'cancelled'])) { self::fail('unfinished'); }
                $new = clone $old;
                $new->timeended = $now;
                $DB->update_record('local_kbacompany_wfassign', $new);
            } else if ($action === 'plan' || $action === 'task') {
                $deadline = self::text($data, 'deadline', 10);
                product_service::validate_period($deadline, 'daily');
                $new = (object)['companyuuid' => $uuid, 'uuid' => self::uuid(), 'kind' => $action,
                    'parentuuid' => '', 'functionuuid' => '', 'assignmentuuid' => '', 'normuuid' => '', 'userid' => 0, 'reviewerid' => 0,
                    'name' => self::text($data, 'name', 255), 'description' => self::text($data, 'description'),
                    'acceptance' => self::text($data, 'acceptance'), 'deadline' => $deadline, 'status' => 'draft',
                    'revision' => 1, 'attempt' => 0, 'timecreated' => $now, 'timemodified' => $now];
                if ($action === 'plan') {
                    $strategy = self::row('local_kbacompany_acobj', $uuid, self::text($data, 'strategy', 36));
                    if ($strategy->kind !== 'strategy') { self::fail('reference'); }
                    $approval = self::approval($uuid, $strategy->uuid, $actor);
                } else {
                    $plan = self::row('local_kbacompany_wfwork', $uuid, self::text($data, 'plan', 36));
                    if ($plan->kind !== 'plan' || $plan->status !== 'draft') { self::fail('state'); }
                    $function = self::row('local_kbacompany_acobj', $uuid, self::text($data, 'function', 36));
                    if ($function->kind !== 'function') { self::fail('reference'); }
                    $planapproval = self::row('local_kbacompany_wfapprove', $uuid, $plan->approvaluuid);
                    if (!$DB->record_exists('local_kbacompany_aclink', ['companyuuid' => $uuid,
                            'planuuid' => $planapproval->objectuuid, 'functionuuid' => $function->uuid])) { self::fail('reference'); }
                    $approval = self::approval($uuid, $function->uuid, $actor);
                    $assignment = self::assignment($uuid, $function->uuid);
                    self::employee($company, (int)$assignment->userid);
                    $reviewer = (int)($data['reviewerid'] ?? 0);
                    self::employee($company, $reviewer);
                    if ($reviewer === (int)$assignment->userid) { self::fail('selfreview'); }
                    if ($deadline > $plan->deadline) { self::fail('deadlineinvalid'); }
                    $new->parentuuid = $plan->uuid;
                    if (!empty($data['norm'])) {
                        $norm = self::row('local_kbacompany_acobj', $uuid, (string)$data['norm']);
                        if ($norm->kind !== 'target' || $norm->status !== 'draft' || $norm->parentuuid !== $function->uuid) {
                            self::fail('reference');
                        }
                        $new->normuuid = $norm->uuid;
                    }
                    $new->functionuuid = $function->uuid;
                    $new->assignmentuuid = $assignment->uuid;
                    $new->userid = $assignment->userid;
                    $new->reviewerid = $reviewer;
                }
                $new->approvaluuid = $approval->uuid;
                $DB->insert_record('local_kbacompany_wfwork', $new);
                $target = $new->uuid;
            } else if (in_array($action, ['activate', 'close', 'cancel', 'start', 'submit', 'accept', 'return'], true)) {
                $old = self::row('local_kbacompany_wfwork', $uuid, $target);
                self::revision($old, $data);
                $new = clone $old;
                if ($action === 'activate') {
                    if ($old->kind !== 'plan' || $old->status !== 'draft') { self::fail('state'); }
                    $pa = self::row('local_kbacompany_wfapprove', $uuid, $old->approvaluuid);
                    if ($pa->revoked || self::approval($uuid, $pa->objectuuid, $actor)->uuid !== $pa->uuid) { self::fail('approvalrequired'); }
                    $children = $DB->get_records('local_kbacompany_wfwork', ['companyuuid' => $uuid, 'parentuuid' => $target]);
                    $count = 0;
                    foreach ($children as $child) {
                        if ($child->status === 'cancelled') { continue; }
                        if ($child->status !== 'draft') { self::fail('state'); }
                        $a = self::approval($uuid, $child->functionuuid, $actor);
                        if ($a->uuid !== $child->approvaluuid || self::assignment($uuid, $child->functionuuid)->uuid !== $child->assignmentuuid) {
                            self::fail('approvalrequired');
                        }
                        self::employee($company, (int)$child->userid);
                        self::employee($company, (int)$child->reviewerid);
                        $before = clone $child;
                        $child->status = 'open';
                        $child->revision++;
                        $child->timemodified = $now;
                        $DB->update_record('local_kbacompany_wfwork', $child);
                        self::log($uuid, $child->uuid, $actor, 'activate', $before, $child);
                        $count++;
                    }
                    if (!$count) { self::fail('emptyplan'); }
                    $new->status = 'active';
                } else if ($action === 'close') {
                    if ($old->kind !== 'plan' || $old->status !== 'active') { self::fail('state'); }
                    $children = $DB->get_records('local_kbacompany_wfwork', ['companyuuid' => $uuid, 'parentuuid' => $target]);
                    $accepted = 0;
                    foreach ($children as $child) {
                        if (!in_array($child->status, ['accepted', 'cancelled'], true)) { self::fail('unfinished'); }
                        $accepted += $child->status === 'accepted' ? 1 : 0;
                    }
                    if (!$accepted) { self::fail('emptyplan'); }
                    $new->status = 'closed';
                } else if ($action === 'cancel') {
                    if (in_array($old->status, ['accepted', 'closed', 'cancelled'], true)) { self::fail('state'); }
                    if ($old->kind === 'plan' && $DB->record_exists_select('local_kbacompany_wfwork',
                            'companyuuid = :c AND parentuuid = :p AND status <> :s',
                            ['c' => $uuid, 'p' => $target, 's' => 'cancelled'])) { self::fail('unfinished'); }
                    self::text($data, 'reason');
                    $new->status = 'cancelled';
                } else {
                    if ($old->kind !== 'task') { self::fail('state'); }
                    $parent = self::row('local_kbacompany_wfwork', $uuid, $old->parentuuid);
                    if ($parent->status !== 'active') { self::fail('state'); }
                    self::employee($company, $actor);
                    if (in_array($action, ['start', 'submit'], true)) {
                        if ((int)$old->userid !== $actor) { self::fail('permission'); }
                        if (!in_array($old->status, ['open', 'doing', 'returned'], true)) { self::fail('state'); }
                        if ($action === 'start') {
                            if ($old->status === 'doing') { self::fail('state'); }
                            $new->status = 'doing';
                        } else {
                            $url = trim((string)($data['url'] ?? ''));
                            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) ||
                                    !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ||
                                    parse_url($url, PHP_URL_USER) !== null || strlen($url) > 2000)) { self::fail('invalid'); }
                            $new->attempt++;
                            $DB->insert_record('local_kbacompany_wfevidence', (object)[
                                'companyuuid' => $uuid, 'uuid' => self::uuid(), 'taskuuid' => $target,
                                'attempt' => $new->attempt, 'body' => self::text($data, 'body'), 'url' => $url,
                                'userid' => $actor, 'timecreated' => $now]);
                            $new->status = 'review';
                        }
                    } else {
                        if ((int)$old->reviewerid !== $actor || (int)$old->userid === $actor) { self::fail('permission'); }
                        if ($old->status !== 'review' || !$DB->record_exists('local_kbacompany_wfevidence',
                                ['companyuuid' => $uuid, 'taskuuid' => $target, 'attempt' => $old->attempt])) { self::fail('state'); }
                        self::text($data, 'reason');
                        $new->status = $action === 'accept' ? 'accepted' : 'returned';
                    }
                }
                $new->revision++;
                $new->timemodified = $now;
                $DB->update_record('local_kbacompany_wfwork', $new);
            } else { self::fail('invalid'); }
            self::log($uuid, $target, $actor, $action, $old, $new, (string)($data['reason'] ?? ''));
            $transaction->allow_commit();
            return $target;
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Calendar dates are calculated in UTC without timezone/DST day-length effects. */
    public static function cycle_start(string $date, string $period): string {
        product_service::validate_period($date, 'daily');
        $d = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        if ($period === 'weekly') { return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d'); }
        if ($period === 'monthly') { return $d->format('Y-m-01'); }
        self::fail('invalid');
        return '';
    }

    public static function cycle_deadline(string $date, string $oldstart, string $newstart, string $period): string {
        if (self::cycle_start($date, $period) !== $oldstart || self::cycle_start($newstart, $period) !== $newstart) {
            self::fail('cycleperiod');
        }
        $zone = new \DateTimeZone('UTC');
        $offset = (int)(new \DateTimeImmutable($oldstart, $zone))->diff(new \DateTimeImmutable($date, $zone))->format('%a');
        $next = (new \DateTimeImmutable($newstart, $zone))->modify($period === 'weekly' ? '+7 days' : '+1 month');
        $result = (new \DateTimeImmutable($newstart, $zone))->modify('+' . $offset . ' days');
        return ($result >= $next ? $next->modify('-1 day') : $result)->format('Y-m-d');
    }

    /** Called only inside the authenticated company lock and transaction in command(). */
    private static function repeat_plan(string $uuid, array $company, int $actor, array $data): string {
        global $DB;
        $source = self::row('local_kbacompany_wfwork', $uuid, self::text($data, 'object', 36));
        if ($source->kind !== 'plan' || !in_array($source->status, ['active', 'closed'], true)) { self::fail('state'); }
        $period = self::text($data, 'period', 10);
        $start = self::text($data, 'periodstart', 10);
        $oldstart = self::cycle_start($source->deadline, $period);
        if ($start <= $oldstart || self::cycle_start($start, $period) !== $start) { self::fail('cycleperiod'); }
        $previous = $DB->get_record('local_kbacompany_wfrepeat', ['companyuuid' => $uuid, 'planuuid' => $source->uuid]);
        if ($previous && $previous->periodtype !== $period) { self::fail('cycleperiod'); }
        $anchor = $previous ? $previous->anchoruuid : $source->uuid;
        $existing = $DB->get_record('local_kbacompany_wfrepeat', ['companyuuid' => $uuid,
            'anchoruuid' => $anchor, 'periodtype' => $period, 'periodstart' => $start]);
        if ($existing) { return $existing->planuuid; }
        $sourceuuid = $source->uuid;
        $source = self::row('local_kbacompany_wfwork', $uuid, $anchor);
        if (!in_array($source->status, ['active', 'closed'], true)) { self::fail('state'); }
        $oldstart = self::cycle_start($source->deadline, $period);
        $pa = self::row('local_kbacompany_wfapprove', $uuid, $source->approvaluuid);
        $approval = self::approval($uuid, $pa->objectuuid, $actor);
        $plan = clone $source;
        unset($plan->id);
        $plan->uuid = self::uuid();
        $plan->approvaluuid = $approval->uuid;
        $plan->deadline = self::cycle_deadline($source->deadline, $oldstart, $start, $period);
        $plan->status = 'draft';
        $plan->revision = 1;
        $plan->attempt = 0;
        $plan->timecreated = $plan->timemodified = time();
        $DB->insert_record('local_kbacompany_wfwork', $plan);
        $count = 0;
        foreach ($DB->get_records('local_kbacompany_wfwork', ['companyuuid' => $uuid, 'parentuuid' => $source->uuid]) as $task) {
            if ($task->status === 'cancelled') { continue; }
            if ($task->kind !== 'task') { self::fail('reference'); }
            $a = self::approval($uuid, $task->functionuuid, $actor);
            if (!$DB->record_exists('local_kbacompany_aclink', ['companyuuid' => $uuid,
                    'planuuid' => $pa->objectuuid, 'functionuuid' => $task->functionuuid])) { self::fail('reference'); }
            $assignment = self::assignment($uuid, $task->functionuuid);
            self::employee($company, (int)$assignment->userid);
            self::employee($company, (int)$task->reviewerid);
            if ((int)$assignment->userid === (int)$task->reviewerid) { self::fail('selfreview'); }
            $sourceapproval = self::row('local_kbacompany_wfapprove', $uuid, $task->approvaluuid);
            unset($task->id);
            $task->uuid = self::uuid();
            $task->parentuuid = $plan->uuid;
            $task->approvaluuid = $a->uuid;
            $task->assignmentuuid = $assignment->uuid;
            $task->userid = $assignment->userid;
            $task->deadline = self::cycle_deadline($task->deadline, $oldstart, $start, $period);
            if ($task->normuuid !== '') {
                $sourcebundle = json_decode($sourceapproval->snapshot, true);
                $oldpayload = null;
                foreach ($sourcebundle['objects'] as $obj) {
                    if ($obj['uuid'] === $task->normuuid && $obj['kind'] === 'target') {
                        $oldpayload = json_decode($obj['payload'], true);
                    }
                }
                if (!$oldpayload) { self::fail('cyclenorm'); }
                $bundle = json_decode($a->snapshot, true);
                $stat = $bundle['references']['stat'];
                $normstart = $stat['periodtype'] === 'daily' ? $task->deadline : self::cycle_start($task->deadline, $stat['periodtype']);
                $matches = [];
                foreach ($bundle['objects'] as $obj) {
                    if ($obj['kind'] !== 'target' || $obj['parentuuid'] !== $task->functionuuid) { continue; }
                    $p = json_decode($obj['payload'], true);
                    if (($p['indicator'] ?? '') === ($oldpayload['indicator'] ?? '') && ($p['periodstart'] ?? '') === $normstart) {
                        $matches[] = $obj['uuid'];
                    }
                }
                if (count($matches) !== 1) { self::fail('cyclenorm'); }
                $task->normuuid = $matches[0];
            }
            $task->status = 'draft';
            $task->revision = 1;
            $task->attempt = 0;
            $task->timecreated = $task->timemodified = time();
            $DB->insert_record('local_kbacompany_wfwork', $task);
            self::log($uuid, $task->uuid, $actor, 'repeat_task', null, $task);
            $count++;
        }
        if (!$count) { self::fail('emptyplan'); }
        $cycle = (object)['companyuuid' => $uuid, 'uuid' => self::uuid(), 'anchoruuid' => $anchor,
            'sourceuuid' => $sourceuuid, 'planuuid' => $plan->uuid, 'periodtype' => $period,
            'periodstart' => $start, 'checkedat' => 0, 'timecreated' => time()];
        $DB->insert_record('local_kbacompany_wfrepeat', $cycle);
        self::log($uuid, $cycle->uuid, $actor, 'repeat_cycle', null, $cycle);
        return $plan->uuid;
    }

    private static function log(string $company, string $object, int $actor, string $action, $old, $new,
            string $reason = ''): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)['companyuuid' => $company,
            'objectuuid' => $object, 'actorid' => $actor, 'action' => 'wf_' . $action,
            'oldvalue' => json_encode($old, JSON_UNESCAPED_UNICODE),
            'newvalue' => json_encode(['record' => $new, 'reason' => $reason], JSON_UNESCAPED_UNICODE), 'timecreated' => time()]);
    }

    public static function context(string $uuid, int $actor, string $selected = ''): array {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $actor);
        $company = company_context_service::context($uuid, $actor);
        $manager = cascade_service::can_manage($uuid, $actor);
        $work = [];
        foreach ($DB->get_records('local_kbacompany_wfwork', ['companyuuid' => $uuid], 'deadline ASC, id ASC') as $r) {
            if ($manager || $r->kind === 'plan' || (int)$r->userid === $actor || (int)$r->reviewerid === $actor) {
                $work[$r->uuid] = $r;
            }
        }
        if ($selected !== '' && !isset($work[$selected])) { self::fail('reference'); }
        $current = $work[$selected] ?? null;
        $history = [];
        $evidence = [];
        if ($current) {
            $history = array_values($DB->get_records('local_kbacompany_aclog',
                ['companyuuid' => $uuid, 'objectuuid' => $selected], 'id DESC', '*', 0, 50));
            $evidence = array_values($DB->get_records('local_kbacompany_wfevidence',
                ['companyuuid' => $uuid, 'taskuuid' => $selected], 'attempt DESC'));
        }
        $assignments = [];
        foreach ($DB->get_records('local_kbacompany_wfassign', ['companyuuid' => $uuid], 'id DESC') as $r) {
            if ($manager || (int)$r->userid === $actor) { $assignments[] = $r; }
        }
        return ['company' => $company, 'can_manage' => $manager, 'work' => array_values($work),
            'cycles' => $manager ? array_values($DB->get_records('local_kbacompany_wfrepeat',
                ['companyuuid' => $uuid], 'periodstart DESC, id DESC')) : [],
            'selected' => $current, 'history' => $history, 'evidence' => $evidence, 'assignments' => $assignments,
            'approvals' => array_values($DB->get_records('local_kbacompany_wfapprove', ['companyuuid' => $uuid], 'id DESC'))];
    }
}
