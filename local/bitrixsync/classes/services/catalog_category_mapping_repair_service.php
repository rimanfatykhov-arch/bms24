<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\moodle_catalog_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Reconnect Moodle categories to already existing Bitrix catalog sections.
 *
 * This service NEVER creates or deletes Bitrix sections. Its only write to
 * Bitrix is a normalization update after an unambiguous existing match.
 */
class catalog_category_mapping_repair_service {
    /** @var moodle_catalog_client */
    private $bitrix;
    /** @var category_mapper */
    private $categories;

    public function __construct(
        ?moodle_catalog_client $bitrix = null,
        ?category_mapper $categories = null
    ) {
        $this->bitrix = $bitrix ?? new moodle_catalog_client();
        $this->categories = $categories ?? new category_mapper();
    }

    public function run(): array {
        global $DB;

        $ctx = $this->bitrix->resolve_context();
        $categories = $DB->get_records(
            'course_categories',
            null,
            'depth ASC, sortorder ASC, id ASC',
            'id,name,parent,depth,visible,idnumber,description'
        );

        $result = [
            'time' => time(),
            'mode' => (string)$ctx['mode'],
            'iblockid' => (int)$ctx['iblockid'],
            'total' => count($categories),
            'already_bound' => 0,
            'repaired' => 0,
            'not_found' => 0,
            'ambiguous' => 0,
            'errors' => 0,
            'items' => [],
        ];

        foreach ($categories as $category) {
            $mapping = $this->categories->get((int)$category->id);
            $currentsectionid = $mapping
                ? (int)$mapping->bitrix_section_id
                : 0;

            if ($currentsectionid > 0) {
                $result['already_bound']++;
                $result['items'][] = [
                    'categoryid' => (int)$category->id,
                    'name' => (string)$category->name,
                    'status' => 'already_bound',
                    'sectionid' => $currentsectionid,
                    'matched_by' => 'local_mapping',
                    'error' => '',
                ];
                continue;
            }

            $xmlid = 'KBA-MOODLE-CATEGORY-' . (int)$category->id;
            $code = $this->safe_code(
                trim((string)$category->idnumber) !== ''
                    ? (string)$category->idnumber
                    : $xmlid
            );

            try {
                $match = $this->bitrix->find_section_for_repair(
                    (string)$category->name,
                    $xmlid,
                    $code
                );

                $sectionid = (int)($match['id'] ?? 0);
                $matchedby = (string)($match['matched_by'] ?? 'not_found');

                if ($sectionid <= 0) {
                    if (strpos($matchedby, 'ambiguous_') === 0) {
                        $result['ambiguous']++;
                        $status = 'ambiguous';
                    } else {
                        $result['not_found']++;
                        $status = 'not_found';
                    }

                    $result['items'][] = [
                        'categoryid' => (int)$category->id,
                        'name' => (string)$category->name,
                        'status' => $status,
                        'sectionid' => 0,
                        'matched_by' => $matchedby,
                        'candidates' => (array)($match['candidates'] ?? []),
                        'error' => '',
                    ];
                    continue;
                }

                $parentsectionid = 0;
                if ((int)$category->parent > 0) {
                    $parentmap = $this->categories->get((int)$category->parent);
                    if ($parentmap) {
                        $parentsectionid = (int)$parentmap->bitrix_section_id;
                    }
                }

                // Normalize identity and hierarchy on the existing section.
                $description = trim(strip_tags((string)$category->description));
                $description = $this->truncate($description, 3500);
                $metadata = "Источник: Moodle\nMoodle Category ID: " . (int)$category->id;
                $description = trim($description . "\n\n" . $metadata);

                $this->bitrix->update_section(
                    $sectionid,
                    (string)$category->name,
                    $xmlid,
                    $code,
                    $parentsectionid,
                    !empty($category->visible),
                    $description
                );

                $this->categories->save(
                    (int)$category->id,
                    $sectionid,
                    $parentsectionid,
                    (int)$ctx['iblockid'],
                    (string)$category->name,
                    'repaired'
                );

                $result['repaired']++;
                $result['items'][] = [
                    'categoryid' => (int)$category->id,
                    'name' => (string)$category->name,
                    'status' => 'repaired',
                    'sectionid' => $sectionid,
                    'matched_by' => $matchedby,
                    'error' => '',
                ];
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->categories->mark_error(
                    (int)$category->id,
                    $e->getMessage()
                );

                $result['items'][] = [
                    'categoryid' => (int)$category->id,
                    'name' => (string)$category->name,
                    'status' => 'error',
                    'sectionid' => 0,
                    'matched_by' => '',
                    'error' => $e->getMessage(),
                ];
            }
        }

        set_config(
            'catalog_category_mapping_repair_last',
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'local_bitrixsync'
        );

        return $result;
    }

    private function safe_code(string $value): string {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
        $value = trim((string)$value, '-_');

        if ($value === '') {
            $value = 'KBA-MOODLE';
        }

        return substr($value, 0, 100);
    }

    private function truncate(string $value, int $max): string {
        if (function_exists('core_text::strlen') &&
                \core_text::strlen($value) > $max) {
            return \core_text::substr($value, 0, $max);
        }

        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }

        return $value;
    }
}
