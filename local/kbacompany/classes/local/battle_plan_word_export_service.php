<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** True DOCX export for a weekly Battle Plan. */
class battle_plan_word_export_service {
    public const CONTRACT_VERSION = '0.5.10';

    /** Fetch human-readable signatories without exposing users outside the requested company. */
    public static function signers_for_export(string $companyuuid, array $plan): array {
        global $DB;
        $result = [];
        $actors = [
            'prepared' => [(int)($plan['created_by'] ?? 0), (int)($plan['time_created'] ?? 0)],
            'approved' => [(int)($plan['approved_by'] ?? 0), (int)($plan['approved_at'] ?? 0)],
        ];
        foreach ($actors as $kind => $actor) {
            list($userid, $at) = $actor;
            if ($userid <= 0 || $at <= 0) { continue; }
            $user = $DB->get_record_sql('SELECT u.id,u.firstname,u.lastname,u.middlename,u.firstnamephonetic,' .
                'u.lastnamephonetic,u.alternatename FROM {user} u WHERE u.id=:userid AND u.deleted=0' .
                ' AND EXISTS (SELECT m.id FROM {local_kbacompany_member} m' .
                ' WHERE m.companyuuid=:company AND m.userid=u.id)',
                ['userid'=>$userid,'company'=>$companyuuid]);
            if (!$user) { continue; }
            $result[$kind . '_name'] = fullname($user);
            $sql = 'SELECT p.id,p.name FROM {local_kbacompany_posassign} a' .
                ' JOIN {local_kbacompany_position} p ON p.companyuuid=a.companyuuid AND p.uuid=a.positionuuid' .
                ' WHERE a.companyuuid=:company AND a.userid=:userid' .
                ' AND (a.effectivefrom=0 OR a.effectivefrom<=:startat)' .
                ' AND (a.effectiveto=0 OR a.effectiveto>=:endat)' .
                ' ORDER BY a.active DESC,a.id DESC';
            $position = $DB->get_record_sql($sql, [
                'company'=>$companyuuid, 'userid'=>$userid, 'startat'=>$at, 'endat'=>$at], IGNORE_MULTIPLE);
            if ($position) { $result[$kind . '_position'] = (string)$position->name; }
        }
        return $result;
    }

