<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:vieweconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$category = optional_param('category', '', PARAM_ALPHANUMEXT);
$periodtype = optional_param('periodtype', '', PARAM_ALPHA);
$auditid = optional_param('auditid', 0, PARAM_INT);
$verifiedonly = optional_param('verifiedonly', 0, PARAM_BOOL);
$frominput = optional_param('fromdate', '', PARAM_RAW_TRIMMED);
$toinput = optional_param('todate', '', PARAM_RAW_TRIMMED);
$indicatorids = optional_param_array('indicatorids', [], PARAM_INT);

$company = \local_kbacorporate\local\access::require_full_company_access(
    $companyid,
    (int)$USER->id
);
$canmanage = has_capability('local/kbacorporate:manageeconomicindicators', $context);

$from = \local_kbacorporate\local\economic_dashboard::parse_date_input($frominput, false);
$to = \local_kbacorporate\local\economic_dashboard::parse_date_input($toinput, true);
$model = \local_kbacorporate\local\economic_dashboard::build($companyid, [
    'category' => $category,
    'periodtype' => $periodtype,
    'auditid' => $auditid,
    'verifiedonly' => $verifiedonly,
    'from' => $from,
    'to' => $to,
    'datesexplicit' => $frominput !== '' || $toinput !== '',
    'indicatorids' => $indicatorids,
]);

$pageparams = ['companyid' => $companyid];
if ($model->category !== '') {
    $pageparams['category'] = $model->category;
}
if ($model->periodtype !== '') {
    $pageparams['periodtype'] = $model->periodtype;
}
if ($model->auditid > 0) {
    $pageparams['auditid'] = $model->auditid;
}
if ($model->verifiedonly) {
    $pageparams['verifiedonly'] = 1;
}
$pageparams['fromdate'] = userdate((int)$model->from, '%Y-%m-%d');
$pageparams['todate'] = userdate((int)$model->to, '%Y-%m-%d');
if ($model->selectedids) {
    $pageparams['indicatorids'] = $model->selectedids;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/economic_dashboard.php', $pageparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('economicdashboard', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$categoryoptions = [
    '' => get_string('all'),
    'finance' => get_string('economiccategoryfinance', 'local_kbacorporate'),
    'sales' => get_string('economiccategorysales', 'local_kbacorporate'),
    'operations' => get_string('economiccategoryoperations', 'local_kbacorporate'),
    'people' => get_string('economiccategorypeople', 'local_kbacorporate'),
    'clients' => get_string('economiccategoryclients', 'local_kbacorporate'),
];
$periodoptions = [
    '' => get_string('all'),
    'month' => get_string('economicperiodmonth', 'local_kbacorporate'),
    'quarter' => get_string('economicperiodquarter', 'local_kbacorporate'),
    'year' => get_string('economicperiodyear', 'local_kbacorporate'),
    'custom' => get_string('economicperiodcustom', 'local_kbacorporate'),
];
$auditoptions = \local_kbacorporate\local\economic_indicators::get_audit_options($companyid);
$auditoptions[0] = get_string('economicdashboardallaudits', 'local_kbacorporate');

$select = static function(string $name, array $options, $selected): string {
    return html_writer::select($options, $name, $selected, false, ['class' => 'form-control']);
};
$formatrecord = static function($record, $indicator): string {
    if (!$record) {
        return '—';
    }
    return \local_kbacorporate\local\economic_indicators::format_value(
        $record->value,
        (string)$indicator->valuetype,
        (string)$indicator->unit,
        (string)$record->currency
    );
};
$formatdelta = static function($change, $indicator, $record): string {
    if (!$change || empty($change->available)) {
        return '—';
    }
    $currency = $record ? (string)$record->currency : 'RUB';
    $formatted = \local_kbacorporate\local\economic_indicators::format_value(
        $change->delta,
        (string)$indicator->valuetype,
        (string)$indicator->unit,
        $currency
    );
    if ((float)$change->delta > 0) {
        $formatted = '+' . $formatted;
    }
    if ($change->percent !== null) {
        $formatted .= ' (' . ((float)$change->percent > 0 ? '+' : '') .
            format_float((float)$change->percent, 2) . '%)';
    }
    return $formatted;
};
$changebadge = static function($change, $indicator, $record) use ($formatdelta): string {
    if (!$change || empty($change->available)) {
        return html_writer::span(get_string('economicdashboardunknown', 'local_kbacorporate'),
            'kbacorp-economic-delta kbacorp-economic-delta-unknown');
    }
    $delta = (float)$change->delta;
    $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');
    $label = $arrow . ' ' . $formatdelta($change, $indicator, $record);
    return html_writer::span($label,
        'kbacorp-economic-delta kbacorp-economic-delta-' . s((string)$change->state));
};
$statehuman = static function(string $state): string {
    $valid = ['positive', 'negative', 'neutral', 'unknown', 'incomparable'];
    if (!in_array($state, $valid, true)) {
        $state = 'unknown';
    }
    return get_string('economicdashboardstate' . $state, 'local_kbacorporate');
};
$axislabel = static function($panel): string {
    $indicator = $panel->indicator;
    if ((string)$indicator->valuetype === \local_kbacorporate\local\economic_indicators::TYPE_MONEY ||
            (string)$indicator->unit === 'currency') {
        if ($panel->latestactual) {
            return (string)$panel->latestactual->currency;
        }
        if ($panel->currencies) {
            return (string)reset($panel->currencies);
        }
        return get_string('economiccurrencyunit', 'local_kbacorporate');
    }
    return (string)$indicator->unit;
};

