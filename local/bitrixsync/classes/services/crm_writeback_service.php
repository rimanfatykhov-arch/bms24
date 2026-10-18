<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\adapters\corporate\api_client as corporate_api;

defined('MOODLE_INTERNAL') || die();

class crm_writeback_service {
    /** @var client */
    private $client;

    /** @var crm_field_manager */
    private $fields;

    /** @var crm_timeline_service */
    private $timeline;

    /** @var corporate_api */
    private $corporate;

    /** @var message_template_service */
    private $templates;

    public function __construct(
        ?client $client = null,
        ?crm_field_manager $fields = null,
        ?crm_timeline_service $timeline = null,
        ?corporate_api $corporate = null,
        ?message_template_service $templates = null
    ) {
        $this->client = $client ?? new client();
        $this->fields = $fields ?? new crm_field_manager($this->client);
        $this->timeline = $timeline ?? new crm_timeline_service($this->client);
        $this->corporate = $corporate ?? new corporate_api();
        $this->templates = $templates ?? new message_template_service();
    }

    public function sync(
        array $result,
        array $context,
        array $userresolution
    ): array {
        global $DB, $CFG;

        $out = [
            'enabled' => (bool)get_config(
                'local_bitrixsync',
                'crm_writeback_enable'
            ),
            'fields_ready' => false,
            'company' => ['status' => 'skipped'],
            'contact' => ['status' => 'skipped'],
            'deal' => ['status' => 'skipped'],
            'timeline' => [
                'deal' => ['status' => 'skipped'],
                'company' => ['status' => 'skipped'],
            ],
            'errors' => [],
        ];

        if (!$out['enabled']) {
            return $out;
        }

        $registry = $this->fields->get_registry(false);
        $out['fields_ready'] = $this->registry_ready($registry);

        if ($out['fields_ready']) {
            // COMPANY.
            $companyid = (int)($context['bitrixcompanyid'] ?? 0);

            if ($companyid > 0) {
                try {
                    $snapshot = $this->corporate->get_company_snapshot(
                        $companyid
                    );

                    $courseitems = [];
                    foreach (($snapshot['courses'] ?? []) as $course) {
                        if (empty($course['active'])) {
                            continue;
                        }

                        $courseitems[] = $this->truncate(
                            (string)($course['fullname'] ?? ''),
                            250
                        );
                    }

                    $fields = [
                        $registry['company']['connected'] =>
                            !empty($snapshot['found'])
                                ? 'Подключён'
                                : 'Не подключён',

                        $registry['company']['corporate_company_id'] =>
                            (int)($snapshot['companyid'] ?? 0),

                        $registry['company']['course_count'] =>
                            (int)($snapshot['activecoursecount']
                                ?? $snapshot['coursecount']
                                ?? 0),

                        $registry['company']['course_list'] =>
                            $courseitems,

                        $registry['company']['employee_count'] =>
                            (int)($snapshot['employeecount'] ?? 0),

                        $registry['company']['sync_date'] =>
                            date('c'),

                        $registry['company']['status'] =>
                            !empty($snapshot['active'])
                                ? 'active'
                                : (!empty($snapshot['found'])
                                    ? 'inactive'
                                    : 'not_found'),
                    ];

                    $this->client->call(
                        'crm.company.update',
                        [
                            'id' => $companyid,
                            'fields' => $fields,
                        ]
                    );

                    $out['company'] = [
                        'status' => 'updated',
                        'coursecount' => count($courseitems),
                        'employeecount' =>
                            (int)($snapshot['employeecount'] ?? 0),
                    ];
                } catch (\Throwable $e) {
                    $out['company'] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                    $out['errors'][] = 'Company: ' . $e->getMessage();
                }
            }

            // CONTACT.
            $contactid = (int)($context['bitrixcontactid'] ?? 0);
            $userid = (int)($result['userid'] ?? 0);

            if ($contactid > 0 && $userid > 0) {
                try {
                    $user = $DB->get_record(
                        'user',
                        ['id' => $userid, 'deleted' => 0],
                        'id,username,lastaccess',
                        MUST_EXIST
                    );

                    $position = trim((string)(
                        $context['contact']['POST'] ?? ''
                    ));

                    $profileurl = rtrim(
                        $CFG->wwwroot,
                        '/'
                    ) . '/user/profile.php?id=' . $userid;

                    $fields = [
                        $registry['contact']['moodle_user_id'] =>
                            $userid,

                        $registry['contact']['moodle_login'] =>
                            (string)$user->username,

                        $registry['contact']['profile_url'] =>
                            $profileurl,

                        $registry['contact']['corporate_employee'] =>
                            array_key_exists('corporate_employee_attached', $context)
                                ? (!empty($context['corporate_employee_attached']) ? 'Да' : 'Нет')
                                : (!empty($context['iscorporate']) ? 'Да' : 'Нет'),

                        $registry['contact']['position'] =>
                            $position,

                        $registry['contact']['last_login'] =>
                            !empty($user->lastaccess)
                                ? date('c', (int)$user->lastaccess)
                                : '',
                    ];

                    $this->client->call(
                        'crm.contact.update',
                        [
                            'id' => $contactid,
                            'fields' => $fields,
                        ]
                    );

                    $out['contact'] = [
                        'status' => 'updated',
                        'userid' => $userid,
                    ];
                } catch (\Throwable $e) {
                    $out['contact'] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                    $out['errors'][] = 'Contact: ' . $e->getMessage();
                }
            }

            // DEAL.
            $dealid = (int)($result['dealid'] ?? 0);

            if ($dealid > 0) {
                try {
                    $dealstatus = $this->deal_status($result);
                    $dealerror = $this->deal_error($result);
                    $dealstatusvalue =
                        $this->fields->encode_deal_enrollment_status(
                            $dealstatus
                        );

                    $fields = [
                        $registry['deal']['enrollment_status'] =>
                            $dealstatusvalue,

                        $registry['deal']['courses_selected'] =>
                            (int)($result['productrows'] ?? 0),

                        $registry['deal']['courses_enrolled'] =>
                            (int)($result['enrolled'] ?? 0) +
                            (int)($result['already'] ?? 0),

                        $registry['deal']['sync_date'] =>
                            date('c'),

                        $registry['deal']['error'] =>
                            $this->truncate($dealerror, 250),
                    ];

                    $this->client->call(
                        'crm.deal.update',
                        [
                            'id' => $dealid,
                            'fields' => $fields,
                        ]
                    );

                    $out['deal'] = [
                        'status' => 'updated',
                        'enrollment_status' => $dealstatus,
                    ];
                } catch (\Throwable $e) {
                    $out['deal'] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                    $out['errors'][] = 'Deal: ' . $e->getMessage();
                }
            }
        } else {
            $out['errors'][] = get_string(
                'crm_fields_not_ready',
                'local_bitrixsync'
            );
        }

        $legacytimeline = get_config(
            'local_bitrixsync',
            'timelineenabled'
        );
        $legacytimelineenabled =
            $legacytimeline === false
                ? true
                : !empty($legacytimeline);

        if ($legacytimelineenabled && get_config(
            'local_bitrixsync',
            'crm_timeline_enable'
        )) {
            $this->sync_timeline(
                $out,
                $result,
                $context
            );
        }

        return $out;
    }

