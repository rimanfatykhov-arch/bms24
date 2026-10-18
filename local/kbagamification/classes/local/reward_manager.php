<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Catalogue, purchasing and order workflow for KBA Rewards.
 *
 * @package local_kbagamification
 */
class reward_manager {
    const TYPE_PHYSICAL = 'physical';
    const TYPE_DIGITAL = 'digital';
    const TYPE_EXPERIENCE = 'experience';

    const STATUS_NEW = 'new';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PREPARING = 'preparing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_RECEIVED = 'received';
    const STATUS_CANCELLED = 'cancelled';

    const RESERVATION_RESERVED = 'reserved';
    const RESERVATION_COMMITTED = 'committed';
    const RESERVATION_CANCELLED = 'cancelled';
    const RESERVATION_EXPIRED = 'expired';

    /** @return bool */
    public static function is_store_enabled(): bool {
        return (bool) get_config('local_kbagamification', 'rewardstoreenabled');
    }

    /** @return array */
    public static function get_types(): array {
        return [
            self::TYPE_PHYSICAL => get_string('rewardtype_physical', 'local_kbagamification'),
            self::TYPE_DIGITAL => get_string('rewardtype_digital', 'local_kbagamification'),
            self::TYPE_EXPERIENCE => get_string('rewardtype_experience', 'local_kbagamification'),
        ];
    }

    /** @return array */
    public static function get_statuses(): array {
        return [
            self::STATUS_NEW => get_string('orderstatus_new', 'local_kbagamification'),
            self::STATUS_CONFIRMED => get_string('orderstatus_confirmed', 'local_kbagamification'),
            self::STATUS_PREPARING => get_string('orderstatus_preparing', 'local_kbagamification'),
            self::STATUS_SHIPPED => get_string('orderstatus_shipped', 'local_kbagamification'),
            self::STATUS_RECEIVED => get_string('orderstatus_received', 'local_kbagamification'),
            self::STATUS_CANCELLED => get_string('orderstatus_cancelled', 'local_kbagamification'),
        ];
    }

    /** @return array */
    public static function get_icons(): array {
        return [
            'gift' => get_string('rewardicon_gift', 'local_kbagamification'),
            'stickers' => get_string('rewardicon_stickers', 'local_kbagamification'),
            'notebook' => get_string('rewardicon_notebook', 'local_kbagamification'),
            'mug' => get_string('rewardicon_mug', 'local_kbagamification'),
            'tumbler' => get_string('rewardicon_tumbler', 'local_kbagamification'),
            'shirt' => get_string('rewardicon_shirt', 'local_kbagamification'),
            'hoodie' => get_string('rewardicon_hoodie', 'local_kbagamification'),
            'ticket' => get_string('rewardicon_ticket', 'local_kbagamification'),
            'expert' => get_string('rewardicon_expert', 'local_kbagamification'),
        ];
    }

    /** @param string $icon @return string */
    public static function icon_glyph(string $icon): string {
        $map = [
            'gift' => '🎁', 'stickers' => '✨', 'notebook' => '📓', 'mug' => '☕',
            'tumbler' => '🥤', 'shirt' => '👕', 'hoodie' => '🧥', 'ticket' => '🎟️', 'expert' => '💼',
        ];
        return $map[$icon] ?? '🎁';
    }

    /**
     * List catalogue rewards.
     *
     * @param bool $onlyenabled
     * @return array
     */
    public static function get_rewards(bool $onlyenabled = true): array {
        global $DB;
        $conditions = $onlyenabled ? ['enabled' => 1] : [];
        return $DB->get_records('local_kbagam_rewards', $conditions, 'sortorder ASC, costcoins ASC, id ASC');
    }

    /** @param int $rewardid @return \stdClass */
    public static function get_reward(int $rewardid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbagam_rewards', ['id' => $rewardid], '*', MUST_EXIST);
    }

