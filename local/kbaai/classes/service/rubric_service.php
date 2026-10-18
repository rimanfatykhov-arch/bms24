<?php
namespace local_kbaai\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Visual rubric storage and backwards-compatible conversion to AI criteria.
 */
class rubric_service {
    const MAX_CRITERIA = 20;
    const MAX_LEVELS = 10;

    /** Return current rubric, falling back to legacy rubricjson/default criterion. */
    public function get($kbaelementid, $maxscore) {
        global $DB;
        $kbaelementid = (int)$kbaelementid;
        $maxscore = (float)$maxscore;
        $rubric = $DB->get_record('local_kbaai_rubric', array('kbaelementid' => $kbaelementid));
        if ($rubric) {
            $criteria = array();
            $items = $DB->get_records('local_kbaai_rubric_item', array('rubricid' => $rubric->id), 'sortorder ASC, id ASC');
            foreach ($items as $item) {
                $levels = array();
                $records = $DB->get_records('local_kbaai_rubric_level', array('itemid' => $item->id), 'sortorder ASC, score ASC, id ASC');
                foreach ($records as $level) {
                    $levels[] = array(
                        'score' => (float)$level->score,
                        'label' => (string)$level->label,
                        'description' => (string)$level->description,
                    );
                }
                $criteria[] = array(
                    'id' => (string)$item->criterionkey,
                    'title' => (string)$item->title,
                    'description' => (string)$item->description,
                    'maxscore' => (float)$item->maxscore,
                    'required' => !empty($item->required),
                    'levels' => $levels,
                );
            }
            return array(
                'id' => (int)$rubric->id,
                'versionno' => (int)$rubric->versionno,
                'criteria' => $criteria,
                'total' => (float)$rubric->totalscore,
                'maxscore' => $maxscore,
                'valid' => !empty($rubric->valid) && abs((float)$rubric->totalscore - $maxscore) <= 0.01,
                'source' => 'visual',
            );
        }

        $legacy = $DB->get_field('local_kbaai_instance', 'rubricjson', array('kbaelementid' => $kbaelementid));
        $criteria = $this->parse_legacy($legacy, $maxscore);
        $total = $this->sum($criteria);
        return array(
            'id' => 0,
            'versionno' => 0,
            'criteria' => $criteria,
            'total' => $total,
            'maxscore' => $maxscore,
            'valid' => abs($total - $maxscore) <= 0.01,
            'source' => trim((string)$legacy) === '' ? 'default' : 'legacy',
        );
    }

    /** Criteria shape used by queue/prompt/result validator. */
    public function get_for_assessment($kbaelementid, $maxscore) {
        $rubric = $this->get($kbaelementid, $maxscore);
        if (empty($rubric['criteria']) || empty($rubric['valid'])) {
            throw new \moodle_exception('rubricmaxmismatch', 'local_kbaai');
        }
        return $rubric;
    }

