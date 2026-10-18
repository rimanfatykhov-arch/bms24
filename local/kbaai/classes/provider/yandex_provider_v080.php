<?php
namespace local_kbaai\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * 0.8.0 Yandex provider with safe local XLSX extraction.
 *
 * Spreadsheet values, formulas/cached results, merged ranges and table
 * definitions are extracted locally. No workbook code or external links are
 * executed or fetched.
 */
class yandex_provider_v080 extends yandex_provider_v071 {
    public function get_capabilities() {
        $caps = parent::get_capabilities();
        $caps['xlsx_extraction'] = true;
        return $caps;
    }

    /** Analyse XLSX in addition to all attachment types supported by v071. */
    public function analyse_attachments(array $attachments, $language = 'ru') {
        $out = array();
        foreach ($attachments as $attachment) {
            if (empty($attachment['kind']) || $attachment['kind'] !== 'xlsx') {
                $processed = parent::analyse_attachments(array($attachment), $language);
                if ($processed) {
                    $out[] = reset($processed);
                }
                continue;
            }

            $result = $attachment;
            $content = isset($result['content']) ? (string)$result['content'] : '';
            unset($result['content']);
            try {
                $extracted = (new \local_kbaai\service\xlsx_extractor())->extract(
                    $content,
                    !empty($attachment['filename']) ? (string)$attachment['filename'] : 'workbook.xlsx'
                );
                if (trim((string)$extracted['evidence']) === '' || ((int)$extracted['cell_count'] === 0 && (int)$extracted['table_count'] === 0)) {
                    $result['analysis_status'] = 'failed';
                    $result['evidence'] = '';
                    $result['analysis_error'] = get_string('xlsxerr_empty', 'local_kbaai');
                } else {
                    $result['analysis_status'] = !empty($extracted['partial']) ? 'partial' : 'analysed';
                    $result['evidence'] = trim((string)$extracted['evidence']);
                    if (!empty($extracted['partial'])) {
                        $result['analysis_error'] = get_string('xlsxpartialreason', 'local_kbaai');
                    }
                }
                $result['analysis_method'] = 'xlsx_ooxml_local';
                $result['analysis_sheets'] = (int)$extracted['sheet_count'];
                $result['analysis_sheets_processed'] = (int)$extracted['sheet_count_processed'];
                $result['analysis_cells'] = (int)$extracted['cell_count'];
                $result['analysis_formulas'] = (int)$extracted['formula_count'];
                $result['analysis_formulas_cached'] = (int)$extracted['formula_cached_count'];
                $result['analysis_formulas_uncached'] = (int)$extracted['formula_uncached_count'];
                $result['analysis_formulas_unresolved'] = (int)$extracted['formula_unresolved_count'];
                $result['analysis_merged'] = (int)$extracted['merged_count'];
                $result['analysis_tables'] = (int)$extracted['table_count'];
                $result['analysis_external_links'] = (int)$extracted['external_link_count'];
                $result['analysis_macros'] = (int)$extracted['macro_count'];
                $result['analysis_drawings'] = (int)$extracted['drawing_count'];
                $result['analysis_embeddings'] = (int)$extracted['embedding_count'];
                $result['analysis_truncated'] = !empty($extracted['truncated']) ? 1 : 0;
            } catch (provider_exception $e) {
                $details = $e->get_provider_details();
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'xlsx_ooxml_local';
                $result['provider_request_id'] = !empty($details['requestid']) ? (string)$details['requestid'] : '';
                $result['analysis_http_status'] = isset($details['httpstatus']) ? (int)$details['httpstatus'] : 0;
            } catch (\Throwable $e) {
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'xlsx_ooxml_local';
            }
            $out[] = $result;
        }
        return $out;
    }



    /** Generate a structured proposal for BMS CRUD. The provider never writes BMS data. */
    public function generate_bms_assistance(\local_kbaai\dto\bms_assistant_request $request) {
        $profile = \local_kbaai\service\bms_profile_registry::profile($request->operation);
        $system = $profile['system'];
        $task = $profile['task'];
        $context = [
            'locale' => $request->locale, 'domain' => $request->domain, 'field' => $request->field,
            'operation' => $request->operation, 'draft_value' => $request->draftvalue,
            'record_context' => $request->context, 'methodology_bindings' => $request->methodology,
        ];
        $schema = \local_kbaai\service\bms_profile_registry::schema();
        $model = (string)(get_config('local_kbaai', 'defaultmodel') ?: 'yandexgpt/latest');
        $messages = array(
            array('role' => 'system', 'text' => $system),
            array('role' => 'user', 'text' => $task . "\n\nКонтекст BMS24:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        );
        $response = $this->text_completion($messages, $model, $schema, 3500);
        if ($request->operation === 'draft' && $this->bms_suggestion_is_instruction($response->result)) {
            $repair = 'Предыдущий structured result нарушил контракт: suggested_value получился инструкцией вместо готового текста поля. '
                . 'Исправь ответ. Верни либо конкретный готовый текст для вставки, опирающийся только на переданные факты, '
                . 'либо пустой suggested_value и конкретные вопросы, если фактов недостаточно.';
            $messages[] = array('role' => 'assistant', 'text' => json_encode($response->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $messages[] = array('role' => 'user', 'text' => $repair);
            $response = $this->text_completion($messages, $model, $schema, 3500);
        }
        if ($request->operation === 'draft' && $this->bms_suggestion_is_instruction($response->result)) {
            $response->result['suggested_value'] = '';
            $response->result['warnings'][] = get_string('bms_instruction_rejected', 'local_kbaai');
        }
        return $response;
    }

    /** Detect a meta-instruction accidentally returned instead of ready-to-paste field content. */
    private function bms_suggestion_is_instruction(array $result) {
        $value = isset($result['suggested_value']) ? trim((string)$result['suggested_value']) : '';
        if ($value === '') {
            return false;
        }
        $lower = \core_text::strtolower($value);
        $markers = array(
            'необходимо составить', 'необходимо сформулировать', 'нужно составить', 'нужно сформулировать',
            'следует составить', 'следует сформулировать', 'на основе переданного контекста необходимо',
            'требуется составить', 'требуется сформулировать', 'составьте ', 'сформулируйте ',
            'you need to draft', 'you need to formulate', 'please draft', 'should be drafted', 'should formulate'
        );
        foreach ($markers as $marker) {
            if (strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Add a local XLSX extraction check to provider diagnostics. */
    public function diagnose($full = false) {
        $report = parent::diagnose($full);
        $report['xlsx'] = (new \local_kbaai\service\xlsx_extractor())->diagnose();
        return $report;
    }
}
