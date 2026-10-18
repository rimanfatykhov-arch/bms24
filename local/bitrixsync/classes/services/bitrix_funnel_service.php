<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

class bitrix_funnel_service {
    private const SNAPSHOT_CONFIG = 'v5_funnel_snapshot';

    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function scan(): array {
        if (!$this->client->is_configured()) {
            throw new \moodle_exception(
                'bitrix_not_configured',
                'local_bitrixsync'
            );
        }

        $response = $this->client->call(
            'crm.category.list',
            ['entityTypeId' => 2]
        );

        $categories = $response['result']['categories'] ?? [];
        if (!is_array($categories)) {
            $categories = [];
        }

        // Bitrix installations may not return the default deal category in
        // crm.category.list. Ensure the default funnel can always be selected.
        $hasdefault = false;
        foreach ($categories as $category) {
            if ((int)($category['id'] ?? -1) === 0 ||
                    (($category['isDefault'] ?? 'N') === 'Y')) {
                $hasdefault = true;
                break;
            }
        }

        if (!$hasdefault) {
            array_unshift($categories, [
                'id' => 0,
                'name' => 'Основная воронка',
                'isDefault' => 'Y',
            ]);
        }

        $snapshot = [];

        foreach ($categories as $category) {
            $categoryid = (int)($category['id'] ?? 0);
            $entityid = $categoryid === 0
                ? 'DEAL_STAGE'
                : 'DEAL_STAGE_' . $categoryid;

            $statuses = $this->call_all(
                'crm.status.list',
                [
                    'order' => ['SORT' => 'ASC'],
                    'filter' => ['ENTITY_ID' => $entityid],
                ]
            );

            $stages = [];
            foreach ($statuses as $status) {
                $stageid = trim((string)($status['STATUS_ID'] ?? ''));
                if ($stageid === '') {
                    continue;
                }

                $stages[] = [
                    'id' => $stageid,
                    'name' => (string)($status['NAME'] ?? $stageid),
                    'sort' => (int)($status['SORT'] ?? 0),
                    'semantics' => (string)($status['SEMANTICS'] ?? ''),
                    'category_id' => $categoryid,
                    'entity_id' => $entityid,
                ];
            }

            usort($stages, static function(array $a, array $b): int {
                return ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0);
            });

            $snapshot[] = [
                'category_id' => $categoryid,
                'name' => trim((string)($category['name'] ?? '')) !== ''
                    ? (string)$category['name']
                    : ($categoryid === 0 ? 'Основная воронка' : 'Воронка #' . $categoryid),
                'is_default' => (string)($category['isDefault'] ?? 'N'),
                'entity_id' => $entityid,
                'stages' => $stages,
            ];
        }

        set_config(
            self::SNAPSHOT_CONFIG,
            json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );
        set_config(
            'v5_funnel_snapshot_time',
            time(),
            'local_bitrixsync'
        );

        return $snapshot;
    }

    public function get_snapshot(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            self::SNAPSHOT_CONFIG
        );

        if ($raw === '') {
            // One-time compatibility with the original v0.4.6 snapshot.
            $raw = (string)get_config(
                'local_bitrixsync',
                'funnelsnapshot'
            );
        }

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function snapshot_time(): int {
        return (int)get_config(
            'local_bitrixsync',
            'v5_funnel_snapshot_time'
        );
    }

    public function selected_stage_ids(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            'v5_allowed_stages'
        );

        $selected = array_filter(array_map(
            'trim',
            explode(',', $raw)
        ));

        return array_values(array_unique($selected));
    }

    public function save_selected_stage_ids(array $stageids): array {
        $available = [];
        foreach ($this->get_snapshot() as $funnel) {
            foreach (($funnel['stages'] ?? []) as $stage) {
                $id = trim((string)($stage['id'] ?? ''));
                if ($id !== '') {
                    $available[$id] = true;
                }
            }
        }

        $clean = [];
        foreach ($stageids as $stageid) {
            $stageid = trim((string)$stageid);
            if ($stageid !== '' && isset($available[$stageid])) {
                $clean[$stageid] = true;
            }
        }

        $selected = array_keys($clean);
        set_config(
            'v5_allowed_stages',
            implode(',', $selected),
            'local_bitrixsync'
        );

        return $selected;
    }

    public function selection_summary(): array {
        $selected = array_flip($this->selected_stage_ids());
        $out = [];

        foreach ($this->get_snapshot() as $funnel) {
            $chosen = [];
            foreach (($funnel['stages'] ?? []) as $stage) {
                $id = (string)($stage['id'] ?? '');
                if ($id !== '' && isset($selected[$id])) {
                    $chosen[] = [
                        'id' => $id,
                        'name' => (string)($stage['name'] ?? $id),
                    ];
                }
            }

            if ($chosen) {
                $out[] = [
                    'category_id' => (int)($funnel['category_id'] ?? 0),
                    'name' => (string)($funnel['name'] ?? ''),
                    'stages' => $chosen,
                ];
            }
        }

        return $out;
    }

    private function call_all(string $method, array $params): array {
        $items = [];
        $start = 0;
        $guard = 0;

        do {
            $pageparams = $params;
            $pageparams['start'] = $start;

            $response = $this->client->call(
                $method,
                $pageparams
            );

            $result = $response['result'] ?? [];
            if (!is_array($result)) {
                break;
            }

            foreach ($result as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (isset($response['next']) &&
                    is_numeric($response['next'])) {
                $start = (int)$response['next'];
            } else {
                $start = null;
            }

            $guard++;
        } while ($start !== null && $guard < 100);

        return $items;
    }
}
