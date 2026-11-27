<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared company write gate for communication content and access (RC33 PR-1, contract §6.4).
 *
 * Every writer that publishes communication content or reduces who may receive it takes this one
 * company-scoped named lock and re-checks access while holding it, so a send and a revocation are
 * serialized: a message whose checks ran before the revocation may commit, a later one fails.
 *
 * Fixed lock order; never acquire an earlier lock while holding a later one:
 *   1. object locks: taskdiscussion:*, bplandiscussion:*, dreportdiscussion:* (object discussion posts)
 *   2. member:sha1(company "\n" user)          employee_service::ensure_membership, membership_access_service::change
 *   3. communication:{company}                  this gate (the key predates PR-1: create_thread, leave_thread)
 *   4. commmsg:{company}:{message}              communication_service::change_message
 *
 * The guarantee holds only while the gate is held through the outermost database commit. Callers that
 * post inside their own transaction take the gate before starting it.
 */
final class communication_write_gate {
    /** Seconds to wait for the gate, as for the earlier communication lock. */
    public const TIMEOUT = 10;

    /** @var array<string, array{lock: \core\lock\lock, depth: int}> held gates of this PHP process */
    private static $held = [];

    /** @var string */
    private $key;

    /** @var bool */
    private $released = false;

    private function __construct(string $key) {
        $this->key = $key;
    }

    public static function lock_key(string $companyuuid): string {
        return 'communication:' . $companyuuid;
    }

    /** Whether this process currently holds the gate of the company. */
    public static function is_held(string $companyuuid): bool {
        return isset(self::$held[self::lock_key($companyuuid)]);
    }

    /**
     * Take the company gate. Re-entrant within one process: a nested writer (for example a message with
     * files, or a post that creates its object thread first) reuses the outer lock instead of waiting
     * for itself. Release every hold in a finally block.
     */
    public static function acquire(string $companyuuid): self {
        $key = self::lock_key($companyuuid);
        if (isset(self::$held[$key])) {
            self::$held[$key]['depth']++;
            return new self($key);
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock($key, self::TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('communication_lock', 'local_kbacompany');
        }
        self::$held[$key] = ['lock' => $lock, 'depth' => 1];
        return new self($key);
    }

    /** Release this hold; the named lock is freed when the outermost hold is released. Idempotent. */
    public function release(): void {
        if ($this->released || !isset(self::$held[$this->key])) {
            return;
        }
        $this->released = true;
        if (--self::$held[$this->key]['depth'] > 0) {
            return;
        }
        $lock = self::$held[$this->key]['lock'];
        unset(self::$held[$this->key]);
        $lock->release();
    }
}
