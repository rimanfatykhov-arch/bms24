<?php
namespace local_bitrixsync\services;

use local_bitrixsync\repositories\enrollment_repository;

defined('MOODLE_INTERNAL') || die();

class multi_course_engine {
    /** @var product_row_resolver */
    private $productrows;
    /** @var course_resolver */
    private $courses;
    /** @var enrollment_repository */
    private $enrollments;
    /** @var moodle_enroller */
    private $enroller;
    /** @var deal_context_service */
    private $dealcontext;
    /** @var corporate_bridge */
    private $corporate;
    /** @var company_service */
    private $companies;
    /** @var contact_service */
    private $contacts;

    public function __construct(
        ?product_row_resolver $productrows = null,
        ?course_resolver $courses = null,
        ?enrollment_repository $enrollments = null,
        ?moodle_enroller $enroller = null,
        ?deal_context_service $dealcontext = null,
        ?corporate_bridge $corporate = null,
        ?company_service $companies = null,
        ?contact_service $contacts = null
    ) {
        $this->productrows = $productrows ?? new product_row_resolver();
        $this->courses = $courses ?? new course_resolver();
        $this->enrollments = $enrollments ?? new enrollment_repository();
        $this->enroller = $enroller ?? new moodle_enroller();
        $this->dealcontext = $dealcontext ?? new deal_context_service();
        $this->corporate = $corporate ?? new corporate_bridge();
        $this->companies = $companies ?? new company_service();
        $this->contacts = $contacts ?? new contact_service();
    }

    /**
     * Resolve and optionally enrol all mapped products from a Bitrix24 deal.
     *
     * Corporate deals (Deal.COMPANY_ID > 0) can enforce KBA Corporate Dashboard
     * v0.13 / Public API v1.9 seat allocation before Moodle enrolment.
     */
    public function process_deal(int $dealid, int $userid, bool $dryrun = true, bool $force = false, ?array $contextoverride = null): array {
        global $DB;

        if (!$dryrun && !$force && get_config('local_bitrixsync', 'engine_mode') !== 'v5') {
            throw new \moodle_exception('v5_engine_disabled', 'local_bitrixsync');
        }

        if ($userid > 0) {
            $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);
        } else if (!$dryrun) {
            throw new \moodle_exception('moodle_user_not_resolved', 'local_bitrixsync', '', 0);
        }

        $context = $contextoverride ?? $this->dealcontext->resolve($dealid);
        $rows = $this->productrows->resolve_products($dealid);

        if (!get_config('local_bitrixsync', 'multi_course') && count($rows) > 1) {
            $rows = array_slice($rows, 0, 1);
        }
        $corporatemode = (string)(get_config('local_bitrixsync', 'corporate_license_mode') ?: 'enforce');
        $corporatediagnostic = $this->corporate->diagnostic();

        $result = [
            'dealid' => $dealid,
            'userid' => $userid,
            'dryrun' => $dryrun,
            'productrows' => count($rows),
            'resolved' => 0,
            'enrolled' => 0,
            'already' => 0,
            'failed' => 0,
            'unmapped' => [],
            'items' => [],
            'context' => [
                'bitrixcompanyid' => (int)$context['bitrixcompanyid'],
                'companytitle' => (string)$context['companytitle'],
                'bitrixcontactid' => (int)$context['bitrixcontactid'],
                'iscorporate' => !empty($context['iscorporate']),
            ],
            'corporate' => [
                'mode' => $corporatemode,
                'enabled' => !empty($corporatediagnostic['enabled']),
                'available' => !empty($corporatediagnostic['available']),
                'compatible' => !empty($corporatediagnostic['compatible']),
                'api_release' => (string)($corporatediagnostic['contract']['api_release'] ?? '0'),
                'writesafe' => !empty($corporatediagnostic['status']['writesafe']),
                'licenseavailable' => !empty($corporatediagnostic['status']['licenseavailable']),
            ],
        ];

