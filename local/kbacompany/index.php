<?php

require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/index.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycompanies', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$status = company_service::status();
// The canonical BMS registry is sufficient on a fresh SaaS installation.
// Corporate Dashboard is an optional legacy compatibility source.
$hascompanysource = !empty($status['canonical_registry']) || !empty($status['corporate_adapter']);
$companies = company_service::companies_for_user((int)$USER->id);
$showlist = optional_param('list', 0, PARAM_BOOL);
if (!$showlist && $hascompanysource && count($companies) === 1) {
    redirect(new moodle_url('/local/kbacompany/bms_home.php', [
        'uuid' => $companies[0]['uuid'],
    ]));
}
$rolelabels = [
    'owner' => get_string('roleowner', 'local_kbacompany'),
    'employee' => get_string('roleemployee', 'local_kbacompany'),
    'manager' => get_string('rolemanager', 'local_kbacompany'),
    'hr' => get_string('rolehr', 'local_kbacompany'),
    'department_manager' => get_string('roledepartmentmanager', 'local_kbacompany'),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mycompanies', 'local_kbacompany'));
echo html_writer::tag('p', get_string('foundationintro', 'local_kbacompany'), ['class' => 'lead']);
echo $OUTPUT->notification(get_string('readonlyfoundation', 'local_kbacompany'), 'info');

if (!$hascompanysource) {
    echo $OUTPUT->notification(get_string('adapterunavailable', 'local_kbacompany'), 'warning');
} else if (!$companies) {
    echo $OUTPUT->notification(get_string('nocompanies', 'local_kbacompany'), 'info');
} else {
    echo html_writer::start_tag('form', [
        'class' => 'kbacompany-company-search',
        'id' => 'kbacompany-company-search-form',
        'role' => 'search',
    ]);
    echo html_writer::start_div('kbacompany-company-search-field');
    echo html_writer::tag('label', get_string('companysearch', 'local_kbacompany'), [
        'for' => 'kbacompany-company-search-input',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'kbacompany-company-search-input',
        'placeholder' => get_string('companysearchplaceholder', 'local_kbacompany'),
        'autocomplete' => 'off',
        'list' => 'kbacompany-company-search-options',
        'aria-describedby' => 'kbacompany-company-search-help',
    ]);
    echo html_writer::start_tag('datalist', ['id' => 'kbacompany-company-search-options']);
    foreach ($companies as $company) {
        echo html_writer::empty_tag('option', [
            'value' => format_string($company['name']),
            'data-url' => (new moodle_url('/local/kbacompany/bms_home.php', [
                'uuid' => $company['uuid'],
            ]))->out(false),
        ]);
    }
    echo html_writer::end_tag('datalist');
    echo html_writer::div(get_string('companysearchhelp', 'local_kbacompany'), 'form-text text-muted', [
        'id' => 'kbacompany-company-search-help',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('companysearchopen', 'local_kbacompany'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::div(get_string('companysearchcount', 'local_kbacompany', (object)[
        'visible' => count($companies), 'total' => count($companies),
    ]), 'kbacompany-company-search-count', [
        'id' => 'kbacompany-company-search-count',
        'data-template' => get_string('companysearchcount', 'local_kbacompany', (object)[
            'visible' => '__VISIBLE__', 'total' => '__TOTAL__',
        ]),
    ]);
    echo html_writer::start_div('row', ['id' => 'kbacompany-company-list']);
    foreach ($companies as $company) {
        $roles = [];
        foreach ($company['roles'] as $role) {
            $roles[] = $rolelabels[$role] ?? s($role);
        }
        if (!$roles) {
            $roles[] = get_string('rolenone', 'local_kbacompany');
        }
        $url = new moodle_url('/local/kbacompany/bms_home.php', ['uuid' => $company['uuid']]);
        $body = html_writer::tag('h3', format_string($company['name']), ['class' => 'h5']) .
            html_writer::tag('p', s(implode(', ', $roles)), ['class' => 'text-muted']) .
            html_writer::link($url, get_string('opencompany', 'local_kbacompany'), ['class' => 'btn btn-primary']);
        $searchtext = core_text::strtolower(format_string($company['name']) . ' ' .
            (string)$company['code'] . ' ' . implode(' ', $roles));
        echo html_writer::div(html_writer::div($body, 'card-body'),
            'card mb-3 col-md-6 kbacompany-company-card', ['data-search' => $searchtext]);
    }
    echo html_writer::end_div();
    echo html_writer::div(get_string('companysearchempty', 'local_kbacompany'),
        'alert alert-info', ['id' => 'kbacompany-company-search-empty', 'hidden' => 'hidden']);
    $PAGE->requires->strings_for_js(['companysearchnotfound'], 'local_kbacompany');
    $PAGE->requires->js_init_code(<<<'JS'
(function() {
    var form = document.getElementById('kbacompany-company-search-form');
    var input = document.getElementById('kbacompany-company-search-input');
    var options = Array.prototype.slice.call(
        document.querySelectorAll('#kbacompany-company-search-options option'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('.kbacompany-company-card'));
    var empty = document.getElementById('kbacompany-company-search-empty');
    var count = document.getElementById('kbacompany-company-search-count');
    if (!form || !input) { return; }

    var normalise = function(value) { return value.trim().toLocaleLowerCase(); };
    var findExact = function(value) {
        var needle = normalise(value);
        return options.find(function(option) { return normalise(option.value) === needle; });
    };
    var openCompany = function(option) {
        var url = option && option.getAttribute('data-url');
        if (url) { window.location.assign(url); }
    };
    var updateCount = function(visible) {
        if (!count) { return; }
        var template = count.getAttribute('data-template') || '';
        count.textContent = template.replace('__VISIBLE__', visible).replace('__TOTAL__', cards.length);
    };

    input.addEventListener('input', function() {
        var needle = normalise(input.value);
        var visible = 0;
        input.setCustomValidity('');
        cards.forEach(function(card) {
            var match = !needle || (card.getAttribute('data-search') || '').indexOf(needle) !== -1;
            card.hidden = !match;
            if (match) { visible++; }
        });
        if (empty) { empty.hidden = visible !== 0; }
        updateCount(visible);
        var option = findExact(input.value);
        if (option) { openCompany(option); }
    });
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        var option = findExact(input.value);
        if (!option) {
            input.setCustomValidity(M.util.get_string('companysearchnotfound', 'local_kbacompany'));
            input.reportValidity();
            return;
        }
        openCompany(option);
    });
}());
JS
    );
}

echo $OUTPUT->footer();
