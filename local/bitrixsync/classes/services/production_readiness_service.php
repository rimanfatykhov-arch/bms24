<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class production_readiness_service {
    private const CACHE_KEY = 'production_readiness_cache';

    public function get_cached(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            self::CACHE_KEY
        );

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function refresh(): array {
        global $CFG;

        $lockdir = $CFG->dataroot . '/temp';
        if (!is_dir($lockdir)) {
            @mkdir($lockdir, 0770, true);
        }

        $lockfile = $lockdir .
            '/local_bitrixsync_production_readiness.lock';

        $handle = @fopen($lockfile, 'c');
        if (!$handle) {
            throw new \moodle_exception(
                'production_lock_failed',
                'local_bitrixsync'
            );
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \moodle_exception(
                'production_refresh_busy',
                'local_bitrixsync'
            );
        }

        try {
            $result = $this->build();
            set_config(
                self::CACHE_KEY,
                json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),
                'local_bitrixsync'
            );

            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function build(): array {
        global $DB;

        $checks = [];

        // Remote Bitrix checks. Each request has a strict client timeout.
        try {
            $diag = (
                new \local_bitrixsync\services\bitrix_diagnostics()
            )->run();

            $tests = [];
            foreach ($diag['tests'] as $test) {
                $tests[$test['key']] = $test;
            }

            $this->add(
                $checks,
                'Bitrix24 webhook authorization',
                !empty($tests['profile']['success']),
                (string)($tests['profile']['message'] ?? '—')
            );

            $this->add(
                $checks,
                'Bitrix24 CRM scope',
                !empty($tests['crm']['success']),
                (string)($tests['crm']['message'] ?? '—')
            );

            $this->add(
                $checks,
                'Bitrix24 Catalog scope',
                !empty($tests['catalog']['success']),
                (string)($tests['catalog']['message'] ?? '—')
            );
        } catch (\Throwable $e) {
            $this->add(
                $checks,
                'Bitrix24 REST',
                false,
                $e->getMessage()
            );
        }

        // Corporate diagnostics are local PHP bridge calls.
        try {
            $corporate =
                new \local_bitrixsync\services\corporate_bridge();

            $cdiag = $corporate->diagnostic();

            $this->add(
                $checks,
                'KBA Corporate API v1.9+',
                !empty($cdiag['available']) &&
                    !empty($cdiag['compatible']),
                (string)(
                    $cdiag['contract']['api_release'] ?? '0'
                )
            );

            $this->add(
                $checks,
                'Corporate writesafe',
                !empty($cdiag['status']['writesafe']),
                !empty($cdiag['status']['writesafe'])
                    ? 'OK'
                    : 'Not writesafe'
            );

            $this->add(
                $checks,
                'Corporate licence API',
                !empty($cdiag['status']['licenseavailable']),
                !empty($cdiag['status']['licenseavailable'])
                    ? 'Available'
                    : 'Unavailable'
            );
        } catch (\Throwable $e) {
            $this->add(
                $checks,
                'KBA Corporate Dashboard',
                false,
                $e->getMessage()
            );
        }

        $totalcourses = (int)$DB->count_records_select(
            'course',
            'id <> ?',
            [SITEID]
        );

        $mappedids = [];

        $productmaptable = new \xmldb_table('local_bitrixsync_productmap');
        if ($DB->get_manager()->table_exists($productmaptable)) {
            $records = $DB->get_records(
                'local_bitrixsync_productmap',
                ['active' => 1],
                '',
                'courseid'
            );
            foreach ($records as $record) {
                if (!empty($record->courseid)) {
                    $mappedids[(int)$record->courseid] = true;
                }
            }
        }

        // Current production installation also contains authoritative
        // mappings in coursemap for historical/current Bitrix product rows.
        $coursemaptable = new \xmldb_table('local_bitrixsync_coursemap');
        if ($DB->get_manager()->table_exists($coursemaptable)) {
            $records = $DB->get_records(
                'local_bitrixsync_coursemap',
                null,
                '',
                'courseid,laststatus'
            );
            foreach ($records as $record) {
                $status = trim((string)($record->laststatus ?? ''));
                if (!empty($record->courseid) &&
                        ($status === '' || $status === 'active')) {
                    $mappedids[(int)$record->courseid] = true;
                }
            }
        }

        $mappedcourses = count($mappedids);

        $this->add(
            $checks,
            'Moodle Master Catalog coverage',
            $totalcourses > 0 &&
                $mappedcourses >= $totalcourses,
            $mappedcourses . ' / ' .
                $totalcourses . ' courses bound'
        );

        // IMPORTANT: no remote field-list request here.
        $fieldmanager =
            new \local_bitrixsync\services\crm_field_manager();

        $fieldsready = $fieldmanager->is_ready(false);

        $this->add(
            $checks,
            'Bitrix24 KBA CRM fields',
            $fieldsready,
            $fieldsready
                ? 'Ready (cached registry)'
                : 'Refresh/provision CRM Fields first'
        );

        $successfulreal = $DB->record_exists_select(
            'local_bitrixsync_dealrun',
            'dryrun = 0 AND failed = 0 ' .
            'AND status IN (:completed, :witherrors)',
            [
                'completed' => 'completed',
                'witherrors' => 'completed_with_errors',
            ]
        );

        $this->add(
            $checks,
            'Successful real v5 deal test',
            $successfulreal,
            $successfulreal
                ? 'Found'
                : 'Run at least one controlled Real Run'
        );

        $dupes = $DB->get_records_sql(
            "SELECT MIN(id) AS id,
                    dealid,
                    userid,
                    courseid,
                    COALESCE(productrowid, 0) AS productrowid,
                    COUNT(id) AS cnt
               FROM {local_bitrixsync_enrollment}
              WHERE status = :status
           GROUP BY dealid,
                    userid,
                    courseid,
                    COALESCE(productrowid, 0)
             HAVING COUNT(id) > 1",
            ['status' => 'completed']
        );

        $this->add(
            $checks,
            'Completed enrollment duplicates',
            count($dupes) === 0,
            count($dupes) === 0
                ? '0'
                : (string)count($dupes)
        );

        $this->add(
            $checks,
            'Queue cron',
            (bool)get_config(
                'local_bitrixsync',
                'queue_enabled'
            ),
            get_config(
                'local_bitrixsync',
                'queue_enabled'
            ) ? 'Enabled' : 'Disabled'
        );

        $this->add(
            $checks,
            'CRM writeback',
            (bool)get_config(
                'local_bitrixsync',
                'crm_writeback_enable'
            ),
            get_config(
                'local_bitrixsync',
                'crm_writeback_enable'
            ) ? 'Enabled' : 'Disabled'
        );

        $this->add(
            $checks,
            'CRM timeline',
            (bool)get_config(
                'local_bitrixsync',
                'crm_timeline_enable'
            ),
            get_config(
                'local_bitrixsync',
                'crm_timeline_enable'
            ) ? 'Enabled' : 'Disabled'
        );

        $outgoingtoken = trim((string)get_config(
            'local_bitrixsync',
            'outgoingtoken'
        ));

        $this->add(
            $checks,
            'Bitrix24 outgoing Application Token',
            $outgoingtoken !== '',
            $outgoingtoken !== ''
                ? 'Configured'
                : 'Missing'
        );

        $inboundtoken = (string)get_config(
            'local_bitrixsync',
            'v5_inbound_token'
        );

        $this->add(
            $checks,
            'v5 inbound webhook token',
            $inboundtoken !== '',
            $inboundtoken !== ''
                ? 'Generated'
                : 'Missing'
        );

        $inboundenabled = (bool)get_config(
            'local_bitrixsync',
            'v5_inbound_enabled'
        );

        $this->add(
            $checks,
            'v5 inbound webhook',
            $inboundenabled,
            $inboundenabled
                ? 'Enabled'
                : 'Disabled until Bitrix event is configured',
            false
        );

        $ready = true;
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                $ready = false;
                break;
            }
        }

        return [
            'time' => time(),
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    private function add(
        array &$checks,
        string $name,
        bool $ok,
        string $detail,
        bool $required = true
    ): void {
        $checks[] = [
            'name' => $name,
            'ok' => $ok,
            'detail' => $detail,
            'required' => $required,
        ];
    }
}
