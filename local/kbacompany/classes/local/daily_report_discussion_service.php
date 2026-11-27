<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * R3: one canonical discussion per daily report (owner decision 2026-11-24).
 *
 * Messages live only in communication_service (object thread, contextkind 'dailyreport'); this service never stores a
 * second history. Reading follows the report read rule: the author, the stored reviewer of any version, copy recipients of
 * a copied version, and administrators/managers who read every report of the company (ADR-0010 D4/D5). Writing is limited
 * to the author and the reviewer stored on the current version (the same principle as the Battle Plan, Q9); the rule is
 * re-checked on every message (communication_service::append_message), so a reassigned reviewer stops writing. A message
 * never accepts, returns, rates or otherwise changes the report. Only reports with a submitted version have a discussion.
 */
final class daily_report_discussion_service {
    public const KIND = 'dailyreport';

    private static function fail(string $key = 'notavailable'): void {
        throw new \moodle_exception('dailyreports_' . $key, 'local_kbacompany');
    }

    /**
     * Read target of one report, or an indistinguishable failure for an unknown, foreign, unsent or unreadable report.
     * Company membership (revocation included) is checked first; every query is scoped by the company UUID.
     */
    public static function target(string $companyuuid, int $actor, string $reportuuid): array {
        global $DB;
        daily_report_store_service::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        $reportuuid = trim($reportuuid);
        if ($actor <= 0 || $reportuuid === '') { self::fail(); }
        $report = $DB->get_record('local_kbacompany_dreport', ['companyuuid' => $companyuuid, 'uuid' => $reportuuid]);
        if (!$report || (string)$report->currentversionuuid === '') { self::fail(); }
        $current = $DB->get_record('local_kbacompany_dreportver', ['companyuuid' => $companyuuid,
            'reportuuid' => $report->uuid, 'uuid' => $report->currentversionuuid]);
        if (!$current) { self::fail(); }
        $author = (int)$report->userid === $actor;
        $reviewer = (int)$current->reviewerid > 0 && (int)$current->reviewerid === $actor;
        if (!$author && !$reviewer && !daily_report_review_service::can_read_report($companyuuid, $actor, $report)) {
            self::fail();
        }
        $user = \core_user::get_user((int)$report->userid);
        return ['uuid' => (string)$report->uuid, 'day' => (string)$report->reportday, 'status' => (string)$report->status,
            'user_id' => (int)$report->userid, 'reviewer_id' => (int)$current->reviewerid, 'is_author' => $author,
            'can_post' => $author || $reviewer,
            'title' => get_string('dailyreports_discussion_title', 'local_kbacompany',
                (object)['day' => (string)$report->reportday, 'name' => $user ? fullname($user) : ''])];
    }

    /** Where this actor opens the report: the author in the personal day view, everyone else in the review card. */
    public static function url(string $companyuuid, array $target, array $extra = []): \moodle_url {
        $params = !empty($target['is_author']) ? ['uuid' => $companyuuid, 'day' => $target['day']] :
            ['uuid' => $companyuuid, 'tab' => 'review', 'report' => $target['uuid']];
        return new \moodle_url('/local/kbacompany/daily_report.php', $params + $extra, 'dailyreport-discussion');
    }

    /** The active canonical thread of a report, if any (company-scoped). */
    public static function thread(string $companyuuid, string $reportuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_commthread', [
            'companyuuid' => $companyuuid, 'threadtype' => 'object', 'contextkind' => self::KIND,
            'contextuuid' => $reportuuid, 'status' => 'active',
        ], 'id ASC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Read projection for the report card; throws for anyone who may not open the report. */
    public static function context(string $companyuuid, int $actor, string $reportuuid): array {
        $target = self::target($companyuuid, $actor, $reportuuid);
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $thread = self::thread($companyuuid, $target['uuid']);
        if (!$thread) {
            return ['thread_uuid' => '', 'messages' => [], 'is_participant' => false,
                'can_post' => $target['can_post'], 'report' => $target];
        }
        // Readers are not joined: communication_service re-checks the report through communication_object_service.
        $ctx = communication_service::context($companyuuid, $actor, (string)$thread->uuid, 'all');
        return ['thread_uuid' => (string)$thread->uuid, 'messages' => $ctx['messages'],
            'is_participant' => !empty($ctx['selected']['is_participant']),
            'can_post' => $target['can_post'], 'report' => $target];
    }

    /** Whether the actor may write to the report discussion now (author or reviewer of the current version). */
    public static function can_post_for(string $companyuuid, int $actor, string $reportuuid): bool {
        try {
            return (bool)self::target($companyuuid, $actor, $reportuuid)['can_post'];
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * The author and the current reviewer become participants when the thread is created (if they may read). As in the
     * Battle Plan discussion, only people on the company staff roster can be added by someone else: a reviewer who is a
     * company manager or owner without a staff row (isemployee = 0) is not added by the author, keeps reading the report
     * by the read rule and joins the thread on the first own message. Otherwise the author's first message would fail.
     */
    private static function initial_participants(string $companyuuid, int $actor, array $target): array {
        $roster = [];
        foreach (organization_service::organization_context($companyuuid, $actor)['employees'] as $employee) {
            $roster[(int)$employee['user_id']] = true;
        }
        $result = [];
        foreach (array_unique(array_filter([$target['user_id'], $target['reviewer_id']])) as $userid) {
            $userid = (int)$userid;
            if ($userid === $actor || !isset($roster[$userid]) ||
                    !daily_report_store_service::eligible_reviewer($companyuuid, $userid, 0)) {
                continue;
            }
            try {
                communication_object_service::describe($companyuuid, $userid, self::KIND, $target['uuid']);
            } catch (\moodle_exception $e) {
                continue;
            }
            $result[] = $userid;
        }
        return $result;
    }

    /** Post one text message (optionally as a reply). Posting explicitly joins the actor. */
    public static function post(string $companyuuid, int $actor, string $reportuuid, string $body,
            string $replytouuid = ''): string {
        $target = self::target($companyuuid, $actor, $reportuuid);
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        if (empty($target['can_post'])) { self::fail('discussion_readonly'); }
        $body = trim($body);
        if ($body === '') { self::fail('discussion_empty_message'); }
        if (\core_text::strlen($body) > 20000) { self::fail('texttoolong'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('dreportdiscussion:' . $companyuuid . ':' . $target['uuid'], 10);
        if (!$lock) { self::fail('busy'); }
        try {
            // create_thread is the idempotent create-or-join for the one canonical object thread.
            $threaduuid = communication_service::create_thread($companyuuid, $actor, [
                'threadtype' => 'object', 'title' => '', 'contextkind' => self::KIND, 'contextuuid' => $target['uuid'],
                'contextrevision' => 0, 'participants' => self::initial_participants($companyuuid, $actor, $target),
            ]);
            return communication_service::send_message($companyuuid, $actor, $threaduuid, $body, [], trim($replytouuid));
        } finally {
            $lock->release();
        }
    }
}
