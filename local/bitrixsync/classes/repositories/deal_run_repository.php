<?php
namespace local_bitrixsync\repositories;

defined('MOODLE_INTERNAL') || die();

class deal_run_repository {
    public function start(
        int $dealid,
        bool $dryrun,
        int $companyid = 0,
        int $contactid = 0,
        int $userid = 0
    ): int {
        global $DB;

        $now = time();

        return (int)$DB->insert_record('local_bitrixsync_dealrun', (object)[
            'dealid' => $dealid,
            'companyid' => $companyid ?: null,
            'contactid' => $contactid ?: null,
            'userid' => $userid ?: null,
            'dryrun' => $dryrun ? 1 : 0,
            'status' => 'processing',
            'productrows' => 0,
            'resolved' => 0,
            'enrolled' => 0,
            'already' => 0,
            'failed' => 0,
            'crm_status' => null,
            'crm_error' => null,
            'timeline_id' => null,
            'company_timeline_id' => null,
            'payload' => null,
            'error' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public function finish(int $id, array $result): void {
        global $DB;

        $context = $result['context'] ?? [];

        $DB->update_record('local_bitrixsync_dealrun', (object)[
            'id' => $id,
            'companyid' => !empty($context['bitrixcompanyid'])
                ? (int)$context['bitrixcompanyid']
                : null,
            'contactid' => !empty($context['bitrixcontactid'])
                ? (int)$context['bitrixcontactid']
                : null,
            'userid' => !empty($result['userid']) ? (int)$result['userid'] : null,
            'status' => !empty($result['failed']) ? 'completed_with_errors' : 'completed',
            'productrows' => (int)($result['productrows'] ?? 0),
            'resolved' => (int)($result['resolved'] ?? 0),
            'enrolled' => (int)($result['enrolled'] ?? 0),
            'already' => (int)($result['already'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'crm_status' => !empty($result['crm']['errors'])
                ? 'error'
                : (!empty($result['crm']['enabled'])
                    ? 'synced'
                    : 'skipped'),
            'crm_error' => !empty($result['crm']['errors'])
                ? implode('; ', $result['crm']['errors'])
                : null,
            'timeline_id' => !empty($result['crm']['timeline']['deal']['id'])
                ? (int)$result['crm']['timeline']['deal']['id']
                : null,
            'company_timeline_id' => !empty($result['crm']['timeline']['company']['id'])
                ? (int)$result['crm']['timeline']['company']['id']
                : null,
            'payload' => json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'error' => null,
            'timemodified' => time(),
        ]);
    }

    public function fail(int $id, string $message): void {
        global $DB;

        $DB->update_record('local_bitrixsync_dealrun', (object)[
            'id' => $id,
            'status' => 'failed',
            'error' => $message,
            'timemodified' => time(),
        ]);
    }
}
