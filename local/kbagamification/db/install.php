<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Seed default KBA Gamification data.
 */
function xmldb_local_kbagamification_install() {
    global $DB;

    $now = time();

    $rules = [
        [
            'name' => get_string('defaultruleactivity', 'local_kbagamification'),
            'eventcode' => 'activity_completed',
            'xp' => 20,
            'coins' => 2,
            'sortorder' => 10,
        ],
        [
            'name' => get_string('defaultrulecourse', 'local_kbagamification'),
            'eventcode' => 'course_completed',
            'xp' => 500,
            'coins' => 50,
            'sortorder' => 20,
        ],
    ];

    foreach ($rules as $ruledata) {
        $rule = (object) $ruledata;
        $rule->enabled = 1;
        $rule->conditionsjson = null;
        $rule->timecreated = $now;
        $rule->timemodified = $now;
        $DB->insert_record('local_kbagam_rules', $rule);
    }

    $levels = [
        ['name' => 'Старт', 'minxp' => 0],
        ['name' => 'Исследователь', 'minxp' => 500],
        ['name' => 'Специалист', 'minxp' => 1500],
        ['name' => 'Практик', 'minxp' => 3000],
        ['name' => 'Профессионал', 'minxp' => 5000],
        ['name' => 'Эксперт', 'minxp' => 8000],
        ['name' => 'Мастер', 'minxp' => 12000],
        ['name' => 'Лидер', 'minxp' => 20000],
        ['name' => 'Наставник', 'minxp' => 30000],
        ['name' => 'KBA Legend', 'minxp' => 50000],
    ];

    $sortorder = 10;
    foreach ($levels as $leveldata) {
        $level = (object) $leveldata;
        $level->enabled = 1;
        $level->sortorder = $sortorder;
        $level->timecreated = $now;
        $level->timemodified = $now;
        $DB->insert_record('local_kbagam_levels', $level);
        $sortorder += 10;
    }

    $achievements = [
        [
            'name' => get_string('defaultach_firststep_name', 'local_kbagamification'),
            'description' => get_string('defaultach_firststep_desc', 'local_kbagamification'),
            'icon' => 'footsteps', 'rarity' => 'common', 'conditiontype' => 'activity_completed_count',
            'targetvalue' => 1, 'rewardxp' => 25, 'rewardcoins' => 5, 'sortorder' => 10,
        ],
        [
            'name' => get_string('defaultach_practitioner_name', 'local_kbagamification'),
            'description' => get_string('defaultach_practitioner_desc', 'local_kbagamification'),
            'icon' => 'brain', 'rarity' => 'common', 'conditiontype' => 'activity_completed_count',
            'targetvalue' => 10, 'rewardxp' => 100, 'rewardcoins' => 10, 'sortorder' => 20,
        ],
        [
            'name' => get_string('defaultach_graduate_name', 'local_kbagamification'),
            'description' => get_string('defaultach_graduate_desc', 'local_kbagamification'),
            'icon' => 'graduate', 'rarity' => 'rare', 'conditiontype' => 'course_completed_count',
            'targetvalue' => 1, 'rewardxp' => 250, 'rewardcoins' => 25, 'sortorder' => 30,
        ],
        [
            'name' => get_string('defaultach_expert_name', 'local_kbagamification'),
            'description' => get_string('defaultach_expert_desc', 'local_kbagamification'),
            'icon' => 'star', 'rarity' => 'epic', 'conditiontype' => 'xp_total',
            'targetvalue' => 8000, 'rewardxp' => 500, 'rewardcoins' => 50, 'sortorder' => 40,
        ],
    ];

    foreach ($achievements as $data) {
        $achievement = (object) $data;
        $achievement->courseid = 0;
        $achievement->enabled = 1;
        $achievement->timecreated = $now;
        $achievement->timemodified = $now;
        $DB->insert_record('local_kbagam_achievements', $achievement);
    }

    if ($DB->count_records('local_kbagam_rewards') === 0) {
        $defaults = [
            ['defaultreward_stickers', 'stickers', 'physical', 'KBA-STICKERS', 300, -1, 10],
            ['defaultreward_notebook', 'notebook', 'physical', 'KBA-NOTEBOOK', 500, -1, 20],
            ['defaultreward_mug', 'mug', 'physical', 'KBA-MUG', 800, -1, 30],
            ['defaultreward_tumbler', 'tumbler', 'physical', 'KBA-TUMBLER', 1200, -1, 40],
            ['defaultreward_tshirt', 'shirt', 'physical', 'KBA-TSHIRT', 2000, -1, 50],
            ['defaultreward_hoodie', 'hoodie', 'physical', 'KBA-HOODIE', 3500, -1, 60],
            ['defaultreward_masterclass', 'ticket', 'experience', 'KBA-MASTERCLASS', 1000, -1, 70],
            ['defaultreward_case', 'expert', 'experience', 'KBA-CASE', 3000, -1, 80],
        ];
        foreach ($defaults as $default) {
            $record = (object) [
                'name' => get_string($default[0] . '_name', 'local_kbagamification'),
                'description' => get_string($default[0] . '_desc', 'local_kbagamification'),
                'rewardtype' => $default[2],
                'icon' => $default[1],
                'sku' => $default[3],
                'costcoins' => $default[4],
                'stock' => $default[5],
                'minlevelid' => 0,
                'enabled' => 0,
                'sortorder' => $default[6],
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_kbagam_rewards', $record);
        }
    }

    set_config('enabled', 1, 'local_kbagamification');
    set_config('achievementsenabled', 1, 'local_kbagamification');
    set_config('achievementnotifications', 1, 'local_kbagamification');
    set_config('leaderboardsenabled', 1, 'local_kbagamification');
    set_config('leaderboardlimit', 20, 'local_kbagamification');
    set_config('leaderboardshowcoins', 0, 'local_kbagamification');
    set_config('teamleaderboardsenabled', 1, 'local_kbagamification');
    set_config('rewardstoreenabled', 1, 'local_kbagamification');
    set_config('externalapienabled', 0, 'local_kbagamification');
    set_config('externalordercreation', 0, 'local_kbagamification');
    set_config('externalreservationenabled', 0, 'local_kbagamification');
    set_config('externalreservationttl', 900, 'local_kbagamification');
    set_config('externalcorporateshowcoins', 0, 'local_kbagamification');
}
