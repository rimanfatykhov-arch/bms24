<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client as bitrix_client;
use local_bitrixsync\repositories\corporate_connection_repository;

defined('MOODLE_INTERNAL') || die();

class corporate_connection_service {
    public function status(\stdClass $connection): array {
        $bitrix = [
            'connected' => false,
            'status' => 'unavailable',
            'source' => 'crm_field_engine',
        ];
        try {
            $manager = new \local_bitrixsync\services\crm_field_manager();
            $manager->resolve_entity('contact', false);
            $bitrix = [
                'connected' => true,
                'status' => 'healthy',
                'source' => 'crm_field_engine',
            ];
        } catch (\Throwable $ignored) {
            // Corporate connection remains valid even if Bitrix REST is unavailable.
        }

        return [
            'success' => true,
            'connected' => true,
            'connection' => [
                'id' => (string)$connection->connectionid,
                'component' => (string)$connection->component,
                'environment' => (string)$connection->environment,
                'client_version' => (string)$connection->clientversion,
                'api_version' => (string)$connection->apiversion,
                'last_seen' => (int)$connection->lastseen,
            ],
            'kba_connect' => ['version' => $this->release(), 'status' => 'healthy'],
            'bitrix24' => $bitrix,
            'corporate_api' => ['supported' => version_compare((string)$connection->apiversion, '1.11', '>='), 'version' => '1.11'],
        ];
    }

    public function active_count(): int {
        return (new corporate_connection_repository())->count_active();
    }

    private function release(): string {
        global $CFG;
        $plugin = new \stdClass();
        include($CFG->dirroot . '/local/bitrixsync/version.php');
        return (string)($plugin->release ?? 'KBA Connect');
    }
}
