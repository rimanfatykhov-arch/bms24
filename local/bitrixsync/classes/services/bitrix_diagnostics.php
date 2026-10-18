<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\exception\bitrix_api_exception;

defined('MOODLE_INTERNAL') || die();

class bitrix_diagnostics {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function run(): array {
        $tests = [];

        $tests[] = $this->test('profile', 'profile', []);

        $tests[] = $this->test(
            'crm',
            'crm.deal.list',
            [
                'order' => ['ID' => 'DESC'],
                'filter' => [],
                'select' => ['ID', 'TITLE'],
                'start' => 0,
            ]
        );

        $tests[] = $this->test(
            'catalog',
            'catalog.catalog.list',
            [
                'select' => ['id', 'iblockId', 'name'],
                'filter' => [],
                'order' => ['id' => 'asc'],
                'start' => 0,
            ]
        );

        $tests[] = $this->test(
            'legacy_catalog',
            'crm.catalog.list',
            [
                'order' => ['ID' => 'ASC'],
                'filter' => [],
                'select' => ['ID', 'NAME'],
                'start' => 0,
            ]
        );

        return [
            'configured' => $this->client->is_configured(),
            'baseurl' => $this->client->get_baseurl(),
            'tests' => $tests,
        ];
    }

    private function test(string $key, string $method, array $params): array {
        try {
            $response = $this->client->call($method, $params);

            return [
                'key' => $key,
                'method' => $method,
                'success' => true,
                'httpcode' => $this->client->get_last_http_code(),
                'errorcode' => '',
                'message' => get_string('ok', 'local_bitrixsync'),
                'response' => $response,
            ];
        } catch (bitrix_api_exception $e) {
            return [
                'key' => $key,
                'method' => $method,
                'success' => false,
                'httpcode' => $e->get_http_code(),
                'errorcode' => $e->get_bitrix_code(),
                'message' => $e->getMessage(),
                'response' => $e->get_response(),
            ];
        } catch (\Throwable $e) {
            return [
                'key' => $key,
                'method' => $method,
                'success' => false,
                'httpcode' => $this->client->get_last_http_code(),
                'errorcode' => '',
                'message' => $e->getMessage(),
                'response' => $this->client->get_last_response() ?? [],
            ];
        }
    }
}
