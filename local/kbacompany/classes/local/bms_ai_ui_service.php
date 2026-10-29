<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation adapter for BMS Assistant actions in company-context EditViews. */
class bms_ai_ui_service {
    public static function init(string $companyuuid, string $domain, ?array $record, string $section = ''): void {
        global $PAGE;
        $fields = [
            'philosophy' => ['purpose', 'mission', 'vision'],
            'market' => ['description', 'evidencesummary'],
            'strategy' => ['thesis', 'positioning', 'outcome', 'description'],
            'owner' => ['statement', 'horizonmetric', 'constraintsprice', 'businessevidence'],
            'task' => ['drafttext', 'title', 'product', 'description'], 'battleplan' => ['drafttext', 'weekgoal', 'weekoutcome'], 'ckp' => ['drafttext', 'name', 'acceptance'],
            'metric' => ['drafttext', 'name', 'description'], 'employee' => ['drafttext'], 'position' => ['drafttext', 'name', 'purpose'],
            'role' => ['drafttext'],
        ];
        if (!isset($fields[$domain])) {
            return;
        }
        $status = bms_ai_bridge_service::status();
        $PAGE->requires->js_call_amd('local_kbacompany/bms_assistant', 'init', [[
            'companyuuid' => company_context_service::normalise_uuid($companyuuid),
            'domain' => $domain,
            'recorduuid' => $record ? (string)$record['uuid'] : '',
            'revision' => $record ? (int)$record['revision'] : 0,
            'section' => $section,
            'endpoint' => (new \moodle_url('/local/kbacompany/bms_ai_assist.php'))->out(false),
            'available' => !empty($status['available']) && bms_ai_bridge_service::supports_domain($domain),
            'testmode' => !empty($status['test_mode']),
            'fields' => $fields[$domain],
            'unavailable' => get_string('bmsai_unavailable', 'local_kbacompany'),
        ]]);
    }
}
