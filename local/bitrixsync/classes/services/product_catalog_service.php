<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\catalog_client;
use local_bitrixsync\exception\bitrix_api_exception;

defined('MOODLE_INTERNAL') || die();

class product_catalog_service {
    /** @var catalog_client */
    private $client;

    public function __construct(?catalog_client $client = null) {
        $this->client = $client ?? new catalog_client();
    }

    public function sync(int $limit = 500): array {
        global $DB;

        $limit = max(1, min(5000, $limit));

        $mode = 'catalog';
        $warning = '';
        $modernerror = '';

        try {
            $products = $this->client->list_modern_products($limit);
        } catch (\Throwable $e) {
            $mode = 'legacy-crm';
            $modernerror = $this->format_error($e);

            try {
                $products = $this->client->list_legacy_products($limit);
                $warning = $modernerror;
            } catch (\Throwable $legacyerror) {
                $legacy = $this->format_error($legacyerror);

                set_config('catalog_api_mode', 'failed', 'local_bitrixsync');
                set_config('catalog_last_warning', $modernerror . ' || ' . $legacy, 'local_bitrixsync');

                throw new \moodle_exception(
                    'catalog_both_modes_failed',
                    'local_bitrixsync',
                    '',
                    (object)[
                        'modern' => $modernerror,
                        'legacy' => $legacy,
                    ]
                );
            }
        }

        $now = time();
        $created = 0;
        $updated = 0;

        foreach ($products as $product) {
            $productid = (int)$product['id'];
            if ($productid <= 0) {
                continue;
            }

            $record = $DB->get_record('local_bitrixsync_prodcache', [
                'bitrix_product_id' => $productid,
            ]);

            $data = (object)[
                'bitrix_product_id' => $productid,
                'name' => (string)$product['name'],
                'code' => (string)$product['code'],
                'xmlid' => (string)$product['xmlid'],
                'iblockid' => (int)$product['iblockid'] ?: null,
                'sectionid' => (int)$product['sectionid'] ?: null,
                'active' => !empty($product['active']) ? 1 : 0,
                'source' => (string)$product['source'],
                'timesynced' => $now,
                'timemodified' => $now,
            ];

            if ($record) {
                $data->id = $record->id;
                $data->timecreated = $record->timecreated;
                $DB->update_record('local_bitrixsync_prodcache', $data);
                $updated++;
            } else {
                $data->timecreated = $now;
                $DB->insert_record('local_bitrixsync_prodcache', $data);
                $created++;
            }
        }

        set_config('catalog_api_mode', $mode, 'local_bitrixsync');
        set_config('catalog_last_sync', $now, 'local_bitrixsync');
        set_config('catalog_last_count', count($products), 'local_bitrixsync');
        set_config('catalog_last_warning', $warning, 'local_bitrixsync');

        return [
            'mode' => $mode,
            'count' => count($products),
            'created' => $created,
            'updated' => $updated,
            'warning' => $warning,
        ];
    }

    public function auto_map(): array {
        global $DB;

        $mapper = new product_mapper();
        $courses = $DB->get_records_select(
            'course',
            'id <> ?',
            [SITEID],
            'id ASC',
            'id,fullname,shortname,category'
        );

        $byshortname = [];
        $byfullname = [];

        foreach ($courses as $course) {
            $short = $this->normalize((string)$course->shortname);
            $full = $this->normalize((string)$course->fullname);

            if ($short !== '') {
                $byshortname[$short][] = $course;
            }
            if ($full !== '') {
                $byfullname[$full][] = $course;
            }
        }

        $products = $DB->get_records('local_bitrixsync_prodcache', ['active' => 1], 'name ASC');
        $mapped = 0;
        $skipped = 0;
        $ambiguous = 0;

        foreach ($products as $product) {
            if ($DB->record_exists('local_bitrixsync_productmap', [
                'bitrix_product_id' => $product->bitrix_product_id,
                'active' => 1,
            ])) {
                $skipped++;
                continue;
            }

            $matches = [];

            $code = $this->normalize((string)$product->code);
            if ($code !== '' && !empty($byshortname[$code])) {
                $matches = $byshortname[$code];
            }

            if (!$matches) {
                $name = $this->normalize((string)$product->name);
                if ($name !== '' && !empty($byfullname[$name])) {
                    $matches = $byfullname[$name];
                }
            }

            if (count($matches) === 1) {
                $course = reset($matches);
                $mapper->map_product((int)$product->bitrix_product_id, (int)$course->id);
                $mapped++;
            } else if (count($matches) > 1) {
                $ambiguous++;
            } else {
                $skipped++;
            }
        }

        return [
            'mapped' => $mapped,
            'skipped' => $skipped,
            'ambiguous' => $ambiguous,
        ];
    }

    private function format_error(\Throwable $e): string {
        if ($e instanceof bitrix_api_exception) {
            return $e->diagnostic_message();
        }
        return $e->getMessage();
    }

    private function normalize(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        return \core_text::strtolower($value);
    }
}
