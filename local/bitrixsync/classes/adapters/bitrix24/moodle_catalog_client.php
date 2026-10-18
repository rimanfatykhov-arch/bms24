<?php
namespace local_bitrixsync\adapters\bitrix24;

defined('MOODLE_INTERNAL') || die();

class moodle_catalog_client {
    /** @var client */
    private $client;

    /** @var array|null */
    private $context = null;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function resolve_context(): array {
        if ($this->context !== null) {
            return $this->context;
        }

        $configured = (int)get_config('local_bitrixsync', 'bitrix_catalog_iblock_id');
        $allowlegacy = (bool)get_config('local_bitrixsync', 'catalog_allow_legacy_write');

        try {
            $response = $this->client->call('catalog.catalog.list', [
                'select' => ['id', 'iblockId', 'name', 'productIblockId'],
                'filter' => [],
                'order' => ['id' => 'asc'],
                'start' => 0,
            ]);

            $catalogs = $response['result']['catalogs'] ?? [];
            if (!is_array($catalogs) || !$catalogs) {
                throw new \moodle_exception('bitrix_catalog_missing', 'local_bitrixsync');
            }

            $selected = null;
            foreach ($catalogs as $catalog) {
                $iblockid = (int)($catalog['iblockId'] ?? 0);
                $catalogid = (int)($catalog['id'] ?? 0);

                if ($configured > 0 && ($configured === $iblockid || $configured === $catalogid)) {
                    $selected = $catalog;
                    break;
                }
            }

            if ($selected === null && $configured <= 0) {
                foreach ($catalogs as $catalog) {
                    if ((int)($catalog['iblockId'] ?? 0) > 0) {
                        $selected = $catalog;
                        break;
                    }
                }
            }

            if ($selected === null) {
                throw new \moodle_exception('bitrix_catalog_configured_not_found', 'local_bitrixsync', '', $configured);
            }

            $this->context = [
                'mode' => 'catalog',
                'catalogid' => (int)($selected['id'] ?? 0),
                'iblockid' => (int)($selected['iblockId'] ?? 0),
                'name' => (string)($selected['name'] ?? ''),
                'deprecated' => false,
            ];

            set_config('catalog_master_api_mode', 'catalog', 'local_bitrixsync');
            set_config('catalog_master_catalog_id', $this->context['catalogid'], 'local_bitrixsync');
            set_config('catalog_master_iblock_id', $this->context['iblockid'], 'local_bitrixsync');

            return $this->context;
        } catch (\Throwable $modernerror) {
            if (!$allowlegacy) {
                throw $modernerror;
            }

            $response = $this->client->call('crm.catalog.list', [
                'order' => ['ID' => 'ASC'],
                'filter' => [],
                'select' => ['ID', 'NAME'],
                'start' => 0,
            ]);

            $catalogs = $response['result'] ?? [];
            if (!is_array($catalogs) || !$catalogs) {
                throw new \moodle_exception('bitrix_catalog_missing', 'local_bitrixsync');
            }

            $selected = null;
            foreach ($catalogs as $catalog) {
                $catalogid = (int)($catalog['ID'] ?? 0);
                if ($configured > 0 && $configured === $catalogid) {
                    $selected = $catalog;
                    break;
                }
            }

            if ($selected === null && $configured <= 0) {
                $selected = reset($catalogs);
            }

            if (!$selected) {
                throw new \moodle_exception('bitrix_catalog_configured_not_found', 'local_bitrixsync', '', $configured);
            }

            $this->context = [
                'mode' => 'legacy-crm',
                'catalogid' => (int)($selected['ID'] ?? 0),
                'iblockid' => (int)($selected['ID'] ?? 0),
                'name' => (string)($selected['NAME'] ?? ''),
                'deprecated' => true,
                'modernerror' => $modernerror->getMessage(),
            ];

            set_config('catalog_master_api_mode', 'legacy-crm', 'local_bitrixsync');
            set_config('catalog_master_catalog_id', $this->context['catalogid'], 'local_bitrixsync');
            set_config('catalog_master_iblock_id', $this->context['iblockid'], 'local_bitrixsync');
            set_config('catalog_master_warning', $modernerror->getMessage(), 'local_bitrixsync');

            return $this->context;
        }
    }

