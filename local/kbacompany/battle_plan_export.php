<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\battle_plan_word_export_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$planuuid = required_param('plan', PARAM_RAW_TRIMMED);

$data = battle_plan_service::context($uuid, (int)$USER->id, $planuuid);
if (!$data['selected']) { throw new moodle_exception('battleplan_reference', 'local_kbacompany'); }
// The plan is already ACL-checked by battle_plan_service::context().
// Query only company-scoped signatory data; do not infer approval from the reviewer field.
$data['export_signers'] = battle_plan_word_export_service::signers_for_export($uuid, $data['selected']);
$model = battle_plan_word_export_service::model($data);
$employee = preg_replace('/[^\pL\pN_-]+/u', '_', $model['employee']);
$filename = clean_filename('BMS_Battle_Plan_' . $data['selected']['period_start'] . '_' . $employee . '.docx');
$path = make_request_directory() . '/' . $filename;
battle_plan_word_export_service::write_docx($model, $path);
// Moodle 4.1 has send_file() (lib/filelib.php), not send_temp_file().
// The generated file is stored in a request-scoped directory and streamed as a download.
require_once($CFG->libdir . '/filelib.php');
\core\session\manager::write_close();
send_file($path, $filename, 0, 0, false, true,
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
exit;
