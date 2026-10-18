<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends one-time Moodle access credentials and records delivery state.
 * Existing users are never reset except KBA-created accounts that have never
 * logged in and have no successful access delivery recorded.
 */
class access_delivery_service {
    /** @var client */
    private $client;
    /** @var message_template_service */
    private $templates;
    /** @var crm_timeline_service */
    private $timeline;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
        $this->templates = new message_template_service();
        $this->timeline = new crm_timeline_service($this->client);
    }

    public function deliver(
        int $dealid,
        int $bitrixcontactid,
        array $contact,
        array $resolution,
        array $learning
    ): array {
        global $DB, $CFG;

        $out = ['status' => 'skipped', 'sent' => false, 'error' => null];
        if (!get_config('local_bitrixsync', 'access_delivery_enable')) {
            $out['reason'] = 'disabled';
            return $out;
        }

        $channel = trim((string)$this->templates->get('accesschannel'));
        if ($channel === '') {
            $channel = 'moodle';
        }

        if ($channel === 'none') {
            $out['reason'] = 'channel_disabled';
            return $out;
        }

        // v5.0.35 RC1 implements one truthful delivery transport only:
        // Moodle email_to_user() / configured SMTP.
        if ($channel !== 'moodle') {
            $out['status'] = 'error';
            $out['reason'] = 'unsupported_channel';
            $out['error'] = 'Выбранный канал доступа пока не реализован. Используйте Moodle Email / SMTP.';
            return $out;
        }

        $channellabel = 'Moodle Email / SMTP';

        $userid = (int)($resolution['userid'] ?? 0);
        if ($userid <= 0 || $bitrixcontactid <= 0) {
            $out['reason'] = 'missing_user_or_contact';
            return $out;
        }

        $bridge = $DB->get_record('local_bitrixsync_contact', ['bitrix_contact_id' => $bitrixcontactid]);
        if ($bridge && !empty($bridge->access_sent_at)) {
            $out['status'] = 'already_sent';
            $out['sent'] = true;
            return $out;
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,username,firstname,lastname,email,idnumber,lastaccess',
            MUST_EXIST
        );

        $password = (string)($resolution['temporarypassword'] ?? '');
        $isnew = !empty($resolution['created']) && $password !== '';

        // Recovery for accounts created by KBA Connect before access delivery
        // existed (e.g. the production incident that created user 41).
        if (!$isnew && get_config('local_bitrixsync', 'access_recover_kba_users')) {
            $expectedidnumber = 'bitrix-contact-' . $bitrixcontactid;
            if ((string)$user->idnumber === $expectedidnumber && (int)$user->lastaccess === 0) {
                $password = $this->generate_temporary_password();
                update_internal_user_password($user, $password);
                set_user_preference('auth_forcepasswordchange', 1, $userid);
                $isnew = true;
            }
        }

        if (!$isnew || $password === '') {
            $out['reason'] = 'not_new_account';
            return $out;
        }

        $coursenames = $this->course_names($learning);
        $coursevalue = $coursenames ? implode(', ', $coursenames) : '—';
        $context = [
            'firstname' => (string)$user->firstname,
            'fullname' => fullname($user),
            'email' => (string)$user->email,
            'url' => rtrim($CFG->wwwroot, '/') . '/login/index.php',
            'login' => (string)$user->username,
            'password' => $password,
            'course' => $coursevalue,
            'userid' => $userid,
            'channel' => $channellabel,
            'accesssentat' => userdate(time()),
            'activityline' => 'Курсов назначено: ' . ((int)($learning['enrolled'] ?? 0) + (int)($learning['already'] ?? 0)),
        ];

        $subject = $this->templates->render('accessemailsubject', $context);
        $body = $this->templates->render('accessemailbody', $context);

        // 1) SMTP / Moodle email transport.
        try {
            $from = $this->sender_user();
            $sent = email_to_user(
                $user,
                $from,
                $subject,
                $body,
                nl2br(s($body))
            );

            if (!$sent) {
                throw new \moodle_exception(
                    'error',
                    'core',
                    '',
                    null,
                    'email_to_user returned false'
                );
            }
        } catch (\Throwable $emailerror) {
            $this->mark_delivery(
                $bitrixcontactid,
                'error',
                $emailerror->getMessage(),
                0
            );

            $timeline = $this->write_timeline(
                $dealid,
                $bitrixcontactid,
                $user,
                $userid,
                $learning,
                $context,
                false,
                $emailerror->getMessage()
            );

            $result = [
                'status' => 'error',
                'sent' => false,
                'email_status' => 'error',
                'timeline_status' => (string)($timeline['status'] ?? 'skipped'),
                'error' => $emailerror->getMessage(),
                'channel' => $channellabel,
            ];
            $this->remember_diagnostic(
                $dealid,
                $bitrixcontactid,
                $userid,
                $result,
                (string)$user->email
            );
            return $result;
        }

        // Email is already delivered at this point. A Bitrix timeline failure
        // must never change that fact to "not sent".
        $this->mark_delivery(
            $bitrixcontactid,
            'sent',
            null,
            time()
        );
        $this->write_legacy_password_if_present(
            $bitrixcontactid,
            $password
        );

        $timeline = $this->write_timeline(
            $dealid,
            $bitrixcontactid,
            $user,
            $userid,
            $learning,
            $context,
            true,
            null
        );

        $result = [
            'status' => 'sent',
            'sent' => true,
            'email_status' => 'sent',
            'timeline_status' => (string)($timeline['status'] ?? 'skipped'),
            'timeline_error' => (string)($timeline['error'] ?? ''),
            'error' => null,
            'channel' => $channellabel,
        ];

        $this->remember_diagnostic(
            $dealid,
            $bitrixcontactid,
            $userid,
            $result,
            (string)$user->email
        );

        return $result;
    }

    /**
     * Timeline write is deliberately isolated from email transport.
     * Password is NEVER included in the Bitrix comment.
     */
    private function write_timeline(
        int $dealid,
        int $bitrixcontactid,
        \stdClass $user,
        int $userid,
        array $learning,
        array $context,
        bool $emailsent,
        ?string $emailerror
    ): array {
        if (!$this->templates->get('timelineenabled')) {
            return [
                'status' => 'disabled',
                'error' => null,
            ];
        }

        try {
            if ($emailsent) {
                $timelinetemplate = $this->templates->get(
                    'timeline_access_sent'
                );

                if (stripos($timelinetemplate, 'bitrix24') !== false ||
                        strpos($timelinetemplate, '{activityid}') !== false) {
                    $defaults = $this->templates->defaults();
                    $timelinetemplate = (string)(
                        $defaults['timeline_access_sent'] ?? ''
                    );
                }

                $timelinebody = $this->templates->render_text(
                    $timelinetemplate,
                    $context
                );

                $detail = "✅ Создана учетная запись Moodle\n"
                    . 'Ученик: ' . fullname($user) . "\n"
                    . 'Email / Login: ' . $user->email . "\n"
                    . 'Moodle User ID: ' . $userid . "\n"
                    . 'Курсов назначено: '
                    . (
                        (int)($learning['enrolled'] ?? 0)
                        + (int)($learning['already'] ?? 0)
                    )
                    . "\n\n"
                    . $timelinebody;
            } else {
                $detail = "⚠️ Moodle — доступ ученику не отправлен\n"
                    . 'Ученик: ' . fullname($user) . "\n"
                    . 'Email / Login: ' . $user->email . "\n"
                    . 'Moodle User ID: ' . $userid . "\n"
                    . 'Канал: Moodle Email / SMTP' . "\n"
                    . 'Ошибка: ' . (string)$emailerror;
            }

            $contactresult = $this->timeline->upsert(
                'contact',
                $bitrixcontactid,
                '[KBA_CONNECT_ACCESS_CONTACT_' . $bitrixcontactid . ']',
                $detail
            );

            $dealresult = [
                'success' => true,
                'action' => 'skipped',
                'id' => 0,
            ];

            if ($dealid > 0) {
                $dealresult = $this->timeline->upsert(
                    'deal',
                    $dealid,
                    '[KBA_CONNECT_ACCESS_DEAL_' . $dealid
                        . '_CONTACT_' . $bitrixcontactid . ']',
                    $detail
                );
            }

            return [
                'status' => 'written',
                'error' => null,
                'contact' => $contactresult,
                'deal' => $dealresult,
            ];
        } catch (\Throwable $timelineerror) {
            return [
                'status' => 'error',
                'error' => $timelineerror->getMessage(),
            ];
        }
    }

    /**
     * Store safe operational diagnostics. No password, token, webhook or
     * message body is persisted here.
     */
    private function remember_diagnostic(
        int $dealid,
        int $contactid,
        int $userid,
        array $result,
        string $recipient
    ): void {
        $safe = [
            'time' => time(),
            'dealid' => $dealid,
            'contactid' => $contactid,
            'userid' => $userid,
            'recipient' => $recipient,
            'enabled' => (bool)get_config(
                'local_bitrixsync',
                'access_delivery_enable'
            ),
            'channel' => (string)$this->templates->get('accesschannel'),
            'timelineenabled' => (bool)$this->templates->get('timelineenabled'),
            'status' => (string)($result['status'] ?? ''),
            'sent' => !empty($result['sent']),
            'email_status' => (string)($result['email_status'] ?? ''),
            'timeline_status' => (string)($result['timeline_status'] ?? ''),
            'error' => (string)($result['error'] ?? ''),
            'timeline_error' => (string)($result['timeline_error'] ?? ''),
        ];

        set_config(
            'access_delivery_last_debug',
            json_encode(
                $safe,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );
    }

    /**
     * Build the actual Moodle sender from KBA Connect message settings.
     *
     * Moodle still performs delivery through email_to_user() and the site's
     * configured SMTP transport.
     */
    private function sender_user(): \stdClass {
        $from = clone \core_user::get_support_user();

        $name = trim((string)$this->templates->get('accesssendername'));
        $email = trim((string)$this->templates->get('accesssenderemail'));

        if ($name !== '') {
            $from->firstname = $name;
            $from->lastname = '';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $from->email = $email;
        }

        return $from;
    }

    private function mark_delivery(int $contactid, string $status, ?string $error, int $sentat): void {
        global $DB;
        $row = $DB->get_record('local_bitrixsync_contact', ['bitrix_contact_id' => $contactid]);
        if (!$row) {
            return;
        }
        $row->access_status = $status;
        $row->access_error = $error;
        if ($sentat > 0) {
            $row->access_sent_at = $sentat;
        }
        $row->timemodified = time();
        $DB->update_record('local_bitrixsync_contact', $row);
    }

    private function write_legacy_password_if_present(int $contactid, string $password): void {
        if (!get_config('local_bitrixsync', 'access_write_legacy_password')) {
            return;
        }
        try {
            $response = $this->client->call('crm.contact.userfield.list', [
                'filter' => [],
                'select' => ['ID','FIELD_NAME','XML_ID','EDIT_FORM_LABEL','LIST_COLUMN_LABEL'],
                'start' => 0,
            ]);
            foreach (($response['result'] ?? []) as $field) {
                $labels = [
                    (string)($field['FIELD_NAME'] ?? ''),
                    (string)($field['XML_ID'] ?? ''),
                    (string)($field['EDIT_FORM_LABEL'] ?? ''),
                    (string)($field['LIST_COLUMN_LABEL'] ?? ''),
                ];
                $normalized = array_map(function($v) { return \core_text::strtolower(trim($v)); }, $labels);
                $match = false;
                foreach ($normalized as $v) {
                    if ($v === 'moodle password' || $v === 'moodle — пароль' || $v === 'moodle - пароль' ||
                            $v === 'kba_moodle_password' || $v === 'mba_m_password') {
                        $match = true;
                        break;
                    }
                }
                if ($match && !empty($field['FIELD_NAME'])) {
                    $this->client->call('crm.contact.update', [
                        'id' => $contactid,
                        'fields' => [(string)$field['FIELD_NAME'] => $password],
                    ]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Password field is backward-compatibility only. Email delivery must not fail because of it.
        }
    }

    private function course_names(array $learning): array {
        global $DB;
        $names = [];
        foreach (($learning['items'] ?? []) as $item) {
            $courseid = (int)($item['courseid'] ?? 0);
            if ($courseid > 0 && !isset($names[$courseid])) {
                $name = $DB->get_field('course', 'fullname', ['id' => $courseid]);
                if ($name !== false) {
                    $names[$courseid] = (string)$name;
                }
            }
        }
        return array_values($names);
    }

    private function generate_temporary_password(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'Kba!' . $password . random_int(10, 99);
    }
}
