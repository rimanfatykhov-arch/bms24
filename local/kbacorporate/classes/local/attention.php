<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Corporate attention/risk engine.
 *
 * The engine converts current Moodle learning data into a stable list of
 * actionable signals. It does not store alerts: every signal is calculated
 * from the current source data so it disappears automatically when the issue
 * is resolved.
 */
class attention {
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    public const TYPE_NOTENROLLED = 'notenrolled';
    public const TYPE_NOTSTARTED = 'notstarted';
    public const TYPE_INACTIVE = 'inactive';
    public const TYPE_LOWPROGRESS = 'lowprogress';
    public const TYPE_LOWGRADE = 'lowgrade';
    public const TYPE_TRAININGPLAN = 'trainingplan';
    public const TYPE_WEBINAR = 'webinar';
    public const TYPE_CERTIFICATE = 'certificate';

    /** Return administrator-configured alert thresholds. */
    public static function thresholds(): array {
        return [
            'notstarteddays' => self::config_int('attentionnotstarteddays', 3, 1, 365),
            'inactivitydays' => self::config_int('attentioninactivitydays', 7, 1, 365),
            'progresspercent' => self::config_int('attentionprogressthreshold', 50, 0, 100),
            'gradepercent' => self::config_int('attentiongradethreshold', 60, 0, 100),
            'trainingpercent' => self::config_int('attentiontrainingthreshold', 80, 0, 100),
            'webinarpercent' => self::config_int('attentionwebinarthreshold', 70, 0, 100),
        ];
    }

