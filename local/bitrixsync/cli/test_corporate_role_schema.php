<?php
// CLI-only isolated contract test. Does not call Bitrix24 or Corporate Dashboard.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\adapters\corporate\api_client;
use local_bitrixsync\services\corporate_role_schema_service;

class local_bitrixsync_fake_role_bitrix extends client {
    public $field = null;
    public $addcount = 0;
    public $updatecount = 0;
    private $nextenumid = 1001;

    public function __construct() {}
    public function is_configured(): bool { return true; }
    public function call(string $method, array $params = []): array {
        if ($method === 'profile') { return ['result' => ['ID' => 1]]; }
        return $this->call_json($method, $params);
    }
    public function call_json(string $method, array $params = []): array {
        if ($method === 'crm.contact.userfield.list') {
            return ['result' => $this->field ? [$this->field] : []];
        }
        if ($method === 'crm.contact.userfield.get') {
            return ['result' => $this->field ?: []];
        }
        if ($method === 'crm.contact.userfield.add') {
            $this->addcount++;
            $fields = $params['fields'];
            $fields['ID'] = 417;
            $fields['FIELD_NAME'] = 'UF_CRM_KBA_ROLE_TEST';
            $fields['LIST'] = $this->assignids($fields['LIST'] ?? []);
            $this->field = $fields;
            return ['result' => 417];
        }
        if ($method === 'crm.contact.userfield.update') {
            $this->updatecount++;
            $this->field['LIST'] = $this->assignids($params['fields']['LIST'] ?? []);
            return ['result' => true];
        }
        if ($method === 'crm.contact.get') {
            return ['result' => ['UF_CRM_KBA_ROLE_TEST' => [1001, 1002]]];
        }
        throw new Exception('Unsupported fake method: ' . $method);
    }
    private function assignids(array $list): array {
        $out = [];
        foreach ($list as $row) {
            if (empty($row['ID'])) {
                $row['ID'] = $this->nextenumid++;
            }
            $out[] = $row;
        }
        return $out;
    }
}

class local_bitrixsync_fake_role_corporate extends api_client {
    public function is_available(): bool { return true; }
    public function is_role_schema_compatible(): bool { return true; }
    public function contract(): array { return ['api_version' => 1, 'api_release' => '1.10']; }
    public function get_role_schema(): array {
        return [
            'roles' => [
                'employee' => 'Сотрудник',
                'corporate_manager' => 'Корпоративный менеджер',
                'department_manager' => 'Руководитель подразделения',
                'hr' => 'HR',
            ],
        ];
    }
}

$keys = ['corporate_role_field_id', 'corporate_role_field_name', 'corporate_role_enum_map'];
$backup = [];
foreach ($keys as $key) {
    $backup[$key] = get_config('local_bitrixsync', $key);
}

try {
    unset_config('corporate_role_field_id', 'local_bitrixsync');
    unset_config('corporate_role_field_name', 'local_bitrixsync');
    unset_config('corporate_role_enum_map', 'local_bitrixsync');

    $bitrix = new local_bitrixsync_fake_role_bitrix();
    $corp = new local_bitrixsync_fake_role_corporate();
    $service = new corporate_role_schema_service($bitrix, $corp);

    $first = $service->provision();
    if (empty($first['ready']) || $bitrix->addcount !== 1) {
        throw new Exception('First provision failed');
    }

    $second = $service->provision();
    if (empty($second['ready']) || $bitrix->addcount !== 1 || $bitrix->updatecount !== 0) {
        throw new Exception('Idempotency failed');
    }

    // Remove one enum and verify only an update is performed.
    array_pop($bitrix->field['LIST']);
    $third = $service->provision();
    if (empty($third['ready']) || $bitrix->addcount !== 1 || $bitrix->updatecount !== 1) {
        throw new Exception('Missing enum recovery failed');
    }

    // Incompatible type must fail without delete/recreate.
    $bitrix->field['USER_TYPE_ID'] = 'string';
    $conflict = $service->provision();
    if (!empty($conflict['success']) || ($conflict['code'] ?? '') !== 'field_schema_conflict' || $bitrix->addcount !== 1) {
        throw new Exception('Schema conflict safety failed');
    }

    echo "Corporate Role Schema tests: PASS\n";
    echo "- initial create: PASS\n";
    echo "- second provision idempotent: PASS\n";
    echo "- missing enum recovery: PASS\n";
    echo "- wrong type safe conflict: PASS\n";
} finally {
    foreach ($backup as $key => $value) {
        if ($value === false) {
            unset_config($key, 'local_bitrixsync');
        } else {
            set_config($key, $value, 'local_bitrixsync');
        }
    }
}
