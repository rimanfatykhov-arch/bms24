<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
$companyid = optional_param('companyid', 0, PARAM_INT);
$canmethodology = has_capability('local/kbacorporate:manageimplementationaudits', $context);
$canscopedimplementation = has_capability(
    'local/kbacorporate:managecompanyimplementation', $context);
if ($companyid <= 0) {
    if (!$canmethodology && !$canscopedimplementation) {
        require_capability('local/kbacorporate:managecompanyimplementation', $context);
    }
    $companies = \local_kbacorporate\local\implementation_entry_service::manageable_companies(
        (int)$USER->id);
    if (!$companies) {
        // Authorised user with no company context: explain the next step,
        // but never grant access or create a corporate company implicitly.
        $PAGE->set_context($context);
        $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation.php'));
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title(get_string('bmsimplementation', 'local_kbacorporate'));
        $PAGE->set_heading(get_string('bmsimplementation', 'local_kbacorporate'));
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('bmsimplementation', 'local_kbacorporate'));
        echo $OUTPUT->notification(get_string('implementationnocompany', 'local_kbacorporate'), 'info');
        echo html_writer::div(
            html_writer::link(
                new moodle_url('/local/kbacompany/index.php', ['list' => 1]),
                get_string('implementationopencompanylist', 'local_kbacorporate'),
                ['class' => 'btn btn-primary']
            ),
            'mt-3'
        );
        echo $OUTPUT->footer();
        exit;
    }
    if (count($companies) === 1) {
        $onlycompany = reset($companies);
        redirect(new moodle_url('/local/kbacorporate/implementation.php', [
            'companyid' => $onlycompany->id,
        ]));
    }

    $search = trim(optional_param('q', '', PARAM_TEXT));
    $status = optional_param('status', 'all', PARAM_ALPHA);
    $validstatuses = ['all', 'noaudit', 'norequest', 'hasrequest', 'active'];
    if (!in_array($status, $validstatuses, true)) {
        $status = 'all';
    }

    $companyids = array_map('intval', array_keys($companies));
    [$companyinsql, $companyparams] = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED, 'implcompany');
    $auditstats = $DB->get_records_sql(
        "SELECT companyid, COUNT(id) AS auditcount
           FROM {local_kbacorp_impl_audit}
          WHERE companyid {$companyinsql}
       GROUP BY companyid",
        $companyparams
    );
    $programstats = $DB->get_records_sql(
        "SELECT companyid,
                COUNT(id) AS programcount,
                SUM(CASE WHEN commercialstatus = :paid AND status = :active THEN 1 ELSE 0 END) AS activecount,
                MAX(timemodified) AS lastactivity
           FROM {local_kbacorp_impl_program}
          WHERE companyid {$companyinsql}
       GROUP BY companyid",
        $companyparams + ['paid' => 'paid', 'active' => 'active']
    );
    $latestaudits = [];
    foreach ($DB->get_records_sql(
        "SELECT *
           FROM {local_kbacorp_impl_audit}
          WHERE companyid {$companyinsql}
       ORDER BY companyid ASC, auditdate DESC, id DESC",
        $companyparams
    ) as $auditrecord) {
        if (!isset($latestaudits[(int)$auditrecord->companyid])) {
            $latestaudits[(int)$auditrecord->companyid] = $auditrecord;
        }
    }
    $latestprograms = [];
    foreach ($DB->get_records_sql(
        "SELECT *
           FROM {local_kbacorp_impl_program}
          WHERE companyid {$companyinsql}
       ORDER BY companyid ASC,
                CASE WHEN commercialstatus = :currentpaid AND status = :currentactive THEN 0 ELSE 1 END,
                timecreated DESC, id DESC",
        $companyparams + ['currentpaid' => 'paid', 'currentactive' => 'active']
    ) as $programrecord) {
        if (!isset($latestprograms[(int)$programrecord->companyid])) {
            $latestprograms[(int)$programrecord->companyid] = $programrecord;
        }
    }
    $packagesbycode = [];
    foreach (\local_kbacorporate\local\implementation_packages::all(false) as $package) {
        $packagesbycode[$package->code] = $package;
    }
    $curatorids = array_values(array_unique(array_filter(array_map(static function($programrecord) {
        return (int)$programrecord->curatorid;
    }, $latestprograms))));
    $curators = $curatorids ? $DB->get_records_list('user', 'id', $curatorids) : [];

    $companystats = [];
    $statuscounts = array_fill_keys($validstatuses, 0);
    foreach ($companies as $availablecompany) {
        $availablecompanyid = (int)$availablecompany->id;
        $auditcount = isset($auditstats[$availablecompanyid]) ? (int)$auditstats[$availablecompanyid]->auditcount : 0;
        $programcount = isset($programstats[$availablecompanyid])
            ? (int)$programstats[$availablecompanyid]->programcount : 0;
        $activecount = isset($programstats[$availablecompanyid])
            ? (int)$programstats[$availablecompanyid]->activecount : 0;
        $latestaudit = $latestaudits[$availablecompanyid] ?? null;
        $latestprogram = $latestprograms[$availablecompanyid] ?? null;
        if ($auditcount === 0) {
            $companystate = 'noaudit';
        } else if ($programcount === 0) {
            $companystate = 'norequest';
        } else if ($activecount > 0) {
            $companystate = 'active';
        } else {
            $companystate = 'hasrequest';
        }
        $companystats[$availablecompanyid] = (object)[
            'auditcount' => $auditcount,
            'programcount' => $programcount,
            'activecount' => $activecount,
            'state' => $companystate,
            'latestaudit' => $latestaudit,
            'latestprogram' => $latestprogram,
            'lastactivity' => max(
                $latestaudit ? max((int)$latestaudit->auditdate, (int)$latestaudit->timemodified) : 0,
                isset($programstats[$availablecompanyid])
                    ? (int)$programstats[$availablecompanyid]->lastactivity : 0
            ),
            'packagename' => $latestprogram && isset($packagesbycode[$latestprogram->package])
                ? format_string($packagesbycode[$latestprogram->package]->name)
                : ($latestprogram ? s($latestprogram->package) : ''),
            'curatorname' => $latestprogram && !empty($curators[(int)$latestprogram->curatorid])
                ? fullname($curators[(int)$latestprogram->curatorid])
                : '',
        ];
        $statuscounts['all']++;
        $statuscounts[$companystate]++;
        if ($companystate === 'active') {
            $statuscounts['hasrequest']++;
        }
    }

    $filteredcompanies = array_filter($companies, static function($availablecompany) use (
            $search, $status, $companystats) {
        $availablecompanyid = (int)$availablecompany->id;
        if ($search !== '' && core_text::strpos(
                core_text::strtolower(format_string($availablecompany->name)),
                core_text::strtolower($search)) === false) {
            return false;
        }
        if ($status === 'hasrequest') {
            return $companystats[$availablecompanyid]->programcount > 0;
        }
        return $status === 'all' || $companystats[$availablecompanyid]->state === $status;
    });
    $sort = optional_param('sort', 'activitydesc', PARAM_ALPHANUMEXT);
    $validsorts = ['activitydesc', 'nameasc', 'namedesc', 'status'];
    if (!in_array($sort, $validsorts, true)) {
        $sort = 'activitydesc';
    }
    $stateorder = ['active' => 0, 'hasrequest' => 1, 'norequest' => 2, 'noaudit' => 3];
    usort($filteredcompanies, static function($left, $right) use ($sort, $companystats, $stateorder) {
        $leftname = core_text::strtolower(format_string($left->name));
        $rightname = core_text::strtolower(format_string($right->name));
        if ($sort === 'nameasc') {
            return $leftname <=> $rightname;
        }
        if ($sort === 'namedesc') {
            return $rightname <=> $leftname;
        }
        $leftstats = $companystats[(int)$left->id];
        $rightstats = $companystats[(int)$right->id];
        if ($sort === 'status') {
            $comparison = $stateorder[$leftstats->state] <=> $stateorder[$rightstats->state];
            return $comparison ?: ($leftname <=> $rightname);
        }
        $comparison = $rightstats->lastactivity <=> $leftstats->lastactivity;
        return $comparison ?: ($leftname <=> $rightname);
    });

    $export = optional_param('export', '', PARAM_ALPHA);
    if ($export === 'csv') {
        require_once($CFG->libdir . '/csvlib.class.php');
        $csv = new csv_export_writer();
        $csv->set_filename('bms-implementation-clients-' . userdate(time(), '%Y-%m-%d'));
        $csv->add_data([
            get_string('company', 'local_kbacorporate'),
            get_string('implementationstatusfilter', 'local_kbacorporate'),
            get_string('implementationauditcount', 'local_kbacorporate'),
            get_string('implementationprogramcount', 'local_kbacorporate'),
            get_string('latestimplementationaudit', 'local_kbacorporate'),
            get_string('currentbmsindex', 'local_kbacorporate'),
            get_string('implementationpackage', 'local_kbacorporate'),
            get_string('commercialstatus', 'local_kbacorporate'),
            get_string('programstatus', 'local_kbacorporate'),
            get_string('implementationcurator', 'local_kbacorporate'),
            get_string('implementationcontactname', 'local_kbacorporate'),
            get_string('implementationcontactemail', 'local_kbacorporate'),
            get_string('implementationcontactphone', 'local_kbacorporate'),
            get_string('lastimplementationactivity', 'local_kbacorporate'),
        ]);
        foreach ($filteredcompanies as $exportcompany) {
            $stats = $companystats[(int)$exportcompany->id];
            $csv->add_data([
                format_string($exportcompany->name),
                get_string('implementationcompanystate' . $stats->state, 'local_kbacorporate'),
                $stats->auditcount,
                $stats->programcount,
                $stats->latestaudit && (int)$stats->latestaudit->auditdate > 0
                    ? userdate((int)$stats->latestaudit->auditdate,
                    get_string('strftimedatefullshort')) : '',
                $stats->latestaudit && $stats->latestaudit->indexvalue !== null
                    ? format_float($stats->latestaudit->indexvalue, 1) : '',
                $stats->packagename,
                $stats->latestprogram ? get_string('commercialstatus' . $stats->latestprogram->commercialstatus,
                    'local_kbacorporate') : '',
                $stats->latestprogram ? get_string('programstatus' . $stats->latestprogram->status,
                    'local_kbacorporate') : '',
                $stats->curatorname ?: get_string('notassigned', 'local_kbacorporate'),
                $stats->latestprogram ? (string)$stats->latestprogram->contactname : '',
                $stats->latestprogram ? (string)$stats->latestprogram->contactemail : '',
                $stats->latestprogram ? (string)$stats->latestprogram->contactphone : '',
                $stats->lastactivity ? userdate($stats->lastactivity, get_string('strftimedatefullshort')) : '',
            ]);
        }
        $csv->download_file();
        exit;
    }

    $perpage = optional_param('perpage', 12, PARAM_INT);
    if (!in_array($perpage, [12, 24, 48], true)) {
        $perpage = 12;
    }
    $totalfiltered = count($filteredcompanies);
    $page = max(0, optional_param('page', 0, PARAM_INT));
    $lastpage = max(0, (int)ceil($totalfiltered / $perpage) - 1);
    $page = min($page, $lastpage);
    $displaycompanies = array_slice($filteredcompanies, $page * $perpage, $perpage);

    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation.php'));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('bmsimplementation', 'local_kbacorporate'));
    $PAGE->set_heading(get_string('bmsimplementation', 'local_kbacorporate'));
    echo $OUTPUT->header();
    echo local_kbacorporate_implementation_navigation(0, 'clients', 1);
    echo html_writer::start_div('kbacorp-hero kbacorp-implementation-hero');
    echo html_writer::div(get_string('bmsmanagementsystem', 'local_kbacorporate'), 'kbacorp-eyebrow');
    echo $OUTPUT->heading(get_string('selectimplementationcompany', 'local_kbacorporate'), 2,
        'kbacorp-company-heading');
    echo html_writer::div(get_string('selectimplementationcompanydesc', 'local_kbacorporate'),
        'kbacorp-hero-text');
    echo html_writer::end_div();

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/local/kbacorporate/implementation.php'))->out(false),
        'class' => 'kbacorp-filters kbacorp-company-picker-filters',
        'id' => 'kbacorp-implementation-company-form',
        'role' => 'search',
    ]);
    echo html_writer::start_div('kbacorp-map-search');
    echo html_writer::tag('label', get_string('searchimplementationcompanies', 'local_kbacorporate'), [
        'for' => 'kbacorp-implementation-company-search',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'kbacorp-implementation-company-search',
        'name' => 'q',
        'value' => $search,
        'placeholder' => get_string('searchimplementationcompaniesplaceholder', 'local_kbacorporate'),
        'autocomplete' => 'off',
        'list' => 'kbacorp-implementation-company-options',
    ]);
    echo html_writer::start_tag('datalist', ['id' => 'kbacorp-implementation-company-options']);
    foreach ($companies as $suggestedcompany) {
        $suggestedstats = $companystats[(int)$suggestedcompany->id];
        echo html_writer::empty_tag('option', [
            'value' => format_string($suggestedcompany->name),
            'label' => get_string('implementationcompanystate' . $suggestedstats->state,
                'local_kbacorporate'),
        ]);
    }
    echo html_writer::end_tag('datalist');
    echo html_writer::end_div();
    echo html_writer::start_div('kbacorp-company-status-filter');
    echo html_writer::tag('label', get_string('implementationstatusfilter', 'local_kbacorporate'), [
        'for' => 'kbacorp-implementation-status',
    ]);
    $statusoptions = [];
    foreach ($validstatuses as $statuskey) {
        $statusoptions[$statuskey] = get_string('implementationfilter' . $statuskey, 'local_kbacorporate',
            $statuscounts[$statuskey]);
    }
    echo html_writer::select($statusoptions, 'status', $status, false, [
        'id' => 'kbacorp-implementation-status',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('kbacorp-company-status-filter');
    echo html_writer::tag('label', get_string('sortimplementationcompanies', 'local_kbacorporate'), [
        'for' => 'kbacorp-implementation-sort',
    ]);
    echo html_writer::select([
        'activitydesc' => get_string('sortbylastactivity', 'local_kbacorporate'),
        'nameasc' => get_string('sortbynameasc', 'local_kbacorporate'),
        'namedesc' => get_string('sortbynamedesc', 'local_kbacorporate'),
        'status' => get_string('sortbyimplementationstatus', 'local_kbacorporate'),
    ], 'sort', $sort, false, [
        'id' => 'kbacorp-implementation-sort',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('kbacorp-company-status-filter kbacorp-perpage-filter');
    echo html_writer::tag('label', get_string('companiesperpage', 'local_kbacorporate'), [
        'for' => 'kbacorp-implementation-perpage',
    ]);
    echo html_writer::select([12 => '12', 24 => '24', 48 => '48'], 'perpage', $perpage, false, [
        'id' => 'kbacorp-implementation-perpage',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
    if ($search !== '' || $status !== 'all' || $sort !== 'activitydesc' || $perpage !== 12) {
        echo html_writer::link(new moodle_url('/local/kbacorporate/implementation.php'),
            get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::end_tag('form');
    echo html_writer::div(get_string('implementationcompaniesfound', 'local_kbacorporate', (object)[
        'shown' => $totalfiltered,
        'total' => count($companies),
    ]), 'kbacorp-company-picker-result');

    $queryparams = ['q' => $search, 'status' => $status, 'sort' => $sort, 'perpage' => $perpage];
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
        $queryparams + ['export' => 'csv']), get_string('exportimplementationclients', 'local_kbacorporate'), [
            'class' => 'btn btn-secondary',
        ]), 'kbacorp-company-picker-export');

    if (!$filteredcompanies) {
        echo $OUTPUT->notification(get_string('noimplementationcompaniesfound', 'local_kbacorporate'), 'info');
    }
    echo html_writer::start_div('kbacorp-navgrid');
    foreach ($displaycompanies as $availablecompany) {
        $stats = $companystats[(int)$availablecompany->id];
        $url = new moodle_url('/local/kbacorporate/implementation.php', [
            'companyid' => $availablecompany->id,
        ]);
        $inside = html_writer::start_div('kbacorp-navcard-inner');
        $inside .= html_writer::div(format_string($availablecompany->name), 'kbacorp-navcard-title');
        $inside .= html_writer::div(get_string('implementationcompanystate' . $stats->state,
            'local_kbacorporate'), 'kbacorp-company-status is-' . $stats->state);
        $inside .= html_writer::div(get_string('implementationcompanymetrics', 'local_kbacorporate', (object)[
            'audits' => $stats->auditcount,
            'programs' => $stats->programcount,
        ]), 'kbacorp-company-metrics');
        if ($stats->latestaudit) {
            $inside .= html_writer::div(get_string('implementationlastauditvalue', 'local_kbacorporate', (object)[
                'date' => (int)$stats->latestaudit->auditdate > 0
                    ? userdate((int)$stats->latestaudit->auditdate, get_string('strftimedateshort')) : '—',
                'index' => $stats->latestaudit->indexvalue === null
                    ? '—' : format_float($stats->latestaudit->indexvalue, 1),
            ]), 'kbacorp-company-detail');
        }
        if ($stats->latestprogram) {
            $inside .= html_writer::div(get_string('implementationpackagevalue', 'local_kbacorporate',
                $stats->packagename), 'kbacorp-company-detail');
            $inside .= html_writer::div(get_string('implementationstagevalue', 'local_kbacorporate',
                get_string('commercialstatus' . $stats->latestprogram->commercialstatus,
                    'local_kbacorporate')), 'kbacorp-company-detail');
        }
        $inside .= html_writer::div(get_string('implementationcuratorvalue', 'local_kbacorporate',
            $stats->curatorname ?: get_string('notassigned', 'local_kbacorporate')), 'kbacorp-company-detail');
        $inside .= html_writer::div(get_string('lastimplementationactivityvalue', 'local_kbacorporate',
            $stats->lastactivity ? userdate($stats->lastactivity, get_string('strftimedateshort')) : '—'),
            'kbacorp-company-detail');
        $inside .= html_writer::div(get_string('bmsimplementationnavdesc', 'local_kbacorporate'),
            'kbacorp-navcard-text');
        $inside .= html_writer::end_div();
        $actions = html_writer::link($url, get_string('openimplementationworkspace', 'local_kbacorporate'), [
            'class' => 'btn btn-primary btn-sm',
        ]);
        if (!$stats->latestaudit) {
            $actions .= html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit_edit.php', [
                'companyid' => $availablecompany->id,
            ]), get_string('createimplementationaudit', 'local_kbacorporate'), [
                'class' => 'btn btn-outline-secondary btn-sm',
            ]);
        } else if (!$stats->latestprogram) {
            $offerurl = new moodle_url('/local/kbacorporate/implementation.php', [
                'companyid' => $availablecompany->id,
            ]);
            $offerurl->set_anchor('implementation-packages');
            $actions .= html_writer::link($offerurl, get_string('offertariff', 'local_kbacorporate'), [
                'class' => 'btn btn-outline-secondary btn-sm',
            ]);
        } else {
            if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
                $programurl = new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
                    'companyid' => $availablecompany->id,
                    'auditid' => $stats->latestprogram->baselineauditid,
                ]);
            } else if (\local_kbacorporate\local\implementation_programs::plan_visible($stats->latestprogram)) {
                $programurl = new moodle_url('/local/kbacorporate/implementation_plan.php', [
                    'companyid' => $availablecompany->id,
                    'auditid' => $stats->latestprogram->baselineauditid,
                ]);
            } else {
                $programurl = new moodle_url('/local/kbacorporate/implementation.php', [
                    'companyid' => $availablecompany->id,
                ]);
                $programurl->set_anchor('implementation-request');
            }
            $actions .= html_writer::link($programurl, get_string('openimplementationprogram',
                'local_kbacorporate'), ['class' => 'btn btn-outline-secondary btn-sm']);
        }
        $companysearchtext = core_text::strtolower(format_string($availablecompany->name) . ' ' .
            get_string('implementationcompanystate' . $stats->state, 'local_kbacorporate') . ' ' .
            $stats->curatorname . ' ' . $stats->packagename);
        echo html_writer::div($inside . html_writer::div($actions, 'kbacorp-client-actions'),
            'kbacorp-navcard kbacorp-navcard-green kbacorp-client-card', [
                'data-search' => $companysearchtext,
            ]);
    }
    echo html_writer::end_div();
    echo html_writer::div(get_string('implementationlivesearchempty', 'local_kbacorporate'),
        'alert alert-info kbacorp-implementation-live-empty', [
            'id' => 'kbacorp-implementation-live-empty', 'hidden' => 'hidden',
        ]);
    if ($totalfiltered > $perpage) {
        echo $OUTPUT->paging_bar($totalfiltered, $page, $perpage,
            new moodle_url('/local/kbacorporate/implementation.php', $queryparams));
    }
    $PAGE->requires->js_init_code(<<<'JS'
(function() {
    var input = document.getElementById('kbacorp-implementation-company-search');
    var form = document.getElementById('kbacorp-implementation-company-form');
    var options = Array.prototype.slice.call(
        document.querySelectorAll('#kbacorp-implementation-company-options option'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('.kbacorp-client-card'));
    var empty = document.getElementById('kbacorp-implementation-live-empty');
    if (!input || !form) { return; }
    var exactValues = options.map(function(option) { return option.value.toLocaleLowerCase(); });
    input.addEventListener('input', function() {
        var needle = input.value.trim().toLocaleLowerCase();
        var visible = 0;
        cards.forEach(function(card) {
            var match = !needle || (card.getAttribute('data-search') || '').indexOf(needle) !== -1;
            card.hidden = !match;
            if (match) { visible++; }
        });
        if (empty) { empty.hidden = visible !== 0; }
        if (needle && exactValues.indexOf(needle) !== -1) {
            form.submit();
        }
    });
}());
JS
    );
    echo $OUTPUT->footer();
    exit;
}
$company = \local_kbacorporate\local\access::require_company_implementation_access(
    $companyid, (int)$USER->id);
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'request') {
    require_sesskey();
    $auditid = required_param('auditid', PARAM_INT);
    $packagecode = required_param('package', PARAM_ALPHANUMEXT);
    $audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
    if ((int)$audit->companyid !== $companyid) {
        throw new moodle_exception('companynotallowed', 'local_kbacorporate');
    }
    \local_kbacorporate\local\implementation_programs::request(
        $companyid,
        $auditid,
        (int)$USER->id,
        $packagecode
    );
    redirect(
        new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]),
        get_string('implementationprogramrequested', 'local_kbacorporate')
    );
}
$audits = \local_kbacorporate\local\implementation_audits::get_audits($companyid);
$programs = \local_kbacorporate\local\implementation_programs::get_company_programs($companyid);
$activeprograms = array_filter($programs, static function($program) {
    return $program->commercialstatus === 'paid' && $program->status === 'active';
});
$visibleprograms = array_filter($programs, static function($program) {
    return \local_kbacorporate\local\implementation_programs::plan_visible($program);
});
$latestindex = '—';
foreach ($audits as $audit) {
    if ($audit->indexvalue !== null) { $latestindex = format_float($audit->indexvalue, 1); break; }
}
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]));
$PAGE->set_pagelayout('standard'); $PAGE->set_title(get_string('bmsimplementation', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'clients');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::start_div('kbacorp-hero kbacorp-implementation-hero');
echo html_writer::div(get_string('bmsmanagementsystem', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('bmsimplementation', 'local_kbacorporate'), 2, 'kbacorp-company-heading');
echo html_writer::div(get_string('bmsimplementationdesc', 'local_kbacorporate'), 'kbacorp-hero-text');
echo html_writer::end_div();
echo html_writer::start_div('kbacorp-summary-grid');
echo html_writer::div(html_writer::tag('strong', count($audits)) . html_writer::span(get_string('implementationauditcount', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-green');
echo html_writer::div(html_writer::tag('strong', $latestindex) . html_writer::span(get_string('currentbmsindex', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-blue');
echo html_writer::div(html_writer::tag('strong', count($programs)) . html_writer::span(get_string('implementationprogramcount', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-violet');
echo html_writer::div(html_writer::tag('strong', count($activeprograms)) . html_writer::span(get_string('activeimplementationprograms', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-amber');
echo html_writer::end_div();

$baselineaudit = $audits ? reset($audits) : null;
$baselineprogram = $baselineaudit
    ? \local_kbacorporate\local\implementation_programs::get_for_audit((int)$baselineaudit->id)
    : null;
$packageurl = new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]);
$packageurl->set_anchor('implementation-packages');
$requesturl = new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]);
$requesturl->set_anchor('implementation-request');
$deliveryprogram = $visibleprograms ? reset($visibleprograms) : null;

echo $OUTPUT->heading(get_string('implementationworkspaceprocess', 'local_kbacorporate'), 3);
echo html_writer::tag('p', get_string('implementationworkspaceprocessdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
$workspacesteps = [
    [get_string('implementationstagegaps', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid])],
    [get_string('implementationstageplan', 'local_kbacorporate'), $deliveryprogram
        ? new moodle_url('/local/kbacorporate/implementation_plan.php', ['companyid' => $companyid,
            'auditid' => $deliveryprogram->baselineauditid]) : null],
    [get_string('implementationstageactions', 'local_kbacorporate'), $deliveryprogram
        ? new moodle_url('/local/kbacorporate/implementation_tasks.php', ['companyid' => $companyid,
            'auditid' => $deliveryprogram->baselineauditid]) : null],
    [get_string('implementationstagetraining', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/development_plans.php', ['companyid' => $companyid])],
    [get_string('implementationstageevidence', 'local_kbacorporate'), $deliveryprogram
        ? new moodle_url('/local/kbacorporate/implementation_tasks.php', ['companyid' => $companyid,
            'auditid' => $deliveryprogram->baselineauditid]) : null],
    [get_string('implementationstageresults', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', ['companyid' => $companyid])],
];
echo html_writer::start_div('kbacorp-workspace-stages');
foreach ($workspacesteps as $index => [$label, $url]) {
    $content = html_writer::span($index + 1, 'kbacorp-workspace-stage-number') .
        html_writer::span($label, 'kbacorp-workspace-stage-label');
    echo $url ? html_writer::link($url, $content, ['class' => 'kbacorp-workspace-stage'])
        : html_writer::div($content, 'kbacorp-workspace-stage is-disabled');
}
echo html_writer::end_div();

echo html_writer::start_tag('details', ['class' => 'kbacorp-contract-program',
    'id' => 'implementation-packages'] + (!$baselineprogram ? ['open' => 'open'] : []));
echo html_writer::tag('summary', get_string('implementationcontractprogram', 'local_kbacorporate'));
echo html_writer::tag('p', get_string('implementationcontractprogramdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
echo html_writer::start_div('kbacorp-commercial-stage');
echo $OUTPUT->heading(get_string('implementationcommercialstage', 'local_kbacorporate'), 3);
echo html_writer::tag('p', get_string('implementationcommercialstagedesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
if (!$baselineaudit) {
    echo $OUTPUT->notification(get_string('implementationpackageauditrequired', 'local_kbacorporate'), 'info');
    echo $OUTPUT->single_button(
        new moodle_url('/local/kbacorporate/implementation_audit_edit.php', ['companyid' => $companyid]),
        get_string('createimplementationaudit', 'local_kbacorporate'),
        'get'
    );
} else if ($baselineprogram) {
    echo $OUTPUT->notification(get_string('currentimplementationpackage', 'local_kbacorporate',
        \local_kbacorporate\local\implementation_programs::label($baselineprogram)), 'success');
    $requestdetails = '';
    foreach ([
        'implementationcontactname' => $baselineprogram->contactname,
        'implementationcontactemail' => $baselineprogram->contactemail,
        'implementationcontactphone' => $baselineprogram->contactphone,
    ] as $labelkey => $value) {
        if ((string)$value !== '') {
            $requestdetails .= html_writer::div(
                html_writer::tag('strong', get_string($labelkey, 'local_kbacorporate') . ': ') . s($value),
                'kbacorp-request-detail'
            );
        }
    }
    if ((string)$baselineprogram->customercomment !== '') {
        $requestdetails .= html_writer::div(
            html_writer::tag('strong', get_string('implementationcustomercomment', 'local_kbacorporate') . ': ') .
                format_text($baselineprogram->customercomment, FORMAT_PLAIN),
            'kbacorp-request-detail kbacorp-request-comment'
        );
    }
    if ((string)$baselineprogram->customerresponse !== '') {
        $requestdetails .= html_writer::div(
            html_writer::tag('strong', get_string('implementationcustomerresponse', 'local_kbacorporate') . ': ') .
                format_text($baselineprogram->customerresponse, FORMAT_PLAIN),
            'kbacorp-request-detail kbacorp-request-response'
        );
    }
    if (!empty($baselineprogram->bitrixdealid)) {
        $requestdetails .= html_writer::div(
            html_writer::tag('strong', get_string('bitrixdealid', 'local_kbacorporate') . ': ') .
                s($baselineprogram->bitrixdealid),
            'kbacorp-request-detail'
        );
    }
    $requestdetails .= html_writer::div(
        html_writer::tag('strong', get_string('implementationrequestdate', 'local_kbacorporate') . ': ') .
            userdate((int)$baselineprogram->timecreated, get_string('strftimedatefullshort')),
        'kbacorp-request-detail'
    );
    if ((int)$baselineprogram->statuschangedat > 0) {
        $requestdetails .= html_writer::div(
            html_writer::tag('strong', get_string('implementationstatusupdated', 'local_kbacorporate') . ': ') .
                userdate((int)$baselineprogram->statuschangedat, get_string('strftimedatefullshort')),
            'kbacorp-request-detail'
        );
    }
    $requestcontent =
        $OUTPUT->heading(get_string('implementationrequestcard', 'local_kbacorporate'), 4) .
        html_writer::div(\local_kbacorporate\local\implementation_programs::label($baselineprogram),
            'kbacorp-request-package-name') .
        ($requestdetails ?: html_writer::div(get_string('implementationrequestnocontact', 'local_kbacorporate'),
            'kbacorp-request-detail'));
    if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
        $requestcontent .= html_writer::div(html_writer::link(
            new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
                'companyid' => $companyid,
                'auditid' => $baselineaudit->id,
            ]),
            get_string('openimplementationrequest', 'local_kbacorporate'),
            ['class' => 'btn btn-outline-primary']
        ), 'kbacorp-request-actions');
    }
    echo html_writer::div($requestcontent, 'kbacorp-request-card', ['id' => 'implementation-request']);
} else {
    echo html_writer::tag('p', get_string('selectimplementationpackagenowdesc', 'local_kbacorporate',
        format_string($baselineaudit->title)), ['class' => 'kbacorp-pagelead']);
    $packages = \local_kbacorporate\local\implementation_packages::all(true);
    if (!$packages) {
        echo $OUTPUT->notification(get_string('noactiveimplementationpackages', 'local_kbacorporate'), 'info');
    } else {
        echo html_writer::start_div('kbacorp-package-grid');
        foreach ($packages as $package) {
            $content = html_writer::div(format_string($package->name), 'kbacorp-package-title');
            $content .= html_writer::div($package->price === null
                ? get_string('priceonrequest', 'local_kbacorporate')
                : format_float($package->price, 2) . ' ' . s($package->currency), 'kbacorp-package-price');
            if ($package->durationdays) {
                $content .= html_writer::div(get_string('packagedurationvalue', 'local_kbacorporate',
                    (int)$package->durationdays), 'kbacorp-package-duration');
            }
            $content .= html_writer::div(format_text($package->description, FORMAT_PLAIN),
                'kbacorp-package-description');
            if ($package->features) {
                $content .= html_writer::div(nl2br(s($package->features)), 'kbacorp-package-features');
            }
            $content .= html_writer::link(new moodle_url('/local/kbacorporate/implementation_request.php', [
                'companyid' => $companyid,
                'auditid' => $baselineaudit->id,
                'package' => $package->code,
            ]), get_string('selectpackage', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
            echo html_writer::div($content, 'kbacorp-package-card');
        }
        echo html_writer::end_div();
    }
}
echo html_writer::end_div();
echo html_writer::end_tag('details');

echo $OUTPUT->heading(get_string('implementationcycle', 'local_kbacorporate'), 3);
$commercialurl = $baselineaudit ? $packageurl : null;
if ($baselineprogram) {
    if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
        $commercialurl = new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
            'companyid' => $companyid,
            'auditid' => $baselineaudit->id,
        ]);
    } else {
        $commercialurl = $requesturl;
    }
}
$steps = [
    ['1', get_string('implementationstepaudit', 'local_kbacorporate'), get_string('implementationstepauditdesc', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid]), 'green'],
    ['2', get_string('implementationstepprogram', 'local_kbacorporate'), get_string('implementationstepprogramdesc', 'local_kbacorporate'),
        $commercialurl, 'blue'],
    ['3', get_string('implementationstepdelivery', 'local_kbacorporate'), get_string('implementationstepdeliverydesc', 'local_kbacorporate'),
        $deliveryprogram ? new moodle_url('/local/kbacorporate/implementation_plan.php', ['companyid' => $companyid,
            'auditid' => $deliveryprogram->baselineauditid]) : null, 'violet'],
    ['4', get_string('implementationstepresult', 'local_kbacorporate'), get_string('implementationstepresultdesc', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid]), 'orange'],
];
echo html_writer::start_div('kbacorp-navgrid kbacorp-implementation-cycle');
foreach ($steps as [$number, $title, $text, $url, $tone]) {
    $inside = html_writer::start_div('kbacorp-navcard-inner');
    $inside .= html_writer::div($number, 'kbacorp-navcard-value');
    $inside .= html_writer::div($title, 'kbacorp-navcard-title');
    $inside .= html_writer::div($text, 'kbacorp-navcard-text');
    $inside .= html_writer::div($url ? get_string('open', 'local_kbacorporate') . ' →' : get_string('notavailableyet', 'local_kbacorporate'), 'kbacorp-navcard-open');
    $inside .= html_writer::end_div();
    echo $url ? html_writer::link($url, $inside, ['class' => 'kbacorp-navcard kbacorp-navcard-' . $tone])
        : html_writer::div($inside, 'kbacorp-navcard kbacorp-navcard-' . $tone . ' is-disabled');
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('companyimplementationprograms', 'local_kbacorporate'), 3);
if (!$audits) {
    echo $OUTPUT->notification(get_string('implementationstartwithaudit', 'local_kbacorporate'), 'info');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_audit_edit.php', ['companyid' => $companyid]),
        get_string('createimplementationaudit', 'local_kbacorporate'), 'get');
} else {
    echo html_writer::start_div('kbacorp-program-grid');
    foreach ($audits as $audit) {
        $program = \local_kbacorporate\local\implementation_programs::get_for_audit((int)$audit->id);
        $content = html_writer::div(format_string($audit->title), 'kbacorp-program-title');
        $content .= html_writer::div(format_string($audit->toolname) . ' · ' . userdate($audit->auditdate,
            get_string('strftimedatefullshort')), 'kbacorp-program-meta');
        $content .= html_writer::div(get_string('bmsindexvalue', 'local_kbacorporate',
            $audit->indexvalue === null ? '—' : format_float($audit->indexvalue, 1)), 'kbacorp-program-index');
        if ($program) {
            $content .= html_writer::div(\local_kbacorporate\local\implementation_programs::label($program),
                'kbacorp-program-status');
            if (\local_kbacorporate\local\implementation_programs::plan_visible($program)) {
                $actionurl = new moodle_url('/local/kbacorporate/implementation_tasks.php', [
                    'companyid' => $companyid,
                    'auditid' => $audit->id,
                ]);
                $action = get_string('openimplementationtasks', 'local_kbacorporate');
            } else if (has_capability('local/kbacorporate:manageimplementationprograms', $context)) {
                $actionurl = new moodle_url('/local/kbacorporate/implementation_program_edit.php', [
                    'companyid' => $companyid,
                    'auditid' => $audit->id,
                ]);
                $action = get_string('openimplementationrequest', 'local_kbacorporate');
            } else {
                $actionurl = $requesturl;
                $action = get_string('openprogramrequest', 'local_kbacorporate');
            }
        } else {
            $content .= html_writer::div(get_string('programnotrequested', 'local_kbacorporate'), 'kbacorp-program-status is-muted');
            $action = get_string('selectpackageandrequest', 'local_kbacorporate');
            $actionurl = $packageurl;
        }
        $content .= html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php',
            ['companyid' => $companyid, 'id' => $audit->id]), get_string('viewauditresult', 'local_kbacorporate')) . ' · ' .
            html_writer::link($actionurl, $action), 'kbacorp-program-actions');
        echo html_writer::div($content, 'kbacorp-program-card');
    }
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
