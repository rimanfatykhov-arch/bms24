<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped employee work calendar with explicit grants. Never exposes personal Moodle events. */
class employee_work_calendar_service {
    const EVENTS = 'local_kbacompany_wcalev';
    const GRANTS = 'local_kbacompany_wcalacl';
    const COMMENTS = 'local_kbacompany_wcalmsg';

    private static function deny(): void {
        throw new \required_capability_exception(\context_system::instance(),
            'local/kbacompany:viewowncompany', 'nopermissions', '');
    }

    private static function context(string $company, int $actor, int $owner): void {
        global $DB;
        company_context_service::context($company, $actor);
        if ($owner <= 0 || !$DB->record_exists('user', ['id' => $owner, 'deleted' => 0])) {
            self::deny();
        }
        // Only employees/participants in this company can own a work calendar.
        employee_service::directory_record($company, $owner, $actor, '');
    }

    /** Permissions are independent: view, edit and discuss are never implied by each other. */
    public static function allowed(string $company, int $actor, int $owner, string $action): bool {
        global $DB;
        self::context($company, $actor, $owner);
        if (!in_array($action, ['view', 'edit', 'comment', 'grant'], true)) { return false; }
        if ($actor === $owner || is_siteadmin($actor)) { return true; }
        if ($action === 'grant') { return permission_service::can_manage_people($company, $actor); }
        $grant = $DB->get_record(self::GRANTS,
            ['companyuuid' => $company, 'ownerid' => $owner, 'granteeid' => $actor], '*', IGNORE_MISSING);
        if (!$grant) { return false; }
        return (bool)$grant->{'can' . $action};
    }

    private static function require_action(string $company, int $actor, int $owner, string $action): void {
        if (!self::allowed($company, $actor, $owner, $action)) { self::deny(); }
    }

    public static function list_events(string $company, int $actor, int $owner, int $from, int $until): array {
        global $DB;
        self::require_action($company, $actor, $owner, 'view');
        $sql = 'companyuuid = :company AND ownerid = :owner AND starttime >= :fromdate AND starttime < :untildate';
        return array_values($DB->get_records_select(self::EVENTS, $sql,
            ['company' => $company, 'owner' => $owner, 'fromdate' => $from, 'untildate' => $until], 'starttime ASC, id ASC', '*', 0, 200));
    }

    public static function save_event(string $company, int $actor, int $owner, int $id, string $title,
            int $start, int $end): int {
        global $DB;
        self::require_action($company, $actor, $owner, 'edit');
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 255 || $start <= 0 || $end <= $start || $end - $start > 604800) {
            throw new \invalid_parameter_exception('Invalid work calendar event.');
        }
        if ($id > 0) {
            $record = $DB->get_record(self::EVENTS, ['id' => $id, 'companyuuid' => $company, 'ownerid' => $owner], '*', IGNORE_MISSING);
            if (!$record) { self::deny(); }
            $record->title = $title;
            $record->starttime = $start;
            $record->endtime = $end;
            $record->timemodified = time();
            $DB->update_record(self::EVENTS, $record);
            return $id;
        }
        return (int)$DB->insert_record(self::EVENTS, (object)[
            'companyuuid' => $company, 'ownerid' => $owner, 'title' => $title,
            'starttime' => $start, 'endtime' => $end, 'createdby' => $actor, 'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public static function add_comment(string $company, int $actor, int $owner, int $eventid, string $body): void {
        global $DB;
        self::require_action($company, $actor, $owner, 'comment');
        if (!$DB->record_exists(self::EVENTS, ['id' => $eventid, 'companyuuid' => $company, 'ownerid' => $owner])) {
            self::deny();
        }
        $body = trim($body);
        if ($body === '' || \core_text::strlen($body) > 4000) {
            throw new \invalid_parameter_exception('Invalid comment.');
        }
        $DB->insert_record(self::COMMENTS, (object)[
            'companyuuid' => $company, 'eventid' => $eventid, 'authorid' => $actor,
            'message' => $body, 'timecreated' => time(),
        ]);
    }

    public static function comments(string $company, int $actor, int $owner, int $eventid): array {
        global $DB;
        self::require_action($company, $actor, $owner, 'view');
        if (!$DB->record_exists(self::EVENTS, ['id' => $eventid, 'companyuuid' => $company, 'ownerid' => $owner])) {
            self::deny();
        }
        return array_values($DB->get_records(self::COMMENTS,
            ['companyuuid' => $company, 'eventid' => $eventid], 'timecreated ASC, id ASC', '*', 0, 100));
    }

    /** Owner, site admin or authorised People manager can grant rights; no name-based identity matching. */
    public static function grant(string $company, int $actor, int $owner, int $recipient,
            bool $view, bool $edit, bool $comment): void {
        global $DB;
        self::require_action($company, $actor, $owner, 'grant');
        if ($recipient <= 0 || $recipient === $owner) { throw new \invalid_parameter_exception('Invalid recipient.'); }
        // Authorised resolution guarantees the recipient belongs to this same company.
        self::context($company, $recipient, $recipient);
        $data = $DB->get_record(self::GRANTS,
            ['companyuuid' => $company, 'ownerid' => $owner, 'granteeid' => $recipient], '*', IGNORE_MISSING);
        if (!$view && !$edit && !$comment) {
            if ($data) { $DB->delete_records(self::GRANTS, ['id' => $data->id]); }
            return;
        }
        $row = (object)[
            'companyuuid' => $company, 'ownerid' => $owner, 'granteeid' => $recipient,
            'canview' => (int)$view, 'canedit' => (int)$edit, 'cancomment' => (int)$comment,
            'timemodified' => time(),
        ];
        if ($data) { $row->id = $data->id; $DB->update_record(self::GRANTS, $row); }
        else { $DB->insert_record(self::GRANTS, $row); }
    }

    public static function grants(string $company, int $actor, int $owner): array {
        global $DB;
        self::require_action($company, $actor, $owner, 'grant');
        return array_values($DB->get_records(self::GRANTS,
            ['companyuuid' => $company, 'ownerid' => $owner], 'granteeid ASC'));
    }
}
