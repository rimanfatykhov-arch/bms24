<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\document_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\office_workspace_service;
use local_kbacompany\local\office_workspace_ui_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\policy_service;
use local_kbacorporate\local\repository;

/**
 * DOC-W2 Office workspace: feature switch, company isolation, actor-scoped counters,
 * revocation, empty state and the planned (non-link) sections. Synthetic data only.
 */
final class office_workspace_service_test extends \advanced_testcase {
    private function company(string $name): array {
        $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
        return ['id' => $companyid, 'uuid' => identity_registry::company_uuid($companyid)];
    }

    private function member(array $company, string $membertype = 'employee'): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $company['id'], 'userid' => $user->id, 'membertype' => $membertype,
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
        ]);
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => \core\uuid::generate(), 'companyuuid' => $company['uuid'], 'userid' => $user->id,
            'membertype' => $membertype, 'isemployee' => 1, 'active' => 1,
        ]);
        return $user;
    }

    private function document(string $company, int $admin, string $code, string $title): string {
        return document_service::create_manual($company, $admin, [
            'code' => $code, 'title' => $title, 'doctype' => 'procedure', 'doclevel' => 2,
            'content' => 'Synthetic ' . $code, 'changereason' => 'Initial issue',
        ]);
    }

    private function release(string $company, int $admin, int $reviewer, string $uuid): void {
        document_service::add_reviewer($company, $admin, $uuid, 1, $reviewer);
        document_service::submit_review($company, $admin, $uuid, 1);
        document_service::review_decision($company, $reviewer, $uuid, 1, 'approved', 'Checked');
        document_service::approve($company, $admin, $uuid, 1, 'Approved');
        document_service::publish($company, $admin, $uuid, 1, 0);
        document_service::activate($company, $admin, $uuid, 1);
    }

    private function decision_task(string $company, int $admin, int $assignee, string $title, string $due): string {
        global $DB;
        $uuid = task_service::create($company, $admin, [
            'title' => $title, 'product' => 'Synthetic result', 'description' => '', 'assigneeid' => $assignee,
            'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '', 'duedate' => '', 'planminutes' => 0,
        ]);
        // Fixture only: mark the task as created from a meeting decision with an explicit due date.
        $DB->set_field('local_kbacompany_task', 'sourcekind', 'decision', ['companyuuid' => $company, 'uuid' => $uuid]);
        $DB->set_field('local_kbacompany_task', 'duedate', $due, ['companyuuid' => $company, 'uuid' => $uuid]);
        return $uuid;
    }

    /** Builds companies A and B with documents, an acknowledgement and decision tasks. */
    private function scenario(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $a = $this->company('Office A');
        $b = $this->company('Office B');
        $ea = $this->member($a);
        $eb = $this->member($b);
        $effective = $this->document($a['uuid'], $admin, 'OA-1', 'A effective procedure');
        $this->release($a['uuid'], $admin, (int)$ea->id, $effective);
        document_service::require_acknowledgement($a['uuid'], $admin, $effective, 1, (int)$ea->id);
        $review = $this->document($a['uuid'], $admin, 'OA-2', 'A procedure in review');
        document_service::add_reviewer($a['uuid'], $admin, $review, 1, (int)$ea->id);
        document_service::submit_review($a['uuid'], $admin, $review, 1);
        $draft = $this->document($a['uuid'], $admin, 'OA-3', 'A private draft');
        $foreign = $this->document($b['uuid'], $admin, 'OB-1', 'B private draft');
        $past = date('Y-m-d', time() - 10 * DAYSECS);
        $future = date('Y-m-d', time() + 10 * DAYSECS);
        $overdue = $this->decision_task($a['uuid'], $admin, (int)$ea->id, 'A overdue decision task', $past);
        $open = $this->decision_task($a['uuid'], $admin, (int)$ea->id, 'A open decision task', $future);
        $manual = task_service::create($a['uuid'], $admin, [
            'title' => 'A manual task', 'product' => 'Synthetic result', 'description' => '', 'assigneeid' => (int)$ea->id,
            'reviewerid' => 0, 'observerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '', 'duedate' => '', 'planminutes' => 0,
        ]);
        $foreigntask = $this->decision_task($b['uuid'], $admin, (int)$eb->id, 'B overdue decision task', $past);
        return compact('admin', 'a', 'b', 'ea', 'eb', 'effective', 'review', 'draft', 'foreign', 'overdue', 'open',
            'manual', 'foreigntask');
    }

    public function test_switch_off_means_no_route_and_no_menu_item(): void {
        $s = $this->scenario();
        set_config(office_workspace_service::SETTING, 0, 'local_kbacompany');
        $this->assertFalse(office_workspace_service::enabled());
        try {
            office_workspace_service::overview($s['a']['uuid'], $s['admin']);
            $this->fail('Overview must not exist while the switch is off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('officews_disabled', $e->errorcode);
        }
        $routes = new \ReflectionMethod(bms_ui_service::class, 'routes');
        $routes->setAccessible(true);
        $groups = new \ReflectionMethod(bms_ui_service::class, 'navigation_groups');
        $groups->setAccessible(true);
        $model = $routes->invoke(null, $s['a']['uuid'], ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], true, false, 'office');
        $this->assertFalse($model['office']['sidebar']);
        $keys = [];
        foreach ($groups->invoke(null, $model) as $group) { $keys = array_merge($keys, array_column($group['items'], 'key')); }
        $this->assertNotContains('office', $keys);

        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $model = $routes->invoke(null, $s['a']['uuid'], ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], true, false, 'office');
        $management = array_values(array_filter($groups->invoke(null, $model), static function(array $g): bool {
            return $g['key'] === 'management';
        }))[0];
        $items = array_column($management['items'], null, 'key');
        $this->assertArrayHasKey('office', $items);
        $this->assertTrue($items['office']['active']);
        $this->assertStringContainsString('office.php', $items['office']['url']);
        $keys = array_column($management['items'], 'key');
        $this->assertSame(array_search('office', $keys, true) + 1, array_search('documents', $keys, true));
    }

    public function test_employee_counts_are_exact_owner_totals_and_isolated(): void {
        $s = $this->scenario();
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->setUser($s['ea']);
        $ea = (int)$s['ea']->id;
        $model = office_workspace_service::overview($s['a']['uuid'], $ea);
        $this->assertSame([
            'documents' => 2, 'review' => 1, 'acknowledge' => 1, 'execution' => 2, 'overdue' => 1,
            'directives' => 0, 'decisions' => 0,
        ], $model['counts']);
        $this->assertFalse($model['can_manage_documents']);
        // Each counter equals the total of the owning service query for the same actor.
        $this->assertSame(document_service::browse($s['a']['uuid'], $ea, [], 0)['total'], $model['counts']['documents']);
        $this->assertSame(document_service::browse($s['a']['uuid'], $ea, ['status' => 'review'], 0)['total'],
            $model['counts']['review']);
        $this->assertSame(task_service::list_page($s['a']['uuid'], $ea,
            ['scope' => 'my', 'sourcekind' => 'decision', 'signal' => 'overdue'])['total'], $model['counts']['overdue']);
        $this->assertSame(policy_service::browse($s['a']['uuid'], $ea, ['kind' => 'directive'])['total'],
            $model['counts']['directives']);
        // Drafts of A and anything of company B never appear, neither in lists nor in the rendered page.
        $html = office_workspace_ui_service::render($model);
        foreach (['A private draft', 'B private draft', 'B overdue decision task', 'A manual task'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        $this->assertStringContainsString('A procedure in review', $html);
        $this->assertStringContainsString('A effective procedure', $html);
        $this->assertStringContainsString('A overdue decision task', $html);
        $this->assertSame(['overdue', 'review', 'acknowledge'], array_column($model['attention'], 'key'));
        $this->assertSame([$s['overdue']], array_column($model['overdue'], 'uuid'));
        $this->assertSame([$s['effective']], array_column($model['acknowledge'], 'uuid'));

        // A foreign company is refused before any count is computed.
        $this->setUser($s['eb']);
        try {
            office_workspace_service::overview($s['a']['uuid'], (int)$s['eb']->id);
            $this->fail('Company B employee must not open company A.');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
        $other = office_workspace_service::overview($s['b']['uuid'], (int)$s['eb']->id);
        $this->assertSame(0, $other['counts']['documents']);
        $this->assertSame(1, $other['counts']['overdue']);
    }

    public function test_manager_sees_company_documents_and_create_action(): void {
        $s = $this->scenario();
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $model = office_workspace_service::overview($s['a']['uuid'], $s['admin']);
        $this->assertTrue($model['can_manage_documents']);
        $this->assertSame(3, $model['counts']['documents']);
        $this->assertSame(1, $model['counts']['review']);
        $this->assertSame(0, $model['counts']['acknowledge']);
        $this->assertSame(0, $model['counts']['execution']);
        $titles = array_column($model['recent'], 'title');
        $this->assertContains('A private draft', $titles);
        $this->assertNotContains('B private draft', $titles);
        $html = office_workspace_ui_service::render($model);
        $this->assertStringContainsString('view=create', $html);
    }

    public function test_revoked_membership_loses_access(): void {
        $s = $this->scenario();
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->setUser($s['ea']);
        $this->assertSame(1, office_workspace_service::overview($s['a']['uuid'], (int)$s['ea']->id)['counts']['acknowledge']);
        // Canonical revocation (the same switch the access acceptance uses): the next request is refused.
        membership_access_service::change($s['a']['uuid'], (int)$s['ea']->id, $s['admin']);
        try {
            office_workspace_service::overview($s['a']['uuid'], (int)$s['ea']->id);
            $this->fail('Revoked member must not open the Office workspace.');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('officews_disabled', $e->errorcode);
        }
    }

    public function test_empty_company_shows_empty_states_and_planned_sections_are_not_links(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $c = $this->company('Office empty');
        $user = $this->member($c);
        $this->setUser($user);
        $model = office_workspace_service::overview($c['uuid'], (int)$user->id);
        $this->assertSame(0, array_sum($model['counts']));
        $this->assertSame([], $model['attention']);
        $html = office_workspace_ui_service::render($model);
        foreach (['attention', 'review', 'acknowledge', 'overdue', 'recent'] as $key) {
            $this->assertStringContainsString('data-office-empty="' . $key . '"', $html);
        }
        foreach (office_workspace_service::PLANNED_SECTIONS as $key) {
            $this->assertMatchesRegularExpression('~<span[^>]*data-office-planned="' . $key . '"~', $html);
        }
        $this->assertStringNotContainsString('view=create', $html);
        $this->assertSame(0, preg_match('~<a[^>]*data-office-planned~', $html));
    }
}
