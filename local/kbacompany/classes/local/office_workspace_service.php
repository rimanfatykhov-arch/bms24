<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * DOC-W2 "Office" (Канцелярия) workspace: a read-only overview over EXISTING canonical owners.
 *
 * The workspace owns no data and no lifecycle. Every number is the total of the same authorized
 * query that the owning page uses for the actor (document_service::browse, task_service::list_page,
 * policy_service::browse, meeting_service::context) or, for acknowledgements, the actor's own
 * required acknowledgements of effective revisions in the current company. Controlled (ISO)
 * documents are never presented as registered correspondence; incoming/outgoing registers,
 * registration numbers, resolutions, contracts and case files are future DOC-W3+ objects.
 * The workspace is behind the site switch local_kbacompany/office_workspace_enabled (default off).
 */
final class office_workspace_service {
    public const CONTRACT_VERSION = '0.1.0';
    public const SETTING = 'office_workspace_enabled';
    /** Rows shown in each overview list; totals are never limited. */
    public const LIST_LIMIT = 5;
    /** Target sections of BMS-ARC-DOC-001 that have no canonical object yet (shown as planned, never as links). */
    public const PLANNED_SECTIONS = ['incoming', 'outgoing', 'internal', 'orders', 'contracts', 'archive'];

    public static function enabled(): bool {
        return (bool)get_config('local_kbacompany', self::SETTING);
    }

    /** The route does not exist while the switch is off: no page, no counts, no menu item. */
    public static function require_enabled(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('officews_disabled', 'local_kbacompany');
        }
    }

    public static function url(string $companyuuid): \moodle_url {
        return new \moodle_url('/local/kbacompany/office.php', ['uuid' => $companyuuid]);
    }

    /** Canonical destinations of the active sections; the owning pages keep their own ACL. */
    public static function routes(string $companyuuid): array {
        $u = static function(string $path, array $params = []) use ($companyuuid): \moodle_url {
            return new \moodle_url('/local/kbacompany/' . $path, ['uuid' => $companyuuid] + $params);
        };
        return [
            'overview' => self::url($companyuuid),
            'documents' => $u('documents.php'),
            'review' => $u('documents.php', ['status' => 'review']),
            'execution' => $u('tasks.php', ['scope' => 'my', 'sourcekind' => 'decision', 'signal' => 'open']),
            'overdue' => $u('tasks.php', ['scope' => 'my', 'sourcekind' => 'decision', 'signal' => 'overdue']),
            'meetings' => $u('meetings.php'),
            'policies' => $u('policies.php'),
            'directives' => $u('policies.php', ['kind' => 'directive']),
            'createdocument' => $u('documents.php', ['view' => 'create']),
        ];
    }

    /**
     * Overview model for one actor in one company. Company membership is checked first; every
     * count below is obtained through the owning service with the same actor, so a count can never
     * include an object that the linked page would hide from this actor.
     */
    public static function overview(string $companyuuid, int $actor): array {
        self::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actor);
        $canmanage = document_service::can_manage($companyuuid, $actor);

        $documents = document_service::browse($companyuuid, $actor, [], 0);
        $review = document_service::browse($companyuuid, $actor, ['status' => 'review'], 0);
        $acks = self::required_acknowledgements($companyuuid, $actor);
        $open = task_service::list_page($companyuuid, $actor,
            ['scope' => 'my', 'sourcekind' => 'decision', 'signal' => 'open'], 0, self::LIST_LIMIT);
        $overdue = task_service::list_page($companyuuid, $actor,
            ['scope' => 'my', 'sourcekind' => 'decision', 'signal' => 'overdue'], 0, self::LIST_LIMIT);
        $directives = policy_service::browse($companyuuid, $actor, ['kind' => 'directive'], 0);
        $meetings = meeting_service::context($companyuuid, $actor);

        $counts = [
            'documents' => (int)$documents['total'],
            'review' => (int)$review['total'],
            'acknowledge' => (int)$acks['total'],
            'execution' => (int)$open['total'],
            'overdue' => (int)$overdue['total'],
            'directives' => (int)$directives['total'],
            'decisions' => (int)($meetings['counts']['open_decisions'] ?? 0),
        ];
        return [
            'company' => ['uuid' => $companyuuid, 'name' => (string)($company['name'] ?? '')],
            'can_manage_documents' => $canmanage,
            'counts' => $counts,
            'recent' => array_map([self::class, 'document_row'], array_slice($documents['rows'], 0, self::LIST_LIMIT)),
            'review' => array_map([self::class, 'document_row'], array_slice($review['rows'], 0, self::LIST_LIMIT)),
            'acknowledge' => $acks['rows'],
            'overdue' => array_map([self::class, 'task_row'], array_slice($overdue['tasks'], 0, self::LIST_LIMIT)),
            'attention' => self::attention($counts),
            'planned' => self::PLANNED_SECTIONS,
            // DOC-W3: the incoming register is linked only behind its own switch and for users with a right.
            'incoming_link' => office_incoming_service::enabled()
                && in_array(true, office_incoming_service::rights($companyuuid, $actor), true),
            'contract' => self::contract(),
        ];
    }

    /** Own required acknowledgements of effective revisions only (the same rule as the document card). */
    public static function required_acknowledgements(string $companyuuid, int $actor): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        $from = "FROM {local_kbacompany_docack} a
            JOIN {local_kbacompany_doc} d ON d.companyuuid = a.companyuuid AND d.uuid = a.documentuuid
                AND d.revision = a.docrevision
            WHERE a.companyuuid = :c AND a.userid = :u AND a.status = :required AND d.status = :effective";
        $params = ['c' => $companyuuid, 'u' => $actor, 'required' => 'required', 'effective' => 'effective'];
        $total = $DB->count_records_sql("SELECT COUNT(a.id) $from", $params);
        $rows = [];
        foreach ($DB->get_records_sql("SELECT a.id, d.uuid, d.code, d.title, d.revision, a.timecreated $from
                ORDER BY a.timecreated ASC, a.id ASC", $params, 0, self::LIST_LIMIT) as $row) {
            $rows[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'title' => (string)$row->title,
                'revision' => (int)$row->revision, 'since' => (int)$row->timecreated];
        }
        return ['total' => (int)$total, 'rows' => $rows];
    }

    /** "Requires attention": only non-zero authorized counts, most urgent first. */
    private static function attention(array $counts): array {
        $out = [];
        foreach (['overdue', 'review', 'acknowledge', 'decisions'] as $key) {
            if ($counts[$key] > 0) {
                $out[] = ['key' => $key, 'count' => $counts[$key]];
            }
        }
        return $out;
    }

    private static function document_row($row): array {
        $row = (object)$row;
        return ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'title' => (string)$row->title,
            'status' => (string)$row->status, 'revision' => (int)$row->revision,
            'timemodified' => (int)$row->timemodified];
    }

    private static function task_row(array $task): array {
        return ['uuid' => (string)($task['uuid'] ?? ''), 'title' => (string)($task['title'] ?? ''),
            'duedate' => (string)($task['due_date'] ?? ''), 'status' => (string)($task['status'] ?? '')];
    }

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_office_workspace',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'feature_switch' => 'local_kbacompany/' . self::SETTING,
            'owns_data' => false,
            'rules' => [
                'workspace_is_read_only_and_owns_no_records',
                'every_count_is_the_total_of_the_owning_service_query_for_the_actor',
                'controlled_documents_are_not_registered_correspondence',
                'planned_sections_are_never_links',
                'switch_off_means_no_route_no_menu_item',
            ],
        ];
    }
}