    /**
     * Get currently reserved KBA Points for a user.
     * Expired reservations are ignored immediately even before the cleanup task marks them expired.
     *
     * @param int $userid
     * @param int $excludeid Optional reservation ID to exclude.
     * @return int
     */
    public static function get_reserved_coins(int $userid, int $excludeid = 0): int {
        global $DB;
        $now = time();
        $params = [
            'userid' => $userid,
            'status' => self::RESERVATION_RESERVED,
            'now' => $now,
        ];
        $exclude = '';
        if ($excludeid > 0) {
            $exclude = ' AND id <> :excludeid';
            $params['excludeid'] = $excludeid;
        }
        $sql = "SELECT COALESCE(SUM(costcoins), 0)
                  FROM {local_kbagam_reservations}
                 WHERE userid = :userid
                   AND status = :status
                   AND (expiresat = 0 OR expiresat > :now)" . $exclude;
        return (int) $DB->get_field_sql($sql, $params);
    }

    /**
     * Get total, reserved and available KBA Points for a user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_balance_state(int $userid): array {
        $wallet = engine::get_wallet($userid);
        $reserved = self::get_reserved_coins($userid);
        return [
            'coins' => (int) $wallet->coins,
            'reservedcoins' => $reserved,
            'availablecoins' => max(0, (int) $wallet->coins - $reserved),
        ];
    }

    /**
     * Validate an external reservation key.
     *
     * @param string $key
     * @return string
     */
    private static function normalise_reservation_key(string $key): string {
        $key = trim($key);
        if (strlen($key) < 8 || strlen($key) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw new \moodle_exception('invalidreservationkey', 'local_kbagamification');
        }
        return $key;
    }

    /**
     * Get reservation by key.
     *
     * @param string $reservationkey
     * @param int $strictness
     * @return \stdClass|false
     */
    public static function get_reservation(string $reservationkey, int $strictness = MUST_EXIST) {
        global $DB;
        $reservationkey = self::normalise_reservation_key($reservationkey);
        return $DB->get_record('local_kbagam_reservations', ['reservationkey' => $reservationkey], '*', $strictness);
    }

    /**
     * Convert a reservation record into an API-safe structure.
     *
     * @param \stdClass $reservation
     * @return array
     */
    public static function reservation_to_array(\stdClass $reservation): array {
        $balance = self::get_balance_state((int) $reservation->userid);
        return [
            'id' => (int) $reservation->id,
            'reservationkey' => $reservation->reservationkey,
            'userid' => (int) $reservation->userid,
            'rewardid' => (int) $reservation->rewardid,
            'quantity' => (int) $reservation->quantity,
            'costcoins' => (int) $reservation->costcoins,
            'status' => $reservation->status,
            'externalref' => (string) ($reservation->externalref ?? ''),
            'expiresat' => (int) $reservation->expiresat,
            'timecreated' => (int) $reservation->timecreated,
            'timemodified' => (int) $reservation->timemodified,
            'timecommitted' => (int) $reservation->timecommitted,
            'timecancelled' => (int) $reservation->timecancelled,
            'coinsbalance' => $balance['coins'],
            'reservedcoins' => $balance['reservedcoins'],
            'availablecoins' => $balance['availablecoins'],
        ];
    }

    /**
     * Check whether a user can buy the reward now.
     *
     * @param \stdClass $reward
     * @param int $userid
     * @return array [allowed => bool, reason => string, levelname => string]
     */
    public static function can_purchase(\stdClass $reward, int $userid): array {
        global $DB;

        if (!self::is_store_enabled()) {
            return ['allowed' => false, 'reason' => get_string('rewardstoredisabled', 'local_kbagamification'), 'levelname' => ''];
        }
        if (empty($reward->enabled)) {
            return ['allowed' => false, 'reason' => get_string('rewardunavailable', 'local_kbagamification'), 'levelname' => ''];
        }
        if ((int) $reward->stock === 0) {
            return ['allowed' => false, 'reason' => get_string('rewardoutofstock', 'local_kbagamification'), 'levelname' => ''];
        }

        $wallet = engine::get_wallet($userid);
        $balance = self::get_balance_state($userid);
        if ((int) $balance['availablecoins'] < (int) $reward->costcoins) {
            return ['allowed' => false, 'reason' => get_string('rewardnotenoughcoins', 'local_kbagamification'), 'levelname' => ''];
        }

        $levelname = '';
        if (!empty($reward->minlevelid)) {
            $level = $DB->get_record('local_kbagam_levels', ['id' => $reward->minlevelid]);
            if (!$level || empty($level->enabled)) {
                return ['allowed' => false, 'reason' => get_string('rewardunavailable', 'local_kbagamification'), 'levelname' => ''];
            }
            $levelname = format_string($level->name);
            if ((int) $wallet->xp < (int) $level->minxp) {
                return [
                    'allowed' => false,
                    'reason' => get_string('rewardlevelrequired', 'local_kbagamification', $levelname),
                    'levelname' => $levelname,
                ];
            }
        }

        return ['allowed' => true, 'reason' => '', 'levelname' => $levelname];
    }

