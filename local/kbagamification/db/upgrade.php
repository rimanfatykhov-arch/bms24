<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade KBA Gamification.
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_local_kbagamification_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026081601) {
        $table = new xmldb_table('local_kbagam_achievements');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('icon', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'trophy');
        $table->add_field('rarity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'common');
        $table->add_field('conditiontype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('targetvalue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rewardxp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rewardcoins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('condition_enabled_ix', XMLDB_INDEX_NOTUNIQUE, ['conditiontype', 'enabled']);
        $table->add_index('sortorder_ix', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbagam_userach');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('achievementid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('source', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'automatic');
        $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('metadatajson', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timeawarded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('achievementid_fk', XMLDB_KEY_FOREIGN, ['achievementid'], 'local_kbagam_achievements', ['id']);
        $table->add_index('user_achievement_uix', XMLDB_INDEX_UNIQUE, ['userid', 'achievementid']);
        $table->add_index('userid_time_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timeawarded']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $now = time();
        $defaults = [
            ['defaultach_firststep_name', 'defaultach_firststep_desc', 'footsteps', 'common', 'activity_completed_count', 1, 25, 5, 10],
            ['defaultach_practitioner_name', 'defaultach_practitioner_desc', 'brain', 'common', 'activity_completed_count', 10, 100, 10, 20],
            ['defaultach_graduate_name', 'defaultach_graduate_desc', 'graduate', 'rare', 'course_completed_count', 1, 250, 25, 30],
            ['defaultach_expert_name', 'defaultach_expert_desc', 'star', 'epic', 'xp_total', 8000, 500, 50, 40],
        ];
        if ($DB->count_records('local_kbagam_achievements') === 0) {
            foreach ($defaults as $default) {
                $record = (object) [
                    'name' => get_string($default[0], 'local_kbagamification'),
                    'description' => get_string($default[1], 'local_kbagamification'),
                    'icon' => $default[2],
                    'rarity' => $default[3],
                    'conditiontype' => $default[4],
                    'targetvalue' => $default[5],
                    'courseid' => 0,
                    'rewardxp' => $default[6],
                    'rewardcoins' => $default[7],
                    'enabled' => 1,
                    'sortorder' => $default[8],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $DB->insert_record('local_kbagam_achievements', $record);
            }
        }

        set_config('achievementsenabled', 1, 'local_kbagamification');
        set_config('achievementnotifications', 1, 'local_kbagamification');

        upgrade_plugin_savepoint(true, 2026081601, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081602) {
        // 1.1.1 is a compatibility hotfix for Moodle 4.0 admin pages.
        // No database schema changes are required.
        upgrade_plugin_savepoint(true, 2026081602, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081603) {
        // 1.2.0 adds computed course/group/company leaderboards. No new tables are required.
        if (get_config('local_kbagamification', 'leaderboardsenabled') === false) {
            set_config('leaderboardsenabled', 1, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'leaderboardlimit') === false) {
            set_config('leaderboardlimit', 20, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'leaderboardshowcoins') === false) {
            set_config('leaderboardshowcoins', 0, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'teamleaderboardsenabled') === false) {
            set_config('teamleaderboardsenabled', 1, 'local_kbagamification');
        }
        upgrade_plugin_savepoint(true, 2026081603, 'local', 'kbagamification');
    }


    if ($oldversion < 2026081604) {
        $table = new xmldb_table('local_kbagam_rewards');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('rewardtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'physical');
        $table->add_field('icon', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'gift');
        $table->add_field('sku', XMLDB_TYPE_CHAR, '100', null, null);
        $table->add_field('costcoins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('stock', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '-1');
        $table->add_field('minlevelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('enabled_sort_ix', XMLDB_INDEX_NOTUNIQUE, ['enabled', 'sortorder']);
        $table->add_index('sku_ix', XMLDB_INDEX_NOTUNIQUE, ['sku']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_kbagam_orders');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ordernumber', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('rewardid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('quantity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('costcoins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'new');
        $table->add_field('deliveryname', XMLDB_TYPE_CHAR, '255', null, null);
        $table->add_field('deliveryphone', XMLDB_TYPE_CHAR, '100', null, null);
        $table->add_field('deliveryaddress', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('usercomment', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('admincomment', XMLDB_TYPE_TEXT, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecancelled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('rewardid_fk', XMLDB_KEY_FOREIGN, ['rewardid'], 'local_kbagam_rewards', ['id']);
        $table->add_index('ordernumber_uix', XMLDB_INDEX_UNIQUE, ['ordernumber']);
        $table->add_index('userid_time_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $table->add_index('status_time_ix', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        if ($DB->count_records('local_kbagam_rewards') === 0) {
            $now = time();
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
        if (get_config('local_kbagamification', 'rewardstoreenabled') === false) {
            set_config('rewardstoreenabled', 1, 'local_kbagamification');
        }
        upgrade_plugin_savepoint(true, 2026081604, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081605) {
        // 1.4.0 adds Web services declarations and corporate/rewards external APIs.
        // No database schema changes are required; both external kill switches default to off.
        if (get_config('local_kbagamification', 'externalapienabled') === false) {
            set_config('externalapienabled', 0, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'externalordercreation') === false) {
            set_config('externalordercreation', 0, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'externalcorporateshowcoins') === false) {
            set_config('externalcorporateshowcoins', 0, 'local_kbagamification');
        }
        upgrade_plugin_savepoint(true, 2026081605, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081700) {
        // 1.5.0 Student Experience. No database schema changes are required.
        // Existing XP, Coins, levels, achievements, transactions, rewards and orders are preserved.
        upgrade_plugin_savepoint(true, 2026081700, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081701) {
        // 1.6.0 introduces the dedicated KBA Learning Profile and display preferences.
        // No database schema changes are required.
        if (get_config('local_kbagamification', 'profile_showcoins') === false) {
            set_config('profile_showcoins', 1, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'profile_showlevelprogress') === false) {
            set_config('profile_showlevelprogress', 1, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'profile_showlockedachievements') === false) {
            set_config('profile_showlockedachievements', 1, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'profile_showhistory') === false) {
            set_config('profile_showhistory', 1, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'profile_historylimit') === false) {
            set_config('profile_historylimit', 10, 'local_kbagamification');
        }
        upgrade_plugin_savepoint(true, 2026081701, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081702) {
        // 1.6.1 Navigation UX adds discoverable learner navigation.
        // No database schema changes are required.
        if (get_config('local_kbagamification', 'navigationenabled') === false) {
            set_config('navigationenabled', 1, 'local_kbagamification');
        }
        upgrade_plugin_savepoint(true, 2026081702, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081703) {
        // 1.6.2 Russian terminology update: user-facing "KBA Coins" is now "KBA Баллы".
        // Internal DB fields, API keys and transaction currency codes remain unchanged.
        upgrade_plugin_savepoint(true, 2026081703, 'local', 'kbagamification');
    }


    if ($oldversion < 2026081704) {
        // 1.6.3 integrates Gamification into the existing KBA Personal Cabinet.
        // No database schema changes. The separate global KBA Profile navigation
        // is disabled by default to keep one clear learner entry point.
        if (get_config('local_kbagamification', 'personalcabinetintegration') === false) {
            set_config('personalcabinetintegration', 1, 'local_kbagamification');
        }
        set_config('navigationenabled', 0, 'local_kbagamification');
        upgrade_plugin_savepoint(true, 2026081704, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081705) {
        // KBA Gamification 1.6.4: API hotfix. No database schema changes.
        upgrade_plugin_savepoint(true, 2026081705, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081706) {
        // KBA Gamification 1.6.5: engagement UX API additions. No schema changes.
        upgrade_plugin_savepoint(true, 2026081706, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081707) {
        // KBA Gamification 1.7.0: Rewards API with temporary point/stock reservations.
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_kbagam_reservations');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('reservationkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rewardid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quantity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('costcoins', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'reserved');
            $table->add_field('externalref', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('metadatajson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecommitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecancelled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('rewardid_fk', XMLDB_KEY_FOREIGN, ['rewardid'], 'local_kbagam_rewards', ['id']);
            $table->add_index('reservationkey_uix', XMLDB_INDEX_UNIQUE, ['reservationkey']);
            $table->add_index('userid_status_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
            $table->add_index('reward_status_ix', XMLDB_INDEX_NOTUNIQUE, ['rewardid', 'status']);
            $table->add_index('expires_status_ix', XMLDB_INDEX_NOTUNIQUE, ['expiresat', 'status']);
            $table->add_index('externalref_ix', XMLDB_INDEX_NOTUNIQUE, ['externalref']);
            $dbman->create_table($table);
        }

        $ordertable = new xmldb_table('local_kbagam_orders');
        $field = new xmldb_field('reservationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($ordertable, $field)) {
            $dbman->add_field($ordertable, $field);
        }
        $field = new xmldb_field('externalref', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'reservationid');
        if (!$dbman->field_exists($ordertable, $field)) {
            $dbman->add_field($ordertable, $field);
        }
        $field = new xmldb_field('channel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'moodle', 'externalref');
        if (!$dbman->field_exists($ordertable, $field)) {
            $dbman->add_field($ordertable, $field);
        }
        $index = new xmldb_index('reservationid_ix', XMLDB_INDEX_NOTUNIQUE, ['reservationid']);
        if (!$dbman->index_exists($ordertable, $index)) {
            $dbman->add_index($ordertable, $index);
        }
        $index = new xmldb_index('externalref_ix', XMLDB_INDEX_NOTUNIQUE, ['externalref']);
        if (!$dbman->index_exists($ordertable, $index)) {
            $dbman->add_index($ordertable, $index);
        }

        if (get_config('local_kbagamification', 'externalreservationenabled') === false) {
            set_config('externalreservationenabled', 0, 'local_kbagamification');
        }
        if (get_config('local_kbagamification', 'externalreservationttl') === false) {
            set_config('externalreservationttl', 900, 'local_kbagamification');
        }

        upgrade_plugin_savepoint(true, 2026081707, 'local', 'kbagamification');
    }

    if ($oldversion < 2026081708) {
        // KBA Gamification 1.7.1: Russian UX rename to «KBA Награды». No schema changes.
        upgrade_plugin_savepoint(true, 2026081708, 'local', 'kbagamification');
    }

    return true;
}