// Build a compact multi-select explicitly because html_writer::select() is
// primarily designed for one selected value.
$indicatoroptionshtml = '';
$selectedmap = array_fill_keys(array_map('intval', $model->selectedids), true);
foreach ($model->catalog as $indicator) {
    $attributes = ['value' => (int)$indicator->id];
    if (isset($selectedmap[(int)$indicator->id])) {
        $attributes['selected'] = 'selected';
    }
    $indicatoroptionshtml .= html_writer::tag(
        'option',
        s((string)$indicator->code) . ' — ' . format_string($indicator->name),
        $attributes
    );
}
$indicatorselect = html_writer::tag('select', $indicatoroptionshtml, [
    'name' => 'indicatorids[]',
    'multiple' => 'multiple',
    'size' => 8,
    'class' => 'form-control kbacorp-economic-indicator-select',
    'aria-describedby' => 'economic-dashboard-indicator-hint',
]);

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'economics');
echo local_kbacorporate_company_context_banner($company);
echo $OUTPUT->heading(get_string('economicdashboard', 'local_kbacorporate'));
echo html_writer::div(get_string('economicdashboarddesc', 'local_kbacorporate'), 'kbacorp-pagelead');

$buttons = [];
$buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators.php', [
    'companyid' => $companyid,
    'auditid' => $auditid,
]), get_string('economicdashboardopenregistry', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
if ($canmanage) {
    $buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('economicaddvalue', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
}
$buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_dashboard_export.php', $pageparams),
    get_string('economicdashboardexport', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
if ($auditid > 0) {
    $buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
        'companyid' => $companyid,
        'id' => $auditid,
    ]), get_string('economicdashboardopenaudit', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
}
echo html_writer::div(implode(' ', $buttons), 'kbacorp-toolbar mb-3');

if ($model->selectiontruncated) {
    echo $OUTPUT->notification(get_string('economicdashboardindicatorlimit', 'local_kbacorporate',
        \local_kbacorporate\local\economic_dashboard::MAX_INDICATORS), 'warning', false);
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'class' => 'kbacorp-economic-dashboard-filters mb-4',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'companyid',
    'value' => $companyid,
]);
echo html_writer::div(
    html_writer::tag('label', get_string('economicdashboardfrom', 'local_kbacorporate'), ['for' => 'id_fromdate']) .
    html_writer::empty_tag('input', [
        'type' => 'date',
        'id' => 'id_fromdate',
        'name' => 'fromdate',
        'value' => userdate((int)$model->from, '%Y-%m-%d'),
        'class' => 'form-control',
    ]),
    'kbacorp-filter-field'
);
echo html_writer::div(
    html_writer::tag('label', get_string('economicdashboardto', 'local_kbacorporate'), ['for' => 'id_todate']) .
    html_writer::empty_tag('input', [
        'type' => 'date',
        'id' => 'id_todate',
        'name' => 'todate',
        'value' => userdate((int)$model->to, '%Y-%m-%d'),
        'class' => 'form-control',
    ]),
    'kbacorp-filter-field'
);
echo html_writer::div(
    html_writer::tag('label', get_string('economicregistrycategory', 'local_kbacorporate')) .
    $select('category', $categoryoptions, $model->category),
    'kbacorp-filter-field'
);
echo html_writer::div(
    html_writer::tag('label', get_string('economicregistryperiodicity', 'local_kbacorporate')) .
    $select('periodtype', $periodoptions, $model->periodtype),
    'kbacorp-filter-field'
);
echo html_writer::div(
    html_writer::tag('label', get_string('economicdashboardauditcontext', 'local_kbacorporate')) .
    $select('auditid', $auditoptions, $model->auditid),
    'kbacorp-filter-field kbacorp-economic-dashboard-audit-filter'
);
echo html_writer::div(
    html_writer::tag('label', get_string('economicdashboardselectindicators', 'local_kbacorporate')) .
    $indicatorselect .
    html_writer::div(get_string('economicdashboardselecthint', 'local_kbacorporate'),
        'form-text text-muted', ['id' => 'economic-dashboard-indicator-hint']),
    'kbacorp-filter-field kbacorp-economic-dashboard-indicator-filter'
);
$verifiedattributes = [
    'type' => 'checkbox',
    'name' => 'verifiedonly',
    'value' => 1,
];
if ($model->verifiedonly) {
    $verifiedattributes['checked'] = 'checked';
}
echo html_writer::div(
    html_writer::tag('label',
        html_writer::empty_tag('input', $verifiedattributes) . ' ' .
        get_string('economicdashboardverifiedonly', 'local_kbacorporate')),
    'kbacorp-filter-field kbacorp-economic-dashboard-check'
);
echo html_writer::div(
    html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]) . ' ' . html_writer::link(
        new moodle_url('/local/kbacorporate/economic_dashboard.php', ['companyid' => $companyid]),
        get_string('reset'),
        ['class' => 'btn btn-secondary']
    ),
    'kbacorp-economic-dashboard-filter-actions'
);
echo html_writer::end_tag('form');

