<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\corporate\api_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Read/apply reconciliation between live Bitrix deal product quantities and
 * Corporate licence packages. It never allocates seats and never enrols users.
 */
class license_reconciliation_service {
    /** @var product_row_resolver */
    private $productrows;
    /** @var course_resolver */
    private $courses;
    /** @var deal_context_service */
    private $dealcontext;
    /** @var api_client */
    private $corporate;

    public function __construct(
        ?product_row_resolver $productrows = null,
        ?course_resolver $courses = null,
        ?deal_context_service $dealcontext = null,
        ?api_client $corporate = null
    ) {
        $this->productrows = $productrows ?? new product_row_resolver();
        $this->courses = $courses ?? new course_resolver();
        $this->dealcontext = $dealcontext ?? new deal_context_service();
        $this->corporate = $corporate ?? new api_client();
    }

    public function reconcile(int $dealid, bool $apply = false): array {
        if ($dealid <= 0) {
            throw new \invalid_parameter_exception('Deal ID must be positive.');
        }

        $context = $this->dealcontext->resolve($dealid);
        $result = [
            'dealid' => $dealid,
            'apply' => $apply,
            'bitrixcompanyid' => (int)($context['bitrixcompanyid'] ?? 0),
            'companytitle' => (string)($context['companytitle'] ?? ''),
            'iscorporate' => !empty($context['iscorporate']),
            'checked' => 0,
            'insync' => 0,
            'drift' => 0,
            'missing' => 0,
            'unmapped' => 0,
            'changed' => 0,
            'failed' => 0,
            'items' => [],
            'snapshot' => [],
        ];

        if (empty($context['iscorporate'])) {
            $result['error'] = 'deal_has_no_company';
            return $result;
        }

        $rows = $this->productrows->resolve_products($dealid);
        foreach ($rows as $row) {
            $productid = (int)($row['PRODUCT_ID'] ?? 0);
            $productrowid = (int)($row['ID'] ?? 0);
            $productname = trim((string)($row['PRODUCT_NAME'] ?? ''));
            $quantity = isset($row['QUANTITY']) ? (float)$row['QUANTITY'] : 1.0;
            $expectedseats = max(1, (int)ceil(max(0.0, $quantity)));

            $item = [
                'productrowid' => $productrowid,
                'productid' => $productid,
                'productname' => $productname,
                'quantity' => $quantity,
                'expectedseats' => $expectedseats,
                'courseid' => 0,
                'licenseid' => 0,
                'actualseats' => null,
                'status' => 'unmapped',
                'action' => 'none',
                'error' => '',
            ];

            if ($productid <= 0) {
                $result['unmapped']++;
                $result['items'][] = $item;
                continue;
            }

            $mapping = $this->courses->resolve($productid, $productname, false);
            if (!$mapping) {
                $result['unmapped']++;
                $result['items'][] = $item;
                continue;
            }

            $courseid = (int)$mapping->courseid;
            $item['courseid'] = $courseid;
            $result['checked']++;

            try {
                $current = $this->corporate->get_license(
                    (int)$context['bitrixcompanyid'],
                    (string)$dealid,
                    (string)$productrowid,
                    $courseid,
                    0
                );

                if (empty($current['found'])) {
                    $item['status'] = 'missing_package';
                    $result['missing']++;
                } else {
                    $license = (array)($current['license'] ?? []);
                    $item['licenseid'] = (int)($license['licenseid'] ?? 0);
                    $item['actualseats'] = (int)($license['seats'] ?? 0);
                    if ($item['actualseats'] !== $expectedseats) {
                        $item['status'] = 'quantity_drift';
                        $result['drift']++;
                    } else {
                        $item['status'] = 'in_sync';
                        $result['insync']++;
                    }
                }

                if ($apply && in_array($item['status'], ['missing_package', 'quantity_drift'], true)) {
                    $datefrom = 0;
                    $dateto = 0;
                    if (!empty($current['license']) && is_array($current['license'])) {
                        $datefrom = max(0, (int)($current['license']['datefrom'] ?? 0));
                        $dateto = max(0, (int)($current['license']['dateto'] ?? 0));
                    }
                    $synced = $this->corporate->sync_course_license(
                        (int)$context['bitrixcompanyid'],
                        (string)$context['companytitle'],
                        $courseid,
                        0,
                        $expectedseats,
                        $datefrom,
                        $dateto,
                        (string)$dealid,
                        (string)$productrowid
                    );
                    $license = (array)($synced['license'] ?? []);
                    $item['licenseid'] = (int)($license['licenseid'] ?? $item['licenseid']);
                    $item['actualseats'] = (int)($license['seats'] ?? $expectedseats);
                    $item['action'] = !empty($synced['created']) ? 'created' : 'updated';
                    $item['status'] = 'reconciled';
                    $result['changed']++;
                }
            } catch (\Throwable $e) {
                $item['status'] = 'failed';
                $item['error'] = $e->getMessage();
                $result['failed']++;
            }

            $result['items'][] = $item;
        }

        try {
            $result['snapshot'] = $this->corporate->get_license_snapshot((int)$context['bitrixcompanyid']);
        } catch (\Throwable $e) {
            $result['snapshot'] = ['success' => false, 'error' => $e->getMessage()];
        }
        return $result;
    }
}
