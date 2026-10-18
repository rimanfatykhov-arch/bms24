<?php
namespace local_bitrixsync\adapters\bitrix24;

defined('MOODLE_INTERNAL') || die();

class catalog_client {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function list_catalogs(): array {
        $response = $this->client->call('catalog.catalog.list', [
            'select' => [
                'id',
                'iblockId',
                'name',
                'productIblockId',
                'skuPropertyId',
            ],
            'filter' => [],
            'order' => ['id' => 'asc'],
            'start' => 0,
        ]);

        $catalogs = $response['result']['catalogs'] ?? [];
        return is_array($catalogs) ? array_values($catalogs) : [];
    }

    /**
     * Return normalized products/services using modern Catalog scope.
     */
    public function list_modern_products(int $limit = 500): array {
        $limit = max(1, min(5000, $limit));
        $catalogs = $this->list_catalogs();

        if (!$catalogs) {
            return [];
        }

        $iblocks = [];
        foreach ($catalogs as $catalog) {
            $productiblock = (int)($catalog['productIblockId'] ?? 0);
            $iblock = (int)($catalog['iblockId'] ?? 0);

            if ($productiblock > 0) {
                $iblocks[$productiblock] = $productiblock;
            } else if ($iblock > 0) {
                $iblocks[$iblock] = $iblock;
            }
        }

        $products = [];

        foreach ($iblocks as $iblockid) {
            if (count($products) >= $limit) {
                break;
            }

            // Simple products.
            $this->collect_modern_page(
                'catalog.product.list',
                'products',
                'product',
                $iblockid,
                $products,
                $limit
            );

            if (count($products) >= $limit) {
                break;
            }

            // Services are optional. Some portals/webhooks do not grant
            // administrator-level service-list permissions. Do not fail the
            // whole product catalog because services cannot be listed.
            try {
                $this->collect_modern_page(
                    'catalog.product.service.list',
                    'services',
                    'service',
                    $iblockid,
                    $products,
                    $limit
                );
            } catch (\Throwable $e) {
                set_config('catalog_service_warning', $e->getMessage(), 'local_bitrixsync');
            }
        }

        ksort($products);
        return array_values($products);
    }

    private function collect_modern_page(
        string $method,
        string $resultkey,
        string $kind,
        int $iblockid,
        array &$products,
        int $limit
    ): void {
        $start = 0;

        while (count($products) < $limit) {
            $response = $this->client->call($method, [
                'select' => [
                    'id',
                    'iblockId',
                    'name',
                    'active',
                    'code',
                    'xmlId',
                    'iblockSectionId',
                ],
                'filter' => ['iblockId' => $iblockid],
                'order' => ['id' => 'asc'],
                'start' => $start,
            ]);

            $page = $response['result'][$resultkey] ?? [];
            if (!is_array($page) || !$page) {
                break;
            }

            foreach ($page as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $products[$id] = [
                    'id' => $id,
                    'name' => trim((string)($row['name'] ?? '')),
                    'code' => trim((string)($row['code'] ?? '')),
                    'xmlid' => trim((string)($row['xmlId'] ?? '')),
                    'iblockid' => (int)($row['iblockId'] ?? $iblockid),
                    'sectionid' => (int)($row['iblockSectionId'] ?? 0),
                    'active' => strtoupper((string)($row['active'] ?? 'Y')) !== 'N' ? 1 : 0,
                    'source' => $kind,
                ];

                if (count($products) >= $limit) {
                    break;
                }
            }

            if (count($page) < 50) {
                break;
            }

            $start += 50;
        }
    }

    /**
     * CRM-scope compatibility fallback.
     *
     * Both methods are deprecated by Bitrix24, but are useful during the
     * migration while the webhook may have CRM scope without Catalog scope.
     */
    public function list_legacy_products(int $limit = 500): array {
        $limit = max(1, min(5000, $limit));

        $catalogresponse = $this->client->call('crm.catalog.list', [
            'order' => ['ID' => 'ASC'],
            'filter' => [],
            'select' => ['ID', 'NAME'],
            'start' => 0,
        ]);

        $catalogs = $catalogresponse['result'] ?? [];
        if (!is_array($catalogs) || !$catalogs) {
            return [];
        }

        $products = [];

        foreach ($catalogs as $catalog) {
            $catalogid = (int)($catalog['ID'] ?? 0);
            if ($catalogid <= 0) {
                continue;
            }

            $start = 0;
            while (count($products) < $limit) {
                $response = $this->client->call('crm.product.list', [
                    'order' => ['ID' => 'ASC'],
                    'filter' => ['CATALOG_ID' => $catalogid],
                    'select' => [
                        'ID',
                        'CATALOG_ID',
                        'NAME',
                        'ACTIVE',
                        'CODE',
                        'XML_ID',
                        'SECTION_ID',
                    ],
                    'start' => $start,
                ]);

                $page = $response['result'] ?? [];
                if (!is_array($page) || !$page) {
                    break;
                }

                foreach ($page as $row) {
                    $id = (int)($row['ID'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $products[$id] = [
                        'id' => $id,
                        'name' => trim((string)($row['NAME'] ?? '')),
                        'code' => trim((string)($row['CODE'] ?? '')),
                        'xmlid' => trim((string)($row['XML_ID'] ?? '')),
                        'iblockid' => (int)($row['CATALOG_ID'] ?? $catalogid),
                        'sectionid' => (int)($row['SECTION_ID'] ?? 0),
                        'active' => strtoupper((string)($row['ACTIVE'] ?? 'Y')) !== 'N' ? 1 : 0,
                        'source' => 'legacy-crm',
                    ];

                    if (count($products) >= $limit) {
                        break;
                    }
                }

                if (count($page) < 50) {
                    break;
                }

                $start += 50;
            }

            if (count($products) >= $limit) {
                break;
            }
        }

        ksort($products);
        return array_values($products);
    }
}
