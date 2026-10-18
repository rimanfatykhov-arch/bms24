<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_kbagamification_category',
        get_string('pluginname', 'local_kbagamification')
    ));

    $settings = new admin_settingpage(
        'local_kbagamification_settings',
        get_string('settings', 'local_kbagamification')
    );
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/enabled',
        get_string('enabled', 'local_kbagamification'),
        get_string('enabled_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/achievementsenabled',
        get_string('achievementsenabled', 'local_kbagamification'),
        get_string('achievementsenabled_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/achievementnotifications',
        get_string('achievementnotifications', 'local_kbagamification'),
        get_string('achievementnotifications_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/leaderboardsenabled',
        get_string('leaderboardsenabled', 'local_kbagamification'),
        get_string('leaderboardsenabled_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbagamification/leaderboardlimit',
        get_string('leaderboardlimit', 'local_kbagamification'),
        get_string('leaderboardlimit_desc', 'local_kbagamification'),
        20,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/leaderboardshowcoins',
        get_string('leaderboardshowcoins', 'local_kbagamification'),
        get_string('leaderboardshowcoins_desc', 'local_kbagamification'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/teamleaderboardsenabled',
        get_string('teamleaderboardsenabled', 'local_kbagamification'),
        get_string('teamleaderboardsenabled_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/rewardstoreenabled',
        get_string('rewardstoreenabled', 'local_kbagamification'),
        get_string('rewardstoreenabled_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/personalcabinetintegration',
        get_string('personalcabinetintegration', 'local_kbagamification'),
        get_string('personalcabinetintegration_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/navigationenabled',
        get_string('navigationenabled', 'local_kbagamification'),
        get_string('navigationenabled_desc', 'local_kbagamification'),
        0
    ));
    $settings->add(new admin_setting_heading(
        'local_kbagamification/studentprofileheading',
        get_string('studentprofileheading', 'local_kbagamification'),
        get_string('studentprofileheading_desc', 'local_kbagamification')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/profile_showcoins',
        get_string('profile_showcoins', 'local_kbagamification'),
        get_string('profile_showcoins_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/profile_showlevelprogress',
        get_string('profile_showlevelprogress', 'local_kbagamification'),
        get_string('profile_showlevelprogress_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/profile_showlockedachievements',
        get_string('profile_showlockedachievements', 'local_kbagamification'),
        get_string('profile_showlockedachievements_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/profile_showhistory',
        get_string('profile_showhistory', 'local_kbagamification'),
        get_string('profile_showhistory_desc', 'local_kbagamification'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbagamification/profile_historylimit',
        get_string('profile_historylimit', 'local_kbagamification'),
        get_string('profile_historylimit_desc', 'local_kbagamification'),
        10,
        PARAM_INT
    ));
    $settings->add(new admin_setting_heading(
        'local_kbagamification/externalapiheading',
        get_string('externalapiheading', 'local_kbagamification'),
        get_string('externalapiheading_desc', 'local_kbagamification')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/externalapienabled',
        get_string('externalapienabled', 'local_kbagamification'),
        get_string('externalapienabled_desc', 'local_kbagamification'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/externalordercreation',
        get_string('externalordercreation', 'local_kbagamification'),
        get_string('externalordercreation_desc', 'local_kbagamification'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/externalreservationenabled',
        get_string('externalreservationenabled', 'local_kbagamification'),
        get_string('externalreservationenabled_desc', 'local_kbagamification'),
        0
    ));
    $settings->add(new admin_setting_configtext(
        'local_kbagamification/externalreservationttl',
        get_string('externalreservationttl', 'local_kbagamification'),
        get_string('externalreservationttl_desc', 'local_kbagamification'),
        900,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_kbagamification/externalcorporateshowcoins',
        get_string('externalcorporateshowcoins', 'local_kbagamification'),
        get_string('externalcorporateshowcoins_desc', 'local_kbagamification'),
        0
    ));
    $ADMIN->add('local_kbagamification_category', $settings);

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_rules',
        get_string('rules', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/rules.php'),
        'local/kbagamification:manage'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_levels',
        get_string('levels', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/levels.php'),
        'local/kbagamification:manage'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_achievements',
        get_string('achievements', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/achievements.php'),
        'local/kbagamification:manage'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_achievement_award',
        get_string('awardachievement', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/achievement_award.php'),
        'local/kbagamification:awardachievement'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_achievement_report',
        get_string('achievementreport', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/achievement_report.php'),
        'local/kbagamification:viewreports'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_leaderboards',
        get_string('leaderboards', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/leaderboards.php'),
        'local/kbagamification:viewallleaderboards'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_rewards',
        get_string('rewardcatalog', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/rewards.php'),
        'local/kbagamification:managecatalog'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_orders',
        get_string('allrewardorders', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/orders.php', ['all' => 1]),
        'local/kbagamification:viewallorders'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_award',
        get_string('manualaward', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/award.php'),
        'local/kbagamification:award'
    ));

    $ADMIN->add('local_kbagamification_category', new admin_externalpage(
        'local_kbagamification_report',
        get_string('transactions', 'local_kbagamification'),
        new moodle_url('/local/kbagamification/report.php'),
        'local/kbagamification:viewreports'
    ));
}
