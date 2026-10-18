<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class contact_service {
    public function get_bridge(int $bitrixcontactid) {
        global $DB;
        return $DB->get_record(
            'local_bitrixsync_contact',
            ['bitrix_contact_id' => $bitrixcontactid]
        );
    }

    /**
     * Resolve a Bitrix contact to a Moodle user.
     *
     * Resolution order:
     * 1. local_bitrixsync_contact mapping
     * 2. exact unique email match (if enabled)
     * 3. optional Moodle user creation
     *
     * Dry Run never creates a Moodle user.
     */
    public function resolve_user(
        int $bitrixcontactid,
        array $contact,
        bool $dryrun = true,
        ?int $overrideuserid = null
    ): array {
        global $DB;

        if ($overrideuserid !== null && $overrideuserid > 0) {
            $user = $DB->get_record(
                'user',
                ['id' => $overrideuserid, 'deleted' => 0],
                'id,username,firstname,lastname,email,lastaccess',
                MUST_EXIST
            );

            return [
                'status' => 'override',
                'userid' => (int)$user->id,
                'user' => $user,
                'email' => (string)$user->email,
                'wouldcreate' => false,
                'created' => false,
            ];
        }

        if ($bitrixcontactid <= 0) {
            throw new \moodle_exception('deal_contact_missing', 'local_bitrixsync');
        }

        $bridge = $this->get_bridge($bitrixcontactid);
        if ($bridge && (int)$bridge->userid > 0) {
            $user = $DB->get_record(
                'user',
                ['id' => (int)$bridge->userid, 'deleted' => 0],
                'id,username,firstname,lastname,email,lastaccess'
            );

            if ($user) {
                return [
                    'status' => 'mapped',
                    'userid' => (int)$user->id,
                    'user' => $user,
                    'email' => (string)$user->email,
                    'wouldcreate' => false,
                    'created' => false,
                ];
            }
        }

        $email = $this->extract_email($contact);

        if ($email !== '' && get_config('local_bitrixsync', 'match_users_by_email')) {
            $users = $DB->get_records_sql(
                "SELECT id,username,firstname,lastname,email,lastaccess
                   FROM {user}
                  WHERE deleted = 0
                    AND LOWER(email) = LOWER(:email)
               ORDER BY id ASC",
                ['email' => $email],
                0,
                2
            );

            if (count($users) === 1) {
                $user = reset($users);

                if (!$dryrun) {
                    $this->record_contact(
                        $bitrixcontactid,
                        (int)$user->id,
                        $contact,
                        'matched',
                        null
                    );
                }

                return [
                    'status' => 'email_match',
                    'userid' => (int)$user->id,
                    'user' => $user,
                    'email' => $email,
                    'wouldcreate' => false,
                    'created' => false,
                ];
            }

            if (count($users) > 1) {
                throw new \moodle_exception(
                    'contact_email_ambiguous',
                    'local_bitrixsync',
                    '',
                    $email
                );
            }
        }

        $autocreate = (bool)get_config('local_bitrixsync', 'auto_create_users');

        if ($dryrun || !$autocreate) {
            return [
                'status' => $autocreate ? 'would_create' : 'not_found',
                'userid' => 0,
                'user' => null,
                'email' => $email,
                'wouldcreate' => $autocreate,
                'created' => false,
                'proposed' => $this->build_user_preview($bitrixcontactid, $contact, $email),
            ];
        }

        if ($email === '') {
            throw new \moodle_exception(
                'contact_email_required_for_create',
                'local_bitrixsync',
                '',
                $bitrixcontactid
            );
        }

        $created = $this->create_moodle_user($bitrixcontactid, $contact, $email);
        $userid = (int)$created['userid'];

        $user = $DB->get_record(
            'user',
            ['id' => $userid],
            'id,username,firstname,lastname,email,lastaccess',
            MUST_EXIST
        );

        $this->record_contact(
            $bitrixcontactid,
            $userid,
            $contact,
            'created',
            null
        );

        return [
            'status' => 'created',
            'userid' => $userid,
            'user' => $user,
            'email' => $email,
            'wouldcreate' => false,
            'created' => true,
            'temporarypassword' => (string)$created['temporarypassword'],
            'username' => (string)$created['username'],
        ];
    }

    public function record_corporate_employee(
        int $bitrixcontactid,
        int $userid,
        array $bitrixcontact = []
    ): int {
        return $this->record_contact(
            $bitrixcontactid,
            $userid,
            $bitrixcontact,
            'corporate',
            null,
            true
        );
    }

    private function record_contact(
        int $bitrixcontactid,
        int $userid,
        array $contact,
        string $status,
        ?string $error = null,
        bool $corporateemployee = false
    ): int {
        global $DB;

        if ($bitrixcontactid <= 0) {
            return 0;
        }

        $now = time();
        $email = $this->extract_email($contact);
        $phone = $this->extract_phone($contact);
        $position = trim((string)($contact['POST'] ?? ''));

        $user = $userid > 0
            ? $DB->get_record(
                'user',
                ['id' => $userid],
                'id,username,email,lastaccess'
            )
            : false;

        $record = $this->get_bridge($bitrixcontactid);

        if ($record) {
            $record->userid = $userid ?: $record->userid;
            $record->username = $user ? $user->username : $record->username;
            $record->email = $email !== ''
                ? $email
                : ($user ? $user->email : $record->email);
            $record->position = $position !== '' ? $position : $record->position;
            $record->phone = $phone !== '' ? $phone : $record->phone;
            $record->corporate_employee = $corporateemployee
                ? 1
                : (int)$record->corporate_employee;
            $record->last_login = $user ? (int)$user->lastaccess : $record->last_login;
            $record->status = $status;
            $record->last_sync = $now;
            $record->error = $error;
            $record->timemodified = $now;

            $DB->update_record('local_bitrixsync_contact', $record);
            return (int)$record->id;
        }

        return (int)$DB->insert_record('local_bitrixsync_contact', (object)[
            'bitrix_contact_id' => $bitrixcontactid,
            'userid' => $userid ?: null,
            'username' => $user ? $user->username : null,
            'email' => $email !== '' ? $email : ($user ? $user->email : null),
            'position' => $position,
            'phone' => $phone,
            'corporate_employee' => $corporateemployee ? 1 : 0,
            'last_login' => $user ? (int)$user->lastaccess : null,
            'status' => $status,
            'last_sync' => $now,
            'error' => $error,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    private function create_moodle_user(
        int $bitrixcontactid,
        array $contact,
        string $email
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');

        $preview = $this->build_user_preview($bitrixcontactid, $contact, $email);
        $username = \core_text::strtolower(clean_param($email, PARAM_USERNAME));
        if ($username === '' || $username !== \core_text::strtolower($email)) {
            throw new \moodle_exception('contact_email_required_for_create', 'local_bitrixsync', '', $bitrixcontactid);
        }

        // Login must be the contact email. An existing username with another
        // account is a conflict and must not silently produce a suffix.
        $existingusername = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id,email');
        if ($existingusername) {
            throw new \moodle_exception('contact_username_conflict', 'local_bitrixsync', '', $username);
        }

        $temporarypassword = $this->generate_temporary_password();

        $user = (object)[
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $username,
            'password' => $temporarypassword,
            'firstname' => $preview['firstname'],
            'lastname' => $preview['lastname'],
            'email' => $email,
            'idnumber' => 'bitrix-contact-' . $bitrixcontactid,
            'city' => '',
            'country' => '',
            'lang' => $CFG->lang ?? 'ru',
            'timezone' => '99',
            'description' => 'Created by KBA Connect from Bitrix24 contact #' . $bitrixcontactid,
        ];

        $userid = (int)user_create_user($user, false, false);
        set_user_preference('auth_forcepasswordchange', 1, $userid);

        return [
            'userid' => $userid,
            'username' => $username,
            'temporarypassword' => $temporarypassword,
        ];
    }

    private function generate_temporary_password(): string {
        // Human-readable, while still containing lower/upper/digit/symbol.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'Kba!' . $password . random_int(10, 99);
    }

    private function build_user_preview(
        int $bitrixcontactid,
        array $contact,
        string $email
    ): array {
        $firstname = trim((string)($contact['NAME'] ?? ''));
        $lastname = trim((string)($contact['LAST_NAME'] ?? ''));

        if ($firstname === '') {
            $firstname = 'Bitrix';
        }
        if ($lastname === '') {
            $lastname = 'Contact ' . $bitrixcontactid;
        }

        return [
            'username' => $email !== '' ? \core_text::strtolower($email) : 'bitrix_' . $bitrixcontactid,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
        ];
    }

    private function unique_username(string $base): string {
        global $DB;

        $username = \core_text::strtolower(clean_param($base, PARAM_USERNAME));
        if ($username === '') {
            $username = 'bitrix_user';
        }

        if (!$DB->record_exists('user', ['username' => $username])) {
            return $username;
        }

        $i = 2;
        while ($DB->record_exists('user', ['username' => $username . '_' . $i])) {
            $i++;
        }

        return $username . '_' . $i;
    }

    private function extract_email(array $contact): string {
        if (empty($contact['EMAIL']) || !is_array($contact['EMAIL'])) {
            return '';
        }

        foreach ($contact['EMAIL'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = trim((string)($row['VALUE'] ?? ''));
            if ($value !== '' && validate_email($value)) {
                return \core_text::strtolower($value);
            }
        }

        return '';
    }

    private function extract_phone(array $contact): string {
        if (empty($contact['PHONE']) || !is_array($contact['PHONE'])) {
            return '';
        }

        foreach ($contact['PHONE'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = trim((string)($row['VALUE'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
