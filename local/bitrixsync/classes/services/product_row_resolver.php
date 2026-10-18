<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\deal_client;

defined('MOODLE_INTERNAL') || die();

class product_row_resolver {
    /** @var deal_client */
    private $client;

    public function __construct(?deal_client $client = null) {
        $this->client = $client ?? new deal_client();
    }

    public function resolve_products(int $dealid): array {
        return $this->client->get_product_rows($dealid);
    }
}
