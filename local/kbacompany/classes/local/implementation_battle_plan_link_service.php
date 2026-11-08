<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only Corporate task deep link -> editable draft Battle Plan chooser. */
final class implementation_battle_plan_link_service {
    public const PROVIDER = 'kbacorporate_impl';

    /** Reject links to another Corporate company, another assignee, or an ineligible source. */
    public static function eligible_task(?array $task, int $corporatecompanyid, int $actor): bool {
        return $task !== null && $corporatecompanyid > 0 && $actor > 0 &&
            ($task['provider'] ?? '') === self::PROVIDER &&
            (int)($task['corporate_company_id'] ?? 0) === $corporatecompanyid &&
            (int)($task['assignee_id'] ?? 0) === $actor &&
            !empty($task['attachable']) && in_array((string)($task['status'] ?? ''), ['open','doing'], true);
    }

    /** Eligible drafts for the task assignee; does not mutate plan status or settings. */
    public static function select_drafts(array $plans, int $assignee): array {
        $result = [];
        foreach ($plans as $key => $plan) {
            if ((int)($plan['user_id'] ?? 0) !== $assignee) { continue; }
            $uuid = (string)($plan['uuid'] ?? $key);
            if (!preg_match('/^[a-f0-9-]{36}$/D', $uuid)) { continue; }
            $result[$uuid] = $plan;
        }
        return $result;
    }
}
