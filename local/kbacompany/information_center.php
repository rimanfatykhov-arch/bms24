<?php
// BMS24 read-only Information Centre (#190): main company metric on top, then divisions of the organizing board
// as columns with their departments/positions, bound metrics, mini charts and trends. Drill-down chart in a dialog.
// Mini charts and the drill-down chart are drawn with the local Apache ECharts module (#192, thirdpartylibs.xml).
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\information_center_service;
require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
// Company membership and scope are enforced inside (indicator_registry_service::context).
$view = information_center_service::overview($uuid, (int)$USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/information_center.php', ['uuid' => $uuid]));
$PAGE->set_title(get_string('productinfocentertitle', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$str = static function(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); };
$jsstrings = [];
foreach (['close', 'window', 'zoomin', 'zoomout', 'panleft', 'panright', 'reset', 'legend_fact', 'legend_plan',
        'legend_trend', 'legend_gap', 'nofact', 'noplan', 'hint', 'axis_value', 'nodata', 'single', 'unit',
        'readout', 'visible'] as $key) {
    $jsstrings[$key] = $str('infocenter2_' . $key);
}
$PAGE->requires->js_call_amd('local_kbacompany/infocenter_chart', 'init', [$jsstrings]);

// The shell owns both Moodle header and footer. Never call $OUTPUT->footer() after begin().
$shellstate = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'informationcenter', get_string('productinfocentertitle', 'local_kbacompany'));

$style = '.bmsic-main{max-width:1440px}' .
    '.bmsic-section{margin:18px 0}.bmsic-meta,.bmsic-muted{color:#5b6f64;font-size:.92rem}' .
    '.bmsic-tile{display:block;width:100%;text-align:left;border:1px solid #d9e8e0;border-radius:10px;background:#fff;' .
    'padding:10px 12px;margin:8px 0;color:#0B2B41;cursor:pointer}' .
    '.bmsic-tile:hover{border-color:#0C6C52}.bmsic-tile:focus-visible{outline:3px solid #1777B5;outline-offset:2px}' .
    '.bmsic-tile-name{display:block;font-weight:600}.bmsic-tile-value{display:block;font-size:1.05rem;margin-top:2px}' .
    '.bmsic-main-tile .bmsic-tile-value{font-size:1.5rem;font-weight:700}' .
    '.bmsic-trend{display:block;margin-top:2px;font-size:.92rem}.bmsic-trend-symbol{font-weight:700;margin-right:4px}' .
    '.bmsic-q-better{color:#0C6C52}.bmsic-q-worse{color:#a1271b}' .
    '.bmsic-mini{display:block;width:100%;height:72px;margin-top:6px;pointer-events:none;overflow:hidden}' .
    '.bmsic-main-tile .bmsic-mini,.bmsic-main-tile .bmsic-range{max-width:760px}.bmsic-main-tile .bmsic-mini{height:140px}' .
    '.bmsic-range{display:flex;justify-content:space-between;color:#5b6f64;font-size:.8rem}' .
    '.bmsic-nodata{display:block;color:#5b6f64;font-style:italic;margin-top:6px}' .
    '.bmsic-board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(260px,1fr);gap:12px;' .
    'overflow-x:auto;padding-bottom:8px;scroll-snap-type:x proximity}' .
    '.bmsic-col{background:#F6FAF8;border:1px solid #d9e8e0;border-top:6px solid var(--bmsic-col,#0C6C52);' .
    'border-radius:10px;padding:10px;scroll-snap-align:start;min-width:0}' .
    '.bmsic-col:nth-child(even){background:#ECF7F3}' .
    '.bmsic-col h3{font-size:1.02rem;margin:0 0 4px}.bmsic-group{margin-top:10px}' .
    '.bmsic-group h4{font-size:.95rem;margin:0;color:#0B2B41}' .
    '.bmsic-depth-1{padding-left:8px}.bmsic-depth-2{padding-left:16px}.bmsic-depth-3{padding-left:24px}' .
    '.bmsic-kind{font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:#5b6f64}' .
    '.bmsic-unbound{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}' .
    '@media (max-width:768px){.bmsic-board{grid-auto-flow:row;grid-auto-columns:auto;grid-template-columns:1fr;overflow-x:visible}}' .
    '.bmsic-dialog{width:min(1100px,96vw);max-height:94vh;border:1px solid #cfdcd5;border-radius:12px;padding:16px}' .
    '.bmsic-dialog::backdrop{background:rgba(11,43,65,.45)}' .
    '.bmsic-dialog-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}' .
    '.bmsic-controls{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:10px 0}' .
    '.bmsic-chartbox{touch-action:none;outline:none;border:1px solid #e3ebe6;border-radius:8px}' .
    '.bmsic-chartbox:focus-visible{outline:3px solid #1777B5;outline-offset:2px}' .
    '.bmsic-chartbox{position:relative}.bmsic-canvas{width:100%;height:clamp(240px,52vh,420px)}' .
    '.bmsic-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#40564a;' .
    'font-size:1.2rem}.bmsic-empty[hidden]{display:none}' .
    '.bmsic-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:.88rem;margin-top:8px}' .
    '.bmsic-readout{min-height:1.5em;font-weight:600;margin-top:6px}';
echo html_writer::tag('style', $style);

/**
 * Mini chart: the period range and notes are server-rendered; the chart itself is drawn by local_kbacompany/infocenter_chart
 * with the local ECharts module when the tile comes into view. An empty series shows "No data" and no chart.
 */
$spark = static function(array $card, bool $large) use ($str): string {
    $geo = information_center_service::mini_geometry($card['mini']);
    if ($geo['empty']) {
        return html_writer::tag('span', $str('infocenter2_nodata'), ['class' => 'bmsic-nodata']);
    }
    $points = array_map(static function(array $point): array {
        return ['period' => $point['period'], 'fact' => $point['fact'], 'gap' => $point['gap']];
    }, $card['mini']);
    $out = html_writer::tag('span', '', ['class' => 'bmsic-mini' . ($large ? ' bmsic-mini-main' : ''),
        'aria-hidden' => 'true', 'data-bmsic-mini' => json_encode($points,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)]);
    $out .= html_writer::tag('span', html_writer::tag('span', s($geo['first'])) . html_writer::tag('span', s($geo['last'])),
        ['class' => 'bmsic-range']);
    if (count($geo['dots']) === 1) {
        $out .= html_writer::tag('span', $str('infocenter2_single'), ['class' => 'bmsic-muted']);
    }
    return $out;
};

$tile = static function(array $card, bool $large = false) use ($str, $spark): string {
    $name = $card['code'] !== '' ? $card['code'] . ' — ' . $card['name'] : $card['name'];
    $value = $card['last'] ? format_float($card['last']['fact'], 2, true, true) . ($card['unit'] !== '' ? ' ' . $card['unit'] : '') .
        ' · ' . $card['last']['period'] : $str('infocenter2_nodata');
    $trend = $card['trend'];
    $trendtext = html_writer::tag('span', $trend['symbol'], ['class' => 'bmsic-trend-symbol', 'aria-hidden' => 'true']) .
        s($str($trend['key']));
    $qclass = '';
    if ($trend['assessment'] !== '') {
        $trendtext .= ' · ' . s($str($trend['assessment']));
        $qclass = $trend['quality'] === 'improving' ? ' bmsic-q-better' : ($trend['quality'] === 'worsening' ? ' bmsic-q-worse' : '');
    }
    $payload = [
        'name' => $name, 'unit' => $card['unit'], 'direction' => $card['direction'],
        'trend' => $trend['symbol'] . ' ' . $str($trend['key']) .
            ($trend['assessment'] !== '' ? ' · ' . $str($trend['assessment']) : ''),
        'points' => $card['points'],
    ];
    $inner = html_writer::tag('span', s($name), ['class' => 'bmsic-tile-name']) .
        html_writer::tag('span', s($value), ['class' => 'bmsic-tile-value']) .
        html_writer::tag('span', $trendtext, ['class' => 'bmsic-trend' . $qclass]) .
        $spark($card, $large);
    return html_writer::tag('button', $inner, [
        'type' => 'button',
        'class' => 'bmsic-tile' . ($large ? ' bmsic-main-tile' : ''),
        'data-bmsic-chart' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'aria-label' => $str('infocenter2_open', $name . '. ' . $value . '. ' .
            $trend['symbol'] . ' ' . $str($trend['key'])),
        'aria-haspopup' => 'dialog',
    ]);
};

$metricsurl = new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]);
echo html_writer::start_tag('main', ['class' => 'bmsic-main']);
echo $OUTPUT->heading(get_string('productinfocentertitle', 'local_kbacompany'));
echo html_writer::tag('p', $str('infocenter2_intro'), ['class' => 'lead']);

