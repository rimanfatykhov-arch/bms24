<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\role_readiness_view;

final class role_readiness_view_test extends \advanced_testcase {
    private function snapshot(): array {
        $positions = [];
        foreach (range(1, 53) as $id) {
            $positions[] = ['uuid' => 'p' . $id, 'name' => '<script>alert(1)</script>', 'code' => 'P',
                'design_status' => $id === 1 ? 'basic_links_present' : 'needs_description',
                'gaps' => $id === 1 ? [] : ['purpose_missing'], 'published_function_count' => 1,
                'published_role_dna_count' => 1];
        }
        return ['company_uuid' => 'company-a', 'positions' => $positions];
    }

    public function test_intersection_paging_and_card_scope(): void {
        $model = role_readiness_view::model($this->snapshot(), 'needs_description', 'purpose_missing', 9);
        $this->assertSame(52, $model['total']);
        $this->assertSame(1, $model['page']);
        $this->assertCount(2, $model['rows']);
        $card = new \moodle_url($model['rows'][0]['url']);
        $this->assertSame(['uuid' => 'company-a', 'view' => 'detail', 'position' => 'p52'], $card->params());
        $empty = role_readiness_view::model($this->snapshot(), 'basic_links_present', 'purpose_missing', 1);
        $this->assertSame([], $empty['rows']);
        $this->assertSame(0, $empty['page']);
    }

    public function test_template_escapes_position_names(): void {
        global $OUTPUT;
        $model = role_readiness_view::model($this->snapshot(), 'basic_links_present', '', 0);
        $html = $OUTPUT->render_from_template('local_kbacompany/bms24_role_readiness', $model);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_unsupported_filter_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        role_readiness_view::model($this->snapshot(), '', 'unknown', 0);
    }
}
