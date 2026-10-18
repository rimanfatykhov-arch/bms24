<?php
namespace local_kbacorporate\local;
defined('MOODLE_INTERNAL') || die();

final class implementation_packages {
    public static function all(bool $activeonly = false): array {
        global $DB;
        return $DB->get_records('local_kbacorp_impl_package', $activeonly ? ['active' => 1] : [], 'sortorder, name');
    }

    public static function options(bool $activeonly = true): array {
        $options = [];
        foreach (self::all($activeonly) as $package) { $options[$package->code] = format_string($package->name); }
        return $options;
    }

    public static function get_by_code(string $code): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_kbacorp_impl_package', ['code' => $code]);
        return $record ?: null;
    }
}
