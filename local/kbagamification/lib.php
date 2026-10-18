<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Add KBA Gamification to Moodle global navigation.
 *
 * @param global_navigation $navigation
 */
function local_kbagamification_extend_navigation(global_navigation $navigation) {
    \local_kbagamification\local\navigation_manager::extend_global_navigation($navigation);
}

/**
 * Add KBA Gamification links to user navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $user
 * @param context_user $usercontext
 * @param stdClass $course
 * @param context_course $coursecontext
 */
function local_kbagamification_extend_navigation_user($navigation, $user, $usercontext, $course, $coursecontext) {
    global $USER;

    if (!\local_kbagamification\local\navigation_manager::is_enabled()) {
        return;
    }

    $systemcontext = context_system::instance();
    $isown = (int) $USER->id === (int) $user->id;
    $canview = ($isown && has_capability('local/kbagamification:viewown', $systemcontext))
        || has_capability('local/kbagamification:viewany', $systemcontext);

    if (!$canview) {
        return;
    }

    $profileurl = new moodle_url('/local/kbagamification/profile.php', ['userid' => $user->id]);
    $parent = $navigation->add(
        get_string('kbanavigation', 'local_kbagamification'),
        $profileurl,
        navigation_node::TYPE_SETTING,
        null,
        'local_kbagamification'
    );

    $parent->add(
        get_string('navprofile', 'local_kbagamification'),
        $profileurl,
        navigation_node::TYPE_SETTING,
        null,
        'local_kbagamification_profile'
    );
    $parent->add(
        get_string('navachievements', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/profile.php', ['userid' => $user->id, 'section' => 'achievements'], 'kba-achievements'),
        navigation_node::TYPE_SETTING,
        null,
        'local_kbagamification_achievements'
    );

    if ((bool) get_config('local_kbagamification', 'leaderboardsenabled')
            && has_capability('local/kbagamification:viewleaderboards', $systemcontext)) {
        $parent->add(
            get_string('navleaderboards', 'local_kbagamification'),
            new moodle_url('/local/kbagamification/leaderboards.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_kbagamification_leaderboards'
        );
    }

    if ($isown && (bool) get_config('local_kbagamification', 'rewardstoreenabled')
            && has_capability('local/kbagamification:viewstore', $systemcontext)) {
        $parent->add(
            get_string('navrewards', 'local_kbagamification'),
            new moodle_url('/local/kbagamification/store.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_kbagamification_rewards'
        );
        $parent->add(
            get_string('navorders', 'local_kbagamification'),
            new moodle_url('/local/kbagamification/orders.php'),
            navigation_node::TYPE_SETTING,
            null,
            'local_kbagamification_orders'
        );
    }
}

/**
 * Theme/integration helper for compact learner identity data.
 *
 * KBA Theme can call this helper without reading Gamification tables directly.
 *
 * @param int|null $userid Moodle user id, current user by default.
 * @return array
 */
function local_kbagamification_get_user_badge_data($userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = (int) $USER->id;
    }
    $userid = (int) $userid;

    $summary = \local_kbagamification\api::get_user_summary($userid);
    $showcoins = get_config('local_kbagamification', 'profile_showcoins');
    if ($showcoins === false) {
        $showcoins = 1;
    }

    return [
        'userid' => $userid,
        'levelname' => $summary['levelname'],
        'xp' => (int) $summary['xp'],
        'coins' => (int) $summary['coins'],
        'showcoins' => (bool) $showcoins,
        'achievementcount' => (int) $summary['achievementcount'],
        'profileurl' => (new moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]))->out(false),
    ];
}
