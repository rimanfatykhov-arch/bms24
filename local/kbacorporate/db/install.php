<?php
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/local/economic_indicators.php');

function xmldb_local_kbacorporate_install(): void {
    global $DB;
    $now = time();
    $defaults = [
        ['start', 'BMS Start', 10, 'Шаблон плана, обучение и самостоятельное внедрение.'],
        ['support', 'BMS Support', 20, 'План, куратор и регулярный контроль внедрения.'],
        ['transformation', 'BMS Transformation', 30, 'Полное сопровождение, экономика, повторный аудит и подготовка к сертификации.'],
    ];
    foreach ($defaults as [$code, $name, $sortorder, $description]) {
        $DB->insert_record('local_kbacorp_impl_package', (object)['code' => $code, 'name' => $name,
            'description' => $description, 'features' => null, 'price' => null, 'currency' => 'RUB',
            'durationdays' => 0, 'sortorder' => $sortorder, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now]);
    }
    // v0.33.4: install the stable global economic-indicator catalogue.
    \local_kbacorporate\local\economic_indicators::seed_defaults(0);
}
