<?php
namespace local_kbaai\task;
defined('MOODLE_INTERNAL') || die();
class scan_submissions extends \core\task\scheduled_task {
    public function get_name() { return get_string('taskscansubmissions','local_kbaai'); }
    public function execute() {
        if (!get_config('local_kbaai','autoscan')) { return; }
        $adapter=new \local_kbaai\source\kbaelement_adapter(); $d=$adapter->diagnose(); if (!$d['ready']) { mtrace('KBA AI Assessor: mapping is not ready; autoscan skipped.'); return; }
        $repo=new \local_kbaai\repository\instance_repository(); $queue=new \local_kbaai\service\queue_service();
        foreach ($repo->list_enabled() as $cfg) {
            try { foreach ($adapter->list_submissions($cfg->kbaelementid,200) as $s) { $queue->queue($s->id,'autoscan',false); } }
            catch (\Throwable $e) { mtrace('KBA AI Assessor scan error for element '.$cfg->kbaelementid.': '.$e->getMessage()); }
        }
    }
}
