<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\moodle_catalog_client;

defined('MOODLE_INTERNAL') || die();

class moodle_master_catalog {
    /** @var moodle_catalog_client */
    private $bitrix;

    /** @var category_mapper */
    private $categories;

    /** @var product_mapper */
    private $products;

    public function __construct(
        ?moodle_catalog_client $bitrix = null,
        ?category_mapper $categories = null,
        ?product_mapper $products = null
    ) {
        $this->bitrix = $bitrix ?? new moodle_catalog_client();
        $this->categories = $categories ?? new category_mapper();
        $this->products = $products ?? new product_mapper();
    }

    public function sync(bool $dryrun = true): array {
        global $DB;

        $ctx = $this->bitrix->resolve_context();

        $result = [
            'dryrun' => $dryrun,
            'mode' => $ctx['mode'],
            'catalogid' => (int)$ctx['catalogid'],
            'iblockid' => (int)$ctx['iblockid'],
            'catalogname' => (string)$ctx['name'],
            'categories' => [
                'total' => 0,
                'create' => 0,
                'update' => 0,
                'synced' => 0,
                'errors' => 0,
            ],
            'courses' => [
                'total' => 0,
                'create' => 0,
                'update' => 0,
                'synced' => 0,
                'errors' => 0,
            ],
            'items' => [],
        ];

        $categories = $DB->get_records(
            'course_categories',
            null,
            'depth ASC, sortorder ASC, id ASC',
            'id,name,parent,depth,visible,idnumber,description'
        );

        $result['categories']['total'] = count($categories);

        foreach ($categories as $category) {
            $item = $this->sync_category($category, $ctx, $dryrun);
            $result['items'][] = $item;

            if ($item['status'] === 'error') {
                $result['categories']['errors']++;
            } else {
                $result['categories'][$item['action']]++;
                if (!$dryrun) {
                    $result['categories']['synced']++;
                }
            }
        }

        $courses = $DB->get_records_select(
            'course',
            'id <> ?',
            [SITEID],
            'category ASC, sortorder ASC, id ASC',
            'id,category,fullname,shortname,summary,visible,idnumber'
        );

        $result['courses']['total'] = count($courses);

        foreach ($courses as $course) {
            $item = $this->sync_course($course, $ctx, $dryrun);
            $result['items'][] = $item;

            if ($item['status'] === 'error') {
                $result['courses']['errors']++;
            } else {
                $result['courses'][$item['action']]++;
                if (!$dryrun) {
                    $result['courses']['synced']++;
                }
            }
        }

        if (!$dryrun) {
            set_config('moodle_catalog_last_sync', time(), 'local_bitrixsync');
            set_config('moodle_catalog_last_mode', $ctx['mode'], 'local_bitrixsync');
        }

        return $result;
    }