    /**
     * Reserve a reward and KBA Points for an external checkout.
     * Coins are not debited yet; they become unavailable to all other spend operations.
     * Finite stock is reserved immediately and restored on cancellation/expiry.
     *
     * @param int $rewardid
     * @param int $userid
     * @param string $reservationkey Idempotency key generated by the trusted storefront.
     * @param int $ttl Reservation lifetime in seconds.
     * @param string $externalref Optional storefront/cart reference.
     * @param array $metadata Optional integration metadata.
     * @return \stdClass
     */
    public static function reserve(
        int $rewardid,
        int $userid,
        string $reservationkey,
        int $ttl = 900,
        string $externalref = '',
        array $metadata = []
    ): \stdClass {
        global $DB;

        $reservationkey = self::normalise_reservation_key($reservationkey);
        $existing = $DB->get_record('local_kbagam_reservations', ['reservationkey' => $reservationkey]);
        if ($existing) {
            if ((int) $existing->userid !== $userid || (int) $existing->rewardid !== $rewardid) {
                throw new \moodle_exception('reservationkeyconflict', 'local_kbagamification');
            }
            return $existing;
        }

        $ttl = max(60, min(3600, $ttl));
        $rewardlockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardstore');
        $rewardlock = $rewardlockfactory->get_lock('reward:' . $rewardid, 10);
        if (!$rewardlock) {
            throw new \moodle_exception('rewardlocktimeout', 'local_kbagamification');
        }
        $walletlockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_wallet');
        $walletlock = $walletlockfactory->get_lock('user:' . $userid, 10);
        if (!$walletlock) {
            $rewardlock->release();
            throw new \moodle_exception('walletlocktimeout', 'local_kbagamification');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $reward = self::get_reward($rewardid);
            $eligibility = self::can_purchase($reward, $userid);
            if (!$eligibility['allowed']) {
                throw new \moodle_exception('rewardpurchaseblocked', 'local_kbagamification', '', $eligibility['reason']);
            }

            // Re-check idempotency inside the serialised section.
            $existing = $DB->get_record('local_kbagam_reservations', ['reservationkey' => $reservationkey]);
            if ($existing) {
                $transaction->allow_commit();
                return $existing;
            }

            $now = time();
            $reservation = (object) [
                'reservationkey' => $reservationkey,
                'userid' => $userid,
                'rewardid' => $rewardid,
                'quantity' => 1,
                'costcoins' => (int) $reward->costcoins,
                'status' => self::RESERVATION_RESERVED,
                'externalref' => clean_param($externalref, PARAM_TEXT),
                'metadatajson' => empty($metadata) ? null : json_encode($metadata),
                'expiresat' => $now + $ttl,
                'timecreated' => $now,
                'timemodified' => $now,
                'timecommitted' => 0,
                'timecancelled' => 0,
            ];
            $reservation->id = $DB->insert_record('local_kbagam_reservations', $reservation);

            if ((int) $reward->stock > 0) {
                $reward->stock = (int) $reward->stock - 1;
                $reward->timemodified = $now;
                $DB->update_record('local_kbagam_rewards', $reward);
            }

            $transaction->allow_commit();
        } finally {
            $walletlock->release();
            $rewardlock->release();
        }

        self::trigger_reservation_event('created', $reservation);
        return $reservation;
    }

