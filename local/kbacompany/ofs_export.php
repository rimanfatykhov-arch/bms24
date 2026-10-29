<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\ofs_export_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);

$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$format = strtolower(optional_param('format', 'svg', PARAM_ALPHA));
$paper = strtoupper(optional_param('paper', 'A3', PARAM_ALPHANUM));

if (!in_array($format, ['svg', 'pdf', 'drawio'], true) ||
        !in_array($paper, ['AUTO', 'A0', 'A1', 'A2', 'A3', 'A4'], true)) {
    throw new moodle_exception('ofsdesigner_export_invalid', 'local_kbacompany');
}

$data = ofs_export_service::context($uuid, (int)$USER->id, $object);
$poster = $data['poster'];
$layout = $data['layout'];
$name = trim((string)($poster['name'] ?? ''));
if ($name === '') {
    $name = get_string('ofsdesigner_defaultname', 'local_kbacompany');
}
$rev = (int)$data['revision'];
$revisionpart = $rev > 0 ? '_v' . $rev : '_live';
$basename = clean_filename('BMS_OFS_' . $name . $revisionpart);

if ($format === 'svg') {
    $svg = ofs_export_service::svg($layout, $name);
    \core\session\manager::write_close();
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.svg"');
    header('Content-Length: ' . strlen($svg));
    echo $svg;
    exit;
}

if ($format === 'drawio') {
    $drawio = ofs_export_service::drawio($layout, $name);
    \core\session\manager::write_close();
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.drawio"');
    header('Content-Length: ' . strlen($drawio));
    echo $drawio;
    exit;
}

require_once($CFG->libdir . '/pdflib.php');
$svg = ofs_export_service::tcpdf_svg($layout, $name);
$page = ofs_export_service::pdf_page($paper, $layout);
if ($page['custom']) {
    // TCPDF applies landscape orientation to an array by swapping portrait dimensions.
    $pageformat = [min($page['width'], $page['height']), max($page['width'], $page['height'])];
    $pdf = new pdf('L', 'mm', $pageformat, true, 'UTF-8', false);
} else {
    $pdf = new pdf('L', 'mm', $page['paper'], true, 'UTF-8', false);
}
$pdf->SetCreator('BMS — Business Management System');
$pdf->SetAuthor('BMS');
$pdf->SetTitle($name);
// Moodle/TCPDF ships DejaVu Sans as a TrueType Unicode font. Set it explicitly
// before ImageSVG so Cyrillic text never falls back to core Helvetica/Arial.
$pdf->SetFont('dejavusans', '', 10);
$pdf->setFontSubsetting(true);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage();

$tempdir = make_temp_directory('local_kbacompany/ofs_export');
$svgfile = tempnam($tempdir, 'ofs_');
if ($svgfile === false || file_put_contents($svgfile, $svg) === false) {
    throw new moodle_exception('ofsdesigner_export_failed', 'local_kbacompany');
}
try {
    $pagew = (float)$pdf->getPageWidth();
    $pageh = (float)$pdf->getPageHeight();
    $margin = 4.0;
    $availw = max(1.0, $pagew - ($margin * 2));
    $availh = max(1.0, $pageh - ($margin * 2));
    $scale = min($availw / (float)$layout['width'], $availh / (float)$layout['height']);
    $imgw = (float)$layout['width'] * $scale;
    $imgh = (float)$layout['height'] * $scale;
    $imgx = ($pagew - $imgw) / 2;
    $imgy = ($pageh - $imgh) / 2;
    $pdf->ImageSVG($svgfile, $imgx, $imgy, $imgw, $imgh, '', '', '', 0, false);
} finally {
    @unlink($svgfile);
}
\core\session\manager::write_close();
$pdf->Output($basename . '_' . $page['paper'] . '.pdf', 'D');
exit;
