<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * "Day feed -> report draft", first scenario: task results only.
 *
 * The feed is the employee's own submitted task results of one civil day (the submit events of the task log, read with
 * the day summary query: company on log and task, author = session user, current "my tasks" ACL). "Add to report" copies
 * one result, always re-read on the server, into the "Done" field of the employee's own draft of that day through the
 * existing daily_report_store_service::save_draft (lock, transaction, optimistic revision, audit). Text already written
 * is kept; a result already present is not added again. No schema, no new records, no automatic submission.
 */
final class daily_report_feed_service {
    /** Feed of one day: own results with the current task status ("submitted" is not "accepted"). */
    public static function day(string $companyuuid, int $actor, string $day, int $page = 0): array {
        $data = daily_report_service::context($companyuuid, $actor, $day, 0, $page);
        return ['day' => $day, 'timezone' => $data['timezone'], 'results' => $data['results'],
            'total' => $data['resulttotal'], 'page' => $page];
    }

    /** The line written into "Done": task title and the submitted result text. */
    public static function block(\stdClass $event): string {
        $result = trim(str_replace("\r\n", "\n", (string)$event->result));
        $title = trim((string)$event->title);
        return $result === '' ? '«' . $title . '»' : '«' . $title . '»: ' . $result;
    }

    /** Append $block to $done unless it is already there; the employee's own text is never changed. */
    public static function merge(string $done, string $block): array {
        $done = trim(str_replace("\r\n", "\n", $done));
        if ($block === '' || ($done !== '' && strpos($done, $block) !== false)) {
            return [$done, false];
        }
        return [$done === '' ? $block : $done . "\n" . $block, true];
    }

    /**
     * Add one own result of $day to the own draft of $day.
     *
     * $form is the open report form (values, links, cc, revision) when the employee pressed the button inside it, so
     * unsaved text is kept; null when the button was pressed in the Communication Center feed (the stored draft is
     * used). Returns ['added' => bool, 'revision' => int]. Errors are the store's known errors
     * (dailyreports_taskunavailable for any foreign, hidden or unknown result, notdraft, futureday, conflict, ...).
     */
    public static function add(string $companyuuid, int $actor, string $day, int $eventid, ?array $form = null): array {
        daily_report_store_service::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        $event = daily_report_service::result($companyuuid, $actor, $day, $eventid);
        if (!$event || task_service::readable_projection($companyuuid, $actor, (string)$event->taskuuid) === null) {
            throw new \moodle_exception('dailyreports_taskunavailable', 'local_kbacompany');
        }
        $view = daily_report_store_service::view($companyuuid, $actor, $day);
        if ($view['report'] && !$view['draft']) {
            throw new \moodle_exception('dailyreports_notdraft', 'local_kbacompany');
        }
        if ($form === null) {
            $draft = $view['draft'];
            $values = [];
            foreach (daily_report_store_service::FIELDS as $field) {
                $values[$field] = $draft ? (string)$draft->$field : '';
            }
            $links = array_map(static function($l): string { return (string)$l->taskuuid; }, $view['draftlinks']);
            $cc = array_map(static function($c): int { return (int)$c->userid; },
                array_values(array_filter($view['draftcc'], static function($c): bool {
                    return (string)$c->source === 'manual';
                })));
            $revision = $draft ? (int)$draft->revision : 0;
        } else {
            $values = (array)($form['values'] ?? []);
            $links = array_map('strval', (array)($form['links'] ?? []));
            $cc = array_map('intval', (array)($form['cc'] ?? []));
            $revision = (int)($form['revision'] ?? 0);
        }
        $values = daily_report_store_service::clean_values($values);
        [$values['done'], $added] = self::merge($values['done'], self::block($event));
        if (!in_array((string)$event->taskuuid, $links, true) && count($links) < daily_report_store_service::MAX_LINKS) {
            $links[] = (string)$event->taskuuid;
        }
        if (!$added && $form === null) {
            // Repeated click in the feed: the stored draft already has this result; nothing is written.
            return ['added' => false, 'revision' => $revision];
        }
        $saved = daily_report_store_service::save_draft($companyuuid, $actor, $day, $values, $links, $revision, $cc);
        return ['added' => $added, 'revision' => (int)$saved['revision']];
    }
}