    /**
     * Commit an existing reservation, debit the points and create the Moodle mirror order.
     *
     * @param string $reservationkey
     * @param string $externalref Bitrix/store order reference.
     * @param array $delivery Delivery details supplied by storefront.
     * @return array Reservation and order.
     */
    public static function commit_reservation(
        string $reservationkey,
        string $externalref = '',
        array $delivery = []
    ): array {
        global $DB;

        $reservation = self::get_reservation($reservationkey);
        if ($reservation->status === self::RESERVATION_COMMITTED) {
            $order = $DB->get_record('local_kbagam_orders', ['reservationid' => $reservation->id]);
            return ['reservation' => $reservation, 'order' => $order ?: null];
        }
        if ($reservation->status !== self::RESERVATION_RESERVED) {
            throw new \moodle_exception('reservationnotactive', 'local_kbagamification');
        }
        if ((int) $reservation->expiresat > 0 && (int) $reservation->expiresat <= time()) {
            self::cancel_reservation($reservationkey, '', true);
            throw new \moodle_exception('reservationexpired', 'local_kbagamification');
        }

        $rewardlockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardstore');
        $rewardlock = $rewardlockfactory->get_lock('reward:' . $reservation->rewardid, 10);
        if (!$rewardlock) {
            throw new \moodle_exception('rewardlocktimeout', 'local_kbagamification');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $reservation = $DB->get_record('local_kbagam_reservations', ['id' => $reservation->id], '*', MUST_EXIST);
            if ($reservation->status === self::RESERVATION_COMMITTED) {
                $order = $DB->get_record('local_kbagam_orders', ['reservationid' => $reservation->id]);
                $transaction->allow_commit();
                return ['reservation' => $reservation, 'order' => $order ?: null];
            }
            if ($reservation->status !== self::RESERVATION_RESERVED) {
                throw new \moodle_exception('reservationnotactive', 'local_kbagamification');
            }

            $reward = self::get_reward((int) $reservation->rewardid);
            if ($reward->rewardtype === self::TYPE_PHYSICAL) {
                if (empty(trim($delivery['deliveryname'] ?? '')) || empty(trim($delivery['deliveryphone'] ?? '')) ||
                        empty(trim($delivery['deliveryaddress'] ?? ''))) {
                    throw new \moodle_exception('deliveryrequired', 'local_kbagamification');
                }
            }

            // Mark committed first inside the same DB transaction so engine::adjust does not count
            // this reservation as protected funds while charging it. Other sessions still see the
            // old row until this outer transaction commits.
            $now = time();
            $reservation->status = self::RESERVATION_COMMITTED;
            $reservation->externalref = clean_param($externalref ?: $reservation->externalref, PARAM_TEXT);
            $reservation->timemodified = $now;
            $reservation->timecommitted = $now;
            $DB->update_record('local_kbagam_reservations', $reservation);

            $order = (object) [
                'ordernumber' => 'TMP-' . random_string(20),
                'userid' => (int) $reservation->userid,
                'rewardid' => (int) $reservation->rewardid,
                'quantity' => (int) $reservation->quantity,
                'costcoins' => (int) $reservation->costcoins,
                'status' => self::STATUS_NEW,
                'reservationid' => (int) $reservation->id,
                'externalref' => (string) $reservation->externalref,
                'channel' => 'external',
                'deliveryname' => clean_param($delivery['deliveryname'] ?? '', PARAM_TEXT),
                'deliveryphone' => clean_param($delivery['deliveryphone'] ?? '', PARAM_TEXT),
                'deliveryaddress' => clean_param($delivery['deliveryaddress'] ?? '', PARAM_TEXT),
                'usercomment' => clean_param($delivery['usercomment'] ?? '', PARAM_TEXT),
                'admincomment' => '',
                'timecreated' => $now,
                'timemodified' => $now,
                'timecompleted' => 0,
                'timecancelled' => 0,
            ];
            $order->id = $DB->insert_record('local_kbagam_orders', $order);
            $order->ordernumber = 'KBA-' . userdate($now, '%Y%m%d') . '-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $DB->update_record('local_kbagam_orders', $order);

            engine::adjust(
                (int) $reservation->userid,
                0,
                -((int) $reservation->costcoins),
                get_string('reasonrewardpurchase', 'local_kbagamification', format_string($reward->name)),
                'reward_purchase_external',
                (int) $order->id,
                0,
                sha1('reward_reservation_commit:' . $reservation->id),
                0,
                [
                    'rewardid' => (int) $reservation->rewardid,
                    'reservationid' => (int) $reservation->id,
                    'reservationkey' => $reservation->reservationkey,
                    'ordernumber' => $order->ordernumber,
                    'externalref' => $reservation->externalref,
                ]
            );

            $transaction->allow_commit();
        } finally {
            $rewardlock->release();
        }

        self::trigger_reservation_event('committed', $reservation);
        $event = \local_kbagamification\event\reward_order_created::create([
            'objectid' => $order->id,
            'context' => \context_system::instance(),
            'relateduserid' => $order->userid,
            'other' => ['rewardid' => $order->rewardid, 'costcoins' => (int) $order->costcoins],
        ]);
        $event->add_record_snapshot('local_kbagam_orders', $order);
        $event->trigger();

        return ['reservation' => $reservation, 'order' => $order];
    }

