<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Target-model validation and canonical GAP registry for BMS Business Model Canvas 2.0.
 *
 * Validation belongs to an exact Business Model snapshot. GAP records pin exact AS-IS/TARGET
 * item revisions and reuse canonical BMS Indicator and Financial Scenario domains.
 */
class business_model_validation_service {
    public const CONTRACT_VERSION = '0.1.0';
    public const CONTOURS = ['market', 'client', 'economics', 'resources', 'ownerrequirements'];
    public const VALIDATION_STATES = ['confirmed', 'partial', 'hyp', 'data', 'open', 'conflict'];
    public const GAP_STATUSES = ['candidate', 'confirmed', 'archived'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_business_model_validation_gap',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['BusinessModelValidation', 'BusinessModelGap', 'BusinessModelGapRequirement'],
            'rules' => [
                'validation_pins_exact_business_model_revision',
                'five_validation_contours',
                'owner_requirement_coverage_is_target_only',
                'economic_confirmation_requires_matching_published_financial_scenario',
                'gap_pins_exact_asis_and_target_item_revisions',
                'gap_is_difference_not_cause_or_solution',
                'gap_does_not_create_project_implicitly',
                'gap_result_measure_reuses_canonical_bms_indicator',
                'no_parallel_financial_kpi_project_or_task_engine',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('bmcval_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' .
            substr($h, 16, 4) . '-' . substr($h, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function authorise_view(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
    }

    private static function authorise_manage(string $companyuuid, int $userid): void {
        self::authorise_view($companyuuid, $userid);
        if (!market_model_service::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function model(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision): array {
        if ($canvasuuid === '' || $canvasrevision <= 0) {
            self::fail('reference');
        }
        return business_model_crud_service::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
    }

    /** Current validation row for each contour in one exact Canvas snapshot. */
    private static function current_validation_rows(string $companyuuid, string $canvasuuid, int $canvasrevision): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_bmcval', [
            'companyuuid' => $companyuuid,
            'canvasuuid' => $canvasuuid,
            'canvasrevision' => $canvasrevision,
        ], 'contour ASC, revision ASC, id ASC');
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string)$row->contour] = $row;
        }
        return $latest;
    }

