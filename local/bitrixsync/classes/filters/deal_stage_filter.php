<?php
namespace local_bitrixsync\filters;

defined('MOODLE_INTERNAL') || die();

class deal_stage_filter {
    public function can_process(array $deal): bool {
        $allowed = get_config('local_bitrixsync', 'v5_allowed_stages');
        if (!$allowed) {
            return false;
        }

        $stages = array_filter(array_map('trim', explode(',', $allowed)));
        return in_array((string)($deal['STAGE_ID'] ?? ''), $stages, true);
    }
}
