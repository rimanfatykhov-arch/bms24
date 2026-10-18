<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();

/**
 * Service for the temporary AI assistant of an absent course supervisor.
 *
 * Assessment and conversation stay deliberately separate: discussion messages
 * can help the assistant answer, but are never sent to the grading pipeline.
 */
class supervisor_service {

    /** @return bool */
    private static function is_student_message($message, $submission) {
        if (!empty($message->isai) || !empty($message->deleted)) {
            return false;
        }
        if (property_exists($message, 'senderrole') && $message->senderrole !== '') {
            return $message->senderrole === 'student';
        }
        return (int)$message->userid === (int)$submission->userid;
    }

    /** @return stdClass */
    private static function create_or_get_reply($message, $submission, $element) {
        global $DB;
        $existing = $DB->get_record('local_kbaai_supreply', array('sourcemessageid' => (int)$message->id));
        if ($existing) {
            return $existing;
        }
        $now = time();
        $record = (object)array(
            'courseid' => (int)$element->course,
            'submissionid' => (int)$submission->id,
            'sourcemessageid' => (int)$message->id,
            'replymessageid' => 0,
            'provider' => (string)(get_config('local_kbaai', 'defaultprovider') ?: 'mock'),
            'model' => (string)(get_config('local_kbaai', 'defaultmodel') ?: ''),
            'status' => 'queued',
            'requiressupervisor' => 0,
            'reason' => '',
            'lasterror' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timecompleted' => 0,
        );
        $record->id = $DB->insert_record('local_kbaai_supreply', $record);
        return $record;
    }

    /**
     * Cron-compatible fallback queue. Used when JavaScript is unavailable or a
     * standard (non-live) discussion form was submitted.
     *
     * @param int $messageid
     * @return int reply tracking id or 0 when no AI reply should be created.
     */
    public static function queue_for_message($messageid) {
        global $DB;
        $message = $DB->get_record('kbaelement_messages', array('id' => (int)$messageid), '*', MUST_EXIST);
        $submission = $DB->get_record('kbaelement_submissions', array('id' => (int)$message->submissionid), '*', MUST_EXIST);
        if (!self::is_student_message($message, $submission)) {
            return 0;
        }
        $element = $DB->get_record('kbaelement', array('id' => (int)$submission->kbaelementid), '*', MUST_EXIST);
        $cfg = (new \local_kbaai\repository\supervisor_repository())->get($element->course);
        if (empty($cfg->enabled) || empty($cfg->away) || empty($cfg->supervisoruserid)) {
            return 0;
        }
        $reply = self::create_or_get_reply($message, $submission, $element);
        if ($reply->status === 'completed' || $reply->status === 'processing') {
            return (int)$reply->id;
        }
        $reply->status = 'queued';
        $reply->lasterror = '';
        $reply->timemodified = time();
        $DB->update_record('local_kbaai_supreply', $reply);

        $task = new \local_kbaai\task\reply_as_supervisor();
        $task->set_custom_data(array('replyid' => (int)$reply->id));
        \core\task\manager::queue_adhoc_task($task);
        return (int)$reply->id;
    }

    /**
     * Generate the AI answer immediately for live chat, without waiting for cron.
     * The same persistent tracking row is used, so the operation is idempotent.
     *
     * @param int $messageid
     * @return int Created/existing kbaelement_messages id, or 0 if assistant is off.
     */
    public static function reply_for_message_now($messageid) {
        global $DB;
        $message = $DB->get_record('kbaelement_messages', array('id' => (int)$messageid), '*', MUST_EXIST);
        $submission = $DB->get_record('kbaelement_submissions', array('id' => (int)$message->submissionid), '*', MUST_EXIST);
        if (!self::is_student_message($message, $submission)) {
            return 0;
        }
        $element = $DB->get_record('kbaelement', array('id' => (int)$submission->kbaelementid), '*', MUST_EXIST);
        $cfg = (new \local_kbaai\repository\supervisor_repository())->get($element->course);
        if (empty($cfg->enabled) || empty($cfg->away) || empty($cfg->supervisoruserid)) {
            return 0;
        }
        $reply = self::create_or_get_reply($message, $submission, $element);
        if ($reply->status === 'completed' && !empty($reply->replymessageid)) {
            return (int)$reply->replymessageid;
        }
        try {
            (new self())->process((int)$reply->id);
        } catch (\Throwable $e) {
            (new self())->fail((int)$reply->id, $e->getMessage());
            throw $e;
        }
        $reply = $DB->get_record('local_kbaai_supreply', array('id' => (int)$reply->id), '*', MUST_EXIST);
        return !empty($reply->replymessageid) ? (int)$reply->replymessageid : 0;
    }

