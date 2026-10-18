<?php
namespace local_kbagamification\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for KBA Gamification.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_kbagam_wallet', [
            'userid' => 'privacy:metadata:wallet:userid',
            'xp' => 'privacy:metadata:wallet:xp',
            'coins' => 'privacy:metadata:wallet:coins',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:wallet');

        $collection->add_database_table('local_kbagam_awards', [
            'userid' => 'privacy:metadata:awards:userid',
            'source' => 'privacy:metadata:awards:source',
            'sourceid' => 'privacy:metadata:awards:sourceid',
            'xp' => 'privacy:metadata:wallet:xp',
            'coins' => 'privacy:metadata:wallet:coins',
            'reason' => 'privacy:metadata:awards:reason',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:awards');

        $collection->add_database_table('local_kbagam_txn', [
            'userid' => 'privacy:metadata:transactions:userid',
            'currency' => 'privacy:metadata:transactions:currency',
            'amount' => 'privacy:metadata:transactions:amount',
            'balancebefore' => 'privacy:metadata:transactions:balancebefore',
            'balanceafter' => 'privacy:metadata:transactions:balanceafter',
            'reason' => 'privacy:metadata:awards:reason',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:transactions');

        $collection->add_database_table('local_kbagam_userach', [
            'userid' => 'privacy:metadata:userachievements:userid',
            'achievementid' => 'privacy:metadata:userachievements:achievementid',
            'source' => 'privacy:metadata:userachievements:source',
            'timeawarded' => 'privacy:metadata:userachievements:timeawarded',
        ], 'privacy:metadata:userachievements');



        $collection->add_database_table('local_kbagam_reservations', [
            'userid' => 'privacy:metadata:reservations:userid',
            'rewardid' => 'privacy:metadata:reservations:rewardid',
            'costcoins' => 'privacy:metadata:reservations:costcoins',
            'status' => 'privacy:metadata:reservations:status',
            'externalref' => 'privacy:metadata:reservations:externalref',
            'expiresat' => 'privacy:metadata:reservations:expiresat',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:reservations');

        $collection->add_database_table('local_kbagam_orders', [
            'userid' => 'privacy:metadata:orders:userid',
            'rewardid' => 'privacy:metadata:orders:rewardid',
            'costcoins' => 'privacy:metadata:orders:costcoins',
            'status' => 'privacy:metadata:orders:status',
            'deliveryname' => 'privacy:metadata:orders:deliveryname',
            'deliveryphone' => 'privacy:metadata:orders:deliveryphone',
            'deliveryaddress' => 'privacy:metadata:orders:deliveryaddress',
            'usercomment' => 'privacy:metadata:orders:usercomment',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:orders');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        global $DB;
        if ($DB->record_exists('local_kbagam_wallet', ['userid' => $userid]) ||
                $DB->record_exists('local_kbagam_awards', ['userid' => $userid]) ||
                $DB->record_exists('local_kbagam_txn', ['userid' => $userid]) ||
                $DB->record_exists('local_kbagam_userach', ['userid' => $userid]) ||
                $DB->record_exists('local_kbagam_orders', ['userid' => $userid]) ||
                $DB->record_exists('local_kbagam_reservations', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            return;
        }

        $wallet = $DB->get_record('local_kbagam_wallet', ['userid' => $userid]);
        if ($wallet) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_kbagamification'), get_string('wallet', 'local_kbagamification')],
                (object) [
                    'xp' => $wallet->xp,
                    'coins' => $wallet->coins,
                    'timecreated' => transform::datetime($wallet->timecreated),
                    'timemodified' => transform::datetime($wallet->timemodified),
                ]
            );
        }

        $transactions = $DB->get_records('local_kbagam_txn', ['userid' => $userid], 'timecreated ASC, id ASC');
        $export = [];
        foreach ($transactions as $transaction) {
            $export[] = (object) [
                'currency' => $transaction->currency,
                'amount' => $transaction->amount,
                'balancebefore' => $transaction->balancebefore,
                'balanceafter' => $transaction->balanceafter,
                'source' => $transaction->source,
                'reason' => $transaction->reason,
                'timecreated' => transform::datetime($transaction->timecreated),
            ];
        }
        if ($export) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_kbagamification'), get_string('transactions', 'local_kbagamification')],
                (object) ['items' => $export]
            );
        }

        $sql = "SELECT ua.id, ua.timeawarded, ua.source, a.name, a.description
                  FROM {local_kbagam_userach} ua
                  JOIN {local_kbagam_achievements} a ON a.id = ua.achievementid
                 WHERE ua.userid = :userid
              ORDER BY ua.timeawarded ASC, ua.id ASC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        $items = [];
        foreach ($records as $record) {
            $items[] = (object) [
                'name' => $record->name,
                'description' => $record->description,
                'source' => $record->source,
                'timeawarded' => transform::datetime($record->timeawarded),
            ];
        }
        if ($items) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_kbagamification'), get_string('achievements', 'local_kbagamification')],
                (object) ['items' => $items]
            );
        }

        $sql = "SELECT rr.*, r.name AS rewardname
                  FROM {local_kbagam_reservations} rr
                  JOIN {local_kbagam_rewards} r ON r.id = rr.rewardid
                 WHERE rr.userid = :userid
              ORDER BY rr.timecreated ASC, rr.id ASC";
        $reservations = $DB->get_records_sql($sql, ['userid' => $userid]);
        $reservationitems = [];
        foreach ($reservations as $reservation) {
            $reservationitems[] = (object) [
                'reservationkey' => $reservation->reservationkey,
                'reward' => $reservation->rewardname,
                'costcoins' => $reservation->costcoins,
                'status' => $reservation->status,
                'externalref' => $reservation->externalref,
                'expiresat' => transform::datetime($reservation->expiresat),
                'timecreated' => transform::datetime($reservation->timecreated),
            ];
        }
        if ($reservationitems) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_kbagamification'), get_string('rewardreservations', 'local_kbagamification')],
                (object) ['items' => $reservationitems]
            );
        }

        $sql = "SELECT o.*, r.name AS rewardname
                  FROM {local_kbagam_orders} o
                  JOIN {local_kbagam_rewards} r ON r.id = o.rewardid
                 WHERE o.userid = :userid
              ORDER BY o.timecreated ASC, o.id ASC";
        $orders = $DB->get_records_sql($sql, ['userid' => $userid]);
        $orderitems = [];
        foreach ($orders as $order) {
            $orderitems[] = (object) [
                'ordernumber' => $order->ordernumber,
                'reward' => $order->rewardname,
                'costcoins' => $order->costcoins,
                'status' => $order->status,
                'deliveryname' => $order->deliveryname,
                'deliveryphone' => $order->deliveryphone,
                'deliveryaddress' => $order->deliveryaddress,
                'usercomment' => $order->usercomment,
                'timecreated' => transform::datetime($order->timecreated),
            ];
        }
        if ($orderitems) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_kbagamification'), get_string('myrewardorders', 'local_kbagamification')],
                (object) ['items' => $orderitems]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('local_kbagam_reservations');
        $DB->delete_records('local_kbagam_orders');
        $DB->delete_records('local_kbagam_userach');
        $DB->delete_records('local_kbagam_txn');
        $DB->delete_records('local_kbagam_awards');
        $DB->delete_records('local_kbagam_wallet');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_kbagam_reservations', ['userid' => $userid]);
        $DB->delete_records('local_kbagam_orders', ['userid' => $userid]);
        $DB->delete_records('local_kbagam_userach', ['userid' => $userid]);
        $DB->delete_records('local_kbagam_txn', ['userid' => $userid]);
        $DB->delete_records('local_kbagam_awards', ['userid' => $userid]);
        $DB->delete_records('local_kbagam_wallet', ['userid' => $userid]);
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $sql = "SELECT userid FROM {local_kbagam_wallet}
                UNION SELECT userid FROM {local_kbagam_awards}
                UNION SELECT userid FROM {local_kbagam_txn}
                UNION SELECT userid FROM {local_kbagam_userach}
                UNION SELECT userid FROM {local_kbagam_orders}
                UNION SELECT userid FROM {local_kbagam_reservations}";
        $userlist->add_from_sql('userid', $sql, []);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_kbagam_reservations', "userid $insql", $params);
        $DB->delete_records_select('local_kbagam_orders', "userid $insql", $params);
        $DB->delete_records_select('local_kbagam_userach', "userid $insql", $params);
        $DB->delete_records_select('local_kbagam_txn', "userid $insql", $params);
        $DB->delete_records_select('local_kbagam_awards', "userid $insql", $params);
        $DB->delete_records_select('local_kbagam_wallet', "userid $insql", $params);
    }
}
