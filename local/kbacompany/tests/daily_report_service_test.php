<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacorporate\local\repository;

/** Canonical data, civil days and tenant isolation for the read-only personal summary. */
final class daily_report_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest(); $this->setAdminUser(); $admin=(int)$USER->id;
        $cid=repository::save_company((object)['name'=>'Synthetic report A','active'=>1]);
        $company=identity_registry::company_uuid($cid);
        $users=[];
        foreach (['employee','peer'] as $name) {
            $u=$this->getDataGenerator()->create_user(['timezone'=>'Europe/Moscow']);
            $DB->insert_record('local_kbacorp_member',(object)['companyid'=>$cid,'userid'=>$u->id,
                'membertype'=>'employee','isemployee'=>1,'ismanager'=>0,'isdepartmentmanager'=>0,'ishr'=>0,
                'timecreated'=>time(),'timemodified'=>time()]);
            $users[$name]=$u;
        }
        return compact('admin','company','cid')+$users;
    }

    private function task(array $f, int $assignee, string $title='My task'): string {
        return task_service::create($f['company'],$f['admin'],['title'=>$title,'product'=>'Verified result',
            'assigneeid'=>$assignee,'reviewerid'=>0,'observerid'=>0,'priority'=>'normal','stream'=>'operate',
            'startdate'=>'2026-11-15','duedate'=>'2026-11-15','planminutes'=>30]);
    }

    public function test_own_deadlines_and_exact_submission_event_not_current_result(): void {
        global $DB;
        $f=$this->fixture(); $id=$this->task($f,(int)$f['employee']->id);
        $this->task($f,(int)$f['peer']->id,'Must not appear');
        $this->setUser($f['employee']);
        task_service::transition($f['company'],(int)$f['employee']->id,$id,1,'start');
        task_service::transition($f['company'],(int)$f['employee']->id,$id,2,'submit',['result'=>'Submitted snapshot','factminutes'=>45]);
        [$from,$until]=daily_report_service::bounds('2026-11-15');
        $log=$DB->get_record('local_kbacompany_tasklog',['taskuuid'=>$id,'action'=>'submit'],'*',MUST_EXIST);
        // Explicit event clock fixture; keep canonical submit payload from the service.
        $DB->set_field('local_kbacompany_tasklog','timecreated',$from+10,['id'=>$log->id]);
        $DB->set_field('local_kbacompany_task','result','Later mutable value',['uuid'=>$id]);
        $before=$DB->count_records('local_kbacompany_tasklog');
        $r=daily_report_service::context($f['company'],(int)$f['employee']->id,'2026-11-15');
        $this->assertSame(1,$r['tasktotal']); $this->assertSame(1,$r['resulttotal']);
        $this->assertSame('Submitted snapshot',$r['results'][0]->result);
        $this->assertSame(45,(int)$r['tasks'][0]->factminutes);
        $this->assertSame($before,$DB->count_records('local_kbacompany_tasklog'));
        $DB->set_field('local_kbacompany_tasklog','timecreated',$until,['id'=>$log->id]);
        $this->assertSame(0,daily_report_service::context($f['company'],(int)$f['employee']->id,'2026-11-15')['resulttotal']);
    }

    public function test_other_company_is_denied(): void {
        $f=$this->fixture();
        $other=repository::save_company((object)['name'=>'Synthetic B','active'=>1]);
        $this->setUser($f['employee']);
        $this->expectException(\moodle_exception::class);
        daily_report_service::context(identity_registry::company_uuid($other),(int)$f['employee']->id,'2026-11-15');
    }

    public function test_revoked_membership_is_denied(): void {
        $f=$this->fixture(); $this->task($f,(int)$f['employee']->id);
        membership_access_service::change($f['company'],(int)$f['employee']->id,$f['admin']);
        $this->setUser($f['employee']); $this->expectException(\moodle_exception::class);
        daily_report_service::context($f['company'],(int)$f['employee']->id,'2026-11-15');
    }

    public function test_civil_bounds_follow_user_timezone_and_dst(): void {
        $this->resetAfterTest();
        $u=$this->getDataGenerator()->create_user(['timezone'=>'Europe/Berlin']); $this->setUser($u);
        [$a,$b]=daily_report_service::bounds('2026-03-29'); $this->assertSame(23*3600,$b-$a);
        [$a,$b]=daily_report_service::bounds('2026-10-25'); $this->assertSame(25*3600,$b-$a);
    }

    public function test_impossible_date_is_rejected(): void {
        $this->resetAfterTest(); $this->setAdminUser();
        $this->expectException(\moodle_exception::class); daily_report_service::bounds('2026-02-30');
    }
}
