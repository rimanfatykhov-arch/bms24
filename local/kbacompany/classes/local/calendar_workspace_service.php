<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * BMS workspace over Moodle Calendar with controlled CRUD for manual events.
 *
 * Moodle Core remains the source of truth. The default "my" scope mirrors the
 * signed-in user's Moodle-visible calendar surface. The optional "company"
 * scope remains restricted to courses explicitly bound to the current Company.
 * No calendar event is copied into local_kbacompany storage; mutations use Moodle Core Calendar API.
 */
class calendar_workspace_service {
    private const EVENT_PAGE_SIZE = 500;
    private const EVENT_HARD_LIMIT = 5000;

    public const CONTRACT_VERSION = '1.1.0-beta2';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'federated_calendar_projection',
            'projection_owner' => 'local_kbacompany',
            'rules' => [
                'moodle_core_calendar_is_source_of_truth',
                'manual_event_crud_via_calendar_event_api',
                'module_generated_events_are_read_only',
                'my_scope_uses_only_moodle_visibility_for_signed_in_user',
                'company_scope_uses_explicit_company_course_bindings',
                'company_uuid_remains_security_boundary_for_company_scope',
                'no_global_course_scan',
                'no_calendar_event_copy',
                'moodle_visibility_rules_remain_authoritative',
                'event_detail_is_read_only_projection',
                'filters_do_not_change_source_data',
                'bms_domains_keep_their_canonical_storage',
                'task_meeting_battle_plan_checkpoint_projections_are_read_only',
                'source_permissions_are_applied_before_fetch',
                'month_week_day_views_are_projection_only',
                'drag_drop_moves_only_authorised_manual_moodle_events',
                'day_planner_is_read_only_projection_over_authorised_events',
                'conflict_analysis_does_not_change_source_data',
                'team_load_uses_only_already_authorised_bms_projections',
                'other_users_personal_moodle_events_are_never_loaded',
                'employee_filter_does_not_widen_record_visibility',
                'meeting_finder_uses_only_permission_scoped_busy_intervals',
                'meeting_finder_never_reads_other_users_personal_moodle_events',
                'meeting_finder_does_not_create_meetings_or_reserve_time',
                'today_snapshot_is_read_only_and_uses_existing_permission_scoped_projections',
                'reminder_signals_do_not_send_messages_or_create_persistent_reminder_state',
                'planning_meeting_workflow_is_read_only_projection_over_canonical_meeting_agenda_decision_task',
                'calendar_never_creates_decisions_or_tasks_implicitly',
                'calendar_analytics_is_read_only_and_not_persisted',
                'calendar_analytics_uses_only_already_authorised_projections',
                'calendar_analytics_thresholds_are_explicit_presentation_rules',
                'calendar_core_event_fetch_is_bounded_and_paginated',
                'calendar_cron_uses_streaming_recipient_scan_and_overlap_lock',
                'local_datetime_inputs_reject_dst_normalisation',
            ],
        ];
    }

    /** Build one calendar month. */
    public static function month(string $companyuuid, int $userid, string $month = '', string $scope = 'my', string $q = '', string $kind = 'all', string $sourcefilter = 'all', int $memberid = 0): array {
        global $CFG;

        $company = company_context_service::context($companyuuid, $userid);
        require_once($CFG->dirroot . '/calendar/lib.php');

        $scope = ($scope === 'company') ? 'company' : 'my';
        $q = trim($q);
        $kind = in_array($kind, ['all', 'manual', 'generated'], true) ? $kind : 'all';
        $sourcefilter = in_array($sourcefilter, ['all','moodle','google','task','meeting','battleplan','checkpoint','cycle'], true) ? $sourcefilter : 'all';
        if (!in_array($sourcefilter, ['all','moodle'], true)) { $kind = 'all'; }
        [$year, $monthnum] = self::normalise_month($month);
        $tz = \core_date::get_user_timezone_object();
        $startdt = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $monthnum), $tz);
        $enddt = $startdt->modify('+1 month')->modify('-1 second');
        $start = $startdt->getTimestamp();
        $end = $enddt->getTimestamp();

        if ($scope === 'company') {
            $courses = self::company_courses($companyuuid, $userid);
            $courseids = array_keys($courses);
            $groupids = self::visible_group_ids($courseids, $userid);
            $users = false;
            $calendarcourseids = $courseids;
        } else {
            $courses = self::my_courses($userid);
            $courseids = array_keys($courses);
            $groupids = self::visible_group_ids($courseids, $userid);
            $users = [$userid];
            // SITEID allows the normal Moodle site calendar surface while the
            // remaining IDs are restricted to courses already visible to this user.
            $calendarcourseids = array_values(array_unique(array_merge([SITEID], $courseids)));
        }

        $events = [];
        if ($calendarcourseids || $scope === 'my') {
            $raw = self::legacy_events_bounded($start, $end, $users, $groupids, $calendarcourseids);
            foreach ($raw as $event) {
                $courseid = (int)($event->courseid ?? 0);
                if ($scope === 'company' && !isset($courses[$courseid])) {
                    continue;
                }
                if ($scope === 'my' && $courseid > SITEID && !isset($courses[$courseid])) {
                    continue;
                }
                $eventstart = (int)($event->timestart ?? 0);
                if ($eventstart <= 0) {
                    continue;
                }
                $source = self::event_source_label($event, $courses, $scope);
                $daykey = userdate($eventstart, '%Y-%m-%d');
                $generated = self::is_generated_event($event);
                $name = format_string((string)($event->name ?? ''));
                if ($kind === 'manual' && $generated) {
                    continue;
                }
                if ($kind === 'generated' && !$generated) {
                    continue;
                }
                if ($q !== '') {
                    $haystack = \core_text::strtolower($name . ' ' . $source);
                    if (\core_text::strpos($haystack, \core_text::strtolower($q)) === false) {
                        continue;
                    }
                }
                if ($sourcefilter !== 'all' && $sourcefilter !== 'moodle') { continue; }
                $events[] = [
                    'id' => (int)($event->id ?? 0),
                    'name' => $name,
                    'course_id' => $courseid,
                    'course_name' => $source,
                    'group_id' => (int)($event->groupid ?? 0),
                    'module' => clean_param((string)($event->modulename ?? ''), PARAM_ALPHANUMEXT),
                    'event_type' => clean_param((string)($event->eventtype ?? ''), PARAM_ALPHANUMEXT),
                    'time_start' => $eventstart,
                    'duration' => max(0, (int)($event->timeduration ?? 0)),
                    'day_key' => $daykey,
                    'time_label' => userdate($eventstart, get_string('strftimetime')),
                    'date_label' => userdate($eventstart, get_string('strftimedaydate')),
                    'generated' => $generated,
                    'source_kind' => 'moodle',
                    'source_label' => calendar_workspace_ui_service::text('bmscalendar_source_moodle'),
                    'all_day' => false,
                    'kind_label' => $generated
                        ? calendar_workspace_ui_service::text('bmscalendar_kind_generated')
                        : calendar_workspace_ui_service::text('bmscalendar_kind_manual'),
                    'url' => (new \moodle_url('/local/kbacompany/calendar.php', [
                        'uuid' => $companyuuid,
                        'scope' => $scope,
                        'month' => $startdt->format('Y-m'),
                        'q' => $q,
                        'kind' => $kind,
                        'source' => $sourcefilter,
                        'action' => 'view',
                        'eventid' => (int)($event->id ?? 0),
                    ]))->out(false),
                    'moodle_url' => (new \moodle_url('/calendar/view.php', [
                        'view' => 'day', 'time' => $eventstart,
                    ]))->out(false),
                    'can_manage' => self::can_manage_event_record($event, $companyuuid, $userid, $scope),
                    'owner_user_ids' => [$userid],
                ];
            }
        }


        // Google Calendar is an optional external provider projection for the signed-in user only.
        // It is read-only inside BMS; BMS-exported meetings are suppressed to avoid duplicates.
        if ($sourcefilter === 'all' || $sourcefilter === 'google') {
            try {
                foreach (google_calendar_provider_service::calendar_projection($companyuuid, $userid, $start, $end) as $googleevent) {
                    if ($kind !== 'all') { continue; }
                    if ($q !== '') {
                        $haystack = \core_text::strtolower((string)$googleevent['name'].' '.(string)$googleevent['source_label']);
                        if (\core_text::strpos($haystack, \core_text::strtolower($q)) === false) { continue; }
                    }
                    $events[] = $googleevent;
                }
            } catch (\Throwable $e) {
                // External provider outage/auth expiry must not break the BMS calendar.
            }
        }

        // Canonical BMS domains contribute read-only calendar projections. Each
        // service applies its own Company/participant/record permissions before fetch.
        $fromdate = $startdt->format('Y-m-d');
        $todate = $enddt->format('Y-m-d');
        $add = static function(array &$events, array $event) use ($q, $kind, $sourcefilter): void {
            if ($kind !== 'all') { return; } // manual/generated applies to Moodle events only.
            if ($sourcefilter !== 'all' && $sourcefilter !== $event['source_kind']) { return; }
            if ($q !== '') {
                $haystack = \core_text::strtolower($event['name'].' '.$event['course_name'].' '.($event['kind_label'] ?? ''));
                if (\core_text::strpos($haystack, \core_text::strtolower($q)) === false) { return; }
            }
            $events[] = $event;
        };
        $allDayTs = static function(string $date) use ($tz): int {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
            return $dt ? $dt->setTime(9,0)->getTimestamp() : 0;
        };

        foreach (task_service::calendar_projection($companyuuid,$userid,$fromdate,$todate) as $task) {
            $ts=$allDayTs((string)$task['date']); if($ts<=0){continue;}
            $prefix=$task['date_kind']==='due' ? calendar_workspace_ui_service::text('bmscalendar_task_due') : calendar_workspace_ui_service::text('bmscalendar_task_start');
            $add($events,[
                'id'=>0,'name'=>$prefix.': '.(string)$task['title'],'course_id'=>0,
                'course_name'=>calendar_workspace_ui_service::text('bmscalendar_source_task'),'group_id'=>0,'module'=>'','event_type'=>'task',
                'time_start'=>$ts,'duration'=>0,'day_key'=>userdate($ts,'%Y-%m-%d'),'time_label'=>calendar_workspace_ui_service::text('bmscalendar_all_day'),
                'date_label'=>userdate($ts,get_string('strftimedaydate')),'generated'=>false,'source_kind'=>'task','source_label'=>calendar_workspace_ui_service::text('bmscalendar_source_task'),
                'all_day'=>true,'kind_label'=>calendar_workspace_ui_service::text('bmscalendar_kind_task'),'url'=>(new \moodle_url('/local/kbacompany/tasks.php',['uuid'=>$companyuuid,'task'=>$task['uuid'],'view'=>'detail']))->out(false),
                'moodle_url'=>'','can_manage'=>false,'status'=>(string)$task['status'],
                'date_kind'=>(string)$task['date_kind'],
                'owner_user_ids'=>[(int)$task['assignee_id']],
            ]);
        }

        foreach (meeting_service::calendar_projection($companyuuid,$userid,$start,$end) as $meeting) {
            $ts=(int)$meeting['starts_at']; if($ts<=0){continue;}
            $duration=max(0,(int)$meeting['ends_at']-$ts);
            $add($events,[
                'id'=>0,'name'=>(string)$meeting['title'],'course_id'=>0,'course_name'=>calendar_workspace_ui_service::text('bmscalendar_source_meeting'),
                'group_id'=>0,'module'=>'','event_type'=>'meeting','time_start'=>$ts,'duration'=>$duration,'day_key'=>userdate($ts,'%Y-%m-%d'),
                'time_label'=>userdate($ts,get_string('strftimetime')),'date_label'=>userdate($ts,get_string('strftimedaydate')),'generated'=>false,
                'source_kind'=>'meeting','source_label'=>calendar_workspace_ui_service::text('bmscalendar_source_meeting'),'all_day'=>false,
                'kind_label'=>calendar_workspace_ui_service::text('bmscalendar_kind_meeting'),'url'=>(new \moodle_url('/local/kbacompany/meetings.php',['uuid'=>$companyuuid,'meeting'=>$meeting['uuid']]))->out(false),
                'moodle_url'=>'','can_manage'=>false,'status'=>(string)$meeting['status'],
                'owner_user_ids'=>array_values(array_map('intval',(array)($meeting['participant_ids'] ?? []))),
            ]);
        }

        foreach (battle_plan_service::calendar_projection($companyuuid,$userid,$fromdate,$todate) as $plan) {
            $ts=$allDayTs((string)$plan['period_start']); if($ts<=0){continue;}
            $label=trim((string)$plan['position'])!=='' ? (string)$plan['position'] : calendar_workspace_ui_service::text('bmscalendar_source_battleplan');
            $name=calendar_workspace_ui_service::text('bmscalendar_battleplan_prefix').': '.$label;
            if(trim((string)$plan['employee'])!==''){$name.=' · '.(string)$plan['employee'];}
            $add($events,[
                'id'=>0,'name'=>$name,'course_id'=>0,'course_name'=>calendar_workspace_ui_service::text('bmscalendar_source_battleplan'),'group_id'=>0,
                'module'=>'','event_type'=>'battleplan','time_start'=>$ts,'duration'=>0,'day_key'=>userdate($ts,'%Y-%m-%d'),'time_label'=>calendar_workspace_ui_service::text('bmscalendar_all_day'),
                'date_label'=>userdate($ts,get_string('strftimedaydate')),'generated'=>false,'source_kind'=>'battleplan','source_label'=>calendar_workspace_ui_service::text('bmscalendar_source_battleplan'),
                'all_day'=>true,'kind_label'=>calendar_workspace_ui_service::text('bmscalendar_kind_battleplan'),'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$companyuuid,'plan'=>$plan['uuid'],'view'=>'detail']))->out(false),
                'moodle_url'=>'','can_manage'=>false,'status'=>(string)$plan['status'],
                'owner_user_ids'=>[(int)$plan['user_id']],
            ]);
        }

        try {
            foreach (management_cycle_template_service::calendar_projection($companyuuid,$userid,$start,$end) as $checkpoint) {
                $ts=(int)$checkpoint['planned_at']; if($ts<=0){continue;}
                $add($events,[
                    'id'=>0,'name'=>(string)$checkpoint['title'],'course_id'=>0,'course_name'=>calendar_workspace_ui_service::text('bmscalendar_source_checkpoint'),'group_id'=>0,
                    'module'=>'','event_type'=>'checkpoint','time_start'=>$ts,'duration'=>0,'day_key'=>userdate($ts,'%Y-%m-%d'),'time_label'=>userdate($ts,get_string('strftimetime')),
                    'date_label'=>userdate($ts,get_string('strftimedaydate')),'generated'=>false,'source_kind'=>'checkpoint','source_label'=>calendar_workspace_ui_service::text('bmscalendar_source_checkpoint'),
                    'all_day'=>false,'kind_label'=>calendar_workspace_ui_service::text('bmscalendar_kind_checkpoint'),'url'=>(new \moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$companyuuid,'cycle'=>$checkpoint['cycle_uuid'],'tab'=>'checkpoints']))->out(false),
                    'moodle_url'=>'','can_manage'=>false,'status'=>(string)$checkpoint['status'],
                    'owner_user_ids'=>[],
                ]);
            }
        } catch (\required_capability_exception $e) {
            // Management cycles are manager-only; absence of permission is a valid empty projection.
        }

        $cycleperiods = [];
        try {
            $cycleperiods = management_cycle_template_service::cycle_calendar_projection(
                $companyuuid, $userid, $fromdate, $todate
            );
            foreach ($cycleperiods as $cycle) {
                $visibledate = max((string)$cycle['period_start'], $fromdate);
                $ts = $allDayTs($visibledate);
                if ($ts <= 0) { continue; }
                $templatekey = 'mcycle_template_' . (string)$cycle['template_code'];
                $templatelabel = calendar_workspace_ui_service::text($templatekey);
                $name = $templatelabel . ': ' . (string)$cycle['title'];
                $add($events,[
                    'id'=>0,'name'=>$name,'course_id'=>0,'course_name'=>calendar_workspace_ui_service::text('bmscalendar_source_cycle'),'group_id'=>0,
                    'module'=>'','event_type'=>'cycle','time_start'=>$ts,'duration'=>0,'day_key'=>userdate($ts,'%Y-%m-%d'),
                    'time_label'=>calendar_workspace_ui_service::text('bmscalendar_all_day'),'date_label'=>userdate($ts,get_string('strftimedaydate')),
                    'generated'=>false,'source_kind'=>'cycle','source_label'=>calendar_workspace_ui_service::text('bmscalendar_source_cycle'),
                    'all_day'=>true,'kind_label'=>$templatelabel,'url'=>(new \moodle_url('/local/kbacompany/management_cycles.php',[
                        'uuid'=>$companyuuid,'cycle'=>$cycle['uuid'],'tab'=>'overview'
                    ]))->out(false),'moodle_url'=>'','can_manage'=>false,'status'=>(string)$cycle['status'],
                    'owner_user_ids'=>array_values(array_unique(array_filter([(int)$cycle['owner_id'],(int)$cycle['curator_id']]))),
                    'period_start'=>(string)$cycle['period_start'],'period_end'=>(string)$cycle['period_end'],
                    'cycle_template'=>(string)$cycle['template_code'],'cycle_uuid'=>(string)$cycle['uuid'],
                ]);
            }
        } catch (\required_capability_exception $e) {
            $cycleperiods = [];
        }

        $canviewteam = permission_service::can_manage_organization($companyuuid, $userid);
        $teamload = self::team_load_model($companyuuid, $userid, $events, $canviewteam);
        if ($memberid > 0) {
            $allowedmember = ($memberid === $userid);
            if ($canviewteam) {
                foreach ($teamload['members'] as $member) {
                    if ((int)$member['user_id'] === $memberid) { $allowedmember = true; break; }
                }
            }
            if (!$allowedmember) { throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:viewowncompany', 'nopermissions', ''); }
            $events = array_values(array_filter($events, static function(array $event) use ($memberid, $userid): bool {
                $owners = array_values(array_map('intval', (array)($event['owner_user_ids'] ?? [])));
                if (($event['source_kind'] ?? '') === 'moodle') { return $memberid === $userid; }
                return in_array($memberid, $owners, true);
            }));
        }

        usort($events, static function(array $a, array $b): int {
            if ($a['time_start'] === $b['time_start']) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $a['time_start'] <=> $b['time_start'];
        });

        $byday = [];
        foreach ($events as $event) {
            $byday[$event['day_key']][] = $event;
        }

        $days = [];
        $cursor = $startdt;
        $today = userdate(time(), '%Y-%m-%d');
        while ($cursor <= $enddt) {
            $key = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => (int)$cursor->format('j'),
                'weekday' => (int)$cursor->format('N'),
                'today' => $key === $today,
                'events' => $byday[$key] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $prev = $startdt->modify('-1 month')->format('Y-m');
        $next = $startdt->modify('+1 month')->format('Y-m');
        $now = time();
        $upcoming = array_values(array_filter($events, static function(array $event) use ($now): bool {
            return $event['time_start'] >= $now;
        }));
        $upcoming = array_slice($upcoming, 0, 10);

        $sourcecounts = ['moodle'=>0,'task'=>0,'meeting'=>0,'battleplan'=>0,'checkpoint'=>0,'cycle'=>0];
        foreach ($events as $event) { $sk=(string)($event['source_kind'] ?? 'moodle'); if(isset($sourcecounts[$sk])){$sourcecounts[$sk]++;} }

        $base = ['uuid' => $companyuuid, 'scope' => $scope, 'q' => $q, 'kind' => $kind, 'source' => $sourcefilter, 'member' => $memberid];
        return [
            'company' => $company,
            'scope' => $scope,
            'month' => $startdt->format('Y-m'),
            'query' => $q,
            'kind' => $kind,
            'source_filter' => $sourcefilter,
            'member_id' => $memberid,
            'team_load' => $teamload,
            'cycle_periods' => $cycleperiods,
            'year' => $year,
            'month_number' => $monthnum,
            'month_label' => userdate($start, '%B %Y'),
            'start_weekday' => (int)$startdt->format('N'),
            'days' => $days,
            'events' => $events,
            'upcoming' => $upcoming,
            'courses' => array_values($courses),
            'summary' => [
                'events' => count($events),
                'courses' => count($courses),
                'upcoming' => count($upcoming),
                'sources' => $sourcecounts,
            ],
            'links' => [
                'prev' => (new \moodle_url('/local/kbacompany/calendar.php', $base + ['month' => $prev]))->out(false),
                'next' => (new \moodle_url('/local/kbacompany/calendar.php', $base + ['month' => $next]))->out(false),
                'today' => (new \moodle_url('/local/kbacompany/calendar.php', $base))->out(false),
                'my' => (new \moodle_url('/local/kbacompany/calendar.php', [
                    'uuid' => $companyuuid, 'scope' => 'my', 'month' => $startdt->format('Y-m'), 'q' => $q, 'kind' => $kind, 'source' => $sourcefilter, 'member' => $memberid,
                ]))->out(false),
                'company' => (new \moodle_url('/local/kbacompany/calendar.php', [
                    'uuid' => $companyuuid, 'scope' => 'company', 'month' => $startdt->format('Y-m'), 'q' => $q, 'kind' => $kind, 'source' => $sourcefilter, 'member' => $memberid,
                ]))->out(false),
                'moodle' => (new \moodle_url('/calendar/view.php', [
                    'view' => 'month', 'time' => $start,
                ]))->out(false),
                'create' => (new \moodle_url('/local/kbacompany/calendar.php', $base + [
                    'month' => $startdt->format('Y-m'), 'action' => 'new',
                    'source' => $sourcefilter === 'all' ? 'all' : 'moodle',
                ]))->out(false),
            ],
            'create_targets' => self::create_targets($companyuuid, $userid, $scope),
            'contract' => self::contract(),
        ];
    }


    /**
     * Read-only regular-management panel for Calendar.
     *
     * Reuses canonical management-cycle and Battle Plan services. No lifecycle
     * transition is executed from Calendar; actions are links into the owning
     * workspaces where the existing services revalidate permissions/state.
     */
    private static function management_rhythm_model(string $companyuuid, int $userid, \DateTimeImmutable $anchor): array {
        $empty = [
            'available'=>false, 'week_start'=>'', 'week_end'=>'', 'state'=>'',
            'hard_count'=>0, 'attention_count'=>0, 'missing_plans'=>0,
            'blocked_plans'=>0, 'overdue_tasks'=>0, 'needs_decision'=>0,
            'cycles'=>[], 'actions'=>[],
        ];
        if (!task_service::can_manage($companyuuid, $userid)) { return $empty; }
        try {
            $week = battle_plan_service::week_start($anchor->format('Y-m-d'));
            $acceptance = weekly_management_acceptance_service::context($companyuuid, $userid, $week, '');
            $cycles = management_cycle_template_service::cycle_calendar_projection(
                $companyuuid, $userid, $anchor->format('Y-m-d'), $anchor->format('Y-m-d')
            );
            $cycleitems = [];
            foreach ($cycles as $cycle) {
                $actions = ['start'=>false,'close'=>false];
                try {
                    $record = management_cycle_template_service::record($companyuuid, $userid, (string)$cycle['uuid']);
                    $allowed = management_cycle_template_service::actions($companyuuid, $userid, $record);
                    $actions['start'] = !empty($allowed['start']);
                    $actions['close'] = !empty($allowed['close']);
                } catch (\Throwable $e) {
                    // Calendar remains a read model if one cycle cannot expose actions.
                }
                $cycleitems[] = $cycle + ['actions'=>$actions];
            }
            $overview = (array)($acceptance['overview'] ?? []);
            $coverage = (array)($acceptance['coverage'] ?? []);
            $readiness = (array)($acceptance['readiness'] ?? []);
            return [
                'available'=>true,
                'week_start'=>(string)$acceptance['week_start'], 'week_end'=>(string)$acceptance['week_end'],
                'state'=>(string)$acceptance['state'], 'hard_count'=>(int)$acceptance['hard_count'],
                'attention_count'=>(int)$acceptance['attention_count'],
                'missing_plans'=>(int)($coverage['missing_plans'] ?? 0),
                'blocked_plans'=>(int)($readiness['blocked'] ?? 0),
                'overdue_tasks'=>(int)($overview['overdue_tasks'] ?? 0),
                'needs_decision'=>(int)($overview['needs_decision'] ?? 0),
                'cycles'=>$cycleitems,
                'actions'=>[
                    'launch'=>(new \moodle_url('/local/kbacompany/battle_plan_coverage.php',['uuid'=>$companyuuid,'week'=>$week]))->out(false),
                    'readiness'=>(new \moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$companyuuid,'week'=>$week]))->out(false),
                    'control'=>(new \moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$companyuuid,'week'=>$week]))->out(false),
                    'acceptance'=>(new \moodle_url('/local/kbacompany/battle_plan_acceptance.php',['uuid'=>$companyuuid,'week'=>$week]))->out(false),
                    'meetings'=>(new \moodle_url('/local/kbacompany/meetings.php',['uuid'=>$companyuuid]))->out(false),
                ],
            ];
        } catch (
equired_capability_exception $e) {
            return $empty;
        }
    }

    /** Read-only nearest planning-meeting workflow for Calendar. */
    private static function meeting_workflow_model(string $companyuuid, int $userid, \DateTimeImmutable $anchor): array {
        $start = $anchor->setTime(0, 0, 0);
        $end = $start->modify('+7 days')->setTime(23, 59, 59);
        $meetings = meeting_service::workflow_projection($companyuuid, $userid, $start->getTimestamp(), $end->getTimestamp(), 6);
        $tz = \core_date::get_user_timezone_object();
        $defaultstart = $start->setTime(10, 0);
        $defaultend = $defaultstart->modify('+1 hour');
        return [
            'meetings'=>$meetings,
            'next'=>$meetings ? $meetings[0] : null,
            'window_start'=>$start->format('Y-m-d'),
            'window_end'=>$end->format('Y-m-d'),
            'create_url'=>(new \moodle_url('/local/kbacompany/meetings.php',[
                'uuid'=>$companyuuid,
                'prefillstart'=>$defaultstart->format('Y-m-d\TH:i'),
                'prefillend'=>$defaultend->format('Y-m-d\TH:i'),
            ]))->out(false),
        ];
    }

    /**
     * Build month/week/day presentation without creating a second event store.
     * Week/day reuse month projections for every calendar month touched by the window,
     * then reduce the already-authorised projections to the requested date interval.
     */
    public static function view_model(string $companyuuid, int $userid, string $month = '', string $scope = 'my',
            string $q = '', string $kind = 'all', string $sourcefilter = 'all', string $view = 'month', string $date = '', int $memberid = 0): array {
        $view = in_array($view, ['month', 'week', 'day', 'analytics'], true) ? $view : 'month';
        $base = self::month($companyuuid, $userid, $month, $scope, $q, $kind, $sourcefilter, $memberid);
        $tz = \core_date::get_user_timezone_object();
        $anchor = self::normalise_date($date, (string)$base['month'], $tz);
        $base['management_rhythm'] = self::management_rhythm_model($companyuuid, $userid, $anchor);
        $base['meeting_workflow'] = self::meeting_workflow_model($companyuuid, $userid, $anchor);

        if ($view === 'analytics') {
            $periodstart = new \DateTimeImmutable((string)$base['month'] . '-01 00:00:00', $tz);
            $periodend = $periodstart->modify('+1 month')->modify('-1 second');
            $base['view'] = 'analytics';
            $base['anchor_date'] = $anchor->format('Y-m-d');
            $base['display_days'] = $base['days'];
            $base['display_start_weekday'] = (int)$base['start_weekday'];
            $base['view_label'] = calendar_workspace_ui_service::text('bmscalendar_view_analytics') . ' · ' . $base['month_label'];
            $base['analytics'] = self::analytics_model($base['events'], (array)$base['team_load'], (array)$base['management_rhythm'], $periodstart, $periodend, $userid);
            $base['links'] = self::view_links($companyuuid, $base, $view, $anchor);
            return $base;
        }

        if ($view === 'month') {
            $base['view'] = 'month';
            $base['anchor_date'] = $anchor->format('Y-m-d');
            $base['display_days'] = $base['days'];
            $base['display_start_weekday'] = (int)$base['start_weekday'];
            $base['view_label'] = $base['month_label'];
            $base['links'] = self::view_links($companyuuid, $base, $view, $anchor);
            return $base;
        }

        if ($view === 'week') {
            $startdt = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days')->setTime(0, 0, 0);
            $enddt = $startdt->modify('+6 days')->setTime(23, 59, 59);
        } else {
            $startdt = $anchor->setTime(0, 0, 0);
            $enddt = $anchor->setTime(23, 59, 59);
        }

        $models = [];
        $cursor = $startdt->modify('first day of this month');
        $lastmonth = $enddt->modify('first day of this month');
        while ($cursor <= $lastmonth) {
            $ym = $cursor->format('Y-m');
            $models[$ym] = ($ym === $base['month']) ? $base
                : self::month($companyuuid, $userid, $ym, $scope, $q, $kind, $sourcefilter, $memberid);
            $cursor = $cursor->modify('+1 month');
        }

        $events = [];
        $seen = [];
        foreach ($models as $model) {
            foreach ($model['events'] as $event) {
                $ts = (int)$event['time_start'];
                if ($ts < $startdt->getTimestamp() || $ts > $enddt->getTimestamp()) {
                    continue;
                }
                $identity = self::projection_identity($event);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                if (($event['source_kind'] ?? '') === 'moodle' && (int)($event['id'] ?? 0) > 0) {
                    $event['url'] = (new \moodle_url('/local/kbacompany/calendar.php', [
                        'uuid'=>$companyuuid, 'scope'=>$scope, 'month'=>$anchor->format('Y-m'),
                        'date'=>$anchor->format('Y-m-d'), 'view'=>$view, 'q'=>$q, 'kind'=>$kind,
                        'source'=>$sourcefilter, 'member'=>$memberid, 'action'=>'view', 'eventid'=>(int)$event['id'],
                    ]))->out(false);
                }
                $events[] = $event;
            }
        }
        usort($events, static function(array $a, array $b): int {
            if ((int)$a['time_start'] === (int)$b['time_start']) {
                return strcasecmp((string)$a['name'], (string)$b['name']);
            }
            return (int)$a['time_start'] <=> (int)$b['time_start'];
        });

        $byday = [];
        foreach ($events as $event) {
            $byday[(string)$event['day_key']][] = $event;
        }
        $today = userdate(time(), '%Y-%m-%d');
        $displaydays = [];
        $cursor = $startdt;
        while ($cursor <= $enddt) {
            $key = $cursor->format('Y-m-d');
            $displaydays[] = [
                'date' => $key,
                'day' => (int)$cursor->format('j'),
                'weekday' => (int)$cursor->format('N'),
                'today' => $key === $today,
                'events' => $byday[$key] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $now = time();
        $upcoming = array_values(array_filter($events, static function(array $event) use ($now): bool {
            return (int)$event['time_start'] >= $now;
        }));
        $upcoming = array_slice($upcoming, 0, 10);
        $sourcecounts = ['moodle'=>0,'task'=>0,'meeting'=>0,'battleplan'=>0,'checkpoint'=>0,'cycle'=>0];
        foreach ($events as $event) {
            $sk = (string)($event['source_kind'] ?? 'moodle');
            if (isset($sourcecounts[$sk])) { $sourcecounts[$sk]++; }
        }

        $base['view'] = $view;
        $base['anchor_date'] = $anchor->format('Y-m-d');
        $base['month'] = $anchor->format('Y-m');
        $base['events'] = $events;
        $base['display_days'] = $displaydays;
        $base['display_start_weekday'] = 1;
        $base['days'] = $displaydays;
        $base['start_weekday'] = 1;
        $base['upcoming'] = $upcoming;
        $base['summary']['events'] = count($events);
        $base['summary']['upcoming'] = count($upcoming);
        $base['summary']['sources'] = $sourcecounts;
        if ($view === 'week') {
            $base['view_label'] = userdate($startdt->getTimestamp(), get_string('strftimedaydate')) . ' — ' .
                userdate($enddt->getTimestamp(), get_string('strftimedaydate'));
        } else {
            $base['view_label'] = userdate($startdt->getTimestamp(), get_string('strftimedaydate'));
        }
        $base['links'] = self::view_links($companyuuid, $base, $view, $anchor);
        if ($view === 'day') {
            $base['day_planner'] = self::day_planner_model($events, $anchor, $tz);
        }
        return $base;
    }



    /**
     * Read-only personal Today snapshot for the BMS workspace.
     *
     * Uses the same already-authorised calendar projections as Calendar. It never
     * creates reminder rows and does not send Moodle messages, so repeated page
     * loads are side-effect free.
     */
    public static function today_snapshot(string $companyuuid, int $userid): array {
        $tz = \core_date::get_user_timezone_object();
        $now = time();
        $todaydt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);
        $today = $todaydt->format('Y-m-d');
        $tomorrowdt = $todaydt->modify('+1 day');
        $tomorrow = $tomorrowdt->format('Y-m-d');

        $model = self::view_model($companyuuid, $userid, $todaydt->format('Y-m'), 'my', '', 'all', 'all', 'day', $today, 0);
        $tomorrowmodel = self::view_model($companyuuid, $userid, $tomorrowdt->format('Y-m'), 'my', '', 'all', 'checkpoint', 'day', $tomorrow, 0);

        $events = array_values((array)($model['events'] ?? []));
        $next = [];
        $meetings = 0;
        $tasksdue = 0;
        $checkpoints = 0;
        $attention = [];
        foreach ($events as $event) {
            $source = (string)($event['source_kind'] ?? '');
            $ts = (int)($event['time_start'] ?? 0);
            if ($source === 'meeting') { $meetings++; }
            if ($source === 'task' && (string)($event['event_type'] ?? '') === 'task') {
                $tasksdue++;
                $attention[] = $event;
            }
            if ($source === 'checkpoint') {
                $checkpoints++;
                $attention[] = $event;
            }
            if ($ts >= $now) { $next[] = $event; }
        }
        usort($next, static function(array $a, array $b): int {
            return (int)($a['time_start'] ?? 0) <=> (int)($b['time_start'] ?? 0);
        });
        $next = array_slice($next, 0, 5);

        $nextwithin60 = [];
        foreach ($next as $event) {
            $ts = (int)($event['time_start'] ?? 0);
            if ($ts >= $now && $ts <= $now + 3600) { $nextwithin60[] = $event; }
        }

        $tomorrowcheckpoints = array_values((array)($tomorrowmodel['events'] ?? []));
        $calendarurl = (new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid' => $companyuuid, 'view' => 'day', 'date' => $today, 'scope' => 'my',
        ]))->out(false);

        return [
            'date' => $today,
            'date_label' => userdate($todaydt->getTimestamp(), get_string('strftimedaydate')),
            'events' => $events,
            'next' => $next,
            'next_within_60' => $nextwithin60,
            'attention' => array_slice($attention, 0, 5),
            'tomorrow_checkpoints' => array_slice($tomorrowcheckpoints, 0, 5),
            'summary' => [
                'events' => count($events),
                'meetings' => $meetings,
                'tasks_due' => $tasksdue,
                'checkpoints' => $checkpoints,
                'next_hour' => count($nextwithin60),
                'tomorrow_checkpoints' => count($tomorrowcheckpoints),
            ],
            'calendar_url' => $calendarurl,
        ];
    }

    /** Team workload from already-authorised projections only. Never loads another user's personal Moodle calendar. */
    private static function team_load_model(string $companyuuid, int $userid, array $events, bool $canviewteam): array {
        $directory = employee_service::employees_context($companyuuid, $userid, false);
        $members = [];
        foreach ((array)$directory['employees'] as $employee) {
            if (empty($employee['active'])) { continue; }
            $id = (int)$employee['user_id'];
            if (!$canviewteam && $id !== $userid) { continue; }
            $members[$id] = [
                'user_id'=>$id, 'name'=>(string)$employee['full_name'],
                'position'=>(string)($employee['position']['name'] ?? ''),
                'events'=>0, 'timed_minutes'=>0, 'tasks'=>0, 'meetings'=>0, 'battleplans'=>0,
                'conflicts'=>0,
            ];
        }
        if (!isset($members[$userid])) {
            global $USER;
            $members[$userid] = ['user_id'=>$userid,'name'=>fullname($USER),'position'=>'','events'=>0,
                'timed_minutes'=>0,'tasks'=>0,'meetings'=>0,'battleplans'=>0,'conflicts'=>0];
        }
        $intervals = [];
        foreach ($events as $event) {
            $source = (string)($event['source_kind'] ?? '');
            $owners = array_values(array_unique(array_map('intval', (array)($event['owner_user_ids'] ?? []))));
            if ($source === 'moodle') { $owners = [$userid]; }
            foreach ($owners as $owner) {
                if (!isset($members[$owner])) { continue; }
                $members[$owner]['events']++;
                if ($source === 'task') { $members[$owner]['tasks']++; }
                if ($source === 'meeting') { $members[$owner]['meetings']++; }
                if ($source === 'battleplan') { $members[$owner]['battleplans']++; }
                if (empty($event['all_day']) && (int)($event['duration'] ?? 0) > 0) {
                    $duration = (int)$event['duration'];
                    $members[$owner]['timed_minutes'] += (int)ceil($duration / 60);
                    $intervals[$owner][] = [(int)$event['time_start'], (int)$event['time_start'] + $duration];
                }
            }
        }
        foreach ($intervals as $owner=>$list) {
            usort($list, static function($a,$b){ return $a[0] <=> $b[0]; });
            $conflict = 0; $maxend = 0;
            foreach ($list as $interval) {
                if ($maxend > 0 && $interval[0] < $maxend) { $conflict++; }
                $maxend = max($maxend, $interval[1]);
            }
            if (isset($members[$owner])) { $members[$owner]['conflicts'] = $conflict; }
        }
        uasort($members, static function(array $a,array $b): int { return strcasecmp($a['name'],$b['name']); });
        return ['can_view_team'=>$canviewteam,'members'=>array_values($members),'current_user_id'=>$userid];
    }

    /**
     * Read-only calendar analytics for the currently visible month.
     *
     * No analytics row is persisted. All metrics are calculated from already-authorised
     * projections. Thresholds are presentation rules, not HR performance scores:
     *  - workload warning: >= 75% of the 09:00-18:00 weekday window occupied by timed events;
     *  - meeting-heavy day: >= 4 hours of meetings or any time overlap.
     */
    private static function analytics_model(array $events, array $teamload, array $rhythm,
            \DateTimeImmutable $periodstart, \DateTimeImmutable $periodend, int $userid): array {
        $workdayminutes = 9 * 60;
        $workdays = 0;
        for ($d = $periodstart->setTime(12, 0); $d <= $periodend; $d = $d->modify('+1 day')) {
            if ((int)$d->format('N') <= 5) { $workdays++; }
        }
        $availableminutes = max(1, $workdays * $workdayminutes);
        $now = time();
        $meetingminutes = 0;
        $meetingcount = 0;
        $timedminutes = 0;
        $overduetasks = 0;
        $meetingsbyday = [];
        $intervalsbyday = [];
        foreach ($events as $event) {
            $source = (string)($event['source_kind'] ?? '');
            $durationseconds = max(0, (int)($event['duration'] ?? 0));
            $minutes = (int)ceil($durationseconds / 60);
            if (empty($event['all_day']) && $minutes > 0) { $timedminutes += $minutes; }
            if ($source === 'meeting') {
                $meetingcount++;
                $meetingminutes += $minutes;
                $day = (string)($event['day_key'] ?? '');
                $meetingsbyday[$day] = ($meetingsbyday[$day] ?? 0) + $minutes;
                if ($durationseconds > 0) {
                    $intervalsbyday[$day][] = [(int)$event['time_start'], (int)$event['time_start'] + $durationseconds];
                }
            }
            if ($source === 'task' && (string)($event['date_kind'] ?? '') === 'due' &&
                    (int)($event['time_start'] ?? 0) < $now &&
                    !in_array((string)($event['status'] ?? ''), ['done','cancelled','deleted'], true)) {
                $overduetasks++;
            }
        }
        $conflictdays = [];
        foreach ($intervalsbyday as $day => $intervals) {
            usort($intervals, static function(array $a, array $b): int { return $a[0] <=> $b[0]; });
            $maxend = 0;
            foreach ($intervals as $interval) {
                if ($maxend > 0 && $interval[0] < $maxend) { $conflictdays[$day] = true; }
                $maxend = max($maxend, $interval[1]);
            }
        }
        $meetingheavydays = 0;
        foreach ($meetingsbyday as $day => $minutes) {
            if ($minutes >= 240 || !empty($conflictdays[$day])) { $meetingheavydays++; }
        }
        $meetingdensity = min(100, round(($meetingminutes / $availableminutes) * 100, 1));
        $workloaddensity = min(100, round(($timedminutes / $availableminutes) * 100, 1));
        $focusminutes = max(0, $availableminutes - $meetingminutes);
        $rhythmsignals = (int)($rhythm['hard_count'] ?? 0) + (int)($rhythm['attention_count'] ?? 0) +
            (int)($rhythm['missing_plans'] ?? 0) + (int)($rhythm['blocked_plans'] ?? 0) +
            (int)($rhythm['overdue_tasks'] ?? 0) + (int)($rhythm['needs_decision'] ?? 0);

        $teamrows = [];
        foreach ((array)($teamload['members'] ?? []) as $member) {
            $busy = max(0, (int)($member['timed_minutes'] ?? 0));
            $loadpct = min(100, round(($busy / $availableminutes) * 100, 1));
            $teamrows[] = [
                'user_id'=>(int)$member['user_id'], 'name'=>(string)$member['name'],
                'position'=>(string)($member['position'] ?? ''), 'events'=>(int)($member['events'] ?? 0),
                'meetings'=>(int)($member['meetings'] ?? 0), 'tasks'=>(int)($member['tasks'] ?? 0),
                'busy_minutes'=>$busy, 'load_pct'=>$loadpct, 'conflicts'=>(int)($member['conflicts'] ?? 0),
                'warning'=>$loadpct >= 75 || (int)($member['conflicts'] ?? 0) > 0,
                'is_current'=>(int)$member['user_id'] === $userid,
            ];
        }
        usort($teamrows, static function(array $a, array $b): int {
            if ($a['warning'] !== $b['warning']) { return $a['warning'] ? -1 : 1; }
            if ($a['load_pct'] !== $b['load_pct']) { return $a['load_pct'] < $b['load_pct'] ? 1 : -1; }
            return strcasecmp($a['name'], $b['name']);
        });
        return [
            'period_start'=>$periodstart->format('Y-m-d'), 'period_end'=>$periodend->format('Y-m-d'),
            'workdays'=>$workdays, 'available_minutes'=>$availableminutes,
            'meeting_count'=>$meetingcount, 'meeting_minutes'=>$meetingminutes,
            'meeting_density'=>$meetingdensity, 'timed_minutes'=>$timedminutes,
            'workload_density'=>$workloaddensity, 'focus_minutes'=>$focusminutes,
            'meeting_heavy_days'=>$meetingheavydays, 'conflict_days'=>count($conflictdays),
            'overdue_tasks'=>$overduetasks, 'rhythm_signals'=>$rhythmsignals,
            'rhythm_state'=>(string)($rhythm['state'] ?? ''), 'team'=>$teamrows,
            'team_visible'=>!empty($teamload['can_view_team']),
            'thresholds'=>['workload_warning_pct'=>75, 'meeting_heavy_minutes'=>240],
        ];
    }

    /** Build a deterministic hourly day-planner projection and overlap diagnostics. */
    private static function day_planner_model(array $events, \DateTimeImmutable $anchor, \DateTimeZone $tz): array {
        $timed = [];
        $allday = [];
        $minminute = 7 * 60;
        $maxminute = 20 * 60;
        foreach ($events as $event) {
            if (!empty($event['all_day'])) {
                $allday[] = $event;
                continue;
            }
            $start = (new \DateTimeImmutable('@' . (int)$event['time_start']))->setTimezone($tz);
            $startminute = ((int)$start->format('G') * 60) + (int)$start->format('i');
            $actualduration = max(0, (int)($event['duration'] ?? 0));
            $displayminutes = max(30, (int)ceil(max(0, $actualduration) / MINSECS));
            $endminute = min(24 * 60, $startminute + $displayminutes);
            $event['planner_start_minute'] = $startminute;
            $event['planner_end_minute'] = $endminute;
            $event['planner_display_minutes'] = $displayminutes;
            $event['planner_conflict'] = false;
            $timed[] = $event;
            $minminute = min($minminute, max(0, $startminute - 60));
            $maxminute = max($maxminute, min(24 * 60, $endminute + 60));
        }
        $startmin = max(0, (int)floor($minminute / 60) * 60);
        $endmin = min(24 * 60, (int)ceil($maxminute / 60) * 60);
        if ($endmin <= $startmin) { $endmin = min(24 * 60, $startmin + 60); }

        usort($timed, static function(array $a, array $b): int {
            if ($a['planner_start_minute'] === $b['planner_start_minute']) {
                return $a['planner_end_minute'] <=> $b['planner_end_minute'];
            }
            return $a['planner_start_minute'] <=> $b['planner_start_minute'];
        });
        $conflicts = 0;
        for ($i = 0, $n = count($timed); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($timed[$j]['planner_start_minute'] >= $timed[$i]['planner_end_minute']) { break; }
                if ($timed[$j]['planner_end_minute'] > $timed[$i]['planner_start_minute']) {
                    if (!$timed[$i]['planner_conflict']) { $timed[$i]['planner_conflict'] = true; }
                    if (!$timed[$j]['planner_conflict']) { $timed[$j]['planner_conflict'] = true; }
                    $conflicts++;
                }
            }
        }

        $lanes = []; $maxlanes = 1;
        foreach ($timed as $idx => $event) {
            $lane = 0;
            while (isset($lanes[$lane]) && $lanes[$lane] > $event['planner_start_minute']) { $lane++; }
            $lanes[$lane] = $event['planner_end_minute'];
            $timed[$idx]['planner_lane'] = $lane;
            $maxlanes = max($maxlanes, $lane + 1);
        }

        $intervals = [];
        foreach ($timed as $event) {
            $duration = max(0, (int)($event['duration'] ?? 0));
            if ($duration <= 0) { continue; }
            $s = (int)$event['planner_start_minute'];
            $e = min(24 * 60, $s + (int)ceil($duration / MINSECS));
            if ($e > $s) { $intervals[] = [$s, $e]; }
        }
        usort($intervals, static function($a, $b) { return $a[0] <=> $b[0]; });
        $occupied = 0; $cur = null;
        foreach ($intervals as $iv) {
            if ($cur === null) { $cur = $iv; continue; }
            if ($iv[0] <= $cur[1]) { $cur[1] = max($cur[1], $iv[1]); }
            else { $occupied += $cur[1]-$cur[0]; $cur = $iv; }
        }
        if ($cur !== null) { $occupied += $cur[1]-$cur[0]; }

        $hours = [];
        for ($m = $startmin; $m < $endmin; $m += 60) {
            $hours[] = [
                'minute' => $m,
                'label' => sprintf('%02d:00', intdiv($m, 60)),
                'datetime' => $anchor->format('Y-m-d') . 'T' . sprintf('%02d:00', intdiv($m, 60)),
            ];
        }
        return [
            'start_minute'=>$startmin, 'end_minute'=>$endmin, 'hours'=>$hours,
            'timed'=>$timed, 'all_day'=>$allday, 'lane_count'=>$maxlanes,
            'conflict_pairs'=>$conflicts, 'conflict_events'=>count(array_filter($timed, static function($e) { return !empty($e['planner_conflict']); })),
            'occupied_minutes'=>$occupied,
        ];
    }


    /**
     * Suggest common free intervals for selected employees using only already-authorised projections.
     *
     * Privacy rule: only the signed-in user's own Moodle events may participate. For other employees
     * availability is derived from BMS records already visible to the current actor; private Moodle
     * calendars are never queried. Suggestions are advisory and do not reserve or create anything.
     */
    public static function meeting_finder_model(string $companyuuid, int $userid, string $scope,
            string $date, array $memberids, int $durationminutes = 60): array {
        company_context_service::context($companyuuid, $userid);
        $tz = \core_date::get_user_timezone_object();
        $target = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date), $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$target || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) {
            $target = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        }
        $durationminutes = in_array($durationminutes, [30,45,60,90,120], true) ? $durationminutes : 60;

        // Always build from the unfiltered authorised calendar projection for the target month.
        $month = self::month($companyuuid, $userid, $target->format('Y-m'), $scope, '', 'all', 'all', 0);
        $load = (array)($month['team_load'] ?? []);
        $membersbyid = [];
        foreach ((array)($load['members'] ?? []) as $member) {
            $membersbyid[(int)$member['user_id']] = $member;
        }
        $canviewteam = !empty($load['can_view_team']);
        $memberids = array_values(array_unique(array_filter(array_map('intval', $memberids), static function($id) { return $id > 0; })));
        if (!$memberids) { $memberids = [$userid]; }
        $selected = [];
        foreach ($memberids as $id) {
            if ($id === $userid || ($canviewteam && isset($membersbyid[$id]))) {
                $selected[$id] = $membersbyid[$id] ?? ['user_id'=>$id,'name'=>'','position'=>''];
            }
        }
        if (!$selected) {
            $selected[$userid] = $membersbyid[$userid] ?? ['user_id'=>$userid,'name'=>'','position'=>''];
        }

        $daystart = $target->setTime(9, 0, 0)->getTimestamp();
        $dayend = $target->setTime(18, 0, 0)->getTimestamp();
        $busy = [];
        foreach (array_keys($selected) as $id) { $busy[$id] = []; }
        foreach ((array)$month['events'] as $event) {
            if ((string)($event['day_key'] ?? '') !== $target->format('Y-m-d')) { continue; }
            $duration = (int)($event['duration'] ?? 0);
            if (!empty($event['all_day']) || $duration <= 0) { continue; }
            $start = (int)$event['time_start'];
            $end = $start + $duration;
            if ($end <= $daystart || $start >= $dayend) { continue; }
            $owners = array_values(array_unique(array_map('intval', (array)($event['owner_user_ids'] ?? []))));
            if (($event['source_kind'] ?? '') === 'moodle') {
                // Other employees' personal Moodle calendars are intentionally not queried.
                $owners = [$userid];
            }
            foreach ($owners as $owner) {
                if (!isset($busy[$owner])) { continue; }
                $busy[$owner][] = [max($start,$daystart), min($end,$dayend)];
            }
        }

        // Union all selected people's visible busy intervals: any busy participant blocks a slot.
        $allbusy = [];
        foreach ($busy as $list) { foreach ($list as $iv) { $allbusy[] = $iv; } }
        usort($allbusy, static function($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [];
        foreach ($allbusy as $iv) {
            if (!$merged || $iv[0] > $merged[count($merged)-1][1]) { $merged[] = $iv; }
            else { $merged[count($merged)-1][1] = max($merged[count($merged)-1][1], $iv[1]); }
        }

        $slots = [];
        $step = 30 * MINSECS;
        $need = $durationminutes * MINSECS;
        for ($start = $daystart; $start + $need <= $dayend; $start += $step) {
            $end = $start + $need;
            $blocked = false;
            foreach ($merged as $iv) {
                if ($iv[0] >= $end) { break; }
                if ($iv[1] > $start && $iv[0] < $end) { $blocked = true; break; }
            }
            if ($blocked) { continue; }
            $participants = array_keys($selected);
            $slots[] = [
                'start'=>$start,
                'end'=>$end,
                'start_value'=>userdate($start, '%Y-%m-%dT%H:%M'),
                'end_value'=>userdate($end, '%Y-%m-%dT%H:%M'),
                'label'=>userdate($start, get_string('strftimetime')).' — '.userdate($end, get_string('strftimetime')),
                'meeting_url'=>(new \moodle_url('/local/kbacompany/meetings.php', [
                    'uuid'=>$companyuuid,
                    'prefillstart'=>userdate($start, '%Y-%m-%dT%H:%M'),
                    'prefillend'=>userdate($end, '%Y-%m-%dT%H:%M'),
                    'prefillparticipantscsv'=>implode(',', $participants),
                ]))->out(false),
            ];
            if (count($slots) >= 8) { break; }
        }

        $memberoptions = [];
        foreach ($membersbyid as $id=>$member) {
            if (!$canviewteam && $id !== $userid) { continue; }
            $memberoptions[] = [
                'user_id'=>$id,
                'name'=>(string)$member['name'],
                'position'=>(string)($member['position'] ?? ''),
                'selected'=>isset($selected[$id]),
            ];
        }
        return [
            'date'=>$target->format('Y-m-d'),
            'duration'=>$durationminutes,
            'members'=>$memberoptions,
            'selected_ids'=>array_keys($selected),
            'slots'=>$slots,
            'can_view_team'=>$canviewteam,
            'working_hours'=>'09:00–18:00',
            'advisory'=>true,
        ];
    }

    /** Move an authorised manual Moodle event to another day, preserving its local clock time. */
    public static function move_event(string $companyuuid, int $userid, string $scope, int $eventid, string $targetdate): void {
        global $CFG;
        company_context_service::context($companyuuid, $userid);
        require_once($CFG->dirroot . '/calendar/lib.php');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetdate)) {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_move_date'));
        }
        $record = self::event_record($eventid);
        self::require_manage_event($record, $companyuuid, $userid, $scope);
        $tz = \core_date::get_user_timezone_object();
        $current = (new \DateTimeImmutable('@' . (int)$record->timestart))->setTimezone($tz);
        $target = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $targetdate . ' ' . $current->format('H:i:s'), $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        $expectedlocal = $targetdate . ' ' . $current->format('H:i:s');
        if (!$target || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) ||
                $target->format('Y-m-d H:i:s') !== $expectedlocal) {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_move_date'));
        }
        $event = \calendar_event::load($eventid);
        $data = new \stdClass();
        $data->id = $eventid;
        $data->name = (string)$record->name;
        $data->description = (string)($record->description ?? '');
        $data->format = (int)($record->format ?? FORMAT_PLAIN);
        $data->timestart = $target->getTimestamp();
        $data->timeduration = max(0, (int)($record->timeduration ?? 0));
        $data->visible = (int)($record->visible ?? 1);
        $event->update($data, true);
    }

    private static function projection_identity(array $event): string {
        $source = (string)($event['source_kind'] ?? 'moodle');
        $id = (int)($event['id'] ?? 0);
        if ($source === 'moodle' && $id > 0) {
            return 'moodle:' . $id;
        }
        return hash('sha256', $source . '|' . (string)($event['event_type'] ?? '') . '|' .
            (string)($event['time_start'] ?? '') . '|' . (string)($event['url'] ?? '') . '|' . (string)($event['name'] ?? ''));
    }

    private static function normalise_date(string $date, string $fallbackmonth, \DateTimeZone $tz): \DateTimeImmutable {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:T\d{2}:\d{2})?$/D', $date, $matches)) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], $tz);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dt && (!is_array($errors) || (empty($errors['warning_count']) && empty($errors['error_count'])))) {
                return $dt->setTime(12, 0, 0);
            }
        }
        $today = new \DateTimeImmutable('now', $tz);
        if ($today->format('Y-m') === $fallbackmonth) {
            return $today->setTime(12, 0, 0);
        }
        return new \DateTimeImmutable($fallbackmonth . '-01 12:00:00', $tz);
    }

    private static function view_links(string $companyuuid, array $model, string $view, \DateTimeImmutable $anchor): array {
        $base = [
            'uuid'=>$companyuuid, 'scope'=>$model['scope'], 'q'=>$model['query'], 'kind'=>$model['kind'],
            'source'=>$model['source_filter'], 'view'=>$view, 'member'=>(int)($model['member_id'] ?? 0),
        ];
        if ($view === 'month' || $view === 'analytics') {
            $prevanchor = $anchor->modify('-1 month');
            $nextanchor = $anchor->modify('+1 month');
        } else if ($view === 'week') {
            $prevanchor = $anchor->modify('-7 days');
            $nextanchor = $anchor->modify('+7 days');
        } else {
            $prevanchor = $anchor->modify('-1 day');
            $nextanchor = $anchor->modify('+1 day');
        }
        $today = new \DateTimeImmutable('now', \core_date::get_user_timezone_object());
        $common = static function(\DateTimeImmutable $dt) use ($base): array {
            return $base + ['month'=>$dt->format('Y-m'), 'date'=>$dt->format('Y-m-d')];
        };
        $links = $model['links'];
        $links['prev'] = (new \moodle_url('/local/kbacompany/calendar.php', $common($prevanchor)))->out(false);
        $links['next'] = (new \moodle_url('/local/kbacompany/calendar.php', $common($nextanchor)))->out(false);
        $links['today'] = (new \moodle_url('/local/kbacompany/calendar.php', $common($today)))->out(false);
        foreach (['month','week','day','analytics'] as $candidate) {
            $links['view_' . $candidate] = (new \moodle_url('/local/kbacompany/calendar.php', [
                'uuid'=>$companyuuid, 'scope'=>$model['scope'], 'month'=>$anchor->format('Y-m'), 'date'=>$anchor->format('Y-m-d'),
                'q'=>$model['query'], 'kind'=>$model['kind'], 'source'=>$model['source_filter'], 'view'=>$candidate, 'member'=>(int)($model['member_id'] ?? 0),
            ]))->out(false);
        }
        $links['my'] = (new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid'=>$companyuuid, 'scope'=>'my', 'month'=>$anchor->format('Y-m'), 'date'=>$anchor->format('Y-m-d'),
            'q'=>$model['query'], 'kind'=>$model['kind'], 'source'=>$model['source_filter'], 'view'=>$view, 'member'=>(int)($model['member_id'] ?? 0),
        ]))->out(false);
        $links['company'] = (new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid'=>$companyuuid, 'scope'=>'company', 'month'=>$anchor->format('Y-m'), 'date'=>$anchor->format('Y-m-d'),
            'q'=>$model['query'], 'kind'=>$model['kind'], 'source'=>$model['source_filter'], 'view'=>$view, 'member'=>(int)($model['member_id'] ?? 0),
        ]))->out(false);
        $links['create'] = (new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid'=>$companyuuid, 'scope'=>$model['scope'], 'month'=>$anchor->format('Y-m'), 'date'=>$anchor->format('Y-m-d'),
            'q'=>$model['query'], 'kind'=>$model['kind'], 'source'=>$model['source_filter']==='all'?'all':'moodle',
            'view'=>$view, 'member'=>(int)($model['member_id'] ?? 0), 'action'=>'new',
        ]))->out(false);
        return $links;
    }

    /** Courses already visible to the signed-in Moodle user; no cross-user scan. */
    private static function my_courses(int $userid): array {
        $out = [];
        foreach (enrol_get_users_courses($userid, true, 'id,fullname,shortname,visible') as $course) {
            $id = (int)$course->id;
            if ($id <= SITEID || !self::course_visible($course, $userid)) {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'name' => format_string($course->fullname),
                'shortname' => format_string($course->shortname),
            ];
        }
        ksort($out);
        return $out;
    }

    /** Explicit Company course catalogue; never derive company scope from arbitrary user enrolments. */
    private static function company_courses(string $companyuuid, int $userid): array {
        global $DB;
        static $cache = [];
        $cachekey = $companyuuid . ':' . $userid;
        if (isset($cache[$cachekey])) {
            return $cache[$cachekey];
        }
        $out = [];
        try {
            foreach (learning_contour_service::company_course_catalogue($companyuuid, $userid) as $course) {
                $id = (int)($course['courseid'] ?? $course['course_id'] ?? 0);
                if ($id <= SITEID) {
                    continue;
                }
                $record = $DB->get_record('course', ['id' => $id], 'id,fullname,shortname,visible', IGNORE_MISSING);
                if (!$record || !self::course_visible($record, $userid)) {
                    continue;
                }
                $out[$id] = [
                    'id' => $id,
                    'name' => format_string($record->fullname),
                    'shortname' => format_string($record->shortname),
                ];
            }
        } catch (\Throwable $e) {
            // Corporate Learning is optional. Explicit BMS course bindings below remain available.
        }

        $sql = "SELECT sourcekey
                  FROM {local_kbacompany_tasksrc}
                 WHERE companyuuid = :companyuuid AND active = 1 AND sourcekey LIKE :courseprefix";
        foreach ($DB->get_records_sql($sql, [
            'companyuuid' => $companyuuid,
            'courseprefix' => 'course:%',
        ]) as $binding) {
            if (!preg_match('/^course:(\d+)$/D', (string)$binding->sourcekey, $matches)) {
                continue;
            }
            $id = (int)$matches[1];
            if ($id <= SITEID || isset($out[$id])) {
                continue;
            }
            $record = $DB->get_record('course', ['id' => $id], 'id,fullname,shortname,visible', IGNORE_MISSING);
            if (!$record || !self::course_visible($record, $userid)) {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'name' => format_string($record->fullname),
                'shortname' => format_string($record->shortname),
            ];
        }
        ksort($out);
        $cache[$cachekey] = $out;
        return $out;
    }

    private static function event_source_label(\stdClass $event, array $courses, string $scope): string {
        $courseid = (int)($event->courseid ?? 0);
        if ($courseid > SITEID && isset($courses[$courseid])) {
            return (string)$courses[$courseid]['name'];
        }
        if ((int)($event->userid ?? 0) > 0) {
            return calendar_workspace_ui_service::text('bmscalendar_source_personal');
        }
        if ($courseid === SITEID) {
            return calendar_workspace_ui_service::text('bmscalendar_source_site');
        }
        if ((int)($event->groupid ?? 0) > 0) {
            return calendar_workspace_ui_service::text('bmscalendar_source_group');
        }
        return $scope === 'company'
            ? calendar_workspace_ui_service::text('bmscalendar_source_company')
            : calendar_workspace_ui_service::text('bmscalendar_source_moodle');
    }

    private static function course_visible(\stdClass $course, int $userid): bool {
        $context = \context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        $authorised = is_enrolled($context, $userid, '', true) || has_capability('moodle/course:view', $context, $userid);
        if (!$authorised && !is_siteadmin($userid)) {
            return false;
        }
        if (empty($course->visible) && !is_siteadmin($userid) &&
                !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
            return false;
        }
        return true;
    }

    /** Visible group ids inside already scoped courses. */
    private static function visible_group_ids(array $courseids, int $userid): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        $ids = [];
        foreach ($courseids as $courseid) {
            $context = \context_course::instance((int)$courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            if (is_siteadmin($userid) || has_capability('moodle/site:accessallgroups', $context, $userid)) {
                foreach ($DB->get_records('groups', ['courseid' => (int)$courseid], '', 'id') as $group) {
                    $ids[(int)$group->id] = true;
                }
                continue;
            }
            $sql = "SELECT g.id
                      FROM {groups} g
                      JOIN {groups_members} gm ON gm.groupid = g.id
                     WHERE g.courseid = :courseid AND gm.userid = :userid";
            foreach ($DB->get_records_sql($sql, ['courseid' => (int)$courseid, 'userid' => $userid]) as $group) {
                $ids[(int)$group->id] = true;
            }
        }
        return array_keys($ids);
    }

    /** Read-only event detail constrained by the same visible calendar projection. */
    public static function detail_model(string $companyuuid, int $userid, string $scope, string $month, int $eventid): array {
        $projection = self::month($companyuuid, $userid, $month, $scope, '', 'all');
        $visible = null;
        foreach ($projection['events'] as $event) {
            if ((int)$event['id'] === $eventid) {
                $visible = $event;
                break;
            }
        }
        if ($visible === null) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $record = self::event_record($eventid);
        $description = trim(strip_tags(format_text((string)($record->description ?? ''), (int)($record->format ?? FORMAT_HTML), [
            'context' => \context_system::instance(),
            'para' => false,
        ])));
        $duration = max(0, (int)($record->timeduration ?? 0));
        $visible['description'] = $description;
        $visible['duration_label'] = self::duration_label($duration);
        $visible['source_label'] = (string)$visible['course_name'];
        return $visible;
    }

    private static function is_generated_event(\stdClass $event): bool {
        return !empty($event->modulename) || !empty($event->component) || !empty($event->subscriptionid) ||
            (int)($event->groupid ?? 0) > 0;
    }

    private static function duration_label(int $seconds): string {
        if ($seconds <= 0) {
            return calendar_workspace_ui_service::text('bmscalendar_duration_none');
        }
        $minutes = (int)round($seconds / MINSECS);
        if ($minutes < 60) {
            return $minutes . ' ' . calendar_workspace_ui_service::text('bmscalendar_minutes_short');
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $rest > 0
            ? $hours . ' ' . calendar_workspace_ui_service::text('bmscalendar_hours_short') . ' ' . $rest . ' ' . calendar_workspace_ui_service::text('bmscalendar_minutes_short')
            : $hours . ' ' . calendar_workspace_ui_service::text('bmscalendar_hours_short');
    }

    /** Targets the current user may create manual events in. */
    public static function create_targets(string $companyuuid, int $userid, string $scope = 'my'): array {
        company_context_service::context($companyuuid, $userid);
        $scope = ($scope === 'company') ? 'company' : 'my';
        $targets = [];
        $systemcontext = \context_system::instance();
        if ($scope === 'my' && (is_siteadmin($userid) || has_capability('moodle/calendar:manageownentries', $systemcontext, $userid))) {
            $targets[] = [
                'value' => 'personal',
                'label' => calendar_workspace_ui_service::text('bmscalendar_target_personal'),
                'type' => 'personal',
                'course_id' => 0,
            ];
        }
        foreach (self::company_courses($companyuuid, $userid) as $courseid => $course) {
            $context = \context_course::instance((int)$courseid, IGNORE_MISSING);
            if (!$context || (!is_siteadmin($userid) && !has_capability('moodle/calendar:manageentries', $context, $userid))) {
                continue;
            }
            $targets[] = [
                'value' => 'course:' . (int)$courseid,
                'label' => (string)$course['name'],
                'type' => 'course',
                'course_id' => (int)$courseid,
            ];
        }
        return $targets;
    }

    /** Build defaults for the create/edit form without mutating data. */
    public static function editor_model(string $companyuuid, int $userid, string $scope, string $month,
            string $action = 'new', int $eventid = 0, string $date = ''): array {
        $scope = ($scope === 'company') ? 'company' : 'my';
        $targets = self::create_targets($companyuuid, $userid, $scope);
        $defaultstart = userdate(time() + HOURSECS, '%Y-%m-%dT%H:00');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            $defaultstart = $date . 'T09:00';
        } else if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $date)) {
            $defaultstart = $date;
        }
        $values = [
            'eventid' => 0,
            'name' => '',
            'description' => '',
            'start' => $defaultstart,
            'duration' => 60,
            'target' => $targets ? (string)$targets[0]['value'] : '',
            'target_label' => $targets ? (string)$targets[0]['label'] : '',
        ];
        $editing = ($action === 'edit' && $eventid > 0);
        if ($editing) {
            $record = self::event_record($eventid);
            self::require_manage_event($record, $companyuuid, $userid, $scope);
            $values['eventid'] = (int)$record->id;
            $values['name'] = (string)$record->name;
            $values['description'] = (string)($record->description ?? '');
            $values['start'] = userdate((int)$record->timestart, '%Y-%m-%dT%H:%M');
            $values['duration'] = max(0, (int)round(((int)$record->timeduration) / MINSECS));
            if ((int)$record->userid === $userid && (int)$record->courseid <= SITEID) {
                $values['target'] = 'personal';
                $values['target_label'] = calendar_workspace_ui_service::text('bmscalendar_target_personal');
            } else {
                $courseid = (int)$record->courseid;
                $values['target'] = 'course:' . $courseid;
                $courses = self::company_courses($companyuuid, $userid);
                $values['target_label'] = isset($courses[$courseid]) ? (string)$courses[$courseid]['name'] : '';
            }
        }
        return [
            'editing' => $editing,
            'values' => $values,
            'targets' => $targets,
            'scope' => $scope,
            'month' => $month,
        ];
    }

    /** Create or update one manual Moodle calendar event. */
    public static function save_event(string $companyuuid, int $userid, string $scope, array $input): int {
        global $CFG;
        company_context_service::context($companyuuid, $userid);
        require_once($CFG->dirroot . '/calendar/lib.php');

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_name'));
        }
        if (\core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_name_long'));
        }
        $start = self::parse_datetime((string)($input['start'] ?? ''));
        $durationminutes = max(0, min(10080, (int)($input['duration'] ?? 0)));
        $description = trim((string)($input['description'] ?? ''));
        $eventid = max(0, (int)($input['eventid'] ?? 0));
        $repeatmode = (string)($input['repeatmode'] ?? 'none');
        $repeatcount = max(1, min(52, (int)($input['repeatcount'] ?? 1)));
        $repeatweekdays = is_array($input['repeatweekdays'] ?? null) ? $input['repeatweekdays'] : [];

        if ($eventid > 0) {
            $record = self::event_record($eventid);
            self::require_manage_event($record, $companyuuid, $userid, $scope);
            $event = \calendar_event::load($eventid);
            $data = new \stdClass();
            $data->id = $eventid;
            $data->name = $name;
            $data->description = $description;
            $data->format = FORMAT_PLAIN;
            $data->timestart = $start;
            $data->timeduration = $durationminutes * MINSECS;
            $data->visible = 1;
            $event->update($data, true);
            return $eventid;
        }

        $target = (string)($input['target'] ?? '');
        self::require_create_target($target, $companyuuid, $userid, $scope);
        $data = new \stdClass();
        $data->name = $name;
        $data->description = $description;
        $data->format = FORMAT_PLAIN;
        $data->type = defined('CALENDAR_EVENT_TYPE_STANDARD') ? CALENDAR_EVENT_TYPE_STANDARD : 0;
        $data->groupid = 0;
        $data->modulename = '';
        $data->instance = 0;
        $data->timestart = $start;
        $data->timeduration = $durationminutes * MINSECS;
        $data->visible = 1;
        if ($target === 'personal') {
            $data->eventtype = 'user';
            $data->courseid = 0;
            $data->userid = $userid;
        } else {
            $courseid = (int)substr($target, strlen('course:'));
            $data->eventtype = 'course';
            $data->courseid = $courseid;
            $data->userid = 0;
        }
        $starts = recurrence_service::starts($start, $repeatmode, $repeatcount, $repeatweekdays);
        $firstid = 0;
        foreach ($starts as $occurrencestart) {
            $eventdata = clone $data;
            $eventdata->timestart = $occurrencestart;
            $created = \calendar_event::create($eventdata, true);
            if (!$created) {
                throw new \moodle_exception('error');
            }
            if ($firstid === 0) {
                $firstid = (int)$created->id;
            }
        }
        return $firstid;
    }

    /** Delete one manual event after explicit authorisation. */
    public static function delete_event(string $companyuuid, int $userid, string $scope, int $eventid): void {
        global $CFG;
        company_context_service::context($companyuuid, $userid);
        require_once($CFG->dirroot . '/calendar/lib.php');
        $record = self::event_record($eventid);
        self::require_manage_event($record, $companyuuid, $userid, $scope);
        $event = \calendar_event::load($eventid);
        $event->delete(false);
    }

    private static function require_create_target(string $target, string $companyuuid, int $userid, string $scope): void {
        $allowed = [];
        foreach (self::create_targets($companyuuid, $userid, $scope) as $item) {
            $allowed[(string)$item['value']] = true;
        }
        if (!isset($allowed[$target])) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/calendar:manageownentries', 'nopermissions', '');
        }
    }

    private static function event_record(int $eventid): \stdClass {
        global $DB;
        return $DB->get_record('event', ['id' => $eventid], '*', MUST_EXIST);
    }

    private static function can_manage_event_record(\stdClass $event, string $companyuuid, int $userid, string $scope): bool {
        try {
            self::require_manage_event($event, $companyuuid, $userid, $scope);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Manual personal events owned by the user are editable. Manual course events
     * are editable only when the course is explicitly in the current Company and
     * Moodle grants calendar management in that course. Module/subscription/group
     * events stay read-only because their owning subsystem must mutate them.
     */
    private static function require_manage_event(\stdClass $event, string $companyuuid, int $userid, string $scope = 'my'): void {
        if (!empty($event->modulename) || !empty($event->component) || !empty($event->subscriptionid) ||
                (int)($event->groupid ?? 0) > 0) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/calendar:manageentries', 'nopermissions', '');
        }
        $courseid = (int)($event->courseid ?? 0);
        $eventuserid = (int)($event->userid ?? 0);
        if ($scope === 'my' && $eventuserid === $userid && $courseid <= SITEID) {
            $context = \context_system::instance();
            if (is_siteadmin($userid) || has_capability('moodle/calendar:manageownentries', $context, $userid)) {
                return;
            }
        }
        if ($courseid > SITEID) {
            $courses = self::company_courses($companyuuid, $userid);
            if (!isset($courses[$courseid])) {
                throw new \required_capability_exception(\context_system::instance(), 'moodle/calendar:manageentries', 'nopermissions', '');
            }
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($context && (is_siteadmin($userid) || has_capability('moodle/calendar:manageentries', $context, $userid))) {
                return;
            }
        }
        throw new \required_capability_exception(\context_system::instance(), 'moodle/calendar:manageentries', 'nopermissions', '');
    }

    private static function parse_datetime(string $value): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_date'));
        }
        $tz = \core_date::get_user_timezone_object();
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) ||
                $dt->format('Y-m-d\TH:i') !== $value) {
            throw new \invalid_parameter_exception(calendar_workspace_ui_service::text('bmscalendar_error_date'));
        }
        return $dt->getTimestamp();
    }

    /** Bounded pagination over Moodle core calendar events for large-company months. */
    private static function legacy_events_bounded(int $start, int $end, $users, array $groupids, array $courseids): array {
        $events = [];
        for ($offset = 0; $offset < self::EVENT_HARD_LIMIT; $offset += self::EVENT_PAGE_SIZE) {
            $page = calendar_get_legacy_events($start, $end, $users, $groupids, $courseids,
                true, true, self::EVENT_PAGE_SIZE, $offset);
            if (!$page) { break; }
            foreach ($page as $event) {
                $events[] = $event;
                if (count($events) >= self::EVENT_HARD_LIMIT) { break 2; }
            }
            if (count($page) < self::EVENT_PAGE_SIZE) { break; }
        }
        return $events;
    }

    private static function normalise_month(string $month): array {
        if (preg_match('/^(\d{4})-(\d{2})$/D', $month, $matches)) {
            $year = (int)$matches[1];
            $monthnum = (int)$matches[2];
            if ($year >= 1970 && $year <= 2100 && $monthnum >= 1 && $monthnum <= 12) {
                return [$year, $monthnum];
            }
        }
        $now = usergetdate(time());
        return [(int)$now['year'], (int)$now['mon']];
    }
}
