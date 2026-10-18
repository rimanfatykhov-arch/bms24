<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\corporate\api_client;
use local_bitrixsync\adapters\corporate\company_adapter;
use local_bitrixsync\adapters\corporate\employee_adapter;
use local_bitrixsync\adapters\corporate\license_adapter;

defined('MOODLE_INTERNAL') || die();

class corporate_bridge {
    /** @var api_client */
    private $api;
    /** @var company_adapter */
    private $companies;
    /** @var employee_adapter */
    private $employees;
    /** @var license_adapter */
    private $licenses;

    public function __construct(
        ?api_client $api = null,
        ?company_adapter $companies = null,
        ?employee_adapter $employees = null,
        ?license_adapter $licenses = null
    ) {
        $this->api = $api ?? new api_client();
        $this->companies = $companies ?? new company_adapter($this->api);
        $this->employees = $employees ?? new employee_adapter($this->api);
        $this->licenses = $licenses ?? new license_adapter($this->api);
    }

    public function diagnostic(): array {
        return [
            'enabled' => $this->api->is_enabled(),
            'available' => $this->api->is_available(),
            'compatible' => $this->api->is_api_compatible(),
            'contract' => $this->api->contract(),
            'status' => $this->api->status(),
        ];
    }

    /**
     * Prepare Corporate Dashboard contract + employee + seat before Moodle enrolment.
     */
    public function prepare_enrollment(
        int $userid,
        int $bitrixcompanyid,
        string $companytitle,
        int $bitrixcontactid,
        int $courseid,
        int $groupid,
        int $seats,
        int $dealid,
        int $productrowid,
        int $datefrom = 0,
        int $dateto = 0
    ): array {
        $company = $this->companies->sync($bitrixcompanyid, $companytitle);
        $employee = $this->employees->sync($userid, $bitrixcompanyid, $companytitle, $bitrixcontactid);

        $license = $this->licenses->sync_package(
            $bitrixcompanyid,
            $companytitle,
            $courseid,
            $groupid,
            max(1, $seats),
            max(0, $datefrom),
            max(0, $dateto),
            (string)$dealid,
            (string)$productrowid
        );

        $allocation = $this->licenses->allocate(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            (string)$dealid,
            (string)$productrowid
        );

        $snapshot = $this->companies->snapshot($bitrixcompanyid);

        return [
            'success' => true,
            'company' => $company,
            'employee' => $employee,
            'license' => $license,
            'allocation' => $allocation,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Reconcile only the commercial licence package for one deal product row.
     * Existing contract dates are preserved because deal product rows currently
     * carry quantity, not contract-date semantics. No seat is allocated here.
     */
    public function sync_license_only(
        int $bitrixcompanyid,
        string $companytitle,
        int $courseid,
        int $groupid,
        int $seats,
        int $dealid,
        int $productrowid
    ): array {
        $datefrom = 0;
        $dateto = 0;
        $current = $this->api->get_license(
            $bitrixcompanyid,
            (string)$dealid,
            (string)$productrowid,
            $courseid,
            $groupid
        );
        if (!empty($current['found']) && !empty($current['license']) && is_array($current['license'])) {
            $datefrom = max(0, (int)($current['license']['datefrom'] ?? 0));
            $dateto = max(0, (int)($current['license']['dateto'] ?? 0));
        }
        return $this->licenses->sync_package(
            $bitrixcompanyid,
            $companytitle,
            $courseid,
            $groupid,
            max(1, $seats),
            $datefrom,
            $dateto,
            (string)$dealid,
            (string)$productrowid
        );
    }

    public function compensate_failed_enrollment(
        int $userid,
        int $bitrixcompanyid,
        int $courseid,
        int $groupid,
        int $dealid,
        int $productrowid,
        array $preparation
    ): array {
        $created = !empty($preparation['allocation']['created']);
        if (!$created) {
            return ['success' => true, 'released' => false, 'reason' => 'allocation_not_created_by_this_run'];
        }

        return $this->licenses->release(
            $userid,
            $bitrixcompanyid,
            $courseid,
            $groupid,
            (string)$dealid,
            (string)$productrowid
        );
    }
}
