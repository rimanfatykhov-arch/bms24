<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Managed transfer/authorization records for canonical BMS Functions. */
class function_transfer_service {
    public const CONTRACT_VERSION = '0.7.7';
    public const TYPES = ['delegation', 'supervised', 'permanent'];
    public const STATES = ['draft', 'pending', 'active', 'accepted', 'revoked'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'function_transfer',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'rules' => [
                'transfer_references_canonical_function',
                'authorization_record_does_not_grant_moodle_capabilities',
                'transfer_does_not_rewrite_organization_or_staffing',
                'target_acceptance_is_explicit',
                'temporary_transfer_can_expire_without_mutating_function',
                'all_queries_are_company_scoped',
            ],
        ];
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return function_service::can_manage($companyuuid, $userid);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function function_row(string $companyuuid, string $functionuuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_function',
            ['companyuuid' => $companyuuid, 'uuid' => $functionuuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { throw new \moodle_exception('functiontransfer_invalidfunction', 'local_kbacompany'); }
        $row = reset($rows);
        if ($row->status !== 'published') {
            throw new \moodle_exception('functiontransfer_requirespublished', 'local_kbacompany');
        }
        return $row;
    }

    private static function validate_employee(string $companyuuid, int $userid): void {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('local_kbacompany_member',
                ['companyuuid' => $companyuuid, 'userid' => $userid, 'active' => 1])) {
            throw new \moodle_exception('functiontransfer_invalidemployee', 'local_kbacompany');
        }
    }

