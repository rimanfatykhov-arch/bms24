<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

class corporate_bitrix_diagnostic_service {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function run(): array {
        $out = [
            'configured' => $this->client->is_configured(),
            'connection' => $this->safe_connection(),
            'tests' => [],
            'summary' => 'not_run',
        ];

        if (!$this->client->is_configured()) {
            $out['summary'] = 'rest_webhook_not_configured';
            return $out;
        }

        $this->probe($out, 'contact_read', function() {
            return $this->client->call('crm.contact.list', [
                'order' => ['ID' => 'ASC'],
                'select' => ['ID'],
                'start' => 0,
            ]);
        });

        $this->probe($out, 'contact_userfields_standard', function() {
            return $this->client->call('crm.contact.userfield.list', [
                'filter' => ['LANG' => 'ru'],
                'order' => ['ID' => 'ASC'],
                'start' => 0,
            ]);
        });

        $this->probe($out, 'contact_userfields_json', function() {
            return $this->client->call_json('crm.contact.userfield.list', [
                'filter' => ['LANG' => 'ru'],
                'order' => ['ID' => 'ASC'],
                'start' => 0,
            ]);
        });

        try {
            $manager = new crm_field_manager($this->client);
            $fields = $manager->resolve_entity('contact', false);
            $out['tests']['crm_field_engine'] = [
                'ok' => true,
                'method' => 'crm_field_manager::resolve_entity(contact)',
                'resolved_fields' => count($fields),
                'corporate_roles' => $fields['corporate_roles'] ?? null,
                'diagnostic' => $this->client->get_last_diagnostic(),
            ];
        } catch (\Throwable $e) {
            $out['tests']['crm_field_engine'] = [
                'ok' => false,
                'method' => 'crm_field_manager::resolve_entity(contact)',
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
                'diagnostic' => $this->client->get_last_diagnostic(),
            ];
        }

        $contactok = !empty($out['tests']['contact_read']['ok']);
        $userfieldok =
            !empty($out['tests']['contact_userfields_standard']['ok']) ||
            !empty($out['tests']['contact_userfields_json']['ok']);
        $engineok = !empty($out['tests']['crm_field_engine']['ok']);

        if (!$contactok) {
            $out['summary'] = 'main_rest_failed';
        } else if (!$userfieldok) {
            $out['summary'] = 'userfield_api_failed';
        } else if (!$engineok) {
            $out['summary'] = 'crm_field_engine_failed';
        } else {
            $out['summary'] = 'ready';
        }

        return $out;
    }

    private function probe(array &$out, string $key, callable $callback): void {
        try {
            $response = $callback();
            $result = $response['result'] ?? null;
            $out['tests'][$key] = [
                'ok' => true,
                'result_count' => is_array($result) ? count($result) : ($result !== null ? 1 : 0),
                'diagnostic' => $this->client->get_last_diagnostic(),
            ];
        } catch (\Throwable $e) {
            $out['tests'][$key] = [
                'ok' => false,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
                'diagnostic' => $this->client->get_last_diagnostic(),
            ];
        }
    }

    private function safe_connection(): array {
        $base = $this->client->get_baseurl();
        if ($base === '') {
            return [
                'host' => '',
                'rest_user_id' => '',
                'secret_configured' => false,
            ];
        }

        $host = (string)parse_url($base, PHP_URL_HOST);
        $userid = '';
        if (preg_match('~/rest/(\d+)/~', $base, $m)) {
            $userid = $m[1];
        }

        return [
            'host' => $host,
            'rest_user_id' => $userid,
            'secret_configured' => true,
        ];
    }
}