$range = userdate((int)$model->from, get_string('strftimedatefullshort')) . ' — ' .
    userdate((int)$model->to, get_string('strftimedatefullshort'));
echo html_writer::start_div('kbacorp-summary-grid');
foreach ([
    [$model->summary->indicators, get_string('economicdashboardshown', 'local_kbacorporate'), 'blue'],
    [$model->summary->actualpoints, get_string('economicdashboardactualpoints', 'local_kbacorporate'), 'green'],
    [format_float((float)$model->summary->verifiedshare, 1) . '%',
        get_string('economicdashboardverifiedshare', 'local_kbacorporate'), 'violet'],
    [format_float((float)$model->summary->coverage, 1) . '%',
        get_string('economicdashboardcoverage', 'local_kbacorporate'), 'amber'],
] as $card) {
    echo html_writer::div(
        html_writer::tag('strong', (string)$card[0]) . html_writer::span($card[1]),
        'kbacorp-summary-card kbacorp-tone-' . $card[2]
    );
}
echo html_writer::end_div();
echo html_writer::div(get_string('economicdashboardperiodrange', 'local_kbacorporate', $range),
    'kbacorp-economic-dashboard-range');

if (!$model->panels) {
    echo $OUTPUT->notification(get_string('economicdashboardnoindicators', 'local_kbacorporate'), 'info', false);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->heading(get_string('economicdashboardkpis', 'local_kbacorporate'), 2);
echo html_writer::start_div('kbacorp-economic-kpi-grid');
foreach ($model->panels as $panel) {
    $indicator = $panel->indicator;
    $latestvalue = $formatrecord($panel->latestactual, $indicator);
    $latestperiod = $panel->latestactual
        ? \local_kbacorporate\local\economic_indicators::period_label($panel->latestactual) :
        get_string('economicdashboardnodata', 'local_kbacorporate');
    $categorylabel = get_string('economiccategory' . $indicator->category, 'local_kbacorporate');
    $header = html_writer::div(
        html_writer::span(s((string)$indicator->code), 'kbacorp-economic-kpi-code') .
        html_writer::span($categorylabel, 'kbacorp-economic-kpi-category'),
        'kbacorp-economic-kpi-header'
    );
    $body = html_writer::tag('h3', format_string($indicator->name), ['class' => 'kbacorp-economic-kpi-name']);
    $body .= html_writer::div($latestvalue, 'kbacorp-economic-kpi-value');
    $body .= html_writer::div($latestperiod, 'kbacorp-economic-kpi-period');
    $body .= html_writer::div(
        html_writer::span(get_string('economicdashboardpreviouschange', 'local_kbacorporate'),
            'kbacorp-economic-kpi-detail-label') .
        $changebadge($panel->trend, $indicator, $panel->latestactual),
        'kbacorp-economic-kpi-detail'
    );
    $body .= html_writer::div(
        html_writer::span(get_string('economicdashboardplan', 'local_kbacorporate'),
            'kbacorp-economic-kpi-detail-label') .
        html_writer::span($formatrecord($panel->latestplan, $indicator), 'kbacorp-economic-kpi-detail-value'),
        'kbacorp-economic-kpi-detail'
    );
    if ($panel->latestplan) {
        $body .= html_writer::div(
            html_writer::span(get_string('economicdashboardvsplan', 'local_kbacorporate'),
                'kbacorp-economic-kpi-detail-label') .
            $changebadge($panel->planvariance, $indicator, $panel->latestactual),
            'kbacorp-economic-kpi-detail'
        );
    }
    if ($panel->latestforecast) {
        $body .= html_writer::div(
            html_writer::span(get_string('economicdashboardforecast', 'local_kbacorporate'),
                'kbacorp-economic-kpi-detail-label') .
            html_writer::span($formatrecord($panel->latestforecast, $indicator),
                'kbacorp-economic-kpi-detail-value'),
            'kbacorp-economic-kpi-detail'
        );
    }
    $footerparts = [];
    if ($panel->latestactual) {
        $footerparts[] = get_string('source' . $panel->latestactual->source, 'local_kbacorporate');
        $footerparts[] = get_string($panel->latestactual->verified
            ? 'economicverifiedyes' : 'economicverifiedno', 'local_kbacorporate');
    }
    if ($panel->lastupdated > 0) {
        $footerparts[] = get_string('economicdashboardupdated', 'local_kbacorporate',
            userdate((int)$panel->lastupdated, get_string('strftimedatetimeshort')));
    }
    $body .= html_writer::div($footerparts ? implode(' · ', $footerparts) :
        get_string('economicdashboardnodata', 'local_kbacorporate'), 'kbacorp-economic-kpi-footer');
    echo html_writer::div($header . $body,
        'kbacorp-economic-kpi-card kbacorp-economic-kpi-state-' . s((string)$panel->trend->state));
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('economicdashboardtrends', 'local_kbacorporate'), 2);
echo html_writer::start_div('kbacorp-economic-chart-grid');
foreach ($model->panels as $panel) {
    $indicator = $panel->indicator;
    echo html_writer::start_div('kbacorp-economic-chart-card');
    echo html_writer::tag('h3', s((string)$indicator->code) . ' — ' . format_string($indicator->name),
        ['class' => 'kbacorp-economic-chart-title']);
    if ($panel->mixedcurrencies) {
        echo $OUTPUT->notification(get_string('economicdashboardmixedcurrency', 'local_kbacorporate',
            implode(', ', $panel->currencies)), 'warning', false);
    } else if (count($panel->chartvalues) >= 2) {
        $chart = new \core\chart_line();
        $chart->set_labels($panel->chartlabels);
        $chart->set_title(get_string('economicdashboardactualtrend', 'local_kbacorporate'));
        $chart->set_smooth(false);
        $chart->set_legend_options(['display' => false]);
        $chart->add_series(new \core\chart_series(
            get_string('economicscenarioactual', 'local_kbacorporate'),
            $panel->chartvalues
        ));
        $yaxis = new \core\chart_axis();
        $yaxis->set_label($axislabel($panel));
        $chart->set_yaxis($yaxis);
        echo html_writer::div($OUTPUT->render($chart), 'kbacorp-economic-chart-canvas');
    } else {
        echo $OUTPUT->notification(get_string('economicdashboardnotenoughtrend', 'local_kbacorporate'),
            'info', false);
    }

    $scenariocurrencies = [];
    foreach ($panel->scenariorecords as $record) {
        if ((string)$indicator->valuetype === \local_kbacorporate\local\economic_indicators::TYPE_MONEY ||
                (string)$indicator->unit === 'currency') {
            $scenariocurrencies[(string)$record->currency] = true;
        }
    }
    if (count($panel->scenariovalues) >= 2 && count($scenariocurrencies) <= 1) {
        $labels = [];
        foreach ($panel->scenariolabels as $scenario) {
            $labels[] = get_string('economicscenario' . $scenario, 'local_kbacorporate');
        }
        $scenariochart = new \core\chart_bar();
        $scenariochart->set_labels($labels);
        $scenariochart->set_title(get_string('economicdashboardcurrentscenario', 'local_kbacorporate',
            s((string)$panel->latestperiodkey)));
        $scenariochart->set_legend_options(['display' => false]);
        $scenariochart->add_series(new \core\chart_series(
            format_string($indicator->name),
            $panel->scenariovalues
        ));
        $yaxis = new \core\chart_axis();
        $yaxis->set_label($axislabel($panel));
        $scenariochart->set_yaxis($yaxis);
        echo html_writer::div($OUTPUT->render($scenariochart), 'kbacorp-economic-chart-canvas');
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($model->auditcontext) {
    $auditcontext = $model->auditcontext;
    echo $OUTPUT->heading(get_string('economicdashboardauditsnapshot', 'local_kbacorporate'), 2);
    echo html_writer::div(
        html_writer::tag('strong', format_string($auditcontext->audit->title)) . ' · ' .
        userdate((int)$auditcontext->audit->auditdate, get_string('strftimedatefullshort')),
        'kbacorp-economic-audit-context-title'
    );
    if ($auditcontext->previousaudit) {
        echo html_writer::div(get_string('economicdashboardcomparedwith', 'local_kbacorporate',
            format_string($auditcontext->previousaudit->title)), 'kbacorp-pagelead');
    } else {
        echo $OUTPUT->notification(get_string('economicdashboardnopreviousaudit', 'local_kbacorporate'),
            'info', false);
    }

    if (!$auditcontext->currentvalues) {
        echo $OUTPUT->notification(get_string('economicdashboardnoauditvalues', 'local_kbacorporate'),
            'info', false);
    } else {
        $comparison = $auditcontext->comparison;
        echo html_writer::start_div('kbacorp-summary-grid');
        foreach ([
            [count($auditcontext->currentvalues),
                get_string('economicdashboardauditvalues', 'local_kbacorporate'), 'blue'],
            [(int)$comparison->summary->comparable,
                get_string('economicdashboardcomparable', 'local_kbacorporate'), 'violet'],
            [(int)$comparison->summary->positive,
                get_string('economicdashboardpositive', 'local_kbacorporate'), 'green'],
            [(int)$comparison->summary->negative,
                get_string('economicdashboardnegative', 'local_kbacorporate'), 'amber'],
        ] as $card) {
            echo html_writer::div(
                html_writer::tag('strong', (string)$card[0]) . html_writer::span($card[1]),
                'kbacorp-summary-card kbacorp-tone-' . $card[2]
            );
        }
        echo html_writer::end_div();

        $audittable = new html_table();
        $audittable->attributes['class'] = 'generaltable kbacorp-report';
        $audittable->head = [
            get_string('economicindicator', 'local_kbacorporate'),
            get_string('auditcomparisonbeforevalue', 'local_kbacorporate'),
            get_string('auditcomparisonaftervalue', 'local_kbacorporate'),
            get_string('economicdashboardchange', 'local_kbacorporate'),
            get_string('economicdashboardoutcome', 'local_kbacorporate'),
        ];
        foreach ($comparison->rows as $row) {
            $reference = $row->after ?: $row->before;
            $beforetext = $row->before ? \local_kbacorporate\local\economic_indicators::format_value(
                $row->before->value, $row->valuetype, $row->unit, (string)$row->before->currency) : '—';
            $aftertext = $row->after ? \local_kbacorporate\local\economic_indicators::format_value(
                $row->after->value, $row->valuetype, $row->unit, (string)$row->after->currency) : '—';
            $deltatext = '—';
            if ($row->delta !== null) {
                $deltatext = \local_kbacorporate\local\economic_indicators::format_value(
                    $row->delta,
                    $row->valuetype,
                    $row->unit,
                    $reference ? (string)$reference->currency : 'RUB'
                );
                if ((float)$row->delta > 0) {
                    $deltatext = '+' . $deltatext;
                }
                if ($row->percentdelta !== null) {
                    $deltatext .= ' (' . ((float)$row->percentdelta > 0 ? '+' : '') .
                        format_float((float)$row->percentdelta, 2) . '%)';
                }
            }
            $audittable->data[] = [
                html_writer::tag('strong', s((string)$row->indicatorcode)) .
                    html_writer::empty_tag('br') . format_string($row->indicatorname),
                $beforetext,
                $aftertext,
                $deltatext,
                html_writer::span($statehuman((string)$row->outcome),
                    'kbacorp-economic-audit-outcome kbacorp-economic-audit-outcome-' .
                    s((string)$row->outcome)),
            ];
        }
        echo html_writer::div(html_writer::table($audittable), 'kbacorp-table-scroll');

        if ($auditcontext->previousaudit &&
                (string)$auditcontext->audit->status ===
                    \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
            echo html_writer::link(new moodle_url(
                '/local/kbacorporate/implementation_audit_comparison.php', [
                    'companyid' => $companyid,
                    'beforeid' => (int)$auditcontext->previousaudit->id,
                    'afterid' => (int)$auditcontext->audit->id,
                ]), get_string('auditcomparison', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
        }
    }
}

echo $OUTPUT->footer();