    public function find_section_by_xmlid(string $xmlid): int {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $response = $this->client->call('catalog.section.list', [
                'select' => ['id', 'iblockId', 'name', 'xmlId'],
                'filter' => [
                    'iblockId' => $ctx['iblockid'],
                    'xmlId' => $xmlid,
                ],
                'order' => ['id' => 'asc'],
                'start' => 0,
            ]);

            $sections = $response['result']['sections'] ?? [];
            if (is_array($sections) && $sections) {
                $row = reset($sections);
                return (int)($row['id'] ?? 0);
            }

            return 0;
        }

        // Best-effort discovery for the deprecated CRM fallback.
        try {
            $response = $this->client->call('crm.productsection.list', [
                'order' => ['ID' => 'ASC'],
                'filter' => [
                    'CATALOG_ID' => $ctx['catalogid'],
                    'XML_ID' => $xmlid,
                ],
                'select' => ['ID', 'NAME', 'XML_ID'],
                'start' => 0,
            ]);

            $sections = $response['result'] ?? [];
            if (is_array($sections) && $sections) {
                $row = reset($sections);
                return (int)($row['ID'] ?? 0);
            }
        } catch (\Throwable $e) {
            // Local KBA mapping remains authoritative in fallback mode.
        }

        return 0;
    }

    public function create_section(
        string $name,
        string $xmlid,
        string $code,
        int $parentsectionid,
        bool $active,
        string $description = ''
    ): int {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $response = $this->client->call('catalog.section.add', [
                'fields' => [
                    'iblockId' => $ctx['iblockid'],
                    'iblockSectionId' => max(0, $parentsectionid),
                    'name' => $name,
                    'active' => $active ? 'Y' : 'N',
                    'sort' => 500,
                    'code' => $code,
                    'xmlId' => $xmlid,
                    'description' => $description,
                    'descriptionType' => 'text',
                ],
            ]);

            return (int)($response['result']['section']['id'] ?? 0);
        }

        $response = $this->client->call('crm.productsection.add', [
            'fields' => [
                'CATALOG_ID' => $ctx['catalogid'],
                'NAME' => $name,
                'SECTION_ID' => max(0, $parentsectionid),
                'SORT' => 500,
                'ACTIVE' => $active ? 'Y' : 'N',
                'XML_ID' => $xmlid,
            ],
        ]);

        return (int)($response['result'] ?? 0);
    }

    public function update_section(
        int $sectionid,
        string $name,
        string $xmlid,
        string $code,
        int $parentsectionid,
        bool $active,
        string $description = ''
    ): void {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $this->client->call('catalog.section.update', [
                'id' => $sectionid,
                'fields' => [
                    // Required by the modern Bitrix24 catalog.section.update API.
                    // Without it Bitrix returns: Required fields: iblockId.
                    'iblockId' => $ctx['iblockid'],
                    'iblockSectionId' => max(0, $parentsectionid),
                    'name' => $name,
                    'active' => $active ? 'Y' : 'N',
                    'sort' => 500,
                    'code' => $code,
                    'xmlId' => $xmlid,
                    'description' => $description,
                    'descriptionType' => 'text',
                ],
            ]);
            return;
        }

        $this->client->call('crm.productsection.update', [
            'id' => $sectionid,
            'fields' => [
                'NAME' => $name,
                'SECTION_ID' => max(0, $parentsectionid),
                'SORT' => 500,
                'ACTIVE' => $active ? 'Y' : 'N',
            ],
        ]);
    }

    public function find_product_by_xmlid(string $xmlid): int {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $response = $this->client->call('catalog.product.list', [
                'select' => ['id', 'iblockId', 'name', 'xmlId'],
                'filter' => [
                    'iblockId' => $ctx['iblockid'],
                    'xmlId' => $xmlid,
                ],
                'order' => ['id' => 'asc'],
                'start' => 0,
            ]);

            $products = $response['result']['products'] ?? [];
            if (is_array($products) && $products) {
                $row = reset($products);
                return (int)($row['id'] ?? 0);
            }

            return 0;
        }

        $response = $this->client->call('crm.product.list', [
            'order' => ['ID' => 'ASC'],
            'filter' => [
                'CATALOG_ID' => $ctx['catalogid'],
                'XML_ID' => $xmlid,
            ],
            'select' => ['ID', 'NAME', 'XML_ID', 'CATALOG_ID'],
            'start' => 0,
        ]);

        $products = $response['result'] ?? [];
        if (is_array($products) && $products) {
            $row = reset($products);
            return (int)($row['ID'] ?? 0);
        }

        return 0;
    }

    public function create_product(
        string $name,
        string $xmlid,
        string $code,
        int $sectionid,
        bool $active,
        string $summary,
        string $metadata
    ): int {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $response = $this->client->call('catalog.product.add', [
                'fields' => [
                    'iblockId' => $ctx['iblockid'],
                    'name' => $name,
                    'active' => $active ? 'Y' : 'N',
                    'code' => $code,
                    'xmlId' => $xmlid,
                    'iblockSectionId' => max(0, $sectionid),
                    'previewText' => $summary,
                    'previewTextType' => 'text',
                    'detailText' => $metadata,
                    'detailTextType' => 'text',
                    'sort' => 500,
                    'canBuyZero' => 'Y',
                ],
            ]);

            return (int)($response['result']['element']['id'] ?? 0);
        }

        $response = $this->client->call('crm.product.add', [
            'fields' => [
                'CATALOG_ID' => $ctx['catalogid'],
                'SECTION_ID' => max(0, $sectionid),
                'NAME' => $name,
                'ACTIVE' => $active ? 'Y' : 'N',
                'CODE' => $code,
                'XML_ID' => $xmlid,
                'DESCRIPTION' => trim($summary . "\n\n" . $metadata),
                'SORT' => 500,
            ],
        ]);

        return (int)($response['result'] ?? 0);
    }

    public function update_product(
        int $productid,
        string $name,
        string $xmlid,
        string $code,
        int $sectionid,
        bool $active,
        string $summary,
        string $metadata
    ): void {
        $ctx = $this->resolve_context();

        if ($ctx['mode'] === 'catalog') {
            $this->client->call('catalog.product.update', [
                'id' => $productid,
                'fields' => [
                    // Required for reliable section binding in the current
                    // Bitrix24 Catalog API.
                    'iblockId' => $ctx['iblockid'],
                    'name' => $name,
                    'active' => $active ? 'Y' : 'N',
                    'code' => $code,
                    'xmlId' => $xmlid,
                    'iblockSectionId' => max(0, $sectionid),
                    'previewText' => $summary,
                    'previewTextType' => 'text',
                    'detailText' => $metadata,
                    'detailTextType' => 'text',
                    'sort' => 500,
                    'canBuyZero' => 'Y',
                ],
            ]);
            return;
        }

        $this->client->call('crm.product.update', [
            'id' => $productid,
            'fields' => [
                'SECTION_ID' => max(0, $sectionid),
                'NAME' => $name,
                'ACTIVE' => $active ? 'Y' : 'N',
                'CODE' => $code,
                'XML_ID' => $xmlid,
                'DESCRIPTION' => trim($summary . "\n\n" . $metadata),
                'SORT' => 500,
            ],
        ]);
    }
    /**
     * Read-only diagnostic snapshot of one Bitrix product.
     *
     * No secrets or raw transport metadata are returned.
     */
    public function get_product_snapshot(int $productid): array {
        $ctx = $this->resolve_context();

        if ($productid <= 0) {
            return [
                'found' => false,
                'id' => 0,
                'iblockid' => 0,
                'sectionid' => 0,
                'name' => '',
                'xmlid' => '',
            ];
        }

        if ($ctx['mode'] === 'catalog') {
            $response = $this->client->call('catalog.product.get', [
                'id' => $productid,
            ]);

            $product = $response['result']['product']
                ?? $response['product']
                ?? $response['result']
                ?? [];

            if (!is_array($product) || empty($product)) {
                return [
                    'found' => false,
                    'id' => $productid,
                    'iblockid' => 0,
                    'sectionid' => 0,
                    'name' => '',
                    'xmlid' => '',
                ];
            }

            return [
                'found' => true,
                'id' => (int)($product['id'] ?? $productid),
                'iblockid' => (int)(
                    $product['iblockId']
                    ?? $product['iblockid']
                    ?? 0
                ),
                'sectionid' => (int)(
                    $product['iblockSectionId']
                    ?? $product['iblocksectionid']
                    ?? 0
                ),
                'name' => (string)($product['name'] ?? ''),
                'xmlid' => (string)($product['xmlId'] ?? $product['xmlid'] ?? ''),
                'active' => (string)($product['active'] ?? ''),
            ];
        }

        $response = $this->client->call('crm.product.get', [
            'id' => $productid,
        ]);

        $product = $response['result'] ?? [];
        if (!is_array($product) || empty($product)) {
            return [
                'found' => false,
                'id' => $productid,
                'iblockid' => 0,
                'sectionid' => 0,
                'name' => '',
                'xmlid' => '',
            ];
        }

        return [
            'found' => true,
            'id' => (int)($product['ID'] ?? $productid),
            'iblockid' => (int)($product['CATALOG_ID'] ?? 0),
            'sectionid' => (int)($product['SECTION_ID'] ?? 0),
            'name' => (string)($product['NAME'] ?? ''),
            'xmlid' => (string)($product['XML_ID'] ?? ''),
            'active' => (string)($product['ACTIVE'] ?? ''),
        ];
    }

    /**
     * Find one existing Bitrix section for a Moodle category without creating it.
     *
     * Matching priority:
     * 1. XML_ID (stable machine identity)
     * 2. CODE (stable-ish machine identity)
     * 3. exact NAME inside the configured iblock
     *
     * NAME/CODE matches are accepted only when exactly one section matches.
     */
    public function find_section_for_repair(
        string $name,
        string $xmlid,
        string $code
    ): array {
        $ctx = $this->resolve_context();

        $xmlidmatch = $this->find_section_by_xmlid($xmlid);
        if ($xmlidmatch > 0) {
            return [
                'id' => $xmlidmatch,
                'matched_by' => 'xmlid',
                'candidates' => [$xmlidmatch],
            ];
        }

        if ($ctx['mode'] === 'catalog') {
            foreach ([
                ['field' => 'code', 'value' => $code],
                ['field' => 'name', 'value' => $name],
            ] as $match) {
                if (trim((string)$match['value']) === '') {
                    continue;
                }

                $response = $this->client->call('catalog.section.list', [
                    'select' => [
                        'id',
                        'iblockId',
                        'iblockSectionId',
                        'name',
                        'code',
                        'xmlId',
                    ],
                    'filter' => [
                        'iblockId' => (int)$ctx['iblockid'],
                        $match['field'] => (string)$match['value'],
                    ],
                    'order' => ['id' => 'asc'],
                    'start' => 0,
                ]);

                $sections = $response['result']['sections'] ?? [];
                $ids = [];

                if (is_array($sections)) {
                    foreach ($sections as $section) {
                        $id = (int)($section['id'] ?? 0);
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                }

                $ids = array_values(array_unique($ids));

                if (count($ids) === 1) {
                    return [
                        'id' => (int)$ids[0],
                        'matched_by' => (string)$match['field'],
                        'candidates' => $ids,
                    ];
                }

                if (count($ids) > 1) {
                    return [
                        'id' => 0,
                        'matched_by' => 'ambiguous_' . (string)$match['field'],
                        'candidates' => $ids,
                    ];
                }
            }

            return [
                'id' => 0,
                'matched_by' => 'not_found',
                'candidates' => [],
            ];
        }

        // Deprecated CRM catalog fallback.
        foreach ([
            ['field' => 'XML_ID', 'value' => $xmlid],
            ['field' => 'NAME', 'value' => $name],
        ] as $match) {
            if (trim((string)$match['value']) === '') {
                continue;
            }

            try {
                $response = $this->client->call('crm.productsection.list', [
                    'order' => ['ID' => 'ASC'],
                    'filter' => [
                        'CATALOG_ID' => (int)$ctx['catalogid'],
                        $match['field'] => (string)$match['value'],
                    ],
                    'select' => ['ID', 'NAME', 'XML_ID', 'SECTION_ID'],
                    'start' => 0,
                ]);

                $sections = $response['result'] ?? [];
                $ids = [];
                if (is_array($sections)) {
                    foreach ($sections as $section) {
                        $id = (int)($section['ID'] ?? 0);
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                }

                $ids = array_values(array_unique($ids));
                if (count($ids) === 1) {
                    return [
                        'id' => (int)$ids[0],
                        'matched_by' => strtolower((string)$match['field']),
                        'candidates' => $ids,
                    ];
                }
                if (count($ids) > 1) {
                    return [
                        'id' => 0,
                        'matched_by' => 'ambiguous_' . strtolower((string)$match['field']),
                        'candidates' => $ids,
                    ];
                }
            } catch (\Throwable $ignored) {
                // Continue to the next safe matching method.
            }
        }

        return [
            'id' => 0,
            'matched_by' => 'not_found',
            'candidates' => [],
        ];
    }

}