    /**
     * Evaluate employees already aggregated by management_report.
     *
     * @param array $employees Employee objects keyed by userid.
     * @param array $progressrows Course progress rows.
     * @return \stdClass Flat alerts, affected employees and summary.
     */
    public static function evaluate(array &$employees, array $progressrows): \stdClass {
        $thresholds = self::thresholds();
        $now = time();
        $rowsbyuser = [];
        foreach ($progressrows as $row) {
            $rowsbyuser[(int)$row->userid][] = $row;
        }

        $alerts = [];
        $affected = [];
        foreach ($employees as $userid => $employee) {
            $userid = (int)$userid;
            $employee->riskscore = 0;
            $employee->riskreasons = [];
            $employee->risktypes = [];
            $employee->riskseverity = '';
            $employee->alertcount = 0;

            $employeealerts = [];
            $hasinprogress = false;
            foreach ($rowsbyuser[$userid] ?? [] as $row) {
                if ($row->status === 'inprogress') {
                    $hasinprogress = true;
                }

                if ($row->status === 'notenrolled') {
                    $reason = get_string('risknotenrolledcourse', 'local_kbacorporate', format_string($row->coursename));
                    $employeealerts[] = self::make_alert(
                        $employee,
                        self::TYPE_NOTENROLLED,
                        self::SEVERITY_HIGH,
                        4,
                        $reason,
                        (int)$row->courseid,
                        (string)$row->coursename,
                        null,
                        null,
                        (int)$row->lastaccess
                    );
                    continue;
                }

                if ($row->status === 'notstarted' && !empty($row->enrolledat)) {
                    $days = max(0, (int)floor(($now - (int)$row->enrolledat) / DAYSECS));
                    if ($days >= $thresholds['notstarteddays']) {
                        $severity = $days >= ($thresholds['notstarteddays'] * 2)
                            ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                        $reason = get_string('risknotstartedcourse', 'local_kbacorporate', (object)[
                            'course' => format_string($row->coursename),
                            'days' => $days,
                            'threshold' => $thresholds['notstarteddays'],
                        ]);
                        $employeealerts[] = self::make_alert(
                            $employee,
                            self::TYPE_NOTSTARTED,
                            $severity,
                            $severity === self::SEVERITY_HIGH ? 3 : 2,
                            $reason,
                            (int)$row->courseid,
                            (string)$row->coursename,
                            (float)$days,
                            (float)$thresholds['notstarteddays'],
                            (int)$row->lastaccess
                        );
                    }
                }

                if ($row->grade !== null && (float)$row->grade < $thresholds['gradepercent']) {
                    $severity = (float)$row->grade <= max(0, $thresholds['gradepercent'] - 20)
                        ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                    $reason = get_string('risklowgradecourse', 'local_kbacorporate', (object)[
                        'course' => format_string($row->coursename),
                        'actual' => format_float((float)$row->grade, 1),
                        'threshold' => $thresholds['gradepercent'],
                    ]);
                    $employeealerts[] = self::make_alert(
                        $employee,
                        self::TYPE_LOWGRADE,
                        $severity,
                        $severity === self::SEVERITY_HIGH ? 3 : 2,
                        $reason,
                        (int)$row->courseid,
                        (string)$row->coursename,
                        (float)$row->grade,
                        (float)$thresholds['gradepercent'],
                        (int)$row->lastaccess
                    );
                }
            }

            if ($hasinprogress && $employee->avgprogress !== null &&
                    (float)$employee->avgprogress < $thresholds['progresspercent']) {
                $severity = (float)$employee->avgprogress <= ($thresholds['progresspercent'] / 2)
                    ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                $reason = get_string('risklowprogressvalue', 'local_kbacorporate', (object)[
                    'actual' => format_float((float)$employee->avgprogress, 1),
                    'threshold' => $thresholds['progresspercent'],
                ]);
                $employeealerts[] = self::make_alert(
                    $employee,
                    self::TYPE_LOWPROGRESS,
                    $severity,
                    $severity === self::SEVERITY_HIGH ? 3 : 2,
                    $reason,
                    0,
                    '',
                    (float)$employee->avgprogress,
                    (float)$thresholds['progresspercent'],
                    (int)$employee->lastaccess
                );
            }

            if ($employee->courses > 0 && $employee->lastaccess > 0) {
                $days = max(0, (int)floor(($now - (int)$employee->lastaccess) / DAYSECS));
                if ($days >= $thresholds['inactivitydays']) {
                    $severity = $days >= ($thresholds['inactivitydays'] * 2)
                        ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                    $reason = get_string('riskinactivevalue', 'local_kbacorporate', (object)[
                        'days' => $days,
                        'threshold' => $thresholds['inactivitydays'],
                    ]);
                    $employeealerts[] = self::make_alert(
                        $employee,
                        self::TYPE_INACTIVE,
                        $severity,
                        $severity === self::SEVERITY_HIGH ? 3 : 2,
                        $reason,
                        0,
                        '',
                        (float)$days,
                        (float)$thresholds['inactivitydays'],
                        (int)$employee->lastaccess
                    );
                }
            }

            if ((int)$employee->courses > 0 && (float)$employee->planminutes > 0 &&
                    $employee->trainingpercent !== null &&
                    (float)$employee->trainingpercent < $thresholds['trainingpercent']) {
                $severity = (float)$employee->trainingpercent <= ($thresholds['trainingpercent'] / 2)
                    ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                $reason = get_string('riskbelowtrainingplanvalue', 'local_kbacorporate', (object)[
                    'actual' => format_float((float)$employee->trainingpercent, 1),
                    'threshold' => $thresholds['trainingpercent'],
                ]);
                $employeealerts[] = self::make_alert(
                    $employee,
                    self::TYPE_TRAININGPLAN,
                    $severity,
                    $severity === self::SEVERITY_HIGH ? 3 : 2,
                    $reason,
                    0,
                    '',
                    (float)$employee->trainingpercent,
                    (float)$thresholds['trainingpercent'],
                    (int)$employee->lastaccess
                );
            }

            if ((int)$employee->webinareligible > 0 && $employee->webinarattendancepercent !== null &&
                    (float)$employee->webinarattendancepercent < $thresholds['webinarpercent']) {
                $severity = (float)$employee->webinarattendancepercent <= ($thresholds['webinarpercent'] / 2)
                    ? self::SEVERITY_HIGH : self::SEVERITY_MEDIUM;
                $reason = get_string('risklowwebinarattendancevalue', 'local_kbacorporate', (object)[
                    'actual' => format_float((float)$employee->webinarattendancepercent, 1),
                    'threshold' => $thresholds['webinarpercent'],
                    'attended' => (int)$employee->webinarattended,
                    'eligible' => (int)$employee->webinareligible,
                ]);
                $employeealerts[] = self::make_alert(
                    $employee,
                    self::TYPE_WEBINAR,
                    $severity,
                    $severity === self::SEVERITY_HIGH ? 3 : 2,
                    $reason,
                    0,
                    '',
                    (float)$employee->webinarattendancepercent,
                    (float)$thresholds['webinarpercent'],
                    (int)$employee->lastaccess
                );
            }

            if (!empty($employee->missingcertificateaftercompletion)) {
                $missingcourses = [];
                foreach ($employee->_missingcertificatecourses ?? [] as $course) {
                    $courseid = (int)$course['courseid'];
                    $missingcourses[$courseid] = (string)$course['coursename'];
                }
                if ($missingcourses) {
                    foreach ($missingcourses as $courseid => $coursename) {
                        $reason = get_string('riskcertificatenotissuedcourses', 'local_kbacorporate',
                            format_string($coursename));
                        $employeealerts[] = self::make_alert(
                            $employee,
                            self::TYPE_CERTIFICATE,
                            self::SEVERITY_LOW,
                            1,
                            $reason,
                            (int)$courseid,
                            $coursename,
                            null,
                            null,
                            (int)$employee->lastaccess
                        );
                    }
                } else {
                    $employeealerts[] = self::make_alert(
                        $employee,
                        self::TYPE_CERTIFICATE,
                        self::SEVERITY_LOW,
                        1,
                        get_string('riskcertificatenotissued', 'local_kbacorporate'),
                        0,
                        '',
                        null,
                        null,
                        (int)$employee->lastaccess
                    );
                }
            }

            // De-duplicate signals while preserving the highest severity version.
            $deduplicated = [];
            foreach ($employeealerts as $alert) {
                $key = $alert->alertkey;
                if (!isset($deduplicated[$key]) ||
                        self::severity_rank($alert->severity) > self::severity_rank($deduplicated[$key]->severity)) {
                    $deduplicated[$key] = $alert;
                }
            }
            $employeealerts = array_values($deduplicated);

            foreach ($employeealerts as $alert) {
                $employee->riskscore += (int)$alert->score;
                $employee->riskreasons[] = (string)$alert->reason;
                $employee->risktypes[$alert->type] = true;
                if ($employee->riskseverity === '' ||
                        self::severity_rank($alert->severity) > self::severity_rank($employee->riskseverity)) {
                    $employee->riskseverity = (string)$alert->severity;
                }
                $alerts[] = $alert;
            }
            $employee->riskreasons = array_values(array_unique($employee->riskreasons));
            $employee->risktypes = array_keys($employee->risktypes);
            $employee->alertcount = count($employeealerts);
            if ($employee->alertcount > 0) {
                $affected[] = $employee;
            }
        }

        usort($alerts, [self::class, 'compare_alerts']);
        usort($affected, static function($a, $b) {
            $rankdiff = self::severity_rank((string)$b->riskseverity) <=> self::severity_rank((string)$a->riskseverity);
            if ($rankdiff !== 0) {
                return $rankdiff;
            }
            if ((int)$a->riskscore !== (int)$b->riskscore) {
                return (int)$b->riskscore <=> (int)$a->riskscore;
            }
            return strcasecmp((string)$a->fullname, (string)$b->fullname);
        });

        return (object)[
            'alerts' => $alerts,
            'employees' => $affected,
            'summary' => self::summary($alerts),
            'thresholds' => $thresholds,
        ];
    }