    private static function validate_position(string $companyuuid, string $positionuuid): void {
        global $DB;
        if ($positionuuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1])) {
            throw new \moodle_exception('functiontransfer_invalidposition', 'local_kbacompany');
        }
    }

    public static function create(string $companyuuid, int $actorid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $functionuuid = trim((string)($data['functionuuid'] ?? ''));
        $function = self::function_row($companyuuid, $functionuuid);
        $targetuserid = (int)($data['targetuserid'] ?? 0);
        self::validate_employee($companyuuid, $targetuserid);
        $targetpositionuuid = trim((string)($data['targetpositionuuid'] ?? ''));
        self::validate_position($companyuuid, $targetpositionuuid);
        $type = (string)($data['transfertype'] ?? 'delegation');
        if (!in_array($type, self::TYPES, true)) {
            throw new \moodle_exception('functiontransfer_invalidtype', 'local_kbacompany');
        }
        $starts = max(0, (int)($data['startsat'] ?? time()));
        $ends = max(0, (int)($data['endsat'] ?? 0));
        if ($ends > 0 && $ends <= $starts) {
            throw new \moodle_exception('functiontransfer_invaliddates', 'local_kbacompany');
        }
        $sourceuserid = (int)($data['sourceuserid'] ?? 0);
        if ($sourceuserid > 0) { self::validate_employee($companyuuid, $sourceuserid); }
        $now = time();
        $uuid = self::uuid();
        $DB->insert_record('local_kbacompany_functxfer', (object)[
            'uuid' => $uuid,
            'companyuuid' => $companyuuid,
            'functionuuid' => $functionuuid,
            'sourcepositionuuid' => (string)$function->positionuuid,
            'sourceuserid' => $sourceuserid,
            'targetpositionuuid' => $targetpositionuuid,
            'targetuserid' => $targetuserid,
            'transfertype' => $type,
            'status' => 'draft',
            'authorityscope' => trim((string)($data['authorityscope'] ?? '')),
            'handovernote' => trim((string)($data['handovernote'] ?? '')),
            'startsat' => $starts,
            'endsat' => $ends,
            'authorizedby' => 0,
            'authorizedat' => 0,
            'acceptedby' => 0,
            'acceptedat' => 0,
            'revokedby' => 0,
            'revokedat' => 0,
            'createdby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $uuid;
    }

    public static function submit(string $companyuuid, int $actorid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $row = self::record($companyuuid, $uuid);
        if ($row->status !== 'draft') { throw new \moodle_exception('functiontransfer_invalidstate', 'local_kbacompany'); }
        $row->status = 'pending'; $row->timemodified = time();
        $DB->update_record('local_kbacompany_functxfer', $row);
    }

    public static function authorize(string $companyuuid, int $actorid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $row = self::record($companyuuid, $uuid);
        if (!in_array($row->status, ['draft', 'pending'], true)) {
            throw new \moodle_exception('functiontransfer_invalidstate', 'local_kbacompany');
        }
        $row->status = 'active'; $row->authorizedby = $actorid; $row->authorizedat = time(); $row->timemodified = time();
        $DB->update_record('local_kbacompany_functxfer', $row);
    }

    public static function accept(string $companyuuid, int $actorid, string $uuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actorid);
        $row = self::record($companyuuid, $uuid);
        if ((int)$row->targetuserid !== $actorid || $row->status !== 'active') {
            throw new \moodle_exception('functiontransfer_cannotaccept', 'local_kbacompany');
        }
        $row->status = 'accepted'; $row->acceptedby = $actorid; $row->acceptedat = time(); $row->timemodified = time();
        $DB->update_record('local_kbacompany_functxfer', $row);
    }

    public static function revoke(string $companyuuid, int $actorid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $actorid);
        $row = self::record($companyuuid, $uuid);
        if ($row->status === 'revoked') { return; }
        $row->status = 'revoked'; $row->revokedby = $actorid; $row->revokedat = time(); $row->timemodified = time();
        $DB->update_record('local_kbacompany_functxfer', $row);
    }

    private static function record(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_functxfer',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], '*', MUST_EXIST);
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $functionctx = function_service::context($companyuuid, $userid);
        $functions = [];
        foreach ($functionctx['functions'] as $function) {
            if ($function['status'] === 'published') { $functions[$function['uuid']] = $function; }
        }
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid'=>$companyuuid,'active'=>1], 'code ASC') as $row) {
            $positions[$row->uuid] = ['uuid'=>(string)$row->uuid,'code'=>(string)$row->code,'name'=>(string)$row->name];
        }
        $org = organization_service::organization_context($companyuuid, $userid);
        $employees = [];
        foreach ($org['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = $employee;
        }
        $rows = $canmanage
            ? $DB->get_records('local_kbacompany_functxfer', ['companyuuid'=>$companyuuid], 'timecreated DESC')
            : $DB->get_records('local_kbacompany_functxfer', ['companyuuid'=>$companyuuid,'targetuserid'=>$userid], 'timecreated DESC');
        $transfers = [];
        $now = time();
        foreach ($rows as $row) {
            $effective = (string)$row->status;
            if (in_array($effective, ['active','accepted'], true) && (int)$row->endsat > 0 && (int)$row->endsat < $now) {
                $effective = 'expired';
            }
            $f = $functions[$row->functionuuid] ?? null;
            $transfers[] = [
                'uuid'=>(string)$row->uuid,
                'function_uuid'=>(string)$row->functionuuid,
                'function_code'=>$f ? $f['code'] : '',
                'function_name'=>$f ? $f['name'] : '',
                'source_position_uuid'=>(string)$row->sourcepositionuuid,
                'source_position_name'=>$positions[$row->sourcepositionuuid]['name'] ?? '',
                'source_user_name'=>$employees[(int)$row->sourceuserid]['full_name'] ?? '',
                'target_position_uuid'=>(string)$row->targetpositionuuid,
                'target_position_name'=>$positions[$row->targetpositionuuid]['name'] ?? '',
                'target_user_name'=>$employees[(int)$row->targetuserid]['full_name'] ?? '',
                'target_userid'=>(int)$row->targetuserid,
                'transfer_type'=>(string)$row->transfertype,
                'status'=>(string)$row->status,
                'effective_status'=>$effective,
                'authority_scope'=>(string)$row->authorityscope,
                'handover_note'=>(string)$row->handovernote,
                'starts_at'=>(int)$row->startsat,
                'ends_at'=>(int)$row->endsat,
                'authorized_by'=>(int)$row->authorizedby,
                'authorized_at'=>(int)$row->authorizedat,
                'accepted_by'=>(int)$row->acceptedby,
                'accepted_at'=>(int)$row->acceptedat,
            ];
        }
        return [
            'company'=>$company,
            'can_manage'=>$canmanage,
            'functions'=>array_values($functions),
            'positions'=>array_values($positions),
            'employees'=>array_values($employees),
            'transfers'=>$transfers,
        ];
    }
}
