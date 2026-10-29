<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

final class management_attention_workspace_service {
    public const CONTRACT_VERSION = '0.8.10';

    private static function uname(int $userid): string {
        global $DB;
        $u = $DB->get_record('user', ['id'=>$userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        return $u ? fullname($u) : (string)$userid;
    }

    private static function company_name(string $uuid): string {
        global $DB;
        return (string)($DB->get_field('local_kbacompany_company', 'name', ['uuid'=>$uuid]) ?: $uuid);
    }

    public static function owner_context(string $companyuuid, int $actor, bool $curatormode = false): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $cycleconditions = ['companyuuid'=>$companyuuid, $curatormode ? 'curatorid' : 'ownerid'=>$actor];
        $cycles = $DB->get_records('local_kbacompany_mcycle', $cycleconditions, 'periodstart DESC, id DESC');
        $items = [];
        $summary = ['decisions'=>0,'plan_reviews'=>0,'checkpoints'=>0,'coordination'=>0,'a3'=>0];
        $now = time();
        foreach ($cycles as $cycle) {
            if (!in_array((string)$cycle->status, ['draft','active'], true)) { continue; }
            $cycleurl = new \moodle_url('/local/kbacompany/management_cycles.php', ['uuid'=>$companyuuid,'cycle'=>$cycle->uuid]);
            if ((string)$cycle->status === 'draft') {
                $items[] = ['kind'=>'cycle','priority'=>20,'title'=>(string)$cycle->title,
                    'detail'=>get_string('owner_ws_cycle_draft_detail','local_kbacompany'),
                    'next'=>get_string('owner_ws_next_activate','local_kbacompany'),'url'=>$cycleurl->out(false)];
            }
            foreach ($DB->get_records('local_kbacompany_mcyclecp', [
                'companyuuid'=>$companyuuid,'cycleuuid'=>$cycle->uuid,'status'=>'planned'
            ], 'plannedat ASC, sequence ASC') as $cp) {
                if ((int)$cp->plannedat <= $now + 7 * DAYSECS) {
                    $summary['checkpoints']++;
                    $items[] = ['kind'=>'checkpoint','priority'=>(int)$cp->plannedat < $now ? 1 : 5,
                        'title'=>(string)$cp->title,
                        'detail'=>(string)$cycle->title . ' · ' . userdate((int)$cp->plannedat),
                        'next'=>(string)$cp->meetinguuid === '' ? get_string('owner_ws_next_prepare_meeting','local_kbacompany') : get_string('owner_ws_next_open_meeting','local_kbacompany'),
                        'url'=>$cycleurl->out(false)];
                }
            }
            foreach ($DB->get_records('local_kbacompany_a3review', [
                'companyuuid'=>$companyuuid,'cycleuuid'=>$cycle->uuid
            ], 'timemodified DESC') as $a3) {
                if ((string)$a3->status === 'closed') { continue; }
                $summary['a3']++;
                $items[] = ['kind'=>'a3','priority'=>8,'title'=>(string)$a3->title,
                    'detail'=>(string)$cycle->title,'next'=>get_string('owner_ws_next_a3','local_kbacompany'),
                    'url'=>$cycleurl->out(false)];
            }
            $meetings = $DB->get_records_sql("SELECT m.* FROM {local_kbacompany_meeting} m
                JOIN {local_kbacompany_mcyclecp} cp ON cp.companyuuid=m.companyuuid AND cp.meetinguuid=m.uuid
                WHERE cp.companyuuid=:c AND cp.cycleuuid=:cy", ['c'=>$companyuuid,'cy'=>$cycle->uuid]);
            foreach ($meetings as $meeting) {
                if ($curatormode) { continue; }
                foreach ($DB->get_records('local_kbacompany_meetdecision', [
                    'companyuuid'=>$companyuuid,'meetinguuid'=>$meeting->uuid,'status'=>'proposed'
                ], 'timecreated ASC') as $decision) {
                    $summary['decisions']++;
                    $items[] = ['kind'=>'decision','priority'=>0,'title'=>(string)$decision->title,
                        'detail'=>(string)$cycle->title,'next'=>get_string('owner_ws_next_decide','local_kbacompany'),
                        'url'=>(new \moodle_url('/local/kbacompany/meeting.php',['uuid'=>$companyuuid,'meeting'=>$meeting->uuid]))->out(false)];
                }
            }
        }
        foreach ($DB->get_records('local_kbacompany_bplan', [
            'companyuuid'=>$companyuuid,'reviewerid'=>$actor,'status'=>'submitted'
        ], 'submittedat ASC') as $plan) {
            $summary['plan_reviews']++;
            $items[] = ['kind'=>'plan','priority'=>2,
                'title'=>get_string('owner_ws_plan_title','local_kbacompany', self::uname((int)$plan->userid)),
                'detail'=>(string)$plan->periodstart . ' — ' . (string)$plan->periodend,
                'next'=>get_string('owner_ws_next_review_plan','local_kbacompany'),
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$companyuuid,'plan'=>$plan->uuid]))->out(false)];
        }
        $sql = "SELECT bc.* FROM {local_kbacompany_bpcoord} bc
                  JOIN {local_kbacompany_bplan} p ON p.uuid=bc.targetplanuuid AND p.companyuuid=bc.companyuuid
                 WHERE bc.companyuuid=:c AND bc.status='open' AND (p.userid=:u OR p.reviewerid=:u2)";
        foreach ($DB->get_records_sql($sql, ['c'=>$companyuuid,'u'=>$actor,'u2'=>$actor]) as $coord) {
            $summary['coordination']++;
            $items[] = ['kind'=>'coordination','priority'=>3,'title'=>(string)$coord->summary,
                'detail'=>(string)$coord->requestedchange,'next'=>get_string('owner_ws_next_coordinate','local_kbacompany'),
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$companyuuid]))->out(false)];
        }
        usort($items, function($a,$b){ return $a['priority'] <=> $b['priority']; });
        return ['companyuuid'=>$companyuuid,'companyname'=>self::company_name($companyuuid),'summary'=>$summary,'items'=>$items];
    }

    public static function curator_portfolio(int $actor): array {
        global $DB;
        $sql = "SELECT c.uuid companyuuid, c.name companyname, mc.uuid cycleuuid, mc.title, mc.periodstart, mc.periodend, mc.status
                  FROM {local_kbacompany_mcycle} mc
                  JOIN {local_kbacompany_company} c ON c.uuid=mc.companyuuid AND c.active=1
                  JOIN {local_kbacompany_member} m ON m.companyuuid=mc.companyuuid AND m.userid=:u AND m.active=1
                 WHERE mc.curatorid=:u2 AND mc.status IN ('draft','active')
              ORDER BY mc.periodstart ASC, c.name ASC";
        $rows = [];
        $companies = [];
        foreach ($DB->get_records_sql($sql, ['u'=>$actor,'u2'=>$actor]) as $r) {
            if (!permission_service::can_manage_management((string)$r->companyuuid, $actor)) { continue; }
            $ctx = self::owner_context((string)$r->companyuuid, $actor, true);
            $companies[(string)$r->companyuuid] = (string)$r->companyname;
            $rows[] = [
                'companyuuid'=>(string)$r->companyuuid,'companyname'=>(string)$r->companyname,
                'cycleuuid'=>(string)$r->cycleuuid,'title'=>(string)$r->title,
                'period'=>(string)$r->periodstart . ' — ' . (string)$r->periodend,'status'=>(string)$r->status,
                'attention'=>count($ctx['items']),
                'url'=>(new \moodle_url('/local/kbacompany/curator_workspace.php',['uuid'=>$r->companyuuid]))->out(false),
            ];
        }
        return ['rows'=>$rows,'companies'=>$companies];
    }
}