    /**
     * Cancel or expire a reservation and release finite stock.
     *
     * @param string $reservationkey
     * @param string $externalref Optional external reference/reason.
     * @param bool $expired Mark as expired instead of cancelled.
     * @return \stdClass
     */
    public static function cancel_reservation(
        string $reservationkey,
        string $externalref = '',
        bool $expired = false
    ): \stdClass {
        global $DB;

        $reservation = self::get_reservation($reservationkey);
        if (in_array($reservation->status, [self::RESERVATION_CANCELLED, self::RESERVATION_EXPIRED], true)) {
            return $reservation;
        }
        if ($reservation->status === self::RESERVATION_COMMITTED) {
            throw new \moodle_exception('reservationalreadycommitted', 'local_kbagamification');
        }

        $rewardlockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardstore');
        $rewardlock = $rewardlockfactory->get_lock('reward:' . $reservation->rewardid, 10);
        if (!$rewardlock) {
            throw new \moodle_exception('rewardlocktimeout', 'local_kbagamification');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $reservation = $DB->get_record('local_kbagam_reservations', ['id' => $reservation->id], '*', MUST_EXIST);
            if ($reservation->status !== self::RESERVATION_RESERVED) {
                $transaction->allow_commit();
                return $reservation;
            }
            $now = time();
            $reservation->status = $expired ? self::RESERVATION_EXPIRED : self::RESERVATION_CANCELLED;
            if ($externalref !== '') {
                $reservation->externalref = clean_param($externalref, PARAM_TEXT);
            }
            $reservation->timemodified = $now;
            $reservation->timecancelled = $now;
            $DB->update_record('local_kbagam_reservations', $reservation);

            $reward = self::get_reward((int) $reservation->rewardid);
            if ((int) $reward->stock >= 0) {
                $reward->stock = (int) $reward->stock + (int) $reservation->quantity;
                $reward->timemodified = $now;
                $DB->update_record('local_kbagam_rewards', $reward);
            }
            $transaction->allow_commit();
        } finally {
            $rewardlock->release();
        }

        self::trigger_reservation_event($expired ? 'expired' : 'cancelled', $reservation);
        return $reservation;
    }