        foreach ($rows as $row) {
            $productid = (int)($row['PRODUCT_ID'] ?? 0);
            $productrowid = (int)($row['ID'] ?? 0);
            $productname = trim((string)($row['PRODUCT_NAME'] ?? ''));
            $quantity = isset($row['QUANTITY']) ? (float)$row['QUANTITY'] : 1.0;
            // Never interpret a missing/zero Bitrix quantity as Corporate "unlimited".
            $seats = max(1, (int)ceil(max(0.0, $quantity)));
            $groupid = 0;

            $item = [
                'productrowid' => $productrowid,
                'productid' => $productid,
                'productname' => $productname,
                'quantity' => $quantity,
                'seats' => $seats,
                'courseid' => null,
                'canonicalproductid' => null,
                'resolutionsource' => null,
                'aliasid' => null,
                'status' => 'unmapped',
                'error' => null,
                'corporate' => [
                    'required' => !empty($context['iscorporate']) && $corporatemode !== 'off',
                    'status' => 'not_applicable',
                    'licenseid' => null,
                    'allocationid' => null,
                    'seats' => $seats,
                ],
            ];

            if ($productid <= 0) {
                $item['error'] = 'Missing PRODUCT_ID';
                $result['unmapped'][] = $item;
                $result['items'][] = $item;
                continue;
            }

            $mapping = $this->courses->resolve(
                $productid,
                $productname,
                !$dryrun
            );
            if (!$mapping) {
                $result['unmapped'][] = $item;
                $result['items'][] = $item;
                continue;
            }

            $courseid = (int)$mapping->courseid;
            $item['courseid'] = $courseid;
            $item['canonicalproductid'] = (int)(
                $mapping->_canonical_product_id ?? $mapping->bitrix_product_id
            );
            $item['resolutionsource'] = (string)(
                $mapping->_resolution_source ?? 'canonical'
            );
            $item['aliasid'] = !empty($mapping->_alias_id)
                ? (int)$mapping->_alias_id
                : null;
            $item['status'] = 'resolved';
            $result['resolved']++;

            if (!empty($context['iscorporate']) && $corporatemode !== 'off') {
                if (!$corporatediagnostic['available'] || !$corporatediagnostic['compatible']) {
                    $item['corporate']['status'] = 'unavailable';
                    if ($corporatemode === 'enforce') {
                        $item['status'] = 'failed';
                        $item['error'] = get_string('corporate_required_unavailable', 'local_bitrixsync');
                        $result['failed']++;
                        $result['items'][] = $item;
                        continue;
                    }
                } else {
                    $item['corporate']['status'] = $dryrun ? 'ready' : 'pending';
                }
            }

            if ($dryrun) {
                $result['items'][] = $item;
                continue;
            }

            $existing = $this->enrollments->find_existing(
                $dealid,
                $userid,
                $courseid,
                $productrowid ?: null
            );
            if ($existing && $existing->status === 'completed') {
                $item['status'] = 'already_processed';

                if (!empty($existing->corp_license_id)) {
                    $item['corporate']['licenseid'] = (int)$existing->corp_license_id;
                }
                if (!empty($existing->corp_allocation_id)) {
                    $item['corporate']['allocationid'] = (int)$existing->corp_allocation_id;
                }

                if (!empty($context['iscorporate']) && $corporatemode !== 'off' &&
                        $corporatediagnostic['available'] && $corporatediagnostic['compatible']) {
                    try {
                        // A previously completed enrolment does not freeze the
                        // commercial quantity. Reconcile the Deal Product Row
                        // with the Corporate licence package on every safe re-run.
                        $licenseonly = $this->corporate->sync_license_only(
                            (int)$context['bitrixcompanyid'],
                            (string)$context['companytitle'],
                            $courseid,
                            $groupid,
                            $seats,
                            $dealid,
                            $productrowid
                        );
                        $licenseid = (int)($licenseonly['license']['licenseid'] ?? 0);
                        if ($licenseid > 0) {
                            $item['corporate']['licenseid'] = $licenseid;
                        }

                        if (!empty($existing->corp_allocation_id)) {
                            $item['corporate']['status'] = !empty($licenseonly['changed'])
                                ? 'license_reconciled' : 'already_allocated';
                            $this->enrollments->update_corporate(
                                (int)$existing->id,
                                $licenseid ?: (!empty($existing->corp_license_id) ? (int)$existing->corp_license_id : null),
                                (int)$existing->corp_allocation_id,
                                'allocated'
                            );
                        } else {
                            // Migration/reconciliation for enrollment rows
                            // created before Corporate allocation IDs were persisted.
                            $reconciled = $this->corporate->prepare_enrollment(
                                $userid,
                                (int)$context['bitrixcompanyid'],
                                (string)$context['companytitle'],
                                (int)$context['bitrixcontactid'],
                                $courseid,
                                $groupid,
                                $seats,
                                $dealid,
                                $productrowid
                            );
                            $allocationid = (int)($reconciled['allocation']['allocationid'] ?? 0);
                            $licenseid = (int)($reconciled['license']['license']['licenseid'] ?? $licenseid);
                            $item['corporate']['status'] = 'already_allocated';
                            $item['corporate']['licenseid'] = $licenseid ?: null;
                            $item['corporate']['allocationid'] = $allocationid ?: null;
                            $this->enrollments->update_corporate(
                                (int)$existing->id,
                                $licenseid ?: null,
                                $allocationid ?: null,
                                'allocated'
                            );
                            $this->companies->record_corporate_sync(
                                (int)$context['bitrixcompanyid'],
                                $reconciled
                            );
                            $this->contacts->record_corporate_employee(
                                (int)$context['bitrixcontactid'],
                                $userid,
                                $context['contact']
                            );
                        }
                    } catch (\Throwable $e) {
                        $item['corporate']['status'] = 'reconcile_failed';
                        $item['corporate']['error'] = $e->getMessage();
                    }
                } else if (!empty($existing->corp_license_id) && !empty($existing->corp_allocation_id)) {
                    $item['corporate']['status'] = 'already_allocated';
                }

                $result['already']++;
                $result['items'][] = $item;
                continue;
            }

            $enrollmentid = $existing ? (int)$existing->id : $this->enrollments->create([
                'dealid' => $dealid,
                'companyid' => (int)$context['bitrixcompanyid'] ?: null,
                'contactid' => (int)$context['bitrixcontactid'] ?: null,
                'userid' => $userid,
                'productid' => $productid,
                'productrowid' => $productrowid ?: null,
                'courseid' => $courseid,
                'groupid' => $groupid ?: null,
                'status' => 'processing',
            ]);

            if ($existing) {
                $this->enrollments->update_status($enrollmentid, 'processing');
            }

            $corporatepreparation = null;

            try {
                if (!empty($context['iscorporate']) && $corporatemode !== 'off'
                        && $corporatediagnostic['available'] && $corporatediagnostic['compatible']) {
                    try {
                        $corporatepreparation = $this->corporate->prepare_enrollment(
                            $userid,
                            (int)$context['bitrixcompanyid'],
                            (string)$context['companytitle'],
                            (int)$context['bitrixcontactid'],
                            $courseid,
                            $groupid,
                            $seats,
                            $dealid,
                            $productrowid
                        );

                        $item['corporate']['status'] = 'allocated';
                        $item['corporate']['licenseid'] = (int)(
                            $corporatepreparation['license']['license']['licenseid'] ?? 0
                        ) ?: null;
                        $item['corporate']['allocationid'] = (int)(
                            $corporatepreparation['allocation']['allocationid'] ?? 0
                        ) ?: null;

                        $this->enrollments->update_corporate(
                            $enrollmentid,
                            $item['corporate']['licenseid'],
                            $item['corporate']['allocationid'],
                            'allocated'
                        );

                        $this->companies->record_corporate_sync(
                            (int)$context['bitrixcompanyid'],
                            $corporatepreparation
                        );
                        $this->contacts->record_corporate_employee(
                            (int)$context['bitrixcontactid'],
                            $userid,
                            $context['contact']
                        );
                    } catch (\Throwable $e) {
                        $item['corporate']['status'] = 'failed';
                        $item['corporate']['error'] = $e->getMessage();

                        if ($corporatemode === 'enforce') {
                            throw $e;
                        }
                    }
                }

                $enrolresult = $this->enroller->enrol($userid, $courseid);
                $this->enrollments->update_status($enrollmentid, 'completed');
                $item['status'] = $enrolresult['status'];

                if ($enrolresult['status'] === 'already_enrolled') {
                    $result['already']++;
                } else {
                    $result['enrolled']++;
                }
            } catch (\Throwable $e) {
                if ($corporatepreparation !== null && !empty($context['iscorporate'])) {
                    try {
                        $compensation = $this->corporate->compensate_failed_enrollment(
                            $userid,
                            (int)$context['bitrixcompanyid'],
                            $courseid,
                            $groupid,
                            $dealid,
                            $productrowid,
                            $corporatepreparation
                        );
                        $item['corporate']['compensation'] = $compensation;
                    } catch (\Throwable $releaseerror) {
                        $item['corporate']['compensation_error'] = $releaseerror->getMessage();
                    }
                }

                $this->enrollments->update_status($enrollmentid, 'failed', $e->getMessage());
                $item['status'] = 'failed';
                $item['error'] = $e->getMessage();
                $result['failed']++;
            }

            $result['items'][] = $item;
        }

        return $result;
    }
}
