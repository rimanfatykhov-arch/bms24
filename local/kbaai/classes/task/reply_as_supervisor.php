<?php
namespace local_kbaai\task;
defined('MOODLE_INTERNAL') || die();
class reply_as_supervisor extends \core\task\adhoc_task {
    public function get_name() { return get_string('tasksupervisorreply','local_kbaai'); }
    public function execute() {
        $d=$this->get_custom_data(); $id=!empty($d->replyid)?(int)$d->replyid:0; if (!$id) { return; }
        try { (new \local_kbaai\service\supervisor_service())->process($id); }
        catch (\local_kbaai\provider\provider_exception $e) {
            if ($e->is_retryable()) { throw $e; }
            (new \local_kbaai\service\supervisor_service())->fail($id,$e->getMessage());
        } catch (\Throwable $e) { (new \local_kbaai\service\supervisor_service())->fail($id,$e->getMessage()); }
    }
}
