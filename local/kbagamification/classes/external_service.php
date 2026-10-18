<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_kbagamification;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_kbagamification\local\leaderboard_manager;
use local_kbagamification\local\reward_manager;

/**
 * External web-service facade for KBA Gamification.
 *
 * The external layer validates parameters and permissions, then delegates all
 * business logic to the stable internal API. This keeps web-service transport
 * concerns out of the XP, achievements and Rewards engines.
 *
 * @package local_kbagamification
 */
class external_service extends external_api {
    /**
     * Ensure the Rewards API kill switch is enabled and the caller is authorised.
     */
    private static function require_rewards_access(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/kbagamification:externalrewards', $context);
        if (!(bool) get_config('local_kbagamification', 'externalapienabled')) {
            throw new \moodle_exception('externalapidisabled', 'local_kbagamification');
        }
    }

    /**
     * Ensure the Corporate API kill switch is enabled and the caller is authorised.
     */
    private static function require_corporate_access(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/kbagamification:externalcorporate', $context);
        if (!(bool) get_config('local_kbagamification', 'externalapienabled')) {
            throw new \moodle_exception('externalapidisabled', 'local_kbagamification');
        }
    }

    /**
     * Validate a Moodle user target.
     *
     * @param int $userid
     * @return int
     */
    private static function validate_userid(int $userid): int {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduserid', 'local_kbagamification');
        }
        return $userid;
    }

    /**
     * Parameters for get_rewards_catalog().
     *
     * @return external_function_parameters
     */
    public static function get_rewards_catalog_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID used for balance/eligibility checks'),
        ]);
    }

    /**
     * Get the enabled Rewards catalogue for a Moodle user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_rewards_catalog(int $userid): array {
        $params = self::validate_parameters(self::get_rewards_catalog_parameters(), ['userid' => $userid]);
        self::require_rewards_access();
        $userid = self::validate_userid((int) $params['userid']);
        return api::get_rewards_catalog($userid);
    }

    /**
     * Return structure for get_rewards_catalog().
     *
     * @return external_multiple_structure
     */
    public static function get_rewards_catalog_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Reward ID'),
            'name' => new external_value(PARAM_TEXT, 'Reward name'),
            'description' => new external_value(PARAM_RAW, 'Plain-text description'),
            'type' => new external_value(PARAM_ALPHA, 'Reward type'),
            'icon' => new external_value(PARAM_RAW, 'Presentation icon'),
            'sku' => new external_value(PARAM_RAW, 'SKU'),
            'costcoins' => new external_value(PARAM_INT, 'Cost in KBA Coins'),
            'stock' => new external_value(PARAM_INT, 'Current stock; -1 means unlimited'),
            'minlevelid' => new external_value(PARAM_INT, 'Required level ID or 0'),
            'minlevelname' => new external_value(PARAM_TEXT, 'Required level name or empty'),
            'allowed' => new external_value(PARAM_BOOL, 'Whether the user can purchase now'),
            'blockedreason' => new external_value(PARAM_RAW, 'Human-readable reason when blocked'),
        ]));
    }

    /**
     * Parameters for get_user_summary().
     *
     * @return external_function_parameters
     */
    public static function get_user_summary_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
        ]);
    }

    /**
     * Get XP/Coins/level summary for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_summary(int $userid): array {
        $params = self::validate_parameters(self::get_user_summary_parameters(), ['userid' => $userid]);
        self::require_rewards_access();
        $userid = self::validate_userid((int) $params['userid']);
        return api::get_user_summary($userid);
    }

    /**
     * Return structure for get_user_summary().
     *
     * @return external_single_structure
     */
    public static function get_user_summary_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'xp' => new external_value(PARAM_INT, 'KBA XP'),
            'coins' => new external_value(PARAM_INT, 'Current KBA Coins balance'),
            'reservedcoins' => new external_value(PARAM_INT, 'KBA Coins reserved by active marketplace checkout reservations'),
            'availablecoins' => new external_value(PARAM_INT, 'KBA Coins currently available to spend'),
            'levelid' => new external_value(PARAM_INT, 'Current level ID'),
            'levelname' => new external_value(PARAM_TEXT, 'Current level name'),
            'nextlevelid' => new external_value(PARAM_INT, 'Next level ID or 0'),
            'nextlevelname' => new external_value(PARAM_TEXT, 'Next level name'),
            'levelprogress' => new external_value(PARAM_FLOAT, 'Progress toward the next level, percent'),
            'achievementcount' => new external_value(PARAM_INT, 'Awarded achievement count'),
        ]);
    }

    /**
     * Parameters for get_user_achievements().
     *
     * @return external_function_parameters
     */
    public static function get_user_achievements_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
        ]);
    }

    /**
     * Get awarded achievements for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_achievements(int $userid): array {
        $params = self::validate_parameters(self::get_user_achievements_parameters(), ['userid' => $userid]);
        self::require_rewards_access();
        $userid = self::validate_userid((int) $params['userid']);
        return api::get_user_achievements($userid);
    }

    /**
     * Return structure for get_user_achievements().
     *
     * @return external_multiple_structure
     */
    public static function get_user_achievements_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Achievement ID'),
            'name' => new external_value(PARAM_TEXT, 'Achievement name'),
            'description' => new external_value(PARAM_RAW, 'Plain-text description'),
            'icon' => new external_value(PARAM_RAW, 'Presentation icon'),
            'rarity' => new external_value(PARAM_ALPHANUMEXT, 'Rarity'),
            'rewardxp' => new external_value(PARAM_INT, 'XP bonus'),
            'rewardcoins' => new external_value(PARAM_INT, 'Coins bonus'),
            'timeawarded' => new external_value(PARAM_INT, 'Unix timestamp'),
            'source' => new external_value(PARAM_RAW, 'Award source'),
        ]));
    }

    /** Parameters for check_reward_eligibility(). */
    public static function check_reward_eligibility_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
        ]);
    }

    /** Check balance, stock and level eligibility before storefront checkout. */
    public static function check_reward_eligibility(int $userid, int $rewardid): array {
        $params = self::validate_parameters(self::check_reward_eligibility_parameters(), [
            'userid' => $userid, 'rewardid' => $rewardid,
        ]);
        self::require_rewards_access();
        $userid = self::validate_userid((int) $params['userid']);
        return api::check_reward_eligibility((int) $params['rewardid'], $userid);
    }

    /** Return structure for check_reward_eligibility(). */
    public static function check_reward_eligibility_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'rewardname' => new external_value(PARAM_TEXT, 'Reward name'),
            'sku' => new external_value(PARAM_RAW, 'Reward SKU'),
            'costcoins' => new external_value(PARAM_INT, 'KBA Points price'),
            'allowed' => new external_value(PARAM_BOOL, 'Whether reservation can be created now'),
            'blockedreason' => new external_value(PARAM_TEXT, 'Reason when blocked'),
            'levelname' => new external_value(PARAM_TEXT, 'Required/current level label'),
            'coinsbalance' => new external_value(PARAM_INT, 'Total KBA Points balance'),
            'reservedcoins' => new external_value(PARAM_INT, 'Reserved KBA Points'),
            'availablecoins' => new external_value(PARAM_INT, 'Available KBA Points'),
        ]);
    }

    /** Parameters for reserve_reward(). */
    public static function reserve_reward_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'reservationkey' => new external_value(PARAM_RAW_TRIMMED, 'Storefront idempotency key, 8-64 safe characters'),
            'ttl' => new external_value(PARAM_INT, 'Reservation lifetime in seconds', VALUE_DEFAULT, 900),
            'externalref' => new external_value(PARAM_TEXT, 'Optional cart/checkout reference', VALUE_DEFAULT, ''),
        ]);
    }

    /** Reserve KBA Points and finite reward stock for an external checkout. */
    public static function reserve_reward(
        int $userid,
        int $rewardid,
        string $reservationkey,
        int $ttl = 900,
        string $externalref = ''
    ): array {
        $params = self::validate_parameters(self::reserve_reward_parameters(), compact(
            'userid', 'rewardid', 'reservationkey', 'ttl', 'externalref'
        ));
        self::require_rewards_access();
        if (!(bool) get_config('local_kbagamification', 'externalreservationenabled')) {
            throw new \moodle_exception('externalreservationdisabled', 'local_kbagamification');
        }
        $userid = self::validate_userid((int) $params['userid']);
        $defaultttl = (int) get_config('local_kbagamification', 'externalreservationttl');
        $ttl = (int) $params['ttl'];
        if ($ttl <= 0) {
            $ttl = $defaultttl > 0 ? $defaultttl : 900;
        }
        return api::reserve_reward(
            (int) $params['rewardid'], $userid, $params['reservationkey'], $ttl, $params['externalref']
        );
    }

    /** Standard reservation return structure. */
    private static function reservation_returns_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Reservation ID'),
            'reservationkey' => new external_value(PARAM_RAW, 'Idempotency key'),
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'quantity' => new external_value(PARAM_INT, 'Quantity'),
            'costcoins' => new external_value(PARAM_INT, 'Reserved KBA Points'),
            'status' => new external_value(PARAM_ALPHA, 'Reservation state'),
            'externalref' => new external_value(PARAM_TEXT, 'External checkout/order reference'),
            'expiresat' => new external_value(PARAM_INT, 'Unix expiry timestamp'),
            'timecreated' => new external_value(PARAM_INT, 'Unix creation timestamp'),
            'timemodified' => new external_value(PARAM_INT, 'Unix modification timestamp'),
            'timecommitted' => new external_value(PARAM_INT, 'Unix commit timestamp'),
            'timecancelled' => new external_value(PARAM_INT, 'Unix cancellation/expiry timestamp'),
            'coinsbalance' => new external_value(PARAM_INT, 'Total KBA Points balance'),
            'reservedcoins' => new external_value(PARAM_INT, 'Total active reserved points'),
            'availablecoins' => new external_value(PARAM_INT, 'Available KBA Points'),
        ]);
    }

    /** Return structure for reserve_reward(). */
    public static function reserve_reward_returns(): external_single_structure {
        return self::reservation_returns_structure();
    }

    /** Parameters for get_reward_reservation(). */
    public static function get_reward_reservation_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reservationkey' => new external_value(PARAM_RAW_TRIMMED, 'Reservation idempotency key'),
        ]);
    }

    /** Read reservation state. */
    public static function get_reward_reservation(string $reservationkey): array {
        $params = self::validate_parameters(self::get_reward_reservation_parameters(), ['reservationkey' => $reservationkey]);
        self::require_rewards_access();
        return api::get_reward_reservation($params['reservationkey']);
    }

    /** Return structure for get_reward_reservation(). */
    public static function get_reward_reservation_returns(): external_single_structure {
        return self::reservation_returns_structure();
    }

    /** Parameters for commit_reward_reservation(). */
    public static function commit_reward_reservation_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reservationkey' => new external_value(PARAM_RAW_TRIMMED, 'Reservation idempotency key'),
            'externalref' => new external_value(PARAM_TEXT, 'Bitrix/store order reference'),
            'deliveryname' => new external_value(PARAM_TEXT, 'Recipient name', VALUE_DEFAULT, ''),
            'deliveryphone' => new external_value(PARAM_TEXT, 'Recipient phone', VALUE_DEFAULT, ''),
            'deliveryaddress' => new external_value(PARAM_TEXT, 'Delivery address', VALUE_DEFAULT, ''),
            'usercomment' => new external_value(PARAM_TEXT, 'User comment', VALUE_DEFAULT, ''),
        ]);
    }

    /** Commit reservation after the external store has created its order. */
    public static function commit_reward_reservation(
        string $reservationkey,
        string $externalref,
        string $deliveryname = '',
        string $deliveryphone = '',
        string $deliveryaddress = '',
        string $usercomment = ''
    ): array {
        $params = self::validate_parameters(self::commit_reward_reservation_parameters(), compact(
            'reservationkey', 'externalref', 'deliveryname', 'deliveryphone', 'deliveryaddress', 'usercomment'
        ));
        self::require_rewards_access();
        if (!(bool) get_config('local_kbagamification', 'externalreservationenabled')) {
            throw new \moodle_exception('externalreservationdisabled', 'local_kbagamification');
        }
        return api::commit_reward_reservation($params['reservationkey'], $params['externalref'], [
            'deliveryname' => $params['deliveryname'],
            'deliveryphone' => $params['deliveryphone'],
            'deliveryaddress' => $params['deliveryaddress'],
            'usercomment' => $params['usercomment'],
        ]);
    }

    /** Return structure for commit_reward_reservation(). */
    public static function commit_reward_reservation_returns(): external_single_structure {
        return new external_single_structure([
            'reservation' => self::reservation_returns_structure(),
            'orderid' => new external_value(PARAM_INT, 'Moodle mirror order ID'),
            'ordernumber' => new external_value(PARAM_RAW, 'Moodle mirror order number'),
            'orderstatus' => new external_value(PARAM_ALPHA, 'Moodle mirror order status'),
            'externalref' => new external_value(PARAM_TEXT, 'Bitrix/store order reference'),
        ]);
    }

    /** Parameters for cancel_reward_reservation(). */
    public static function cancel_reward_reservation_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reservationkey' => new external_value(PARAM_RAW_TRIMMED, 'Reservation idempotency key'),
            'externalref' => new external_value(PARAM_TEXT, 'Optional cancellation reference/reason', VALUE_DEFAULT, ''),
        ]);
    }

    /** Cancel an uncommitted reservation and release stock/points. */
    public static function cancel_reward_reservation(string $reservationkey, string $externalref = ''): array {
        $params = self::validate_parameters(self::cancel_reward_reservation_parameters(), compact('reservationkey', 'externalref'));
        self::require_rewards_access();
        if (!(bool) get_config('local_kbagamification', 'externalreservationenabled')) {
            throw new \moodle_exception('externalreservationdisabled', 'local_kbagamification');
        }
        return api::cancel_reward_reservation($params['reservationkey'], $params['externalref']);
    }

    /** Return structure for cancel_reward_reservation(). */
    public static function cancel_reward_reservation_returns(): external_single_structure {
        return self::reservation_returns_structure();
    }

    /**
     * Parameters for get_user_reward_orders().
     *
     * @return external_function_parameters
     */
    public static function get_user_reward_orders_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
        ]);
    }

    /**
     * Get reward order history for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_reward_orders(int $userid): array {
        $params = self::validate_parameters(self::get_user_reward_orders_parameters(), ['userid' => $userid]);
        self::require_rewards_access();
        $userid = self::validate_userid((int) $params['userid']);
        return api::get_user_reward_orders($userid);
    }

    /**
     * Return structure for get_user_reward_orders().
     *
     * @return external_multiple_structure
     */
    public static function get_user_reward_orders_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Order ID'),
            'ordernumber' => new external_value(PARAM_ALPHANUMEXT, 'Human-readable order number'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'rewardname' => new external_value(PARAM_TEXT, 'Reward name'),
            'costcoins' => new external_value(PARAM_INT, 'Coins charged'),
            'status' => new external_value(PARAM_ALPHA, 'Machine status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Localised status label'),
            'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
        ]));
    }

    /**
     * Parameters for create_reward_order().
     *
     * @return external_function_parameters
     */
    public static function create_reward_order_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'deliveryname' => new external_value(PARAM_TEXT, 'Recipient name', VALUE_DEFAULT, ''),
            'deliveryphone' => new external_value(PARAM_TEXT, 'Recipient phone', VALUE_DEFAULT, ''),
            'deliveryaddress' => new external_value(PARAM_TEXT, 'Delivery address', VALUE_DEFAULT, ''),
            'usercomment' => new external_value(PARAM_TEXT, 'User comment', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Create a Rewards order on behalf of a Moodle user.
     *
     * @param int $userid
     * @param int $rewardid
     * @param string $deliveryname
     * @param string $deliveryphone
     * @param string $deliveryaddress
     * @param string $usercomment
     * @return array
     */
    public static function create_reward_order(
        int $userid,
        int $rewardid,
        string $deliveryname = '',
        string $deliveryphone = '',
        string $deliveryaddress = '',
        string $usercomment = ''
    ): array {
        $params = self::validate_parameters(self::create_reward_order_parameters(), [
            'userid' => $userid,
            'rewardid' => $rewardid,
            'deliveryname' => $deliveryname,
            'deliveryphone' => $deliveryphone,
            'deliveryaddress' => $deliveryaddress,
            'usercomment' => $usercomment,
        ]);
        self::require_rewards_access();
        if (!(bool) get_config('local_kbagamification', 'externalordercreation')) {
            throw new \moodle_exception('externalordercreationdisabled', 'local_kbagamification');
        }
        $userid = self::validate_userid((int) $params['userid']);
        $rewardid = (int) $params['rewardid'];
        if ($rewardid <= 0) {
            throw new \moodle_exception('invalidreward', 'local_kbagamification');
        }

        $order = reward_manager::purchase($rewardid, $userid, [
            'deliveryname' => $params['deliveryname'],
            'deliveryphone' => $params['deliveryphone'],
            'deliveryaddress' => $params['deliveryaddress'],
            'usercomment' => $params['usercomment'],
        ]);
        $summary = api::get_user_summary($userid);

        return [
            'id' => (int) $order->id,
            'ordernumber' => $order->ordernumber,
            'userid' => (int) $order->userid,
            'rewardid' => (int) $order->rewardid,
            'costcoins' => (int) $order->costcoins,
            'status' => $order->status,
            'timecreated' => (int) $order->timecreated,
            'coinsbalance' => (int) $summary['coins'],
        ];
    }

    /**
     * Return structure for create_reward_order().
     *
     * @return external_single_structure
     */
    public static function create_reward_order_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Order ID'),
            'ordernumber' => new external_value(PARAM_ALPHANUMEXT, 'Human-readable order number'),
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'rewardid' => new external_value(PARAM_INT, 'Reward ID'),
            'costcoins' => new external_value(PARAM_INT, 'Coins charged'),
            'status' => new external_value(PARAM_ALPHA, 'Order status'),
            'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
            'coinsbalance' => new external_value(PARAM_INT, 'Balance after purchase'),
        ]);
    }

    /**
     * Parameters for get_company_snapshot().
     *
     * @return external_function_parameters
     */
    public static function get_company_snapshot_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Employee Moodle user ID'),
                'Company employee Moodle user IDs'
            ),
            'limit' => new external_value(PARAM_INT, 'Leaderboard row limit; 0 means all', VALUE_DEFAULT, 20),
            'metric' => new external_value(PARAM_ALPHA, 'xp or achievements', VALUE_DEFAULT, 'xp'),
        ]);
    }

    /**
     * Get an aggregate corporate snapshot for a supplied employee cohort.
     *
     * Company membership stays the responsibility of KBA Corporate Dashboard.
     * Gamification only receives Moodle user IDs and calculates totals/ranking.
     *
     * @param array $userids
     * @param int $limit
     * @param string $metric
     * @return array
     */
    public static function get_company_snapshot(array $userids, int $limit = 20, string $metric = 'xp'): array {
        $params = self::validate_parameters(self::get_company_snapshot_parameters(), [
            'userids' => $userids,
            'limit' => $limit,
            'metric' => $metric,
        ]);
        self::require_corporate_access();

        $userids = array_values(array_unique(array_filter(array_map('intval', $params['userids']), static function($id) {
            return $id > 0;
        })));
        if (count($userids) > 5000) {
            throw new \moodle_exception('externaltoomanyusers', 'local_kbagamification');
        }
        $limit = max(0, min(500, (int) $params['limit']));
        $metric = leaderboard_manager::normalise_metric((string) $params['metric']);

        $snapshot = api::get_company_snapshot($userids, $limit, $metric);
        if (!(bool) get_config('local_kbagamification', 'externalcorporateshowcoins')) {
            $snapshot['summary']['totalcoins'] = 0;
            foreach ($snapshot['leaderboard'] as &$row) {
                $row['coins'] = 0;
            }
            unset($row);
        }
        return $snapshot;
    }

    /**
     * Return structure for get_company_snapshot().
     *
     * @return external_single_structure
     */
    public static function get_company_snapshot_returns(): external_single_structure {
        return new external_single_structure([
            'summary' => new external_single_structure([
                'participants' => new external_value(PARAM_INT, 'Active non-suspended Moodle users included'),
                'totalxp' => new external_value(PARAM_INT, 'Combined KBA XP'),
                'averagexp' => new external_value(PARAM_INT, 'Average KBA XP'),
                'totalcoins' => new external_value(PARAM_INT, 'Combined current Coins balances'),
                'achievementcount' => new external_value(PARAM_INT, 'Total awarded achievements'),
                'averageachievements' => new external_value(PARAM_FLOAT, 'Average achievements per participant'),
                'leveldistribution' => new external_multiple_structure(new external_single_structure([
                    'levelid' => new external_value(PARAM_INT, 'Level ID'),
                    'levelname' => new external_value(PARAM_TEXT, 'Level name'),
                    'count' => new external_value(PARAM_INT, 'Number of employees at level'),
                ])),
            ]),
            'leaderboard' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'xp' => new external_value(PARAM_INT, 'KBA XP'),
                'coins' => new external_value(PARAM_INT, 'Current KBA Coins balance'),
                'achievementcount' => new external_value(PARAM_INT, 'Awarded achievements'),
                'levelid' => new external_value(PARAM_INT, 'Level ID'),
                'levelname' => new external_value(PARAM_TEXT, 'Level name'),
                'rank' => new external_value(PARAM_INT, 'Rank'),
            ])),
            'metric' => new external_value(PARAM_ALPHA, 'Effective ranking metric'),
        ]);
    }
}