    /** Build a localized document model from Battle Plan context. */
    public static function model(array $context): array {
        $plan = $context['selected'] ?? null;
        if (!$plan) { throw new \moodle_exception('battleplan_reference', 'local_kbacompany'); }
        $employees = $context['employees'] ?? [];
        $positions = $context['positions'] ?? [];
        $streams = [];
        foreach (task_service::STREAMS as $stream) {
            $tasks = [];
            foreach (($context['by_stream'][$stream] ?? []) as $item) {
                $task = $item['task'];
                $start = (string)($task['start_date'] ?? '');
                $end = (string)($task['due_date'] ?? '');
                $dates = $start === '' ? $end : ($end === '' || $end === $start ? $start : $start . ' — ' . $end);
                // Export labels use the human-readable local date rather than ISO notation.
                $dates = self::date_labels($dates);
                $tasks[] = [
                    'title' => (string)($task['title'] ?? '').
                        ((int)($plan['approved_at'] ?? 0) > 0 &&
                            (int)($item['time_created'] ?? 0) > (int)$plan['approved_at'] ?
                            ' · '.get_string('bpu_added_after_approval', 'local_kbacompany') : ''),
                    'product' => (string)($task['product'] ?? ''),
                    'status' => get_string('taskcenter_status_' . (string)($task['status'] ?? 'open'), 'local_kbacompany'),
                    'priority' => get_string('taskcenter_priority_' . (string)($task['priority'] ?? 'normal'), 'local_kbacompany'),
                    'dates' => $dates,
                    'time' => (int)($task['plan_minutes'] ?? 0) . ' / ' . (int)($task['fact_minutes'] ?? 0),
                    'result' => (string)($task['result'] ?? ''),
                ];
            }
            $streams[] = [
                'key' => $stream,
                'label' => get_string('taskcenter_stream_' . $stream, 'local_kbacompany'),
                'tasks' => $tasks,
            ];
        }
        $metrics = [];
        $metricmode = !empty($context['position_indicators']) ? 'canonical' : 'legacy';
        foreach (($context['position_indicators'] ?? []) as $metric) {
            $condition = (string)($metric['condition'] ?? '');
            $movement = (string)($metric['trend']['movement'] ?? 'insufficient');
            $quality = (string)($metric['trend']['quality'] ?? 'insufficient');
            $trend = get_string('indicator_trend_' . $movement, 'local_kbacompany');
            if ($quality !== 'insufficient') {
                $trend .= ' · ' . get_string('indicator_trend_' . $quality, 'local_kbacompany');
            }
            $conditiontext = $condition !== '' ? get_string('indicator_condition_' . $condition, 'local_kbacompany') :
                get_string('indicator_condition_undetermined', 'local_kbacompany');
            if (!empty($metric['task']['visible'])) {
                $conditiontext .= "\n" . get_string('battleplan_indicator_task_status', 'local_kbacompany') . ': ' .
                    get_string('taskcenter_status_' . (string)$metric['task']['status'], 'local_kbacompany');
                if ($metric['task']['checklist_percent'] !== null) {
                    $conditiontext .= "\n" . get_string('battleplan_indicator_task_checklist', 'local_kbacompany', (object)[
                        'done'=>$metric['task']['checklist_done'], 'total'=>$metric['task']['checklist_total'],
                        'percent'=>format_float((float)$metric['task']['checklist_percent'], 0),
                    ]);
                }
                if ((string)$metric['task']['result'] !== '') {
                    $conditiontext .= "\n" . get_string('battleplan_indicator_task_result', 'local_kbacompany') . ': ' .
                        (string)$metric['task']['result'];
                }
            }
            $previousaction = $metric['previous_action'] ?? ['exists'=>false,'task'=>['visible'=>false],
                'observation'=>['baseline_fact'=>null,'followup_fact'=>null,'quality'=>'insufficient']];
            if (!empty($previousaction['exists']) && !empty($previousaction['task']['visible'])) {
                $conditiontext .= "\n" . get_string('battleplan_indicator_previous_action', 'local_kbacompany') . ': ' .
                    get_string('taskcenter_status_' . (string)$previousaction['task']['status'], 'local_kbacompany');
                if ($previousaction['task']['checklist_percent'] !== null) {
                    $conditiontext .= "\n" . get_string('battleplan_indicator_task_checklist', 'local_kbacompany', (object)[
                        'done'=>$previousaction['task']['checklist_done'], 'total'=>$previousaction['task']['checklist_total'],
                        'percent'=>format_float((float)$previousaction['task']['checklist_percent'], 0),
                    ]);
                }
                if ((string)$previousaction['task']['result'] !== '') {
                    $conditiontext .= "\n" . get_string('battleplan_indicator_task_result', 'local_kbacompany') . ': ' .
                        (string)$previousaction['task']['result'];
                }
                $observation = $previousaction['observation'];
                if (($observation['baseline_fact'] ?? null) !== null && ($observation['followup_fact'] ?? null) !== null) {
                    $conditiontext .= "\n" . get_string('battleplan_indicator_after_action', 'local_kbacompany', (object)[
                        'before'=>format_float((float)$observation['baseline_fact'], 2) . ' ' . (string)$metric['unit'],
                        'after'=>format_float((float)$observation['followup_fact'], 2) . ' ' . (string)$metric['unit'],
                        'assessment'=>get_string('battleplan_indicator_after_action_' . (string)$observation['quality'],
                            'local_kbacompany'),
                    ]);
                }
            }
            $previous = $metric['previous_fact'] === null ? '—' :
                format_float((float)$metric['previous_fact'], 2);
            // Day-based indicators use the sum of real dated observations from the previous week.
            // Mark partial totals explicitly; do not treat absent days as zero.
            if ($previous !== '—' && !empty($metric['daily_aggregate'])) {
                $previous .= ' (' . get_string('bpu_metric_previous_fact_days', 'local_kbacompany',
                    (int)($metric['previous_fact_days'] ?? 0)) . ')';
            }
            $targetvalue = $metric['current_plan'] ?? null;
            $quota = $targetvalue !== null ? format_float((float)$targetvalue, 2) :
                ((string)($metric['norm_label'] ?? '') !== '' ? (string)$metric['norm_label'] : '—');
            $metrics[] = [
                'name' => (string)($metric['code'] ?? '') . ' — ' . (string)($metric['name'] ?? '') .
                    ((string)($metric['unit'] ?? '') !== '' ? ' (' . (string)$metric['unit'] . ')' : ''),
                'previous' => $previous,
                'quota' => $quota,
                'condition' => $conditiontext,
                'fact' => $metric['current_fact'] === null ? '—' : format_float((float)$metric['current_fact'], 2),
                'trend' => $trend,
            ];
        }
        // Legacy pinned quota metrics remain exportable for historical plans that do not yet have
        // canonical position bindings.
        if (!$metrics) {
            foreach (($context['metrics'] ?? []) as $metric) {
                if (!array_key_exists('quota', $metric)) { continue; }
                $condition = (string)($metric['condition'] ?? '');
                $metrics[] = [
                    'name' => (string)($metric['code'] ?? '') . ' — ' . (string)($metric['name'] ?? ''),
                    'previous' => $metric['previous_fact'] === null ? '—' : format_float((float)$metric['previous_fact'], 2),
                    'quota' => format_float((float)$metric['quota'], 2),
                    'condition' => $condition !== '' ? get_string('battleplan_condition_' . $condition, 'local_kbacompany') : get_string('battleplan_condition_nodata', 'local_kbacompany'),
                    'fact' => $metric['fact'] === null ? '—' : format_float((float)$metric['fact'], 2),
                    'performance' => $metric['performance'] === null ? '—' : format_float((float)$metric['performance'], 1) . '%',
                    'trend' => $metric['trend'] === null ? '—' : format_float((float)$metric['trend'], 1) . '%',
                ];
            }
        }
        // The preparer is the recorded creation actor; creation time is not an approval time.
        // The approver is recorded only after approval. Never fabricate a signature for drafts.
        $preparerid = (int)($plan['created_by'] ?? 0);
        if ($preparerid <= 0) { $preparerid = (int)$plan['user_id']; }
        $approverid = (int)($plan['approved_by'] ?? 0);
        $approvedat = (int)($plan['approved_at'] ?? 0);
        $signers = (array)($context['export_signers'] ?? []);
        $preparedat = (int)($plan['time_created'] ?? 0);
        $preparedname = (string)($signers['prepared_name'] ?? $employees[$preparerid] ?? '');
        $approvedname = ($approverid > 0 && $approvedat > 0) ?
            (string)($signers['approved_name'] ?? $employees[$approverid] ?? '') : '';
        $preparedrole = (string)($signers['prepared_position'] ?? '');
        if ($preparedrole === '' && $preparerid === (int)$plan['user_id']) {
            $preparedrole = (string)($positions[(string)$plan['position_uuid']] ?? '');
        }
        $approvedrole = ($approverid > 0 && $approvedat > 0) ?
            (string)($signers['approved_position'] ?? '') : '';
        return [
            'prepared_signature' => [
                'position' => $preparedrole, 'name' => $preparedname,
                'datetime' => $preparedat > 0 ? userdate($preparedat, '%d.%m.%Y %H:%M') : '',
            ],
            'approved_signature' => [
                'position' => $approvedrole, 'name' => $approvedname,
                'datetime' => $approverid > 0 && $approvedat > 0 ? userdate($approvedat, '%d.%m.%Y %H:%M') : '',
            ],
            'title' => get_string('battleplan_word_title', 'local_kbacompany'),
            'company' => (string)($context['company']['name'] ?? ''),
            'employee' => (string)($employees[(int)$plan['user_id']] ?? ''),
            'position' => (string)($positions[(string)$plan['position_uuid']] ?? $plan['position_uuid']),
            'period' => self::date_labels((string)$plan['period_start'] . ' — ' . (string)$plan['period_end']),
            'status' => get_string('battleplan_status_' . (string)$plan['status'], 'local_kbacompany'),
            'reviewer' => (string)($employees[(int)$plan['reviewer_id']] ?? ''),
            'observer' => (string)($employees[(int)($plan['observer_id'] ?? 0)] ?? ''),
            'summary' => get_string('battleplan_summary', 'local_kbacompany', (object)$context['summary']),
            'generated' => get_string('battleplan_word_generated', 'local_kbacompany') . ': ' . userdate(time()),
            'metrics' => $metrics,
            'metric_mode' => $metricmode,
            'overall_condition' => $context['overall_condition'] ?? ['performance'=>null,'condition'=>''],
            'streams' => $streams,
            'labels' => [
                'company' => get_string('battleplan_word_company', 'local_kbacompany'),
                'employee' => get_string('battleplan_employee', 'local_kbacompany'),
                'position' => get_string('battleplan_position', 'local_kbacompany'),
                'period' => get_string('battleplan_period', 'local_kbacompany'),
                'status' => get_string('battleplan_status', 'local_kbacompany'),
                'reviewer' => get_string('battleplan_reviewer', 'local_kbacompany'),
                'observer' => get_string('battleplan_observer', 'local_kbacompany'),
                'tasks' => get_string('battleplan_word_tasks', 'local_kbacompany'),
                'task' => get_string('taskcenter_task', 'local_kbacompany'),
                'product' => get_string('battleplan_word_product', 'local_kbacompany'),
                'state' => get_string('taskcenter_status', 'local_kbacompany') . ' / ' . get_string('battleplan_word_priority', 'local_kbacompany'),
                'dates' => get_string('battleplan_word_dates', 'local_kbacompany'),
                'time' => get_string('battleplan_word_time', 'local_kbacompany'),
                'result' => get_string('battleplan_word_result', 'local_kbacompany'),
                'metrics' => $metricmode === 'canonical' ? get_string('battleplan_position_indicators', 'local_kbacompany') :
                    get_string('battleplan_statistics', 'local_kbacompany'),
                'metric' => get_string('battleplan_metric', 'local_kbacompany'),
                'previous' => get_string('battleplan_previous_fact', 'local_kbacompany'),
                'quota' => self::label('bpu_metric_export_quota_or_plan', 'План / квота', 'Target / quota'),
                'condition' => get_string('battleplan_condition', 'local_kbacompany'),
                'fact' => get_string('battleplan_fact', 'local_kbacompany'),
                'performance' => get_string('battleplan_performance', 'local_kbacompany'),
                'trend' => get_string('battleplan_trend', 'local_kbacompany'),
                'prepared' => self::label('bpu_export_prepared_by', 'План подготовил', 'Prepared by'),
                'approved' => self::label('bpu_export_approved_by', 'План одобрил', 'Approved by'),
                'signature_position' => self::label('bpu_export_signature_position', 'Должность', 'Position'),
                'signature_name' => self::label('bpu_export_signature_name', 'ФИО', 'Full name'),
                'signature_date' => self::label('bpu_export_signature_date', 'Дата и время', 'Date and time'),
                'signature_pending' => self::label('bpu_export_signature_pending', 'Не одобрен', 'Not approved'),
            ],
        ];
    }

