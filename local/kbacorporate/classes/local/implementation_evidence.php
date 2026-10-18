<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Versioned evidence packages for managed BMS implementation tasks.
 *
 * Every submission is immutable. A rejected package is corrected by creating
 * the next version, which keeps the complete decision trail intact.
 */
final class implementation_evidence {
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    /** File area used for one immutable evidence version. */
    public const FILEAREA = 'implementation_evidence_record';

    /** @return array<string,int|bool> */
    public static function file_options(): array {
        return [
            'subdirs' => 0,
            'maxbytes' => 20 * 1024 * 1024,
            'maxfiles' => 20,
            'accepted_types' => '*',
        ];
    }

    /** Whether the evidence tables are installed. */
    public static function is_available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_kbacorp_impl_evid')) &&
            $dbman->table_exists(new \xmldb_table('local_kbacorp_impl_evlog'));
    }

    /** Load the task and its audit criterion snapshot. */
    public static function get_task(int $taskid): \stdClass {
        return implementation_task_discussion::get_task($taskid);
    }

    /** Load one evidence version and the owning task. */
    public static function get_record(int $evidenceid): \stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT e.*, t.companyid, t.auditid, t.ownerid, t.status AS taskstatus,
                    t.title AS tasktitle, t.criterionname
               FROM {local_kbacorp_impl_evid} e
               JOIN {local_kbacorp_impl_task} t ON t.id = e.taskid
              WHERE e.id = :evidenceid AND t.deleted = 0",
            ['evidenceid' => $evidenceid], MUST_EXIST
        );
    }

    /** Return evidence versions newest first. */
    public static function get_versions(int $taskid): array {
        global $DB;
        if (!self::is_available()) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT e.*,
                    su.firstname AS submitterfirstname, su.lastname AS submitterlastname,
                    su.firstnamephonetic AS submitterfirstnamephonetic,
                    su.lastnamephonetic AS submitterlastnamephonetic,
                    su.middlename AS submittermiddlename, su.alternatename AS submitteralternatename,
                    ru.firstname AS reviewerfirstname, ru.lastname AS reviewerlastname,
                    ru.firstnamephonetic AS reviewerfirstnamephonetic,
                    ru.lastnamephonetic AS reviewerlastnamephonetic,
                    ru.middlename AS reviewermiddlename, ru.alternatename AS revieweralternatename
               FROM {local_kbacorp_impl_evid} e
          LEFT JOIN {user} su ON su.id = e.submitterid
          LEFT JOIN {user} ru ON ru.id = e.reviewerid
              WHERE e.taskid = :taskid
           ORDER BY e.versionno DESC, e.id DESC",
            ['taskid' => $taskid]
        ));
    }

    /** Latest evidence version or null. */
    public static function get_latest(int $taskid): ?\stdClass {
        global $DB;
        if (!self::is_available()) {
            return null;
        }
        $record = $DB->get_record_sql(
            "SELECT *
               FROM {local_kbacorp_impl_evid}
              WHERE taskid = :taskid
           ORDER BY versionno DESC, id DESC",
            ['taskid' => $taskid], IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    /** Whether at least one version exists for this task. */
    public static function has_records(int $taskid): bool {
        global $DB;
        return self::is_available() && $DB->record_exists('local_kbacorp_impl_evid', ['taskid' => $taskid]);
    }

    /** Summary counters used by the task and evidence pages. */
    public static function get_summary(int $taskid): \stdClass {
        global $DB;
        $summary = (object)[
            'total' => 0,
            'submitted' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'latest' => null,
        ];
        if (!self::is_available()) {
            return $summary;
        }
        $rows = $DB->get_records_sql(
            "SELECT status, COUNT(1) AS total
               FROM {local_kbacorp_impl_evid}
              WHERE taskid = :taskid
           GROUP BY status",
            ['taskid' => $taskid]
        );
        foreach ($rows as $row) {
            $count = (int)$row->total;
            $summary->total += $count;
            if (property_exists($summary, (string)$row->status)) {
                $summary->{$row->status} = $count;
            }
        }
        $summary->latest = self::get_latest($taskid);
        return $summary;
    }

    /** Evidence event history, newest first. */
    public static function get_history(int $taskid): array {
        global $DB;
        if (!self::is_available() ||
                !$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_evlog'))) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT l.*, e.versionno,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {local_kbacorp_impl_evlog} l
               JOIN {local_kbacorp_impl_evid} e ON e.id = l.evidenceid
          LEFT JOIN {user} u ON u.id = l.userid
              WHERE l.taskid = :taskid
           ORDER BY l.timecreated DESC, l.id DESC",
            ['taskid' => $taskid]
        ));
    }

    /** Files attached to one evidence version. */
    public static function get_files(int $evidenceid): array {
        $context = \context_system::instance();
        return array_values(get_file_storage()->get_area_files(
            $context->id,
            'local_kbacorporate',
            self::FILEAREA,
            $evidenceid,
            'filename ASC',
            false
        ));
    }

    /** Whether the task owner may submit a new evidence version. */
    public static function can_submit(\stdClass $task, int $userid): bool {
        global $DB;
        if ((int)$task->ownerid !== $userid || (string)$task->status !== 'inprogress') {
            return false;
        }
        return !$DB->record_exists('local_kbacorp_impl_evid', [
            'taskid' => (int)$task->id,
            'status' => self::STATUS_SUBMITTED,
        ]);
    }

    /** Whether a curator or company manager may review the current evidence. */
    public static function can_review(\stdClass $task, int $userid): bool {
        if ((int)$task->ownerid === $userid) {
            return false;
        }
        return implementation_task_discussion::is_curator($task, $userid) ||
            access::can_manage_company_implementation((int)$task->companyid, $userid);
    }

    /**
     * Submit the next immutable evidence version and move the task to review.
     */
    public static function submit(int $taskid, int $userid, string $summary,
            string $externalurl, int $draftitemid): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');

        if (!self::is_available()) {
            throw new \moodle_exception('implevidenceunavailable', 'local_kbacorporate');
        }
        $task = self::get_task($taskid);
        implementation_task_discussion::require_access($task, $userid);
        if (!self::can_submit($task, $userid)) {
            throw new \moodle_exception('implevidencecannotsubmit', 'local_kbacorporate');
        }

        $summary = trim(clean_param($summary, PARAM_TEXT));
        $externalurl = trim(clean_param($externalurl, PARAM_URL));
        if ($summary === '') {
            throw new \moodle_exception('implevidencesummaryrequired', 'local_kbacorporate');
        }
        if (\core_text::strlen($summary) > 20000 || \core_text::strlen($externalurl) > 1333) {
            throw new \invalid_parameter_exception('Implementation evidence is too long.');
        }

        $transaction = $DB->start_delegated_transaction();
        $latest = self::get_latest($taskid);
        $version = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(versionno), 0) FROM {local_kbacorp_impl_evid} WHERE taskid = :taskid',
            ['taskid' => $taskid]
        ) + 1;
        $now = time();
        $record = (object)[
            'taskid' => $taskid,
            'versionno' => $version,
            'status' => self::STATUS_SUBMITTED,
            'summary' => $summary,
            'externalurl' => $externalurl === '' ? null : $externalurl,
            'submitterid' => $userid,
            'submittedat' => $now,
            'reviewerid' => 0,
            'reviewedat' => 0,
            'reviewcomment' => null,
            'supersedesid' => $latest ? (int)$latest->id : 0,
            'createdby' => $userid,
            'modifiedby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = (int)$DB->insert_record('local_kbacorp_impl_evid', $record);

        file_save_draft_area_files(
            $draftitemid,
            \context_system::instance()->id,
            'local_kbacorporate',
            self::FILEAREA,
            $record->id,
            self::file_options()
        );

        // Keep the historical task result fields populated for integrations and old reports.
        $DB->update_record('local_kbacorp_impl_task', (object)[
            'id' => $taskid,
            'completioncomment' => $summary,
            'resulturl' => $record->externalurl,
            'modifiedby' => $userid,
            'timemodified' => $now,
        ]);

        self::log($record->id, $taskid, $userid, 'submitted', '', self::STATUS_SUBMITTED,
            get_string('implevidencelogsubmitted', 'local_kbacorporate', $version), [
                'version' => $version,
                'filecount' => count(self::get_files($record->id)),
                'externalurl' => $record->externalurl,
            ]);

        $task = self::get_task($taskid);
        implementation_task_lifecycle::transition(
            $task,
            $userid,
            'submit',
            get_string('implevidencetasksubmitted', 'local_kbacorporate', $version)
        );
        $transaction->allow_commit();
        return self::get_record($record->id);
    }

    /**
     * Accept or reject the latest submitted evidence and move the task accordingly.
     */
    public static function review(int $evidenceid, int $userid, bool $accepted,
            string $reviewcomment): \stdClass {
        global $DB;
        if (!self::is_available()) {
            throw new \moodle_exception('implevidenceunavailable', 'local_kbacorporate');
        }
        $evidence = self::get_record($evidenceid);
        $task = self::get_task((int)$evidence->taskid);
        implementation_task_discussion::require_access($task, $userid);
        if ((int)$evidence->submitterid === $userid) {
            throw new \moodle_exception('implevidenceselfreview', 'local_kbacorporate');
        }
        if (!self::can_review($task, $userid)) {
            throw new \moodle_exception('implevidencecannotreview', 'local_kbacorporate');
        }
        $latest = self::get_latest((int)$task->id);
        if (!$latest || (int)$latest->id !== $evidenceid ||
                (string)$evidence->status !== self::STATUS_SUBMITTED ||
                (string)$task->status !== 'review') {
            throw new \moodle_exception('implevidencecannotreview', 'local_kbacorporate');
        }
        $reviewcomment = trim(clean_param($reviewcomment, PARAM_TEXT));
        if (!$accepted && $reviewcomment === '') {
            throw new \moodle_exception('implevidencerejectcommentrequired', 'local_kbacorporate');
        }
        if (\core_text::strlen($reviewcomment) > 20000) {
            throw new \invalid_parameter_exception('Implementation evidence review is too long.');
        }

        $transaction = $DB->start_delegated_transaction();
        $newstatus = $accepted ? self::STATUS_ACCEPTED : self::STATUS_REJECTED;
        $now = time();
        $DB->update_record('local_kbacorp_impl_evid', (object)[
            'id' => $evidenceid,
            'status' => $newstatus,
            'reviewerid' => $userid,
            'reviewedat' => $now,
            'reviewcomment' => $reviewcomment === '' ? null : $reviewcomment,
            'modifiedby' => $userid,
            'timemodified' => $now,
        ]);
        self::log($evidenceid, (int)$task->id, $userid,
            $accepted ? 'accepted' : 'rejected', self::STATUS_SUBMITTED, $newstatus,
            $reviewcomment, ['version' => (int)$evidence->versionno]);

        // The evidence decision and task lifecycle are one atomic management action.
        $task = self::get_task((int)$task->id);
        implementation_task_lifecycle::transition(
            $task,
            $userid,
            $accepted ? 'approve' : 'return',
            $reviewcomment !== '' ? $reviewcomment : get_string(
                $accepted ? 'implevidencetaskaccepted' : 'implevidencetaskrejected',
                'local_kbacorporate',
                (int)$evidence->versionno
            )
        );
        $transaction->allow_commit();
        return self::get_record($evidenceid);
    }

    /** Evidence status string key. */
    public static function status_string(string $status): string {
        $key = 'implevidencestatus' . $status;
        return get_string_manager()->string_exists($key, 'local_kbacorporate')
            ? get_string($key, 'local_kbacorporate') : s($status);
    }

    /** Write one immutable evidence audit event. */
    private static function log(int $evidenceid, int $taskid, int $userid, string $action,
            string $fromstatus, string $tostatus, string $comment = '', array $details = []): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacorp_impl_evlog'))) {
            return;
        }
        $DB->insert_record('local_kbacorp_impl_evlog', (object)[
            'evidenceid' => $evidenceid,
            'taskid' => $taskid,
            'userid' => $userid,
            'action' => $action,
            'fromstatus' => $fromstatus === '' ? null : $fromstatus,
            'tostatus' => $tostatus === '' ? null : $tostatus,
            'comment' => trim($comment) === '' ? null : trim($comment),
            'detailsjson' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'timecreated' => time(),
        ]);
    }
}