    private function sync_timeline(
        array &$out,
        array $result,
        array $context
    ): void {
        $dealid = (int)($result['dealid'] ?? 0);
        $companyid = (int)($context['bitrixcompanyid'] ?? 0);

        $courses = $this->course_names($result);

        if ($dealid > 0) {
            try {
                $text = $this->templates->deal_summary(
                    $result,
                    $context,
                    $courses
                );

                $timeline = $this->timeline->upsert(
                    'deal',
                    $dealid,
                    '[KBA_CONNECT_DEAL_' . $dealid . ']',
                    $text
                );

                $out['timeline']['deal'] = [
                    'status' => $timeline['action'],
                    'id' => (int)$timeline['id'],
                ];
            } catch (\Throwable $e) {
                $out['timeline']['deal'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $out['errors'][] = 'Deal timeline: ' .
                    $e->getMessage();
            }
        }

        if ($companyid > 0) {
            try {
                $snapshot = $this->corporate->get_company_snapshot(
                    $companyid
                );

                $activecourses = [];
                foreach (($snapshot['courses'] ?? []) as $course) {
                    if (!empty($course['active'])) {
                        $activecourses[] = (string)$course['fullname'];
                    }
                }

                $text = $this->templates->company_summary(
                    $snapshot,
                    $context
                );

                $timeline = $this->timeline->upsert(
                    'company',
                    $companyid,
                    '[KBA_CONNECT_COMPANY_' . $companyid . ']',
                    $text
                );

                $out['timeline']['company'] = [
                    'status' => $timeline['action'],
                    'id' => (int)$timeline['id'],
                ];
            } catch (\Throwable $e) {
                $out['timeline']['company'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $out['errors'][] = 'Company timeline: ' .
                    $e->getMessage();
            }
        }
    }

    private function deal_status(array $result): string {
        if (!empty($result['failed'])) {
            return 'error';
        }

        if (!empty($result['unmapped'])) {
            return 'partial';
        }

        $processed =
            (int)($result['enrolled'] ?? 0) +
            (int)($result['already'] ?? 0);

        if ($processed >= (int)($result['resolved'] ?? 0) &&
                (int)($result['resolved'] ?? 0) > 0) {
            return 'completed';
        }

        return 'processing';
    }

    private function deal_error(array $result): string {
        $messages = [];

        foreach (($result['items'] ?? []) as $item) {
            if (!empty($item['error'])) {
                $messages[] = (string)$item['error'];
            }
        }

        if (!empty($result['unmapped'])) {
            $messages[] = 'Unmapped products: ' .
                count($result['unmapped']);
        }

        return implode('; ', array_unique($messages));
    }

    private function course_names(array $result): array {
        global $DB;

        $ids = [];

        foreach (($result['items'] ?? []) as $item) {
            if (!empty($item['courseid'])) {
                $ids[(int)$item['courseid']] =
                    (int)$item['courseid'];
            }
        }

        if (!$ids) {
            return [];
        }

        $courses = $DB->get_records_list(
            'course',
            'id',
            array_values($ids),
            'fullname ASC',
            'id,fullname'
        );

        $names = [];
        foreach ($courses as $course) {
            $names[] = format_string($course->fullname);
        }

        return $names;
    }

    private function registry_ready(array $registry): bool {
        $required = [
            'company' => [
                'connected',
                'corporate_company_id',
                'course_count',
                'course_list',
                'employee_count',
                'sync_date',
                'status',
            ],
            'contact' => [
                'moodle_user_id',
                'moodle_login',
                'profile_url',
                'corporate_employee',
                'position',
                'last_login',
            ],
            'deal' => [
                'enrollment_status',
                'courses_selected',
                'courses_enrolled',
                'sync_date',
                'error',
            ],
        ];

        foreach ($required as $entity => $keys) {
            foreach ($keys as $key) {
                if (empty($registry[$entity][$key])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function truncate(string $value, int $limit): string {
        if (\core_text::strlen($value) <= $limit) {
            return $value;
        }

        return \core_text::substr(
            $value,
            0,
            max(0, $limit - 1)
        ) . '…';
    }
}