    /** Localize ISO dates in a human-facing DOCX without changing stored dates. */
    private static function date_labels(string $text): string {
        return preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/',
            static function ($m) { return $m[3] . '.' . $m[2] . '.' . $m[1]; }, $text);
    }

    /** A fallback is required for a newly added Moodle string until caches are purged. */
    private static function label(string $key, string $ru, string $en): string {
        $value = (string)get_string($key, 'local_kbacompany');
        if (strpos($value, '[[') === 0) {
            return function_exists('current_language') && current_language() !== 'ru' ? $en : $ru;
        }
        return $value;
    }

    private static function x(string $value): string {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function paragraph(string $text, bool $bold = false, int $size = 20, string $align = ''): string {
        $ppr = '<w:pPr><w:spacing w:before="0" w:after="50" w:line="240" w:lineRule="auto"/>' .
            ($align !== '' ? '<w:jc w:val="' . self::x($align) . '"/>' : '') . '</w:pPr>';
        $rpr = '<w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $runs = [];
        foreach ($lines as $line) {
            $runs[] = '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::x($line) . '</w:t></w:r>';
        }
        return '<w:p>' . $ppr . implode('<w:r><w:br/></w:r>', $runs) . '</w:p>';
    }

    private static function cell(string $text, int $width, bool $header = false): string {
        $shading = $header ? '<w:shd w:val="clear" w:color="auto" w:fill="D9EAD3"/>' : '';
        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . $shading .
            '<w:tcMar><w:top w:w="55" w:type="dxa"/><w:start w:w="70" w:type="dxa"/>' .
            '<w:bottom w:w="45" w:type="dxa"/><w:end w:w="70" w:type="dxa"/></w:tcMar>' .
            '<w:vAlign w:val="top"/></w:tcPr>' . self::paragraph($text, $header, 17) . '</w:tc>';
    }

    /** The final two-column block follows the source weekly-plan template. */
    private static function signatures(array $model): string {
        $labels = $model['labels'];
        $prepared = $model['prepared_signature'] ?? [];
        $approved = $model['approved_signature'] ?? [];
        $pairs = [
            [$labels['prepared'] ?? 'План подготовил', $prepared, false],
            [$labels['approved'] ?? 'План одобрил', $approved, true],
        ];
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="14900" w:type="dxa"/><w:tblLayout w:type="fixed"/>' .
            '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="CBD8D1"/>' .
            '<w:bottom w:val="single" w:sz="4" w:color="CBD8D1"/>' .
            '<w:left w:val="single" w:sz="4" w:color="CBD8D1"/>' .
            '<w:right w:val="single" w:sz="4" w:color="CBD8D1"/>' .
            '<w:insideV w:val="single" w:sz="4" w:color="CBD8D1"/></w:tblBorders></w:tblPr>' .
            '<w:tblGrid><w:gridCol w:w="7450"/><w:gridCol w:w="7450"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>';
        foreach ($pairs as $pair) {
            list($title, $signer, $isapproval) = $pair;
            $blank = '—';
            $position = trim((string)($signer['position'] ?? ''));
            $name = trim((string)($signer['name'] ?? ''));
            $date = trim((string)($signer['datetime'] ?? ''));
            $pending = $isapproval && $date === '';
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="7450" w:type="dxa"/>' .
                '<w:shd w:val="clear" w:color="auto" w:fill="EDF4EE"/>' .
                '<w:tcMar><w:top w:w="125" w:type="dxa"/><w:start w:w="185" w:type="dxa"/>' .
                '<w:bottom w:w="115" w:type="dxa"/><w:end w:w="185" w:type="dxa"/></w:tcMar></w:tcPr>' .
                self::paragraph((string)$title, true, 20) .
                self::paragraph((string)($labels['signature_position'] ?? 'Должность') . ': ' .
                    (!$pending && $position !== '' ? $position : $blank), false, 18) .
                self::paragraph((string)($labels['signature_name'] ?? 'ФИО') . ': ' .
                    (!$pending && $name !== '' ? $name : $blank), false, 18) .
                self::paragraph((string)($labels['signature_date'] ?? 'Дата и время') . ': ' .
                    ($date !== '' ? $date : $blank), false, 18);
            if ($pending) {
                $xml .= self::paragraph((string)($labels['signature_pending'] ?? 'Не одобрен'), false, 16);
            }
            $xml .= '</w:tc>';
        }
        return $xml . '</w:tr></w:tbl>';
    }

    private static function metric_table(array $metrics, array $labels, string $mode = 'legacy'): string {
        if ($mode === 'canonical') {
            $widths = [4000, 2050, 1950, 2400, 1750, 2650];
            $heads = [$labels['metric'], $labels['previous'], $labels['quota'], $labels['condition'], $labels['fact'], $labels['trend']];
        } else {
            $widths = [3600, 1800, 1700, 2200, 1700, 1900, 1900];
            $heads = [$labels['metric'], $labels['previous'], $labels['quota'], $labels['condition'], $labels['fact'], $labels['performance'], $labels['trend']];
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="14800" w:type="dxa"/><w:tblLayout w:type="fixed"/>' .
            '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="B7B7B7"/><w:left w:val="single" w:sz="4" w:color="B7B7B7"/>' .
            '<w:bottom w:val="single" w:sz="4" w:color="B7B7B7"/><w:right w:val="single" w:sz="4" w:color="B7B7B7"/>' .
            '<w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideV w:val="single" w:sz="4" w:color="D9D9D9"/></w:tblBorders></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) { $xml .= '<w:gridCol w:w="' . $width . '"/>'; }
        $xml .= '</w:tblGrid><w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($heads as $i => $head) { $xml .= self::cell($head, $widths[$i], true); }
        $xml .= '</w:tr>';
        foreach ($metrics as $metric) {
            if ($mode === 'canonical') {
                $xml .= '<w:tr>' . self::cell($metric['name'], $widths[0]) . self::cell($metric['previous'], $widths[1]) .
                    self::cell($metric['quota'], $widths[2]) . self::cell($metric['condition'], $widths[3]) .
                    self::cell($metric['fact'], $widths[4]) . self::cell($metric['trend'], $widths[5]) . '</w:tr>';
            } else {
                $xml .= '<w:tr>' . self::cell($metric['name'], $widths[0]) . self::cell($metric['previous'], $widths[1]) .
                    self::cell($metric['quota'], $widths[2]) . self::cell($metric['condition'], $widths[3]) .
                    self::cell($metric['fact'], $widths[4]) . self::cell($metric['performance'], $widths[5]) .
                    self::cell($metric['trend'], $widths[6]) . '</w:tr>';
            }
        }
        return $xml . '</w:tbl>';
    }

    private static function task_table(array $tasks, array $labels): string {
        $widths = [3300, 4100, 1900, 2000, 1350, 2450];
        $heads = [$labels['task'], $labels['product'], $labels['state'], $labels['dates'], $labels['time'], $labels['result']];
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="15100" w:type="dxa"/><w:tblLayout w:type="fixed"/>' .
            '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="B7B7B7"/><w:left w:val="single" w:sz="4" w:color="B7B7B7"/>' .
            '<w:bottom w:val="single" w:sz="4" w:color="B7B7B7"/><w:right w:val="single" w:sz="4" w:color="B7B7B7"/>' .
            '<w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideV w:val="single" w:sz="4" w:color="D9D9D9"/></w:tblBorders></w:tblPr>' .
            '<w:tblGrid>';
        foreach ($widths as $width) { $xml .= '<w:gridCol w:w="' . $width . '"/>'; }
        $xml .= '</w:tblGrid>';
        $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($heads as $i => $head) { $xml .= self::cell($head, $widths[$i], true); }
        $xml .= '</w:tr>';
        foreach ($tasks as $task) {
            $xml .= '<w:tr>' . self::cell($task['title'], $widths[0]) . self::cell($task['product'], $widths[1]) .
                self::cell($task['status'] . ' / ' . $task['priority'], $widths[2]) . self::cell($task['dates'], $widths[3]) .
                self::cell($task['time'], $widths[4]) . self::cell($task['result'], $widths[5]) . '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    /** Pure OOXML file map; useful for runtime packaging and deterministic tests. */
    public static function ooxml_files(array $model): array {
        $body = self::paragraph($model['title'], true, 32, 'center');
        $body .= self::paragraph($model['company'], true, 24, 'center');
        $meta = [
            $model['labels']['employee'] . ': ' . $model['employee'],
            $model['labels']['position'] . ': ' . $model['position'],
            $model['labels']['period'] . ': ' . $model['period'],
            $model['labels']['status'] . ': ' . $model['status'],
            $model['labels']['reviewer'] . ': ' . $model['reviewer'],
            $model['labels']['observer'] . ': ' . (!empty($model['observer']) ? $model['observer'] : '—'),
        ];
        foreach ($meta as $line) { $body .= self::paragraph($line, false, 20); }
        $body .= self::paragraph($model['summary'], true, 20);
        if (!empty($model['metrics'])) {
            $body .= self::paragraph($model['labels']['metrics'], true, 24);
            $body .= self::metric_table($model['metrics'], $model['labels'], (string)($model['metric_mode'] ?? 'legacy'));
            if (!empty($model['overall_condition']['condition'])) {
                $label = get_string('battleplan_condition_' . $model['overall_condition']['condition'], 'local_kbacompany');
                $body .= self::paragraph(get_string('battleplan_overall_condition', 'local_kbacompany', (object)[
                    'condition' => $label,
                    'performance' => format_float((float)$model['overall_condition']['performance'], 1),
                ]), true, 20);
            }
            $body .= self::paragraph('', false, 8);
        }
        foreach ($model['streams'] as $stream) {
            $body .= self::paragraph($stream['label'], true, 24);
            if ($stream['tasks']) {
                $body .= self::task_table($stream['tasks'], $model['labels']);
            } else {
                $body .= self::paragraph('—', false, 18);
            }
            $body .= self::paragraph('', false, 8);
        }
        $body .= self::paragraph('', false, 8);
        $body .= self::signatures($model);
        $body .= self::paragraph($model['generated'], false, 16, 'right');
        $body .= '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>' .
            '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr>' .
            '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style></w:styles>';
        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
                '<Default Extension="xml" ContentType="application/xml"/>' .
                '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
                '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' .
                '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
                '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
                '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => $styles,
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" ' .
                'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
                '<dc:title>' . self::x($model['title']) . '</dc:title><dc:creator>BMS</dc:creator></cp:coreProperties>',
            'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" ' .
                'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>BMS</Application></Properties>',
        ];
    }

    /** Package OOXML as a real .docx using Moodle's zip packer. */
    public static function write_docx(array $model, string $destination): void {
        $requestroot = make_request_directory();
        $root = $requestroot . '/battleplan_docx_' . bin2hex(random_bytes(6));
        if (!is_dir($root)) { mkdir($root, 0777, true); }
        $files = [];
        foreach (self::ooxml_files($model) as $relative => $content) {
            $path = $root . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir)) { mkdir($dir, 0777, true); }
            file_put_contents($path, $content);
            $files[$relative] = $path;
        }
        $packer = get_file_packer('application/zip');
        if (!$packer->archive_to_pathname($files, $destination)) {
            throw new \moodle_exception('battleplan_wordexporterror', 'local_kbacompany');
        }
    }
}
