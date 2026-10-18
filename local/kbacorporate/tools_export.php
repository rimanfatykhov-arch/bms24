<?php
require(__DIR__.'/../../config.php');
require_login();
header('Content-Type: application/json; charset=utf-8');
echo file_get_contents(__DIR__.'/json/KBA_BMS_TOOLS_v1.0.json');
exit;
