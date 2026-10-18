<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/pdflib.php');

use local_kbacompany\local\design_service;

require_login();
$companyuuid = required_param('uuid', PARAM_RAW_TRIMMED);
$object = required_param('object', PARAM_RAW_TRIMMED);
$revision = required_param('version', PARAM_INT);
$format = strtoupper(optional_param('format', 'A3', PARAM_ALPHA));
$mode = optional_param('mode', 'poster', PARAM_ALPHA);
$peoplemode = optional_param('people', 'positions', PARAM_ALPHA);
if (!in_array($format, ['A0', 'A1', 'A2', 'A3', 'A4'], true) ||
        !in_array($mode, ['poster', 'multipage'], true) ||
        !in_array($peoplemode, ['positions', 'fio', 'photo'], true)) {
    throw new moodle_exception('design_invalid', 'local_kbacompany');
}
$context = design_service::context($companyuuid, (int)$USER->id, $object, $revision);
$payload = $context['payload'];
$project = $context['selected'];
$nodes = $payload['nodes'];
usort($nodes, static function(array $a, array $b): int {
    return [$a['sortorder'], $a['code']] <=> [$b['sortorder'], $b['code']];
});
$nodemap = array_column($nodes, null, 'uuid');
$levels = [];
foreach ($nodes as $node) {
    $level = 0; $parent = $node['layout_parent']; $seen = [];
    while ($parent !== '' && isset($nodemap[$parent]) && !isset($seen[$parent])) {
        $seen[$parent] = true; $level++; $parent = $nodemap[$parent]['layout_parent'];
    }
    $levels[$level][] = $node;
}
$positions = [];
foreach ($payload['positions'] as $position) {
    $positions[$position['nodeuuid']][] = $position;
}
$functions = [];
foreach ($payload['functions'] as $function) {
    $functions[$function['nodeuuid']][] = $function;
}
$positionnames = array_column($payload['positions'], 'name', 'uuid');
$people = [];
if ($peoplemode !== 'positions') {
    $now = time();
    $livepositions = $DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1]);
    $livebycode = [];
    foreach ($livepositions as $position) { $livebycode[core_text::strtolower($position->code)] = $position; }
    foreach ($payload['positions'] as $position) {
        $liveposition = $livebycode[core_text::strtolower($position['code'])] ?? null;
        if (!$liveposition) { continue; }
        foreach ($DB->get_records('local_kbacompany_posassign', ['companyuuid' => $companyuuid,
                'positionuuid' => $liveposition->uuid, 'active' => 1]) as $assignment) {
            if ((int)$assignment->effectivefrom > $now ||
                    ((int)$assignment->effectiveto > 0 && (int)$assignment->effectiveto < $now)) { continue; }
            $user = core_user::get_user((int)$assignment->userid, '*', IGNORE_MISSING);
            if (!$user || !empty($user->deleted)) { continue; }
            $item = ['name' => trim($user->lastname . ' ' . $user->firstname . ' ' . ($user->middlename ?? '')), 'photo' => ''];
            if ($peoplemode === 'photo') {
                $files = get_file_storage()->get_area_files(context_user::instance($user->id)->id,
                    'user', 'icon', 0, 'filename', false);
                $file = reset($files);
                if ($file) { $item['photo'] = 'data:' . $file->get_mimetype() . ';base64,' . base64_encode($file->get_content()); }
            }
            $people[$position['uuid']][] = $item;
        }
    }
}
$nodehtml = static function(array $node) use ($positions, $functions, $positionnames, $people, $peoplemode): string {
    $html = '<table cellpadding="5" cellspacing="0" border="1"><tr><td bgcolor="' . s($node['color']) . '">'
        . '<b>' . s($node['code']) . ' — ' . s($node['name']) . '</b></td></tr><tr><td>';
    if (!empty($positions[$node['uuid']])) {
        $html .= '<b>' . get_string('design_positions', 'local_kbacompany') . '</b><br>';
        foreach ($positions[$node['uuid']] as $position) {
            $html .= '• ' . s($position['name']) . ' — ' . s($position['plannedfte']) . ' FTE';
            if ($position['managerial']) { $html .= ' (' . get_string('design_managerial', 'local_kbacompany') . ')'; }
            if ($peoplemode !== 'positions') {
                $assigned = $people[$position['uuid']] ?? [];
                if (!$assigned) { $html .= ' — ' . get_string('design_pdfvacancy', 'local_kbacompany'); }
                foreach ($assigned as $person) {
                    $html .= '<br>';
                    if ($person['photo'] !== '') { $html .= '<img src="' . $person['photo'] . '" width="18" height="18"> '; }
                    $html .= s($person['name']);
                }
            }
            $html .= '<br>';
        }
    }
    if (!empty($functions[$node['uuid']])) {
        $html .= '<b>' . get_string('design_functions', 'local_kbacompany') . '</b><br>';
        foreach ($functions[$node['uuid']] as $function) {
            $html .= '• ' . s($function['name']) . ': ' . s($function['result']);
            if ($function['positionuuid'] !== '') {
                $html .= ' — ' . s($positionnames[$function['positionuuid']] ?? '');
            }
            $html .= '<br>';
        }
    }
    return $html . '</td></tr></table>';
};
$titlehtml = '<h1>' . s($payload['name']) . '</h1>';
if ($payload['showmission'] && $payload['mission'] !== '') {
    $titlehtml .= '<p><b>' . get_string('design_mission', 'local_kbacompany') . ':</b> ' . s($payload['mission']) . '</p>';
}
if ($payload['showintent'] && $payload['intent'] !== '') {
    $titlehtml .= '<p><b>' . get_string('design_intent', 'local_kbacompany') . ':</b> ' . s($payload['intent']) . '</p>';
}
$snapshotstatus = (string)($payload['status'] ?? $project->status);
$titlehtml .= '<p>' . get_string('design_version', 'local_kbacompany') . ' ' . (int)$revision . ' · '
    . get_string('design_status_' . $snapshotstatus, 'local_kbacompany') . ' · ' . s(userdate(time())) . '</p>';
