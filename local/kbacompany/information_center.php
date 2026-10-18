<?php
// BMS24 read-only Information Center: only explicitly designated main company metric.
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\indicator_registry_service;
require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$registry = indicator_registry_service::context($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/information_center.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('productinfocentertitle', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
// The shell owns both Moodle header and footer. Never call $OUTPUT->footer() after begin().
$shellstate = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'informationcenter', get_string('productinfocentertitle', 'local_kbacompany'));
$style = '.bmsic-main{max-width:1050px}.bmsic-card{border:1px solid #d9e8e0;border-radius:12px;padding:22px;background:#fff;margin:15px 0}' .
    '.bmsic-meta{color:#62776c;font-size:.94rem}.bmsic-number{font-size:1.8rem;font-weight:700}' .
    '.bmsic-chart{width:100%;max-width:950px;height:auto;min-height:130px;margin:12px 0}' .
    '.bmsic-table{width:100%;border-collapse:collapse}.bmsic-table th,.bmsic-table td{border-bottom:1px solid #e3ebe6;padding:10px;text-align:left}' .
    '.bmsic-muted{color:#64756c}';
echo html_writer::tag('style', $style);
echo html_writer::start_tag('main', ['class' => 'bmsic-main']);
echo $OUTPUT->heading(get_string('productinfocentertitle', 'local_kbacompany'));
echo html_writer::tag('p', get_string('indicator_main_company_metric', 'local_kbacompany'), ['class' => 'lead']);
$main = array_values(array_filter($registry['metrics'], static function($metric) {
    return !empty($metric['maincompany']) && ($metric['status'] ?? 'active') !== 'archived';
}));
$metricsurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]);
if (!$main) {
    echo $OUTPUT->notification(get_string('infocentermainempty', 'local_kbacompany'), 'info');
    echo html_writer::link($metricsurl, get_string('infocentersetmain', 'local_kbacompany'), ['class' => 'btn btn-primary']);
} else {
    // The main-company flag is the sole canonical selection criterion. If more than one is flagged,
    // expose that configuration conflict without silently guessing which is primary.
    if (count($main) > 1) {
        echo $OUTPUT->notification(get_string('infocentermultiplemain', 'local_kbacompany'), 'warning');
        echo html_writer::link($metricsurl, get_string('infocentersetmain', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-3']);
    }
    foreach ($main as $metric) {
        $series = indicator_registry_service::series_for_metric($uuid, $metric['uuid'], 13);
        $series = array_slice($series, -13);
        $name = $metric['code'] . ' — ' . $metric['name'];
        $editurl = new moodle_url('/local/kbacompany/indicators.php',
            ['uuid' => $uuid, 'stat' => $metric['uuid'], 'tab' => 'input']);
        echo html_writer::start_tag('section', ['class' => 'bmsic-card']);
        echo html_writer::tag('h2', s($name), ['class' => 'h4']);
        echo html_writer::tag('p', s($metric['unit']) . ' · ' . s($metric['periodtype']), ['class' => 'bmsic-meta']);
        $recent = $series ? end($series) : null;
        $lastfact = $recent && $recent['fact'] !== null ? format_float((float)$recent['fact'], 2) : '—';
        $lastplan = $recent && $recent['plan'] !== null ? format_float((float)$recent['plan'], 2) : '—';
        echo html_writer::tag('div', get_string('infocenterlastfact', 'local_kbacompany') . ': ' . s($lastfact) .
            ' · ' . get_string('infocenterlastplan', 'local_kbacompany') . ': ' . s($lastplan), ['class' => 'bmsic-number']);
        $factrows = array_values(array_filter($series, static function($row) { return $row['fact'] !== null; }));
        if ($factrows) {
            $nums = array_map(static function($row) { return (float)$row['fact']; }, $factrows);
            $low = min($nums); $high = max($nums); $range = max(0.00001, $high - $low);
            $count = count($nums); $svg = '';
            $coords = [];
            foreach ($nums as $index => $number) {
                $x = $count === 1 ? 150 : 12 + $index * 276 / ($count - 1);
                $y = 98 - 80 * ($number - $low) / $range;
                $coords[] = round($x, 1) . ',' . round($y, 1);
            }
            if (count($coords) > 1) {
                $svg .= html_writer::empty_tag('polyline', ['points' => implode(' ', $coords),
                    'fill' => 'none', 'stroke' => '#16834a', 'stroke-width' => '2.5']);
            }
            foreach ($coords as $point) {
                list($x, $y) = explode(',', $point);
                $svg .= html_writer::empty_tag('circle', ['cx' => $x, 'cy' => $y,
                    'r' => 4, 'fill' => '#16834a']);
            }
            echo html_writer::tag('svg', $svg, ['class' => 'bmsic-chart', 'viewBox' => '0 0 300 120',
                'role' => 'img', 'aria-label' => get_string('infocentertrend', 'local_kbacompany')]);
        } else {
            echo html_writer::tag('p', get_string('infocenternodata', 'local_kbacompany'), ['class' => 'bmsic-muted']);
        }
        $rows = '';
        foreach (array_reverse($series) as $point) {
            $rows .= html_writer::tag('tr',
                html_writer::tag('td', s($point['period'])) .
                html_writer::tag('td', $point['plan'] === null ? '—' : s(format_float((float)$point['plan'], 2))) .
                html_writer::tag('td', $point['fact'] === null ? '—' : s(format_float((float)$point['fact'], 2))));
        }
        if ($rows !== '') {
            echo html_writer::tag('table', html_writer::tag('thead', html_writer::tag('tr',
                html_writer::tag('th', get_string('infocenterperiod', 'local_kbacompany')) .
                html_writer::tag('th', get_string('infocenterlastplan', 'local_kbacompany')) .
                html_writer::tag('th', get_string('infocenterlastfact', 'local_kbacompany')))) .
                html_writer::tag('tbody', $rows), ['class' => 'bmsic-table']);
        }
        echo html_writer::link($editurl, get_string('infocenteropenmetric', 'local_kbacompany'),
            ['class' => 'btn btn-outline-primary mt-3']);
        echo html_writer::end_tag('section');
    }
}
echo html_writer::end_tag('main');
\local_kbacompany\local\bms_page_shell_service::finish($shellstate);
