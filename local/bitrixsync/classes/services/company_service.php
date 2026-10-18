<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class company_service {
    public function get_bridge(int $bitrixcompanyid) {
        global $DB;
        return $DB->get_record('local_bitrixsync_company', ['bitrix_company_id' => $bitrixcompanyid]);
    }

    public function record_corporate_sync(int $bitrixcompanyid, array $corporateresult): int {
        global $DB;

        $now = time();
        $companyid = (int)($corporateresult['company']['companyid']
            ?? $corporateresult['companyid']
            ?? 0);

        $licenseid = (int)($corporateresult['license']['license']['licenseid']
            ?? $corporateresult['license']['licenseid']
            ?? 0);

        $summary = $corporateresult['snapshot']['summary'] ?? [];
        $seatstotal = (int)($summary['purchasedseats'] ?? 0);
        $seatsused = (int)($summary['allocatedseats'] ?? $summary['usedseats'] ?? 0);

        $record = $DB->get_record('local_bitrixsync_company', [
            'bitrix_company_id' => $bitrixcompanyid,
        ]);

        if ($record) {
            $record->moodle_company_id = $companyid ?: $record->moodle_company_id;
            $record->corporate_company_id = $companyid ?: $record->corporate_company_id;
            $record->license_id = $licenseid ?: $record->license_id;
            $record->seats_total = $seatstotal;
            $record->seats_used = $seatsused;
            $record->status = 'active';
            $record->last_sync = $now;
            $record->timemodified = $now;
            $DB->update_record('local_bitrixsync_company', $record);
            return (int)$record->id;
        }

        return (int)$DB->insert_record('local_bitrixsync_company', (object)[
            'bitrix_company_id' => $bitrixcompanyid,
            'moodle_company_id' => $companyid ?: null,
            'corporate_company_id' => $companyid ?: null,
            'license_id' => $licenseid ?: null,
            'seats_total' => $seatstotal,
            'seats_used' => $seatsused,
            'status' => 'active',
            'last_sync' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
    /** Persist only the Bitrix ↔ Corporate company identity without changing licence counters. */
    public function record_corporate_company_mapping(int $bitrixcompanyid, int $corporatecompanyid): int {
        global $DB;
        if ($bitrixcompanyid <= 0 || $corporatecompanyid <= 0) {
            return 0;
        }
        $now = time();
        $record = $DB->get_record('local_bitrixsync_company', ['bitrix_company_id' => $bitrixcompanyid]);
        if ($record) {
            $record->moodle_company_id = $corporatecompanyid;
            $record->corporate_company_id = $corporatecompanyid;
            $record->status = 'active';
            $record->last_sync = $now;
            $record->timemodified = $now;
            $DB->update_record('local_bitrixsync_company', $record);
            return (int)$record->id;
        }
        return (int)$DB->insert_record('local_bitrixsync_company', (object)[
            'bitrix_company_id' => $bitrixcompanyid,
            'moodle_company_id' => $corporatecompanyid,
            'corporate_company_id' => $corporatecompanyid,
            'license_id' => null, 'seats_total' => 0, 'seats_used' => 0,
            'status' => 'active', 'last_sync' => $now,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

}
