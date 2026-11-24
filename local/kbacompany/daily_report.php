<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\business_date_service;
use local_kbacompany\local\daily_report_service;
use local_kbacompany\local\daily_report_feed_service as feed;
use local_kbacompany\local\daily_report_store_service as store;
use local_kbacompany\local\daily_report_ui_service as reportui;
use local_kbacompany\local\daily_report_review_service as review;
use local_kbacompany\local\daily_report_reviewer_service as reviewers;
use local_kbacompany\local\daily_report_team_service as team;
use local_kbacompany\local\daily_report_discussion_service as discussion;
use local_kbacompany\local\daily_report_discussion_ui_service as discussionui;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms24_shell_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$day = optional_param('day', business_date_service::day(), PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
// ADR-0009 R1: saving reports is behind a server-side switch; while it is off the page is the R0 read-only summary.
$enabled = store::enabled();
$tab = $enabled ? optional_param('tab', 'my', PARAM_ALPHA) : 'my';
// R2/ADR-0010: the reviewer tab exists for administrators, managers, stored reviewers and copy recipients.
$canreview = $enabled && review::has_review_access($uuid, $actor);
if (!in_array($tab, ['my', 'history', 'review'], true) || ($tab === 'review' && !$canreview)) {
    $tab = 'my';
}
$data = daily_report_service::context($uuid, $actor, $day,
    optional_param('taskpage', 0, PARAM_INT), optional_param('resultpage', 0, PARAM_INT));
$base = ['uuid' => $uuid, 'day' => $day];

// ---- R3 report discussion: one text message by the author or the current reviewer (re-checked by the service).
if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHA) === 'discuss') {
    require_sesskey();
    $discussreport = required_param('discussreport', PARAM_ALPHANUMEXT);
    $discussback = reportui::url($uuid, []);
    try {
        $discussback = discussion::url($uuid, discussion::target($uuid, $actor, $discussreport));
        discussion::post($uuid, $actor, $discussreport, optional_param('message', '', PARAM_RAW),
            optional_param('replytouuid', '', PARAM_ALPHANUMEXT));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') {
            throw $e;
        }
        // Unknown, foreign and unreadable reports fail the same way; nothing is stored on failure.
        redirect($discussback, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($discussback);
}

// ---- writes: POST + sesskey only; the actor is the session user, never a request parameter.
$posted = null; $error = ''; $conflict = false;
$knownerrors = ['dailyreports_conflict', 'dailyreports_donerequired', 'dailyreports_notdraft', 'dailyreports_futureday',
    'dailyreports_taskunavailable', 'dailyreports_toomanylinks', 'dailyreports_texttoolong', 'dailyreports_busy',
    'dailyreports_notrevisable', 'dailyreports_correctionrequired', 'dailyreports_ratingrequired',
    'dailyreports_commentrequired', 'dailyreports_reasonrequired', 'dailyreports_notreviewer', 'dailyreports_staleversion',
    'dailyreports_notpending', 'dailyreports_reviewerinvalid', 'dailyreports_ccinvalid', 'dailyreports_toomanycc',
    'dailyreports_settingsinvalid'];
if ($enabled && $tab === 'my' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    // Day feed -> report draft: "Add to report" posts the id of one own submit event (never its text).
    $addevent = optional_param('addevent', 0, PARAM_INT);
    $fromfeed = optional_param('source', '', PARAM_ALPHA) === 'feed';
    $action = $addevent > 0 ? 'addresult' : required_param('action', PARAM_ALPHA);
    $values = [];
    foreach (store::FIELDS as $field) {
        $values[$field] = optional_param($field, '', PARAM_RAW);
    }
    $links = optional_param_array('linkedtasks', [], PARAM_ALPHANUMEXT);
    $ccusers = optional_param_array('ccusers', [], PARAM_INT);
    $revision = optional_param('revision', 0, PARAM_INT);
    try {
        if ($action === 'save') {
            store::save_draft($uuid, $actor, $day, $values, $links, $revision, $ccusers);
            redirect(reportui::url($uuid, ['day' => $day, 'notice' => 'saved']));
        } else if ($action === 'submit') {
            store::submit($uuid, $actor, $day, required_param('requestkey', PARAM_ALPHANUMEXT), $revision, $values, $links,
                $ccusers);
            redirect(reportui::url($uuid, ['day' => $day, 'notice' => 'submitted']));
        } else if ($action === 'addresult') {
            // From the feed the stored draft is used; from the open form its unsaved text is kept.
            $added = feed::add($uuid, $actor, $day, $addevent, $fromfeed ? null :
                ['values' => $values, 'links' => $links, 'cc' => $ccusers, 'revision' => $revision]);
            $target = reportui::url($uuid, ['day' => $day, 'notice' => $added['added'] ? 'resultadded' : 'resultexists']);
            $target->set_anchor('dailyreport-done');
            redirect($target);
        } else if ($action === 'rework' || $action === 'correct') {
            store::start_revision($uuid, $actor, $day,
                $action === 'correct' ? optional_param('correctionnote', '', PARAM_RAW) : '');
            redirect(reportui::url($uuid, ['day' => $day, 'notice' => 'revision']));
        }
        throw new moodle_exception('invalidparameter');
    } catch (moodle_exception $e) {
        if (!in_array($e->errorcode, $knownerrors, true)) {
            throw $e;
        }
        // Keep the employee's text in the form; show the saved text next to it after a conflict.
        // A feed request carries no form text: the stored draft stays on screen.
        $posted = $action === 'addresult' && $fromfeed ? null : ['values' => $values, 'links' => $links, 'cc' => $ccusers];
        $error = get_string($e->errorcode, 'local_kbacompany');
        $conflict = $posted !== null && $e->errorcode === 'dailyreports_conflict';
    }
}

// ---- R2 reviewer writes: the decision is always bound to the posted version and re-checked on the server.
$reviewerror = '';
$reportparam = $tab === 'review' ? optional_param('report', '', PARAM_ALPHANUMEXT) : '';
if ($canreview && $tab === 'review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    try {
        if (in_array($action, ['accept', 'return', 'rerate'], true)) {
            review::decide($uuid, $actor, $reportparam, required_param('version', PARAM_ALPHANUMEXT),
                $action === 'return' ? 'returned' : 'accepted', optional_param('rating', '', PARAM_ALPHA),
                optional_param('comment', '', PARAM_RAW), required_param('requestkey', PARAM_ALPHANUMEXT));
        } else if ($action === 'reassign') {
            review::reassign($uuid, $actor, $reportparam, required_param('reviewerid', PARAM_INT));
        } else if ($action === 'settings') {
            reviewers::save_settings($uuid, $actor, required_param('toppolicy', PARAM_ALPHA),
                optional_param('topreviewerid', 0, PARAM_INT), required_param('ccpolicy', PARAM_ALPHA),
                required_param('revision', PARAM_INT));
            redirect(reportui::url($uuid, ['tab' => 'review', 'queue' => 'settings', 'notice' => 'settings']));
        } else {
            throw new moodle_exception('invalidparameter');
        }
        redirect(reportui::url($uuid, ['tab' => 'review', 'report' => $reportparam, 'notice' => 'decided']));
    } catch (moodle_exception $e) {
        if (!in_array($e->errorcode, $knownerrors, true)) {
            throw $e;
        }
        $reviewerror = get_string($e->errorcode, 'local_kbacompany');
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/daily_report.php', $base + ($tab === 'history' ? ['tab' => 'history'] : [])));
$PAGE->set_title(get_string('dailyreports_nav', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
if ($enabled && $tab === 'my') {
    $PAGE->requires->js(new moodle_url('/local/kbacompany/js/daily_report_form.js'));
}
$shell = bms_page_shell_service::begin($uuid, $actor, 'dailyreports', get_string('dailyreports_nav', 'local_kbacompany'));
$str = static function(string $key): string { return get_string('dailyreport_' . $key, 'local_kbacompany'); };
$status = static function(string $value): string { return get_string('taskcenter_status_' . $value, 'local_kbacompany'); };
$link = static function(string $taskuuid, string $title) use ($uuid): string {
    return html_writer::link(new moodle_url('/local/kbacompany/tasks.php', ['uuid' => $uuid, 'task' => $taskuuid]), s($title));
};
echo $OUTPUT->heading(get_string('dailyreports_nav', 'local_kbacompany'), 1);
// R0 tab; R1 adds the own history tab only behind the switch. The reviewer tab arrives with R2 and its rights.
$tabs = [['url' => (new moodle_url('/local/kbacompany/daily_report.php', $base))->out(false),
    'label' => get_string('dailyreports_tab_my', 'local_kbacompany'), 'active' => $tab === 'my']];
if ($enabled) {
    $tabs[] = ['url' => reportui::url($uuid, ['tab' => 'history'])->out(false),
        'label' => get_string('dailyreports_tab_history', 'local_kbacompany'), 'active' => $tab === 'history'];
}
if ($canreview) {
    $tabs[] = ['url' => reportui::url($uuid, ['tab' => 'review'])->out(false),
        'label' => get_string('dailyreports_tab_review', 'local_kbacompany'), 'active' => $tab === 'review'];
}
$tablabel = get_string('dailyreports_tabs', 'local_kbacompany');
if (bms24_shell_service::is_enabled()) {
    echo bms24_ui_kit_service::fragment('ui_tabs', ['label' => $tablabel, 'items' => $tabs]);
} else {
    echo html_writer::start_tag('nav', ['aria-label' => $tablabel]);
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3']);
    foreach ($tabs as $tabitem) {
        echo html_writer::tag('li', html_writer::link($tabitem['url'], s($tabitem['label']), ['class' => 'nav-link'
            . ($tabitem['active'] ? ' active' : ''), 'aria-current' => $tabitem['active'] ? 'page' : null]), ['class' => 'nav-item']);
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_tag('nav');
}

if ($tab === 'review') {
    $reviewnotice = optional_param('notice', '', PARAM_ALPHA);
    if (in_array($reviewnotice, ['decided', 'settings'], true) && $reviewerror === '') {
        echo html_writer::div(s(get_string($reviewnotice === 'settings' ? 'dailyreports_settings_saved' :
            'dailyreports_decided', 'local_kbacompany')), 'alert alert-success', ['role' => 'status']);
    }
    if ($reviewerror !== '') {
        echo html_writer::div(s($reviewerror), 'alert alert-danger', ['role' => 'alert']);
    }
    if ($reportparam !== '') {
        $card = review::card($uuid, $actor, $reportparam);
        echo html_writer::link(reportui::url($uuid, ['tab' => 'review']), s(get_string('dailyreports_back_queue', 'local_kbacompany')));
        echo reportui::card($uuid, $actor, $card,
            $card['can_reassign'] ? review::reviewer_candidates($uuid, $actor, (int)$card['report']->userid) : []);
        // R3: the report discussion, readable by everyone who opens this card.
        echo discussionui::render($uuid, discussion::context($uuid, $actor, (string)$card['report']->uuid),
            optional_param('reply', '', PARAM_ALPHANUMEXT));
    } else {
        $queue = optional_param('queue', 'pending', PARAM_ALPHA);
        $queues = review::queues($uuid, $actor);
        $isadmin = review::is_admin($uuid, $actor);
        if ($queue === 'settings' && $isadmin) {
            // ADR-0010: company review rules (top of the chart, automatic copy), administrators only.
            echo reportui::queue($uuid, ['queue' => 'settings', 'items' => [], 'total' => 0, 'page' => 0], $queues);
            echo reportui::settings_form($uuid, reviewers::settings($uuid), reviewers::people($uuid, $actor, 0));
        } else {
            if (!in_array($queue, $queues, true)) {
                $queue = 'pending';
            }
            if ($queue === 'team') {
                // R4: every current employee for one day (read-only); filters are typed GET parameters.
                echo reportui::team($uuid, team::day($uuid, $actor, $day, optional_param('scope', '', PARAM_ALPHA),
                    optional_param('unit', '', PARAM_ALPHANUMEXT), optional_param('status', '', PARAM_ALPHA),
                    optional_param('page', 0, PARAM_INT)), $queues);
            } else {
                echo reportui::queue($uuid, review::queue($uuid, $actor, $queue, optional_param('page', 0, PARAM_INT)),
                    $queues);
            }
            if ($isadmin) {
                echo html_writer::link(reportui::url($uuid, ['tab' => 'review', 'queue' => 'settings']),
                    s(get_string('dailyreports_settings_heading', 'local_kbacompany')), ['class' => 'btn btn-link px-0']);
            }
        }
    }
    bms_page_shell_service::finish($shell);
    exit;
}

if ($tab === 'history') {
    echo reportui::history($uuid, store::history($uuid, $actor, optional_param('page', 0, PARAM_INT)));
    bms_page_shell_service::finish($shell);
    exit;
}

echo html_writer::tag('p', $str('intro'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
echo html_writer::tag('label', $str('day'), ['for' => 'dailyreport-day', 'class' => 'd-block']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'dailyreport-day', 'name' => 'day',
    'value' => $day, 'required' => 'required', 'max' => business_date_service::day(),
    'class' => 'form-control d-inline-block w-auto mr-2 mb-2']);
echo html_writer::tag('button', $str('show'), ['type' => 'submit', 'class' => 'btn btn-primary mb-2']);
echo html_writer::end_tag('form');

if ($enabled) {
    // ---- the saved report of this day (draft form or frozen versions)
    $notice = optional_param('notice', '', PARAM_ALPHA);
    if ($error === '' && in_array($notice, ['saved', 'submitted', 'revision', 'resultadded', 'resultexists'], true)) {
        echo html_writer::div(s(get_string('dailyreports_' . $notice, 'local_kbacompany')), 'alert alert-success',
            ['role' => 'status']);
    }
    $future = strcmp($day, business_date_service::day()) > 0;
    $view = $future ? null : store::view($uuid, $actor, $day);
    $report = $view['report'] ?? null;
    echo html_writer::start_tag('section', ['class' => 'bms-dailyreport mb-4', 'aria-labelledby' => 'dailyreport-own']);
    echo $OUTPUT->heading(s(get_string('dailyreports_own_heading', 'local_kbacompany')) . ' ' .
        reportui::status_badge($report ? (string)$report->status : 'none'), 2, '', 'dailyreport-own');
    if ($error !== '') {
        echo html_writer::div(s($error), 'alert alert-danger', ['role' => 'alert']);
    }
    $formopen = false;
    if ($future) {
        echo html_writer::tag('p', s(get_string('dailyreports_futureday', 'local_kbacompany')));
    } else if (!$report || $view['draft']) {
        $formopen = true;
        $draft = $view['draft'];
        if ($conflict && $draft) {
            echo reportui::conflict($draft, $posted['values']);
        }
        $values = $posted['values'] ?? [];
        if (!$posted && $draft) {
            foreach (store::FIELDS as $field) {
                $values[$field] = (string)$draft->$field;
            }
        }
        $selected = $posted['links'] ?? array_map(static function($l): string { return (string)$l->taskuuid; },
            $view['draftlinks']);
        $candidates = store::link_candidates($uuid, $actor, $data, $view['draftlinks']);
        // ADR-0010 D7: the reviewer is shown before sending; copy recipients are chosen here.
        $review = store::preview($uuid, $actor, $day);
        $review['options'] = reviewers::people($uuid, $actor, $actor);
        $review['selected'] = $posted['cc'] ?? array_map(static function($c): int { return (int)$c->userid; },
            array_filter($view['draftcc'], static function($c): bool { return (string)$c->source === 'manual'; }));
        echo reportui::form($uuid, $day, $values, $selected, $candidates, $draft ? (int)$draft->revision : 0,
            \core\uuid::generate(), $review);
    }
    foreach ($view['versions'] ?? [] as $i => $version) {
        $revieweruser = (int)$version->reviewerid > 0 ? core_user::get_user((int)$version->reviewerid) : false;
        $reviewer = $revieweruser ? fullname($revieweruser) : null;
        if ($i === 1) {
            echo $OUTPUT->heading(s(get_string('dailyreports_versions_previous', 'local_kbacompany')), 3);
        }
        echo reportui::version($uuid, $actor, $version, $view['links'][$version->uuid] ?? [], $reviewer,
            $i === 0 && $view['late'], $view['reviews'][$version->uuid] ?? [], $view['copies'][$version->uuid] ?? []);
    }
    if ($report && !$view['draft']) {
        $current = $view['versions'][0] ?? null;
        $selfreview = $current && (string)$current->reviewersource === 'self_top' && (int)$current->reviewerid === $actor;
        echo reportui::revision_controls($uuid, $day, (string)$report->status, $selfreview ? (string)$report->uuid : '');
    }
    if ($report && (string)$report->currentversionuuid !== '') {
        // R3: the discussion of this report with the reviewer (also while a returned report is being corrected).
        echo discussionui::render($uuid, discussion::context($uuid, $actor, (string)$report->uuid),
            optional_param('reply', '', PARAM_ALPHANUMEXT));
    }
    echo html_writer::end_tag('section');
    // The live summary below is the current state of BMS24 data, not part of any submitted version.
    echo $OUTPUT->heading(s(get_string('dailyreports_daydata', 'local_kbacompany')), 2);
    echo html_writer::div(s(get_string('dailyreports_daydata_note', 'local_kbacompany')), 'alert alert-secondary');
}

echo html_writer::tag('p', s($str('timezone') . ': ' . $data['timezone']));
echo html_writer::div($str('scope'), 'alert alert-info');
$pager = static function(string $kind, int $page, int $total) use ($base): string {
    return $GLOBALS['OUTPUT']->paging_bar($total, $page, daily_report_service::PAGE_SIZE,
        new moodle_url('/local/kbacompany/daily_report.php', $base), $kind . 'page');
};
echo $OUTPUT->heading($str('tasks') . ' (' . $data['tasktotal'] . ')', $enabled ? 3 : 2);
echo html_writer::tag('p', $str('tasksnote'), ['class' => 'text-muted']);
if (!$data['tasks']) { echo html_writer::tag('p', $str('emptytasks')); }
foreach ($data['tasks'] as $task) {
    echo html_writer::start_tag('article', ['class' => 'border-bottom py-3']);
    echo html_writer::tag('h3', $link($task->uuid, $task->title), ['class' => 'h5']);
    echo html_writer::tag('p', s($str('currentstatus') . ': ' . $status($task->status)));
    if ((int)$task->factminutes > 0) {
        echo html_writer::tag('p', s($str('tasktime') . ': ' . $task->factminutes . ' ' . $str('minutes')));
    }
    echo html_writer::end_tag('article');
}
echo $pager('task', $data['taskpage'], $data['tasktotal']);
echo $OUTPUT->heading($str('results') . ' (' . $data['resulttotal'] . ')', $enabled ? 3 : 2);
echo html_writer::tag('p', $str('resultsnote'), ['class' => 'text-muted']);
if (!$data['results']) { echo html_writer::tag('p', $str('emptyresults')); }
foreach ($data['results'] as $event) {
    echo html_writer::start_tag('article', ['class' => 'border-bottom py-3']);
    echo html_writer::tag('h3', $link($event->taskuuid, $event->title), ['class' => 'h5']);
    echo html_writer::tag('p', s(userdate($event->timecreated) . ' · ' . $str('submittedstatus') . ': ' . $status($event->newstatus)));
    echo html_writer::tag('p', nl2br(s($event->result !== '' ? $event->result : $str('missingresult'))));
    if ($enabled && !empty($formopen)) {
        // Submits the open report form with this event id: unsaved text is kept, the server adds the result once.
        echo html_writer::tag('button', s(get_string('dailyreports_feed_add', 'local_kbacompany')), ['type' => 'submit',
            'form' => 'dailyreport-form', 'name' => 'addevent', 'value' => (int)$event->id, 'formnovalidate' => 'formnovalidate',
            'class' => 'btn btn-outline-primary btn-sm', 'data-dailyreport-add' => (int)$event->id]);
    }
    echo html_writer::end_tag('article');
}
echo $pager('result', $data['resultpage'], $data['resulttotal']);
bms_page_shell_service::finish($shell);
