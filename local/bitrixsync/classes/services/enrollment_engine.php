<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class enrollment_engine {
    /** @var multi_course_engine */
    private $engine;

    public function __construct(?multi_course_engine $engine = null) {
        $this->engine = $engine ?? new multi_course_engine();
    }

    public function process_deal(int $dealid, int $userid, bool $dryrun = true, bool $force = false): array {
        return $this->engine->process_deal($dealid, $userid, $dryrun, $force);
    }
}
