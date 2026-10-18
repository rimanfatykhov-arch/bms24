<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
require_capability('local/kbacorporate:viewlicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$licenses = \local_kbacorporate\local\licenses::get_company_licenses($companyid, true);

$filename = clean_filename('licenses_' . $company->name . '_' . userdate(time(), '%Y%m%d'));
$csv = new csv_export_writer('semicolon');
$csv->set_filename($filename);
$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('group', 'local_kbacorporate'),
    get_string('licensestart', 'local_kbacorporate'),
    get_string('licenseend', 'local_kbacorporate'),
    get_string('purchasedseats', 'local_kbacorporate'),
    get_string('usedseats', 'local_kbacorporate'),
    get_string('freeseats', 'local_kbacorporate'),
    get_string('licenseutilization', 'local_kbacorporate'),
    get_string('licensesource', 'local_kbacorporate'),
    get_string('licenseexternaldealid', 'local_kbacorporate'),
    get_string('licenseexternalrowid', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('visibleallocations', 'local_kbacorporate'),
]);
foreach ($licenses as $license) {
    $visibleallocations = \local_kbacorporate\local\licenses::get_license_allocations((int)$license->id, $visibleids);
    $names = [];
    foreach ($visibleallocations as $allocation) {
        if ((string)$allocation->effectivestatus !== \local_kbacorporate\local\licenses::ALLOCATION_RELEASED) {
            $names[] = $allocation->fullname;
        }
    }
    $csv->add_data([
        $company->name,
        $license->fullname,
        $license->groupid ? $license->groupname : get_string('nogroup', 'local_kbacorporate'),
        $license->datefrom ? userdate($license->datefrom, '%Y-%m-%d') : '',
        $license->dateto ? userdate($license->dateto, '%Y-%m-%d') : '',
        $license->unlimited ? get_string('licenseunlimited', 'local_kbacorporate') : $license->seats,
        $license->used,
        $license->unlimited ? '' : $license->available,
        $license->utilizationpercent === null ? '' : format_float($license->utilizationpercent, 1),
        \local_kbacorporate\local\licenses::source_label((string)$license->source),
        $license->externaldealid,
        $license->externalrowid,
        \local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus),
        implode(', ', $names),
    ]);
}
$csv->download_file();
exit;
