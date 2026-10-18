<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\administrative_scale_ui_service;

class administrative_scale_ui_service_test extends \advanced_testcase {
    public function test_ckp_schema_uses_record_specific_fields(): void {
        $schema = administrative_scale_ui_service::schema('ckp');
        $fields = [];
        foreach ($schema as $section) {
            foreach ($section['fields'] as $field) {
                $fields[] = $field;
            }
        }
        $this->assertContains('code', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('expectedresult', $fields);
        $this->assertContains('productuuid', $fields);
        $this->assertSame('adminscale_field_ckp_name', administrative_scale_ui_service::label_key('ckp', 'name'));
        $this->assertSame('adminscale_field_ckp_expectedresult',
            administrative_scale_ui_service::label_key('ckp', 'expectedresult'));
    }

    public function test_plan_schema_keeps_situation_goal_and_plan_body(): void {
        $schema = administrative_scale_ui_service::schema('plan');
        $fields = [];
        foreach ($schema as $section) {
            foreach ($section['fields'] as $field) {
                $fields[] = $field;
            }
        }
        $this->assertContains('situation', $fields);
        $this->assertContains('plangoal', $fields);
        $this->assertContains('description', $fields);
        $this->assertTrue(administrative_scale_ui_service::required('plan', 'situation'));
        $this->assertTrue(administrative_scale_ui_service::required('plan', 'plangoal'));
    }
}
