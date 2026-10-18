<?php
require(__DIR__.'/../../config.php');
require_login();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
'schema'=>'kba-bms-master',
'version'=>'1.0',
'framework'=>'BMS-COMP-2026-V1'
],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
exit;
