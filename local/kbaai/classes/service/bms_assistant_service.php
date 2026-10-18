<?php
namespace local_kbaai\service;

defined('MOODLE_INTERNAL') || die();

use local_kbaai\dto\bms_assistant_request;
use local_kbaai\provider\provider_factory;

/** Existing provider orchestration; never queries or writes BMS canonical tables. */
class bms_assistant_service {
    public const CONTRACT_VERSION = '0.3';
    public const BMS_DOMAINS = ['philosophy', 'market', 'owner', 'strategy',
        'task', 'battleplan', 'ckp', 'metric', 'employee', 'position', 'role'];

    public static function contract(): array {
        return ['component' => 'local_kbaai', 'contract' => 'bms_ai_assistance',
            'version' => self::CONTRACT_VERSION, 'write_policy' => 'proposal_only',
            'operations' => bms_profile_registry::OPERATIONS, 'domains' => self::BMS_DOMAINS,
            'readiness' => true,
            'profile_version' => bms_profile_registry::VERSION];
    }

    /** Configuration readiness only: no outbound requests; never return credentials or endpoints. */
    public static function status(): array {
        global $CFG;
        $provider = trim((string)get_config('local_kbaai', 'defaultprovider'));
        $result = ['available' => false, 'provider' => '', 'test_mode' => false,
            'reason' => 'not_configured'];
        if (!in_array($provider, ['mock', 'yandex'], true)) {
            return $result;
        }
        $result['provider'] = $provider;
        if ($provider === 'mock') {
            $result['available'] = true;
            $result['test_mode'] = true;
            $result['reason'] = 'test_mode';
            return $result;
        }
        $key = !empty($CFG->kbaai_yandex_apikey) ? trim((string)$CFG->kbaai_yandex_apikey) :
            trim((string)get_config('local_kbaai', 'yandexapikey'));
        $model = trim((string)get_config('local_kbaai', 'defaultmodel'));
        $folder = trim((string)get_config('local_kbaai', 'yandexfolderid'));
        if ($key === '' || ($folder === '' && strpos($model, 'gpt://') !== 0)) {
            return $result;
        }
        $result['available'] = true;
        $result['reason'] = 'configured';
        return $result;
    }

    public function assist(array $payload): array {
        foreach (['operation', 'domain', 'field', 'locale', 'draftvalue'] as $key) {
            if (isset($payload[$key]) && !is_string($payload[$key])) {
                throw new \invalid_parameter_exception('Invalid BMS AI request field.');
            }
        }
        foreach (['context', 'methodology'] as $key) {
            if (isset($payload[$key]) && !is_array($payload[$key])) {
                throw new \invalid_parameter_exception('Invalid BMS AI context.');
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 393216) {
            throw new \invalid_parameter_exception('Invalid BMS AI request encoding or size.');
        }
        $domain = isset($payload['domain']) ? $payload['domain'] : '';
        if (!in_array($domain, self::BMS_DOMAINS, true)) {
            throw new \invalid_parameter_exception('Unsupported BMS AI domain.');
        }
        $operation = isset($payload['operation']) ? $payload['operation'] : '';
        $profile = bms_profile_registry::profile($operation);
        $status = self::status();
        if (!$status['available']) {
            throw new \local_kbaai\provider\provider_exception('bms_assistant_unavailable',
                'BMS Assistant provider is not configured.', false);
        }
        $request = new bms_assistant_request($payload);
        $providerid = $status['provider'];
        $provider = provider_factory::create($providerid);
        if (!method_exists($provider, 'generate_bms_assistance')) {
            throw new \local_kbaai\provider\provider_exception('bms_assistant_unsupported',
                'Configured AI provider does not support BMS Assistant.', false);
        }
        $response = $provider->generate_bms_assistance($request);
        $result = bms_profile_registry::normalise($response->result, $operation);
        // Authoritative metadata is supplied by orchestration, never copied from model text.
        $result['provider'] = $providerid;
        $result['request_id'] = (string)$response->requestid;
        $result['model_profile'] = $profile['id'];
        $result['profile_version'] = $profile['version'];
        $result['write_policy'] = 'proposal_only';
        return $result;
    }
}
