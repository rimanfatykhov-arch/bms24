<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class sync_manager {
    public function process_queue_item(\stdClass $item): void {
        global $DB;

        $payload = [];
        if (!empty($item->payload)) {
            $decoded = json_decode($item->payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($item->entity_type === 'crmfields' &&
                in_array(
                    $item->action,
                    [
                        'crm_fields_provision_entity',
                        'crm_fields_refresh_entity',
                    ],
                    true
                )) {
            $entity = trim((string)($payload['entity'] ?? ''));

            if (!in_array($entity, ['company', 'contact', 'deal'], true)) {
                throw new \moodle_exception(
                    'crm_entity_unknown',
                    'local_bitrixsync',
                    '',
                    $entity
                );
            }

            $manager = new crm_field_manager();
            $create =
                $item->action === 'crm_fields_provision_entity';

            $result = $manager->resolve_entity(
                $entity,
                $create
            );

            if ($create) {
                foreach ($result as $field) {
                    if (empty($field['ready'])) {
                        throw new \moodle_exception(
                            'crm_field_entity_incomplete',
                            'local_bitrixsync',
                            '',
                            $entity
                        );
                    }
                }
            }

            return;
        }

        if ($item->entity_type === 'deal' &&
                in_array($item->action, ['full_sync', 'sync_enrollments'], true)) {
            $overrideuserid = !empty($payload['userid'])
                ? (int)$payload['userid']
                : null;

            if ($overrideuserid !== null && $overrideuserid > 0) {
                // Preserve the targeted/manual sync path when a specific Moodle
                // user is explicitly supplied in the queue payload.
                $flow = new deal_flow_service();
                $flow->process(
                    (int)$item->entity_id,
                    false,
                    false,
                    $overrideuserid
                );
            } else {
                // Normal Deal webhook/queue execution must process every
                // attached Bitrix Contact, not only CONTACT_ID / primary contact.
                $flow = new multi_contact_deal_service();
                $flow->process(
                    (int)$item->entity_id,
                    false
                );
            }
            return;
        }


        if ($item->entity_type === 'company' &&
                $item->action === 'bms_sync') {
            $result = (new bms_bridge_service())->sync_company(
                (int)$item->entity_id,
                true
            );
            $payload['result'] = $result;
            $item->payload = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }

        if ($item->entity_type === 'contact' &&
                $item->action === 'profile_sync') {
            // Contact updates never trigger course enrolment. They refresh the
            // current CRM Contact and synchronize Corporate membership/roles.
            $result = (new contact_profile_sync_service())->process(
                (int)$item->entity_id
            );

            // Keep the execution result in the queue payload so History/debug
            // can show what happened without introducing another log table.
            $payload['result'] = $result;
            $item->payload = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return;
        }

        throw new \moodle_exception(
            'queue_action_unknown',
            'local_bitrixsync',
            '',
            $item->action
        );
    }

    public function run_batch(int $limit = 20): array {
        global $DB;

        $limit = max(1, min(100, $limit));

        $items = $DB->get_records(
            'local_bitrixsync_syncqueue',
            ['status' => 'pending'],
            'timecreated ASC, id ASC',
            '*',
            0,
            $limit
        );

        $stats = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($items as $item) {
            $stats['processed']++;

            $item->status = 'processing';
            $item->attempts = ((int)$item->attempts) + 1;
            $item->timemodified = time();
            $DB->update_record('local_bitrixsync_syncqueue', $item);

            try {
                $this->process_queue_item($item);
                $item->status = 'completed';
                $item->error = null;
                $stats['completed']++;
            } catch (\Throwable $e) {
                $item->status = 'failed';
                $item->error = $e->getMessage();
                $stats['failed']++;
            }

            $item->timemodified = time();
            $DB->update_record('local_bitrixsync_syncqueue', $item);

            // CRM field creation can involve several remote calls. Keep it
            // out of web workers and provision only one entity per cron run.
            if ($item->entity_type === 'crmfields' &&
                    $item->action === 'crm_fields_provision_entity') {
                break;
            }
        }

        return $stats;
    }
}
