<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Permission-scoped bridge from BMS CRUD to optional local_kbaai. */
class bms_ai_bridge_service {
    public const CONTRACT_VERSION = '0.3';
    private const DOMAINS = ['philosophy', 'market', 'owner', 'strategy', 'task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'];
    private const OPERATIONS = ['explain', 'interview', 'draft', 'review', 'evidence_check'];
    private const FIELDS = [
        'philosophy' => ['purpose', 'mission', 'vision'],
        'market' => ['description', 'evidencesummary'],
        'owner' => ['statement', 'horizonmetric', 'constraintsprice', 'businessevidence'],
        'strategy' => ['thesis', 'positioning', 'outcome', 'description'],
        'task' => ['drafttext', 'title', 'product', 'description'], 'battleplan' => ['drafttext', 'weekgoal', 'weekoutcome'], 'ckp' => ['drafttext', 'name', 'acceptance'],
        'metric' => ['drafttext', 'name', 'description'], 'employee' => ['drafttext'], 'position' => ['drafttext', 'name', 'purpose'],
        'role' => ['drafttext'],
    ];

    /** Human-readable field semantics: trusted server metadata, never user/model-provided labels. */
    public static function field_meaning(string $domain, string $field): array {
        $definitions = [
            'task' => [
                'drafttext' => ['Черновик задачи', 'Рабочее описание задачи: что сделать, какой результат нужен и как проверить завершение. Это не данные кадрового или другого раздела.'],
                'title' => ['Название задачи', 'Коротко обозначьте конкретное действие и его предмет.'],
                'product' => ['Ожидаемый результат задачи', 'Опишите проверяемый результат, который исполнитель должен предоставить.'],
                'description' => ['Описание задачи', 'Укажите исходные условия, объём работ, ограничения и критерии завершения.'],
            ],
            'battleplan' => [
                'drafttext' => ['Черновик боевого плана', 'Рабочие приоритеты недели, задачи, ожидаемые результаты и критерии выполнения.'],
                'weekgoal' => ['Цель недели', 'Какой конкретный управленческий результат требуется достигнуть за неделю.'],
                'weekoutcome' => ['Ожидаемый результат недели', 'Измеримый или проверяемый итог выполнения боевого плана к концу недели.'],
            ],
            'ckp' => [
                'drafttext' => ['Черновик ЦКП', 'Ценный конечный продукт: законченный результат работы для внутреннего или внешнего потребителя.'],
                'name' => ['Ценный конечный продукт (ЦКП)', 'Сформулируйте законченный, полезный потребителю результат, а не действие или обязанность.'],
                'acceptance' => ['Критерии приёмки ЦКП', 'По каким признакам можно объективно проверить, что продукт получен и соответствует требованиям.'],
            ],
            'metric' => [
                'drafttext' => ['Черновик метрики', 'Как измеряется результат: показатель, единица, период, план и факт.'],
                'name' => ['Название метрики', 'Краткое наименование конкретного измеряемого показателя.'],
                'description' => ['Описание метрики', 'Что измеряется, зачем, откуда берутся данные и как интерпретировать показатель.'],
            ],
            'employee' => [
                'drafttext' => ['Рабочий черновик по сотруднику', 'Помощь с нейтральными рабочими процессами: адаптация, задачи, обучение. Не оценивайте личность, не придумывайте факты и не запрашивайте персональные данные.'],
            ],
            'position' => [
                'drafttext' => ['Черновик описания должности', 'Назначение должности, её результат, границы ответственности и требования к работе.'],
                'name' => ['Название должности', 'Понятное название функциональной позиции в организационной структуре.'],
                'purpose' => ['Назначение должности', 'Зачем существует должность, какую ценность и результат она обеспечивает компании.'],
            ],
            'role' => [
                'drafttext' => ['Черновик бизнес-роли', 'Функции, ответственность и ожидаемые результаты бизнес-роли; не путать с техническими правами доступа RBAC.'],
            ],
        ];
        return $definitions[$domain][$field] ?? ['', ''];
    }

