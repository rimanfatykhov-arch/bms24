<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Integration Health Center service.
 *
 * Checks are deliberately read-only. Persisting a run stores only technical
 * aggregate diagnostics in local_kbacorp_health_log.
 */
class health {
    const STATUS_HEALTHY = 'healthy';
    const STATUS_WARNING = 'warning';
    const STATUS_CRITICAL = 'critical';

    /** Required Corporate Dashboard tables for the current release. */
    private static function required_tables(): array {
        return [
            'local_kbacorp_company',
            'local_kbacorp_member',
            'local_kbacorp_course',
            'local_kbacorp_department',
            'local_kbacorp_dept_member',
            'local_kbacorp_license',
            'local_kbacorp_license_user',
            'local_kbacorp_health_log',
            'local_kbacorp_busrole',
            'local_kbacorp_rolecomp',
            'local_kbacorp_rolever',
            'local_kbacorp_rolevercomp',
            'local_kbacorp_member_busrole',
            'local_kbacorp_audit',
            'local_kbacorp_audit_item',
            'local_kbacorp_audit_ev',
            'local_kbacorp_devplan',
            'local_kbacorp_devitem',
            'local_kbacorp_devlog',
            'local_kbacorp_bms_tool',
            'local_kbacorp_bms_toolver',
            'local_kbacorp_bms_crit',
            'local_kbacorp_bms_comp',
            'local_kbacorp_impl_audit',
            'local_kbacorp_impl_item',
            'local_kbacorp_bms_route',
            'local_kbacorp_impl_task',
            'local_kbacorp_impl_tasklog',
            'local_kbacorp_impl_check',
            'local_kbacorp_impl_comment',
            'local_kbacorp_impl_read',
            'local_kbacorp_impl_evid',
            'local_kbacorp_impl_evlog',
            'local_kbacorp_impl_econ',
            'local_kbacorp_impl_package',
            'local_kbacorp_impl_program',
            'local_kbacorp_extmap',
            'local_kbacorp_econ_ind',
            'local_kbacorp_econ_val',
            'local_kbacorp_econ_log',
            'local_kbacorp_erp_log',
        ];
    }

