<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\office_incoming_service as incoming;
use local_kbacompany\local\office_incoming_ui_service;
use local_kbacompany\local\office_upload;
use local_kbacompany\local\office_workspace_service;
use local_kbacompany\local\office_workspace_ui_service;
use local_kbacorporate\local\repository;

/**
 * DOC-W3 incoming register (owner decisions 27.11.2026, #196): switches, explicit rights without manager fallback,
 * two-company isolation, visibility, server numbering, idempotent repeats, immutable original, correction with a
 * reason, cancellation that keeps the number, revocation, upload validation and privacy. Synthetic data only.
 * A real concurrent registration race is exercised by the browser acceptance with parallel PHP processes.
 */
final class office_incoming_service_test extends \advanced_testcase {
    private function company(string $name): array {
        $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
        return ['id' => $companyid, 'uuid' => identity_registry::company_uuid($companyid)];
    }

    private function member(array $company, bool $manager = false): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $company['id'], 'userid' => $user->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => (int)$manager, 'isdepartmentmanager' => 0, 'ishr' => 0,
        ]);
        $DB->insert_record('local_kbacompany_member', (object)[
            'uuid' => \core\uuid::generate(), 'companyuuid' => $company['uuid'], 'userid' => $user->id,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => (int)$manager, 'active' => 1,
        ]);
        if ($manager) {
            $context = \context_system::instance();
            $roleid = create_role('Incoming test manager ' . $user->id, 'inmanager' . $user->id, 'Synthetic fixture');
            assign_capability('local/kbacompany:viewowncompany', CAP_ALLOW, $roleid, $context->id);
            assign_capability('local/kbacompany:manageorganization', CAP_ALLOW, $roleid, $context->id);
            assign_capability('local/kbacompany:viewallcompanies', CAP_PROHIBIT, $roleid, $context->id);
            role_assign($roleid, $user->id, $context->id);
        }
        return $user;
    }

    /** Company role with the given office_incoming rights, assigned to the users (the role administrator's actions). */
    private function role(array $company, int $admin, array $rights, array $users): int {
        $roleid = access_role_service::create_role($company['uuid'], $admin, 'Incoming ' . implode('+', $rights), '');
        foreach ($rights as $right) {
            access_role_service::set_grant($company['uuid'], $admin, $roleid, 'office_incoming', $right, true);
        }
        foreach ($users as $user) {
            access_role_service::assign($company['uuid'], $admin, $roleid, (int)$user->id, true);
        }
        return $roleid;
    }

    private function data(string $subject, array $extra = []): array {
        return $extra + ['subject' => $subject, 'correspondent' => 'Synthetic Sender LLC', 'externalnumber' => '17/4',
            'externaldate' => '2026-11-20', 'receivedon' => date('Y-m-d', time() - DAYSECS), 'channel' => 'post',
            'summary' => 'Synthetic letter'];
    }

    private function scenario(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        set_config(incoming::SETTING, 1, 'local_kbacompany');
        $admin = (int)get_admin()->id;
        $a = $this->company('Incoming A');
        $b = $this->company('Incoming B');
        $ra = $this->member($a);          // registrar A
        $aa = $this->member($a);          // author A (create only)
        $ca = $this->member($a);          // second author A
        $ma = $this->member($a, true);    // company manager A without any office right
        $ea = $this->member($a);          // ordinary employee A
        $rb = $this->member($b);          // registrar B
        $registrara = $this->role($a, $admin, ['register'], [$ra]);
        $authora = $this->role($a, $admin, ['create'], [$aa, $ca]);
        $this->role($b, $admin, ['register'], [$rb]);
        return compact('admin', 'a', 'b', 'ra', 'aa', 'ca', 'ma', 'ea', 'rb', 'registrara', 'authora');
    }

    private function file(string $name, string $content): array {
        $path = make_request_directory() . '/' . $name;
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'x/y'];
    }

    private function pdf(string $name = 'letter.pdf'): array {
        return office_upload::inspect($this->file($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . $name), true);
    }

    private function docx(bool $macro = false): string {
        $path = make_request_directory() . '/build.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            ($macro ? '<Default Extension="bin" ContentType="application/vnd.ms-office.vbaProject"/>' : '') .
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        if ($macro) { $zip->addFromString('word/vbaProject.bin', 'x'); }
        $zip->close();
        return file_get_contents($path);
    }

    private function expect_error(string $code, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_switches_off_mean_no_register(): void {
        $s = $this->scenario();
        set_config(incoming::SETTING, 0, 'local_kbacompany');
        $this->assertFalse(incoming::enabled());
        $this->expect_error('officein_disabled', function() use ($s) {
            incoming::browse($s['a']['uuid'], (int)$s['ra']->id);
        });
        set_config(incoming::SETTING, 1, 'local_kbacompany');
        set_config(office_workspace_service::SETTING, 0, 'local_kbacompany');
        $this->assertFalse(incoming::enabled(), 'The register also needs the Office workspace switch.');
        set_config(office_workspace_service::SETTING, 1, 'local_kbacompany');
        $this->assertTrue(incoming::enabled());
    }

    public function test_rights_are_explicit_without_manager_fallback(): void {
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $this->assertSame(['create' => true, 'register' => true], incoming::rights($c, (int)$s['ra']->id));
        $this->assertSame(['create' => true, 'register' => false], incoming::rights($c, (int)$s['aa']->id));
        $this->assertSame(['create' => false, 'register' => false], incoming::rights($c, (int)$s['ma']->id),
            'A company manager gets no incoming right automatically.');
        $this->assertSame(['create' => false, 'register' => false], incoming::rights($c, (int)$s['ea']->id));
        // Only company-wide create/register can be granted on office_incoming.
        foreach ([['view', 'company'], ['edit', 'company'], ['approve', 'company'], ['register', 'department']] as [$a, $scope]) {
            try {
                access_role_service::set_grant($c, $s['admin'], $s['registrara'], 'office_incoming', $a, true, $scope);
                $this->fail('Unexpected grant accepted: ' . $a . '/' . $scope);
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('not enforced', $e->getMessage());
            }
        }
        $this->expect_error('officein_err_denied', function() use ($c, $s) {
            incoming::create($c, (int)$s['ma']->id, \core\uuid::generate(), $this->data('Manager attempt'));
        });
        // The overview links "Incoming" only for users with a right.
        $this->assertTrue(office_workspace_service::overview($c, (int)$s['aa']->id)['incoming_link']);
        $this->assertFalse(office_workspace_service::overview($c, (int)$s['ea']->id)['incoming_link']);
        $html = office_workspace_ui_service::section_nav($c, 'overview', true);
        $this->assertMatchesRegularExpression('~<a[^>]*data-office-section="incoming"~', $html);
        $this->assertStringNotContainsString('data-office-planned="incoming"', $html);
        $html = office_workspace_ui_service::section_nav($c, 'overview');
        $this->assertStringContainsString('data-office-planned="incoming"', $html);
    }

    public function test_create_register_number_journal_and_idempotent_repeats(): void {
        global $DB;
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = (int)$s['aa']->id;
        $registrar = (int)$s['ra']->id;
        $key = \core\uuid::generate();
        $first = incoming::create($c, $author, $key, $this->data('First letter'));
        $this->assertSame($first, incoming::create($c, $author, $key, $this->data('First letter (double click)')),
            'Repeating the create command returns the same card.');
        $this->assertSame(1, $DB->count_records('local_kbacompany_offrec', ['companyuuid' => $c]));
        $card = incoming::card($c, $author, $first);
        $this->assertSame('received', $card['status']);
        $this->assertNull($card['regnumber']);
        $this->assertFalse($card['can']['register'], 'An author is not a registrar.');
        $this->expect_error('officein_err_denied', function() use ($c, $author, $first, $card) {
            incoming::register($c, $author, $first, $card['lockversion'], \core\uuid::generate());
        });

        $year = (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->format('Y');
        $registerkey = \core\uuid::generate();
        $before = time();
        $result = incoming::register($c, $registrar, $first, $card['lockversion'], $registerkey);
        $this->assertSame(['number' => 'Вх-' . $year . '/0001', 'repeated' => false], $result);
        $repeat = incoming::register($c, $registrar, $first, $card['lockversion'], $registerkey);
        $this->assertSame(['number' => 'Вх-' . $year . '/0001', 'repeated' => true], $repeat);
        $other = incoming::register($c, $registrar, $first, 0, \core\uuid::generate());
        $this->assertSame('Вх-' . $year . '/0001', $other['number'], 'A second command on a registered card changes nothing.');
        $this->assertSame(1, (int)$DB->get_field('local_kbacompany_offseries', 'lastseq', ['companyuuid' => $c]));

        $card = incoming::card($c, $registrar, $first);
        $this->assertSame('registered', $card['status']);
        $this->assertGreaterThanOrEqual($before, $card['registeredat']);
        $this->assertSame($registrar, $card['registeredby']);
        $this->assertSame(['created', 'registered'], array_column($card['journal'], 'action'));
        $this->assertSame('Вх-' . $year . '/0001', $card['journal'][1]['new']['number']);

        $second = incoming::create($c, (int)$s['ca']->id, \core\uuid::generate(), $this->data('Second letter'));
        $this->assertSame('Вх-' . $year . '/0002', incoming::register($c, $registrar, $second, 0, \core\uuid::generate())['number']);
        // Another company has its own series.
        $foreign = incoming::create($s['b']['uuid'], (int)$s['rb']->id, \core\uuid::generate(), $this->data('B letter'));
        $this->assertSame('Вх-' . $year . '/0001',
            incoming::register($s['b']['uuid'], (int)$s['rb']->id, $foreign, 0, \core\uuid::generate())['number']);
        // The unique index is the last guard against a duplicate number in the series.
        $row = $DB->get_record('local_kbacompany_offrec', ['companyuuid' => $c, 'uuid' => $second]);
        $dup = clone $row;
        unset($dup->id);
        $dup->uuid = \core\uuid::generate();
        $dup->createkey = \core\uuid::generate();
        $dup->regseq = 1;
        try {
            $DB->insert_record('local_kbacompany_offrec', $dup);
            $this->fail('Duplicate registration number accepted.');
        } catch (\dml_exception $e) {
            $this->assertInstanceOf(\dml_write_exception::class, $e);
        }
    }

    public function test_registration_requires_receipt_date_and_current_lockversion(): void {
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $registrar = (int)$s['ra']->id;
        $uuid = incoming::create($c, $registrar, \core\uuid::generate(), $this->data('No date', ['receivedon' => '']));
        $this->expect_error('officein_err_receivedonrequired', function() use ($c, $registrar, $uuid) {
            incoming::register($c, $registrar, $uuid, 0, \core\uuid::generate());
        });
        incoming::save($c, $registrar, $uuid, 0, $this->data('No date'));
        $this->expect_error('officein_err_conflict', function() use ($c, $registrar, $uuid) {
            incoming::register($c, $registrar, $uuid, 0, \core\uuid::generate());
        });
        $this->expect_error('officein_err_futuredate', function() use ($c, $registrar) {
            incoming::create($c, $registrar, \core\uuid::generate(),
                $this->data('Future', ['receivedon' => date('Y-m-d', time() + 3 * DAYSECS)]));
        });
        $this->expect_error('officein_err_subject', function() use ($c, $registrar) {
            incoming::create($c, $registrar, \core\uuid::generate(), $this->data(''));
        });
        $this->expect_error('officein_err_key', function() use ($c, $registrar) {
            incoming::create($c, $registrar, 'not-a-key', $this->data('Bad key'));
        });
    }

    public function test_visibility_and_two_company_isolation(): void {
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $own = incoming::create($c, (int)$s['aa']->id, \core\uuid::generate(), $this->data('Author A card'));
        $peer = incoming::create($c, (int)$s['ca']->id, \core\uuid::generate(), $this->data('Peer A card'));
        $foreign = incoming::create($s['b']['uuid'], (int)$s['rb']->id, \core\uuid::generate(), $this->data('Company B card'));

        $this->assertSame(2, incoming::browse($c, (int)$s['ra']->id)['total'], 'The registrar sees every company card.');
        $authorlist = incoming::browse($c, (int)$s['aa']->id);
        $this->assertSame([$own], array_column($authorlist['rows'], 'uuid'), 'An author sees own cards only.');
        $this->assertSame(0, incoming::browse($c, (int)$s['ea']->id)['total']);
        $this->assertSame(0, incoming::browse($c, (int)$s['ma']->id)['total']);
        $this->assertSame([$foreign], array_column(incoming::browse($s['b']['uuid'], (int)$s['rb']->id)['rows'], 'uuid'));

        foreach ([[(int)$s['aa']->id, $peer], [(int)$s['ea']->id, $own], [(int)$s['ma']->id, $own], [(int)$s['ra']->id, $foreign]]
                as [$actor, $uuid]) {
            $this->expect_error('officein_err_notfound', function() use ($c, $actor, $uuid) {
                incoming::card($c, $actor, $uuid);
            });
        }
        // A foreign card opened through the actor's own company is "not found"; through the foreign company it is refused.
        try {
            incoming::card($s['b']['uuid'], (int)$s['ra']->id, $foreign);
            $this->fail('A registrar of company A must not open company B.');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('officein_disabled', $e->errorcode);
        }
        $html = office_incoming_ui_service::list_page($c, incoming::browse($c, (int)$s['ra']->id), []);
        $this->assertStringContainsString('Author A card', $html);
        $this->assertStringNotContainsString('Company B card', $html);
    }

    public function test_original_is_immutable_attachments_and_protected_download(): void {
        global $DB;
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = (int)$s['aa']->id;
        $registrar = (int)$s['ra']->id;
        $uuid = incoming::create($c, $author, \core\uuid::generate(), $this->data('With original'));
        $original = $this->pdf('original.pdf');
        incoming::add_file($c, $author, $uuid, 0, 'original', $original);
        $this->expect_error('officein_err_originalset', function() use ($c, $author, $uuid) {
            incoming::add_file($c, $author, $uuid, 1, 'original', $this->pdf('replacement.pdf'));
        });
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        incoming::add_file($c, $author, $uuid, 1, 'attachment', office_upload::inspect($this->file('scan.png', $png), true));
        incoming::add_file($c, $author, $uuid, 2, 'attachment',
            office_upload::inspect($this->file('note.docx', $this->docx()), true));
        $card = incoming::card($c, $author, $uuid);
        $this->assertSame($original['sha256'], $card['original']['sha256']);
        $this->assertCount(2, $card['attachments']);
        $this->assertFalse($card['can']['original']);

        $before = incoming::card($c, $registrar, $uuid)['lockversion'];
        incoming::register($c, $registrar, $uuid, $before, \core\uuid::generate());
        $id = (int)$DB->get_field('local_kbacompany_offrec', 'id', ['companyuuid' => $c, 'uuid' => $uuid]);
        $file = incoming::file_for_download($c, $registrar, 'office_original', $id, $original['sha256'], 'original.pdf');
        $this->assertSame($original['bytes'], (int)$file->get_filesize());
        $this->assertSame(sha1_file($original['tmp']), $file->get_contenthash(), 'The original is unchanged after registration.');
        // Author keeps reading own card; others and another company never get the file, even with exact identifiers.
        incoming::file_for_download($c, $author, 'office_original', $id, $original['sha256'], 'original.pdf');
        foreach ([[$c, (int)$s['ca']->id], [$c, (int)$s['ea']->id], [$s['b']['uuid'], (int)$s['rb']->id]] as [$company, $actor]) {
            try {
                incoming::file_for_download($company, $actor, 'office_original', $id, $original['sha256'], 'original.pdf');
                $this->fail('Protected original was served.');
            } catch (\moodle_exception $e) {
                $this->assertSame('officein_err_notfound', $e->errorcode);
            }
        }
        $this->expect_error('officein_err_notfound', function() use ($c, $registrar, $id) {
            incoming::file_for_download($c, $registrar, 'office_original', $id, str_repeat('a', 64), 'original.pdf');
        });
        // After registration only a registrar adds files; the author cannot.
        $lock = incoming::card($c, $registrar, $uuid)['lockversion'];
        $this->expect_error('officein_err_denied', function() use ($c, $author, $uuid, $lock) {
            incoming::add_file($c, $author, $uuid, $lock, 'attachment', $this->pdf('late.pdf'));
        });
        incoming::add_file($c, $registrar, $uuid, $lock, 'attachment', $this->pdf('late.pdf'));
        $this->assertSame(['created', 'original', 'attachment', 'attachment', 'registered', 'attachment'],
            array_column(incoming::card($c, $registrar, $uuid)['journal'], 'action'));
    }

    public function test_upload_validation_rejects_disguised_macro_and_large_files(): void {
        $this->resetAfterTest();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $cases = [
            'officein_err_type' => [$this->file('disguised.pdf', $png), $this->file('script.exe', 'MZ'),
                $this->file('fake.png', "%PDF-1.4\n%%EOF\n"), $this->file('old.doc', 'x')],
            'officein_err_package' => [$this->file('macro.docx', $this->docx(true))],
            'officein_err_empty' => [$this->file('empty.pdf', '')],
            'officein_err_toolarge' => [$this->file('big.pdf', "%PDF-1.4\n" . str_repeat('0', office_upload::MAX_BYTES))],
        ];
        foreach ($cases as $code => $uploads) {
            foreach ($uploads as $upload) {
                $this->expect_error($code, function() use ($upload) { office_upload::inspect($upload, true); });
            }
        }
        $this->expect_error('officein_err_upload', function() {
            office_upload::inspect($this->file('letter.pdf', "%PDF-1.4\n%%EOF\n"));
        });
        $this->assertNull(office_upload::inspect(['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]));
    }

    public function test_correction_after_registration_needs_registrar_and_reason(): void {
        global $DB;
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = (int)$s['aa']->id;
        $registrar = (int)$s['ra']->id;
        $uuid = incoming::create($c, $author, \core\uuid::generate(), $this->data('Original subject'));
        incoming::save($c, $author, $uuid, 0, $this->data('Draft subject'));
        incoming::register($c, $registrar, $uuid, 1, \core\uuid::generate());
        $lock = incoming::card($c, $registrar, $uuid)['lockversion'];
        $this->expect_error('officein_err_denied', function() use ($c, $author, $uuid, $lock) {
            incoming::save($c, $author, $uuid, $lock, $this->data('Author change'), 'Because');
        });
        $this->expect_error('officein_err_reasonrequired', function() use ($c, $registrar, $uuid, $lock) {
            incoming::save($c, $registrar, $uuid, $lock, $this->data('Corrected subject'));
        });
        $this->assertSame(3, incoming::save($c, $registrar, $uuid, $lock,
            $this->data('Corrected subject', ['correspondent' => 'Corrected Sender']), 'Typo in the sender'));
        $card = incoming::card($c, $registrar, $uuid);
        $this->assertSame('Corrected subject', $card['data']['subject']);
        $this->assertSame('registered', $card['status']);
        $entry = end($card['journal']);
        $this->assertSame('corrected', $entry['action']);
        $this->assertSame(['subject' => 'Draft subject', 'correspondent' => 'Synthetic Sender LLC'], $entry['old']);
        $this->assertSame('Corrected subject', $entry['new']['subject']);
        $this->assertSame('Typo in the sender', $entry['new']['reason']);
        $revisions = $DB->get_records('local_kbacompany_offrev', ['companyuuid' => $c, 'recorduuid' => $uuid], 'revision ASC');
        $this->assertSame(['Original subject', 'Draft subject', 'Corrected subject'], array_values(array_column($revisions, 'subject')));
        $this->assertSame($card['regnumber'], (string)$DB->get_field('local_kbacompany_offrec', 'regnumber', ['uuid' => $uuid]));
        // Saving identical data creates no revision.
        $this->assertSame(3, incoming::save($c, $registrar, $uuid, $card['lockversion'],
            $this->data('Corrected subject', ['correspondent' => 'Corrected Sender']), 'Same'));
    }

    public function test_cancellation_keeps_number_and_never_reuses_it(): void {
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = (int)$s['aa']->id;
        $registrar = (int)$s['ra']->id;
        $year = (new \DateTimeImmutable('now', \core_date::get_server_timezone_object()))->format('Y');
        $draft = incoming::create($c, $author, \core\uuid::generate(), $this->data('Draft to cancel'));
        incoming::cancel($c, $author, $draft, 0, '');
        $this->assertSame('cancelled', incoming::card($c, $author, $draft)['status']);
        $this->expect_error('officein_err_state', function() use ($c, $registrar, $draft) {
            incoming::register($c, $registrar, $draft, 1, \core\uuid::generate());
        });
        $uuid = incoming::create($c, $author, \core\uuid::generate(), $this->data('Registered then cancelled'));
        incoming::register($c, $registrar, $uuid, 0, \core\uuid::generate());
        $this->expect_error('officein_err_denied', function() use ($c, $author, $uuid) {
            incoming::cancel($c, $author, $uuid, 1, 'Author wants it');
        });
        $this->expect_error('officein_err_reasonrequired', function() use ($c, $registrar, $uuid) {
            incoming::cancel($c, $registrar, $uuid, 1, '');
        });
        incoming::cancel($c, $registrar, $uuid, 1, 'Sent to us by mistake');
        $card = incoming::card($c, $registrar, $uuid);
        $this->assertSame('cancelled', $card['status']);
        $this->assertSame('Вх-' . $year . '/0001', $card['regnumber']);
        $this->assertSame('Sent to us by mistake', $card['cancelreason']);
        $next = incoming::create($c, $author, \core\uuid::generate(), $this->data('Next'));
        $this->assertSame('Вх-' . $year . '/0002', incoming::register($c, $registrar, $next, 0, \core\uuid::generate())['number']);
        $this->expect_error('officein_err_state', function() use ($c, $registrar, $uuid, $card) {
            incoming::save($c, $registrar, $uuid, $card['lockversion'], $this->data('After cancel'), 'Why');
        });
    }

    public function test_revoked_right_and_membership_lose_access(): void {
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = (int)$s['aa']->id;
        $uuid = incoming::create($c, $author, \core\uuid::generate(), $this->data('Before revocation'));
        $this->assertSame($uuid, incoming::card($c, $author, $uuid)['uuid']);
        access_role_service::assign($c, $s['admin'], $s['authora'], $author, false);
        $this->assertSame(['create' => false, 'register' => false], incoming::rights($c, $author));
        $this->expect_error('officein_err_notfound', function() use ($c, $author, $uuid) {
            incoming::card($c, $author, $uuid);
        });
        $this->assertSame(1, incoming::browse($c, (int)$s['ra']->id)['total'], 'The card itself stays in the register.');
        membership_access_service::change($c, (int)$s['ra']->id, $s['admin']);
        try {
            incoming::browse($c, (int)$s['ra']->id);
            $this->fail('A revoked member must not open the register.');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('officein_disabled', $e->errorcode);
        }
    }

    public function test_privacy_removes_identity_and_keeps_company_records(): void {
        global $DB;
        $s = $this->scenario();
        $c = $s['a']['uuid'];
        $author = $s['aa'];
        $uuid = incoming::create($c, (int)$author->id, \core\uuid::generate(), $this->data('Privacy card'));
        incoming::register($c, (int)$s['ra']->id, $uuid, 0, \core\uuid::generate());
        $contexts = \local_kbacompany\privacy\provider::get_contexts_for_userid((int)$author->id);
        $this->assertContains((int)\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        \local_kbacompany\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $author, 'local_kbacompany', [\context_system::instance()->id]));
        $row = $DB->get_record('local_kbacompany_offrec', ['companyuuid' => $c, 'uuid' => $uuid]);
        $this->assertSame(0, (int)$row->createdby);
        $this->assertNotEmpty($row->regnumber);
        $this->assertSame(0, $DB->count_records('local_kbacompany_offrev', ['createdby' => (int)$author->id]));
    }
}
