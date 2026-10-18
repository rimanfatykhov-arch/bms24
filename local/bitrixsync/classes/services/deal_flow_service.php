<?php
namespace local_bitrixsync\services;

use local_bitrixsync\repositories\deal_run_repository;

defined('MOODLE_INTERNAL') || die();

class deal_flow_service {
    /** @var deal_context_service */
    private $contextservice;
    /** @var contact_service */
    private $contacts;
    /** @var multi_course_engine */
    private $engine;
    /** @var deal_run_repository */
    private $runs;
    /** @var access_delivery_service */
    private $accessdelivery;

    public function __construct(
        ?deal_context_service $contextservice = null,
        ?contact_service $contacts = null,
        ?multi_course_engine $engine = null,
        ?deal_run_repository $runs = null,
        ?access_delivery_service $accessdelivery = null
    ) {
        $this->contextservice = $contextservice ?? new deal_context_service();
        $this->contacts = $contacts ?? new contact_service();
        $this->engine = $engine ?? new multi_course_engine();
        $this->runs = $runs ?? new deal_run_repository();
        $this->accessdelivery = $accessdelivery ?? new access_delivery_service();
    }

    /**
     * Complete v5 flow from a Bitrix deal.
     *
     * Automatic/queue traffic is stage-filtered.
     * A manually confirmed controlled admin test may use forceadmin=true.
     */
    public function process(
        int $dealid,
        bool $dryrun = true,
        bool $forceadmin = false,
        ?int $overrideuserid = null
    ): array {
        if ($dealid <= 0) {
            throw new \moodle_exception('invalid_deal_id', 'local_bitrixsync');
        }

        if (!$dryrun && !$forceadmin &&
                get_config('local_bitrixsync', 'engine_mode') !== 'v5') {
            throw new \moodle_exception('v5_engine_disabled', 'local_bitrixsync');
        }

        $context = $this->contextservice->resolve($dealid);

        // Restore the original business rule: only configured Bitrix stages
        // are processed automatically. Controlled admin tests are explicit
        // and may bypass this filter for the configured test deal.
        if (!$forceadmin) {
            $filter = new \local_bitrixsync\filters\deal_stage_filter();
            if (!$filter->can_process($context['deal'])) {
                return [
                    'dealid' => $dealid,
                    'dryrun' => $dryrun,
                    'skipped' => true,
                    'skipreason' => 'stage_not_allowed',
                    'stageid' => (string)($context['deal']['STAGE_ID'] ?? ''),
                    'context' => [
                        'bitrixcompanyid' => (int)$context['bitrixcompanyid'],
                        'companytitle' => (string)$context['companytitle'],
                        'bitrixcontactid' => (int)$context['bitrixcontactid'],
                        'iscorporate' => !empty($context['iscorporate']),
                    ],
                ];
            }
        }

        if ((int)$context['bitrixcontactid'] <= 0) {
            throw new \moodle_exception('deal_contact_missing', 'local_bitrixsync');
        }

        $userresolution = $this->contacts->resolve_user(
            (int)$context['bitrixcontactid'],
            $context['contact'],
            $dryrun,
            $overrideuserid
        );

        $userid = (int)($userresolution['userid'] ?? 0);

        if (!$dryrun && $userid <= 0) {
            if (empty($userresolution['wouldcreate'])) {
                throw new \moodle_exception(
                    'moodle_user_not_resolved',
                    'local_bitrixsync',
                    '',
                    (int)$context['bitrixcontactid']
                );
            }

            throw new \moodle_exception(
                'moodle_user_create_failed',
                'local_bitrixsync',
                '',
                (int)$context['bitrixcontactid']
            );
        }

        $runid = $this->runs->start(
            $dealid,
            $dryrun,
            (int)$context['bitrixcompanyid'],
            (int)$context['bitrixcontactid'],
            $userid
        );

        try {
            $result = $this->engine->process_deal(
                $dealid,
                $userid,
                $dryrun,
                $forceadmin
            );

            $result['user_resolution'] = [
                'status' => (string)($userresolution['status'] ?? ''),
                'userid' => $userid,
                'email' => (string)($userresolution['email'] ?? ''),
                'wouldcreate' => !empty($userresolution['wouldcreate']),
                'created' => !empty($userresolution['created']),
                'proposed' => $userresolution['proposed'] ?? null,
            ];

            $result['dealrunid'] = $runid;

            if (!$dryrun) {
                // IMPORTANT: the automatic queue path uses deal_flow_service.
                // Until v5.0.36.1.12 only multi_contact_deal_service invoked
                // access_delivery_service, so webhook-created Moodle accounts
                // received no password/email and no access timeline entry.
                try {
                    $result['access'] = $this->accessdelivery->deliver(
                        $dealid,
                        (int)$context['bitrixcontactid'],
                        (array)$context['contact'],
                        $userresolution,
                        $result
                    );
                } catch (\Throwable $accesserror) {
                    // Access delivery must not roll back enrolment.
                    $result['access'] = [
                        'status' => 'error',
                        'sent' => false,
                        'error' => $accesserror->getMessage(),
                    ];
                }

                try {
                    $crm = new crm_writeback_service();
                    $result['crm'] = $crm->sync(
                        $result,
                        $context,
                        $userresolution
                    );
                } catch (\Throwable $crmerror) {
                    // CRM writeback must never roll back a completed learning
                    // transaction. Surface the error in diagnostics/audit.
                    $result['crm'] = [
                        'enabled' => true,
                        'fields_ready' => false,
                        'errors' => [$crmerror->getMessage()],
                    ];
                }
            } else {
                $result['access'] = [
                    'status' => 'dryrun',
                    'sent' => false,
                ];
                $result['crm'] = [
                    'enabled' => false,
                    'dryrun' => true,
                ];
            }

            $this->runs->finish($runid, $result);
            return $result;
        } catch (\Throwable $e) {
            $this->runs->fail($runid, $e->getMessage());
            throw $e;
        }
    }
}
