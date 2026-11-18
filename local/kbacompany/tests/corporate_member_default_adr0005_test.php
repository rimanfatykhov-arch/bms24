<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0005 (accepted 2026-11-10), finding P1 in PR #124: on a fresh install an insert that omits
 * local_kbacorp_member.isemployee must store 0, exactly like the upgraded production schema.
 * PHPUnit installs the plugins from install.xml, so this test runs against the true fresh definition.
 */
final class corporate_member_default_adr0005_test extends \advanced_testcase {
    private function member(array $extra): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $company = $DB->insert_record('local_kbacorp_company', (object)['name' => 'ADR-0005 synthetic ' . $user->id,
            'active' => 1]);
        return (int)$DB->insert_record('local_kbacorp_member', (object)($extra + ['companyid' => $company,
            'userid' => $user->id, 'timecreated' => time(), 'timemodified' => time()]));
    }

    public function test_omitted_isemployee_defaults_to_zero_and_explicit_values_are_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $columns = $DB->get_columns('local_kbacorp_member');
        $this->assertSame('0', (string)$columns['isemployee']->default_value);
        $this->assertTrue($columns['isemployee']->not_null);
        foreach ([[[], 0], [['isemployee' => 0], 0], [['isemployee' => 1], 1]] as [$extra, $expected]) {
            $id = $this->member($extra);
            $this->assertSame($expected, (int)$DB->get_field('local_kbacorp_member', 'isemployee', ['id' => $id]),
                json_encode($extra));
        }
        // The other accepted ADR-0005 default is unchanged.
        $this->assertSame('draft', (string)$DB->get_columns('local_kbacorp_devplan')['status']->default_value);
    }
}
