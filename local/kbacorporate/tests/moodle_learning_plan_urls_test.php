<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\moodle_learning_plans;

/** Regression guards for links to Moodle core learning-plan pages. */
final class moodle_learning_plan_urls_test extends \advanced_testcase {
    public function test_template_url_includes_explicit_page_context(): void {
        $url = moodle_learning_plans::template_url(17, 29);
        $params = $url->params();

        $this->assertSame(17, (int)$params['templateid']);
        $this->assertSame(29, (int)$params['pagecontextid']);
    }

    public function test_template_url_discovers_context_from_moodle_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'core_competency');

        $context = \context_system::instance();
        $template = \core_competency\api::create_template((object)[
            'shortname' => 'KBA URL regression template',
            'description' => 'Template used to verify the pagecontextid URL contract.',
            'descriptionformat' => FORMAT_PLAIN,
            'duedate' => 0,
            'visible' => 1,
            'contextid' => $context->id,
        ]);

        $url = moodle_learning_plans::template_url((int)$template->get('id'));
        $params = $url->params();

        $this->assertSame((int)$template->get('id'), (int)$params['templateid']);
        $this->assertSame((int)$context->id, (int)$params['pagecontextid']);
    }
}
