<?php
namespace local_kbaai\output;
defined('MOODLE_INTERNAL') || die();
class teacher_presenter {
    public static function status_label($assessment) {
        if (!$assessment) { return get_string('status_notassessed', 'local_kbaai'); }
        if ($assessment->status === 'queued') { return get_string('status_queued', 'local_kbaai'); }
        if ($assessment->status === 'processing') { return get_string('status_processing', 'local_kbaai'); }
        if ($assessment->status === 'failed') { return get_string('status_failed', 'local_kbaai'); }
        if ($assessment->publishstatus === 'published') { return get_string('status_published', 'local_kbaai'); }
        if ($assessment->status === 'completed' && $assessment->reviewstatus === 'needsreview') { return get_string('status_needsreview', 'local_kbaai'); }
        if ($assessment->status === 'completed' && $assessment->mode === 'tutor') { return get_string('status_feedbackready', 'local_kbaai'); }
        if ($assessment->status === 'completed') { return get_string('status_completed', 'local_kbaai'); }
        return get_string('status_unknown', 'local_kbaai');
    }
    public static function status_class($assessment) {
        if (!$assessment) { return 'badge badge-secondary'; }
        if ($assessment->status === 'failed') { return 'badge badge-danger'; }
        if ($assessment->publishstatus === 'published') { return 'badge badge-success'; }
        if ($assessment->status === 'completed' && $assessment->reviewstatus === 'needsreview') { return 'badge badge-warning'; }
        if ($assessment->status === 'completed') { return 'badge badge-info'; }
        return 'badge badge-secondary';
    }
    public static function result($assessment) {
        $decoded = json_decode((string)$assessment->resultjson, true);
        if (!is_array($decoded)) { return array(); }
        // Older Mock runs stored English development text. Localise them at
        // presentation time so an upgrade immediately produces a Russian UI.
        if (!empty($assessment->provider) && $assessment->provider === 'mock') {
            $decoded['strengths'] = array(get_string('mockstrength', 'local_kbaai'));
            $decoded['improvements'] = array(get_string('mockimprovement', 'local_kbaai'));
            $decoded['student_feedback'] = get_string('mockstudentfeedback', 'local_kbaai');
            $decoded['teacher_note'] = get_string('mockteachernote', 'local_kbaai');
        }
        return $decoded;
    }
}
