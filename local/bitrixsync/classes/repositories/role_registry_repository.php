<?php
namespace local_bitrixsync\repositories;
defined('MOODLE_INTERNAL') || die();
class role_registry_repository {
    public function get_all(): array { global $DB; return array_values($DB->get_records('local_bitrixsync_role_registry', null, 'sort ASC')); }
    public function get_active(): array { global $DB; return array_values($DB->get_records('local_bitrixsync_role_registry', ['active'=>1], 'sort ASC')); }
    public function get_by_code(string $code): ?\stdClass { global $DB; return $DB->get_record('local_bitrixsync_role_registry',['rolecode'=>$code]) ?: null; }
    public function exists(string $code): bool { global $DB; return $DB->record_exists('local_bitrixsync_role_registry',['rolecode'=>$code]); }
}
