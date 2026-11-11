<?php
namespace local_kbacompany;
defined('MOODLE_INTERNAL') || die();
use local_kbacompany\local\employee_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\company_service;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;
use local_kbacorporate\local\access;
use local_kbacorporate\local\development_plans;

final class membership_revocation_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest(); $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $a = repository::save_company((object)['name'=>'Revoke A','active'=>1]);
        $b = repository::save_company((object)['name'=>'Keep B','active'=>1]);
        foreach ([$a,$b] as $id) { repository::add_member($id,(int)$user->id,'employee'); }
        $au = identity_registry::company_uuid($a); $bu = identity_registry::company_uuid($b);
        employee_service::ensure_membership($au,(int)$user->id,[],'bms');
        employee_service::ensure_membership($bu,(int)$user->id,[],'bms');
        $plan = $DB->insert_record('local_kbacorp_devplan',(object)[
            'companyid'=>$a,'userid'=>$user->id,'title'=>'Retained plan','status'=>'pending']);
        return [$user,$a,$b,$au,$bu,$plan];
    }

    public function test_revoke_denies_legacy_self_and_grants_preserving_history_and_other_company(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan] = $this->fixture();
        $before = $DB->get_record('local_kbacorp_devplan',['id'=>$plan]);
        $members = $DB->get_records('local_kbacorp_member',['companyid'=>$a]);
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $this->setUser($u);
        $this->assertTrue(membership_access_service::is_revoked($au,(int)$u->id));
        $this->assertSame($bu,company_service::company_context($bu,(int)$u->id)['uuid']);
        $this->assertNotContains($au,array_column(company_service::companies_for_user((int)$u->id),'uuid'));
        $this->assertArrayNotHasKey($a,access::get_competency_company_options((int)$u->id,(int)$u->id));
        try { company_service::company_context($au,(int)$u->id); $this->fail('Legacy fallback bypassed revoke'); }
        catch (\moodle_exception $e) { $this->assertSame('companynotavailable',$e->errorcode); }
        try { development_plans::get_plan($plan); $this->fail('Self read bypassed revoke'); }
        catch (\moodle_exception $e) { $this->assertNotEmpty($e->errorcode); }
        try { development_plans::employee_decision($plan,(int)$u->id,true,'must not persist'); $this->fail('Owner write bypassed revoke'); }
        catch (\moodle_exception $e) { $this->assertSame('companynotallowed',$e->errorcode); }
        $task=(object)['companyid'=>$a,'ownerid'=>$u->id,'deleted'=>0,'auditid'=>1];
        $this->assertFalse(\local_kbacorporate\local\implementation_task_discussion::can_access($task,(int)$u->id));
        $this->assertEquals($before,$DB->get_record('local_kbacorp_devplan',['id'=>$plan]));
        $this->assertEquals($members,$DB->get_records('local_kbacorp_member',['companyid'=>$a]));
    }

    public function test_sync_cannot_reopen_and_explicit_reopen_retains_revocation_history(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan] = $this->fixture(); $actor=(int)get_admin()->id;
        employee_service::close_membership($au,(int)$u->id,$actor);
        employee_service::close_membership($au,(int)$u->id,$actor);
        $this->assertSame(1,$DB->count_records('local_kbacompany_revoke',['companyuuid'=>$au,'userid'=>$u->id]));
        try { employee_service::ensure_membership($au,(int)$u->id,[],'bitrix24'); $this->fail('Sync must not reopen'); }
        catch (\moodle_exception $e) { $this->assertSame('companynotavailable',$e->errorcode); }
        employee_service::reopen_membership_access($au,(int)$u->id,$actor);
        $this->assertFalse(membership_access_service::is_revoked($au,(int)$u->id));
        $this->assertSame(1,$DB->count_records('local_kbacompany_revoke',['companyuuid'=>$au,'userid'=>$u->id]));
        employee_service::ensure_membership($au,(int)$u->id,[],'bms');
        $this->setUser($u); $this->assertSame($plan,(int)development_plans::get_plan($plan)->id);
        $this->setAdminUser(); employee_service::close_membership($au,(int)$u->id,$actor);
        $this->assertSame(2,$DB->count_records('local_kbacompany_revoke',['companyuuid'=>$au,'userid'=>$u->id]));
        $this->assertTrue($DB->record_exists('local_kbacorp_devplan',['id'=>$plan]));
    }

    public function test_legacy_only_user_and_global_grants_do_not_bypass_explicit_revoke(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan] = $this->fixture();
        $DB->delete_records('local_kbacompany_member',['companyuuid'=>$au,'userid'=>$u->id]);
        $role=create_role('Revocation global grant','revoke_global','');
        foreach (['local/kbacompany:viewallcompanies','local/kbacorporate:viewallcompanies'] as $cap) {
            assign_capability($cap,CAP_ALLOW,$role,\context_system::instance()->id);
        }
        role_assign($role,$u->id,\context_system::instance()->id);
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $this->assertFalse(access::has_full_company_access($a,(int)$u->id));
        $this->assertArrayNotHasKey($a,access::get_allowed_companies((int)$u->id));
        $this->assertNotContains($au,array_column(company_service::companies_for_user((int)$u->id),'uuid'));
        $this->assertContains($bu,array_column(company_service::companies_for_user((int)$u->id),'uuid'));
    }

    public function test_unauthorised_actor_cannot_revoke_or_reopen_another_company(): void {
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        $this->setUser($u);
        $this->expectException(\required_capability_exception::class);
        employee_service::close_membership($au,(int)$u->id,(int)$u->id);
    }
    public function test_canonical_company_mapping_denies_corporate_without_matching_registry_uuid(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        $canonical='11111111-1111-4111-8111-111111111111';
        $DB->insert_record('local_kbacompany_company',(object)[
            'uuid'=>$canonical,'name'=>'Canonical A','legacycompanyid'=>$a]);
        employee_service::close_membership($canonical,(int)$u->id,(int)get_admin()->id);
        $this->assertTrue(membership_access_service::is_legacy_revoked($a,(int)$u->id));
        $this->assertFalse(membership_access_service::is_legacy_revoked($b,(int)$u->id));
        $this->assertArrayNotHasKey($a,access::get_allowed_companies((int)$u->id));
    }

    public function test_owner_task_list_excludes_revoked_company_and_keeps_other_company(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture(); $ids=[];
        foreach ([$a,$b] as $company) {
            $tool=$DB->insert_record('local_kbacorp_bms_tool',(object)[
                'companyid'=>$company,'code'=>'revoke'.$company,'name'=>'Revocation fixture']);
            $criterion=$DB->insert_record('local_kbacorp_bms_crit',(object)[
                'toolid'=>$tool,'code'=>'criterion','name'=>'Test criterion']);
            $audit=$DB->insert_record('local_kbacorp_impl_audit',(object)[
                'companyid'=>$company,'toolid'=>$tool,'title'=>'Revocation audit']);
            $item=$DB->insert_record('local_kbacorp_impl_item',(object)[
                'auditid'=>$audit,'criterionid'=>$criterion,'criterioncode'=>'criterion','criterionname'=>'Test criterion']);
            $ids[]=$DB->insert_record('local_kbacorp_impl_task',(object)[
                'companyid'=>$company,'auditid'=>$audit,'itemid'=>$item,'title'=>'Retained task',
                'criterionname'=>'Test criterion','ownerid'=>$u->id]);
        }
        $this->assertCount(2,\local_kbacorporate\local\implementation_task_lifecycle::get_user_tasks((int)$u->id));
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $tasks=\local_kbacorporate\local\implementation_task_lifecycle::get_user_tasks((int)$u->id);
        $this->assertSame([$ids[1]],array_map(static function($t) { return (int)$t->id; },$tasks));
        $this->assertTrue($DB->record_exists('local_kbacorp_impl_task',['id'=>$ids[0]]));
    }

    public function test_privacy_erasure_does_not_restore_access_for_live_account(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $contexts=\local_kbacompany\privacy\provider::get_contexts_for_userid((int)$u->id);
        $this->assertContains(\context_system::instance()->id,array_map('intval',$contexts->get_contextids()));
        $approved=new \core_privacy\local\request\approved_contextlist($u,'local_kbacompany',
            [\context_system::instance()->id]);
        \local_kbacompany\privacy\provider::delete_data_for_user($approved);
        $this->assertTrue(membership_access_service::is_revoked($au,(int)$u->id));
        $this->assertTrue(membership_access_service::is_legacy_revoked($a,(int)$u->id));
        $this->assertTrue($DB->record_exists('local_kbacorp_devplan',['id'=>$plan]));
    }

    public function test_unauthorised_actor_cannot_reopen_revoked_access(): void {
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $this->setUser($u);
        try { employee_service::reopen_membership_access($au,(int)$u->id,(int)$u->id); $this->fail('Unauthorised reopen'); }
        catch (\moodle_exception $e) {
            $this->assertSame('companynotavailable',$e->errorcode);
            $this->assertTrue(membership_access_service::is_revoked($au,(int)$u->id));
        }
    }

    public function test_legacy_revoke_denies_active_canonical_alias_and_sync(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        $alias='22222222-2222-4222-8222-222222222222';
        $DB->insert_record('local_kbacompany_company',(object)[
            'uuid'=>$alias,'name'=>'Canonical alias','legacycompanyid'=>$a]);
        employee_service::ensure_membership($alias,(int)$u->id,[],'bms');
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);
        $this->assertTrue(membership_access_service::is_revoked($alias,(int)$u->id));
        $this->assertNotContains($alias,array_column(company_service::companies_for_user((int)$u->id),'uuid'));
        try { company_service::company_context($alias,(int)$u->id); $this->fail('Canonical alias bypassed revoke'); }
        catch (\moodle_exception $e) { $this->assertSame('companynotavailable',$e->errorcode); }
        try { employee_service::ensure_membership($alias,(int)$u->id,[],'bitrix24'); $this->fail('Alias sync bypassed revoke'); }
        catch (\moodle_exception $e) { $this->assertSame('companynotavailable',$e->errorcode); }
    }

    public function test_department_update_cannot_move_revoked_company_record(): void {
        global $DB;
        [$u,$a,$b,$au,$bu,$plan]=$this->fixture();
        $id=repository::save_department((object)['companyid'=>$a,'name'=>'Retained department','active'=>1]);
        $before=$DB->get_record('local_kbacorp_department',['id'=>$id]);
        employee_service::close_membership($au,(int)$u->id,(int)get_admin()->id);$this->setUser($u);
        try { repository::save_department((object)['id'=>$id,'companyid'=>$b,'name'=>'Forged move','active'=>1]); $this->fail('Department target mismatch accepted'); }
        catch (\moodle_exception $e) { $this->assertNotEmpty($e->errorcode); }
        $this->assertEquals($before,$DB->get_record('local_kbacorp_department',['id'=>$id]));
    }

}