    private function sync_category(\stdClass $category, array $ctx, bool $dryrun): array {
        $xmlid = 'KBA-MOODLE-CATEGORY-' . (int)$category->id;
        $code = $this->safe_code(
            trim((string)$category->idnumber) !== ''
                ? (string)$category->idnumber
                : 'KBA-MOODLE-CATEGORY-' . (int)$category->id
        );

        $parentsectionid = 0;
        if ((int)$category->parent > 0) {
            $parentmap = $this->categories->get((int)$category->parent);
            if ($parentmap) {
                $parentsectionid = (int)$parentmap->bitrix_section_id;
            }
        }

        $mapping = $this->categories->get((int)$category->id);
        $sectionid = $mapping ? (int)$mapping->bitrix_section_id : 0;
        $action = $sectionid > 0 ? 'update' : 'create';

        $description = trim(strip_tags((string)$category->description));
        $description = $this->truncate($description, 3500);
        $metadata = "Источник: Moodle\nMoodle Category ID: " . (int)$category->id;
        $description = trim($description . "\n\n" . $metadata);

        try {
            if ($sectionid <= 0) {
                $sectionid = $this->bitrix->find_section_by_xmlid($xmlid);
                if ($sectionid > 0) {
                    $action = 'update';
                }
            }

            if ($dryrun) {
                return [
                    'type' => 'category',
                    'moodleid' => (int)$category->id,
                    'name' => (string)$category->name,
                    'action' => $action,
                    'bitrixid' => $sectionid ?: null,
                    'status' => 'planned',
                    'error' => null,
                ];
            }

            if ($sectionid > 0) {
                $this->bitrix->update_section(
                    $sectionid,
                    (string)$category->name,
                    $xmlid,
                    $code,
                    $parentsectionid,
                    !empty($category->visible),
                    $description
                );
            } else {
                $sectionid = $this->bitrix->create_section(
                    (string)$category->name,
                    $xmlid,
                    $code,
                    $parentsectionid,
                    !empty($category->visible),
                    $description
                );

                if ($sectionid <= 0) {
                    throw new \moodle_exception('bitrix_section_id_missing', 'local_bitrixsync');
                }
            }

            $this->categories->save(
                (int)$category->id,
                $sectionid,
                $parentsectionid,
                (int)$ctx['iblockid'],
                (string)$category->name,
                'synced'
            );

            return [
                'type' => 'category',
                'moodleid' => (int)$category->id,
                'name' => (string)$category->name,
                'action' => $action,
                'bitrixid' => $sectionid,
                'status' => 'synced',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            if (!$dryrun) {
                $this->categories->mark_error((int)$category->id, $e->getMessage());
            }

            return [
                'type' => 'category',
                'moodleid' => (int)$category->id,
                'name' => (string)$category->name,
                'action' => $action,
                'bitrixid' => $sectionid ?: null,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function sync_course(\stdClass $course, array $ctx, bool $dryrun): array {
        global $CFG;

        $xmlid = 'KBA-MOODLE-COURSE-' . (int)$course->id;
        $code = $this->safe_code(
            trim((string)$course->shortname) !== ''
                ? (string)$course->shortname
                : $xmlid
        );

        $categorymap = $this->categories->get((int)$course->category);
        $sectionid = $categorymap ? (int)$categorymap->bitrix_section_id : 0;

        $mapping = $this->products->get_by_course((int)$course->id);
        $productid = $mapping ? (int)$mapping->bitrix_product_id : 0;
        $action = $productid > 0 ? 'update' : 'create';

        $courseurl = rtrim($CFG->wwwroot, '/') . '/course/view.php?id=' . (int)$course->id;

        $summary = trim(strip_tags((string)$course->summary));
        $summary = $this->truncate($summary, 3500);

        $metadata = implode("\n", [
            'Источник: Moodle',
            'Moodle Course ID: ' . (int)$course->id,
            'Moodle Course Code: ' . (string)$course->shortname,
            'Moodle URL: ' . $courseurl,
        ]);

        try {
            if ($productid <= 0) {
                $productid = $this->bitrix->find_product_by_xmlid($xmlid);
                if ($productid > 0) {
                    $action = 'update';
                }
            }

            if ($dryrun) {
                return [
                    'type' => 'course',
                    'moodleid' => (int)$course->id,
                    'name' => (string)$course->fullname,
                    'action' => $action,
                    'bitrixid' => $productid ?: null,
                    'status' => 'planned',
                    'error' => null,
                ];
            }

            if ($productid > 0) {
                $this->bitrix->update_product(
                    $productid,
                    (string)$course->fullname,
                    $xmlid,
                    $code,
                    $sectionid,
                    !empty($course->visible),
                    $summary,
                    $metadata
                );
            } else {
                $productid = $this->bitrix->create_product(
                    (string)$course->fullname,
                    $xmlid,
                    $code,
                    $sectionid,
                    !empty($course->visible),
                    $summary,
                    $metadata
                );

                if ($productid <= 0) {
                    throw new \moodle_exception('bitrix_product_id_missing', 'local_bitrixsync');
                }
            }

            $this->products->bind_course(
                (int)$course->id,
                $productid,
                (int)$ctx['iblockid'],
                $sectionid,
                'synced'
            );

            return [
                'type' => 'course',
                'moodleid' => (int)$course->id,
                'name' => (string)$course->fullname,
                'action' => $action,
                'bitrixid' => $productid,
                'status' => 'synced',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            if (!$dryrun) {
                $this->products->mark_error((int)$course->id, $e->getMessage());
            }

            return [
                'type' => 'course',
                'moodleid' => (int)$course->id,
                'name' => (string)$course->fullname,
                'action' => $action,
                'bitrixid' => $productid ?: null,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function safe_code(string $value): string {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
        $value = trim((string)$value, '-_');

        if ($value === '') {
            $value = 'KBA';
        }

        return substr($value, 0, 100);
    }

    private function truncate(string $value, int $limit): string {
        if (\core_text::strlen($value) <= $limit) {
            return $value;
        }

        return \core_text::substr($value, 0, $limit);
    }
}
