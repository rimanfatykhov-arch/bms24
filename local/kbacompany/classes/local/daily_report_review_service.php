<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0009 R2: review of saved daily reports.
 *
 * Only the reviewer stored on the current version decides, never the author (no self review). A decision is bound
 * to one version: accept with a rating (positive / negative / without rating, owner decision В3) or return with a
 * reason. Rating changes and revoked acceptance are new append-only rows that supersede the previous one. A company
 * administrator (people management in this company) reads everything read-only and may reassign a pending report,
 * which is journaled. Accepting a report never accepts or closes tasks. Company scope is applied in SQL.
 */
final class daily_report_review_service {
    public const RATINGS = ['positive', 'negative', 'none'];
    public const PAGE_SIZE = 25;
    public const QUEUES = ['pending', 'returned', 'accepted'];

    public static function is_admin(string $companyuuid, int $actor): bool {
        return permission_service::can_manage_people($companyuuid, $actor);
    }

    private static function report(string $companyuuid, string $reportuuid): \stdClass {
        global $DB;
        $report = $DB->get_record('local_kbacompany_dreport', ['companyuuid' => $companyuuid, 'uuid' => $reportuuid]);
        if (!$report) {
            // Unknown and foreign reports look the same.
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        return $report;
    }

    private static function version(string $companyuuid, \stdClass $report, string $versionuuid): \stdClass {
        global $DB;
        $version = $DB->get_record('local_kbacompany_dreportver',
            ['companyuuid' => $companyuuid, 'reportuuid' => $report->uuid, 'uuid' => $versionuuid]);
        if (!$version) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        return $version;
    }

    private static function member(string $companyuuid, int $actor): string {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        return $companyuuid;
    }

    /** Was this user the stored reviewer of any version of the report (В6: earlier versions stay with that reviewer)? */
    private static function reviewed_by(string $companyuuid, \stdClass $report, int $actor): bool {
        global $DB;
        return $actor > 0 && $DB->record_exists('local_kbacompany_dreportver',
            ['companyuuid' => $companyuuid, 'reportuuid' => $report->uuid, 'reviewerid' => $actor]);
    }

    /** Reviewer queue (own versions) or, for an administrator, the "without reviewer" list. */
    public static function queue(string $companyuuid, int $actor, string $queue, int $page = 0): array {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        if ($page < 0 || $page > 100000) {
            throw new \moodle_exception('invalidparameter');
        }
        $params = ['c' => $companyuuid];
        if ($queue === 'unassigned') {
            if (!self::is_admin($companyuuid, $actor)) {
                throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
            }
            $where = "r.status = 'submitted' AND v.reviewerid = 0";
        } else if (in_array($queue, self::QUEUES, true)) {
            $status = ['pending' => "r.status = 'submitted'", 'returned' => "(r.status = 'returned' OR
                (r.status = 'draft' AND r.currentversionuuid <> ''))", 'accepted' => "r.status = 'accepted'"][$queue];
            $where = $status . ' AND v.reviewerid = :actor';
            $params['actor'] = $actor;
        } else {
            throw new \moodle_exception('invalidparameter');
        }
        $from = "FROM {local_kbacompany_dreport} r JOIN {local_kbacompany_dreportver} v
            ON v.companyuuid = r.companyuuid AND v.uuid = r.currentversionuuid
            WHERE r.companyuuid = :c AND " . $where . ' AND ' .
            membership_access_service::canonical_sql('r.companyuuid', $actor);
        $total = $DB->count_records_sql("SELECT COUNT(r.id) {$from}", $params);
        $rows = $DB->get_records_sql("SELECT r.id, r.uuid, r.userid, r.reportday, r.status, v.uuid AS versionuuid,
                v.versionno, v.submittedat, v.reviewerid {$from} ORDER BY v.submittedat ASC, r.id ASC",
            $params, $page * self::PAGE_SIZE, self::PAGE_SIZE);
        return ['total' => (int)$total, 'page' => $page, 'queue' => $queue, 'items' => array_values($rows)];
    }

    /** Report card for a reviewer of any of its versions, or read-only for an administrator. Authors use their own view. */
    public static function card(string $companyuuid, int $actor, string $reportuuid): array {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        $report = self::report($companyuuid, $reportuuid);
        $admin = self::is_admin($companyuuid, $actor);
        if ((int)$report->userid === $actor || (!$admin && !self::reviewed_by($companyuuid, $report, $actor))) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        $versions = array_values($DB->get_records_select('local_kbacompany_dreportver',
            'companyuuid = :c AND reportuuid = :r AND state <> :draft',
            ['c' => $companyuuid, 'r' => $report->uuid, 'draft' => 'draft'], 'versionno DESC'));
        $links = []; $reviews = [];
        foreach ($versions as $version) {
            $links[$version->uuid] = array_values($DB->get_records('local_kbacompany_dreportlink',
                ['companyuuid' => $companyuuid, 'versionuuid' => $version->uuid], 'id ASC'));
        }
        foreach ($DB->get_records('local_kbacompany_dreportrev', ['companyuuid' => $companyuuid,
                'reportuuid' => $report->uuid], 'timecreated ASC, id ASC') as $row) {
            $reviews[$row->versionuuid][] = $row;
        }
        $current = $versions[0] ?? null;
        $isreviewer = $current && (int)$current->reviewerid === $actor && (int)$report->userid !== $actor;
        $last = $current ? self::last_decision($companyuuid, $current->uuid) : null;
        return ['report' => $report, 'versions' => $versions, 'links' => $links, 'reviews' => $reviews,
            'author' => \core_user::get_user((int)$report->userid), 'admin' => $admin,
            'can_decide' => $isreviewer && $report->status === 'submitted' &&
                $report->currentversionuuid === $current->uuid,
            'can_change' => $isreviewer && $report->status === 'accepted' && $last &&
                (int)$last->reviewerid === $actor,
            'can_reassign' => $admin && $report->status === 'submitted'];
    }

    /** The reviewer tab is shown to stored reviewers and to company administrators only. */
    public static function has_review_access(string $companyuuid, int $actor): bool {
        global $DB;
        $companyuuid = self::member($companyuuid, $actor);
        return self::is_admin($companyuuid, $actor) || $DB->record_exists_select('local_kbacompany_dreportver',
            'companyuuid = :c AND reviewerid = :u AND state <> :draft', ['c' => $companyuuid, 'u' => $actor, 'draft' => 'draft']);
    }

    /** Eligible reviewers an administrator may pick: other active, non-revoked members of this company. */
    public static function reviewer_candidates(string $companyuuid, int $actor, int $author): array {
        $companyuuid = self::member($companyuuid, $actor);
        if (!self::is_admin($companyuuid, $actor)) {
            return [];
        }
        $out = [];
        try {
            $employees = organization_service::organization_context($companyuuid, $actor)['employees'] ?? [];
        } catch (\moodle_exception $e) {
            $employees = [];
        }
        foreach ($employees as $employee) {
            $id = (int)$employee['user_id'];
            if (daily_report_store_service::eligible_reviewer($companyuuid, $id, $author)) {
                $out[$id] = (string)$employee['full_name'];
            }
        }
        asort($out);
        return $out;
    }

    private static function last_decision(string $companyuuid, string $versionuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_dreportrev', ['companyuuid' => $companyuuid,
            'versionuuid' => $versionuuid], 'timecreated DESC, id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function lock(string $companyuuid, \stdClass $report): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('dreport:' .
            sha1($companyuuid . "\n" . (int)$report->userid . "\n" . $report->reportday), 10);
        if (!$lock) {
            throw new \moodle_exception('dailyreports_busy', 'local_kbacompany');
        }
        return $lock;
    }

    private static function requestkey(string $key): string {
        $key = strtolower(trim($key));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            throw new \moodle_exception('invalidparameter');
        }
        return $key;
    }

    /**
     * Decide on the current version. 'accepted' needs a rating (negative needs a comment); 'returned' needs a reason.
     * From 'accepted' the same reviewer may change the rating or revoke acceptance (returned); both supersede the
     * previous decision with a mandatory reason. A repeated request key returns the first decision unchanged.
     */
    public static function decide(string $companyuuid, int $actor, string $reportuuid, string $versionuuid,
            string $decision, string $rating, string $comment, string $requestkey): array {
        global $DB;
        daily_report_store_service::require_enabled();
        $companyuuid = self::member($companyuuid, $actor);
        $requestkey = self::requestkey($requestkey);
        $comment = trim(str_replace("\r\n", "\n", $comment));
        if (\core_text::strlen($comment) > daily_report_store_service::MAX_TEXT) {
            throw new \moodle_exception('dailyreports_texttoolong', 'local_kbacompany');
        }
        if (!in_array($decision, ['accepted', 'returned'], true)) {
            throw new \moodle_exception('invalidparameter');
        }
        if ($decision === 'accepted') {
            if (!in_array($rating, self::RATINGS, true)) {
                throw new \moodle_exception('dailyreports_ratingrequired', 'local_kbacompany');
            }
            if ($rating === 'negative' && $comment === '') {
                throw new \moodle_exception('dailyreports_commentrequired', 'local_kbacompany');
            }
        } else {
            $rating = '';
            if ($comment === '') {
                throw new \moodle_exception('dailyreports_reasonrequired', 'local_kbacompany');
            }
        }
        $report = self::report($companyuuid, $reportuuid);
        $lock = self::lock($companyuuid, $report);
        try {
            $replay = $DB->get_record('local_kbacompany_dreportrev', ['companyuuid' => $companyuuid,
                'versionuuid' => $versionuuid, 'requestkey' => $requestkey]);
            if ($replay) {
                return ['uuid' => $replay->uuid, 'replayed' => true];
            }
            $report = self::report($companyuuid, $reportuuid);
            $version = self::version($companyuuid, $report, $versionuuid);
            if ((int)$report->userid === $actor || (int)$version->reviewerid !== $actor) {
                throw new \moodle_exception('dailyreports_notreviewer', 'local_kbacompany');
            }
            if ($report->currentversionuuid !== $version->uuid || $version->state !== 'submitted') {
                throw new \moodle_exception('dailyreports_staleversion', 'local_kbacompany');
            }
            $previous = self::last_decision($companyuuid, $version->uuid);
            if ($report->status === 'submitted') {
                $supersedes = '';
            } else if ($report->status === 'accepted' && $previous && (int)$previous->reviewerid === $actor) {
                if ($comment === '') {
                    throw new \moodle_exception('dailyreports_reasonrequired', 'local_kbacompany');
                }
                $supersedes = (string)$previous->uuid;
            } else {
                throw new \moodle_exception('dailyreports_notpending', 'local_kbacompany');
            }
            $row = (object)['uuid' => \core\uuid::generate(), 'companyuuid' => $companyuuid, 'reportuuid' => $report->uuid,
                'versionuuid' => $version->uuid, 'reviewerid' => $actor, 'decision' => $decision, 'rating' => $rating,
                'comment' => $comment === '' ? null : $comment, 'supersedesuuid' => $supersedes,
                'requestkey' => $requestkey, 'timecreated' => time()];
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->insert_record('local_kbacompany_dreportrev', $row);
                $report->status = $decision;
                $report->revision = (int)$report->revision + 1;
                $report->timemodified = time();
                $DB->update_record('local_kbacompany_dreport', $report);
                self::log($companyuuid, $report->uuid, $version->uuid, $actor, $supersedes === '' ? 'decided' : 'decision_changed',
                    ['decision' => $decision, 'rating' => $rating, 'supersedes' => $supersedes]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        self::notify($companyuuid, (int)$report->userid, 'dailyreport_decision', [$row->uuid],
            get_string('dailyreports_notify_decision_' . $decision, 'local_kbacompany', $report->reportday),
            daily_report_ui_service::url($companyuuid, ['day' => $report->reportday]));
        return ['uuid' => $row->uuid, 'replayed' => false];
    }

    /** Administrator reassigns the reviewer of a pending report (В2); journaled, never to the author. */
    public static function reassign(string $companyuuid, int $actor, string $reportuuid, int $reviewerid): void {
        global $DB;
        daily_report_store_service::require_enabled();
        $companyuuid = self::member($companyuuid, $actor);
        if (!self::is_admin($companyuuid, $actor)) {
            throw new \moodle_exception('dailyreports_notavailable', 'local_kbacompany');
        }
        $report = self::report($companyuuid, $reportuuid);
        if (!daily_report_store_service::eligible_reviewer($companyuuid, $reviewerid, (int)$report->userid)) {
            throw new \moodle_exception('dailyreports_reviewerinvalid', 'local_kbacompany');
        }
        $lock = self::lock($companyuuid, $report);
        try {
            $report = self::report($companyuuid, $reportuuid);
            if ($report->status !== 'submitted') {
                throw new \moodle_exception('dailyreports_notpending', 'local_kbacompany');
            }
            $version = self::version($companyuuid, $report, (string)$report->currentversionuuid);
            $old = (int)$version->reviewerid;
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->update_record('local_kbacompany_dreportver', (object)['id' => $version->id,
                    'reviewerid' => $reviewerid, 'reviewersource' => 'admin', 'timemodified' => time()]);
                self::log($companyuuid, $report->uuid, $version->uuid, $actor, 'reviewer_reassigned',
                    ['from' => $old, 'to' => $reviewerid]);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);
            }
        } finally {
            $lock->release();
        }
        self::notify($companyuuid, $reviewerid, 'dailyreport_submitted', [$version->uuid, 'reassigned', (string)$reviewerid],
            get_string('dailyreports_notify_submitted', 'local_kbacompany', $report->reportday),
            daily_report_ui_service::url($companyuuid, ['tab' => 'review', 'report' => $report->uuid]));
    }

    private static function log(string $companyuuid, string $reportuuid, string $versionuuid, int $actor,
            string $action, array $details): void {
        global $DB;
        $DB->insert_record('local_kbacompany_dreportlog', (object)['companyuuid' => $companyuuid,
            'reportuuid' => $reportuuid, 'versionuuid' => $versionuuid, 'actorid' => $actor, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'timecreated' => time()]);
    }

    /**
     * One notification per event key in the canonical Communication Center, then one Moodle message. A replayed request
     * never reaches this point; the deterministic key also absorbs a concurrent duplicate.
     */
    public static function notify(string $companyuuid, int $userid, string $kind, array $keyparts, string $title,
            \moodle_url $url): void {
        global $CFG;
        if ($userid <= 0) {
            return;
        }
        $key = hash('sha256', implode('|', array_merge([$kind, $companyuuid, (string)$userid], $keyparts)));
        if (!communication_service::ensure_system_notification($companyuuid, $userid, $kind, $key, $title)) {
            return;
        }
        try {
            require_once($CFG->dirroot . '/message/lib.php');
            $message = new \core\message\message();
            $message->component = 'local_kbacompany';
            $message->name = $kind;
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = \core_user::get_user($userid, '*', MUST_EXIST);
            $message->subject = $title;
            $message->fullmessage = $title;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>' . s($title) . '</p>';
            $message->smallmessage = $title;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('dailyreports_nav', 'local_kbacompany');
            message_send($message);
        } catch (\Throwable $e) {
            // The in-app notification is stored; a mail transport error must not undo the decision.
            debugging('Daily report notification failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }
}
