<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends calculated learning-attention digests to corporate and department managers.
 *
 * Alerts themselves are not persisted. Each recipient receives a digest built from
 * the employees that the existing Corporate Dashboard access rules allow them to see.
 */
class attention_notifier {
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';

    /** Run all due company digests. */
    public static function run(): \stdClass {
        global $DB;

        $stats = (object)[
            'companies' => 0,
            'due' => 0,
            'recipients' => 0,
            'sent' => 0,
            'empty' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $companies = $DB->get_records('local_kbacorp_company', [
            'active' => 1,
            'alertsenabled' => 1,
        ], 'id ASC');
        $stats->companies = count($companies);

        foreach ($companies as $company) {
            if (!self::is_due($company)) {
                $stats->skipped++;
                continue;
            }
            $stats->due++;
            try {
                $result = self::send_company((int)$company->id, false, true);
                $stats->recipients += (int)$result->recipients;
                $stats->sent += (int)$result->sent;
                $stats->empty += (int)$result->empty;
                $stats->errors += (int)$result->errors;
                $stats->skipped += (int)$result->skipped;
            } catch (\Throwable $exception) {
                $stats->errors++;
                debugging('KBA Corporate Dashboard attention digest failed for company ' .
                    (int)$company->id . ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }

        return $stats;
    }

    /** Return notification configuration and scheduling status for one company. */
    public static function status(\stdClass $company): array {
        $frequency = self::normalise_frequency((string)($company->alertfrequency ?? self::FREQUENCY_WEEKLY));
        $lastsent = (int)($company->lastalertsent ?? 0);
        $enabled = !empty($company->active) && !empty($company->alertsenabled);
        $interval = self::interval_seconds($frequency);
        $nextdue = $enabled ? ($lastsent > 0 ? $lastsent + $interval : time()) : 0;

        return [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'minimumseverity' => self::minimum_severity(),
            'lastsent' => $lastsent,
            'nextdue' => $nextdue,
            'due' => $enabled && self::is_due($company),
            'recipients' => count(self::get_recipients((int)$company->id)),
        ];
    }

    /** Normalise a stored or submitted frequency. */
    public static function normalise_frequency(string $frequency): string {
        return $frequency === self::FREQUENCY_DAILY ? self::FREQUENCY_DAILY : self::FREQUENCY_WEEKLY;
    }

    /** Check whether a company is due for its next digest. */
    public static function is_due(\stdClass $company, ?int $now = null): bool {
        if (empty($company->active) || empty($company->alertsenabled)) {
            return false;
        }
        $now = $now ?? time();
        $lastsent = (int)($company->lastalertsent ?? 0);
        if ($lastsent <= 0) {
            return true;
        }
        $frequency = self::normalise_frequency((string)($company->alertfrequency ?? self::FREQUENCY_WEEKLY));
        return $lastsent + self::interval_seconds($frequency) <= $now;
    }

    /**
     * Send one company digest, respecting per-recipient department scope.
     *
     * @param int $companyid
     * @param bool $force Ignore the schedule and company alert toggle. Used by the administrator action.
     * @param bool $markrun Update lastalertsent after an error-free run.
     * @return \stdClass
     */
    public static function send_company(int $companyid, bool $force = false, bool $markrun = true): \stdClass {
        global $CFG, $DB;

        $result = (object)[
            'companyid' => $companyid,
            'due' => 0,
            'recipients' => 0,
            'sent' => 0,
            'empty' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $company = $DB->get_record('local_kbacorp_company', ['id' => $companyid]);
        if (!$company || empty($company->active)) {
            $result->skipped = 1;
            return $result;
        }
        if (!$force && !self::is_due($company)) {
            $result->skipped = 1;
            return $result;
        }
        $result->due = 1;

        require_once($CFG->libdir . '/messagelib.php');

        $recipients = self::get_recipients($companyid);
        $result->recipients = count($recipients);
        $frequency = self::normalise_frequency((string)($company->alertfrequency ?? self::FREQUENCY_WEEKLY));
        $preset = $frequency === self::FREQUENCY_DAILY ? 'week' : 'month';
        $minimumseverity = self::minimum_severity();

        foreach ($recipients as $recipient) {
            try {
                $visibleids = access::get_visible_employee_ids($companyid, (int)$recipient->id);
                if (!$visibleids) {
                    $result->empty++;
                    continue;
                }

                $report = management_report::build($companyid, $preset, '', '', $visibleids);
                $alerts = self::filter_by_minimum_severity($report->alerts, $minimumseverity);
                if (!$alerts) {
                    $result->empty++;
                    continue;
                }

                $message = self::build_message($company, $recipient, $report, $alerts, $minimumseverity);
                $messageid = message_send($message);
                if ($messageid === false) {
                    $result->errors++;
                } else {
                    $result->sent++;
                }
            } catch (\Throwable $exception) {
                $result->errors++;
                debugging('KBA Corporate Dashboard attention digest failed for company ' . $companyid .
                    ', user ' . (int)$recipient->id . ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }

        // Mark even an empty successful run. This prevents cron from recalculating the same
        // company every day until the next configured interval.
        if ($markrun && $result->errors === 0) {
            $DB->set_field('local_kbacorp_company', 'lastalertsent', time(), ['id' => $companyid]);
        }

        return $result;
    }

    /** Return active corporate and department managers for a company, without duplicates. */
    public static function get_recipients(int $companyid): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'local_kbacorp_member',
            'userid',
            'companyid = :companyid AND ismanager = :ismanager',
            ['companyid' => $companyid, 'ismanager' => 1]
        );
        $departmentids = $DB->get_fieldset_select(
            'local_kbacorp_department',
            'id',
            'companyid = :companyid AND active = 1',
            ['companyid' => $companyid]
        );
        if ($departmentids) {
            list($insql, $params) = $DB->get_in_or_equal(
                array_map('intval', $departmentids),
                SQL_PARAMS_NAMED,
                'attentiondep'
            );
            $params['role'] = 'manager';
            $departmentmanagerids = $DB->get_fieldset_select(
                'local_kbacorp_dept_member',
                'userid',
                "departmentid {$insql} AND deptrole = :role",
                $params
            );
            $ids = array_merge($ids, $departmentmanagerids);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', $ids, 'lastname ASC, firstname ASC, id ASC');
        $context = \context_system::instance();
        foreach ($users as $userid => $user) {
            if (!empty($user->deleted) || !empty($user->suspended) ||
                    !has_capability('local/kbacorporate:viewalerts', $context, (int)$userid)) {
                unset($users[$userid]);
            }
        }
        return $users;
    }

    /** Filter alerts at or above the configured minimum severity. */
    private static function filter_by_minimum_severity(array $alerts, string $minimumseverity): array {
        $minimumrank = attention::severity_rank($minimumseverity);
        return array_values(array_filter($alerts, static function($alert) use ($minimumrank) {
            return attention::severity_rank((string)$alert->severity) >= $minimumrank;
        }));
    }

    /** Build a Moodle notification message. */
    private static function build_message(
        \stdClass $company,
        \stdClass $recipient,
        \stdClass $report,
        array $alerts,
        string $minimumseverity
    ): \core\message\message {
        $summary = attention::summary($alerts);
        $companyname = format_string($company->name);
        $periodlabel = $report->period->from . ' — ' . $report->period->to;
        $url = new \moodle_url('/local/kbacorporate/alerts.php', [
            'companyid' => (int)$company->id,
            'preset' => $report->period->preset,
            'from' => $report->period->from,
            'to' => $report->period->to,
        ]);
        $urlstring = $url->out(false);

        $subject = get_string('attentiondigestsubject', 'local_kbacorporate', (object)[
            'company' => $companyname,
            'count' => $summary->alerts,
        ]);
        $countline = get_string('attentiondigestcounts', 'local_kbacorporate', (object)[
            'employees' => $summary->employees,
            'high' => $summary->high,
            'medium' => $summary->medium,
            'low' => $summary->low,
        ]);

        $plainlines = [
            get_string('attentiondigestheading', 'local_kbacorporate', $companyname),
            get_string('attentiondigestperiod', 'local_kbacorporate', $periodlabel),
            $countline,
            '',
        ];
        $htmlitems = [];
        $limit = 15;
        foreach (array_slice($alerts, 0, $limit) as $alert) {
            $line = $alert->fullname . ' — ' . attention::type_label((string)$alert->type) . ': ' . $alert->reason;
            $plainlines[] = '• ' . $line;
            $htmlitems[] = \html_writer::tag('li', s($line));
        }
        if (count($alerts) > $limit) {
            $more = get_string('attentiondigestmore', 'local_kbacorporate', count($alerts) - $limit);
            $plainlines[] = $more;
            $htmlitems[] = \html_writer::tag('li', s($more));
        }
        $plainlines[] = '';
        $plainlines[] = get_string('attentiondigestopen', 'local_kbacorporate') . ': ' . $urlstring;

        $html = \html_writer::tag('h3', s(get_string('attentiondigestheading', 'local_kbacorporate', $companyname))) .
            \html_writer::tag('p', s(get_string('attentiondigestperiod', 'local_kbacorporate', $periodlabel))) .
            \html_writer::tag('p', s($countline)) .
            \html_writer::tag('ul', implode('', $htmlitems)) .
            \html_writer::tag('p', \html_writer::link($url, get_string('attentiondigestopen', 'local_kbacorporate')));

        $message = new \core\message\message();
        $message->component = 'local_kbacorporate';
        $message->name = 'attentiondigest';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = implode("\n", $plainlines);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = get_string('attentiondigestshort', 'local_kbacorporate', (object)[
            'company' => $companyname,
            'count' => $summary->alerts,
        ]);
        $message->notification = 1;
        $message->contexturl = $urlstring;
        $message->contexturlname = get_string('attentiondigestopen', 'local_kbacorporate');
        $message->courseid = SITEID;
        $message->customdata = [
            'companyid' => (int)$company->id,
            'minimumseverity' => $minimumseverity,
            'alertcount' => $summary->alerts,
        ];
        return $message;
    }

    /** Minimum severity configured by the administrator. */
    private static function minimum_severity(): string {
        $severity = (string)get_config('local_kbacorporate', 'attentiondigestminseverity');
        if (!in_array($severity, [attention::SEVERITY_HIGH, attention::SEVERITY_MEDIUM, attention::SEVERITY_LOW], true)) {
            return attention::SEVERITY_MEDIUM;
        }
        return $severity;
    }

    /** Practical interval with a small cron tolerance. */
    private static function interval_seconds(string $frequency): int {
        if ($frequency === self::FREQUENCY_DAILY) {
            return 20 * HOURSECS;
        }
        return (6 * DAYSECS) + (20 * HOURSECS);
    }
}
