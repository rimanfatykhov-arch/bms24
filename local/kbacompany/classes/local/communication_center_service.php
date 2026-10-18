<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Unified daily-work projection over communication and heterogeneous task sources. */
class communication_center_service {
    public const CONTRACT_VERSION = '0.5.0';
    public const TABS = ['all', 'inbox', 'unread', 'messages', 'tasks', 'discussions', 'mentions', 'meetings', 'notifications'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany', 'contract' => 'bms_unified_task_communication_center',
            'version' => self::CONTRACT_VERSION, 'tabs' => self::TABS,
            'rules' => ['one_daily_work_entry_point', 'message_task_notification_remain_distinct_objects',
                'private_thread_access_remains_participant_scoped', 'task_provider_source_of_truth_is_preserved',
                'kbaelement_learning_tasks_use_develop_stream', 'meeting_decision_task_objects_remain_distinct',
                'theme_controls_presentation_not_menu_business_logic', 'unread_threads_are_first_class_inbox_projection', 'mentions_are_first_class_inbox_projection',
                'message_files_use_protected_moodle_file_api', 'message_to_task_and_meeting_decision_keep_traceable_canonical_objects'],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function context(string $companyuuid, int $actor, string $tab = 'inbox', string $taskscope = 'my',
            string $provider = 'all', string $threaduuid = ''): array {
        company_context_service::context($companyuuid, $actor);
        if (!in_array($tab, self::TABS, true)) { $tab = 'inbox'; }
        $comm = communication_service::context($companyuuid, $actor, $threaduuid, 'all');
        $targetuser = ($taskscope === 'all' && task_service::can_manage($companyuuid, $actor)) ? 0 : $actor;
        $tasks = task_provider_service::context($companyuuid, $actor, $taskscope, $provider, $targetuser);
        $meetings = meeting_service::context($companyuuid, $actor);
        $direct = []; $discussions = [];
        foreach ($comm['threads'] as $thread) {
            if ($thread['thread_type'] === 'object') { $discussions[] = $thread; }
            else { $direct[] = $thread; }
        }
        $inbox = [];
        foreach ($comm['notifications'] as $notification) {
            if (!empty($notification['is_read'])) { continue; }
            $inbox[] = ['kind' => 'notification', 'time' => (int)$notification['time_created'], 'notification' => $notification];
        }
        foreach ($tasks['tasks'] as $task) {
            if (in_array($task['status'], ['done', 'cancelled'], true)) { continue; }
            if (!$task['is_overdue'] && $task['due_date'] === '') { continue; }
            $time = $task['due_date'] !== '' ? strtotime($task['due_date'] . ' 23:59:59 UTC') : 0;
            $inbox[] = ['kind' => $task['is_overdue'] ? 'task_overdue' : 'task_due', 'time' => $time, 'task' => $task];
        }
        usort($inbox, static function(array $a, array $b): int { return $b['time'] <=> $a['time']; });
        return ['company' => $comm['company'], 'tab' => $tab, 'communication' => $comm, 'task_center' => $tasks,
            'messages' => $direct, 'discussions' => $discussions, 'meetings' => $meetings['meetings'],
            'meeting_counts' => $meetings['counts'], 'notifications' => $comm['notifications'],
            'inbox' => $inbox, 'counts' => [
                'unread_threads' => (int)($comm['counts']['unread_threads'] ?? 0),
                'unread_notifications' => (int)($comm['counts']['unread_notifications'] ?? 0),
                'tasks' => (int)$tasks['counts']['total'], 'overdue' => (int)$tasks['counts']['overdue'],
                'develop' => (int)$tasks['counts']['develop'],
                'meetings' => (int)$meetings['counts']['total'],
                'open_decisions' => (int)$meetings['counts']['open_decisions'],
            ]];
    }

    /** Counts only for the common shell; no task, course, meeting or message body hydration. */
    public static function shell_counters(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $candidateparams = ['shellcompany'=>$companyuuid, 'shellactor'=>$actor,
            'shellauthor'=>$actor, 'shellreader'=>$actor];
        $candidates = $DB->get_records_sql("SELECT t.*
            FROM {local_kbacompany_commthread} t
            WHERE t.companyuuid=:shellcompany
              AND EXISTS (SELECT p.id FROM {local_kbacompany_commpart} p
                WHERE p.companyuuid=t.companyuuid AND p.threaduuid=t.uuid AND p.userid=:shellactor AND p.active=1)
              AND EXISTS (SELECT m.id FROM {local_kbacompany_commmsg} m
                WHERE m.companyuuid=t.companyuuid AND m.threaduuid=t.uuid AND m.status='active'
                  AND m.authorid<>:shellauthor AND m.id>COALESCE((SELECT r.lastmessageid
                    FROM {local_kbacompany_commread} r WHERE r.companyuuid=t.companyuuid
                    AND r.threaduuid=t.uuid AND r.userid=:shellreader),0))", $candidateparams);
        $unreadthreads = 0;
        foreach ($candidates as $candidate) {
            if ((string)$candidate->threadtype === 'object') {
                try {
                    communication_object_service::describe($companyuuid,$actor,(string)$candidate->contextkind,
                        (string)$candidate->contextuuid,(int)$candidate->contextrevision);
                } catch (\moodle_exception $e) { continue; }
            }
            $unreadthreads++;
        }
        $unreadnotifications = 0;
        foreach ($DB->get_records('local_kbacompany_commnotif', [
            'companyuuid'=>$companyuuid,'userid'=>$actor,'isread'=>0,
        ]) as $notification) {
            if ((string)$notification->threaduuid === '') { $unreadnotifications++; continue; }
            $thread = $DB->get_record('local_kbacompany_commthread', [
                'companyuuid'=>$companyuuid,'uuid'=>(string)$notification->threaduuid,
            ]);
            if (!$thread || !$DB->record_exists('local_kbacompany_commpart', [
                    'companyuuid'=>$companyuuid,'threaduuid'=>(string)$thread->uuid,'userid'=>$actor,'active'=>1])) {
                continue;
            }
            if ((string)$thread->threadtype === 'object') {
                try {
                    communication_object_service::describe($companyuuid,$actor,(string)$thread->contextkind,
                        (string)$thread->contextuuid,(int)$thread->contextrevision);
                } catch (\moodle_exception $e) { continue; }
            }
            $unreadnotifications++;
        }
        $tasks = task_service::dashboard_counts($companyuuid, $actor);
        $learning = $DB->count_records_sql("SELECT COUNT(t.id) FROM {local_kbacompany_task} t
            WHERE t.companyuuid=:c AND t.assigneeid=:a AND t.stream='develop'
              AND t.status NOT IN ('done','cancelled','deleted')", ['c'=>$companyuuid,'a'=>$actor]);
        return ['incoming'=>(int)$unreadthreads+(int)$unreadnotifications,
            'tasks'=>$tasks['my_open'], 'overdue'=>$tasks['overdue'], 'control'=>$tasks['review'],
            'learning'=>(int)$learning, 'unread_threads'=>(int)$unreadthreads,
            'unread_notifications'=>(int)$unreadnotifications];
    }

    /**
     * Communication Center 2.0 workspace projection.
     *
     * The canonical communication service remains the source of truth. This method
     * only filters and groups participant-visible threads for the three-pane UI.
     */
    public static function workspace_context(string $companyuuid, int $actor, string $tab = 'inbox',
            string $selectedthread = '', string $search = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!in_array($tab, self::TABS, true) || $tab === 'tasks') { $tab = 'inbox'; }
        $scope = 'all';
        if ($tab === 'unread') { $scope = 'unread'; }
        if ($tab === 'mentions') { $scope = 'mentions'; }
        $comm = communication_service::context($companyuuid, $actor, $selectedthread, $scope);
        $threads = [];
        $needle = \core_text::strtolower(trim(\core_text::substr($search, 0, 200)));
        foreach ($comm['threads'] as $thread) {
            if ($tab === 'inbox' && (int)$thread['unread_count'] < 1) { continue; }
            if ($tab === 'messages' && $thread['thread_type'] === 'object') { continue; }
            if ($tab === 'discussions' && $thread['thread_type'] !== 'object') { continue; }
            if (in_array($tab, ['meetings', 'notifications'], true)) { continue; }
            if ($needle !== '') {
                $haystack = (string)$thread['title'];
                if (!empty($thread['context'])) {
                    $haystack .= ' ' . (string)$thread['context']['label'] . ' ' . (string)$thread['context']['title'];
                }
                foreach ($thread['participants'] as $participant) { $haystack .= ' ' . (string)$participant['name']; }
                $matched = strpos(\core_text::strtolower($haystack), $needle) !== false;
                if (!$matched && $tab === 'mentions') {
                    $pattern = '%' . $DB->sql_like_escape(\core_text::substr($search, 0, 200)) . '%';
                    $matched = $DB->record_exists_sql('SELECT mn.id
                          FROM {local_kbacompany_commmention} mn
                          JOIN {local_kbacompany_commmsg} msg
                            ON msg.companyuuid=mn.companyuuid AND msg.uuid=mn.messageuuid
                         WHERE mn.companyuuid=:msc AND mn.threaduuid=:mst AND mn.userid=:msu
                           AND msg.status=\'active\' AND ' . $DB->sql_like('msg.body', ':msq', false),
                        ['msc'=>$companyuuid,'mst'=>$thread['uuid'],'msu'=>$actor,'msq'=>$pattern]);
                }
                if (!$matched) { continue; }
            }
            $threads[] = $thread;
        }
        $taskcounts = task_service::dashboard_counts($companyuuid, $actor);
        return [
            'company' => $comm['company'], 'tab' => $tab, 'threads' => $threads,
            'selected' => $comm['selected'], 'messages' => $comm['messages'], 'employees' => $comm['employees'],
            'notifications' => $comm['notifications'], 'counts' => $comm['counts'], 'task_counts' => $taskcounts,
            'search' => $search, 'can_manage' => $comm['can_manage'],
        ];
    }

    /** Focused paginated screen projection. Does not open threads or mark messages read. */
    public static function screen_context(string $companyuuid, int $actor, string $tab, string $search = '', int $page = 0): array {
        global $DB;
        company_context_service::context($companyuuid,$actor);
        if(!in_array($tab,['inbox','unread','messages','discussions','mentions','meetings','notifications'],true)){$tab='inbox';}
        $rows=[];$total=0;$pagesize=25;$page=max(0,$page);
        $params=['cccompany'=>$companyuuid,'ccactor'=>$actor];
        if(in_array($tab,['messages','discussions','unread'],true)){
            $where="t.companyuuid=:cccompany AND EXISTS (SELECT p.id FROM {local_kbacompany_commpart} p
                WHERE p.companyuuid=t.companyuuid AND p.threaduuid=t.uuid AND p.userid=:ccactor AND p.active=1)";
            if($tab==='discussions'){$where.=" AND t.threadtype='object'";}
            else if($tab==='messages'){$where.=" AND t.threadtype<>'object'";}
            else {
                $where.=" AND EXISTS (SELECT um.id FROM {local_kbacompany_commmsg} um
                    WHERE um.companyuuid=t.companyuuid AND um.threaduuid=t.uuid AND um.status='active'
                      AND um.authorid<>:ccunreadauthor AND um.id>COALESCE((SELECT ur.lastmessageid
                        FROM {local_kbacompany_commread} ur WHERE ur.companyuuid=t.companyuuid
                          AND ur.threaduuid=t.uuid AND ur.userid=:ccunreadreader),0))";
                $params['ccunreadauthor']=$actor;$params['ccunreadreader']=$actor;
            }
            if($search!==''){$where.=' AND '.$DB->sql_like('t.title',':ccsearch',false);$params['ccsearch']='%'.$DB->sql_like_escape(\core_text::substr($search,0,200)).'%';}
            $total=$DB->count_records_sql("SELECT COUNT(t.id) FROM {local_kbacompany_commthread} t WHERE $where",$params);
            $page=min($page,max(0,(int)ceil($total/$pagesize)-1));
            // One paginated query instead of two extra read/unread queries per thread.
            // Use separate named parameters because Moodle's DML placeholders are unique.
            $params['ccreadactor']=$actor;
            $params['cccountauthor']=$actor;
            $sql="SELECT t.*, COALESCE(cr.lastmessageid,0) AS readlast,
                    (SELECT COUNT(umc.id) FROM {local_kbacompany_commmsg} umc
                      WHERE umc.companyuuid=t.companyuuid AND umc.threaduuid=t.uuid
                        AND umc.authorid<>:cccountauthor AND umc.status='active'
                        AND umc.id>COALESCE(cr.lastmessageid,0)) AS unread_count
                  FROM {local_kbacompany_commthread} t
             LEFT JOIN {local_kbacompany_commread} cr
                    ON cr.companyuuid=t.companyuuid AND cr.threaduuid=t.uuid AND cr.userid=:ccreadactor
                 WHERE $where ORDER BY t.lastmessageat DESC,t.id DESC";
            foreach($DB->get_records_sql($sql,$params,$page*$pagesize,$pagesize) as $r){
                if ((string)$r->threadtype === 'object') {
                    try { communication_object_service::describe($companyuuid,$actor,(string)$r->contextkind,(string)$r->contextuuid,(int)$r->contextrevision); }
                    catch (\moodle_exception $e) { continue; }
                }
                $rows[]=['unread'=>(int)$r->unread_count,'uuid'=>(string)$r->uuid,'title'=>(string)$r->title,'kind'=>(string)$r->contextkind,
                    'threadtype'=>(string)$r->threadtype,'status'=>(string)$r->status,'updated'=>(int)($r->lastmessageat?:$r->timecreated)];
            }
        }else if($tab==='mentions'){
            $where="mn.companyuuid=:cccompany AND mn.userid=:ccactor
                AND m.status='active'
                AND EXISTS (SELECT p.id FROM {local_kbacompany_commpart} p
                    WHERE p.companyuuid=mn.companyuuid AND p.threaduuid=mn.threaduuid
                      AND p.userid=:ccparticipant AND p.active=1)";
            $params['ccparticipant']=$actor;
            if($search!==''){
                $where.=' AND ('.$DB->sql_like('t.title',':ccsearchtitle',false).' OR '.$DB->sql_like('m.body',':ccsearchbody',false).')';
                $needle='%'.$DB->sql_like_escape(\core_text::substr($search,0,200)).'%';
                $params['ccsearchtitle']=$needle;$params['ccsearchbody']=$needle;
            }
            $total=$DB->count_records_sql("SELECT COUNT(mn.id)
                FROM {local_kbacompany_commmention} mn
                JOIN {local_kbacompany_commmsg} m ON m.companyuuid=mn.companyuuid AND m.uuid=mn.messageuuid
                JOIN {local_kbacompany_commthread} t ON t.companyuuid=mn.companyuuid AND t.uuid=mn.threaduuid
                WHERE $where",$params);
            $page=min($page,max(0,(int)ceil($total/$pagesize)-1));
            $sql="SELECT mn.id,mn.messageuuid,mn.threaduuid,m.body,m.timecreated,t.title,t.threadtype,t.contextkind,
                    t.contextuuid,t.contextrevision,u.firstname,u.lastname
                FROM {local_kbacompany_commmention} mn
                JOIN {local_kbacompany_commmsg} m ON m.companyuuid=mn.companyuuid AND m.uuid=mn.messageuuid
                JOIN {local_kbacompany_commthread} t ON t.companyuuid=mn.companyuuid AND t.uuid=mn.threaduuid
                LEFT JOIN {user} u ON u.id=m.authorid
                WHERE $where ORDER BY m.timecreated DESC,m.id DESC";
            foreach($DB->get_records_sql($sql,$params,$page*$pagesize,$pagesize) as $r){
                if ((string)$r->threadtype === 'object') {
                    try { communication_object_service::describe($companyuuid,$actor,(string)$r->contextkind,(string)$r->contextuuid,(int)$r->contextrevision); }
                    catch (\moodle_exception $e) { continue; }
                }
                $author=trim((string)$r->firstname.' '.(string)$r->lastname);
                $rows[]=['message_uuid'=>(string)$r->messageuuid,'thread_uuid'=>(string)$r->threaduuid,
                    'thread_title'=>(string)$r->title,'body'=>(string)$r->body,'author'=>$author,'updated'=>(int)$r->timecreated];
            }
        }else if($tab==='inbox'||$tab==='notifications'){
            $where='n.companyuuid=:cccompany AND n.userid=:ccactor';
            if($tab==='inbox'){$where.=' AND n.isread=0';}
            if($search!==''){$where.=' AND '.$DB->sql_like('n.title',':ccsearch',false);$params['ccsearch']='%'.$DB->sql_like_escape(\core_text::substr($search,0,200)).'%';}
            $total=$DB->count_records_sql("SELECT COUNT(n.id) FROM {local_kbacompany_commnotif} n WHERE $where",$params);
            $page=min($page,max(0,(int)ceil($total/$pagesize)-1));
            foreach($DB->get_records_sql("SELECT n.* FROM {local_kbacompany_commnotif} n WHERE $where ORDER BY n.id DESC",$params,$page*$pagesize,$pagesize) as $r){
                // A notification never grants access to its old/private source thread.
                $thread='';
                if($r->threaduuid!==''){
                    $source=$DB->get_record('local_kbacompany_commthread',['companyuuid'=>$companyuuid,'uuid'=>$r->threaduuid]);
                    $allowed=$source&&$DB->record_exists('local_kbacompany_commpart',['companyuuid'=>$companyuuid,'threaduuid'=>$r->threaduuid,'userid'=>$actor,'active'=>1]);
                    if($allowed&&(string)$source->threadtype==='object'){
                        try { communication_object_service::describe($companyuuid,$actor,(string)$source->contextkind,(string)$source->contextuuid,(int)$source->contextrevision); }
                        catch (\moodle_exception $e) { $allowed=false; }
                    }
                    if($allowed){$thread=(string)$r->threaduuid;}
                }
                $rows[]=['uuid'=>(string)$r->uuid,'title'=>(string)$r->title,'kind'=>(string)$r->kind,'is_read'=>!empty($r->isread),'thread_uuid'=>$thread,'updated'=>(int)$r->timecreated];
            }
        }
        return ['tab'=>$tab,'rows'=>$rows,'total'=>$total,'page'=>$page,'pagesize'=>$pagesize,
            'task_counts'=>task_service::dashboard_counts($companyuuid,$actor)];
    }
}
