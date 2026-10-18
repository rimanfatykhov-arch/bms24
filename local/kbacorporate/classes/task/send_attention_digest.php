<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Scheduled delivery of corporate learning-attention digests. */
class send_attention_digest extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('tasksendattentiondigest', 'local_kbacorporate');
    }

    public function execute() {
        $result = \local_kbacorporate\local\attention_notifier::run();
        mtrace(get_string('attentiontaskresult', 'local_kbacorporate', $result));
    }
}
