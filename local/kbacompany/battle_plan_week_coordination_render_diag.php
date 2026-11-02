<?php
// Admin-only full render test for the weekly-coordination page. Does not write data.
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
// Never execute actions or writes from this diagnostic route.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new moodle_exception('invalidrequest');
}
// The included page may write HTTP headers; don't send any output before it finishes.
$start = microtime(true);
$baselevel = ob_get_level();
$GLOBALS['bms24_coord_render_step'] = 'init';
$failure = null;
$size = 0;
try {
    ob_start();
    // The real page executes in this isolated buffer, exercising its entire HTML render path.
    require(__DIR__ . '/battle_plan_week_coordination.php');
    $size = strlen(ob_get_contents());
} catch (\Throwable $e) {
    $failure = $e;
} finally {
    while (ob_get_level() > $baselevel) {
        ob_end_clean();
    }
}
$step = (string)($GLOBALS['bms24_coord_render_step'] ?? 'unknown');
$elapsed = round(microtime(true) - $start, 2);
// The original page has already initialized $PAGE and may have emitted headers in the
// discarded buffer. Render a plain, self-contained diagnostic body for administrators.
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>BMS24 — диагностика рендеринга</title>';
echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:850px;margin:50px auto;padding:0 20px}' .
    '.good{background:#def4e6;padding:16px}.bad{background:#fee3e0;padding:16px}pre{white-space:pre-wrap;overflow-wrap:anywhere}' .
    '</style></head><body><h1>BMS24: диагностика HTML-рендеринга</h1>';
echo '<p>Доступно только администратору. Данные не изменяются.</p>';
echo '<p>Последний этап: <strong>' . s($step) . '</strong>; время: ' . $elapsed . ' с.</p>';
if ($failure === null) {
    echo '<div class="good"><strong>PASS:</strong> весь HTML сформирован; размер: ' . (int)$size . ' байт.</div>';
    echo '<p>Если обычная страница всё равно выдаёт HTTP 500, проверьте PHP memory_limit, серверный таймаут и вывод/заголовки.</p>';
} else {
    $reference = 'BPWC-RENDER-' . date('YmdHis') . '-' . substr(hash('sha256',
        $failure->getFile() . ':' . $failure->getLine() . ':' . $failure->getMessage()), 0, 8);
    error_log('[BMS24 ' . $reference . '] Render diagnostic at ' . $step . ': ' . (string)$failure);
    echo '<div class="bad"><strong>FAIL:</strong> ' . s(get_class($failure)) . '</div>';
    echo '<h2>Сведения для исправления</h2><pre>' . s($failure->getMessage() . "\n" .
        $failure->getFile() . ':' . $failure->getLine()) . '</pre>';
    echo '<p>Код диагностики: ' . s($reference) . '</p>';
}
echo '<p><a href="' . s((new moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
    ['uuid' => $uuid, 'week' => $week, 'orgnode' => $orgnode]))->out(false)) . '">Открыть обычную страницу</a></p>';
echo '</body></html>';
