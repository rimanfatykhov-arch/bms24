<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC33 inbound refusal with a stable, safe category (contract §5). The message never contains payload text,
 * external IDs or another company's identifiers.
 */
class inbound_exception extends \moodle_exception {
    public const CATEGORIES = ['unauthenticated', 'scope_denied', 'payload_invalid', 'idempotency_conflict',
        'retryable_unavailable'];

    /** @var string */
    public $category;

    public function __construct(string $category, string $debuginfo = '') {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \coding_exception('Unknown inbound refusal category');
        }
        $this->category = $category;
        parent::__construct('inbound_' . $category, 'local_kbacompany', '', null, $debuginfo);
    }
}