    /** Filter a flat alert list for the attention centre and exports. */
    public static function filter(array $alerts, string $severity = '', string $type = '', int $userid = 0): array {
        $validseverities = ['', self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW];
        $validtypes = array_merge([''], self::types());
        if (!in_array($severity, $validseverities, true)) {
            $severity = '';
        }
        if (!in_array($type, $validtypes, true)) {
            $type = '';
        }
        return array_values(array_filter($alerts, static function($alert) use ($severity, $type, $userid) {
            if ($severity !== '' && $alert->severity !== $severity) {
                return false;
            }
            if ($type !== '' && $alert->type !== $type) {
                return false;
            }
            if ($userid > 0 && (int)$alert->userid !== $userid) {
                return false;
            }
            return true;
        }));
    }

    /** Available machine-readable alert types. */
    public static function types(): array {
        return [
            self::TYPE_NOTENROLLED,
            self::TYPE_NOTSTARTED,
            self::TYPE_INACTIVE,
            self::TYPE_LOWPROGRESS,
            self::TYPE_LOWGRADE,
            self::TYPE_TRAININGPLAN,
            self::TYPE_WEBINAR,
            self::TYPE_CERTIFICATE,
        ];
    }

    /** Human-readable label for a type. */
    public static function type_label(string $type): string {
        return get_string('risktype_' . $type, 'local_kbacorporate');
    }

