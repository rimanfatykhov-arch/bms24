<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve historical Bitrix Product IDs to the canonical Moodle-master product.
 *
 * Resolution is deliberately conservative:
 * 1. previously saved alias
 * 2. exact normalized legacy product name == exactly one Moodle course fullname
 *
 * The target course MUST already have a canonical Moodle-master Bitrix product
 * in local_bitrixsync_productmap.
 *
 * No fuzzy matching is used.
 */
class legacy_product_resolver {
    /** @var product_mapper */
    private $mapper;

    public function __construct(?product_mapper $mapper = null) {
        $this->mapper = $mapper ?? new product_mapper();
    }

    public function resolve(
        int $legacyproductid,
        string $legacyname,
        bool $persist = false
    ) {
        global $DB;

        if ($legacyproductid <= 0) {
            return false;
        }

        // A canonical product is never treated as a legacy alias.
        $direct = $DB->get_record(
            'local_bitrixsync_productmap',
            [
                'bitrix_product_id' => $legacyproductid,
                'active' => 1,
            ],
            '*',
            IGNORE_MULTIPLE
        );
        if ($direct) {
            $direct->_resolution_source = 'canonical';
            $direct->_canonical_product_id = (int)$direct->bitrix_product_id;
            $direct->_legacy_product_id = $legacyproductid;
            $direct->_alias_id = 0;
            return $direct;
        }

        // Reuse an existing alias.
        $alias = $DB->get_record(
            'local_bitrixsync_pralias',
            [
                'legacy_product_id' => $legacyproductid,
                'active' => 1,
            ],
            '*',
            IGNORE_MULTIPLE
        );

        if ($alias) {
            $mapping = $DB->get_record(
                'local_bitrixsync_productmap',
                [
                    'courseid' => (int)$alias->courseid,
                    'active' => 1,
                ],
                '*',
                IGNORE_MULTIPLE
            );

            if ($mapping &&
                    (int)$mapping->bitrix_product_id === (int)$alias->canonical_product_id) {
                $mapping->_resolution_source = 'alias';
                $mapping->_canonical_product_id = (int)$alias->canonical_product_id;
                $mapping->_legacy_product_id = $legacyproductid;
                $mapping->_alias_id = (int)$alias->id;
                $mapping->_legacy_product_name = (string)$alias->legacy_product_name;
                return $mapping;
            }
        }

        if (!get_config('local_bitrixsync', 'legacy_alias_resolution')) {
            return false;
        }

        $normalizedname = $this->normalize($legacyname);
        if ($normalizedname === '') {
            return false;
        }

        // Compare locally so normalization is identical on every DB engine.
        $courses = $DB->get_records_select(
            'course',
            'id <> ?',
            [SITEID],
            'id ASC',
            'id,fullname,shortname,category'
        );

        $matches = [];
        foreach ($courses as $course) {
            if ($this->normalize((string)$course->fullname) !== $normalizedname) {
                continue;
            }

            $canonical = $this->mapper->get_by_course((int)$course->id);
            if (!$canonical ||
                    empty($canonical->active) ||
                    (int)$canonical->bitrix_product_id <= 0) {
                // Moodle-master product has not been published yet.
                continue;
            }

            $matches[] = [
                'course' => $course,
                'mapping' => $canonical,
            ];
        }

        if (count($matches) !== 1) {
            return false;
        }

        $match = reset($matches);
        $mapping = $match['mapping'];

        $aliasid = 0;
        if ($persist && get_config('local_bitrixsync', 'legacy_alias_autosave')) {
            $aliasid = $this->save_alias(
                $legacyproductid,
                $legacyname,
                (int)$mapping->courseid,
                (int)$mapping->bitrix_product_id,
                'exact_name'
            );
        }

        $mapping->_resolution_source = 'legacy_exact_name';
        $mapping->_canonical_product_id = (int)$mapping->bitrix_product_id;
        $mapping->_legacy_product_id = $legacyproductid;
        $mapping->_alias_id = $aliasid;
        $mapping->_legacy_product_name = $legacyname;

        return $mapping;
    }

