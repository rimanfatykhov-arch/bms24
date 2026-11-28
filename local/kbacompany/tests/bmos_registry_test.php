<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_kbacompany\local\bmos_registry;
use local_kbacompany\local\business_date_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\membership_access_service;
use local_kbacompany\local\position_occupancy_service as occupancy;
use local_kbacompany\local\principal_context;
use local_kbacompany\local\principal_context_service;
use local_kbacompany\local\principal_grant_service as grants;
use local_kbacompany\local\principal_permission;
use local_kbacompany\local\principal_registry_service as registry;
use local_kbacompany\local\task_service;
use local_kbacompany\privacy\provider;
use local_kbacorporate\local\repository;

/**
 * BMOS-A1.1 acceptance at service level (A0_09 §3, A0 PASS 68b1dd0): S01.1, S02.1, S03.1, S04.1, S06.1, S08.1,
 * S10.1, S19.1, S24.1, S28.1, S29.1, S33.1, S35.1, S36.1. Synthetic data only.
 */
final class bmos_registry_test extends \advanced_testcase {
    private const TABLES = ['local_kbacompany_wprincipal', 'local_kbacompany_wpaudit', 'local_kbacompany_wpgrant',
        'local_kbacompany_wpdeleg', 'local_kbacompany_posoccup', 'local_kbacompany_posoccuplog'];