if (!in_array($snapshotstatus, ['approved', 'applied'], true)) {
    $titlehtml .= '<h2 color="#b42318">' . get_string('design_pdfdraft', 'local_kbacompany') . '</h2>';
}
$pdf = new pdf('L', 'mm', $format, true, 'UTF-8', false);
$pdf->SetCreator('BMS — Business Management System');
$pdf->SetTitle($payload['name']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 8);
$pdf->SetFont('dejavusans', '', $mode === 'poster' ? 8 : 10);
if ($mode === 'poster') {
    $pdf->AddPage();
    $html = $titlehtml;
    foreach ($levels as $level => $items) {
        $html .= '<h3>' . get_string('design_level', 'local_kbacompany') . ' ' . ($level + 1) . '</h3>';
        $html .= '<table cellpadding="3"><tr>';
        foreach ($items as $node) { $html .= '<td width="' . (100 / max(1, count($items))) . '%">' . $nodehtml($node) . '</td>'; }
        $html .= '</tr></table>';
    }
    $pdf->writeHTML($html, true, false, true, false, '');
} else {
    $pdf->AddPage();
    $pdf->writeHTML($titlehtml, true, false, true, false, '');
    foreach ($levels as $level => $items) {
        $pdf->AddPage();
        $html = '<h2>' . get_string('design_level', 'local_kbacompany') . ' ' . ($level + 1) . '</h2>';
        foreach ($items as $node) { $html .= $nodehtml($node) . '<br>'; }
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}
\core\session\manager::write_close();
$filename = clean_filename('orgscheme_' . $payload['name'] . '_v' . $revision . '_' . $format . '.pdf');
$pdf->Output($filename, 'D');
exit;
