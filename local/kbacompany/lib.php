<?php

defined('MOODLE_INTERNAL') || die();

/** Add the company-first entry point for authorised signed-in users. */
function local_kbacompany_extend_navigation(global_navigation $navigation): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }
    if (\local_kbacompany\local\competency_review_queue_service::has_pending()) {
        $navigation->add(get_string('reviewqueue_title', 'local_kbacompany'),
            new moodle_url('/local/kbacompany/competency_review_queue.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_kbacompany_reviewqueue');
    }
    $context = context_system::instance();
    if (!has_capability('local/kbacompany:viewowncompany', $context)) {
        return;
    }
    $navigation->add(
        get_string('mycompany', 'local_kbacompany'),
        new moodle_url('/local/kbacompany/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_kbacompany'
    );
}

/**
 * Add a reliable «Моя компания» shortcut to Boost and Boost-derived navbars.
 *
 * Moodle 4.x themes do not necessarily expose custom global-navigation nodes
 * in the primary header. The navbar plugin output callback is the extension
 * point already used by the KBA interface.
 *
 * @param core_renderer $renderer
 * @return string
 */
function local_kbacompany_render_navbar_output($renderer): string {
    if (!isloggedin() || isguestuser() || during_initial_install()) {
        return '';
    }

    $queuelink = '';
    if (\local_kbacompany\local\competency_review_queue_service::has_pending()) {
        $queuelink = html_writer::link(new moodle_url('/local/kbacompany/competency_review_queue.php'),
            get_string('reviewqueue_title', 'local_kbacompany'), ['class' => 'kbacompany-navbar-link']);
    }
    $context = context_system::instance();
    if (!has_capability('local/kbacompany:viewowncompany', $context)) {
        return $queuelink ? html_writer::div($queuelink, 'kbacompany-navbar-entry') : '';
    }

    $url = new moodle_url('/local/kbacompany/index.php');
    $icon = $renderer->pix_icon('i/home', '', 'moodle', [
        'class' => 'kbacompany-navbar-icon',
    ]);
    $label = html_writer::span(
        get_string('mycompany', 'local_kbacompany'),
        'kbacompany-navbar-label'
    );
    $link = html_writer::link($url, $icon . $label, [
        'class' => 'kbacompany-navbar-link',
        'title' => get_string('mycompany', 'local_kbacompany'),
        'aria-label' => get_string('mycompany', 'local_kbacompany'),
    ]);

    return html_writer::div($link . $queuelink, 'kbacompany-navbar-entry');
}

/** Shared navigation for the organization workspace. */
function local_kbacompany_organization_tabs(string $companyuuid, string $current, bool $canmanage): string {
    $tabs = [
        new tabobject('overview', new moodle_url('/local/kbacompany/organization_hub.php', ['uuid' => $companyuuid]),
            get_string('orghub_overview', 'local_kbacompany')),
        new tabobject('designer', new moodle_url('/local/kbacompany/ofs_designer.php', ['uuid' => $companyuuid]),
            get_string('ofsdesigner_title', 'local_kbacompany')),
        new tabobject('board', new moodle_url('/local/kbacompany/organizing_board.php', ['uuid' => $companyuuid]),
            get_string('orghub_board', 'local_kbacompany')),
        new tabobject('ofs', new moodle_url('/local/kbacompany/ofs.php', ['uuid' => $companyuuid]),
            get_string('orghub_ofs', 'local_kbacompany')),
        new tabobject('people', new moodle_url('/local/kbacompany/organization.php', ['uuid' => $companyuuid]),
            get_string('orghub_people', 'local_kbacompany')),
        new tabobject('employees', new moodle_url('/local/kbacompany/employees.php', ['uuid' => $companyuuid]),
            get_string('bms_employees_title', 'local_kbacompany')),
        new tabobject('staffing', new moodle_url('/local/kbacompany/positions.php', ['uuid' => $companyuuid]),
            get_string('orghub_staffing', 'local_kbacompany')),
        new tabobject('positionos', new moodle_url('/local/kbacompany/position_os.php', ['uuid' => $companyuuid]),
            get_string('orghub_positionos', 'local_kbacompany')),
    ];
    if ($canmanage) {
        $tabs[] = new tabobject('projects', new moodle_url('/local/kbacompany/designs.php', ['uuid' => $companyuuid]),
            get_string('orghub_projects', 'local_kbacompany'));
    }
    global $OUTPUT;
    return $OUTPUT->tabtree($tabs, $current);
}


/** Serve protected task/communication attachments and exact document revisions through Moodle File API. */
function local_kbacompany_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($filearea === 'document_revision') {
        if ($context->contextlevel !== CONTEXT_SYSTEM || count($args) !== 4) { return false; }
        require_login();
        require_capability('local/kbacompany:viewowncompany', $context);
        list($id, $company, $hash, $filename) = array_values($args);
        if (!ctype_digit((string)$id) || (int)$id <= 0 ||
                !preg_match('/^[a-f0-9-]{36}$/Di', (string)$company) ||
                !preg_match('/^[a-f0-9]{64}$/D', (string)$hash) || !is_string($filename)) { return false; }
        try {
            $file = \local_kbacompany\local\document_service::file_for_download(
                $company, (int)$USER->id, (int)$id, $hash, $filename);
        } catch (\moodle_exception $e) { return false; }
        // Always download, never inline-render untrusted Office/PDF/HTML content. No public cache.
        send_stored_file($file, 0, 0, true, ['cacheability'=>'private']);
        return true;
    }
    if ($context->contextlevel !== CONTEXT_SYSTEM || !in_array($filearea,
            ['task_attachment', 'task_result', 'communication_attachment'], true)) { return false; }
    require_login();
    require_capability('local/kbacompany:viewowncompany', $context);
    if (!$args) { return false; }
    $rawitemid = array_shift($args);
    if (!ctype_digit((string)$rawitemid) || (int)$rawitemid <= 0 || !$args) { return false; }
    $itemid = (int)$rawitemid;
    try {
        if ($filearea === 'communication_attachment') {
            $message = $DB->get_record('local_kbacompany_commmsg', ['id' => $itemid], 'id,companyuuid,uuid');
            if (!$message || !\local_kbacompany\local\communication_service::can_view_message(
                    (string)$message->companyuuid, (int)$USER->id, (string)$message->uuid)) { return false; }
        } else {
            $task = $DB->get_record('local_kbacompany_task', ['id' => $itemid], 'id,companyuuid,uuid');
            if (!$task || !\local_kbacompany\local\task_service::can_view_task(
                    (string)$task->companyuuid, (int)$USER->id, (string)$task->uuid)) { return false; }
        }
    } catch (\moodle_exception $e) {
        // Revoked company/object access must not reveal whether a protected file exists.
        return false;
    }
    $filename = array_pop($args);
    if (!is_string($filename) || $filename === '' || $filename === '.' || $filename === '..') { return false; }
    $filepath = '/' . implode('/', $args);
    if ($filepath !== '/') { $filepath .= '/'; }
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_kbacompany', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) { return false; }
    send_stored_file($file, 0, 0, true, ['cacheability' => 'private'] + $options);
}
