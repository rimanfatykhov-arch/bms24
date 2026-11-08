<?php
// Real provider declarations + real BMS availability guard. No Moodle boot or DB.
define('MOODLE_INTERNAL', true);
$root = dirname(__DIR__);
require_once $argv[1] ?? $root . '/local/kbacorporate/classes/local/bridge.php';
require_once $root . '/local/kbacompany/classes/local/task_provider/task_provider_interface.php';
require_once $root . '/local/kbacompany/classes/local/task_provider/kbacorporate_impl_task_provider.php';
$bridge = new ReflectionClass('local_kbacorporate\\local\\bridge');
if (version_compare((string)$bridge->getConstant('API_RELEASE'), '1.26', '<')) {
    throw new RuntimeException('Corporate API 1.26 required');
}
$signatures = [
    'list_implementation_task_projections' => [3, 6, false],
    'get_implementation_task_projection' => [3, 3, true],
    'list_implementation_program_projections' => [2, 4, false],
    'get_implementation_program_projection' => [3, 3, true],
    'get_implementation_program_sections' => [3, 3, true],
    'get_implementation_program_task_sections' => [3, 3, true],
    'get_implementation_program_result_statuses' => [3, 3, true],
];
$contract = local_kbacorporate\local\bridge::contract();
foreach ($signatures as $name => [$required, $total, $nullable]) {
    $method = $bridge->getMethod($name);
    $type = $method->getReturnType();
    if (!$method->isPublic() || !$method->isStatic() ||
            $method->getNumberOfRequiredParameters() !== $required ||
            $method->getNumberOfParameters() !== $total || !$type ||
            $type->getName() !== 'array' || $type->allowsNull() !== $nullable ||
            empty($contract['methods'][$name]['read_only']) ||
            $contract['methods'][$name]['company_scope'] !== 'explicit') {
        throw new RuntimeException('Incompatible bridge method: ' . $name);
    }
    foreach (array_slice($method->getParameters(), 0, $required) as $parameter) {
        if ((string)$parameter->getType() !== 'int') {
            throw new RuntimeException('Incompatible argument: ' . $name);
        }
    }
}
$provider = new local_kbacompany\local\task_provider\kbacorporate_impl_task_provider();
if (!$provider->available()) {
    throw new RuntimeException('Real BMS provider unavailable');
}
echo "PASS: 7 real bridge signatures, read-only contract, BMS provider available (not DB acceptance)\n";
