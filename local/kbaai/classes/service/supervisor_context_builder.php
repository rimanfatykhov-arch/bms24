<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();

/**
 * Builds the learning + conversation context for the temporary supervisor AI.
 *
 * Only current and already-reachable previous KBA Elements are included. Future
 * course content is never added. The final assessment pipeline remains separate.
 */
class supervisor_context_builder {

    /** @return string */
    public function plain_text($value, $limit = 18000) {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, (int)$limit, 'UTF-8');
        }
        return substr($text, 0, (int)$limit);
    }

    /** @return int */
    private function config_int($name, $default, $min, $max) {
        $value = (int)get_config('local_kbaai', $name);
        if ($value <= 0) { $value = (int)$default; }
        return max((int)$min, min((int)$max, $value));
    }

    /** @return array */
    private function normalise_learning(array $learning, $materiallimit = 18000, $assignmentlimit = 8000) {
        return array(
            'source' => !empty($learning['source']) ? (string)$learning['source'] : 'mod_kbaelement',
            'instanceid' => !empty($learning['instanceid']) ? (int)$learning['instanceid'] : 0,
            'title' => $this->plain_text(isset($learning['title']) ? $learning['title'] : '', 500),
            'learning_material' => $this->plain_text(isset($learning['learning_material']) ? $learning['learning_material'] : '', $materiallimit),
            'learning_objectives' => $this->plain_text(isset($learning['learning_objectives']) ? $learning['learning_objectives'] : '', 3500),
            'assignment' => $this->plain_text(isset($learning['assignment']) ? $learning['assignment'] : '', $assignmentlimit),
        );
    }

    /** @return int */
    private function context_chars(array $item) {
        return strlen((string)$item['title']) + strlen((string)$item['learning_material'])
            + strlen((string)$item['learning_objectives']) + strlen((string)$item['assignment']);
    }

    /** @return string */
    private function section_label($courseid, $sectionnum) {
        global $DB;
        $section = $DB->get_record('course_sections', array('course' => (int)$courseid, 'section' => (int)$sectionnum), 'id,name,section');
        if ($section && trim((string)$section->name) !== '') {
            return $this->plain_text($section->name, 300);
        }
        $prefix = strpos(current_language(), 'ru') === 0 ? 'Раздел' : 'Section';
        return $prefix . ' ' . (int)$sectionnum;
    }

    /**
     * Ordered KBA Element cmids that occur before the current activity and are
     * visible/available to the target student.
     *
     * @return array each item: cmid, instanceid, sectionnum, same_section
     */
    private function previous_elements($courseid, $currentcmid, $studentuserid, $limit) {
        $modinfo = get_fast_modinfo((int)$courseid, (int)$studentuserid);
        $current = $modinfo->get_cm((int)$currentcmid);
        $ordered = array();
        $seenCurrent = false;
        $sectioninfos = $modinfo->get_section_info_all();
        foreach ($sectioninfos as $sectioninfo) {
            $sectionnum = (int)$sectioninfo->section;
            if (empty($modinfo->sections[$sectionnum])) { continue; }
            foreach ($modinfo->sections[$sectionnum] as $cmid) {
                if ((int)$cmid === (int)$currentcmid) {
                    $seenCurrent = true;
                    break 2;
                }
                $cm = $modinfo->get_cm((int)$cmid);
                if ($cm->modname !== 'kbaelement') { continue; }
                if (!$cm->uservisible || !$cm->available) { continue; }
                $ordered[] = array(
                    'cmid' => (int)$cm->id,
                    'instanceid' => (int)$cm->instance,
                    'sectionnum' => (int)$cm->sectionnum,
                    'same_section' => ((int)$cm->sectionnum === (int)$current->sectionnum),
                );
            }
        }
        if (!$seenCurrent || !$ordered) { return array(); }

        // Prefer the nearest elements in the same section, then nearest prior
        // elements elsewhere in the course. Keep final order chronological.
        $same = array(); $other = array();
        for ($i = count($ordered) - 1; $i >= 0; $i--) {
            if ($ordered[$i]['same_section']) { $same[] = $ordered[$i]; }
            else { $other[] = $ordered[$i]; }
        }
        $picked = array();
        foreach (array_merge($same, $other) as $item) {
            $picked[$item['cmid']] = $item;
            if (count($picked) >= (int)$limit) { break; }
        }
        $picked = array_values($picked);
        usort($picked, function($a, $b) use ($ordered) {
            $positions = array();
            foreach ($ordered as $idx => $row) { $positions[$row['cmid']] = $idx; }
            return $positions[$a['cmid']] <=> $positions[$b['cmid']];
        });
        return $picked;
    }

    /** @return array */
    public function build_learning_context($element, $submission) {
        $adapter = new \local_kbaai\source\kbaelement_adapter();
        $cm = get_coursemodule_from_instance('kbaelement', $element->id, $element->course, false, MUST_EXIST);
        $current = $this->normalise_learning($adapter->get_learning_context($element->id), 22000, 10000);
        $current['cmid'] = (int)$cm->id;
        $current['sectionnum'] = (int)$cm->sectionnum;
        $current['section'] = $this->section_label($element->course, $cm->sectionnum);
        $current['relationship'] = 'current';

        $relatedlimit = $this->config_int('supervisorrelatedlimit', 4, 0, 8);
        $charlimit = $this->config_int('supervisorcontextchars', 32000, 12000, 70000);
        $remaining = max(0, $charlimit - $this->context_chars($current));
        $related = array();
        if ($relatedlimit > 0 && $remaining > 1000) {
            $previous = $this->previous_elements($element->course, $cm->id, $submission->userid, $relatedlimit);
            foreach (array_reverse($previous) as $item) {
                if ($remaining <= 1000) { break; }
                $candidate = $this->normalise_learning($adapter->get_learning_context($item['instanceid']), min(9000, $remaining), min(4500, $remaining));
                if ($candidate['learning_material'] === '' && $candidate['assignment'] === '' && $candidate['learning_objectives'] === '') {
                    continue;
                }
                $candidate['cmid'] = (int)$item['cmid'];
                $candidate['sectionnum'] = (int)$item['sectionnum'];
                $candidate['section'] = $this->section_label($element->course, $item['sectionnum']);
                $candidate['relationship'] = !empty($item['same_section']) ? 'previous_same_section' : 'previous_course';
                $chars = $this->context_chars($candidate);
                if ($chars > $remaining) {
                    // Re-normalise with the remaining budget, preserving assignment.
                    $raw = $adapter->get_learning_context($item['instanceid']);
                    $candidate = $this->normalise_learning($raw, max(500, $remaining - 2500), min(2200, $remaining));
                    $candidate['cmid'] = (int)$item['cmid'];
                    $candidate['sectionnum'] = (int)$item['sectionnum'];
                    $candidate['section'] = $this->section_label($element->course, $item['sectionnum']);
                    $candidate['relationship'] = !empty($item['same_section']) ? 'previous_same_section' : 'previous_course';
                    $chars = $this->context_chars($candidate);
                }
                $remaining -= $chars;
                $related[] = $candidate;
            }
            // We iterated nearest-first; provider should see course order.
            $related = array_reverse($related);
        }

        $relatedchars = 0;
        foreach ($related as $item) { $relatedchars += $this->context_chars($item); }
        return array(
            'current' => $current,
            'related' => $related,
            'meta' => array(
                'current_material_chars' => strlen($current['learning_material']),
                'current_assignment_chars' => strlen($current['assignment']),
                'current_objectives_chars' => strlen($current['learning_objectives']),
                'related_count' => count($related),
                'related_chars' => $relatedchars,
                'context_char_budget' => $charlimit,
                'future_elements_included' => false,
            ),
        );
    }

    /** @return array */
    public function build_conversation($submission, $sourceid = 0) {
        global $DB;
        $limit = $this->config_int('supervisorconversationlimit', 24, 8, 50);
        $params = array('submissionid' => (int)$submission->id);
        $where = 'submissionid = :submissionid';
        if ($sourceid > 0) {
            $where .= ' AND id < :sourceid';
            $params['sourceid'] = (int)$sourceid;
        }
        $messages = $DB->get_records_select('kbaelement_messages', $where, $params, 'id DESC', '*', 0, $limit);
        $messages = array_reverse($messages, true);
        $history = array();
        $hasai = false;
        $chars = 0;
        foreach ($messages as $m) {
            if (!empty($m->deleted)) { continue; }
            if (!empty($m->isai)) {
                $role = 'ai_assistant'; $hasai = true;
            } else if (property_exists($m, 'senderrole') && $m->senderrole !== '') {
                $role = $m->senderrole === 'student' ? 'student' : 'supervisor';
            } else {
                $role = ((int)$m->userid === (int)$submission->userid) ? 'student' : 'supervisor';
            }
            $text = $this->plain_text($m->message, 4000);
            if ($text === '') { continue; }
            $history[] = array('id' => (int)$m->id, 'role' => $role, 'text' => $text);
            $chars += strlen($text);
        }
        return array(
            'messages' => $history,
            'meta' => array(
                'message_count' => count($history),
                'conversation_chars' => $chars,
                'has_previous_ai_reply' => $hasai,
                'first_assistant_reply' => !$hasai,
                'message_limit' => $limit,
            ),
        );
    }

    /**
     * Build the exact provider-facing context for one student message.
     * @return array
     */
    public function build_for_message($message, $submission, $element, $supervisorinstructions = '') {
        $learning = $this->build_learning_context($element, $submission);
        $conversation = $this->build_conversation($submission, (int)$message->id);
        return array(
            'learningcontext' => $learning,
            'conversation' => $conversation['messages'],
            'conversationmeta' => $conversation['meta'],
            'studentmessage' => $this->plain_text($message->message, 4000),
            'officialanswer' => $this->plain_text(isset($submission->onlinetext) ? $submission->onlinetext : '', 5000),
            'supervisorinstructions' => $this->plain_text($supervisorinstructions, 6000),
        );
    }

    /**
     * Admin preview used by "What does AI see?" diagnostics.
     * @return array
     */
    public function preview($cmid, $submissionid = 0) {
        global $DB;
        $cm = get_coursemodule_from_id('kbaelement', (int)$cmid, 0, false, MUST_EXIST);
        $element = $DB->get_record('kbaelement', array('id' => (int)$cm->instance), '*', MUST_EXIST);
        if ($submissionid) {
            $submission = $DB->get_record('kbaelement_submissions', array('id' => (int)$submissionid, 'kbaelementid' => (int)$element->id), '*', MUST_EXIST);
        } else {
            $submission = $DB->get_record('kbaelement_submissions', array('kbaelementid' => (int)$element->id), '*', IGNORE_MULTIPLE);
            if (!$submission) {
                global $USER;
                $submission = (object)array('id' => 0, 'userid' => (int)$USER->id, 'onlinetext' => '');
            }
        }
        $learning = $this->build_learning_context($element, $submission);
        $conversation = $submission->id ? $this->build_conversation($submission, 0) : array('messages'=>array(),'meta'=>array(
            'message_count'=>0,'conversation_chars'=>0,'has_previous_ai_reply'=>false,'first_assistant_reply'=>true,
            'message_limit'=>$this->config_int('supervisorconversationlimit',24,8,50)));
        return array(
            'cm' => $cm,
            'element' => $element,
            'submission' => $submission,
            'learningcontext' => $learning,
            'conversation' => $conversation,
            'officialanswer' => $this->plain_text(isset($submission->onlinetext) ? $submission->onlinetext : '', 5000),
        );
    }
}