    /** @return string */
    private function plain_text($value, $limit = 18000) {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, (int)$limit, 'UTF-8');
        }
        return substr($text, 0, (int)$limit);
    }

    /**
     * Process one persistent supervisor reply record.
     *
     * @param int $replyid
     */
    public function process($replyid) {
        global $CFG, $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_kbaai');
        $lock = $factory->get_lock('supervisor_reply_' . (int)$replyid, 10);
        if (!$lock) {
            // Another request/task is already generating the same reply.
            // The live client will receive it through polling when it appears.
            return;
        }
        try {
            $reply = $DB->get_record('local_kbaai_supreply', array('id' => (int)$replyid), '*', MUST_EXIST);
            if ($reply->status === 'completed' && !empty($reply->replymessageid)) {
                return;
            }
            $reply->status = 'processing';
            $reply->lasterror = '';
            $reply->timemodified = time();
            $DB->update_record('local_kbaai_supreply', $reply);

            $source = $DB->get_record('kbaelement_messages', array('id' => (int)$reply->sourcemessageid), '*', MUST_EXIST);
            if (!empty($source->deleted)) {
                $reply->status = 'cancelled';
                $reply->reason = 'source_message_deleted';
                $reply->timemodified = time();
                $DB->update_record('local_kbaai_supreply', $reply);
                return;
            }

            $submission = $DB->get_record('kbaelement_submissions', array('id' => (int)$reply->submissionid), '*', MUST_EXIST);
            if (!self::is_student_message($source, $submission)) {
                $reply->status = 'cancelled';
                $reply->reason = 'source_not_student_message';
                $reply->timemodified = time();
                $DB->update_record('local_kbaai_supreply', $reply);
                return;
            }
            $element = $DB->get_record('kbaelement', array('id' => (int)$submission->kbaelementid), '*', MUST_EXIST);
            $cfg = (new \local_kbaai\repository\supervisor_repository())->get($element->course);
            if (empty($cfg->enabled) || empty($cfg->away) || empty($cfg->supervisoruserid)) {
                $reply->status = 'cancelled';
                $reply->reason = 'supervisor_is_present';
                $reply->timemodified = time();
                $DB->update_record('local_kbaai_supreply', $reply);
                return;
            }

            $cm = get_coursemodule_from_instance('kbaelement', $element->id, $element->course, false, MUST_EXIST);
            $course = get_course($element->course);
            $context = \context_module::instance($cm->id);
            $contextbuilder = new \local_kbaai\service\supervisor_context_builder();
            $smartcontext = $contextbuilder->build_for_message($source, $submission, $element, (string)$cfg->instructions);

            $request = new \local_kbaai\dto\supervisor_request(array(
                'language' => current_language(),
                'model' => $reply->model,
                'learningcontext' => $smartcontext['learningcontext'],
                'conversation' => $smartcontext['conversation'],
                'conversationmeta' => $smartcontext['conversationmeta'],
                'studentmessage' => $smartcontext['studentmessage'],
                'officialanswer' => $smartcontext['officialanswer'],
                'supervisorinstructions' => $smartcontext['supervisorinstructions'],
            ));

            $provider = \local_kbaai\provider\provider_factory::create($reply->provider);
            $response = $provider->generate_supervisor_reply($request);
            $result = $response->result;
            if (empty($result['reply'])) {
                throw new \local_kbaai\provider\provider_exception('empty_supervisor_reply', 'AI assistant returned an empty reply.', true);
            }

            $replytext = trim((string)$result['reply']);
            $firstreply = !empty($request->conversationmeta['first_assistant_reply']);
            if ($firstreply && stripos($replytext, 'ИИ-помощник') === false && stripos($replytext, 'AI assistant') === false) {
                $replytext = get_string('supervisordisclosure', 'local_kbaai') . "\n\n" . $replytext;
            }
            $record = (object)array(
                'submissionid' => (int)$submission->id,
                'userid' => (int)$cfg->supervisoruserid,
                'isai' => 1,
                'message' => $replytext,
                'messageformat' => FORMAT_PLAIN,
                'timecreated' => time(),
                'timemodified' => time(),
            );
            $columns = $DB->get_columns('kbaelement_messages');
            if (isset($columns['senderrole'])) {
                $record->senderrole = 'ai';
            }
            if (isset($columns['deleted'])) {
                $record->deleted = 0;
                $record->deletedby = 0;
                $record->timedeleted = 0;
            }
            $record->id = $DB->insert_record('kbaelement_messages', $record);

            require_once($CFG->dirroot . '/mod/kbaelement/locallib.php');
            kbaelement_send_discussion_notifications($record, $submission, $element, $course, $cm, $context);

            $reply->replymessageid = (int)$record->id;
            $reply->status = 'completed';
            $reply->requiressupervisor = !empty($result['requires_supervisor']) ? 1 : 0;
            $reply->reason = !empty($result['reason']) ? (string)$result['reason'] : '';
            $reply->timecompleted = time();
            $reply->timemodified = time();
            $DB->update_record('local_kbaai_supreply', $reply);
        } finally {
            $lock->release();
        }
    }

    /** @param int $replyid @param string $message */
    public function fail($replyid, $message) {
        global $DB;
        $r = $DB->get_record('local_kbaai_supreply', array('id' => (int)$replyid), '*', MUST_EXIST);
        $r->status = 'failed';
        $r->lasterror = (string)$message;
        $r->timemodified = time();
        $DB->update_record('local_kbaai_supreply', $r);
    }
}
