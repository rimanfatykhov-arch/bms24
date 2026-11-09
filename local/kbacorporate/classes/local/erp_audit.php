<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Privacy-safe operational audit trail for the stable ERP exchange. */
class erp_audit {
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    /** Record one request without storing payloads, raw IDs, PII or exception messages. */
    public static function record(string $system, string $entitytype, string $externalid,
            string $idempotencykey, string $status, float $startedat, int $companyid = 0,
            ?\Throwable $error = null): void {
        global $CFG, $DB;

        try {
            $table = new \xmldb_table('local_kbacorp_erp_log');
            if (!$DB->get_manager()->table_exists($table)) {
                return;
            }
            $secret = !empty($CFG->passwordsaltmain) ? (string)$CFG->passwordsaltmain : (string)$CFG->wwwroot;
            $errorcode = null;
            if ($error) {
                $errorcode = preg_replace('/[^a-zA-Z0-9_.-]/', '_', get_class($error));
                $errorcode = substr((string)$errorcode, 0, 80);
            }
            $record = [
                'companyid' => max(0, $companyid),
                'system' => substr($system, 0, 30),
                'entitytype' => substr($entitytype, 0, 30),
                'externalidhash' => hash_hmac('sha256', $externalid, $secret),
                'idempotencyhash' => $idempotencykey === '' ? null : hash_hmac('sha256', $idempotencykey, $secret),
                'status' => $status === self::STATUS_SUCCESS ? self::STATUS_SUCCESS : self::STATUS_FAILURE,
                'durationms' => max(0, min(PHP_INT_MAX, (int)round((microtime(true) - $startedat) * 1000))),
                'errorcode' => $errorcode,
                'timecreated' => time(),
            ];
            // SYSTEM is reserved in MySQL 8; generic Moodle INSERT does not quote
            // field names. This fixed-column insert retains bound, privacy-safe values.
            $systemcolumn = $DB->get_dbfamily() === 'mysql' ? '`system`' : 'system';
            $DB->execute('INSERT INTO {local_kbacorp_erp_log} ' .
                '(companyid, ' . $systemcolumn . ', entitytype, externalidhash, idempotencyhash, ' .
                'status, durationms, errorcode, timecreated) VALUES ' .
                '(:companyid, :system, :entitytype, :externalidhash, :idempotencyhash, ' .
                ':status, :durationms, :errorcode, :timecreated)', $record);
        } catch (\Throwable $ignored) {
            // Observability must never change the result of the business request.
        }
    }

    /** Aggregate recent operational statistics without exposing identifiers. */
    public static function statistics(int $periodseconds = 86400): array {
        global $DB;

        $table = new \xmldb_table('local_kbacorp_erp_log');
        if (!$DB->get_manager()->table_exists($table)) {
            return ['available' => false, 'requests' => 0, 'successes' => 0, 'failures' => 0, 'average_ms' => 0];
        }
        $since = time() - max(60, min(2592000, $periodseconds));
        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS requests,
                    SUM(CASE WHEN status = :success THEN 1 ELSE 0 END) AS successes,
                    SUM(CASE WHEN status = :failure THEN 1 ELSE 0 END) AS failures,
                    AVG(durationms) AS average_ms
               FROM {local_kbacorp_erp_log}
              WHERE timecreated >= :since",
            ['success' => self::STATUS_SUCCESS, 'failure' => self::STATUS_FAILURE, 'since' => $since]
        );
        return [
            'available' => true,
            'requests' => (int)($row->requests ?? 0),
            'successes' => (int)($row->successes ?? 0),
            'failures' => (int)($row->failures ?? 0),
            'average_ms' => (int)round((float)($row->average_ms ?? 0)),
        ];
    }

    /** Remove expired technical logs only; business records are never touched. */
    public static function prune(?int $retentiondays = null): int {
        global $DB;

        $table = new \xmldb_table('local_kbacorp_erp_log');
        if (!$DB->get_manager()->table_exists($table)) {
            return 0;
        }
        if ($retentiondays === null) {
            $retentiondays = (int)get_config('local_kbacorporate', 'erpauditretentiondays');
        }
        $retentiondays = max(30, min(3650, $retentiondays ?: 180));
        $cutoff = time() - ($retentiondays * DAYSECS);
        $count = $DB->count_records_select('local_kbacorp_erp_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if ($count) {
            $DB->delete_records_select('local_kbacorp_erp_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        }
        return (int)$count;
    }
}
