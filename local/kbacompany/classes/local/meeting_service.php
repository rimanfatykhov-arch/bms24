<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Participant-scoped meetings and decisions.
 *
 * Meetings are governance records, discussions remain communication threads,
 * decisions remain separate objects, and execution converges into canonical BMS tasks.
 */
class meeting_service {
    public const CONTRACT_VERSION = '0.4.9';
    public const MEETING_STATUSES = ['draft', 'scheduled', 'inprogress', 'closed', 'cancelled'];
    public const AGENDA_STATUSES = ['open', 'discussed', 'deferred'];
    public const DECISION_STATUSES = ['proposed', 'accepted', 'rejected', 'superseded'];
    public const SOURCE_KINDS = ['manual', 'admin_project'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_meetings_decisions',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['Meeting', 'MeetingParticipant', 'MeetingAgendaItem', 'Decision'],
            'meeting_states' => self::MEETING_STATUSES,
            'agenda_states' => self::AGENDA_STATUSES,
            'decision_states' => self::DECISION_STATUSES,
            'source_kinds' => self::SOURCE_KINDS,
            'rules' => [
                'discussion_is_not_meeting',
                'meeting_is_not_decision',
                'decision_is_not_task',
                'meeting_visibility_is_explicit_participant_scoped',
                'meeting_has_traceable_discussion_thread',
                'meeting_may_originate_from_existing_discussion',
                'meeting_may_pin_exact_published_administrative_project_revision',
                'administrative_cycle_source_is_immutable_after_meeting_creation',
                'accepted_decision_can_create_one_or_more_canonical_tasks',
                'decision_task_keeps_source_traceability',
                'meeting_close_requires_minutes_and_resolved_agenda_and_decisions',
                'optimistic_meeting_revision',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('meeting_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) { self::fail('invalid'); }
        return $value;
    }

    private static function employees(string $companyuuid, int $actor): array {
        global $DB;
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        if (!isset($employees[$actor])) {
            $user = $DB->get_record('user', ['id' => $actor], 'id,firstname,lastname');
            if ($user) { $employees[$actor] = fullname($user); }
        }
        return $employees;
    }

    private static function require_employee(array $employees, int $userid): void {
        if ($userid <= 0 || !isset($employees[$userid])) { self::fail('employee'); }
    }

    private static function meeting(string $companyuuid, string $meetinguuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_meeting', ['companyuuid' => $companyuuid, 'uuid' => $meetinguuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    private static function participant(string $companyuuid, string $meetinguuid, int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_meetpart', [
            'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'userid' => $userid, 'active' => 1,
        ]) ?: null;
    }

    private static function require_participant(string $companyuuid, string $meetinguuid, int $userid): \stdClass {
        $part = self::participant($companyuuid, $meetinguuid, $userid);
        if (!$part) { self::fail('permission'); }
        return $part;
    }

    private static function can_govern(\stdClass $meeting, int $actor): bool {
        return (int)$meeting->organizerid === $actor || (int)$meeting->chairid === $actor ||
            (int)$meeting->secretaryid === $actor;
    }

    private static function require_govern(\stdClass $meeting, int $actor): void {
        self::require_participant((string)$meeting->companyuuid, (string)$meeting->uuid, $actor);
        if (!self::can_govern($meeting, $actor)) { self::fail('permission'); }
    }

    private static function sync_participant_roles(\stdClass $meeting): void {
        global $DB;
        $parts = $DB->get_records('local_kbacompany_meetpart', [
            'companyuuid' => $meeting->companyuuid, 'meetinguuid' => $meeting->uuid, 'active' => 1,
        ]);
        foreach ($parts as $part) {
            $role = 'member';
            if ((int)$part->userid === (int)$meeting->organizerid) { $role = 'organizer'; }
            else if ((int)$part->userid === (int)$meeting->chairid) { $role = 'chair'; }
            else if ((int)$part->userid === (int)$meeting->secretaryid) { $role = 'secretary'; }
            if ((string)$part->role !== $role) {
                $part->role = $role; $part->timemodified = time();
                $DB->update_record('local_kbacompany_meetpart', $part);
            }
        }
    }

    private static function sync_thread(\stdClass $meeting): void {
        global $DB;
        if ((string)$meeting->threaduuid === '') { return; }
        $thread = $DB->get_record('local_kbacompany_commthread', [
            'companyuuid' => $meeting->companyuuid, 'uuid' => $meeting->threaduuid,
        ]);
        if (!$thread) { return; }
        $thread->title = (string)$meeting->title;
        $thread->contextrevision = (int)$meeting->revision;
        $thread->timemodified = time();
        $DB->update_record('local_kbacompany_commthread', $thread);
    }

    private static function validate_times(int $startsat, int $endsat): void {
        if ($startsat < 0 || $endsat < 0 || ($startsat > 0 && $endsat > 0 && $endsat <= $startsat)) {
            self::fail('time');
        }
    }

    /** Validate an immutable Administrative Cycle source reference. */
    private static function cycle_source(string $companyuuid, int $actor, string $kind, string $uuid, int $revision): array {
        global $DB;
        $kind = $kind === '' ? 'manual' : $kind;
        if (!in_array($kind, self::SOURCE_KINDS, true)) { self::fail('source'); }
        if ($kind === 'manual') {
            if ($uuid !== '' || $revision !== 0) { self::fail('source'); }
            return ['kind' => 'manual', 'uuid' => '', 'revision' => 0, 'label' => '', 'stale' => false];
        }
        if (!administrative_scale_service::can_manage($companyuuid, $actor) || $uuid === '' || $revision <= 0) {
            self::fail('permission');
        }
        $row = $DB->get_record('local_kbacompany_adminitem', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision,
            'kind' => 'project', 'status' => 'published',
        ]);
        if (!$row) { self::fail('source'); }
        return ['kind' => 'admin_project', 'uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
            'label' => (string)$row->name, 'stale' => false];
    }

