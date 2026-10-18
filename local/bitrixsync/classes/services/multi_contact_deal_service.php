<?php
namespace local_bitrixsync\services;

use local_bitrixsync\logging\logger;

defined('MOODLE_INTERNAL') || die();

/**
 * Process every Bitrix24 contact attached to one deal.
 *
 * The legacy deal_flow_service remains untouched for compatibility. This
 * service powers the Production Control Center and is the safe migration
 * path for multi-contact deals.
 */
class multi_contact_deal_service {
    /** @var deal_context_service */
    private $contexts;
    /** @var contact_service */
    private $contacts;
    /** @var multi_course_engine */
    private $engine;
    /** @var corporate_employee_sync_service */
    private $corporateemployees;
    /** @var access_delivery_service */
    private $accessdelivery;

    public function __construct(
        ?deal_context_service $contexts = null,
        ?contact_service $contacts = null,
        ?multi_course_engine $engine = null,
        ?corporate_employee_sync_service $corporateemployees = null,
        ?access_delivery_service $accessdelivery = null
    ) {
        $this->contexts = $contexts ?? new deal_context_service();
        $this->contacts = $contacts ?? new contact_service();
        $this->engine = $engine ?? new multi_course_engine();
        $this->corporateemployees = $corporateemployees ?? new corporate_employee_sync_service();
        $this->accessdelivery = $accessdelivery ?? new access_delivery_service();
    }

