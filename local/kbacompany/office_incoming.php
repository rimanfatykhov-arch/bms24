<?php
// DOC-W3 "Office → Incoming" register (owner decisions 27.11.2026, #196). Off unless both the Office workspace and the
// incoming register switches are on. Every read and write goes through office_incoming_service, which checks the
// company, the explicit role rights and the card visibility; this page only routes requests and renders.
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\office_incoming_service as incoming;
use local_kbacompany\local\office_incoming_ui_service as ui;
use local_kbacompany\local\office_upload;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
incoming::require_enabled();
company_context_service::context($uuid, $actor);

// Protected download: company, right and card visibility are checked by the service; always an attachment, never
// rendered inline, never publicly cached. A foreign or hidden file is a plain 404.
$download = optional_param('download', '', PARAM_ALPHA);
if ($download !== '') {
    $area = ['original' => 'office_original', 'attachment' => 'office_attachment'][$download] ?? '';
    $sha = optional_param('sha', '', PARAM_ALPHANUM);
    $name = optional_param('name', '', PARAM_FILE);
    try {
        if ($area === '' || !preg_match('/^[a-f0-9]{64}$/D', $sha) || $name === '') {
            throw new moodle_exception('officein_err_notfound', 'local_kbacompany');
        }
        $file = incoming::file_for_download($uuid, $actor, $area, optional_param('item', 0, PARAM_INT), $sha, $name);
    } catch (moodle_exception $e) {
        send_header_404();
        die;
    }
    send_stored_file($file, 0, 0, true, ['cacheability' => 'private']);
}

$cardparam = optional_param('card', '', PARAM_ALPHANUMEXT);
$view = optional_param('view', '', PARAM_ALPHA);
$error = null;
$values = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $operation = required_param('operation', PARAM_ALPHA);
    $card = optional_param('card', '', PARAM_ALPHANUMEXT);
    $lockversion = optional_param('lockversion', -1, PARAM_INT);
    $data = [];
    foreach (incoming::FIELDS as $field) {
        $data[$field] = optional_param($field, '', $field === 'summary' ? PARAM_RAW : PARAM_TEXT);
    }
    try {
        if ($operation === 'create') {
            $values = $data;
            $view = 'new';
            $created = incoming::create($uuid, $actor, required_param('createkey', PARAM_ALPHANUMEXT), $data);
            redirect(incoming::url($uuid, ['card' => $created]), get_string('officein_created', 'local_kbacompany'));
        }
        $cardparam = $card;
        if ($operation === 'save') {
            $values = $data;
            incoming::save($uuid, $actor, $card, $lockversion, $data, optional_param('reason', '', PARAM_TEXT));
            redirect(incoming::url($uuid, ['card' => $card]), get_string('officein_saved', 'local_kbacompany'));
        } else if ($operation === 'register') {
            $result = incoming::register($uuid, $actor, $card, $lockversion, required_param('registerkey', PARAM_ALPHANUMEXT));
            redirect(incoming::url($uuid, ['card' => $card]), get_string($result['repeated'] ? 'officein_registered_repeat' :
                'officein_registered', 'local_kbacompany', $result['number']));
        } else if ($operation === 'file') {
            $kind = required_param('kind', PARAM_ALPHA);
            $upload = office_upload::inspect($_FILES['officefile'] ?? []);
            if ($upload === null) { throw new moodle_exception('officein_err_upload', 'local_kbacompany'); }
            incoming::add_file($uuid, $actor, $card, $lockversion, $kind, $upload);
            redirect(incoming::url($uuid, ['card' => $card]), get_string('officein_uploaded', 'local_kbacompany'));
        } else if ($operation === 'cancel') {
            incoming::cancel($uuid, $actor, $card, $lockversion, optional_param('reason', '', PARAM_TEXT));
            redirect(incoming::url($uuid, ['card' => $card]), get_string('officein_cancelled', 'local_kbacompany'));
        } else {
            throw new moodle_exception('invalidparameter');
        }
    } catch (moodle_exception $e) {
        // Business errors are shown on the same view with the entered values (a create keeps its idempotency key);
        // a hidden or foreign card renders as "not found" below.
        $error = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url(incoming::url($uuid, array_filter(['card' => $cardparam, 'view' => $view])));
$PAGE->set_title(get_string('officein_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/bms_ui/office_workspace.css'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/bms_ui/office_incoming.css'));
$shell = bms_page_shell_service::begin($uuid, $actor, 'office', get_string('officein_title', 'local_kbacompany'));

if ($cardparam !== '') {
    try {
        $model = incoming::card($uuid, $actor, $cardparam);
        $recordid = incoming::record_id($uuid, $actor, $cardparam);
        echo ui::card_page($uuid, $recordid, $model, \core\uuid::generate(), $error, $values);
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'officein_err_notfound') { throw $e; }
        echo ui::not_found($uuid);
    }
} else if ($view === 'new') {
    $rights = incoming::rights($uuid, $actor);
    if (!$rights['create']) {
        echo ui::not_found($uuid);
    } else {
        $default = ['channel' => 'post', 'receivedon' => (new DateTimeImmutable('now',
            core_date::get_server_timezone_object()))->format('Y-m-d')];
        echo ui::new_page($uuid, optional_param('createkey', '', PARAM_ALPHANUMEXT) ?: \core\uuid::generate(),
            $values ?? $default, $error);
    }
} else {
    $filters = ['status' => optional_param('status', '', PARAM_ALPHA), 'q' => optional_param('q', '', PARAM_TEXT)];
    $page = optional_param('page', 0, PARAM_INT);
    echo ui::list_page($uuid, incoming::browse($uuid, $actor, $filters, $page), $filters);
}
bms_page_shell_service::finish($shell);
