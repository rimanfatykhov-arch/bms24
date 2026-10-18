<?php
namespace local_bitrixsync\services;

defined('MOODLE_INTERNAL') || die();

class production_launch_service {
    /** @var deal_context_service */
    private $contexts;
    /** @var contact_service */
    private $contacts;
    /** @var multi_course_engine */
    private $engine;

    public function __construct(
        ?deal_context_service $contexts = null,
        ?contact_service $contacts = null,
        ?multi_course_engine $engine = null
    ) {
        $this->contexts = $contexts ?? new deal_context_service();
        $this->contacts = $contacts ?? new contact_service();
        $this->engine = $engine ?? new multi_course_engine();
    }

    public function controlled_deal_id(): int {
        $id = (int)get_config('local_bitrixsync', 'controlled_test_deal_id');
        return $id > 0 ? $id : 3761;
    }

    public function preflight(int $dealid, ?int $overrideuserid = null): array {
        $context = $this->contexts->resolve($dealid);
        $deal = $context['deal'];
        $stageid = (string)($deal['STAGE_ID'] ?? '');

        $user = [
            'status' => 'not_checked',
            'userid' => 0,
            'email' => '',
            'wouldcreate' => false,
            'created' => false,
        ];

        if ((int)$context['bitrixcontactid'] > 0) {
            $user = $this->contacts->resolve_user(
                (int)$context['bitrixcontactid'],
                $context['contact'],
                true,
                $overrideuserid
            );
        }

        $userid = (int)($user['userid'] ?? 0);
        $engine = $this->engine->process_deal(
            $dealid,
            $userid,
            true,
            true
        );

        $corporate = $engine['corporate'] ?? [];
        $corporatemode = (string)(get_config(
            'local_bitrixsync',
            'corporate_license_mode'
        ) ?: 'enforce');

        $crmwrite = (bool)get_config('local_bitrixsync', 'crm_writeback_enable');
        $crmready = true;
        if ($crmwrite) {
            $crmready = (new crm_field_manager())->is_ready(false);
        }

        $autocreate = (bool)get_config('local_bitrixsync', 'auto_create_users');
        $userready = $userid > 0 ||
            (!empty($user['wouldcreate']) && $autocreate);

        $productsready =
            (int)($engine['productrows'] ?? 0) > 0 &&
            (int)($engine['resolved'] ?? 0) === (int)($engine['productrows'] ?? 0) &&
            empty($engine['unmapped']) &&
            (int)($engine['failed'] ?? 0) === 0;

        $corporateready = true;
        if (!empty($context['iscorporate']) && $corporatemode === 'enforce') {
            $corporateready =
                !empty($corporate['enabled']) &&
                !empty($corporate['available']) &&
                !empty($corporate['compatible']) &&
                !empty($corporate['writesafe']) &&
                !empty($corporate['licenseavailable']);
        }

        $controlledid = $this->controlled_deal_id();
        $iscontrolled = $dealid === $controlledid;

        $stagefilter = new \local_bitrixsync\filters\deal_stage_filter();
        $stageallowed = $stagefilter->can_process($deal);

        $checks = [
            'controlled_deal' => [
                'ok' => $iscontrolled,
                'detail' => $iscontrolled
                    ? 'Controlled Deal ID matched'
                    : 'Only controlled Deal #' . $controlledid . ' may execute manually',
            ],
            'deal_stage' => [
                'ok' => $stageallowed,
                'detail' => $stageid !== '' ? $stageid : 'missing',
                'warning_only_for_manual_test' => true,
            ],
            'company' => [
                'ok' => (int)$context['bitrixcompanyid'] > 0,
                'detail' => (string)$context['companytitle'],
            ],
            'contact' => [
                'ok' => (int)$context['bitrixcontactid'] > 0,
                'detail' => (string)((int)$context['bitrixcontactid']),
            ],
            'moodle_user' => [
                'ok' => $userready,
                'detail' => $userid > 0
                    ? 'Moodle User #' . $userid
                    : (!empty($user['wouldcreate'])
                        ? 'Will create from Bitrix contact'
                        : 'Not resolved'),
            ],
            'products_courses' => [
                'ok' => $productsready,
                'detail' =>
                    (int)($engine['resolved'] ?? 0) . ' / ' .
                    (int)($engine['productrows'] ?? 0) . ' resolved',
            ],
            'corporate' => [
                'ok' => $corporateready,
                'detail' => $corporatemode,
            ],
            'crm_writeback' => [
                'ok' => $crmready,
                'detail' => $crmwrite
                    ? ($crmready ? 'Enabled / fields ready' : 'Enabled / fields NOT ready')
                    : 'Disabled for this configuration',
            ],
        ];

        $ready = true;
        foreach ($checks as $key => $check) {
            if ($key === 'deal_stage') {
                // Manual controlled run may intentionally validate a deal
                // before its stage is added to automatic production stages.
                continue;
            }
            if (empty($check['ok'])) {
                $ready = false;
                break;
            }
        }

        return [
            'dealid' => $dealid,
            'stageid' => $stageid,
            'stageallowed_automatic' => $stageallowed,
            'context' => [
                'companyid' => (int)$context['bitrixcompanyid'],
                'companytitle' => (string)$context['companytitle'],
                'contactid' => (int)$context['bitrixcontactid'],
            ],
            'user_resolution' => $user,
            'dry_plan' => $engine,
            'checks' => $checks,
            'ready_for_controlled_execute' => $ready,
        ];
    }

    public function dry_run(
        int $dealid,
        ?int $overrideuserid = null
    ): array {
        return (new deal_flow_service())->process(
            $dealid,
            true,
            true,
            $overrideuserid
        );
    }

    /**
     * REAL write path.
     *
     * This uses the already existing v5 production engine:
     * Contact -> Moodle user -> Corporate allocation -> Moodle enrolment
     * -> CRM writeback -> real local_bitrixsync_dealrun audit.
     */
    public function execute(
        int $dealid,
        string $confirmation,
        ?int $overrideuserid = null
    ): array {
        $controlledid = $this->controlled_deal_id();

        if ($dealid !== $controlledid) {
            throw new \moodle_exception(
                'production_controlled_deal_only',
                'local_bitrixsync',
                '',
                $controlledid
            );
        }

        $expected = 'EXECUTE_' . $dealid;
        if (!hash_equals($expected, trim($confirmation))) {
            throw new \moodle_exception(
                'production_confirmation_required',
                'local_bitrixsync',
                '',
                $expected
            );
        }

        $preflight = $this->preflight($dealid, $overrideuserid);
        if (empty($preflight['ready_for_controlled_execute'])) {
            throw new \moodle_exception(
                'production_preflight_failed',
                'local_bitrixsync'
            );
        }

        return (new deal_flow_service())->process(
            $dealid,
            false,
            true,
            $overrideuserid
        );
    }

    public function latest_real_run(int $dealid = 0) {
        global $DB;

        $params = ['dryrun' => 0];
        $where = 'dryrun = :dryrun';

        if ($dealid > 0) {
            $where .= ' AND dealid = :dealid';
            $params['dealid'] = $dealid;
        }

        return $DB->get_record_sql(
            "SELECT *
               FROM {local_bitrixsync_dealrun}
              WHERE {$where}
           ORDER BY timecreated DESC, id DESC",
            $params,
            IGNORE_MULTIPLE
        );
    }
}
