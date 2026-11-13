<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Curator candidate catalogue and explicitly triggered Moodle role provisioning.
 *
 * The capability gives eligibility for appointment, NEVER access to all programs.
 * A curator sees only the programs where program.curatorid == actor id.
 */
final class implementation_curator_role {
    public const CAPABILITY = 'local/kbacorporate:curateimplementation';
    public const MANAGER_CAPABILITY = 'local/kbacorporate:manageimplementationprograms';
    public const ROLENAME = 'kbacorporatecurator';

    /** Existing administrators remain eligible; the new capability needs no admin privilege. */
    public static function is_eligible(int $userid, int $existingcuratorid = 0): bool {
        if ($userid <= 0) {
            return false;
        }
        if ($userid === $existingcuratorid) {
            // Preserve old program appointments. Does not grant cross-program access.
            return true;
        }
        $context = \context_system::instance();
        return has_capability(self::CAPABILITY, $context, $userid) ||
            has_capability(self::MANAGER_CAPABILITY, $context, $userid);
    }

    /** List all eligible active users, plus the active incumbent of the current program. */
    public static function candidates(int $existingcuratorid = 0): array {
        global $DB;
        $context = \context_system::instance();
        $fields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, ' .
            'u.middlename, u.alternatename, u.email, u.deleted, u.suspended';
        $users = [];
        foreach ([self::CAPABILITY, self::MANAGER_CAPABILITY] as $capability) {
            $result = get_users_by_capability($context, $capability, $fields,
                'u.lastname ASC, u.firstname ASC') ?: [];
            foreach ($result as $user) {
                if (empty($user->deleted) && empty($user->suspended)) {
                    $users[(int)$user->id] = $user;
                }
            }
        }
        if ($existingcuratorid > 0 && !isset($users[$existingcuratorid])) {
            $user = $DB->get_record('user', [
                'id' => $existingcuratorid, 'deleted' => 0, 'suspended' => 0,
            ], 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, ' .
                'middlename, alternatename, email');
            if ($user) {
                $users[(int)$user->id] = $user;
            }
        }
        uasort($users, static function($a, $b): int {
            return strcmp(\core_text::strtolower(fullname($a)), \core_text::strtolower(fullname($b)));
        });
        return $users;
    }

    /** No implicit assignments. May be invoked ONLY by a Moodle role administrator. */
    public static function provision_role(): int {
        global $DB;
        $context = \context_system::instance();
        require_capability('moodle/role:manage', $context);
        $existing = $DB->get_record('role', ['shortname' => self::ROLENAME]);
        if ($existing) {
            return (int)$existing->id;
        }
        // Provisioning happens after the plugin upgrade registered new capabilities.
        if (!$DB->record_exists('capabilities', ['name' => self::CAPABILITY])) {
            throw new \moodle_exception('curatorrolecapmissing', 'local_kbacorporate');
        }
        $roleid = create_role(get_string('curatorrolename', 'local_kbacorporate'),
            self::ROLENAME, get_string('curatorroledesc', 'local_kbacorporate'));
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability(self::CAPABILITY, CAP_ALLOW, $roleid, $context->id, true);
        return (int)$roleid;
    }
}
