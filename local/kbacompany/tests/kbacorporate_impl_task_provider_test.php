<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_provider\kbacorporate_impl_task_provider as provider;

/** BMS-side projection and security contract. Full integration requires Corporate 0.40.3. */
final class kbacorporate_impl_task_provider_test extends \advanced_testcase {
    private function fixture(): array {
        return [
            'provider' => 'kbacorporate_impl',
            'external_id' => 'impltask:28', 'company_id' => 17,
            'program_title' => 'Внедрение BMS', 'program_status' => 'active',
            'title' => 'Разработать ЛПФ собственника', 'expected_result' => 'Утверждённый документ',
            'assignee_id' => 42, 'reviewer_id' => 11, 'creator_id' => 9,
            'status' => 'planned', 'priority' => 'normal',
            'due_at' => 1790208000, 'start_at' => 1789603200,
            'completed_at' => 0, 'time_modified' => 1789603200,
            'attachable' => true, 'editable' => false,
            'source_url' => 'https://invalid.example/phishing',
        ];
    }

    public function test_company_and_external_identifier_are_fail_closed(): void {
        $row = $this->fixture();
        $this->assertNull(provider::project($row, 19));
        $row['company_id'] = 17;
        foreach (['impltask:0', 'impltask:-12', 'impltask:01', 'impltask:28?redirect=bad',
                'impltask:999999999999999999999999999999'] as $id) {
            $row['external_id'] = $id;
            $this->assertNull(provider::project($row, 17));
        }
    }

    public function test_statuses_are_read_only_and_bms_compatible(): void {
        $this->assertSame('open', provider::status_value('planned'));
        $this->assertSame('doing', provider::status_value('inprogress'));
        $this->assertSame('review', provider::status_value('review'));
        $this->assertSame('done', provider::status_value('completed'));
        $this->assertSame('cancelled', provider::status_value('cancelled'));
        $this->assertSame('unknown', provider::status_value('unexpected'));
    }

    public function test_projection_never_trusts_external_source_url_or_editability(): void {
        $task = provider::project($this->fixture(), 17);
        $this->assertSame('/local/kbacorporate/implementation_task.php?id=28',
            parse_url($task['source_url'], PHP_URL_PATH) . '?'.parse_url($task['source_url'], PHP_URL_QUERY));
        $this->assertFalse($task['editable']);
        $this->assertSame('', $task['canonical_task_uuid']);
        $this->assertSame('develop', $task['stream']);
        $this->assertSame('open', $task['status']);
        $this->assertTrue($task['attachable']);
    }

    public function test_read_only_projected_task_is_not_attachable_when_ineligible(): void {
        foreach (['review', 'completed', 'cancelled', 'unexpected'] as $status) {
            $row = $this->fixture();
            $row['status'] = $status;
            $this->assertFalse(provider::project($row, 17)['attachable']);
        }
        $row = $this->fixture(); $row['attachable'] = false;
        $this->assertFalse(provider::project($row, 17)['attachable']);
    }
}