    /** Resolve a pinned source for display without changing the historic reference. */
    private static function source_context(\stdClass $meeting): array {
        global $DB;
        $kind = isset($meeting->sourcekind) ? (string)$meeting->sourcekind : 'manual';
        $uuid = isset($meeting->sourceuuid) ? (string)$meeting->sourceuuid : '';
        $revision = isset($meeting->sourcerevision) ? (int)$meeting->sourcerevision : 0;
        if ($kind !== 'admin_project' || $uuid === '' || $revision <= 0) {
            return ['kind' => 'manual', 'uuid' => '', 'revision' => 0, 'label' => '', 'stale' => false];
        }
        $source = $DB->get_record('local_kbacompany_adminitem', [
            'companyuuid' => $meeting->companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'kind' => 'project',
        ]);
        if (!$source) {
            return ['kind' => $kind, 'uuid' => $uuid, 'revision' => $revision, 'label' => '', 'stale' => true];
        }
        $rows = $DB->get_records('local_kbacompany_adminitem', [
            'companyuuid' => $meeting->companyuuid, 'uuid' => $uuid, 'kind' => 'project',
        ], 'revision DESC');
        $effective = null;
        foreach ($rows as $candidate) {
            if ($candidate->status === 'archived') { break; }
            if ($candidate->status === 'published') { $effective = $candidate; break; }
        }
        return ['kind' => $kind, 'uuid' => $uuid, 'revision' => $revision, 'label' => (string)$source->name,
            'stale' => !$effective || (int)$effective->revision !== $revision];
    }

