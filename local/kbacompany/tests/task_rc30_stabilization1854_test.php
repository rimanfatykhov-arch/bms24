<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_list_preferences_service as prefs;
use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** RC30 stabilization: sorting must agree with visible effective assignee. */
final class task_rc30_stabilization1854_test extends \advanced_testcase {
    public function test_assignee_sort_uses_coverage_expression_and_binds_all_parameters(): void {
        [$join, $order, $params] = prefs::sql_order('assignee', 'asc');
        $this->assertStringContainsString('sortperson.id = CASE WHEN', $join);
        $this->assertStringContainsString('cv.companyuuid=t.companyuuid', $join);
        $this->assertStringContainsString('cv.delegateid', $join);
        $this->assertStringContainsString('sortperson.lastname ASC', $order);
        $this->assertStringContainsString('t.id ASC', $order);
        $this->assertSame(['covlistsortatfrom','covlistsortatto',
            'covlistsortstart','covlistsortend'], array_keys($params));
        foreach (array_keys($params) as $key) {
            $this->assertStringContainsString(':'.$key, $join);
        }
        [$descendingjoin, $descendingorder, $descendingparams] = prefs::sql_order('assignee', 'desc');
        $this->assertStringContainsString('sortperson.lastname DESC', $descendingorder);
        $this->assertSame(array_keys($params), array_keys($descendingparams));
        $this->assertStringContainsString('cv.companyuuid=t.companyuuid', $descendingjoin);
    }

    public function test_corrupted_personal_preference_types_fall_back_without_php_warnings(): void {
        $this->resetAfterTest();
        $actor = $this->getDataGenerator()->create_user();
        $company = 'f9de920e-4e47-4ed8-b1f4-0bd29b127efa';
        set_user_preference('local_kbacompany_tasklist_'.$company,
            json_encode(['columns'=>['title','status'], 'sort'=>['title'], 'dir'=>['desc']]),
            (int)$actor->id);
        $current = prefs::current($company, (int)$actor->id);
        $this->assertSame(['title','status'], $current['columns']);
        $this->assertSame('duedate', $current['sort']);
        $this->assertSame('asc', $current['dir']);

        $corrupt = \local_kbacompany\local\task_kanban_preferences_service::normalise([
            'columns'=>['open'=>['label'=>['invalid'], 'order'=>1, 'visible'=>true]],
            'mapping'=>['open'=>['bad']], 'groupby'=>['bad'],
        ]);
        $this->assertSame('', $corrupt['columns']['open']['label']);
        $this->assertSame('none', $corrupt['groupby']);
        $this->assertSame('open', $corrupt['mapping']['open']);
    }

    public function test_all_other_sort_keys_keep_a_safe_parameterless_contract(): void {
        foreach (['title', 'creator', 'status', 'duedate', 'priority',
                'sourcekind', 'updated', 'created', 'INVALID;DROP'] as $key) {
            [$join, $order, $params] = prefs::sql_order($key, 'desc');
            $this->assertSame([], $params);
            $this->assertStringNotContainsString(';', $join.$order);
            $this->assertStringContainsString('t.id ASC', $order);
        }
    }

    public function test_assignee_sort_operates_before_paging_and_preserves_company_boundary(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $actor = (int)$USER->id;
        $companyid = repository::save_company((object)['name'=>'RC30 sorting tenant A','active'=>1]);
        $company = identity_registry::company_uuid($companyid);
        $othercompanyid = repository::save_company((object)['name'=>'RC30 sorting tenant B','active'=>1]);
        $othercompany = identity_registry::company_uuid($othercompanyid);
        foreach ([$companyid, $othercompanyid] as $id) {
            if (!$DB->record_exists('local_kbacorp_member', ['companyid'=>$id, 'userid'=>$actor])) {
                $DB->insert_record('local_kbacorp_member', (object)[
                    'companyid'=>$id,'userid'=>$actor,'membertype'=>'employee',
                    'isemployee'=>1,'ismanager'=>1,'isdepartmentmanager'=>0,'ishr'=>0,
                    'timecreated'=>time(),'timemodified'=>time(),
                ]);
            }
        }
        $employees = [];
        foreach (['Zed','Alpha'] as $name) {
            $user = $this->getDataGenerator()->create_user(['lastname'=>$name, 'firstname'=>'Sort']);
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid'=>$companyid,'userid'=>$user->id,'membertype'=>'employee',
                'isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>time(),'timemodified'=>time(),
            ]);
            $employees[] = (int)$user->id;
        }
        $data = static function(int $assignee, string $title): array {
            return ['title'=>$title,'product'=>'Verified result', 'description'=>'',
                'assigneeid'=>$assignee,'reviewerid'=>0,'observerid'=>0,
                'priority'=>'normal','stream'=>'operate','startdate'=>'','duedate'=>'',
                'planminutes'=>0,'parentuuid'=>''];
        };
        task_service::create($company, $actor, $data($employees[0], 'Zed owned'));
        task_service::create($company, $actor, $data($employees[1], 'Alpha owned'));
        task_service::create($othercompany, $actor, $data($actor, 'Other tenant'));
        $first = task_service::list_page($company, $actor,
            ['scope'=>'all','sort'=>'assignee','dir'=>'asc'], 0, 1);
        $second = task_service::list_page($company, $actor,
            ['scope'=>'all','sort'=>'assignee','dir'=>'asc'], 1, 1);
        $this->assertSame(2, $first['total']);
        $this->assertSame(2, $second['total']);
        $this->assertSame('Alpha owned', $first['tasks'][0]['title']);
        $this->assertSame('Zed owned', $second['tasks'][0]['title']);
    }
}
