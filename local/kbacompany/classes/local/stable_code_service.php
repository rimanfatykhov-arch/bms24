<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company-scoped stable human-readable code allocator.
 *
 * Canonical identity remains UUID. Stable codes are display/reference identifiers
 * and are never reused once allocated, even if the source record is later archived.
 */
class stable_code_service {
    public const CONTRACT_VERSION = '0.1.0';

    /**
     * Allocate the next stable code for one company/entity scope.
     *
     * The counter is persisted before the caller writes its business record. This
     * deliberately allows gaps after failed creates; avoiding reuse is more important
     * than producing a gap-free sequence.
     *
     * @param string $companyuuid Canonical BMS Company UUID.
     * @param string $scope Stable entity scope, e.g. adminscale:plan.
     * @param string $prefix Human-readable prefix, e.g. PLN.
     * @param callable $available function(string $candidate): bool; true when candidate is unused.
     * @param int $minimum Existing maximum numeric suffix for safe adoption of legacy/manual codes.
     * @return string
     */
    public static function next(string $companyuuid, string $scope, string $prefix, callable $available, int $minimum = 0): string {
        global $DB;

        $companyuuid = trim($companyuuid);
        $scope = strtolower(trim($scope));
        $prefix = strtoupper(trim($prefix));
        if ($companyuuid === '' || strlen($companyuuid) > 36 || !preg_match('/^[a-z0-9_.:-]{1,64}$/', $scope) ||
                !preg_match('/^[A-Z0-9]{1,12}$/', $prefix) || $minimum < 0) {
            throw new \invalid_parameter_exception('Invalid stable-code scope');
        }

        $lockkey = 'stablecode:' . sha1($companyuuid . '|' . $scope);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock($lockkey, 10);
        if (!$lock) {
            throw new \moodle_exception('stablecode_lock', 'local_kbacompany');
        }

        try {
            $row = $DB->get_record('local_kbacompany_codecounter', [
                'companyuuid' => $companyuuid,
                'entityscope' => $scope,
            ]);
            $number = max($row ? (int)$row->lastnumber : 0, $minimum);
            $candidate = '';
            do {
                $number++;
                $candidate = $prefix . '-' . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
                if ($number > 999999999) {
                    throw new \moodle_exception('stablecode_exhausted', 'local_kbacompany');
                }
            } while (!$available($candidate));

            $now = time();
            if ($row) {
                $row->lastnumber = $number;
                $row->timemodified = $now;
                $DB->update_record('local_kbacompany_codecounter', $row);
            } else {
                $DB->insert_record('local_kbacompany_codecounter', (object)[
                    'companyuuid' => $companyuuid,
                    'entityscope' => $scope,
                    'lastnumber' => $number,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            return $candidate;
        } finally {
            $lock->release();
        }
    }
}