    public static function available(): bool {
        return self::status()['available'];
    }

    /** Probe the optional companion without making an outbound AI request. */
    public static function status(): array {
        $out = ['available' => false, 'test_mode' => false];
        $class = '\\local_kbaai\\service\\bms_assistant_service';
        if (!class_exists($class) || !is_callable([$class, 'contract']) || !method_exists($class, 'assist')) {
            return $out;
        }
        try {
            $contract = $class::contract();
            if (($contract['write_policy'] ?? '') !== 'proposal_only' ||
                    !in_array($contract['version'] ?? '', ['0.1', '0.2', '0.3'], true)) {
                return $out;
            }
            if (is_callable([$class, 'status'])) {
                $status = $class::status();
                $out['available'] = isset($status['available']) && $status['available'] === true;
                $out['test_mode'] = !empty($status['test_mode']);
            } else {
                $out['available'] = true;
            }
        } catch (\Throwable $e) {
            return ['available' => false, 'test_mode' => false];
        }
        return $out;
    }

    /** The companion provider must explicitly advertise each additional BMS domain. */
    public static function supports_domain(string $domain): bool {
        if (!in_array($domain, self::DOMAINS, true) || !self::available()) { return false; }
        if (in_array($domain, ['philosophy', 'market', 'owner', 'strategy'], true)) { return true; }
        $class = '\local_kbaai\service\bms_assistant_service';
        try {
            $contract = $class::contract();
            return isset($contract['domains']) && is_array($contract['domains']) &&
                in_array($domain, $contract['domains'], true);
        } catch (\Throwable $e) { return false; }
    }

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ai_assistance_bridge',
            'version' => self::CONTRACT_VERSION,
            'write_policy' => 'proposal_only',
            'domains' => self::DOMAINS,
            'operations' => self::OPERATIONS,
            'provider_component' => 'local_kbaai',
        ];
    }

    public static function assist(string $companyuuid, int $userid, string $domain, string $field,
            string $operation, string $recorduuid = '', int $revision = 0, string $draftvalue = '', string $section = ''): array {
        company_context_service::context($companyuuid, $userid);
        if (!in_array($domain, self::DOMAINS, true) || !in_array($operation, self::OPERATIONS, true)) {
            throw new \invalid_parameter_exception('Unsupported BMS AI domain or operation.');
        }
        if (!isset(self::FIELDS[$domain]) || !in_array($field, self::FIELDS[$domain], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS AI field.');
        }
        // Additional domains require explicit provider support; never dispatch unknown material to a legacy provider.
        if (in_array($domain, ['task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'], true)) {
            if (!permission_service::can_manage_organization($companyuuid, $userid)) {
                throw new \required_capability_exception(\context_system::instance(),
                    'local/kbacompany:manageorganization', 'nopermissions', '');
            }
            if (!self::supports_domain($domain)) {
                throw new \moodle_exception('bmsai_unavailable', 'local_kbacompany');
            }
            // The new panels are explicitly free-standing drafts, not links to an unchecked record identifier.
            if ($recorduuid !== '' || $revision !== 0) {
                throw new \invalid_parameter_exception('Draft assistant does not accept a record identifier.');
            }
        }
        if (!self::available()) {
            throw new \moodle_exception('bmsai_unavailable', 'local_kbacompany');
        }
        $record = [];
        if ($recorduuid !== '') {
            if ($domain === 'philosophy') {
                $record = company_context_domain_service::philosophy($companyuuid, $userid, $recorduuid, $revision);
                unset($record['createdby'], $record['modifiedby']);
            } else if ($domain === 'market') {
                $record = company_context_domain_service::market($companyuuid, $userid, $recorduuid, $revision);
                unset($record['createdby'], $record['modifiedby']);
                $record['evidence'] = company_context_domain_service::market_evidence($companyuuid, $userid, $recorduuid);
            } else if ($domain === 'owner') {
                if (!owner_service::can_manage($companyuuid, $userid)) {
                    throw new \required_capability_exception(\context_system::instance(),
                        'local/kbacompany:viewowncompany', 'nopermissions', '');
                }
                if (!in_array($section, owner_service::SECTION_KEYS, true)) {
                    throw new \invalid_parameter_exception('Unsupported owner section.');
                }
                $ownercontext = owner_service::context($companyuuid, $userid);
                $frame = null;
                foreach ($ownercontext['frames'] as $candidate) {
                    if ((string)$candidate['uuid'] === $recorduuid && !empty($candidate['editable'])) {
                        $frame = $candidate;
                        break;
                    }
                }
                if (!$frame || ($revision > 0 && (int)$frame['revision'] !== $revision)) {
                    throw new \invalid_parameter_exception('Owner frame is not available for AI assistance.');
                }
                $sections = owner_service::sections_from_payload($frame['payload_data'] ?? []);
                $record = [
                    'uuid' => (string)$frame['uuid'],
                    'revision' => (int)$frame['revision'],
                    'section' => $section,
                    'section_values' => $sections[$section] ?? [],
                ];
            }
        }
        if ($domain === 'strategy') {
            if (!strategy_service::can_manage($companyuuid, $userid)) {
                throw new \required_capability_exception(\context_system::instance(),
                    'local/kbacompany:manageorganization', 'nopermissions', '');
            }
            $strategycontext = strategy_service::context($companyuuid, $userid);
            $selected = null;
            if ($recorduuid !== '') {
                $pool = in_array($field, ['outcome'], true) ? $strategycontext['goals'] :
                    (in_array($field, ['description'], true) ? $strategycontext['causal_links'] : $strategycontext['strategies']);
                foreach ($pool as $candidate) {
                    if ((string)$candidate['uuid'] === $recorduuid && ($revision <= 0 || (int)$candidate['revision'] === $revision)) {
                        $selected = $candidate;
                        break;
                    }
                }
            }
            $record = $selected ?: [];
            $record['published_context'] = [
                'owner_requirements_available' => count(owner_service::published_requirement_sets($companyuuid, $userid)),
                'philosophies' => array_map(static function(array $row): array {
                    return ['uuid' => $row['uuid'], 'revision' => $row['revision'], 'stablecode' => $row['stablecode'] ?? '',
                        'mission' => $row['mission'] ?? '', 'vision' => $row['vision'] ?? ''];
                }, $strategycontext['published_philosophies']),
                'markets' => array_map(static function(array $row): array {
                    return ['uuid' => $row['uuid'], 'revision' => $row['revision'], 'stablecode' => $row['stablecode'] ?? ($row['code'] ?? ''),
                        'name' => $row['name'] ?? '', 'description' => $row['description'] ?? ''];
                }, $strategycontext['published_markets']),
                'business_models' => array_map(static function(array $row): array {
                    return ['uuid' => $row['uuid'], 'revision' => $row['revision'], 'code' => $row['code'] ?? '', 'name' => $row['name'] ?? ''];
                }, $strategycontext['published_business_models']),
            ];
        }

        $ownerrequirements = [];
        if ($domain === 'philosophy') {
            foreach (owner_service::published_requirement_sets($companyuuid, $userid) as $set) {
                foreach ($set['requirements'] as $requirement) {
                    $ownerrequirements[] = [
                        'code' => (string)$requirement['id'],
                        'text' => (string)($requirement['text'] ?? ''),
                        'source_revision' => (int)$set['revision'],
                    ];
                }
            }
        }
        $context = [
            'company_uuid' => $companyuuid,
            'company' => company_context_service::context($companyuuid, $userid)['name'] ?? '',
            'domain' => $domain,
            'record' => $record,
            'owner_requirements' => $ownerrequirements,
            'write_policy' => 'proposal_only',
        ];
        if ($domain === 'owner') {
            $context['field_context'] = [
                'section_key' => $section,
                'section_title' => get_string('ownermodel_section_' . $section . '_title', 'local_kbacompany'),
                'section_methodology' => get_string('ownermodel_section_' . $section . '_help', 'local_kbacompany'),
                'working_example' => get_string('ownermodel_section_' . $section . '_example', 'local_kbacompany'),
                'field_key' => $field,
                'field_label' => get_string('ownermodel_field_' . $field, 'local_kbacompany'),
                'field_help' => get_string('ownermodel_field_' . $field . '_help', 'local_kbacompany'),
                'expected_output' => 'Return ready-to-paste field content, not an instruction to write it. If the supplied context is insufficient, leave suggested_value empty and return concise clarification questions.',
            ];
        }
        if (in_array($domain, ['task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'], true)) {
            $meaning = self::field_meaning($domain, $field);
            $context['field_context'] = [
                'object_type' => $domain,
                'field_key' => $field,
                'field_label' => $meaning[0],
                'field_help' => $meaning[1],
                'expected_output' => 'Address exactly the selected field and its stated business meaning in Russian. ' .
                    'Explain the field with its real Russian name, never technical identifiers (drafttext, name, title). ' .
                    'Do not change to a different business process based on examples found in draft text. ' .
                    'Do not invent employee assessments, numerical performance, personal facts or completed work. ' .
                    'If facts are insufficient, ask brief clarifying questions.',
            ];
        }
        if ($domain === 'philosophy') {
            $methodology = ['BMS-ARC-CONTEXT-001/company-philosophy', 'BMS-UX-CRUD-001/ai-assistance'];
        } else if ($domain === 'market') {
            $methodology = ['BMS-ARC-CONTEXT-001/market', 'BMS-UX-CRUD-001/ai-assistance'];
        } else if ($domain === 'strategy') {
            $methodology = ['BMS-ARC-CONTEXT-001/pinned-context', 'BMS-ARC-BMC-001/target-validation',
                'BMS-UX-CRUD-001/ai-assistance', 'BMS-STRATEGY/guided-workflow'];
        } else if (in_array($domain, ['task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'], true)) {
            $methodology = ['BMS-UX-CRUD-001/ai-assistance', 'BMS-AI-PROPOSAL-ONLY/confirm-before-save'];
        } else {
            $methodology = ['BMS-AUD-EXM-001/owner-intent', 'BMS-UX-CRUD-001/ai-assistance'];
        }
        $service = new \local_kbaai\service\bms_assistant_service();
        $result = $service->assist([
            'operation' => $operation,
            'domain' => $domain,
            'field' => $field,
            'locale' => current_language(),
            'draftvalue' => $draftvalue,
            'context' => $context,
            'methodology' => $methodology,
        ]);
        if (!is_array($result) || !isset($result['suggested_value']) || !is_string($result['suggested_value']) ||
                !isset($result['rationale']) || !is_string($result['rationale'])) {
            throw new \moodle_exception('bmsai_invalidresponse', 'local_kbacompany');
        }
        foreach (['questions', 'issues', 'evidence_suggestions', 'warnings'] as $key) {
            if (!isset($result[$key])) {
                $result[$key] = [];
            }
            if (!is_array($result[$key])) {
                throw new \moodle_exception('bmsai_invalidresponse', 'local_kbacompany');
            }
        }
        // Bind proposal identity to server-owned request data. Provider/model text cannot choose the target.
        $result['company_uuid'] = $companyuuid;
        $result['record_uuid'] = $recorduuid;
        $result['record_revision'] = $revision;
        $result['domain'] = $domain;
        $result['field'] = $field;
        $result['operation'] = $operation;
        $result['write_policy'] = 'proposal_only';
        $result['can_apply'] = $operation === 'draft' && trim($result['suggested_value']) !== '';
        if (!isset($result['proposal_id']) || !is_string($result['proposal_id']) || $result['proposal_id'] === '') {
            $result['proposal_id'] = bin2hex(random_bytes(16));
        }
        if (self::status()['test_mode']) {
            $result['warnings'][] = get_string('bmsai_mockwarning', 'local_kbacompany');
        }
        return $result;
    }
}