// Main company metric.
echo html_writer::start_tag('section', ['class' => 'bmsic-section', 'aria-labelledby' => 'bmsic-main-h']);
echo html_writer::tag('h2', $str('infocenter2_main_heading'), ['class' => 'h4', 'id' => 'bmsic-main-h']);
if (!$view['main']) {
    echo $OUTPUT->notification($str('infocentermainempty'), 'info');
    if ($view['can_manage']) {
        echo html_writer::link($metricsurl, $str('infocentersetmain'), ['class' => 'btn btn-primary']);
    }
} else {
    if ($view['multiple_main']) {
        // Configuration conflict is shown, not silently resolved.
        echo $OUTPUT->notification($str('infocentermultiplemain'), 'warning');
    }
    foreach ($view['main'] as $card) {
        echo $tile($card, true);
    }
}
echo html_writer::end_tag('section');

// Divisions in organizing-board order.
echo html_writer::start_tag('section', ['class' => 'bmsic-section', 'aria-labelledby' => 'bmsic-board-h']);
echo html_writer::tag('h2', $str('infocenter2_board_heading'), ['class' => 'h4', 'id' => 'bmsic-board-h']);
if (!$view['has_board']) {
    echo $OUTPUT->notification($str('infocenternoorg'), 'info');
} else {
    echo html_writer::start_tag('div', ['class' => 'bmsic-board', 'role' => 'list', 'tabindex' => '0',
        'aria-label' => $str('infocenter2_board_heading')]);
    foreach ($view['divisions'] as $division) {
        $attrs = ['class' => 'bmsic-col', 'role' => 'listitem'];
        if ($division['color'] !== '') { $attrs['style'] = '--bmsic-col:' . $division['color']; }
        echo html_writer::start_tag('div', $attrs);
        echo html_writer::tag('h3', s(trim($division['code'] . ' ' . $division['name'])));
        echo html_writer::tag('div', $str('infocenter2_division_count', $division['metric_count']), ['class' => 'bmsic-meta']);
        if (!$division['groups']) {
            echo html_writer::tag('p', $str('infocenter2_division_empty'), ['class' => 'bmsic-muted']);
        }
        foreach ($division['groups'] as $group) {
            $depth = min(3, (int)$group['depth']);
            echo html_writer::start_tag('div', ['class' => 'bmsic-group bmsic-depth-' . $depth]);
            echo html_writer::tag('div', $str($group['kind'] === 'position' ? 'infocenter2_kind_position' : 'infocenter2_kind_unit'),
                ['class' => 'bmsic-kind']);
            echo html_writer::tag('h4', s(trim($group['code'] . ' ' . $group['name'])));
            foreach ($group['metrics'] as $card) {
                echo $tile($card);
            }
            echo html_writer::end_tag('div');
        }
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('section');

if ($view['unbound']) {
    echo html_writer::start_tag('section', ['class' => 'bmsic-section', 'aria-labelledby' => 'bmsic-unbound-h']);
    echo html_writer::tag('h2', $str('infocenter2_unbound_heading'), ['class' => 'h4', 'id' => 'bmsic-unbound-h']);
    echo html_writer::tag('p', $str('infocenter2_unbound_note'), ['class' => 'bmsic-muted']);
    echo html_writer::start_tag('div', ['class' => 'bmsic-unbound']);
    foreach ($view['unbound'] as $card) {
        echo $tile($card);
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('section');
}
if ($view['can_manage']) {
    echo html_writer::link($metricsurl, $str('infocentersetmain'), ['class' => 'btn btn-outline-secondary mt-2']);
}

// One drill-down dialog, filled by local_kbacompany/infocenter_chart from the clicked tile's data.
$windowopts = '';
foreach (information_center_service::WINDOWS as $window) {
    $windowopts .= html_writer::tag('option', $str('infocenter2_window_n', $window), ['value' => $window]);
}
echo html_writer::tag('dialog',
    html_writer::tag('div',
        html_writer::tag('div', html_writer::tag('h2', '', ['class' => 'h4 mb-1', 'id' => 'bmsic-dlg-title']) .
            html_writer::tag('div', '', ['class' => 'bmsic-meta', 'data-bmsic' => 'meta'])) .
        html_writer::tag('button', $str('infocenter2_close'), ['type' => 'button', 'class' => 'btn btn-outline-secondary',
            'data-bmsic' => 'close']),
        ['class' => 'bmsic-dialog-head']) .
    html_writer::tag('div',
        html_writer::tag('label', $str('infocenter2_window'), ['for' => 'bmsic-window']) .
        html_writer::tag('select', $windowopts, ['id' => 'bmsic-window', 'class' => 'custom-select w-auto', 'data-bmsic' => 'window']) .
        html_writer::tag('button', '−', ['type' => 'button', 'class' => 'btn btn-outline-primary', 'data-bmsic' => 'zoomout',
            'aria-label' => $str('infocenter2_zoomout'), 'title' => $str('infocenter2_zoomout')]) .
        html_writer::tag('button', '+', ['type' => 'button', 'class' => 'btn btn-outline-primary', 'data-bmsic' => 'zoomin',
            'aria-label' => $str('infocenter2_zoomin'), 'title' => $str('infocenter2_zoomin')]) .
        html_writer::tag('button', '←', ['type' => 'button', 'class' => 'btn btn-outline-primary', 'data-bmsic' => 'panleft',
            'aria-label' => $str('infocenter2_panleft'), 'title' => $str('infocenter2_panleft')]) .
        html_writer::tag('button', '→', ['type' => 'button', 'class' => 'btn btn-outline-primary', 'data-bmsic' => 'panright',
            'aria-label' => $str('infocenter2_panright'), 'title' => $str('infocenter2_panright')]) .
        html_writer::tag('button', $str('infocenter2_reset'), ['type' => 'button', 'class' => 'btn btn-outline-secondary',
            'data-bmsic' => 'reset']) .
        html_writer::tag('span', '', ['class' => 'bmsic-meta', 'data-bmsic' => 'visible']),
        ['class' => 'bmsic-controls']) .
    html_writer::tag('div', '', ['class' => 'bmsic-chartbox', 'tabindex' => '0', 'role' => 'application',
        'data-bmsic' => 'chart', 'aria-describedby' => 'bmsic-hint bmsic-readout']) .
    html_writer::tag('div', '', ['class' => 'bmsic-readout', 'id' => 'bmsic-readout', 'aria-live' => 'polite',
        'data-bmsic' => 'readout']) .
    html_writer::tag('div', '', ['class' => 'bmsic-legend', 'data-bmsic' => 'legend']) .
    html_writer::tag('p', $str('infocenter2_hint'), ['class' => 'bmsic-muted mt-2 mb-0', 'id' => 'bmsic-hint']),
    ['class' => 'bmsic-dialog', 'id' => 'bmsic-dialog', 'aria-labelledby' => 'bmsic-dlg-title']);

echo html_writer::end_tag('main');
\local_kbacompany\local\bms_page_shell_service::finish($shellstate);
