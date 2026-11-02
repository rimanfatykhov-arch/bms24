<?php
/** RC30/1811 pure isolation matrix regression without Moodle/DB. */
define('MOODLE_INTERNAL', true);
require dirname(__DIR__) . '/classes/local/rc30_company_isolation_service.php';
use local_kbacompany\local\rc30_company_isolation_service as audit;
function must1811(bool $condition, string $test): void {
    if (!$condition) { fwrite(STDERR, "FAIL $test\n"); exit(2); }
    echo "PASS $test\n";
}
$a = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$b = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$matrix = static function(array $grant): callable {
    return static function(string $company, int $actor) use ($grant): bool {
        return in_array($company, $grant[$actor] ?? [], true);
    };
};
$result = audit::verify($a, 101, $b, 202, $matrix([101=>[$a], 202=>[$b]]));
must1811($result['status'] === 'PASS' && $result['checks']['actor_a_own_company'] &&
    !$result['checks']['actor_a_foreign_company'] && !$result['checks']['actor_b_foreign_company'],
    'true reciprocal isolation with both valid positive controls');
$result = audit::verify($a, 101, $b, 202, $matrix([101=>[$a,$b], 202=>[$b]]));
must1811($result['status'] === 'HOLD' && $result['reason'] === 'FOREIGN_ACCESS_OR_PRIVILEGED_ACTOR',
    'actor A can access B: not false PASS');
$result = audit::verify($a, 101, $b, 202, $matrix([101=>[$a], 202=>[$a,$b]]));
must1811($result['status'] === 'HOLD', 'actor B can access A: not false PASS');
$result = audit::verify($a, 101, $b, 202, $matrix([101=>[], 202=>[$b]]));
must1811($result['status'] === 'HOLD' && $result['reason'] === 'INVALID_POSITIVE_CONTROL',
    'broken own-company control does not look secure');
$result = audit::verify($a, 101, $b, 202, $matrix([101=>[$a], 202=>[]]));
must1811($result['status'] === 'HOLD', 'second actor must access own company');
$result = audit::verify($a, 101, $a, 202, $matrix([101=>[$a],202=>[$a]]));
must1811($result['status'] === 'HOLD' && $result['reason'] === 'INVALID_TEST_FIXTURE',
    'reject same company');
$result = audit::verify($a, 101, $b, 101, $matrix([101=>[$a]]));
must1811($result['status'] === 'HOLD', 'reject same actor in two slots');
$result = audit::verify($a, 0, $b, 202, $matrix([]));
must1811($result['status'] === 'HOLD', 'reject invalid userid');
try {
    audit::verify($a, 101, $b, 202, static function() { throw new RuntimeException('DB down'); });
    must1811(false, 'resolver failure must propagate');
} catch (RuntimeException $e) { must1811(true, 'resolver failure propagates for HOLD'); }
echo "RC30 isolation matrix tests PASS\n";
