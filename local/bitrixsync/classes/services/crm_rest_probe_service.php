<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

class crm_rest_probe_service {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function run(): array {
        $tests = [
            'profile' => ['profile', []],
            'company_userfields' => [
                'crm.company.userfield.list',
                [
                    'filter' => ['LANG' => 'ru'],
                    'order' => ['ID' => 'ASC'],
                    'start' => 0,
                ],
            ],
            'contact_userfields' => [
                'crm.contact.userfield.list',
                [
                    'filter' => ['LANG' => 'ru'],
                    'order' => ['ID' => 'ASC'],
                    'start' => 0,
                ],
            ],
            'deal_userfields' => [
                'crm.deal.userfield.list',
                [
                    'filter' => ['LANG' => 'ru'],
                    'order' => ['ID' => 'ASC'],
                    'start' => 0,
                ],
            ],
        ];

        $out = [
            'ready' => true,
            'configured' => $this->client->is_configured(),
            'tests' => [],
        ];

        if (!$this->client->is_configured()) {
            $out['ready'] = false;
            return $out;
        }

        foreach ($tests as $key => [$method, $params]) {
            try {
                $response = $this->client->call(
                    $method,
                    $params
                );

                $result = $response['result'] ?? null;
                $count = is_array($result)
                    ? count($result)
                    : ($result !== null ? 1 : 0);

                $out['tests'][$key] = [
                    'ok' => true,
                    'method' => $method,
                    'result_count' => $count,
                    'diagnostic' =>
                        $this->client->get_last_diagnostic(),
                ];
            } catch (\Throwable $e) {
                $out['ready'] = false;
                $out['tests'][$key] = [
                    'ok' => false,
                    'method' => $method,
                    'error' => $e->getMessage(),
                    'diagnostic' =>
                        $this->client->get_last_diagnostic(),
                ];
            }
        }

        return $out;
    }
}