    /** Human-readable label for a severity. */
    public static function severity_label(string $severity): string {
        return get_string('riskseverity_' . $severity, 'local_kbacorporate');
    }

    /** Rank used for filtering, sorting and digest minimum severity. */
    public static function severity_rank(string $severity): int {
        switch ($severity) {
            case self::SEVERITY_HIGH:
                return 3;
            case self::SEVERITY_MEDIUM:
                return 2;
            case self::SEVERITY_LOW:
                return 1;
            default:
                return 0;
        }
    }

    /** Summary counters for alert cards and API. */
    public static function summary(array $alerts): \stdClass {
        $summary = (object)[
            'alerts' => count($alerts),
            'employees' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'highriskemployees' => 0,
            'mediumriskemployees' => 0,
            'lowriskemployees' => 0,
            'types' => [],
        ];
        $employeerisk = [];
        foreach ($alerts as $alert) {
            if (isset($summary->{$alert->severity})) {
                $summary->{$alert->severity}++;
            }
            if (!isset($summary->types[$alert->type])) {
                $summary->types[$alert->type] = 0;
            }
            $summary->types[$alert->type]++;
            $userid = (int)$alert->userid;
            if (!isset($employeerisk[$userid]) ||
                    self::severity_rank($alert->severity) > self::severity_rank($employeerisk[$userid])) {
                $employeerisk[$userid] = $alert->severity;
            }
        }
        $summary->employees = count($employeerisk);
        foreach ($employeerisk as $severity) {
            $property = $severity . 'riskemployees';
            if (isset($summary->{$property})) {
                $summary->{$property}++;
            }
        }
        ksort($summary->types);
        return $summary;
    }

    /** Alert comparator: severity, score, employee, type, course. */
    public static function compare_alerts($a, $b): int {
        $severity = self::severity_rank((string)$b->severity) <=> self::severity_rank((string)$a->severity);
        if ($severity !== 0) {
            return $severity;
        }
        if ((int)$a->score !== (int)$b->score) {
            return (int)$b->score <=> (int)$a->score;
        }
        $name = strcasecmp((string)$a->fullname, (string)$b->fullname);
        if ($name !== 0) {
            return $name;
        }
        $type = strcmp((string)$a->type, (string)$b->type);
        if ($type !== 0) {
            return $type;
        }
        return strcasecmp((string)$a->coursename, (string)$b->coursename);
    }

    /** Build one immutable calculated signal. */
    private static function make_alert(
        \stdClass $employee,
        string $type,
        string $severity,
        int $score,
        string $reason,
        int $courseid = 0,
        string $coursename = '',
        ?float $currentvalue = null,
        ?float $thresholdvalue = null,
        int $lastaccess = 0
    ): \stdClass {
        $key = sha1((int)$employee->userid . '|' . $type . '|' . $courseid);
        return (object)[
            'alertkey' => $key,
            'userid' => (int)$employee->userid,
            'fullname' => (string)$employee->fullname,
            'email' => (string)$employee->email,
            'position' => (string)$employee->position,
            'departments' => (string)$employee->departments,
            'type' => $type,
            'severity' => $severity,
            'score' => $score,
            'reason' => $reason,
            'courseid' => $courseid,
            'coursename' => $coursename,
            'currentvalue' => $currentvalue,
            'thresholdvalue' => $thresholdvalue,
            'lastaccess' => $lastaccess,
            'avgprogress' => $employee->avgprogress,
            'avggrade' => $employee->avggrade,
            'trainingpercent' => $employee->trainingpercent,
            'webinarattendancepercent' => $employee->webinarattendancepercent,
        ];
    }

    /** Read a bounded integer plugin setting. */
    private static function config_int(string $name, int $default, int $min, int $max): int {
        $value = get_config('local_kbacorporate', $name);
        if ($value === false || $value === null || $value === '') {
            $value = $default;
        }
        return max($min, min($max, (int)$value));
    }
}