    private static function validation_dto(\stdClass $row): array {
        return [
            'uuid' => (string)$row->uuid,
            'revision' => (int)$row->revision,
            'canvasuuid' => (string)$row->canvasuuid,
            'canvasrevision' => (int)$row->canvasrevision,
            'contour' => (string)$row->contour,
            'state' => (string)$row->state,
            'note' => (string)($row->note ?? ''),
            'evidence' => (string)($row->evidence ?? ''),
            'finscenariouuid' => (string)($row->finscenariouuid ?? ''),
            'finscenariorevision' => (int)($row->finscenariorevision ?? 0),
            'createdby' => (int)$row->createdby,
            'modifiedby' => (int)$row->modifiedby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    /** Published financial scenarios pinned to exactly this Business Model snapshot. */
    public static function financial_scenario_options(string $companyuuid, int $userid, array $model): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $rows = $DB->get_records('local_kbacompany_finscenario', [
            'companyuuid' => $companyuuid,
            'status' => 'published',
            'businessmodeluuid' => (string)$model['uuid'],
            'businessmodelrevision' => (int)$model['revision'],
        ], 'uuid ASC, revision ASC');
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string)$row->uuid] = $row;
        }
        $out = [];
        foreach ($latest as $row) {
            $out[(string)$row->uuid . '|' . (int)$row->revision] = trim((string)$row->code . ' — ' . (string)$row->name, ' —');
        }
        return $out;
    }

    private static function validate_financial_scenario(string $companyuuid, array $model,
            string $scenarioselection, bool $required): array {
        global $DB;
        if ($scenarioselection === '') {
            if ($required) {
                self::fail('economicscenario');
            }
            return ['', 0];
        }
        $parts = explode('|', $scenarioselection, 2);
        $uuid = trim((string)($parts[0] ?? ''));
        $revision = (int)($parts[1] ?? 0);
        if ($uuid === '' || $revision <= 0) {
            self::fail('economicscenario');
        }
        $row = $DB->get_record('local_kbacompany_finscenario', [
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => $revision,
            'status' => 'published',
        ]);
        if (!$row || (string)$row->businessmodeluuid !== (string)$model['uuid'] ||
                (int)$row->businessmodelrevision !== (int)$model['revision']) {
            self::fail('economicscenario');
        }
        return [$uuid, $revision];
    }

    public static function save_validation(string $companyuuid, int $userid, string $canvasuuid,
            int $canvasrevision, string $contour, array $data): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        $model = self::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        if (!in_array($contour, self::CONTOURS, true)) {
            self::fail('invalid');
        }
        $state = self::text($data, 'state', 12, true);
        if (!in_array($state, self::VALIDATION_STATES, true)) {
            self::fail('invalid');
        }
        $note = self::text($data, 'note', 12000, false);
        $evidence = self::text($data, 'evidence', 12000, false);
        $finscenario = ['', 0];
        if ($contour === 'economics') {
            $finscenario = self::validate_financial_scenario($companyuuid, $model,
                self::text($data, 'finscenario', 80, false), $state === 'confirmed');
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('bmc_validation:' . $companyuuid . ':' . $canvasuuid . ':' . $canvasrevision . ':' . $contour, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $rows = $DB->get_records('local_kbacompany_bmcval', [
                'companyuuid' => $companyuuid,
                'canvasuuid' => $canvasuuid,
                'canvasrevision' => $canvasrevision,
                'contour' => $contour,
            ], 'revision DESC', '*', 0, 1);
            $old = $rows ? reset($rows) : null;
            $now = time();
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $old ? (string)$old->uuid : self::uuid(),
                'revision' => $old ? ((int)$old->revision + 1) : 1,
                'canvasuuid' => $canvasuuid,
                'canvasrevision' => $canvasrevision,
                'contour' => $contour,
                'state' => $state,
                'note' => $note,
                'evidence' => $evidence,
                'finscenariouuid' => $finscenario[0],
                'finscenariorevision' => $finscenario[1],
                'createdby' => $userid,
                'modifiedby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_bmcval', $row);
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

    /** Current validation + target-only owner-requirement coverage. */
    public static function summary(string $companyuuid, int $userid, array $model): array {
        self::authorise_view($companyuuid, $userid);
        $rows = self::current_validation_rows($companyuuid, (string)$model['uuid'], (int)$model['revision']);
        $validations = [];
        foreach (self::CONTOURS as $contour) {
            $validations[$contour] = isset($rows[$contour]) ? self::validation_dto($rows[$contour]) : null;
        }
        $readiness = business_model_crud_service::readiness($companyuuid, $userid, $model);
        $allconfirmed = true;
        foreach (self::CONTOURS as $contour) {
            if (!$validations[$contour] || $validations[$contour]['state'] !== 'confirmed') {
                $allconfirmed = false;
                break;
            }
        }
        $reqcovered = $readiness['requirements_total'] > 0 &&
            $readiness['requirements_linked'] === $readiness['requirements_total'];
        return [
            'validations' => $validations,
            'confirmed_contours' => count(array_filter($validations, static function($row): bool {
                return is_array($row) && $row['state'] === 'confirmed';
            })),
            'requirements_total' => $readiness['requirements_total'],
            'requirements_linked' => $readiness['requirements_linked'],
            'requirements_covered' => $reqcovered,
            'all_contours_confirmed' => $allconfirmed,
            'agreed' => $allconfirmed && $reqcovered,
            'financial_scenarios' => self::financial_scenario_options($companyuuid, $userid, $model),
        ];
    }

    private static function allocate_gap_code(string $companyuuid): string {
        global $DB;
        $minimum = 0;
        foreach ($DB->get_fieldset_select('local_kbacompany_bmcgap', 'stablecode',
                'companyuuid = :companyuuid AND stablecode <> :empty',
                ['companyuuid' => $companyuuid, 'empty' => '']) as $code) {
            if (preg_match('/^GAP-(\d+)$/', (string)$code, $matches)) {
                $minimum = max($minimum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, 'businessmodel:gap', 'GAP',
            static function(string $candidate) use ($DB, $companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_bmcgap', [
                    'companyuuid' => $companyuuid,
                    'stablecode' => $candidate,
                ]);
            }, $minimum);
    }

    private static function current_gap_rows(string $companyuuid, string $canvasuuid, int $canvasrevision,
            bool $includehidden = false): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_bmcgap', [
            'companyuuid' => $companyuuid,
            'canvasuuid' => $canvasuuid,
            'canvasrevision' => $canvasrevision,
        ], 'uuid ASC, revision ASC, id ASC');
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string)$row->uuid] = $row;
        }
        if (!$includehidden) {
            $latest = array_filter($latest, static function($row): bool {
                return (string)$row->status !== 'archived';
            });
        }
        return array_values($latest);
    }

    private static function gap_requirements(string $companyuuid, string $gapuuid, int $gaprevision): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_bmcgapreq', [
            'companyuuid' => $companyuuid,
            'gapuuid' => $gapuuid,
            'gaprevision' => $gaprevision,
        ], 'requirementcode ASC');
        return array_values(array_map(static function($row): array {
            return [
                'code' => (string)$row->requirementcode,
                'ownerframeuuid' => (string)$row->ownerframeuuid,
                'ownerframerevision' => (int)$row->ownerframerevision,
            ];
        }, $rows));
    }

    private static function gap_dto(\stdClass $row, array $requirements = []): array {
        return [
            'uuid' => (string)$row->uuid,
            'revision' => (int)$row->revision,
            'stablecode' => (string)$row->stablecode,
            'status' => (string)$row->status,
            'canvasuuid' => (string)$row->canvasuuid,
            'canvasrevision' => (int)$row->canvasrevision,
            'block' => (string)$row->blockkey,
            'asisitemuuid' => (string)$row->asisitemuuid,
            'asisitemrevision' => (int)$row->asisitemrevision,
            'targetitemuuid' => (string)$row->targetitemuuid,
            'targetitemrevision' => (int)$row->targetitemrevision,
            'significance' => (string)$row->significance,
            'evidence' => (string)($row->evidence ?? ''),
            'indicatoruuid' => (string)($row->indicatoruuid ?? ''),
            'requirements' => $requirements,
            'createdby' => (int)$row->createdby,
            'modifiedby' => (int)$row->modifiedby,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function gaps(string $companyuuid, int $userid, array $model, bool $includehidden = false): array {
        self::authorise_view($companyuuid, $userid);
        $out = [];
        foreach (self::current_gap_rows($companyuuid, (string)$model['uuid'], (int)$model['revision'], $includehidden) as $row) {
            $out[] = self::gap_dto($row, self::gap_requirements($companyuuid, (string)$row->uuid, (int)$row->revision));
        }
        return $out;
    }

    public static function gap(string $companyuuid, int $userid, array $model, string $gapuuid): array {
        self::authorise_view($companyuuid, $userid);
        foreach (self::current_gap_rows($companyuuid, (string)$model['uuid'], (int)$model['revision'], true) as $row) {
            if ((string)$row->uuid === $gapuuid) {
                $gap = self::gap_dto($row, self::gap_requirements($companyuuid, $gapuuid, (int)$row->revision));
                $gap['asisitem'] = business_model_crud_service::item($companyuuid, $userid,
                    (string)$model['uuid'], (int)$model['revision'], $gap['asisitemuuid'], $gap['asisitemrevision']);
                $gap['targetitem'] = business_model_crud_service::item($companyuuid, $userid,
                    (string)$model['uuid'], (int)$model['revision'], $gap['targetitemuuid'], $gap['targetitemrevision']);
                return $gap;
            }
        }
        self::fail('reference');
    }

    private static function validate_indicator(string $companyuuid, string $indicatoruuid): string {
        global $DB;
        if ($indicatoruuid === '') {
            return '';
        }
        if (!$DB->record_exists('local_kbacompany_stat', [
            'companyuuid' => $companyuuid,
            'uuid' => $indicatoruuid,
        ])) {
            self::fail('indicator');
        }
        return $indicatoruuid;
    }

    private static function validate_gap_requirements(string $companyuuid, int $userid, array $model,
            array $codes): array {
        $options = business_model_crud_service::requirement_options($companyuuid, $userid, $model);
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code === '' || !isset($options[$code])) {
                self::fail('requirement');
            }
            $out[$code] = true;
        }
        return array_keys($out);
    }

    private static function write_gap_requirements(string $companyuuid, array $model, string $gapuuid,
            int $gaprevision, array $codes): void {
        global $DB;
        $now = time();
        foreach ($codes as $code) {
            $DB->insert_record('local_kbacompany_bmcgapreq', (object)[
                'companyuuid' => $companyuuid,
                'gapuuid' => $gapuuid,
                'gaprevision' => $gaprevision,
                'ownerframeuuid' => (string)$model['ownerframeuuid'],
                'ownerframerevision' => (int)$model['ownerframerevision'],
                'requirementcode' => $code,
                'timecreated' => $now,
            ]);
        }
    }

    public static function save_gap(string $companyuuid, int $userid, string $canvasuuid, int $canvasrevision,
            array $data, string $gapuuid = '', int $expectedrevision = 0): string {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        $model = self::model($companyuuid, $userid, $canvasuuid, $canvasrevision);
        $asisuuid = self::text($data, 'asisitemuuid', 36, true);
        $targetuuid = self::text($data, 'targetitemuuid', 36, true);
        $asis = business_model_crud_service::item($companyuuid, $userid, $canvasuuid, $canvasrevision, $asisuuid);
        $target = business_model_crud_service::item($companyuuid, $userid, $canvasuuid, $canvasrevision, $targetuuid);
        if ($asis['status'] === 'archived' || $target['status'] === 'archived' ||
                $asis['state'] !== 'asis' || $target['state'] !== 'target' || $asis['block'] !== $target['block']) {
            self::fail('items');
        }
        $requirements = self::validate_gap_requirements($companyuuid, $userid, $model,
            is_array($data['requirements'] ?? null) ? $data['requirements'] : []);
        $indicatoruuid = self::validate_indicator($companyuuid, self::text($data, 'indicatoruuid', 36, false));
        $significance = self::text($data, 'significance', 16000, true);
        $evidence = self::text($data, 'evidence', 12000, false);

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('bmc_gap:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($gapuuid !== '') {
                $rows = $DB->get_records('local_kbacompany_bmcgap', [
                    'companyuuid' => $companyuuid,
                    'uuid' => $gapuuid,
                ], 'revision DESC', '*', 0, 1);
                if (!$rows) {
                    self::fail('reference');
                }
                $old = reset($rows);
                if ((int)$old->revision !== $expectedrevision || (string)$old->canvasuuid !== $canvasuuid ||
                        (int)$old->canvasrevision !== $canvasrevision || (string)$old->status === 'archived') {
                    self::fail('conflict');
                }
            } else if ($expectedrevision !== 0) {
                self::fail('conflict');
            }
            $now = time();
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            $revision = $old ? ((int)$old->revision + 1) : 1;
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stableuuid,
                'revision' => $revision,
                'stablecode' => $old ? (string)$old->stablecode : self::allocate_gap_code($companyuuid),
                'status' => 'candidate',
                'canvasuuid' => $canvasuuid,
                'canvasrevision' => $canvasrevision,
                'blockkey' => (string)$asis['block'],
                'asisitemuuid' => $asisuuid,
                'asisitemrevision' => (int)$asis['revision'],
                'targetitemuuid' => $targetuuid,
                'targetitemrevision' => (int)$target['revision'],
                'significance' => $significance,
                'evidence' => $evidence,
                'indicatoruuid' => $indicatoruuid,
                'createdby' => $userid,
                'modifiedby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_bmcgap', $row);
            self::write_gap_requirements($companyuuid, $model, $stableuuid, $revision, $requirements);
            $tx->allow_commit();
            return $stableuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function transition_gap(string $companyuuid, int $userid, array $model, string $gapuuid,
            int $expectedrevision, string $newstatus): void {
        global $DB;
        self::authorise_manage($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('bmc_gap:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $rows = $DB->get_records('local_kbacompany_bmcgap', [
                'companyuuid' => $companyuuid,
                'uuid' => $gapuuid,
            ], 'revision DESC', '*', 0, 1);
            if (!$rows) {
                self::fail('reference');
            }
            $old = reset($rows);
            if ((int)$old->revision !== $expectedrevision || (string)$old->canvasuuid !== (string)$model['uuid'] ||
                    (int)$old->canvasrevision !== (int)$model['revision']) {
                self::fail('conflict');
            }
            if ($newstatus === 'confirmed' && (string)$old->status !== 'candidate') {
                self::fail('invalidstate');
            }
            if ($newstatus === 'archived' && (string)$old->status === 'archived') {
                self::fail('invalidstate');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = (int)$old->revision + 1;
            $row->status = $newstatus;
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_bmcgap', $row);
            $reqs = self::gap_requirements($companyuuid, $gapuuid, (int)$old->revision);
            self::write_gap_requirements($companyuuid, $model, $gapuuid, (int)$row->revision,
                array_column($reqs, 'code'));
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

    public static function confirm_gap(string $companyuuid, int $userid, array $model, string $gapuuid,
            int $revision): void {
        self::transition_gap($companyuuid, $userid, $model, $gapuuid, $revision, 'confirmed');
    }

    public static function archive_gap(string $companyuuid, int $userid, array $model, string $gapuuid,
            int $revision): void {
        self::transition_gap($companyuuid, $userid, $model, $gapuuid, $revision, 'archived');
    }

    public static function gap_history(string $companyuuid, int $userid, string $gapuuid): array {
        global $DB;
        self::authorise_view($companyuuid, $userid);
        $rows = $DB->get_records('local_kbacompany_bmcgap', [
            'companyuuid' => $companyuuid,
            'uuid' => $gapuuid,
        ], 'revision DESC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::gap_dto($row, self::gap_requirements($companyuuid, $gapuuid, (int)$row->revision));
        }
        return $out;
    }

    public static function indicator_options(string $companyuuid, int $userid): array {
        $context = indicator_registry_service::context($companyuuid, $userid);
        $out = [];
        foreach ($context['metrics'] as $metric) {
            $out[(string)$metric['uuid']] = trim((string)$metric['code'] . ' — ' . (string)$metric['name'], ' —');
        }
        return $out;
    }
}
