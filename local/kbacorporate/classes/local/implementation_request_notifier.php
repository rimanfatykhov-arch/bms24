<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Sends customer confirmation and administrator alerts for a new implementation request. */
final class implementation_request_notifier {
    /** Send request notifications without interrupting request creation on a delivery failure. */
    public static function send(\stdClass $program): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/messagelib.php');
        $company = $DB->get_record('local_kbacorp_company', ['id' => (int)$program->companyid]);
        $customer = \core_user::get_user((int)$program->requestedby);
        if (!$company || !$customer) {
            return;
        }

        $recipients = [];
        if (empty($customer->deleted) && empty($customer->suspended)) {
            $recipients[(int)$customer->id] = ['user' => $customer, 'customer' => true];
        }
        $context = \context_system::instance();
        $admins = get_users_by_capability($context, 'local/kbacorporate:manageimplementationprograms',
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, ' .
            'u.alternatename, u.email, u.deleted, u.suspended', 'u.id ASC');
        foreach ($admins ?: [] as $admin) {
            if (empty($admin->deleted) && empty($admin->suspended) && (int)$admin->id !== (int)$customer->id) {
                $fulladmin = \core_user::get_user((int)$admin->id);
                if ($fulladmin) {
                    $recipients[(int)$admin->id] = ['user' => $fulladmin, 'customer' => false];
                }
            }
        }

        foreach ($recipients as $recipient) {
            try {
                $message = self::build_message($program, $company, $recipient['user'], $recipient['customer']);
                if (message_send($message) === false) {
                    debugging('KBA implementation request notification was not delivered for program ' .
                        (int)$program->id, DEBUG_NORMAL);
                }
            } catch (\Throwable $exception) {
                debugging('KBA implementation request notification failed for program ' . (int)$program->id .
                    ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }
    }

    /** Notify the requester when an administrator changes the commercial or delivery status. */
    public static function send_status_changed(\stdClass $program): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/messagelib.php');
        $company = $DB->get_record('local_kbacorp_company', ['id' => (int)$program->companyid]);
        $customer = \core_user::get_user((int)$program->requestedby);
        if (!$company || !$customer || !empty($customer->deleted) || !empty($customer->suspended)) {
            return;
        }
        $package = implementation_packages::get_by_code((string)$program->package);
        $url = new \moodle_url('/local/kbacorporate/implementation.php', ['companyid' => (int)$company->id]);
        $data = (object)[
            'company' => format_string($company->name),
            'package' => $package ? format_string($package->name) : s($program->package),
            'commercialstatus' => get_string('commercialstatus' . $program->commercialstatus,
                'local_kbacorporate'),
            'programstatus' => get_string('programstatus' . $program->status, 'local_kbacorporate'),
            'comment' => trim((string)$program->customerresponse),
        ];
        $body = get_string('implementationstatuschangedbody', 'local_kbacorporate', $data);
        if ($data->comment !== '') {
            $body .= "\n\n" . get_string('implementationstatuscommentvalue', 'local_kbacorporate', $data->comment);
        }
        $message = new \core\message\message();
        $message->component = 'local_kbacorporate';
        $message->name = 'implementationrequest';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $customer;
        $message->subject = get_string('implementationstatuschangedsubject', 'local_kbacorporate', $data);
        $message->fullmessage = $body . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', nl2br(s($body))) .
            \html_writer::tag('p', \html_writer::link($url,
                get_string('openimplementationrequest', 'local_kbacorporate')));
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('openimplementationrequest', 'local_kbacorporate');
        $message->courseid = SITEID;
        try {
            if (message_send($message) === false) {
                debugging('KBA implementation status notification was not delivered for program ' .
                    (int)$program->id, DEBUG_NORMAL);
            }
        } catch (\Throwable $exception) {
            debugging('KBA implementation status notification failed for program ' . (int)$program->id .
                ': ' . $exception->getMessage(), DEBUG_NORMAL);
        }
    }

    /** Build one Moodle notification. */
    private static function build_message(\stdClass $program, \stdClass $company, \stdClass $recipient,
            bool $customer): \core\message\message {
        $url = new \moodle_url('/local/kbacorporate/implementation.php', ['companyid' => (int)$company->id]);
        $package = implementation_packages::get_by_code((string)$program->package);
        $data = (object)[
            'company' => format_string($company->name),
            'package' => $package ? format_string($package->name) : s($program->package),
            'date' => userdate((int)$program->timecreated, get_string('strftimedatefullshort')),
        ];
        $subjectkey = $customer ? 'implementationrequestcustomersubject' : 'implementationrequestadminsubject';
        $bodykey = $customer ? 'implementationrequestcustomerbody' : 'implementationrequestadminbody';
        $message = new \core\message\message();
        $message->component = 'local_kbacorporate';
        $message->name = 'implementationrequest';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = get_string($subjectkey, 'local_kbacorporate', $data);
        $message->fullmessage = get_string($bodykey, 'local_kbacorporate', $data) . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s(get_string($bodykey,
            'local_kbacorporate', $data))) . \html_writer::tag('p', \html_writer::link($url,
            get_string('openimplementationrequest', 'local_kbacorporate')));
        $message->smallmessage = get_string($subjectkey, 'local_kbacorporate', $data);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('openimplementationrequest', 'local_kbacorporate');
        $message->courseid = SITEID;
        return $message;
    }
}
