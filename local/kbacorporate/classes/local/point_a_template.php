<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Installs the canonical Point A diagnostic as a company-owned audit template. */
class point_a_template {
    public const TEMPLATE_CODE = 'BMS-AUD-CHK-001';

    /** Install once per company and return the created tool id. */
    public static function install(int $companyid): int {
        global $DB;
        if ($existing = $DB->get_record('local_kbacorp_bms_tool', [
                'companyid' => $companyid, 'templatecode' => self::TEMPLATE_CODE])) {
            return (int)$existing->id;
        }
        if ($DB->record_exists('local_kbacorp_bms_tool', [
                'companyid' => $companyid, 'code' => self::TEMPLATE_CODE])) {
            throw new \moodle_exception('pointatemplatecodeexists', 'local_kbacorporate');
        }
        $path = __DIR__ . '/../../templates/bms_point_a_v0.json';
        $template = json_decode(file_get_contents($path));
        if (!$template || empty($template->sections)) {
            throw new \moodle_exception('pointatemplateinvalid', 'local_kbacorporate');
        }
        $transaction = $DB->start_delegated_transaction();
        $toolid = implementation_audits::save_tool((object)[
            'companyid' => $companyid,
            'code' => $template->templatecode,
            'name' => $template->name,
            'area' => 'management',
            'templatecode' => $template->templatecode,
            'sourceversion' => $template->version,
            'scalemax' => $template->scalemax,
            'attentionthreshold' => $template->threshold,
            'indexmethod' => $template->indexmethod,
            'description' => $template->description,
            'active' => 1,
        ]);
        $sortorder = 10;
        foreach ($template->sections as $section) {
            foreach ($section->criteria as $criterion) {
                implementation_audits::save_criterion((object)[
                    'toolid' => $toolid,
                    'code' => $criterion->code,
                    'name' => $criterion->name,
                    'sectioncode' => $section->code,
                    'sectionname' => $section->name,
                    'minlabel' => $criterion->minimum,
                    'maxlabel' => $criterion->maximum,
                    'targetscore' => (int)$template->threshold,
                    'description' => '',
                    'evidencehint' => '',
                    'weight' => 1,
                    'required' => 1,
                    'sortorder' => $sortorder,
                    'active' => 1,
                ]);
                $sortorder += 10;
            }
        }
        $transaction->allow_commit();
        return $toolid;
    }
}
