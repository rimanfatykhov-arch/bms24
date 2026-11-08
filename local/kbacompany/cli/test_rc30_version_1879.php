<?php
/** Candidate version regression with a fake read-only DB; no Moodle bootstrap. */
require_once(__DIR__ . '/test_rc30_gate_1865.php');

use local_kbacompany\local\task_rc30_acceptance_service as VersionGate;

define('MATURITY_RC', 150);
$plugin = new stdClass();
require(__DIR__ . '/../version.php');
$candidateversion = (int)$plugin->version;
check1865(VersionGate::EXPECTED_PLUGIN_VERSION === $candidateversion,
    'Acceptance expectation matches the actual candidate version.php');

foreach ([false, true] as $strictmode) {
    $result = VersionGate::assess(new TestDB1865(), $candidateversion, $uuid, $strictmode);
    check1865($result['errors'] === [], 'Matching installed candidate has no version error');
    check1865($result['technical_gate'] === ($actualtargetphp ? 'passed' : ($strictmode ? 'failed' : 'warnings')),
        'Matching version preserves runtime gate');
    check1865($result['installed_plugin_version'] === $candidateversion &&
        $result['expected_plugin_version'] === $candidateversion, 'Report identifies actual candidate');
    check1865($result['rc30_promotion_approved'] === false, 'Matching version never approves RC30');

    foreach ([0, 2026101873, $candidateversion - 1, $candidateversion + 1] as $wrongversion) {
        $result = VersionGate::assess(new TestDB1865(), $wrongversion, $uuid, $strictmode);
        check1865($result['technical_gate'] === 'failed', 'Wrong installed version fails in both modes');
        check1865(in_array('plugin_version_mismatch', $result['errors'], true), 'Mismatch stays explicit');
        check1865($result['installed_plugin_version'] === $wrongversion, 'Mismatch report preserves installed version');
        check1865($result['rc30_promotion_approved'] === false, 'Mismatch never approves RC30');
    }
}
echo "PASS: candidate version.php alignment and 10 installed-version/mode cases\n";
