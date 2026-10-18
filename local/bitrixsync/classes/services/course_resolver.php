<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class course_resolver {
    /** @var product_mapper */
    private $mapper;

    /** @var legacy_product_resolver */
    private $legacy;

    public function __construct(
        ?product_mapper $mapper = null,
        ?legacy_product_resolver $legacy = null
    ) {
        $this->mapper = $mapper ?? new product_mapper();
        $this->legacy = $legacy ?? new legacy_product_resolver($this->mapper);
    }

    /**
     * Production resolution order:
     * 1. canonical product map;
     * 2. legacy coursemap used by the current Bitrix product rows;
     * 3. pralias/name legacy resolver.
     */
    public function resolve(
        int $productid,
        ?string $productname = null,
        bool $persistalias = false
    ) {
        global $DB;

        $mapping = $DB->get_record(
            'local_bitrixsync_productmap',
            [
                'bitrix_product_id' => $productid,
                'active' => 1,
            ],
            '*',
            IGNORE_MULTIPLE
        );

        if ($mapping) {
            $mapping->_resolution_source = 'canonical_productmap';
            $mapping->_canonical_product_id = (int)$mapping->bitrix_product_id;
            $mapping->_legacy_product_id = $productid;
            $mapping->_alias_id = 0;
            return $mapping;
        }

        // Existing production data on this Moodle uses coursemap for the
        // product IDs that are still present in historical/current deals.
        $coursemaptable = new \xmldb_table('local_bitrixsync_coursemap');
        if ($DB->get_manager()->table_exists($coursemaptable)) {
            $coursemap = $DB->get_record(
                'local_bitrixsync_coursemap',
                ['productid' => $productid],
                '*',
                IGNORE_MULTIPLE
            );

            if ($coursemap) {
                $laststatus = isset($coursemap->laststatus)
                    ? trim((string)$coursemap->laststatus)
                    : '';

                if ($laststatus === '' || $laststatus === 'active') {
                    $resolved = new \stdClass();
                    $resolved->id = (int)$coursemap->id;
                    $resolved->bitrix_product_id = $productid;
                    $resolved->courseid = (int)$coursemap->courseid;
                    $resolved->coursecode = (string)($coursemap->coursecode ?? '');
                    $resolved->categoryid = (int)($coursemap->categoryid ?? 0);
                    $resolved->active = 1;
                    $resolved->_resolution_source = 'coursemap';
                    $resolved->_canonical_product_id = $productid;
                    $resolved->_legacy_product_id = $productid;
                    $resolved->_alias_id = 0;
                    return $resolved;
                }
            }
        }

        return $this->legacy->resolve(
            $productid,
            trim((string)$productname),
            $persistalias
        );
    }
}
