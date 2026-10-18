<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * User-facing navigation helpers for KBA Gamification.
 *
 * @package    local_kbagamification
 */
class navigation_manager {
    /**
     * Whether user-facing navigation is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config('local_kbagamification', 'navigationenabled');
        return $value === false ? false : (bool) $value;
    }

    /**
     * Build the common learner navigation model.
     *
     * @param int $userid Profile user id.
     * @param string $current Current item key.
     * @return array
     */
    public static function get_user_nav_data(int $userid, string $current = 'profile'): array {
        global $USER;

        $systemcontext = \context_system::instance();
        $isown = $userid === (int) $USER->id;
        $canviewprofile = ($isown && has_capability('local/kbagamification:viewown', $systemcontext))
            || has_capability('local/kbagamification:viewany', $systemcontext);

        $items = [];
        if ($canviewprofile) {
            $items[] = self::item(
                'profile',
                get_string('navprofile', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]),
                $current
            );
            $items[] = self::item(
                'achievements',
                get_string('navachievements', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/profile.php', ['userid' => $userid, 'section' => 'achievements'], 'kba-achievements'),
                $current
            );
        }

        if ((bool) get_config('local_kbagamification', 'leaderboardsenabled')
                && has_capability('local/kbagamification:viewleaderboards', $systemcontext)) {
            $items[] = self::item(
                'leaderboards',
                get_string('navleaderboards', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/leaderboards.php'),
                $current
            );
        }

        if ($isown && (bool) get_config('local_kbagamification', 'rewardstoreenabled')
                && has_capability('local/kbagamification:viewstore', $systemcontext)) {
            $items[] = self::item(
                'rewards',
                get_string('navrewards', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/store.php'),
                $current
            );
            $items[] = self::item(
                'orders',
                get_string('navorders', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/orders.php'),
                $current
            );
        }

        return [
            'items' => $items,
            'hasitems' => !empty($items),
        ];
    }

    /**
     * Add a compact KBA entry to Moodle global navigation.
     *
     * @param \global_navigation $navigation
     * @return void
     */
    public static function extend_global_navigation(\global_navigation $navigation): void {
        global $USER;

        if (!self::is_enabled() || !isloggedin() || isguestuser()) {
            return;
        }

        $systemcontext = \context_system::instance();
        if (!has_capability('local/kbagamification:viewown', $systemcontext)) {
            return;
        }

        $profileurl = new \moodle_url('/local/kbagamification/profile.php', ['userid' => $USER->id]);
        $root = $navigation->add(
            get_string('kbanavigation', 'local_kbagamification'),
            $profileurl,
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_kbagamification_root'
        );

        $root->add(
            get_string('navprofile', 'local_kbagamification'),
            $profileurl,
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_kbagamification_profile'
        );
        $root->add(
            get_string('navachievements', 'local_kbagamification'),
            new \moodle_url('/local/kbagamification/profile.php', ['userid' => $USER->id, 'section' => 'achievements'], 'kba-achievements'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_kbagamification_achievements'
        );

        if ((bool) get_config('local_kbagamification', 'leaderboardsenabled')
                && has_capability('local/kbagamification:viewleaderboards', $systemcontext)) {
            $root->add(
                get_string('navleaderboards', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/leaderboards.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_kbagamification_leaderboards'
            );
        }

        if ((bool) get_config('local_kbagamification', 'rewardstoreenabled')
                && has_capability('local/kbagamification:viewstore', $systemcontext)) {
            $root->add(
                get_string('navrewards', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/store.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_kbagamification_rewards'
            );
            $root->add(
                get_string('navorders', 'local_kbagamification'),
                new \moodle_url('/local/kbagamification/orders.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_kbagamification_orders'
            );
        }
    }

    /**
     * Render common learner navigation.
     *
     * @param int $userid Profile user id.
     * @param string $current Current item key.
     * @return string
     */
    public static function render_user_nav(int $userid, string $current = 'profile'): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template(
            'local_kbagamification/user_nav',
            self::get_user_nav_data($userid, $current)
        );
    }

    /**
     * Create a nav view item.
     *
     * @param string $key
     * @param string $label
     * @param \moodle_url $url
     * @param string $current
     * @return array
     */
    private static function item(string $key, string $label, \moodle_url $url, string $current): array {
        return [
            'key' => $key,
            'label' => $label,
            'url' => $url->out(false),
            'active' => $key === $current,
        ];
    }
}
