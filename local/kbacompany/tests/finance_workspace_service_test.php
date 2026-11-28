<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_ui_service;
use local_kbacompany\local\budgeting_service;
use local_kbacompany\local\finance_workspace_service;
use local_kbacompany\local\finance_workspace_ui_service;
use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_statement_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\resource_gate_service;
use local_kbacorporate\local\repository;

/**
 * FIN-W2 Finance workspace: feature switch and menu, the existing financial gate, two-company isolation,
 * plan / confirmed fact / no fact, per-currency totals, revocation, empty states and planned (non-link) sections.
 * Synthetic data only.
 */
final class finance_workspace_service_test extends \advanced_testcase {
    private function company(string $name): array {
        $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
        return ['id' => $companyid, 'uuid' => identity_registry::company_uuid($companyid)];
    }

    private function member(array $company, bool $manager = false): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $company['id'], 'userid' => $user->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => (int)$manager, 'isdepartmentmanager' => 0, 'ishr' => 0,
        ]);
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => \core\uuid::generate(), 'companyuuid' => $company['uuid'], 'userid' => $user->id,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => (int)$manager, 'active' => 1,
        ]);
        if ($manager) {
            // Company manager (not a site admin): the existing management capability plus the company manager flag.
            $context = \context_system::instance();
            $roleid = create_role('Finance test manager ' . $user->id, 'financemanager' . $user->id, 'Synthetic fixture');
            assign_capability('local/kbacompany:viewowncompany', CAP_ALLOW, $roleid, $context->id);
            assign_capability('local/kbacompany:manageorganization', CAP_ALLOW, $roleid, $context->id);
            assign_capability('local/kbacompany:viewallcompanies', CAP_PROHIBIT, $roleid, $context->id);
            role_assign($roleid, $user->id, $context->id);
        }
        return $user;
    }

    private function scenario_published(string $company, int $admin, string $code, string $currency): string {
        $uuid = financial_model_service::save_scenario($company, $admin, [
            'code' => $code, 'name' => 'Scenario ' . $code, 'scenariotype' => 'base',
            'periodstart' => '2027-01-01', 'periodend' => '2027-12-31', 'currency' => $currency,
            'description' => '', 'assumptions' => '', 'strategyuuid' => '', 'businessmodeluuid' => '',
        ]);
        financial_model_service::publish_scenario($company, $admin, $uuid, 1);
        return $uuid;
    }

    private function orgunit(string $company): string {
        global $DB;
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_orgnode', (object)[
            'companyuuid' => $company, 'branchuuid' => 'main', 'uuid' => $uuid, 'parentuuid' => '',
            'code' => 'OPS', 'nodetype' => 'department', 'name' => 'Operations', 'sortorder' => 1,
            'color' => '', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        return $uuid;
    }

    private function budget(string $company, int $admin, string $scenario, string $org, string $code, string $name,
            string $currency, array $lines): string {
        $rows = [];
        foreach ($lines as $linecode => $plan) {
            $rows[] = ['linecode' => $linecode, 'name' => 'Line ' . $linecode, 'linetype' => 'expense',
                'periodstart' => '2027-01-01', 'periodend' => '2027-03-31', 'planamount' => $plan,
                'limitamount' => $plan, 'ownerpositionuuid' => '', 'indicatoruuid' => '', 'sourceinfo' => 'Plan'];
        }
        return budgeting_service::save_budget($company, $admin, [
            'code' => $code, 'name' => $name, 'budgettype' => 'orgunit', 'scenariouuid' => $scenario,
            'centeruuid' => '', 'targetuuid' => $org, 'periodstart' => '2027-01-01', 'periodend' => '2027-03-31',
            'currency' => $currency, 'approvalrule' => 'CEO approval', 'notes' => '',
        ], $rows);
    }

    private function gate_items(string $status): array {
        $items = [];
        foreach (resource_gate_service::RESOURCE_KINDS as $kind) {
            $items[] = ['itemcode' => strtoupper(substr($kind, 0, 4)), 'resourcekind' => $kind,
                'name' => ucfirst($kind) . ' readiness', 'required' => 1, 'status' => $status,
                'referencekind' => 'none', 'referenceuuid' => '', 'referencerevision' => 0,
                'expected' => 'Required', 'available' => '', 'gap' => '', 'evidence' => '',
                'evidenceurl' => '', 'ownerpositionuuid' => '', 'duedate' => ''];
        }
        return $items;
    }

    private function target(string $company): string {
        global $DB;
        $uuid = \core\uuid::generate();
        $DB->insert_record('local_kbacompany_adminitem', (object)[
            'companyuuid' => $company, 'uuid' => $uuid, 'revision' => 1, 'status' => 'published',
            'kind' => 'project', 'code' => 'TARGET', 'name' => 'Launch target', 'description' => '',
            'expectedresult' => '', 'parentuuid' => '', 'parentrevision' => 0, 'sourcekind' => '',
            'sourceuuid' => '', 'sourcerevision' => 0, 'ownerpositionuuid' => '', 'productuuid' => '',
            'metricuuid' => '', 'targetvalue' => '', 'startdate' => '', 'duedate' => '', 'sortorder' => 0,
            'createdby' => 0, 'modifiedby' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return $uuid;
    }

    /**
     * Company A: RUB and USD scenarios, an approved RUB budget with a fact on one of two lines, an approved USD budget
     * without fact, a RUB budget on approval, a published P&L and a draft resource gate with pending items.
     * Company B: an approved budget with a private title.
     */
    private function fixture(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)get_admin()->id;
        $a = $this->company('Finance A');
        $b = $this->company('Finance B');
        $ea = $this->member($a);
        $ma = $this->member($a, true);
        $mb = $this->member($b, true);
        $rub = $this->scenario_published($a['uuid'], $admin, 'BASE-RUB', 'RUB');
        $usd = $this->scenario_published($a['uuid'], $admin, 'BASE-USD', 'USD');
        $org = $this->orgunit($a['uuid']);

        $withfact = $this->budget($a['uuid'], $admin, $rub, $org, 'BUD-RUB', 'A RUB budget', 'RUB',
            ['MKT' => 1000, 'OPS' => 500]);
        budgeting_service::submit_budget($a['uuid'], $admin, $withfact, 1);
        budgeting_service::approve_budget($a['uuid'], $admin, $withfact, 2, 'Approved');
        budgeting_service::record_fact($a['uuid'], $admin, $withfact, 3, 'MKT', [
            'asofdate' => '2027-03-31', 'actualamount' => 900, 'sourcekind' => 'manual', 'sourceinfo' => 'Management close',
        ]);
        $nofact = $this->budget($a['uuid'], $admin, $usd, $org, 'BUD-USD', 'A USD budget', 'USD', ['IT' => 200]);
        budgeting_service::submit_budget($a['uuid'], $admin, $nofact, 1);
        budgeting_service::approve_budget($a['uuid'], $admin, $nofact, 2, 'Approved');
        $submitted = $this->budget($a['uuid'], $admin, $rub, $org, 'BUD-NEXT', 'A budget on approval', 'RUB', ['HR' => 300]);
        budgeting_service::submit_budget($a['uuid'], $admin, $submitted, 1);

        $statement = financial_statement_service::save_statement($a['uuid'], $admin, [
            'code' => 'BDR-2027-01', 'name' => 'A January P&L plan', 'statementtype' => 'bdr',
            'scenariouuid' => $rub, 'centeruuid' => '', 'periodstart' => '2027-01-01', 'periodend' => '2027-01-31',
            'currency' => 'RUB', 'sourceinfo' => 'Sales plan', 'notes' => '',
        ], ['REV' => [1 => 1000], 'VAR' => [1 => 300], 'FIX' => [1 => 200], 'OTH_INC' => [1 => 0],
            'OTH_EXP' => [1 => 0], 'TAX' => [1 => 50]], []);
        financial_statement_service::publish_statement($a['uuid'], $admin, $statement, 1);

        resource_gate_service::save_gate($a['uuid'], $admin, [
            'code' => 'RG-A', 'name' => 'A launch gate', 'targettype' => 'project', 'targetuuid' => $this->target($a['uuid']),
            'scenariouuid' => '', 'plannedstart' => '2027-02-01', 'ownerpositionuuid' => '',
        ], $this->gate_items('pending'));

        $bscenario = $this->scenario_published($b['uuid'], $admin, 'BASE-B', 'EUR');
        $borg = $this->orgunit($b['uuid']);
        $bbudget = $this->budget($b['uuid'], $admin, $bscenario, $borg, 'BUD-B', 'B private budget', 'EUR', ['X' => 777]);
        budgeting_service::submit_budget($b['uuid'], $admin, $bbudget, 1);
        budgeting_service::approve_budget($b['uuid'], $admin, $bbudget, 2, 'Approved');
        return compact('admin', 'a', 'b', 'ea', 'ma', 'mb', 'withfact', 'nofact', 'submitted');
    }

    private function nav_items(string $company, bool $canmanagement): array {
        $routes = new \ReflectionMethod(bms_ui_service::class, 'routes');
        $routes->setAccessible(true);
        $groups = new \ReflectionMethod(bms_ui_service::class, 'navigation_groups');
        $groups->setAccessible(true);
        $model = $routes->invoke(null, $company, ['incoming' => 0, 'tasks' => 0, 'overdue' => 0], $canmanagement, false,
            'financeworkspace');
        $out = [];
        foreach ($groups->invoke(null, $model) as $group) {
            foreach ($group['items'] as $item) { $out[$item['key']] = $item + ['group' => $group['key']]; }
        }
        return $out;
    }

    public function test_switch_off_means_no_route_and_no_menu_item(): void {
        $s = $this->fixture();
        set_config(finance_workspace_service::SETTING, 0, 'local_kbacompany');
        $this->assertFalse(finance_workspace_service::enabled());
        $this->setUser($s['ma']);
        try {
            finance_workspace_service::overview($s['a']['uuid'], (int)$s['ma']->id);
            $this->fail('Overview must not exist while the switch is off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('financews_disabled', $e->errorcode);
        }
        $this->assertArrayNotHasKey('financeworkspace', $this->nav_items($s['a']['uuid'], true));

        set_config(finance_workspace_service::SETTING, 1, 'local_kbacompany');
        $items = $this->nav_items($s['a']['uuid'], true);
        $this->assertArrayHasKey('financeworkspace', $items);
        $this->assertSame('architecture', $items['financeworkspace']['group']);
        $this->assertTrue($items['financeworkspace']['active']);
        $this->assertStringContainsString('finance.php', $items['financeworkspace']['url']);
        $keys = array_keys(array_filter($items, static function(array $i): bool { return $i['group'] === 'architecture'; }));
        $this->assertSame(array_search('financeworkspace', $keys, true) + 1, array_search('financialmodel', $keys, true));
        // Without the existing financial gate there is no menu item even when the switch is on.
        $this->assertArrayNotHasKey('financeworkspace', $this->nav_items($s['a']['uuid'], false));
    }

    public function test_manager_sees_plan_fact_by_currency_and_no_fact_is_not_zero(): void {
        $s = $this->fixture();
        set_config(finance_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->assertFalse(is_siteadmin($s['ma']->id));
        $this->setUser($s['ma']);
        $ma = (int)$s['ma']->id;
        $model = finance_workspace_service::overview($s['a']['uuid'], $ma);

        $this->assertSame(['total' => 2, 'published' => 2], $model['counts']['scenarios']);
        $this->assertSame(['total' => 0], $model['counts']['centers']);
        $this->assertSame(['total' => 1, 'published' => 1], $model['counts']['statements']);
        $this->assertSame(3, $model['counts']['budgets']['total']);
        $this->assertSame(2, $model['counts']['budgets']['approved']);
        $this->assertSame(1, $model['counts']['budgets']['submitted']);
        $this->assertSame(['total' => 1, 'draft' => 1], $model['counts']['gates']);
        // Counters equal the owner registries for the same actor.
        $this->assertSame(count(budgeting_service::context($s['a']['uuid'], $ma)['budgets']), $model['counts']['budgets']['total']);
        $this->assertSame(count(financial_statement_service::context($s['a']['uuid'], $ma)['statements']),
            $model['counts']['statements']['total']);

        // Plan per currency, never one cross-currency total.
        $this->assertSame(['RUB' => 1500.0, 'USD' => 200.0], $model['plan_by_currency']);
        $rows = array_column($model['execution'], null, 'uuid');
        $this->assertEqualsCanonicalizing([$s['withfact'], $s['nofact']], array_keys($rows));
        $this->assertArrayNotHasKey($s['submitted'], $rows);
        $fact = $rows[$s['withfact']];
        $this->assertSame(3, $fact['revision']);
        $this->assertSame(900.0, $fact['fact']);
        $this->assertSame(-100.0, $fact['variance']); // Only lines with a fact are compared with plan.
        $this->assertSame([1, 2], [$fact['lineswithfact'], $fact['lines']]);
        $this->assertSame('2027-03-31', $fact['asof']);
        $this->assertSame(['manual'], $fact['factsources']);
        $none = $rows[$s['nofact']];
        $this->assertNull($none['fact']);
        $this->assertNull($none['variance']);
        $this->assertSame('USD', $none['currency']);
        $this->assertSame(['budgetsubmitted', 'budgetnofact', 'gatepending'], array_column($model['attention'], 'key'));

        $html = finance_workspace_ui_service::render($model);
        $this->assertStringContainsString('data-finance-fact="none"', $html);
        $this->assertStringContainsString('data-finance-fact="confirmed"', $html);
        $this->assertStringContainsString('data-finance-plan="RUB"', $html);
        $this->assertStringContainsString('data-finance-plan="USD"', $html);
        $this->assertStringContainsString('A January P&amp;L plan', $html);
        $this->assertStringContainsString('A launch gate', $html);
        $this->assertStringNotContainsString('B private budget', $html);
        $this->assertStringNotContainsString('EUR', $html);
    }

    public function test_employee_and_foreign_manager_are_refused_before_any_figure(): void {
        $s = $this->fixture();
        set_config(finance_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->setUser($s['ea']);
        $this->assertFalse(finance_workspace_service::can_view($s['a']['uuid'], (int)$s['ea']->id));
        try {
            finance_workspace_service::overview($s['a']['uuid'], (int)$s['ea']->id);
            $this->fail('An employee without the financial gate must not open Finance.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->setUser($s['mb']);
        try {
            finance_workspace_service::overview($s['a']['uuid'], (int)$s['mb']->id);
            $this->fail('Company B manager must not open company A.');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable', $e->errorcode);
        }
        $own = finance_workspace_service::overview($s['b']['uuid'], (int)$s['mb']->id);
        $this->assertSame(['EUR' => 777.0], $own['plan_by_currency']);
        $this->assertSame(1, $own['counts']['budgets']['total']);
        $html = finance_workspace_ui_service::render($own);
        $this->assertStringNotContainsString('A RUB budget', $html);
        $this->assertStringNotContainsString('A launch gate', $html);
    }

    public function test_revoked_membership_loses_access(): void {
        $s = $this->fixture();
        set_config(finance_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->setUser($s['ma']);
        $this->assertSame(2, finance_workspace_service::overview($s['a']['uuid'], (int)$s['ma']->id)['counts']['budgets']['approved']);
        membership_access_service::change($s['a']['uuid'], (int)$s['ma']->id, $s['admin']);
        try {
            finance_workspace_service::overview($s['a']['uuid'], (int)$s['ma']->id);
            $this->fail('Revoked member must not open the Finance workspace.');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('financews_disabled', $e->errorcode);
        }
    }

    public function test_empty_company_shows_empty_states_and_planned_sections_are_not_links(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config(finance_workspace_service::SETTING, 1, 'local_kbacompany');
        $c = $this->company('Finance empty');
        $user = $this->member($c, true);
        $this->setUser($user);
        $model = finance_workspace_service::overview($c['uuid'], (int)$user->id);
        foreach ($model['counts'] as $count) {
            $this->assertSame(['total' => 0], $count);
        }
        $this->assertSame([], $model['attention']);
        $this->assertSame([], $model['plan_by_currency']);
        $html = finance_workspace_ui_service::render($model);
        foreach (['attention', 'plan', 'execution', 'statements', 'gates'] as $key) {
            $this->assertStringContainsString('data-finance-empty="' . $key . '"', $html);
        }
        foreach (finance_workspace_service::PLANNED_SECTIONS as $key) {
            $this->assertMatchesRegularExpression('~<span[^>]*data-finance-planned="' . $key . '"~', $html);
        }
        $this->assertSame(0, preg_match('~<a[^>]*data-finance-planned~', $html));
        $this->assertSame(7, preg_match_all('~data-finance-count="[a-z]+" data-value="0"~', $html));
    }
}
