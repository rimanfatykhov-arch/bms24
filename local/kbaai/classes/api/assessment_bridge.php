<?php
namespace local_kbaai\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Small public read-only bridge for other KBA plugins.
 *
 * This API intentionally exposes only teacher-facing assessment summary data.
 * Provider credentials, prompts and raw technical payloads are never returned.
 */
class assessment_bridge {
    /**
     * Return the latest AI assessment summary for a KBA Element submission.
     *
     * @param int $submissionid kbaelement_submissions.id
     * @return array|null Null when local_kbaai storage is not available.
     */
    public static function latest_for_submission($submissionid) {
        global $DB;

        $submissionid = (int)$submissionid;
        if ($submissionid <= 0) {
            return null;
        }

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_kbaai_assessment');
        if (!$dbman->table_exists($table)) {
            return null;
        }

        $assessment = (new \local_kbaai\repository\assessment_repository())
            ->latest_for_submission($submissionid);

        if (!$assessment) {
            return array(
                'available' => true,
                'hasassessment' => false,
                'statuslabel' => get_string('status_notassessed', 'local_kbaai'),
            );
        }

        $result = \local_kbaai\output\teacher_presenter::result($assessment);
        $snapshot = json_decode((string)$assessment->inputsnapshot, true);
        $criterionlookup = array();
        if (is_array($snapshot) && !empty($snapshot['criteria']) && is_array($snapshot['criteria'])) {
            foreach ($snapshot['criteria'] as $criterion) {
                if (is_array($criterion) && isset($criterion['id'])) { $criterionlookup[(string)$criterion['id']] = $criterion; }
            }
        }
        $files = array();
        if (is_array($snapshot) && !empty($snapshot['assessment_target']['files']) &&
                is_array($snapshot['assessment_target']['files'])) {
            foreach ($snapshot['assessment_target']['files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $status = !empty($file['analysis_status']) ? (string)$file['analysis_status'] :
                    (!empty($file['kind']) ? (string)$file['kind'] : 'unsupported');
                if ($status === 'analysed') {
                    $statuslabel = get_string('fileanalysed', 'local_kbaai');
                } else if ($status === 'mock') {
                    $statuslabel = get_string('filemock', 'local_kbaai');
                } else if ($status === 'failed') {
                    $statuslabel = get_string('filefailed', 'local_kbaai');
                } else if ($status === 'partial') {
                    $statuslabel = get_string('filepartial', 'local_kbaai');
                } else if ($status === 'too_large') {
                    $statuslabel = get_string('filetoolarge', 'local_kbaai');
                } else {
                    $statuslabel = get_string('fileunsupported', 'local_kbaai');
                }
                $files[] = array(
                    'filename' => isset($file['filename']) ? (string)$file['filename'] : '',
                    'mimetype' => isset($file['mimetype']) ? (string)$file['mimetype'] : '',
                    'status' => $status,
                    'statuslabel' => $statuslabel,
                    'error' => isset($file['analysis_error']) ? (string)$file['analysis_error'] : '',
                    'evidence' => isset($file['evidence']) ? (string)$file['evidence'] : '',
                    'method' => isset($file['analysis_method']) ? (string)$file['analysis_method'] : '',
                    'pages' => isset($file['analysis_pages']) ? (int)$file['analysis_pages'] : 0,
                    'tables' => isset($file['analysis_tables']) ? (int)$file['analysis_tables'] : 0,
                    'paragraphs' => isset($file['analysis_paragraphs']) ? (int)$file['analysis_paragraphs'] : 0,
                    'images' => isset($file['analysis_images']) ? (int)$file['analysis_images'] : 0,
                    'imagesanalysed' => isset($file['analysis_images_analysed']) ? (int)$file['analysis_images_analysed'] : 0,
                    'truncated' => !empty($file['analysis_truncated']),
                    'sheets' => isset($file['analysis_sheets']) ? (int)$file['analysis_sheets'] : 0,
                    'sheetsprocessed' => isset($file['analysis_sheets_processed']) ? (int)$file['analysis_sheets_processed'] : 0,
                    'cells' => isset($file['analysis_cells']) ? (int)$file['analysis_cells'] : 0,
                    'formulas' => isset($file['analysis_formulas']) ? (int)$file['analysis_formulas'] : 0,
                    'formulascached' => isset($file['analysis_formulas_cached']) ? (int)$file['analysis_formulas_cached'] : 0,
                    'merged' => isset($file['analysis_merged']) ? (int)$file['analysis_merged'] : 0,
                    'externallinks' => isset($file['analysis_external_links']) ? (int)$file['analysis_external_links'] : 0,
                );
            }
        }

        $criteriasummary = array();
        if (!empty($result['criteria']) && is_array($result['criteria'])) {
            foreach ($result['criteria'] as $criterionresult) {
                if (!is_array($criterionresult) || !isset($criterionresult['id'])) { continue; }
                $id = (string)$criterionresult['id'];
                $cfgcriterion = isset($criterionlookup[$id]) ? $criterionlookup[$id] : array();
                $criterionresult['title'] = !empty($cfgcriterion['title']) ? (string)$cfgcriterion['title'] : $id;
                $criterionresult['description'] = isset($cfgcriterion['description']) ? (string)$cfgcriterion['description'] : '';
                $criteriasummary[] = $criterionresult;
            }
        }

        return array(
            'available' => true,
            'hasassessment' => true,
            'id' => (int)$assessment->id,
            'status' => (string)$assessment->status,
            'statuslabel' => \local_kbaai\output\teacher_presenter::status_label($assessment),
            'reviewstatus' => (string)$assessment->reviewstatus,
            'publishstatus' => (string)$assessment->publishstatus,
            'aiscore' => $assessment->aiscore === null ? null : (float)$assessment->aiscore,
            'finalscore' => $assessment->finalscore === null ? null : (float)$assessment->finalscore,
            'maxscore' => (float)$assessment->maxscore,
            'confidence' => (int)$assessment->confidence,
            'requireshumanreview' => !empty($assessment->requireshumanreview),
            'studentfeedback' => isset($result['student_feedback']) ? (string)$result['student_feedback'] :
                (string)$assessment->studentfeedback,
            'teachernote' => isset($result['teacher_note']) ? (string)$result['teacher_note'] :
                (string)$assessment->teachernote,
            'strengths' => !empty($result['strengths']) && is_array($result['strengths']) ?
                array_values($result['strengths']) : array(),
            'improvements' => !empty($result['improvements']) && is_array($result['improvements']) ?
                array_values($result['improvements']) : array(),
            'criteria' => $criteriasummary,
            'gradingsource' => isset($assessment->gradingsource) ? (string)$assessment->gradingsource : '',
            'files' => $files,
            'lasterror' => (string)$assessment->lasterror,
            'timecompleted' => (int)$assessment->timecompleted,
            'timepublished' => (int)$assessment->timepublished,
        );
    }
}