    public function process(int $dealid, bool $dryrun = true): array {
        if ($dealid <= 0) {
            throw new \moodle_exception('invalid_deal_id', 'local_bitrixsync');
        }

        $context = $this->contexts->resolve_contacts($dealid);
        $contactitems = $context['contacts'] ?? [];
        if (!$contactitems) {
            throw new \moodle_exception('deal_contact_missing', 'local_bitrixsync');
        }

        logger::debug($dealid, 'CONTACT_DISCOVERY', [
            'count' => count($contactitems),
            'ids' => array_map(function(array $item): int {
                return (int)($item['bitrixcontactid'] ?? 0);
            }, $contactitems),
        ]);

        $result = [
            'dealid' => $dealid,
            'dryrun' => $dryrun,
            'contacts' => count($contactitems),
            'productrows' => 0,
            'resolved' => 0,
            'enrolled' => 0,
            'already' => 0,
            'failed' => 0,
            'contact_results' => [],
        ];

        foreach ($contactitems as $contactitem) {
            $contactid = (int)($contactitem['bitrixcontactid'] ?? 0);
            $contact = is_array($contactitem['contact'] ?? null)
                ? $contactitem['contact']
                : [];

            $name = trim(
                (string)($contact['LAST_NAME'] ?? '') . ' ' .
                (string)($contact['NAME'] ?? '') . ' ' .
                (string)($contact['SECOND_NAME'] ?? '')
            );

            $row = [
                'bitrixcontactid' => $contactid,
                'name' => $name,
                'email' => '',
                'userid' => 0,
                'decision' => 'error',
                'enrolled' => 0,
                'already' => 0,
                'failed' => 0,
                'crm' => 'skipped',
                'corporate' => 'skipped',
                'access' => 'skipped',
                'error' => null,
            ];

            try {
                if (!empty($contactitem['error'])) {
                    throw new \moodle_exception(
                        'error',
                        'core',
                        '',
                        null,
                        (string)$contactitem['error']
                    );
                }

                $resolution = $this->contacts->resolve_user(
                    $contactid,
                    $contact,
                    $dryrun,
                    null
                );

                $row['email'] = (string)($resolution['email'] ?? '');
                $row['userid'] = (int)($resolution['userid'] ?? 0);
                $status = (string)($resolution['status'] ?? '');

                if ($dryrun) {
                    if ($row['userid'] > 0) {
                        $row['decision'] = 'ALREADY / MATCHED';
                    } else if (!empty($resolution['wouldcreate'])) {
                        $row['decision'] = 'WOULD CREATE';
                    } else {
                        $row['decision'] = 'ERROR';
                        $row['error'] = $row['email'] === ''
                            ? 'У контакта нет валидного email для создания Moodle User.'
                            : 'Автоматическое создание Moodle User выключено.';
                        $row['failed'] = 1;
                        $result['failed']++;
                        $result['contact_results'][] = $row;
                        continue;
                    }
                } else {
                    if ($row['userid'] <= 0) {
                        throw new \moodle_exception(
                            'moodle_user_not_resolved',
                            'local_bitrixsync',
                            '',
                            $contactid
                        );
                    }
                    $row['decision'] = !empty($resolution['created'])
                        ? 'CREATED'
                        : 'ALREADY / MATCHED';
                }

                $contactcontext = $context;
                $contactcontext['bitrixcontactid'] = $contactid;
                $contactcontext['contact'] = $contact;
                unset($contactcontext['contacts']);

                $learning = $this->engine->process_deal(
                    $dealid,
                    $row['userid'],
                    $dryrun,
                    true,
                    $contactcontext
                );

                $row['enrolled'] = (int)($learning['enrolled'] ?? 0);
                $row['already'] = (int)($learning['already'] ?? 0);
                $row['failed'] += (int)($learning['failed'] ?? 0);

                $result['productrows'] = max(
                    $result['productrows'],
                    (int)($learning['productrows'] ?? 0)
                );
                $result['resolved'] = max(
                    $result['resolved'],
                    (int)($learning['resolved'] ?? 0)
                );
                $result['enrolled'] += $row['enrolled'];
                $result['already'] += $row['already'];
                $result['failed'] += (int)($learning['failed'] ?? 0);

                if (!$dryrun) {
                    $corporateresult = $this->corporateemployees->sync($row['userid'], $contactcontext);
                    $row['corporate'] = (string)($corporateresult['status'] ?? 'skipped');
                    $contactcontext['corporate_employee_attached'] = !empty($corporateresult['attached']);

                    $accessresult = $this->accessdelivery->deliver(
                        $dealid,
                        $contactid,
                        $contact,
                        $resolution,
                        $learning
                    );
                    $row['access'] = (string)($accessresult['status'] ?? 'skipped');
                    if (($accessresult['status'] ?? '') === 'error' && !empty($accessresult['error'])) {
                        $row['error'] = trim(((string)$row['error'] . '; ' . (string)$accessresult['error']), '; ');
                    }

                    try {
                        $crm = (new crm_writeback_service())->sync(
                            $learning,
                            $contactcontext,
                            $resolution
                        );
                        $row['crm'] = empty($crm['errors']) ? 'updated' : 'error';
                        if (!empty($crm['errors'])) {
                            $row['error'] = implode('; ', $crm['errors']);
                        }
                    } catch (\Throwable $crmerror) {
                        $row['crm'] = 'error';
                        $row['error'] = $crmerror->getMessage();
                    }
                }

                logger::debug($dealid, 'CONTACT_RESULT', [
                    'contactid' => $contactid,
                    'email' => $row['email'],
                    'userid' => $row['userid'],
                    'decision' => $row['decision'],
                    'enrolled' => $row['enrolled'],
                    'already' => $row['already'],
                    'failed' => $row['failed'],
                    'corporate' => $row['corporate'],
                    'access' => $row['access'],
                ]);
            } catch (\Throwable $e) {
                $row['decision'] = 'ERROR';
                $row['error'] = $e->getMessage();
                $row['failed']++;
                $result['failed']++;
                logger::debug($dealid, 'CONTACT_ERROR', [
                    'contactid' => $contactid,
                    'error' => $e->getMessage(),
                ]);
            }

            $result['contact_results'][] = $row;
        }

        set_config(
            'multi_contact_deal_last',
            json_encode(
                [
                    'time' => time(),
                    'dealid' => $dealid,
                    'dryrun' => $dryrun,
                    'contacts' => (int)($result['contacts'] ?? 0),
                    'enrolled' => (int)($result['enrolled'] ?? 0),
                    'already' => (int)($result['already'] ?? 0),
                    'failed' => (int)($result['failed'] ?? 0),
                    'contact_results' => (array)($result['contact_results'] ?? []),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        return $result;
    }
}
