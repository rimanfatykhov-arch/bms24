<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Generic external ID mapping for Bitrix24 now and other ERP/HR systems later. */
class external_mapping {
    public static function upsert(int $companyid, string $system, string $entitytype,
            string $localentity, int $localid, string $externalid, string $externalcode = '', array $metadata = []): int {
        global $DB;
        $system = self::token($system, 30);
        $entitytype = self::token($entitytype, 30);
        $localentity = self::token($localentity, 50);
        $externalid = trim($externalid);
        if ($externalid === '') {
            throw new \invalid_parameter_exception('External ID is required.');
        }
        $metadata = self::validate_metadata($metadata);
        self::assert_company_scope(max(0, $companyid), $localentity, $localid);
        $now = time();
        $lookupsql = 'SELECT em.* FROM {local_kbacorp_extmap} em ' .
            'WHERE em.companyid = :companyid AND em.system = :system ' .
            'AND em.entitytype = :entitytype AND em.externalid = :externalid';
        $lookupparams = [
            'companyid' => max(0, $companyid),
            'system' => $system,
            'entitytype' => $entitytype,
            'externalid' => $externalid,
        ];
        $existing = $DB->get_record_sql($lookupsql, $lookupparams);
        $record = (object)[
            'companyid' => max(0, $companyid),
            'system' => $system,
            'entitytype' => $entitytype,
            'localentity' => $localentity,
            'localid' => $localid,
            'externalid' => $externalid,
            'externalcode' => trim($externalcode) ?: null,
            'status' => 'active',
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'lastsync' => $now,
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            // Provider is part of the lookup identity and is unchanged. Omitting
            // it avoids an unquoted SYSTEM assignment in Moodle's generic UPDATE.
            unset($record->system);
            $DB->update_record('local_kbacorp_extmap', $record);
            return $record->id;
        }
        $record->timecreated = $now;
        // Moodle's generic INSERT does not quote reserved column names. Keep the
        // existing schema/API and bind every value; only the fixed identifier varies.
        $systemcolumn = $DB->get_dbfamily() === 'mysql' ? '`system`' : 'system';
        $DB->execute('INSERT INTO {local_kbacorp_extmap} ' .
            '(companyid, ' . $systemcolumn . ', entitytype, localentity, localid, externalid, externalcode, ' .
            'status, metadata, lastsync, timemodified, timecreated) VALUES ' .
            '(:companyid, :system, :entitytype, :localentity, :localid, :externalid, :externalcode, ' .
            ':status, :metadata, :lastsync, :timemodified, :timecreated)', (array)$record);
        // The unique (companyid, system, entitytype, externalid) tuple identifies
        // exactly this insert, including when other tenants use the same external ID.
        $created = $DB->get_record_sql($lookupsql, $lookupparams, MUST_EXIST);
        return (int)$created->id;
    }

    public static function find_local(int $companyid, string $system, string $entitytype, string $externalid) {
        global $DB;
        return $DB->get_record_sql('SELECT em.* FROM {local_kbacorp_extmap} em ' .
            'WHERE em.companyid = :companyid AND em.system = :system AND em.entitytype = :entitytype ' .
            'AND em.externalid = :externalid AND em.status = :status', [
            'companyid' => max(0, $companyid),
            'system' => self::token($system, 30),
            'entitytype' => self::token($entitytype, 30),
            'externalid' => trim($externalid),
            'status' => 'active',
        ]);
    }

    /** Soft-deactivate one mapping without losing integration history. */
    public static function set_status(int $mappingid, string $status): void {
        global $DB;
        $status = self::token($status, 20);
        $DB->update_record('local_kbacorp_extmap', (object)[
            'id' => $mappingid,
            'status' => $status,
            'timemodified' => time(),
        ]);
    }