    /** Run a complete health check. */
    public static function check(bool $remote = true): array {
        global $CFG, $DB;

        $components = [];
        $errors = 0;
        $warnings = 0;

        $disk = bridge::get_module_info();
        $versionok = !empty($disk['build']) && (int)$disk['build'] === (int)$disk['dbbuild'];
        $components['moodle'] = self::component(
            $versionok ? self::STATUS_HEALTHY : self::STATUS_WARNING,
            $versionok ? 'Runtime and plugin DB version are aligned.' : 'Plugin files and Moodle DB version differ.',
            [
                'moodle' => isset($CFG->release) ? (string)$CFG->release : '',
                'php' => PHP_VERSION,
                'component' => bridge::COMPONENT,
                'release' => $disk['release'],
                'build' => $disk['build'],
                'dbbuild' => $disk['dbbuild'],
            ]
        );

        $dbman = $DB->get_manager();
        $tables = [];
        foreach (self::required_tables() as $tablename) {
            $exists = $dbman->table_exists(new \xmldb_table($tablename));
            $tables[$tablename] = $exists;
            if (!$exists) {
                $errors++;
            }
        }
        $components['database'] = self::component(
            $errors ? self::STATUS_CRITICAL : self::STATUS_HEALTHY,
            $errors ? 'One or more required Corporate Dashboard tables are missing.' : 'Required Corporate Dashboard tables are available.',
            ['tables' => $tables]
        );

        $client = new kba_connect_client();
        $cfg = $client->configuration_status();
        $connectstatus = $cfg['paired'] ? self::STATUS_HEALTHY : self::STATUS_WARNING;
        $connectmessage = $cfg['paired'] ? 'KBA Connect pairing is configured.' : 'KBA Connect is not paired.';
        $connectdetails = $cfg;
        if ($remote && $cfg['paired']) {
            $response = $client->connection_status();
            $connectdetails['remote'] = self::safe_remote($response);
            if (empty($response['success']) || empty($response['connected'])) {
                $connectstatus = self::STATUS_CRITICAL;
                $connectmessage = 'KBA Connect connection check failed.';
            }
        }
        $components['kbaconnect'] = self::component($connectstatus, $connectmessage, $connectdetails);

        $bitrixstatus = $cfg['paired'] ? self::STATUS_WARNING : self::STATUS_WARNING;
        $bitrixmessage = $cfg['paired'] ? 'Bitrix24 schema status has not been checked.' : 'Bitrix24 is checked through KBA Connect after pairing.';
        $bitrixdetails = [];
        if ($remote && $cfg['paired']) {
            $schema = $client->schema_status();
            $bitrixdetails = self::safe_remote($schema);
            if (!empty($schema['success']) && (!empty($schema['ready']) || (!empty($schema['field']) && !empty($schema['field']['exists'])))) {
                $bitrixstatus = self::STATUS_HEALTHY;
                $bitrixmessage = 'Bitrix24 corporate role schema is ready.';
            } else if (empty($schema['success'])) {
                $bitrixstatus = self::STATUS_CRITICAL;
                $bitrixmessage = 'Bitrix24 schema check through KBA Connect failed.';
            } else {
                $bitrixstatus = self::STATUS_WARNING;
                $bitrixmessage = 'Bitrix24 corporate role schema requires attention.';
            }
        }
        $components['bitrix24'] = self::component($bitrixstatus, $bitrixmessage, $bitrixdetails);

        $bbbok = bigbluebutton::is_available();
        $components['bigbluebutton'] = self::component(
            $bbbok ? self::STATUS_HEALTHY : self::STATUS_WARNING,
            $bbbok ? 'BigBlueButton activity and log tables are available.' : 'BigBlueButton integration is not available.',
            ['available' => $bbbok]
        );

        $certok = customcert::is_available();
        $components['customcert'] = self::component(
            $certok ? self::STATUS_HEALTHY : self::STATUS_WARNING,
            $certok ? 'Custom Certificate registry is available.' : 'Custom Certificate integration is not available.',
            ['available' => $certok]
        );

        $licenseok = licenses::is_available();
        $components['licenses'] = self::component(
            $licenseok ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
            $licenseok ? 'Corporate license engine is available.' : 'Corporate license engine tables are unavailable.',
            ['available' => $licenseok]
        );

        $bridge = bridge::status();
        $apiok = !empty($bridge['schemaok']) && !empty($bridge['writesafe']);
        $components['api'] = self::component(
            $apiok ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
            $apiok ? 'Corporate Public API is ready for safe writes.' : 'Corporate Public API requires attention before write operations.',
            [
                'api_release' => bridge::API_RELEASE,
                'schemaok' => !empty($bridge['schemaok']),
                'writesafe' => !empty($bridge['writesafe']),
                'duplicatebitrixcompanies' => (int)($bridge['duplicatebitrixcompanies'] ?? 0),
                'duplicatemembers' => (int)($bridge['duplicatemembers'] ?? 0),
                'duplicatelicenserefs' => (int)($bridge['duplicatelicenserefs'] ?? 0),
            ]
        );

        $isolation = external_mapping::integrity_report();
        $components['company_isolation'] = self::component(
            $isolation['isolated'] ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
            $isolation['isolated']
                ? 'Active ERP mappings are confined to their declared company.'
                : 'One or more ERP mappings have a missing or cross-company target.',
            $isolation
        );

        $erpaudit = erp_audit::statistics(DAYSECS);
        $components['erp_audit'] = self::component(
            $erpaudit['available'] ? self::STATUS_HEALTHY : self::STATUS_CRITICAL,
            $erpaudit['available']
                ? 'Privacy-safe ERP operational audit is available.'
                : 'ERP operational audit table is unavailable.',
            $erpaudit
        );

        // Calculate totals after all components are assembled. Optional integrations
        // generate warnings, while broken core DB/API checks are critical.
        $warnings = 0;
        $errors = 0;
        foreach ($components as $component) {
            if ($component['status'] === self::STATUS_CRITICAL) {
                $errors++;
            } else if ($component['status'] === self::STATUS_WARNING) {
                $warnings++;
            }
        }
        $status = $errors ? self::STATUS_CRITICAL : ($warnings ? self::STATUS_WARNING : self::STATUS_HEALTHY);

        return [
            'status' => $status,
            'warnings' => $warnings,
            'errors' => $errors,
            'checkedat' => time(),
            'module' => $disk,
            'components' => $components,
        ];
    }

    /** Run and store one health check. */
    public static function run(string $source = 'manual', bool $remote = true): array {
        global $DB;
        $result = self::check($remote);
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new \xmldb_table('local_kbacorp_health_log'))) {
            $DB->insert_record('local_kbacorp_health_log', (object)[
                'status' => $result['status'],
                'runsource' => substr(clean_param($source, PARAM_ALPHANUMEXT), 0, 20),
                'warnings' => (int)$result['warnings'],
                'errors' => (int)$result['errors'],
                'details' => json_encode($result),
                'timecreated' => (int)$result['checkedat'],
            ]);
            self::prune_history();
        }
        return $result;
    }

    /** Recent persisted runs. */
    public static function history(int $limit = 20): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_kbacorp_health_log'))) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return array_values($DB->get_records('local_kbacorp_health_log', [], 'timecreated DESC, id DESC', '*', 0, $limit));
    }

    private static function prune_history(): void {
        global $DB;
        $keep = 180;
        $ids = $DB->get_fieldset_sql('SELECT id FROM {local_kbacorp_health_log} ORDER BY timecreated DESC, id DESC', [], 0, $keep);
        if (!$ids) {
            return;
        }
        $cutoff = $DB->get_field('local_kbacorp_health_log', 'timecreated', ['id' => end($ids)]);
        if ($cutoff) {
            $DB->delete_records_select('local_kbacorp_health_log', 'timecreated < :cutoff', ['cutoff' => (int)$cutoff]);
        }
    }

    private static function component(string $status, string $message, array $details = []): array {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }

    /** Strip secrets and raw response bodies before persisting/showing remote status. */
    private static function safe_remote(array $response): array {
        unset($response['integration_token'], $response['token'], $response['access_token'], $response['raw']);
        return $response;
    }
}
