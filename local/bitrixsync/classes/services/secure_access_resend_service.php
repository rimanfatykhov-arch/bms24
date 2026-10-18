<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class secure_access_resend_service {
    private $timeline;

    public function __construct(?crm_timeline_service $timeline = null) {
        $this->timeline = $timeline ?? new crm_timeline_service();
    }

    public function regenerate(
        int $userid,
        int $bitrixcontactid = 0,
        int $dealid = 0
    ): array {
        global $DB, $CFG;

        if ($userid <= 0) {
            throw new \invalid_parameter_exception('Invalid Moodle user ID');
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            '*',
            MUST_EXIST
        );

        if (empty($user->email)) {
            throw new \moodle_exception('invalidemail');
        }

        $password = $this->generate_password();

        update_internal_user_password($user, $password, false);
        set_user_preference('auth_forcepasswordchange', 1, $userid);

        $loginurl = $CFG->wwwroot . '/login/index.php';

        $message = "Здравствуйте!\n\n"
            . "Для вас создан новый временный доступ к образовательной среде "
            . "Kazan Business Academy.\n\n"
            . "Адрес для входа:\n" . $loginurl . "\n\n"
            . "Логин:\n" . $user->email . "\n\n"
            . "Временный пароль:\n" . $password . "\n\n"
            . "При первом входе система попросит установить новый пароль.";

        $audit = "🔐 Сформирован новый временный доступ Moodle\n"
            . "Ученик: " . fullname($user) . "\n"
            . "Email / Login: " . $user->email . "\n"
            . "Moodle User ID: " . $userid . "\n"
            . "Пароль показан администратору один раз и в Timeline не сохраняется.\n"
            . "Рекомендуется передать доступ ученику через доверенный мессенджер.";

        $timeline = ['status' => 'skipped', 'error' => null];

        try {
            if ($bitrixcontactid > 0) {
                $this->timeline->upsert(
                    'contact',
                    $bitrixcontactid,
                    '[KBA_CONNECT_ACCESS_REGEN_CONTACT_' . $bitrixcontactid . ']',
                    $audit
                );
            }

            if ($dealid > 0) {
                $this->timeline->upsert(
                    'deal',
                    $dealid,
                    '[KBA_CONNECT_ACCESS_REGEN_DEAL_' . $dealid . '_USER_' . $userid . ']',
                    $audit
                );
            }

            $timeline['status'] = 'written';
        } catch (\Throwable $e) {
            $timeline['status'] = 'error';
            $timeline['error'] = $e->getMessage();
        }

        return [
            'success' => true,
            'userid' => $userid,
            'fullname' => fullname($user),
            'email' => (string)$user->email,
            'loginurl' => $loginurl,
            'password' => $password,
            'messenger_text' => $message,
            'timeline_status' => $timeline['status'],
            'timeline_error' => $timeline['error'],
        ];
    }

    private function generate_password(): string {
        if (function_exists('generate_password')) {
            return generate_password(14);
        }

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
        $password = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