    private static function source_thread_participants(string $companyuuid, string $threaduuid, int $actor): array {
        global $DB;
        if ($threaduuid === '') { return []; }
        $thread = $DB->get_record('local_kbacompany_commthread', ['companyuuid' => $companyuuid, 'uuid' => $threaduuid]);
        if (!$thread) { self::fail('source'); }
        $part = $DB->get_record('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $actor, 'active' => 1,
        ]);
        if (!$part) { self::fail('permission'); }
        $ids = [];
        foreach ($DB->get_records('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'active' => 1,
        ]) as $row) {
            $ids[] = (int)$row->userid;
        }
        return array_values(array_unique($ids));
    }

    /** Create a participant-scoped meeting and its own object discussion thread. */
    public static function create(string $companyuuid, int $actor, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $actor);

        $title = self::text($data, 'title', 255, true);
        $purpose = self::text($data, 'purpose', 12000, true);
        $location = self::text($data, 'location', 255, false);
        $resourceuuid = self::text($data, 'resourceuuid', 36, false);
        $onlineurl = meeting_resource_service::validate_online_url((string)($data['onlineurl'] ?? ''));
        $startsat = max(0, (int)($data['startsat'] ?? 0));
        $endsat = max(0, (int)($data['endsat'] ?? 0));
        self::validate_times($startsat, $endsat);
        $chairid = (int)($data['chairid'] ?? $actor);
        $secretaryid = (int)($data['secretaryid'] ?? 0);
        self::require_employee($employees, $chairid);
        if ($secretaryid > 0) { self::require_employee($employees, $secretaryid); }

        $sourcekind = self::text($data, 'sourcekind', 20, false);
        $sourceuuid = self::text($data, 'sourceuuid', 36, false);
        $sourcerevision = max(0, (int)($data['sourcerevision'] ?? 0));
        $source = self::cycle_source($companyuuid, $actor, $sourcekind, $sourceuuid, $sourcerevision);
        $sourcethreaduuid = self::text($data, 'sourcethreaduuid', 36, false);
        $participantids = array_values(array_unique(array_filter(array_map('intval', $data['participants'] ?? []))));
        if ($sourcethreaduuid !== '') {
            $participantids = array_values(array_unique(array_merge($participantids,
                self::source_thread_participants($companyuuid, $sourcethreaduuid, $actor))));
        }
        $participantids[] = $actor;
        $participantids[] = $chairid;
        if ($secretaryid > 0) { $participantids[] = $secretaryid; }
        $participantids = array_values(array_unique(array_filter($participantids)));
        foreach ($participantids as $userid) { self::require_employee($employees, $userid); }
        $otherparticipants = array_values(array_diff($participantids, [$actor]));

        $resourcelock = meeting_resource_service::acquire_booking_lock($companyuuid, $resourceuuid);
        try {
            meeting_resource_service::assert_available($companyuuid, $actor, $resourceuuid, $startsat, $endsat);
            meeting_resource_service::assert_capacity($companyuuid, $actor, $resourceuuid, count($participantids));

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('meeting:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $uuid = self::uuid();
            $now = time();
            $status = $startsat > 0 ? 'scheduled' : 'draft';
            $DB->insert_record('local_kbacompany_meeting', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'title' => $title, 'purpose' => $purpose,
                'status' => $status, 'startsat' => $startsat, 'endsat' => $endsat, 'location' => $location,
                'resourceuuid' => $resourceuuid, 'onlineurl' => $onlineurl,
                'sourcekind' => $source['kind'], 'sourceuuid' => $source['uuid'],
                'sourcerevision' => $source['revision'], 'sourcethreaduuid' => $sourcethreaduuid,
                'threaduuid' => '', 'organizerid' => $actor,
                'chairid' => $chairid, 'secretaryid' => $secretaryid, 'minutes' => '', 'result' => '',
                'revision' => 1, 'closedby' => 0, 'closedat' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            foreach ($participantids as $userid) {
                $role = 'member';
                if ($userid === $actor) { $role = 'organizer'; }
                else if ($userid === $chairid) { $role = 'chair'; }
                else if ($userid === $secretaryid) { $role = 'secretary'; }
                $DB->insert_record('local_kbacompany_meetpart', (object)[
                    'companyuuid' => $companyuuid, 'meetinguuid' => $uuid, 'userid' => $userid,
                    'role' => $role, 'attendancestatus' => 'invited', 'required' => 1, 'active' => 1,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
            }
            $threadparticipants = array_values(array_diff($participantids, [$actor]));
            $threaduuid = communication_service::create_thread($companyuuid, $actor, [
                'threadtype' => 'object', 'title' => $title, 'contextkind' => 'meeting', 'contextuuid' => $uuid,
                'contextrevision' => 1, 'participants' => $threadparticipants,
            ]);
            $meeting = self::meeting($companyuuid, $uuid);
            $meeting->threaduuid = $threaduuid;
            $DB->update_record('local_kbacompany_meeting', $meeting);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
        } finally {
            if ($resourcelock) { $resourcelock->release(); }
        }
    }

    /** Edit meeting definition before closure; draft/scheduled state follows whether a start time is set. */
    public static function save(string $companyuuid, int $actor, string $meetinguuid, int $expectedrevision,
            array $data): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_govern($meeting, $actor);
        if ((int)$meeting->revision !== $expectedrevision) { self::fail('revision'); }
        if (in_array($meeting->status, ['closed', 'cancelled'], true)) { self::fail('state'); }
        $title = self::text($data, 'title', 255, true);
        $purpose = self::text($data, 'purpose', 12000, true);
        $location = self::text($data, 'location', 255, false);
        $resourceuuid = self::text($data, 'resourceuuid', 36, false);
        $onlineurl = meeting_resource_service::validate_online_url((string)($data['onlineurl'] ?? ''));
        $startsat = max(0, (int)($data['startsat'] ?? 0));
        $endsat = max(0, (int)($data['endsat'] ?? 0));
        self::validate_times($startsat, $endsat);
        $chairid = (int)($data['chairid'] ?? $meeting->chairid);
        $secretaryid = (int)($data['secretaryid'] ?? $meeting->secretaryid);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $chairid);
        if ($secretaryid > 0) { self::require_employee($employees, $secretaryid); }
        if (!self::participant($companyuuid, $meetinguuid, $chairid) ||
                ($secretaryid > 0 && !self::participant($companyuuid, $meetinguuid, $secretaryid))) {
            self::fail('participantrole');
        }
        $participantcount = $DB->count_records('local_kbacompany_meetpart', [
            'companyuuid'=>$companyuuid, 'meetinguuid'=>$meetinguuid, 'active'=>1,
        ]);
        $resourcelock = meeting_resource_service::acquire_booking_lock($companyuuid, $resourceuuid);
        try {
            $freshmeeting = self::meeting($companyuuid, $meetinguuid);
            if ((int)$freshmeeting->revision !== $expectedrevision) { self::fail('revision'); }
            meeting_resource_service::assert_available($companyuuid, $actor, $resourceuuid, $startsat, $endsat, $meetinguuid);
            meeting_resource_service::assert_capacity($companyuuid, $actor, $resourceuuid, $participantcount);
            $meeting = $freshmeeting;
        $meeting->title = $title; $meeting->purpose = $purpose; $meeting->location = $location;
        $meeting->resourceuuid = $resourceuuid; $meeting->onlineurl = $onlineurl;
        $meeting->startsat = $startsat; $meeting->endsat = $endsat;
        $meeting->chairid = $chairid; $meeting->secretaryid = $secretaryid;
        if (in_array($meeting->status, ['draft', 'scheduled'], true)) {
            $meeting->status = $startsat > 0 ? 'scheduled' : 'draft';
        }
        $meeting->revision = (int)$meeting->revision + 1; $meeting->timemodified = time();
        $DB->update_record('local_kbacompany_meeting', $meeting);
        self::sync_participant_roles($meeting);
        self::sync_thread($meeting);
        } finally {
            if ($resourcelock) { $resourcelock->release(); }
        }
    }

    /** Add a participant to both the meeting and its linked discussion. */
    public static function add_participant(string $companyuuid, int $actor, string $meetinguuid, int $userid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_participant($companyuuid, $meetinguuid, $actor);
        if ((int)$meeting->organizerid !== $actor) { self::fail('permission'); }
        if (in_array($meeting->status, ['closed', 'cancelled'], true)) { self::fail('state'); }
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $userid);
        $resourcelock = meeting_resource_service::acquire_booking_lock($companyuuid, (string)($meeting->resourceuuid ?? ''));
        try {
            $currentcount = $DB->count_records('local_kbacompany_meetpart', [
                'companyuuid'=>$companyuuid, 'meetinguuid'=>$meetinguuid, 'active'=>1,
            ]);
            $existingactive = $DB->record_exists('local_kbacompany_meetpart', [
                'companyuuid'=>$companyuuid, 'meetinguuid'=>$meetinguuid, 'userid'=>$userid, 'active'=>1,
            ]);
            meeting_resource_service::assert_capacity($companyuuid, $actor, (string)($meeting->resourceuuid ?? ''),
                $currentcount + ($existingactive ? 0 : 1));
        $existing = $DB->get_record('local_kbacompany_meetpart', [
            'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'userid' => $userid,
        ]);
        $now = time();
        if ($existing) {
            $existing->active = 1; $existing->timemodified = $now;
            $DB->update_record('local_kbacompany_meetpart', $existing);
        } else {
            $DB->insert_record('local_kbacompany_meetpart', (object)[
                'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'userid' => $userid,
                'role' => 'member', 'attendancestatus' => 'invited', 'required' => 1, 'active' => 1,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        if ((string)$meeting->threaduuid !== '') {
            communication_service::add_participant($companyuuid, $actor, (string)$meeting->threaduuid, $userid);
        }
        $meeting->revision = (int)$meeting->revision + 1; $meeting->timemodified = $now;
        $DB->update_record('local_kbacompany_meeting', $meeting);
        self::sync_thread($meeting);
        } finally {
            if ($resourcelock) { $resourcelock->release(); }
        }
    }

    /** Update attendance state for oneself or, for governance roles, another participant. */
    public static function set_attendance(string $companyuuid, int $actor, string $meetinguuid, int $userid,
            string $status): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_participant($companyuuid, $meetinguuid, $actor);
        if (!in_array($status, ['invited', 'accepted', 'declined', 'attended', 'absent'], true)) { self::fail('invalid'); }
        if ($userid !== $actor && !self::can_govern($meeting, $actor)) { self::fail('permission'); }
        $part = self::participant($companyuuid, $meetinguuid, $userid);
        if (!$part) { self::fail('reference'); }
        $part->attendancestatus = $status; $part->timemodified = time();
        $DB->update_record('local_kbacompany_meetpart', $part);
    }

    /** Update meeting lifecycle with optimistic revision control. */
    public static function transition(string $companyuuid, int $actor, string $meetinguuid, int $expectedrevision,
            string $action, array $data = []): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_govern($meeting, $actor);
        if ((int)$meeting->revision !== $expectedrevision) { self::fail('revision'); }
        $old = (string)$meeting->status;
        $new = $old;
        if ($action === 'start' && in_array($old, ['draft', 'scheduled'], true)) { $new = 'inprogress'; }
        else if ($action === 'cancel' && !in_array($old, ['closed', 'cancelled'], true)) { $new = 'cancelled'; }
        else if ($action === 'close' && in_array($old, ['scheduled', 'inprogress'], true)) {
            $minutes = self::text($data, 'minutes', 30000, true);
            $result = self::text($data, 'result', 12000, false);
            $openagenda = $DB->count_records('local_kbacompany_meetagenda', [
                'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'status' => 'open',
            ]);
            $proposed = $DB->count_records('local_kbacompany_decision', [
                'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'status' => 'proposed',
            ]);
            if ($openagenda > 0 || $proposed > 0) { self::fail('unresolved'); }
            $meeting->minutes = $minutes; $meeting->result = $result;
            $meeting->closedby = $actor; $meeting->closedat = time();
            $new = 'closed';
        } else { self::fail('state'); }
        $meeting->status = $new; $meeting->revision = (int)$meeting->revision + 1; $meeting->timemodified = time();
        $DB->update_record('local_kbacompany_meeting', $meeting);
        self::sync_thread($meeting);
    }

    public static function add_agenda(string $companyuuid, int $actor, string $meetinguuid, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_govern($meeting, $actor);
        if (in_array($meeting->status, ['closed', 'cancelled'], true)) { self::fail('state'); }
        $title = self::text($data, 'title', 255, true);
        $expectedproduct = self::text($data, 'expectedproduct', 12000, false);
        $ownerid = (int)($data['ownerid'] ?? $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $ownerid);
        $sortorder = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) + 10 FROM {local_kbacompany_meetagenda}
            WHERE companyuuid = :c AND meetinguuid = :m', ['c' => $companyuuid, 'm' => $meetinguuid]);
        $uuid = self::uuid(); $now = time();
        $DB->insert_record('local_kbacompany_meetagenda', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'meetinguuid' => $meetinguuid,
            'sortorder' => $sortorder, 'title' => $title, 'expectedproduct' => $expectedproduct,
            'ownerid' => $ownerid, 'status' => 'open', 'notes' => '', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $uuid;
    }

    public static function set_agenda_status(string $companyuuid, int $actor, string $meetinguuid, string $agendauuid,
            string $status, string $notes = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_participant($companyuuid, $meetinguuid, $actor);
        if (!in_array($status, self::AGENDA_STATUSES, true)) { self::fail('invalid'); }
        $row = $DB->get_record('local_kbacompany_meetagenda', [
            'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'uuid' => $agendauuid,
        ]);
        if (!$row) { self::fail('reference'); }
        if ((int)$row->ownerid !== $actor && !self::can_govern($meeting, $actor)) { self::fail('permission'); }
        if (\core_text::strlen($notes) > 12000) { self::fail('invalid'); }
        $row->status = $status; $row->notes = trim($notes); $row->timemodified = time();
        $DB->update_record('local_kbacompany_meetagenda', $row);
    }

    /** Propose a decision. Acceptance/rejection is a separate governance action. */
    public static function propose_decision(string $companyuuid, int $actor, string $meetinguuid, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $meeting = self::meeting($companyuuid, $meetinguuid);
        self::require_participant($companyuuid, $meetinguuid, $actor);
        if (in_array($meeting->status, ['closed', 'cancelled'], true)) { self::fail('state'); }
        $title = self::text($data, 'title', 255, true);
        $statement = self::text($data, 'statement', 20000, true);
        $rationale = self::text($data, 'rationale', 12000, false);
        $expectedresult = self::text($data, 'expectedresult', 12000, false);
        $agendauuid = self::text($data, 'agendauuid', 36, false);
        if ($agendauuid !== '') {
            $agenda = $DB->get_record('local_kbacompany_meetagenda', [
                'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid, 'uuid' => $agendauuid,
            ]);
            if (!$agenda) { self::fail('reference'); }
        }
        $ownerid = (int)($data['ownerid'] ?? $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $ownerid);
        $uuid = self::uuid(); $now = time();
        $DB->insert_record('local_kbacompany_decision', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'meetinguuid' => $meetinguuid,
            'agendauuid' => $agendauuid, 'threaduuid' => (string)$meeting->threaduuid, 'title' => $title,
            'statement' => $statement, 'rationale' => $rationale, 'expectedresult' => $expectedresult,
            'ownerid' => $ownerid, 'proposedby' => $actor, 'status' => 'proposed', 'decidedby' => 0,
            'decidedat' => 0, 'revision' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $uuid;
    }

    /** Accept or reject a proposed decision. Accepted content remains immutable; status is revised. */
    public static function decide(string $companyuuid, int $actor, string $decisionuuid, string $action,
            int $expectedrevision): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $decision = $DB->get_record('local_kbacompany_decision', ['companyuuid' => $companyuuid, 'uuid' => $decisionuuid]);
        if (!$decision) { self::fail('reference'); }
        $meeting = self::meeting($companyuuid, (string)$decision->meetinguuid);
        self::require_govern($meeting, $actor);
        if ($decision->status !== 'proposed' || (int)$decision->revision !== $expectedrevision) { self::fail('revision'); }
        if ($action !== 'accept' && $action !== 'reject') { self::fail('invalid'); }
        $decision->status = $action === 'accept' ? 'accepted' : 'rejected';
        $decision->decidedby = $actor; $decision->decidedat = time();
        $decision->revision = (int)$decision->revision + 1; $decision->timemodified = time();
        $DB->update_record('local_kbacompany_decision', $decision);
    }

    /** Create a canonical execution task from one accepted decision. */
    public static function create_task_from_decision(string $companyuuid, int $actor, string $decisionuuid,
            array $taskdata): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $decision = $DB->get_record('local_kbacompany_decision', ['companyuuid' => $companyuuid, 'uuid' => $decisionuuid]);
        if (!$decision || $decision->status !== 'accepted') { self::fail('decisionstate'); }
        self::require_participant($companyuuid, (string)$decision->meetinguuid, $actor);
        return task_service::create_from_decision($companyuuid, $actor, $decisionuuid, $taskdata);
    }

    private static function dto(\stdClass $meeting, int $actor, array $employees, bool $includetasks = true): array {
        global $DB;
        $participants = [];
        foreach ($DB->get_records('local_kbacompany_meetpart', [
            'companyuuid' => $meeting->companyuuid, 'meetinguuid' => $meeting->uuid, 'active' => 1,
        ], 'id ASC') as $part) {
            $participants[] = [
                'user_id' => (int)$part->userid, 'name' => $employees[(int)$part->userid] ?? '',
                'role' => (string)$part->role, 'attendance_status' => (string)$part->attendancestatus,
                'required' => !empty($part->required),
            ];
        }
        $agenda = [];
        foreach ($DB->get_records('local_kbacompany_meetagenda', [
            'companyuuid' => $meeting->companyuuid, 'meetinguuid' => $meeting->uuid,
        ], 'sortorder ASC, id ASC') as $row) {
            $agenda[] = [
                'uuid' => (string)$row->uuid, 'title' => (string)$row->title,
                'expected_product' => (string)$row->expectedproduct, 'owner_id' => (int)$row->ownerid,
                'owner_name' => $employees[(int)$row->ownerid] ?? '', 'status' => (string)$row->status,
                'notes' => (string)$row->notes,
            ];
        }
        $decisions = [];
        foreach ($DB->get_records('local_kbacompany_decision', [
            'companyuuid' => $meeting->companyuuid, 'meetinguuid' => $meeting->uuid,
        ], 'id ASC') as $row) {
            $tasks = $includetasks ? $DB->get_records('local_kbacompany_task', [
                'companyuuid' => $meeting->companyuuid, 'sourcekind' => 'decision', 'sourceuuid' => $row->uuid,
            ], 'id ASC') : [];
            $taskdtos = [];
            foreach ($tasks as $task) { $taskdtos[] = task_service::row_dto($task, $employees); }
            $decisions[] = [
                'uuid' => (string)$row->uuid, 'agenda_uuid' => (string)$row->agendauuid,
                'title' => (string)$row->title, 'statement' => (string)$row->statement,
                'rationale' => (string)$row->rationale, 'expected_result' => (string)$row->expectedresult,
                'owner_id' => (int)$row->ownerid, 'owner_name' => $employees[(int)$row->ownerid] ?? '',
                'proposed_by' => (int)$row->proposedby, 'proposed_by_name' => $employees[(int)$row->proposedby] ?? '',
                'status' => (string)$row->status, 'decided_by' => (int)$row->decidedby,
                'decided_by_name' => $employees[(int)$row->decidedby] ?? '', 'decided_at' => (int)$row->decidedat,
                'revision' => (int)$row->revision, 'tasks' => $taskdtos,
            ];
        }
        $workflow = [
            'agenda_total' => count($agenda),
            'agenda_open' => count(array_filter($agenda, static function(array $item): bool {
                return (string)$item['status'] === 'open';
            })),
            'decisions_total' => count($decisions),
            'decisions_proposed' => count(array_filter($decisions, static function(array $item): bool {
                return (string)$item['status'] === 'proposed';
            })),
            'decisions_accepted' => count(array_filter($decisions, static function(array $item): bool {
                return (string)$item['status'] === 'accepted';
            })),
            'tasks_total' => array_sum(array_map(static function(array $item): int {
                return count((array)$item['tasks']);
            }, $decisions)),
            'accepted_without_task' => count(array_filter($decisions, static function(array $item): bool {
                return (string)$item['status'] === 'accepted' && empty($item['tasks']);
            })),
        ];
        $workflow['ready_to_close'] = in_array((string)$meeting->status, ['scheduled', 'inprogress'], true) &&
            $workflow['agenda_open'] === 0 && $workflow['decisions_proposed'] === 0;

        $source = self::source_context($meeting);
        return [
            'uuid' => (string)$meeting->uuid, 'title' => (string)$meeting->title, 'purpose' => (string)$meeting->purpose,
            'status' => (string)$meeting->status, 'starts_at' => (int)$meeting->startsat, 'ends_at' => (int)$meeting->endsat,
            'location' => (string)$meeting->location, 'resource_uuid' => (string)($meeting->resourceuuid ?? ''),
            'online_url' => (string)($meeting->onlineurl ?? ''), 'source_kind' => $source['kind'],
            'source_uuid' => $source['uuid'], 'source_revision' => $source['revision'],
            'source_label' => $source['label'], 'source_stale' => $source['stale'],
            'source_thread_uuid' => (string)$meeting->sourcethreaduuid,
            'thread_uuid' => (string)$meeting->threaduuid, 'organizer_id' => (int)$meeting->organizerid,
            'organizer_name' => $employees[(int)$meeting->organizerid] ?? '', 'chair_id' => (int)$meeting->chairid,
            'chair_name' => $employees[(int)$meeting->chairid] ?? '', 'secretary_id' => (int)$meeting->secretaryid,
            'secretary_name' => $employees[(int)$meeting->secretaryid] ?? '', 'minutes' => (string)$meeting->minutes,
            'result' => (string)$meeting->result, 'revision' => (int)$meeting->revision,
            'closed_by' => (int)$meeting->closedby, 'closed_at' => (int)$meeting->closedat,
            'can_govern' => self::can_govern($meeting, $actor), 'can_organize' => (int)$meeting->organizerid === $actor,
            'participants' => $participants,
            'agenda' => $agenda, 'decisions' => $decisions, 'workflow' => $workflow,
            'time_created' => (int)$meeting->timecreated, 'time_modified' => (int)$meeting->timemodified,
        ];
    }

    /**
     * Bounded participant-scoped planning-meeting workflow projection.
     *
     * Used by Calendar only as a read model. Meeting/agenda/decision/task
     * mutations remain in their canonical services and controllers.
     */
    public static function workflow_projection(string $companyuuid, int $actor, int $fromts, int $tots, int $limit = 6): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($fromts <= 0 || $tots < $fromts) { throw new \moodle_exception('invalidparameter'); }
        $limit = max(1, min(12, $limit));
        $employees = self::employees($companyuuid, $actor);
        $sql = "SELECT m.*
                  FROM {local_kbacompany_meeting} m
                 WHERE m.companyuuid = :company
                   AND m.startsat BETWEEN :fromts AND :tots
                   AND m.status <> :cancelled
                   AND EXISTS (SELECT p.id FROM {local_kbacompany_meetpart} p
                                WHERE p.companyuuid = m.companyuuid AND p.meetinguuid = m.uuid
                                  AND p.userid = :actor AND p.active = 1)
              ORDER BY m.startsat ASC, m.id ASC";
        $rows = $DB->get_records_sql($sql, [
            'company'=>$companyuuid, 'fromts'=>$fromts, 'tots'=>$tots, 'cancelled'=>'cancelled', 'actor'=>$actor,
        ], 0, $limit);
        $out = [];
        foreach ($rows as $row) {
            $dto = self::dto($row, $actor, $employees, true);
            $out[] = [
                'uuid'=>$dto['uuid'], 'title'=>$dto['title'], 'status'=>$dto['status'],
                'starts_at'=>$dto['starts_at'], 'ends_at'=>$dto['ends_at'], 'location'=>$dto['location'],
                'resource_uuid'=>$dto['resource_uuid'], 'online_url'=>$dto['online_url'],
                'chair_name'=>$dto['chair_name'], 'can_govern'=>$dto['can_govern'],
                'workflow'=>$dto['workflow'],
            ];
        }
        return $out;
    }

    /** Participant-scoped calendar projection for the BMS Calendar. */
    public static function calendar_projection(string $companyuuid, int $actor, int $fromts, int $tots): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($fromts <= 0 || $tots < $fromts) { throw new \moodle_exception('invalidparameter'); }
        $sql = "SELECT m.uuid,m.title,m.status,m.startsat,m.endsat,m.location,m.resourceuuid,m.onlineurl
                  FROM {local_kbacompany_meeting} m
                 WHERE m.companyuuid = :company
                   AND m.startsat BETWEEN :fromts AND :tots
                   AND EXISTS (SELECT p.id FROM {local_kbacompany_meetpart} p
                                WHERE p.companyuuid = m.companyuuid AND p.meetinguuid = m.uuid
                                  AND p.userid = :actor AND p.active = 1)
              ORDER BY m.startsat,m.id";
        $records = array_values($DB->get_records_sql($sql, ['company'=>$companyuuid,'fromts'=>$fromts,'tots'=>$tots,'actor'=>$actor]));
        $participants = [];
        if ($records) {
            $uuids = array_map(static function($row): string { return (string)$row->uuid; }, $records);
            list($insql, $inparams) = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'calmeet');
            $params = ['companyparts'=>$companyuuid] + $inparams;
            $partsql = "SELECT p.id,p.meetinguuid,p.userid FROM {local_kbacompany_meetpart} p