    private function member(int $companyid, string $name, int $manager): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => $name]);
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $companyid, 'userid' => $user->id,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => $manager, 'isdepartmentmanager' => 0,
            'ishr' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        return $user;
    }

    private function position(string $company, string $uuid, int $planned): string {
        global $DB;
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company,
            'branchuuid' => '20000000-0000-4000-8000-000000000002', 'orgnodeuuid' => '20000000-0000-4000-8000-000000000003',
            'uuid' => $uuid, 'code' => 'P-' . substr($uuid, -4), 'name' => 'Synthetic post', 'purpose' => 'Result',
            'ismanager' => 0, 'plannedcount' => $planned, 'sortorder' => 0, 'active' => 1, 'timecreated' => time(),
            'timemodified' => time()]);
        return $uuid;
    }

    private function fixture(bool $enabled = true): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        if ($enabled) {
            set_config('bmos_enabled', 1, 'local_kbacompany');
        }
        $cid = repository::save_company((object)['name' => 'Synthetic BMOS A', 'active' => 1]);
        $company = identity_registry::company_uuid($cid);
        $otherid = repository::save_company((object)['name' => 'Synthetic BMOS B', 'active' => 1]);
        $other = identity_registry::company_uuid($otherid);
        $f = ['company' => $company, 'cid' => $cid, 'other' => $other];
        foreach (['manager1' => 1, 'manager2' => 1, 'manager3' => 1, 'sponsor' => 0, 'supervisor' => 0,
                'employee' => 0] as $name => $manager) {
            $f[$name] = $this->member($cid, $name, $manager);
        }
        $f['othermanager'] = $this->member($otherid, 'othermanager', 1);
        $f['outsider'] = $this->getDataGenerator()->create_user(['firstname' => 'outsider']);
        $f['post'] = $this->position($company, '20000000-0000-4000-8000-000000000001', 2);
        $f['otherpost'] = $this->position($other, '20000000-0000-4000-8000-000000000009', 1);
        return $f;
    }

    private function as(\stdClass $user): int {
        $this->setUser($user);
        return (int)$user->id;
    }

    private function expect_error(string $code, callable $call): void {
        try {
            $call();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame('bmos_error_' . $code, $e->errorcode);
        }
    }

    private function revision(string $table, string $uuid): int {
        global $DB;
        return (int)$DB->get_field($table, 'revision', ['uuid' => $uuid]);
    }

    /** Registered and activated agent of company A. */
    private function agent(array $f): string {
        $uuid = registry::register_agent($f['company'], 'Synthetic analyst', (int)$f['sponsor']->id,
            $this->as($f['manager1']));
        registry::activate($f['company'], $uuid, 1, (int)$f['manager1']->id);
        return $uuid;
    }

    /** The site administrator holds every capability, including the separate grant authority (OD-A11-1 (a)). */
    private function granter(): int {
        $this->setAdminUser();
        return (int)get_admin()->id;
    }

    /** OD-A11-1 (a): assign the separate grant authority explicitly through a system role. */
    private function grant_authority(\stdClass $user): void {
        $this->setAdminUser();
        $system = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'bmosgrants' . $user->id]);
        assign_capability(bmos_registry::CAPABILITY_MANAGE_GRANTS, CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    private function grant_spec(array $extra = []): array {
        return $extra + ['command' => 'task.read', 'objecttype' => 'task', 'action' => 'view', 'scope' => 'own',
            'risklimit' => 'R0', 'expiresat' => time() + 30 * DAYSECS];
    }

    /** Approved and active agent-mode occupancy of the post; created by manager1, approved by manager2. */
    private function occupy(array $f, string $principal, string $mode = 'agent'): string {
        $uuid = occupancy::propose($f['company'], $principal, $f['post'], $mode,
            [['uuid' => '30000000-0000-4000-8000-000000000001', 'revision' => 1],
             ['uuid' => '30000000-0000-4000-8000-000000000002', 'revision' => 3]],
            ['maxopenitems' => 5, 'maxrunsperday' => 10, 'maxcommandsperhour' => 20], (int)$f['supervisor']->id,
            time() - 60, 0, $this->as($f['manager1']));
        occupancy::approve($f['company'], $uuid, 1, $this->as($f['manager2']));
        occupancy::activate($f['company'], $uuid, 2, (int)$f['manager2']->id);
        return $uuid;
    }

    /** Context ids as integers: the DB returns strings and PHPUnit 9 assertContains is strict. */
    private function context_ids(\stdClass $user): array {
        return array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids());
    }

    private function count_active_grants(string $principal): int {
        global $DB;
        return $DB->count_records('local_kbacompany_wpgrant', ['principaluuid' => $principal, 'status' => 'active']);
    }

    private function snapshot(array $tables): array {
        global $DB;
        $data = [];
        foreach ($tables as $table) {
            // Plain arrays: assertSame compares values, not object identity.
            $data[$table] = array_map(function($row) {
                return (array)$row;
            }, array_values($DB->get_records($table, null, 'id ASC')));
        }
        return $data;
    }

    // S28.1: install, flag off by default, behaviour as before BMOS.
    public function test_s28_install_flag_off_by_default_and_every_entry_point_is_closed(): void {
        global $DB;
        $f = $this->fixture(false);
        $this->assertSame('2026112800', (string)get_config('local_kbacompany', 'version'));
        $this->assertFalse(bmos_registry::enabled());
        $this->assertSame(0, (int)get_config('local_kbacompany', 'bmos_enabled'));
        $dbman = $DB->get_manager();
        foreach (self::TABLES as $table) {
            $this->assertTrue($dbman->table_exists($table), $table);
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $actor = $this->as($f['manager1']);
        $uuid = '40000000-0000-4000-8000-000000000001';
        foreach ([
            function() use ($f, $actor) { registry::register_agent($f['company'], 'Agent', (int)$f['sponsor']->id, $actor); },
            function() use ($f, $actor, $uuid) { registry::activate($f['company'], $uuid, 1, $actor); },
            function() use ($f, $actor, $uuid) { grants::grant($f['company'], $uuid, $this->grant_spec(), $actor); },
            function() use ($f, $actor, $uuid) { grants::revoke($f['company'], $uuid, $actor); },
            function() use ($f, $actor, $uuid) {
                occupancy::propose($f['company'], $uuid, $f['post'], 'agent', [], [], (int)$f['supervisor']->id, 0, 0,
                    $actor);
            },
            function() use ($f, $actor) { occupancy::occupancy_view($f['company'], $f['post'], $actor); },
            function() use ($f, $uuid) { principal_context_service::context($f['company'], $uuid); },
        ] as $call) {
            $this->expect_error('disabled', $call);
        }
        $context = new principal_context($f['company'], $uuid, 'ai_agent', (int)$f['sponsor']->id, 1, time());
        $this->assertFalse(principal_permission::can($context, 'task', 'view'));
        // The privacy path runs with the switch off too: every BMOS EXISTS predicate is valid SQL.
        $system = \context_system::instance();
        $this->assertInstanceOf(\core_privacy\local\request\contextlist::class,
            provider::get_contexts_for_userid((int)$f['sponsor']->id));
        writer::reset();
        provider::export_user_data(new approved_contextlist($f['sponsor'], 'local_kbacompany', [$system->id]));
        $this->assertFalse(writer::with_context($system)->has_any_data([get_string('bmos_privacy_registry',
            'local_kbacompany')]));
        provider::delete_data_for_user(new approved_contextlist($f['sponsor'], 'local_kbacompany', [$system->id]));
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        // The upgrade step installs exactly these tables from install.xml and records its savepoint once.
        $upgrade = file_get_contents(__DIR__ . '/../db/upgrade.php');
        $this->assertSame(1, substr_count($upgrade, 'if ($oldversion < 2026112800)'));
        $this->assertSame(1, substr_count($upgrade, "upgrade_plugin_savepoint(true, 2026112800, 'local', 'kbacompany')"));
        foreach (self::TABLES as $table) {
            $this->assertStringContainsString("'" . $table . "'", $upgrade);
        }
    }

    // S01.1: registration by an authorised human; without the right; activation without a current sponsor.
    public function test_s01_registration_requires_authority_and_a_current_sponsor(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = registry::register_agent($f['company'], 'Synthetic analyst', (int)$f['sponsor']->id,
            $this->as($f['manager1']));
        $row = $DB->get_record('local_kbacompany_wprincipal', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('draft', $row->status);
        $this->assertSame('ai_agent', $row->principaltype);
        $this->assertSame(0, (int)$row->userid);
        $this->assertSame(0, (int)$row->transportuserid);
        $this->assertSame((int)$f['manager1']->id, (int)$row->createdby);
        $this->assertSame(1, $DB->count_records('local_kbacompany_wpaudit', ['principaluuid' => $uuid]));
        foreach (['employee', 'sponsor', 'outsider', 'othermanager'] as $who) {
            $actor = $this->as($f[$who]);
            $this->expect_error('denied', function() use ($f, $actor) {
                registry::register_agent($f['company'], 'Agent', (int)$f['sponsor']->id, $actor);
            });
        }
        // The writer must be the logged-in user; a manager id passed by other code does not count.
        $this->as($f['employee']);
        $this->expect_error('denied', function() use ($f) {
            registry::register_agent($f['company'], 'Agent', (int)$f['sponsor']->id, (int)$f['manager1']->id);
        });
        $manager = $this->as($f['manager1']);
        $this->expect_error('sponsor', function() use ($f, $manager) {
            registry::register_agent($f['company'], 'Agent', (int)$f['outsider']->id, $manager);
        });
        $this->expect_error('invalid', function() use ($f, $manager) {
            registry::register_agent($f['company'], '   ', (int)$f['sponsor']->id, $manager);
        });
        // The sponsor loses company access (ADR-0006) before activation: activation fails closed.
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['sponsor']->id, get_admin()->id);
        $this->expect_error('sponsor', function() use ($f, $uuid) {
            registry::activate($f['company'], $uuid, 1, $this->as($f['manager1']));
        });
        $this->assertSame('draft', $DB->get_field('local_kbacompany_wprincipal', 'status', ['uuid' => $uuid]));
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['sponsor']->id, get_admin()->id, true);
        registry::activate($f['company'], $uuid, 1, $this->as($f['manager1']));
        $row = $DB->get_record('local_kbacompany_wprincipal', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('active', $row->status);
        $this->assertSame((int)$f['manager1']->id, (int)$row->approvedby);
        $this->expect_error('conflict', function() use ($f, $uuid) {
            registry::suspend($f['company'], $uuid, 1, (int)$f['manager1']->id);
        });
        registry::revoke($f['company'], $uuid, 2, 'Synthetic reason', (int)$f['manager1']->id);
        // Revoked is terminal: no reactivation; only archiving remains.
        $this->expect_error('state', function() use ($f, $uuid) {
            registry::activate($f['company'], $uuid, 3, (int)$f['manager1']->id);
        });
        registry::archive($f['company'], $uuid, 3, (int)$f['manager1']->id);
        $this->assertSame([1, 2, 3, 4], array_map('intval', $DB->get_fieldset_select('local_kbacompany_wpaudit',
            'revision', 'principaluuid = ? ORDER BY revision', [$uuid])));
    }

    // S04.1: an ai_agent principal is not a Moodle user.
    public function test_s04_agent_principal_has_no_moodle_identity(): void {
        global $DB;
        $f = $this->fixture();
        $users = $DB->count_records('user');
        $uuid = $this->agent($f);
        $this->assertSame($users, $DB->count_records('user'));
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertSame($uuid, $context->principaluuid());
        $this->assertSame('ai_agent', $context->principaltype());
        $properties = array_map(function(\ReflectionProperty $p) { return $p->getName(); },
            (new \ReflectionClass(principal_context::class))->getProperties());
        foreach ($properties as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(userid|token|session|sesskey|password)$/', $name);
        }
        $parameters = array_map(function(\ReflectionParameter $p) { return $p->getName(); },
            (new \ReflectionMethod(principal_context_service::class, 'context'))->getParameters());
        $this->assertSame(['companyuuid', 'principaluuid'], $parameters);
        // A hand-made context with a Moodle user as the principal id is not a principal.
        $fake = new principal_context($f['company'], (string)$f['sponsor']->id, 'ai_agent', (int)$f['sponsor']->id, 1,
            time());
        $this->assertFalse(principal_permission::can($fake, 'task', 'view'));
        $row = $DB->get_record('local_kbacompany_wprincipal', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$row->userid);
        $this->assertSame('', $row->memberuuid);
        $this->assertFalse($DB->record_exists('local_kbacorp_member', ['userid' => 0]));
    }

    // S03.1: company boundary for principal, grant, occupancy and object; no existence disclosure.
    public function test_s03_company_isolation_without_existence_disclosure(): void {
        $f = $this->fixture();
        $uuid = $this->agent($f);
        $grant = grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $occupancy = $this->occupy($f, $uuid);
        $missing = '40000000-0000-4000-8000-00000000abcd';
        // Same error for a foreign principal and a principal that does not exist.
        foreach ([$uuid, $missing, 'not-a-uuid'] as $ref) {
            $this->expect_error('notavailable', function() use ($f, $ref) {
                principal_context_service::context($f['other'], $ref);
            });
        }
        // Even the grant authority cannot reach an A principal through the B company.
        $admin = $this->granter();
        $this->expect_error('notavailable', function() use ($f, $uuid, $admin) {
            grants::grant($f['other'], $uuid, $this->grant_spec(), $admin);
        });
        $other = $this->as($f['othermanager']);
        foreach ([
            function() use ($f, $grant, $other) { grants::revoke($f['other'], $grant, $other); },
            function() use ($f, $occupancy, $other) { occupancy::suspend($f['other'], $occupancy, 3, $other); },
            function() use ($f, $uuid, $other) { registry::suspend($f['other'], $uuid, 2, $other); },
            function() use ($f, $uuid, $other) {
                occupancy::propose($f['other'], $uuid, $f['otherpost'], 'agent', [], [], (int)$f['othermanager']->id,
                    0, 0, $other);
            },
        ] as $call) {
            $this->expect_error('notavailable', $call);
        }
        // A manager of company B has no authority in company A.
        $this->expect_error('denied', function() use ($f, $grant, $other) { grants::revoke($f['company'], $grant, $other); });
        // A post of company B is not available to an occupancy in company A.
        $manager = $this->as($f['manager1']);
        $this->expect_error('notavailable', function() use ($f, $uuid, $manager) {
            occupancy::propose($f['company'], $uuid, $f['otherpost'], 'agent', [], [], (int)$f['supervisor']->id, 0, 0,
                $manager);
        });
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertTrue(principal_permission::can($context, 'task', 'view'));
        $this->assertFalse(principal_permission::can($context, 'task', 'view',
            ['companyuuid' => $f['other'], 'uuid' => $missing, 'ownerprincipaluuid' => $uuid]));
        $this->assertTrue(principal_permission::can($context, 'task', 'view',
            ['companyuuid' => $f['company'], 'uuid' => $missing, 'ownerprincipaluuid' => $uuid,
             'positionuuid' => $f['post']]));
        // A context naming company B for an A principal is not a context.
        $forged = new principal_context($f['other'], $uuid, 'ai_agent', (int)$f['sponsor']->id,
            $context->authzrevision(), time());
        $this->assertFalse(principal_permission::can($forged, 'task', 'view'));
    }

    // S06.1: only an authorised human writes grants and occupancy; a principal has no write path.
    public function test_s06_only_an_authorised_human_writes_registries(): void {
        $f = $this->fixture();
        $uuid = $this->agent($f);
        foreach (['employee', 'sponsor', 'supervisor', 'outsider'] as $who) {
            $actor = $this->as($f[$who]);
            $this->expect_error('denied', function() use ($f, $uuid, $actor) {
                grants::grant($f['company'], $uuid, $this->grant_spec(), $actor);
            });
            $this->expect_error('denied', function() use ($f, $uuid, $actor) {
                occupancy::propose($f['company'], $uuid, $f['post'], 'agent', [], [], (int)$f['supervisor']->id, 0, 0,
                    $actor);
            });
        }
        $this->as($f['manager1']);
        $this->expect_error('denied', function() use ($f, $uuid) {
            grants::grant($f['company'], $uuid, $this->grant_spec(), 0);
        });
        // Organisation rights are not grant rights: manager1 can manage the organisation but cannot issue or renew.
        $manager = (int)$f['manager1']->id;
        $this->assertTrue(\local_kbacompany\local\permission_service::can_manage_organization($f['company'], $manager));
        $this->expect_error('grantauthority', function() use ($f, $uuid, $manager) {
            grants::grant($f['company'], $uuid, $this->grant_spec(), $manager);
        });
        $grant = grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $manager = $this->as($f['manager1']);
        $this->expect_error('grantauthority', function() use ($f, $grant, $manager) {
            grants::extend($f['company'], $grant, time() + 40 * DAYSECS, $manager);
        });
        // Removing a right never escalates: an organisation manager may revoke.
        grants::revoke($f['company'], $grant, $manager);
        $this->assertSame(0, $this->count_active_grants($uuid));
        // OD-A11-1 (a): the capability has no archetype; held alone it is not enough, with org rights it is.
        global $DB;
        $this->assertNotEmpty(get_capability_info(bmos_registry::CAPABILITY_MANAGE_GRANTS));
        $this->assertFalse($DB->record_exists('role_capabilities',
            ['capability' => bmos_registry::CAPABILITY_MANAGE_GRANTS]));
        $this->grant_authority($f['employee']);
        $employee = $this->as($f['employee']);
        $this->assertFalse(\local_kbacompany\local\permission_service::can_manage_organization($f['company'], $employee));
        $this->expect_error('denied', function() use ($f, $uuid, $employee) {
            grants::grant($f['company'], $uuid, $this->grant_spec(), $employee);
        });
        $this->grant_authority($f['manager3']);
        $holder = $this->as($f['manager3']);
        $issued = grants::grant($f['company'], $uuid, $this->grant_spec(), $holder);
        $this->assertSame(1, $this->count_active_grants($uuid));
        grants::extend($f['company'], $issued, time() + 40 * DAYSECS, $holder);
        $this->assertSame(1, $this->count_active_grants($uuid));
        // The capability is not a company right: it does not open another company's registry.
        $this->expect_error('denied', function() use ($f, $uuid, $holder) {
            grants::grant($f['other'], $uuid, $this->grant_spec(), $holder);
        });
        // No public registry method accepts a principal context: principals cannot be writers.
        foreach ([registry::class, grants::class, occupancy::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $this->assertFalse($type && $type->getName() === principal_context::class,
                        $class . '::' . $method->getName());
                }
            }
        }
    }

    // S36.1: deny by default, unknown pair denied, positive control passes.
    public function test_s36_deny_by_default_with_positive_control(): void {
        $f = $this->fixture();
        $uuid = $this->agent($f);
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertFalse(principal_permission::can($context, 'task', 'view'));
        $manager = $this->granter();
        grants::grant($f['company'], $uuid, $this->grant_spec(), $manager);
        // The grant moved authzrevision: the earlier context is stale and denies until rebuilt.
        $this->assertFalse(principal_permission::can($context, 'task', 'view'));
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertTrue(principal_permission::can($context, 'task', 'view'), 'positive control');
        $this->assertFalse(principal_permission::can($context, 'task', 'edit'));
        $this->assertFalse(principal_permission::can($context, 'indicator', 'view'));
        $this->assertFalse(principal_permission::can($context, 'agent', 'view'));
        $this->assertFalse(principal_permission::can($context, 'daily_report', 'view'));
        $this->assertFalse(principal_permission::can($context, 'task', 'run'));
        // own: positive control on an allowed resource, negatives for another owner and a personal task.
        $owned = ['companyuuid' => $f['company'], 'uuid' => '40000000-0000-4000-8000-000000000002',
            'ownerprincipaluuid' => $uuid, 'positionuuid' => $f['post']];
        $this->assertTrue(principal_permission::can($context, 'task', 'view', $owned), 'own positive control');
        $this->assertFalse(principal_permission::can($context, 'task', 'view', ['ownerprincipaluuid' => ''] + $owned));
        $this->assertFalse(principal_permission::can($context, 'task', 'view', ['positionuuid' => ''] + $owned),
            'own personal task');
        $this->assertFalse(principal_permission::can($context, 'task', 'view', ['personal' => true] + $owned));
        // Grants outside the A1 contract are not issued.
        foreach ([['scope' => 'delegated_team'], ['scope' => 'department'], ['scope' => 'company'],
                ['risklimit' => 'R4'], ['objecttype' => 'agent'], ['action' => 'run'], ['command' => 'Task Read'],
                ['scope' => 'explicit_object', 'objects' => []], ['scope' => 'position', 'occupancyuuid' => 'x']] as $bad) {
            $this->expect_error('invalid', function() use ($f, $uuid, $manager, $bad) {
                grants::grant($f['company'], $uuid, $bad + $this->grant_spec(), $manager);
            });
        }
        // explicit_object: only the listed objects.
        $listed = '50000000-0000-4000-8000-000000000001';
        grants::grant($f['company'], $uuid, $this->grant_spec(['scope' => 'explicit_object', 'action' => 'comment',
            'objects' => [strtoupper($listed)]]), $manager);
        $context = principal_context_service::context($f['company'], $uuid);
        $object = ['companyuuid' => $f['company'], 'uuid' => $listed, 'positionuuid' => $f['post']];
        $this->assertTrue(principal_permission::can($context, 'task', 'comment', $object), 'explicit positive control');
        $this->assertFalse(principal_permission::can($context, 'task', 'comment',
            ['uuid' => '50000000-0000-4000-8000-000000000002'] + $object));
        // A personal task (no duty position) is denied even when it is listed explicitly.
        $this->assertFalse(principal_permission::can($context, 'task', 'comment', ['positionuuid' => ''] + $object),
            'explicit personal task');
        $this->assertFalse(principal_permission::can($context, 'task', 'comment',
            ['companyuuid' => $f['company'], 'uuid' => $listed]), 'explicit task without a duty position');
    }

    // S08.1: revocation of grant, occupancy, principal and sponsor membership.
    public function test_s08_revocation_denies_on_the_next_context_and_check(): void {
        $f = $this->fixture();
        $uuid = $this->agent($f);
        $own = grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $occupancyuuid = $this->occupy($f, $uuid);
        grants::grant($f['company'], $uuid, $this->grant_spec(['scope' => 'position', 'action' => 'comment',
            'occupancyuuid' => $occupancyuuid]), $this->granter());
        $manager = $this->as($f['manager1']);
        $context = principal_context_service::context($f['company'], $uuid);
        $object = ['companyuuid' => $f['company'], 'uuid' => '60000000-0000-4000-8000-000000000001',
            'positionuuid' => $f['post']];
        $this->assertTrue(principal_permission::can($context, 'task', 'view'));
        $this->assertTrue(principal_permission::can($context, 'task', 'comment', $object));
        $this->assertFalse(principal_permission::can($context, 'task', 'comment', ['positionuuid' => ''] + $object),
            'personal object');
        grants::revoke($f['company'], $own, $manager);
        $this->assertFalse(principal_permission::can($context, 'task', 'view'), 'stale context');
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertFalse(principal_permission::can($context, 'task', 'view'), 'revoked grant');
        $this->assertTrue(principal_permission::can($context, 'task', 'comment', $object));
        occupancy::end($f['company'], $occupancyuuid, 3, $manager);
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertFalse(principal_permission::can($context, 'task', 'comment', $object), 'ended occupancy');
        // Sponsor membership revoked (ADR-0006): the next context build fails closed.
        grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertTrue(principal_permission::can($context, 'task', 'view'));
        $this->setAdminUser();
        membership_access_service::change($f['company'], (int)$f['sponsor']->id, get_admin()->id);
        $this->assertFalse(principal_permission::can($context, 'task', 'view'), 'sponsor revoked');
        $this->expect_error('notavailable', function() use ($f, $uuid) {
            principal_context_service::context($f['company'], $uuid);
        });
        membership_access_service::change($f['company'], (int)$f['sponsor']->id, get_admin()->id, true);
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertTrue(principal_permission::can($context, 'task', 'view'));
        registry::suspend($f['company'], $uuid, $this->revision('local_kbacompany_wprincipal', $uuid),
            $this->as($f['manager1']));
        $this->assertFalse(principal_permission::can($context, 'task', 'view'), 'suspended principal');
        $this->expect_error('notavailable', function() use ($f, $uuid) {
            principal_context_service::context($f['company'], $uuid);
        });
    }

    // S24.1: grant expiry (<= 90 days) and occupancy end date; renewal only by a human as a new revision.
    public function test_s24_expiry_and_human_renewal_as_a_new_revision(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = $this->agent($f);
        $manager = $this->granter();
        foreach ([time() - 1, time() + 91 * DAYSECS] as $expires) {
            $this->expect_error('invalid', function() use ($f, $uuid, $manager, $expires) {
                grants::grant($f['company'], $uuid, $this->grant_spec(['expiresat' => $expires]), $manager);
            });
        }
        $grant = grants::grant($f['company'], $uuid, $this->grant_spec(['expiresat' => time() + 10 * DAYSECS]), $manager);
        $employee = $this->as($f['employee']);
        $this->expect_error('denied', function() use ($f, $grant, $employee) {
            grants::extend($f['company'], $grant, time() + 20 * DAYSECS, $employee);
        });
        $orgmanager = $this->as($f['manager2']);
        $this->expect_error('grantauthority', function() use ($f, $grant, $orgmanager) {
            grants::extend($f['company'], $grant, time() + 20 * DAYSECS, $orgmanager);
        });
        $manager = $this->granter();
        $this->expect_error('invalid', function() use ($f, $grant, $manager) {
            grants::extend($f['company'], $grant, time() + 91 * DAYSECS, $manager);
        });
        $renewed = grants::extend($f['company'], $grant, time() + 60 * DAYSECS, $manager);
        $old = $DB->get_record('local_kbacompany_wpgrant', ['uuid' => $grant], '*', MUST_EXIST);
        $new = $DB->get_record('local_kbacompany_wpgrant', ['uuid' => $renewed], '*', MUST_EXIST);
        $this->assertSame('superseded', $old->status);
        $this->assertSame('active', $new->status);
        $this->assertSame(2, (int)$new->revision);
        $this->assertSame($grant, $new->supersedesuuid);
        $this->assertSame($manager, (int)$new->grantedby);
        $context = principal_context_service::context($f['company'], $uuid);
        $this->assertTrue(principal_permission::can($context, 'task', 'view'));
        // Time passes the expiry: the same now of the check denies, and an expired grant is never renewed.
        $DB->set_field('local_kbacompany_wpgrant', 'expiresat', time() - 1, ['uuid' => $renewed]);
        $this->assertFalse(principal_permission::can($context, 'task', 'view'), 'expired grant');
        $this->expect_error('state', function() use ($f, $renewed, $manager) {
            grants::extend($f['company'], $renewed, time() + 30 * DAYSECS, $manager);
        });
        $this->expect_error('state', function() use ($f, $grant, $manager) {
            grants::extend($f['company'], $grant, time() + 30 * DAYSECS, $manager);
        });
        // Occupancy end date in the past stops the position grant.
        $occupancyuuid = $this->occupy($f, $uuid);
        grants::grant($f['company'], $uuid, $this->grant_spec(['scope' => 'position',
            'occupancyuuid' => $occupancyuuid]), $this->granter());
        $context = principal_context_service::context($f['company'], $uuid);
        $object = ['companyuuid' => $f['company'], 'uuid' => '60000000-0000-4000-8000-000000000002',
            'positionuuid' => $f['post']];
        $this->assertTrue(principal_permission::can($context, 'task', 'view', $object));
        $DB->set_field('local_kbacompany_posoccup', 'effectiveto', time() - 1, ['uuid' => $occupancyuuid]);
        $this->assertFalse(principal_permission::can($context, 'task', 'view', $object), 'occupancy ended by date');
    }

    // S02.1, S10.1, S33.1: occupancy and OccupancyView leave people, tasks, coverage and history untouched.
    public function test_s02_s10_s33_occupancy_keeps_people_tasks_and_coverage_unchanged(): void {
        global $DB;
        $f = $this->fixture();
        $this->setAdminUser();
        task_service::create($f['company'], (int)get_admin()->id, ['title' => 'Synthetic task',
            'product' => 'Verified result', 'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
            'observerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => business_date_service::day(), 'duedate' => business_date_service::day(), 'planminutes' => 30]);
        $tables = ['local_kbacompany_position', 'local_kbacompany_posassign', 'local_kbacompany_poscover',
            'local_kbacompany_task', 'local_kbacorp_member'];
        $before = $this->snapshot($tables);
        $uuid = $this->agent($f);
        $occupancyuuid = $this->occupy($f, $uuid);
        $row = $DB->get_record('local_kbacompany_posoccup', ['uuid' => $occupancyuuid], '*', MUST_EXIST);
        $this->assertSame('active', $row->status);
        $this->assertSame((int)$f['manager2']->id, (int)$row->approvedby);
        $this->assertSame([1, 2, 3], array_map('intval', $DB->get_fieldset_select('local_kbacompany_posoccuplog',
            'revision', 'occupancyuuid = ? ORDER BY revision', [$occupancyuuid])));
        $view = occupancy::occupancy_view($f['company'], $f['post'], $this->as($f['manager1']));
        $this->assertSame(['plannedcount' => 2, 'userids' => [], 'vacant' => 2], $view['people']);
        $this->assertSame(2, $view['agentcoverage']['functions']);
        $this->assertSame($uuid, $view['agentcoverage']['occupancies'][0]['principaluuid']);
        $this->assertSame($before, $this->snapshot($tables));
        occupancy::end($f['company'], $occupancyuuid, 3, (int)$f['manager1']->id);
        $view = occupancy::occupancy_view($f['company'], $f['post'], (int)$f['manager1']->id);
        $this->assertSame(0, $view['agentcoverage']['functions']);
        $this->assertSame(2, $view['people']['vacant']);
        $this->assertSame($before, $this->snapshot($tables));
        // Hybrid needs a person on the post; the occupancy still does not count as a person.
        $manager = (int)$f['manager1']->id;
        $this->expect_error('state', function() use ($f, $uuid, $manager) {
            occupancy::propose($f['company'], $uuid, $f['post'], 'hybrid', [], [], 0, 0, 0, $manager);
        });
        $DB->insert_record('local_kbacompany_posassign', (object)['companyuuid' => $f['company'],
            'positionuuid' => $f['post'], 'slotuuid' => '', 'userid' => (int)$f['employee']->id,
            'assignmenttype' => 'primary', 'effectivefrom' => time() - 60, 'effectiveto' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        $this->occupy($f, $uuid, 'hybrid');
        $view = occupancy::occupancy_view($f['company'], $f['post'], $this->as($f['manager1']));
        $this->assertSame(['plannedcount' => 2, 'userids' => [(int)$f['employee']->id], 'vacant' => 1], $view['people']);
        $this->assertSame('hybrid', $view['agentcoverage']['occupancies'][0]['mode']);
    }

    // S35.1: two persons for an agent-mode occupancy.
    public function test_s35_agent_mode_occupancy_needs_a_second_person(): void {
        global $DB;
        $f = $this->fixture();
        // The sponsor is a manager here, to prove the sponsor cannot approve either.
        $uuid = registry::register_agent($f['company'], 'Synthetic analyst', (int)$f['manager2']->id,
            $this->as($f['manager1']));
        registry::activate($f['company'], $uuid, 1, (int)$f['manager1']->id);
        $occupancyuuid = occupancy::propose($f['company'], $uuid, $f['post'], 'agent', [], [],
            (int)$f['supervisor']->id, 0, 0, (int)$f['manager1']->id);
        $this->expect_error('twoperson', function() use ($f, $occupancyuuid) {
            occupancy::approve($f['company'], $occupancyuuid, 1, $this->as($f['manager1']));
        });
        $this->expect_error('twoperson', function() use ($f, $occupancyuuid) {
            occupancy::approve($f['company'], $occupancyuuid, 1, $this->as($f['manager2']));
        });
        $this->expect_error('denied', function() use ($f, $occupancyuuid) {
            occupancy::approve($f['company'], $occupancyuuid, 1, $this->as($f['supervisor']));
        });
        $this->assertSame('proposed', $DB->get_field('local_kbacompany_posoccup', 'status', ['uuid' => $occupancyuuid]));
        occupancy::approve($f['company'], $occupancyuuid, 1, $this->as($f['manager3']));
        $this->assertSame((int)$f['manager3']->id,
            (int)$DB->get_field('local_kbacompany_posoccup', 'approvedby', ['uuid' => $occupancyuuid]));
        // Agent mode without a supervising member is not proposed.
        $manager = $this->as($f['manager1']);
        $this->expect_error('sponsor', function() use ($f, $uuid, $manager) {
            occupancy::propose($f['company'], $uuid, $f['post'], 'agent', [], [], 0, 0, 0, $manager);
        });
    }

    // S19.1: no secret fields in the new tables or their journals.
    public function test_s19_registries_have_no_secret_fields(): void {
        global $DB;
        $xml = simplexml_load_file(__DIR__ . '/../db/install.xml');
        $found = [];
        foreach ($xml->TABLES->TABLE as $table) {
            if (!in_array((string)$table['NAME'], self::TABLES, true)) {
                continue;
            }
            $found[] = (string)$table['NAME'];
            foreach ($table->FIELDS->FIELD as $field) {
                $this->assertDoesNotMatchRegularExpression('/secret|token|password|passwd|apikey|privatekey/i',
                    (string)$field['NAME'], (string)$table['NAME']);
            }
        }
        $this->assertSame(self::TABLES, $found);
        $f = $this->fixture();
        $uuid = $this->agent($f);
        grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $this->occupy($f, $uuid);
        $row = $DB->get_record('local_kbacompany_wprincipal', ['uuid' => $uuid], '*', MUST_EXIST);
        $this->assertSame('', $row->credentialref);
        $this->assertSame('', $row->credfingerprint);
        foreach (['local_kbacompany_wpaudit', 'local_kbacompany_posoccuplog'] as $table) {
            foreach ($DB->get_fieldset_select($table, 'details', '1 = 1') as $details) {
                $this->assertDoesNotMatchRegularExpression('/secret|token|password|apikey/i', (string)$details);
            }
        }
    }

    // S29.1: privacy export and erasure for sponsor, grantor and approver.
    public function test_s29_privacy_export_and_erasure_by_person(): void {
        global $DB;
        $f = $this->fixture();
        $uuid = $this->agent($f);
        $grant = grants::grant($f['company'], $uuid, $this->grant_spec(), $this->granter());
        $occupancyuuid = $this->occupy($f, $uuid);
        $system = \context_system::instance();
        foreach (['sponsor', 'manager1', 'manager2', 'supervisor'] as $who) {
            $this->assertContains((int)$system->id, $this->context_ids($f[$who]), $who);
        }
        writer::reset();
        provider::export_user_data(new approved_contextlist($f['sponsor'], 'local_kbacompany', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('bmos_privacy_registry', 'local_kbacompany')]);
        $this->assertSame($uuid, $data->local_kbacompany_wprincipal[0]->uuid);
        writer::reset();
        provider::export_user_data(new approved_contextlist($f['manager2'], 'local_kbacompany', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('bmos_privacy_registry', 'local_kbacompany')]);
        $this->assertSame($occupancyuuid, $data->local_kbacompany_posoccup[0]->uuid);
        $revision = (int)$DB->get_field('local_kbacompany_wprincipal', 'authzrevision', ['uuid' => $uuid]);
        $this->setAdminUser();
        provider::delete_data_for_user(new approved_contextlist($f['manager1'], 'local_kbacompany', [$system->id]));
        provider::delete_data_for_user(new approved_contextlist($f['sponsor'], 'local_kbacompany', [$system->id]));
        $admin = (int)get_admin()->id;
        $this->assertSame($admin, (int)$DB->get_field('local_kbacompany_wpgrant', 'grantedby', ['uuid' => $grant]));
        provider::delete_data_for_user(new approved_contextlist(get_admin(), 'local_kbacompany', [$system->id]));
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_wpgrant', 'grantedby', ['uuid' => $grant]));
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_wprincipal', 'createdby', ['uuid' => $uuid]));
        $this->assertSame(0, (int)$DB->get_field('local_kbacompany_wprincipal', 'sponsoruserid', ['uuid' => $uuid]));
        $this->assertGreaterThan($revision,
            (int)$DB->get_field('local_kbacompany_wprincipal', 'authzrevision', ['uuid' => $uuid]));
        $this->assertSame(0, $DB->count_records('local_kbacompany_wpaudit', ['actoruserid' => (int)$f['manager1']->id]));
        $this->assertSame(0, $DB->count_records_select('local_kbacompany_posoccup', 'createdby = ?',
            [(int)$f['manager1']->id]));
        $this->assertNotContains((int)$system->id, $this->context_ids($f['manager1']));
        // Without its sponsor the agent fails closed.
        $this->expect_error('notavailable', function() use ($f, $uuid) {
            principal_context_service::context($f['company'], $uuid);
        });
        $this->assertSame((int)$f['manager2']->id,
            (int)$DB->get_field('local_kbacompany_posoccup', 'approvedby', ['uuid' => $occupancyuuid]));
        provider::delete_data_for_all_users_in_context($system);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }
}