    public function inspect(
        int $legacyproductid,
        string $legacyname
    ): array {
        global $DB;

        $existing = $DB->get_record(
            'local_bitrixsync_pralias',
            ['legacy_product_id' => $legacyproductid],
            '*',
            IGNORE_MULTIPLE
        );

        if ($existing) {
            return [
                'legacyproductid' => $legacyproductid,
                'legacyname' => $legacyname,
                'status' => 'alias',
                'courseid' => (int)$existing->courseid,
                'canonicalproductid' => (int)$existing->canonical_product_id,
                'aliasid' => (int)$existing->id,
                'matchmethod' => (string)$existing->match_method,
                'error' => null,
            ];
        }

        $normalizedname = $this->normalize($legacyname);
        if ($normalizedname === '') {
            return [
                'legacyproductid' => $legacyproductid,
                'legacyname' => $legacyname,
                'status' => 'unmatched',
                'courseid' => 0,
                'canonicalproductid' => 0,
                'aliasid' => 0,
                'matchmethod' => '',
                'error' => get_string('legacy_name_empty', 'local_bitrixsync'),
            ];
        }

        $courses = $DB->get_records_select(
            'course',
            'id <> ?',
            [SITEID],
            'id ASC',
            'id,fullname,shortname'
        );

        $matches = [];
        foreach ($courses as $course) {
            if ($this->normalize((string)$course->fullname) !== $normalizedname) {
                continue;
            }

            $canonical = $this->mapper->get_by_course((int)$course->id);
            if ($canonical && !empty($canonical->active) &&
                    (int)$canonical->bitrix_product_id > 0) {
                $matches[] = [
                    'course' => $course,
                    'mapping' => $canonical,
                ];
            }
        }

        if (count($matches) === 1) {
            $match = reset($matches);
            return [
                'legacyproductid' => $legacyproductid,
                'legacyname' => $legacyname,
                'status' => 'suggested',
                'courseid' => (int)$match['course']->id,
                'canonicalproductid' => (int)$match['mapping']->bitrix_product_id,
                'aliasid' => 0,
                'matchmethod' => 'exact_name',
                'error' => null,
            ];
        }

        if (count($matches) > 1) {
            return [
                'legacyproductid' => $legacyproductid,
                'legacyname' => $legacyname,
                'status' => 'ambiguous',
                'courseid' => 0,
                'canonicalproductid' => 0,
                'aliasid' => 0,
                'matchmethod' => '',
                'error' => get_string('legacy_name_ambiguous', 'local_bitrixsync'),
            ];
        }

        return [
            'legacyproductid' => $legacyproductid,
            'legacyname' => $legacyname,
            'status' => 'unmatched',
            'courseid' => 0,
            'canonicalproductid' => 0,
            'aliasid' => 0,
            'matchmethod' => '',
            'error' => get_string('legacy_name_unmatched', 'local_bitrixsync'),
        ];
    }

    public function save_alias(
        int $legacyproductid,
        string $legacyname,
        int $courseid,
        int $canonicalproductid,
        string $matchmethod = 'manual'
    ): int {
        global $DB;

        if ($legacyproductid <= 0 || $courseid <= 0 || $canonicalproductid <= 0) {
            throw new \moodle_exception('invalid_legacy_alias', 'local_bitrixsync');
        }

        if ($legacyproductid === $canonicalproductid) {
            throw new \moodle_exception('legacy_alias_same_product', 'local_bitrixsync');
        }

        $mapping = $this->mapper->get_by_course($courseid);
        if (!$mapping ||
                (int)$mapping->bitrix_product_id !== $canonicalproductid ||
                empty($mapping->active)) {
            throw new \moodle_exception('legacy_alias_canonical_invalid', 'local_bitrixsync');
        }

        $now = time();

        $existing = $DB->get_record(
            'local_bitrixsync_pralias',
            ['legacy_product_id' => $legacyproductid],
            '*',
            IGNORE_MULTIPLE
        );

        if ($existing) {
            $existing->legacy_product_name = $legacyname;
            $existing->courseid = $courseid;
            $existing->canonical_product_id = $canonicalproductid;
            $existing->match_method = $matchmethod;
            $existing->status = 'active';
            $existing->active = 1;
            $existing->last_used = $now;
            $existing->error = null;
            $existing->timemodified = $now;
            $DB->update_record('local_bitrixsync_pralias', $existing);
            return (int)$existing->id;
        }

        return (int)$DB->insert_record('local_bitrixsync_pralias', (object)[
            'legacy_product_id' => $legacyproductid,
            'legacy_product_name' => $legacyname,
            'courseid' => $courseid,
            'canonical_product_id' => $canonicalproductid,
            'match_method' => $matchmethod,
            'status' => 'active',
            'active' => 1,
            'last_used' => $now,
            'error' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public function touch_alias(int $aliasid): void {
        global $DB;

        if ($aliasid <= 0) {
            return;
        }

        $DB->set_field(
            'local_bitrixsync_pralias',
            'last_used',
            time(),
            ['id' => $aliasid]
        );
    }

    public function delete_alias(int $id): void {
        global $DB;
        $DB->delete_records('local_bitrixsync_pralias', ['id' => $id]);
    }

    private function normalize(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Exact semantic normalization only: quote glyphs + whitespace + case.
        $value = str_replace(
            ['“', '”', '„', '«', '»', '’', '‘', '`', '´'],
            ['"', '"', '"', '"', '"', "'", "'", "'", "'"],
            $value
        );

        $value = preg_replace('/\s+/u', ' ', $value);
        return \core_text::strtolower(trim((string)$value));
    }
}