    /** Save a validated visual rubric and synchronise legacy rubricjson. */
    public function save($kbaelementid, $payloadjson, $userid, $maxscore) {
        global $DB;
        $payload = json_decode((string)$payloadjson, true);
        if (!is_array($payload)) {
            throw new \moodle_exception('rubricpayloadinvalid', 'local_kbaai');
        }
        $criteria = isset($payload['criteria']) && is_array($payload['criteria']) ? $payload['criteria'] : array();
        $criteria = $this->normalise_and_validate($criteria, (float)$maxscore);
        $total = $this->sum($criteria);
        $now = time();
        $transaction = $DB->start_delegated_transaction();

        $rubric = $DB->get_record('local_kbaai_rubric', array('kbaelementid' => (int)$kbaelementid));
        if (!$rubric) {
            $rubric = (object)array(
                'kbaelementid' => (int)$kbaelementid,
                'versionno' => 1,
                'totalscore' => $total,
                'valid' => 1,
                'modifiedby' => (int)$userid,
                'timecreated' => $now,
                'timemodified' => $now,
            );
            $rubric->id = $DB->insert_record('local_kbaai_rubric', $rubric);
        } else {
            $rubric->versionno = (int)$rubric->versionno + 1;
            $rubric->totalscore = $total;
            $rubric->valid = 1;
            $rubric->modifiedby = (int)$userid;
            $rubric->timemodified = $now;
            $DB->update_record('local_kbaai_rubric', $rubric);
            $olditems = $DB->get_records('local_kbaai_rubric_item', array('rubricid' => $rubric->id), '', 'id');
            if ($olditems) {
                $itemids = array_keys($olditems);
                list($insql, $params) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('local_kbaai_rubric_level', 'itemid ' . $insql, $params);
            }
            $DB->delete_records('local_kbaai_rubric_item', array('rubricid' => $rubric->id));
        }

        foreach ($criteria as $sort => $criterion) {
            $item = (object)array(
                'rubricid' => (int)$rubric->id,
                'criterionkey' => (string)$criterion['id'],
                'sortorder' => (int)$sort,
                'title' => (string)$criterion['title'],
                'description' => (string)$criterion['description'],
                'maxscore' => (float)$criterion['maxscore'],
                'required' => !empty($criterion['required']) ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            );
            $itemid = $DB->insert_record('local_kbaai_rubric_item', $item);
            foreach ($criterion['levels'] as $levelsort => $level) {
                $DB->insert_record('local_kbaai_rubric_level', (object)array(
                    'itemid' => $itemid,
                    'sortorder' => (int)$levelsort,
                    'score' => (float)$level['score'],
                    'label' => (string)$level['label'],
                    'description' => (string)$level['description'],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ));
            }
        }

        // Keep legacy field populated for backwards compatibility and old snapshots/tools.
        $legacyjson = json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $instance = $DB->get_record('local_kbaai_instance', array('kbaelementid' => (int)$kbaelementid));
        if ($instance) {
            $instance->rubricjson = $legacyjson;
            $instance->modifiedby = (int)$userid;
            $instance->timemodified = $now;
            $DB->update_record('local_kbaai_instance', $instance);
        }
        $transaction->allow_commit();
        return $this->get($kbaelementid, $maxscore);
    }