    /** Verify that a mapping target belongs to the declared company boundary. */
    private static function assert_company_scope(int $companyid, string $localentity, int $localid): void {
        global $DB;

        if ($localid <= 0) {
            throw new \invalid_parameter_exception('Local mapping ID is required.');
        }
        if ($localentity === 'company') {
            if ($companyid !== 0 || !$DB->record_exists('local_kbacorp_company', ['id' => $localid])) {
                throw new \invalid_parameter_exception('Company mapping target is invalid.');
            }
            return;
        }
        if ($companyid <= 0 || !$DB->record_exists('local_kbacorp_company', ['id' => $companyid])) {
            throw new \invalid_parameter_exception('Scoped mapping requires a valid company.');
        }

        $valid = false;
        switch ($localentity) {
            case 'user':
                $valid = $DB->record_exists('local_kbacorp_member', ['companyid' => $companyid, 'userid' => $localid]);
                break;
            case 'department':
                $valid = $DB->record_exists('local_kbacorp_department', ['id' => $localid, 'companyid' => $companyid]);
                break;
            case 'business_role':
                $valid = $DB->record_exists('local_kbacorp_busrole', ['id' => $localid, 'companyid' => $companyid]);
                break;
            case 'course_mapping':
                $valid = $DB->record_exists('local_kbacorp_course', ['id' => $localid, 'companyid' => $companyid]);
                break;
            case 'economic_value':
                $valid = $DB->record_exists('local_kbacorp_econ_val', ['id' => $localid, 'companyid' => $companyid]);
                break;
            case 'business_role_assignment':
                $valid = $DB->record_exists('local_kbacorp_member_busrole', ['id' => $localid, 'companyid' => $companyid]);
                break;
            case 'department_membership':
                $sql = "SELECT dm.id
                          FROM {local_kbacorp_dept_member} dm
                          JOIN {local_kbacorp_department} d ON d.id = dm.departmentid
                         WHERE dm.id = :localid AND d.companyid = :companyid";
                $valid = $DB->record_exists_sql($sql, ['localid' => $localid, 'companyid' => $companyid]);
                break;
        }
        if (!$valid) {
            throw new \invalid_parameter_exception('External mapping target is outside the declared company scope.');
        }
    }

    /** Count active mappings with missing or cross-company targets. */
    public static function integrity_report(): array {
        global $DB;

        $invalid = 0;
        $checked = 0;
        $mappings = $DB->get_recordset('local_kbacorp_extmap', ['status' => 'active'], 'id ASC',
            'id,companyid,localentity,localid');
        try {
            foreach ($mappings as $mapping) {
                $checked++;
                try {
                    self::assert_company_scope((int)$mapping->companyid, (string)$mapping->localentity,
                        (int)$mapping->localid);
                } catch (\invalid_parameter_exception $error) {
                    $invalid++;
                }
            }
        } finally {
            $mappings->close();
        }
        return ['checked' => $checked, 'invalid' => $invalid, 'isolated' => $invalid === 0];
    }

    private static function token(string $value, int $max): string {
        $value = strtolower(trim($value));
        if ($value === '' || !preg_match('/^[a-z0-9_.-]+$/', $value)) {
            throw new \invalid_parameter_exception('Invalid external mapping token.');
        }
        return substr($value, 0, $max);
    }

    /** Keep mapping metadata technical, bounded and free of credentials/identity data. */
    private static function validate_metadata(array $metadata): array {
        $forbidden = '/(?:secret|password|passwd|token|credential|authorization|cookie|email|phone|firstname|lastname|fullname|address)/i';
        $validate = static function(array $items) use (&$validate, $forbidden): void {
            foreach ($items as $key => $value) {
                if (preg_match($forbidden, (string)$key)) {
                    throw new \invalid_parameter_exception('External mapping metadata contains a forbidden key.');
                }
                if (is_array($value)) {
                    $validate($value);
                } else if (!is_scalar($value) && $value !== null) {
                    throw new \invalid_parameter_exception('External mapping metadata must contain scalar values only.');
                }
            }
        };
        $validate($metadata);
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 16384) {
            throw new \invalid_parameter_exception('External mapping metadata is invalid or too large.');
        }
        return $metadata;
    }
}
