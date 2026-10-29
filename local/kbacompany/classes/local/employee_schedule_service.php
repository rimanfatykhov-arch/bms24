<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();
/** Company scoped weekly work capacity. Informational; it never refuses task assignment. */
final class employee_schedule_service {
    public static function hours(string $companyuuid, int $userid): int {
        global $DB;
        $row = $DB->get_record('local_kbacompany_wksched',
            ['companyuuid'=>$companyuuid, 'userid'=>$userid], 'weeklyhours');
        return $row ? (int)$row->weeklyhours : 40;
    }
    public static function capacities(string $companyuuid, array $userids): array {
        global $DB;
        $out=[];
        foreach ($userids as $id) { if ((int)$id > 0) { $out[(int)$id]=2400; } }
        if (!$out) { return $out; }
        list($where,$params)=$DB->get_in_or_equal(array_keys($out), SQL_PARAMS_NAMED, 'scuser');
        $params['sccompany']=$companyuuid;
        $rows=$DB->get_records_sql("SELECT userid, weeklyhours FROM {local_kbacompany_wksched} WHERE companyuuid = :sccompany AND userid $where", $params);
        foreach ($rows as $row) { $out[(int)$row->userid]=(int)$row->weeklyhours*60; }
        return $out;
    }
    public static function save(string $companyuuid, int $actor, int $userid, int $hours): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) { throw new \moodle_exception('nopermissions', 'error'); }
        if (!in_array($hours, [20,36,40], true) || $userid <= 0) { throw new \moodle_exception('invalidparameter'); }
        // Validate linked company membership; an identical display name is not enough.
        company_context_service::context($companyuuid, $userid);
        $key=['companyuuid'=>$companyuuid, 'userid'=>$userid];
        $row=$DB->get_record('local_kbacompany_wksched',$key);
        if ($row) {
            $row->weeklyhours=$hours; $row->modifiedby=$actor; $row->timemodified=time();
            $DB->update_record('local_kbacompany_wksched',$row);
        } else {
            $DB->insert_record('local_kbacompany_wksched',(object)($key+[
                'weeklyhours'=>$hours,'modifiedby'=>$actor,'timemodified'=>time()]));
        }
    }
}
