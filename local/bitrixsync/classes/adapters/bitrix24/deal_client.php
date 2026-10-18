<?php
namespace local_bitrixsync\adapters\bitrix24;

defined('MOODLE_INTERNAL') || die();

class deal_client {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function get_deal(int $dealid): array {
        $response = $this->client->call('crm.deal.get', ['id' => $dealid]);
        $result = $response['result'] ?? [];
        return is_array($result) ? $result : [];
    }

    /**
     * Preferred modern method is crm.item.productrow.list.
     * crm.deal.productrows.get remains as a compatibility fallback.
     */
    public function get_product_rows(int $dealid): array {
        try {
            $rows = [];
            $start = 0;

            while (true) {
                $response = $this->client->call('crm.item.productrow.list', [
                    'filter' => [
                        '=ownerType' => 'D',
                        '=ownerId' => $dealid,
                    ],
                    'order' => ['sort' => 'asc'],
                    'start' => $start,
                ]);

                $page = $response['result']['productRows'] ?? [];
                if (!is_array($page) || !$page) {
                    break;
                }

                foreach ($page as $row) {
                    $rows[] = [
                        'ID' => (int)($row['id'] ?? 0),
                        'PRODUCT_ID' => (int)($row['productId'] ?? 0),
                        'PRODUCT_NAME' => (string)($row['productName'] ?? ''),
                        'QUANTITY' => (float)($row['quantity'] ?? 1),
                        'PRICE' => (float)($row['price'] ?? 0),
                    ];
                }

                if (count($page) < 50) {
                    break;
                }

                $start += 50;
                if ($start >= 5000) {
                    break;
                }
            }

            set_config('productrow_api_mode', 'crm.item.productrow.list', 'local_bitrixsync');
            return $rows;
        } catch (\Throwable $modernerror) {
            // Compatibility with existing Bitrix24 portals while migrating.
            $response = $this->client->call('crm.deal.productrows.get', ['id' => $dealid]);
            $rows = $response['result'] ?? [];
            set_config('productrow_api_mode', 'crm.deal.productrows.get (legacy)', 'local_bitrixsync');

            return is_array($rows) ? array_values($rows) : [];
        }
    }

    public function get_company(int $companyid): array {
        if ($companyid <= 0) {
            return [];
        }
        $response = $this->client->call('crm.company.get', ['id' => $companyid]);
        $result = $response['result'] ?? [];
        return is_array($result) ? $result : [];
    }

    public function get_contact(int $contactid): array {
        if ($contactid <= 0) {
            return [];
        }
        $response = $this->client->call('crm.contact.get', ['id' => $contactid]);
        $result = $response['result'] ?? [];
        return is_array($result) ? $result : [];
    }
    /** Return hydrated contacts belonging to one Bitrix24 company. */
    public function get_company_contacts(int $companyid): array {
        if ($companyid <= 0) {
            return [];
        }
        $out = [];
        $start = 0;
        $guard = 0;
        do {
            $response = $this->client->call('crm.contact.list', [
                'filter' => ['COMPANY_ID' => $companyid],
                // Keep enough fields for a safe hydration fallback. Some portals can
                // return an empty crm.contact.get for a relation that is still visible
                // in crm.contact.list. Email resolution must not silently lose it.
                'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'PHONE', 'COMPANY_ID', 'POST'],
                'order' => ['ID' => 'ASC'],
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
                $contact = [];
                $hydration = 'crm.contact.get';
                try {
                    $contact = $this->get_contact($id);
                } catch (\Throwable $e) {
                    $contact = [];
                }
                if (!$contact) {
                    $contact = is_array($row) ? $row : [];
                    $hydration = 'crm.contact.list_fallback';
                }
                if ($contact) {
                    $contact['_KBA_HYDRATION_SOURCE'] = $hydration;
                }
                $out[] = ['id' => $id, 'contact' => $contact, 'hydration' => $hydration];
            }
            if (empty($response['next'])) {
                break;
            }
            $start = (int)$response['next'];
            $guard++;
        } while ($guard < 100);
        return $out;
    }

    /**
     * Return every contact linked to the deal and hydrate each relation with
     * the full Bitrix24 contact card. crm.deal.contact.items.get returns the
     * relation only, therefore EMAIL/PHONE/NAME must be loaded separately.
     */
    public function get_deal_contacts(int $dealid): array {
        if ($dealid <= 0) {
            return [];
        }

        $relations = [];
        try {
            $response = $this->client->call(
                'crm.deal.contact.items.get',
                ['id' => $dealid]
            );
            $relations = $response['result'] ?? [];
            if (!is_array($relations)) {
                $relations = [];
            }
        } catch (\Throwable $e) {
            // Compatibility fallback below uses CONTACT_ID / CONTACT_IDS from
            // crm.deal.get, so a temporary relation endpoint problem does not
            // break the already working production path.
            $relations = [];
        }

        $ids = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }
            $id = (int)($relation['CONTACT_ID'] ?? $relation['contactId'] ?? 0);
            if ($id > 0) {
                $ids[$id] = [
                    'id' => $id,
                    'isprimary' => !empty($relation['IS_PRIMARY']) || !empty($relation['isPrimary']),
                    'sort' => (int)($relation['SORT'] ?? $relation['sort'] ?? 0),
                    'relation' => $relation,
                ];
            }
        }

        // Fallback/merge with IDs available directly on the deal.
        $deal = $this->get_deal($dealid);
        $primaryid = (int)($deal['CONTACT_ID'] ?? 0);
        if ($primaryid > 0 && !isset($ids[$primaryid])) {
            $ids[$primaryid] = [
                'id' => $primaryid,
                'isprimary' => true,
                'sort' => 0,
                'relation' => [],
            ];
        }
        if (!empty($deal['CONTACT_IDS']) && is_array($deal['CONTACT_IDS'])) {
            foreach ($deal['CONTACT_IDS'] as $contactid) {
                $contactid = (int)$contactid;
                if ($contactid > 0 && !isset($ids[$contactid])) {
                    $ids[$contactid] = [
                        'id' => $contactid,
                        'isprimary' => ($contactid === $primaryid),
                        'sort' => 0,
                        'relation' => [],
                    ];
                }
            }
        }

        uasort($ids, function(array $a, array $b): int {
            if ($a['isprimary'] !== $b['isprimary']) {
                return $a['isprimary'] ? -1 : 1;
            }
            if ($a['sort'] === $b['sort']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['sort'] <=> $b['sort'];
        });

        $out = [];
        foreach ($ids as $meta) {
            $contact = $this->get_contact((int)$meta['id']);
            if (!$contact) {
                $out[] = [
                    'id' => (int)$meta['id'],
                    'isprimary' => !empty($meta['isprimary']),
                    'contact' => [],
                    'relation' => $meta['relation'],
                    'error' => 'Bitrix24 contact card could not be loaded',
                ];
                continue;
            }

            $out[] = [
                'id' => (int)$meta['id'],
                'isprimary' => !empty($meta['isprimary']),
                'contact' => $contact,
                'relation' => $meta['relation'],
                'error' => null,
            ];
        }

        return $out;
    }

}
