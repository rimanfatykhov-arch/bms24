<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

class crm_timeline_service {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function upsert(
        string $entitytype,
        int $entityid,
        string $marker,
        string $comment
    ): array {
        if ($entityid <= 0) {
            return [
                'success' => false,
                'id' => 0,
                'action' => 'skipped',
            ];
        }

        $existingid = $this->find_marker(
            $entitytype,
            $entityid,
            $marker
        );

        $comment = trim($comment) . "\n\n" . $marker;

        if ($existingid > 0) {
            $this->client->call(
                'crm.timeline.comment.update',
                [
                    'id' => $existingid,
                    'fields' => [
                        'COMMENT' => $comment,
                    ],
                ]
            );

            return [
                'success' => true,
                'id' => $existingid,
                'action' => 'updated',
            ];
        }

        $response = $this->client->call(
            'crm.timeline.comment.add',
            [
                'fields' => [
                    'ENTITY_ID' => $entityid,
                    'ENTITY_TYPE' => $entitytype,
                    'COMMENT' => $comment,
                ],
            ]
        );

        return [
            'success' => true,
            'id' => (int)($response['result'] ?? 0),
            'action' => 'created',
        ];
    }

    private function find_marker(
        string $entitytype,
        int $entityid,
        string $marker
    ): int {
        $start = 0;

        while ($start < 250) {
            $response = $this->client->call(
                'crm.timeline.comment.list',
                [
                    'filter' => [
                        'ENTITY_ID' => $entityid,
                        'ENTITY_TYPE' => $entitytype,
                    ],
                    'select' => [
                        'ID',
                        'COMMENT',
                    ],
                    'order' => ['ID' => 'DESC'],
                    'start' => $start,
                ]
            );

            $rows = $response['result'] ?? [];
            if (!is_array($rows) || !$rows) {
                break;
            }

            foreach ($rows as $row) {
                if (strpos(
                    (string)($row['COMMENT'] ?? ''),
                    $marker
                ) !== false) {
                    return (int)($row['ID'] ?? 0);
                }
            }

            if (count($rows) < 50) {
                break;
            }

            $start += 50;
        }

        return 0;
    }
}
