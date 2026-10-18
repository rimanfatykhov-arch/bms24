<?php
namespace local_kbacorporate\local;
defined('MOODLE_INTERNAL') || die();

final class implementation_programs {
    public static function get_company_programs(int $companyid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacorp_impl_program', ['companyid' => $companyid],
            'timecreated DESC, id DESC'));
    }

    public static function get_for_audit(int $auditid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_kbacorp_impl_program', ['baselineauditid' => $auditid]);
        return $record ?: null;
    }

    public static function request(int $companyid, int $auditid, int $userid, string $packagecode = 'start',
            array $requestdata = []): \stdClass {
        global $DB;
        $audit = implementation_audits::get_audit($auditid);
        if ((int)$audit->companyid !== $companyid) { throw new \moodle_exception('companynotallowed', 'local_kbacorporate'); }
        if ($existing = self::get_for_audit($auditid)) { return $existing; }
        $package = implementation_packages::get_by_code($packagecode);
        if (!$package || !$package->active) { throw new \invalid_parameter_exception('Unknown implementation package'); }
        $now = time();
        $record = (object)[
            'companyid' => $companyid, 'baselineauditid' => $auditid, 'finalauditid' => 0,
            'code' => 'BMS-PROG-' . $companyid . '-' . $auditid,
            'title' => get_string('implementationprogramdefaulttitle', 'local_kbacorporate', $audit->title),
            'package' => $packagecode, 'commercialstatus' => 'requested', 'status' => 'draft',
            'startdate' => 0, 'enddate' => 0, 'curatorid' => 0, 'companyownerid' => 0,
            'contactname' => trim(clean_param($requestdata['contactname'] ?? '', PARAM_TEXT)) ?: null,
            'contactemail' => trim(clean_param($requestdata['contactemail'] ?? '', PARAM_EMAIL)) ?: null,
            'contactphone' => trim(clean_param($requestdata['contactphone'] ?? '', PARAM_TEXT)) ?: null,
            'customercomment' => trim(clean_param($requestdata['customercomment'] ?? '', PARAM_TEXT)) ?: null,
            'customerresponse' => null, 'statuschangedby' => 0, 'statuschangedat' => 0,
            'bitrixdealid' => null, 'notes' => null, 'requestedby' => $userid,
            'activatedby' => 0, 'activatedat' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_kbacorp_impl_program', $record);
        implementation_request_notifier::send($record);
        if (get_config('local_kbacorporate', 'bitrixprogramautocreate')) {
            try { bitrix_programs::create_deal($record); } catch (\Throwable $e) {
                debugging('Bitrix24 program deal was not created: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $record;
    }

    public static function plan_visible(?\stdClass $program): bool {
        return $program && $program->commercialstatus === 'paid' &&
            in_array($program->status, ['active', 'paused', 'completed'], true);
    }

    public static function plan_editable(?\stdClass $program): bool {
        return self::plan_visible($program) && $program->status === 'active';
    }

    public static function label(\stdClass $program): string {
        $package = implementation_packages::get_by_code($program->package);
        $packagename = $package ? format_string($package->name) : s($program->package);
        return $packagename . ' · ' .
            get_string('programstatus' . $program->status, 'local_kbacorporate') . ' · ' .
            get_string('commercialstatus' . $program->commercialstatus, 'local_kbacorporate');
    }
}