"
                . " WHERE p.companyuuid=:companyparts AND p.active=1 AND p.meetinguuid $insql";
            foreach ($DB->get_records_sql($partsql, $params) as $part) {
                $participants[(string)$part->meetinguuid][] = (int)$part->userid;
            }
        }
        $rows = [];
        foreach ($records as $row) {
            $rows[] = [
                'uuid'=>(string)$row->uuid, 'title'=>(string)$row->title, 'status'=>(string)$row->status,
                'starts_at'=>(int)$row->startsat, 'ends_at'=>(int)$row->endsat, 'location'=>(string)$row->location,
                'resource_uuid'=>(string)($row->resourceuuid ?? ''), 'online_url'=>(string)($row->onlineurl ?? ''),
                'participant_ids'=>array_values(array_unique($participants[(string)$row->uuid] ?? [])),
            ];
        }
        return $rows;
    }

    /** Participant-scoped list/detail context. */
    public static function context(string $companyuuid, int $actor, string $selected = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $parts = $DB->get_records('local_kbacompany_meetpart', [
            'companyuuid' => $companyuuid, 'userid' => $actor, 'active' => 1,
        ], 'id DESC');
        $meetings = [];
        foreach ($parts as $part) {
            $meeting = $DB->get_record('local_kbacompany_meeting', [
                'companyuuid' => $companyuuid, 'uuid' => $part->meetinguuid,
            ]);
            if ($meeting) { $meetings[] = self::dto($meeting, $actor, $employees); }
        }
        usort($meetings, static function(array $a, array $b): int {
            $at = $a['starts_at'] ?: $a['time_created'];
            $bt = $b['starts_at'] ?: $b['time_created'];
            return $bt <=> $at;
        });
        $selectedmeeting = null;
        if ($selected !== '') {
            foreach ($meetings as $meeting) {
                if ($meeting['uuid'] === $selected) { $selectedmeeting = $meeting; break; }
            }
            if ($selectedmeeting === null) { self::fail('permission'); }
        }
        $counts = ['total' => count($meetings), 'scheduled' => 0, 'inprogress' => 0, 'open_decisions' => 0];
        foreach ($meetings as $meeting) {
            if ($meeting['status'] === 'scheduled') { $counts['scheduled']++; }
            if ($meeting['status'] === 'inprogress') { $counts['inprogress']++; }
            foreach ($meeting['decisions'] as $decision) {
                if ($decision['status'] === 'proposed') { $counts['open_decisions']++; }
            }
        }
        return [
            'company' => $company, 'employees' => $employees, 'meetings' => $meetings,
            'selected' => $selectedmeeting, 'counts' => $counts, 'contract' => self::contract(),
        ];
    }

    /** Read meetings for a single project, never widening explicit meeting participation. */
    public static function for_project(string $companyuuid, int $actor, string $projectuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($projectuuid === '') { self::fail('permission'); }
        $employees = self::employees($companyuuid, $actor);
        $sql = "SELECT m.* FROM {local_kbacompany_meeting} m
                 WHERE m.companyuuid = :company AND m.sourcekind = :kind AND m.sourceuuid = :project
                   AND EXISTS (SELECT p.id FROM {local_kbacompany_meetpart} p
                                WHERE p.companyuuid = m.companyuuid AND p.meetinguuid = m.uuid
                                  AND p.userid = :actor AND p.active = 1)
              ORDER BY m.startsat DESC, m.id DESC";
        $out = [];
        foreach ($DB->get_records_sql($sql, [
                'company' => $companyuuid, 'kind' => 'admin_project', 'project' => $projectuuid, 'actor' => $actor]) as $row) {
            $out[] = self::dto($row, $actor, $employees, false);
        }
        return $out;
    }


}