    /**
     * Mark expired reservations and release their stock.
     *
     * @param int $limit
     * @return int Number processed.
     */
    public static function expire_reservations(int $limit = 200): int {
        global $DB;
        $now = time();
        $sql = "SELECT *
                  FROM {local_kbagam_reservations}
                 WHERE status = :status
                   AND expiresat > 0
                   AND expiresat <= :now
              ORDER BY expiresat ASC, id ASC";
        $records = $DB->get_records_sql($sql, ['status' => self::RESERVATION_RESERVED, 'now' => $now], 0, max(1, $limit));
        $count = 0;
        foreach ($records as $record) {
            try {
                self::cancel_reservation($record->reservationkey, '', true);
                $count++;
            } catch (\Throwable $e) {
                debugging('KBA Gamification reservation expiry failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $count;
    }

    /**
     * Trigger reservation lifecycle events when classes are available.
     *
     * @param string $action
     * @param \stdClass $reservation
     */
    private static function trigger_reservation_event(string $action, \stdClass $reservation): void {
        $class = '\\local_kbagamification\\event\\reward_reservation_' . $action;
        if (!class_exists($class)) {
            return;
        }
        $event = $class::create([
            'objectid' => $reservation->id,
            'context' => \context_system::instance(),
            'relateduserid' => $reservation->userid,
            'other' => [
                'rewardid' => (int) $reservation->rewardid,
                'costcoins' => (int) $reservation->costcoins,
                'reservationkey' => $reservation->reservationkey,
            ],
        ]);
        $event->add_record_snapshot('local_kbagam_reservations', $reservation);
        $event->trigger();
    }

    /**
     * Purchase one reward and debit KBA Coins.
     *
     * @param int $rewardid
     * @param int $userid
     * @param array $delivery
     * @return \stdClass Created order.
     */
    public static function purchase(int $rewardid, int $userid, array $delivery = []): \stdClass {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardstore');
        $lock = $lockfactory->get_lock('reward:' . $rewardid, 10);
        if (!$lock) {
            throw new \moodle_exception('rewardlocktimeout', 'local_kbagamification');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $reward = self::get_reward($rewardid);
            $eligibility = self::can_purchase($reward, $userid);
            if (!$eligibility['allowed']) {
                throw new \moodle_exception('rewardpurchaseblocked', 'local_kbagamification', '', $eligibility['reason']);
            }

            if ($reward->rewardtype === self::TYPE_PHYSICAL) {
                if (empty(trim($delivery['deliveryname'] ?? '')) || empty(trim($delivery['deliveryphone'] ?? '')) ||
                        empty(trim($delivery['deliveryaddress'] ?? ''))) {
                    throw new \moodle_exception('deliveryrequired', 'local_kbagamification');
                }
            }

            $now = time();
            $order = (object) [
                'ordernumber' => 'TMP-' . random_string(20),
                'userid' => $userid,
                'rewardid' => $rewardid,
                'quantity' => 1,
                'costcoins' => (int) $reward->costcoins,
                'status' => self::STATUS_NEW,
                'reservationid' => 0,
                'externalref' => '',
                'channel' => 'moodle',
                'deliveryname' => clean_param($delivery['deliveryname'] ?? '', PARAM_TEXT),
                'deliveryphone' => clean_param($delivery['deliveryphone'] ?? '', PARAM_TEXT),
                'deliveryaddress' => clean_param($delivery['deliveryaddress'] ?? '', PARAM_TEXT),
                'usercomment' => clean_param($delivery['usercomment'] ?? '', PARAM_TEXT),
                'admincomment' => '',
                'timecreated' => $now,
                'timemodified' => $now,
                'timecompleted' => 0,
                'timecancelled' => 0,
            ];
            $order->id = $DB->insert_record('local_kbagam_orders', $order);
            $order->ordernumber = 'KBA-' . userdate($now, '%Y%m%d') . '-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $DB->update_record('local_kbagam_orders', $order);

            engine::adjust(
                $userid,
                0,
                -((int) $reward->costcoins),
                get_string('reasonrewardpurchase', 'local_kbagamification', format_string($reward->name)),
                'reward_purchase',
                (int) $order->id,
                $userid,
                sha1('reward_purchase:order:' . $order->id),
                0,
                ['rewardid' => $rewardid, 'ordernumber' => $order->ordernumber]
            );

            if ((int) $reward->stock > 0) {
                $reward->stock = (int) $reward->stock - 1;
                $reward->timemodified = $now;
                $DB->update_record('local_kbagam_rewards', $reward);
            }

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $event = \local_kbagamification\event\reward_order_created::create([
            'objectid' => $order->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => ['rewardid' => $rewardid, 'costcoins' => (int) $order->costcoins],
        ]);
        $event->add_record_snapshot('local_kbagam_orders', $order);
        $event->trigger();

        return $order;
    }

    /**
     * Change an order status. Cancellation refunds Coins and restocks finite stock.
     *
     * @param int $orderid
     * @param string $newstatus
     * @param int $actorid
     * @param string $admincomment
     * @return \stdClass
     */
    public static function change_status(int $orderid, string $newstatus, int $actorid, string $admincomment = ''): \stdClass {
        global $DB;

        $statuses = self::get_statuses();
        if (!isset($statuses[$newstatus])) {
            throw new \moodle_exception('invalidorderstatus', 'local_kbagamification');
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardorders');
        $lock = $lockfactory->get_lock('order:' . $orderid, 10);
        if (!$lock) {
            throw new \moodle_exception('orderlocktimeout', 'local_kbagamification');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $order = $DB->get_record('local_kbagam_orders', ['id' => $orderid], '*', MUST_EXIST);
            $oldstatus = $order->status;

            if ($oldstatus === $newstatus) {
                $transaction->allow_commit();
                return $order;
            }
            if ($oldstatus === self::STATUS_CANCELLED || $oldstatus === self::STATUS_RECEIVED) {
                throw new \moodle_exception('orderterminalstatus', 'local_kbagamification');
            }

            $allowed = [
                self::STATUS_NEW => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
                self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
                self::STATUS_PREPARING => [self::STATUS_SHIPPED, self::STATUS_CANCELLED],
                self::STATUS_SHIPPED => [self::STATUS_RECEIVED],
            ];
            if (!in_array($newstatus, $allowed[$oldstatus] ?? [], true)) {
                throw new \moodle_exception('invalidordertransition', 'local_kbagamification');
            }

            $now = time();
            if ($newstatus === self::STATUS_CANCELLED) {
                // Serialize finite-stock restoration against simultaneous purchases of the same reward.
                $rewardlockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_rewardstore');
                $rewardlock = $rewardlockfactory->get_lock('reward:' . $order->rewardid, 10);
                if (!$rewardlock) {
                    throw new \moodle_exception('rewardlocktimeout', 'local_kbagamification');
                }
                try {
                    engine::adjust(
                        (int) $order->userid,
                        0,
                        (int) $order->costcoins,
                        get_string('reasonrewardrefund', 'local_kbagamification', $order->ordernumber),
                        'reward_refund',
                        (int) $order->id,
                        $actorid,
                        sha1('reward_refund:order:' . $order->id),
                        0,
                        ['rewardid' => (int) $order->rewardid, 'ordernumber' => $order->ordernumber]
                    );
                    $reward = self::get_reward((int) $order->rewardid);
                    if ((int) $reward->stock >= 0) {
                        $reward->stock = (int) $reward->stock + 1;
                        $reward->timemodified = $now;
                        $DB->update_record('local_kbagam_rewards', $reward);
                    }
                } finally {
                    $rewardlock->release();
                }
                $order->timecancelled = $now;
            }
            if ($newstatus === self::STATUS_RECEIVED) {
                $order->timecompleted = $now;
            }

            $order->status = $newstatus;
            $order->admincomment = clean_param($admincomment, PARAM_TEXT);
            $order->timemodified = $now;
            $DB->update_record('local_kbagam_orders', $order);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $event = \local_kbagamification\event\reward_order_status_changed::create([
            'objectid' => $order->id,
            'context' => \context_system::instance(),
            'relateduserid' => $order->userid,
            'other' => ['oldstatus' => $oldstatus, 'newstatus' => $newstatus],
        ]);
        $event->add_record_snapshot('local_kbagam_orders', $order);
        $event->trigger();

        return $order;
    }

    /** @param int $userid @return array */
    public static function get_user_orders(int $userid): array {
        global $DB;
        $sql = "SELECT o.*, r.name AS rewardname, r.icon AS rewardicon, r.rewardtype
                  FROM {local_kbagam_orders} o
                  JOIN {local_kbagam_rewards} r ON r.id = o.rewardid
                 WHERE o.userid = :userid
              ORDER BY o.timecreated DESC, o.id DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /** @param string $status @return array */
    public static function get_all_orders(string $status = ''): array {
        global $DB;
        $params = [];
        $where = '';
        if ($status !== '') {
            $where = 'WHERE o.status = :status';
            $params['status'] = $status;
        }
        $sql = "SELECT o.*, r.name AS rewardname, r.icon AS rewardicon, r.rewardtype,
                       u.firstname, u.lastname, u.email
                  FROM {local_kbagam_orders} o
                  JOIN {local_kbagam_rewards} r ON r.id = o.rewardid
                  JOIN {user} u ON u.id = o.userid
                  $where
              ORDER BY o.timecreated DESC, o.id DESC";
        return $DB->get_records_sql($sql, $params);
    }

    /** @param int $orderid @return \stdClass */
    public static function get_order(int $orderid): \stdClass {
        global $DB;
        $sql = "SELECT o.*, r.name AS rewardname, r.description AS rewarddescription,
                       r.icon AS rewardicon, r.rewardtype, r.sku,
                       u.firstname, u.lastname, u.email
                  FROM {local_kbagam_orders} o
                  JOIN {local_kbagam_rewards} r ON r.id = o.rewardid
                  JOIN {user} u ON u.id = o.userid
                 WHERE o.id = :id";
        return $DB->get_record_sql($sql, ['id' => $orderid], MUST_EXIST);
    }
}