    private function normalise_and_validate(array $criteria, $maxscore) {
        if (!$criteria || count($criteria) > self::MAX_CRITERIA) {
            throw new \moodle_exception('rubriccriterioncount', 'local_kbaai');
        }
        $out = array();
        $seen = array();
        foreach (array_values($criteria) as $i => $criterion) {
            if (!is_array($criterion)) {
                throw new \moodle_exception('rubricpayloadinvalid', 'local_kbaai');
            }
            $title = trim(clean_param(isset($criterion['title']) ? $criterion['title'] : '', PARAM_TEXT));
            $description = trim((string)(isset($criterion['description']) ? $criterion['description'] : ''));
            $mx = isset($criterion['maxscore']) && is_numeric($criterion['maxscore']) ? (float)$criterion['maxscore'] : 0.0;
            if ($title === '' || $mx <= 0 || $mx > $maxscore) {
                throw new \moodle_exception('rubriccriterioninvalid', 'local_kbaai');
            }
            $id = isset($criterion['id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$criterion['id']) : '';
            if ($id === '' || isset($seen[$id])) {
                $id = 'C' . ($i + 1);
                while (isset($seen[$id])) { $id .= 'x'; }
            }
            $seen[$id] = true;
            $levels = isset($criterion['levels']) && is_array($criterion['levels']) ? $criterion['levels'] : array();
            $levels = $this->normalise_levels($levels, $mx);
            $out[] = array(
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'maxscore' => $mx,
                'required' => !isset($criterion['required']) || !empty($criterion['required']),
                'levels' => $levels,
            );
        }
        $sum = $this->sum($out);
        if (abs($sum - (float)$maxscore) > 0.01) {
            throw new \moodle_exception('rubricmaxmismatchvisual', 'local_kbaai', '', (object)array(
                'rubrictotal' => format_float($sum, 2),
                'maxscore' => format_float($maxscore, 2),
            ));
        }
        return $out;
    }

    private function normalise_levels(array $levels, $maxscore) {
        $out = array();
        foreach (array_slice(array_values($levels), 0, self::MAX_LEVELS) as $level) {
            if (!is_array($level) || !isset($level['score']) || !is_numeric($level['score'])) { continue; }
            $score = (float)$level['score'];
            if ($score < 0 || $score > $maxscore) { continue; }
            $out[] = array(
                'score' => $score,
                'label' => trim(clean_param(isset($level['label']) ? $level['label'] : '', PARAM_TEXT)),
                'description' => trim((string)(isset($level['description']) ? $level['description'] : '')),
            );
        }
        if (count($out) < 2) {
            $out = $this->default_levels($maxscore);
        }
        usort($out, function($a, $b) { return $a['score'] <=> $b['score']; });
        // De-duplicate score anchors.
        $unique = array();
        foreach ($out as $level) { $unique[(string)$level['score']] = $level; }
        return array_values($unique);
    }

    private function default_levels($maxscore) {
        $half = round(((float)$maxscore) / 2, 2);
        return array(
            array('score' => 0.0, 'label' => get_string('rubriclevelnotmet', 'local_kbaai'), 'description' => ''),
            array('score' => $half, 'label' => get_string('rubriclevelpartial', 'local_kbaai'), 'description' => ''),
            array('score' => (float)$maxscore, 'label' => get_string('rubriclevelfull', 'local_kbaai'), 'description' => ''),
        );
    }

    private function parse_legacy($json, $maxscore) {
        $json = trim((string)$json);
        if ($json === '') {
            return array(array(
                'id' => 'overall',
                'title' => get_string('defaultcriteriontitle', 'local_kbaai'),
                'description' => get_string('defaultcriteriondescription', 'local_kbaai'),
                'maxscore' => (float)$maxscore,
                'required' => true,
                'levels' => $this->default_levels($maxscore),
            ));
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array(array(
                'id' => 'overall',
                'title' => get_string('defaultcriteriontitle', 'local_kbaai'),
                'description' => get_string('defaultcriteriondescription', 'local_kbaai'),
                'maxscore' => (float)$maxscore,
                'required' => true,
                'levels' => $this->default_levels($maxscore),
            ));
        }
        $out = array();
        foreach (array_values($decoded) as $i => $criterion) {
            if (!is_array($criterion)) { continue; }
            $mx = isset($criterion['maxscore']) ? (float)$criterion['maxscore'] : (isset($criterion['max_score']) ? (float)$criterion['max_score'] : 0);
            if ($mx <= 0) { continue; }
            $out[] = array(
                'id' => !empty($criterion['id']) ? (string)$criterion['id'] : 'C' . ($i + 1),
                'title' => !empty($criterion['title']) ? (string)$criterion['title'] : 'C' . ($i + 1),
                'description' => isset($criterion['description']) ? (string)$criterion['description'] : '',
                'maxscore' => $mx,
                'required' => !isset($criterion['required']) || !empty($criterion['required']),
                'levels' => !empty($criterion['levels']) && is_array($criterion['levels']) ? $this->normalise_levels($criterion['levels'], $mx) : $this->default_levels($mx),
            );
        }
        return $out ?: array(array(
            'id' => 'overall',
            'title' => get_string('defaultcriteriontitle', 'local_kbaai'),
            'description' => get_string('defaultcriteriondescription', 'local_kbaai'),
            'maxscore' => (float)$maxscore,
            'required' => true,
            'levels' => $this->default_levels($maxscore),
        ));
    }

    private function sum(array $criteria) {
        $sum = 0.0;
        foreach ($criteria as $criterion) { $sum += (float)$criterion['maxscore']; }
        return $sum;
    }
}
