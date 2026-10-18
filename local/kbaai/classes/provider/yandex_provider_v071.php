<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();

/**
 * 0.7.1 Yandex provider.
 *
 * Fixes PDF OCR: application/pdf + asynchronous recognizeTextAsync/getRecognition.
 */
class yandex_provider_v071 extends yandex_provider_v070 {
    public function get_capabilities() {
        $caps = parent::get_capabilities();
        $caps['pdf_async_ocr'] = true;
        return $caps;
    }

    /** Intercept PDF files and use the corrected async OCR client. */
    public function analyse_attachments(array $attachments, $language = 'ru') {
        $out = array();
        foreach ($attachments as $attachment) {
            if (empty($attachment['kind']) || $attachment['kind'] !== 'pdf') {
                $processed = parent::analyse_attachments(array($attachment), $language);
                if ($processed) { $out[] = reset($processed); }
                continue;
            }

            $result = $attachment;
            $content = isset($result['content']) ? (string)$result['content'] : '';
            unset($result['content']);
            try {
                $ocr = (new \local_kbaai\provider\yandex_ocr_service_v071())->recognise_pdf(
                    $content,
                    !empty($attachment['filename']) ? (string)$attachment['filename'] : 'document.pdf'
                );
                $result['analysis_status'] = 'analysed';
                $result['evidence'] = (string)$ocr['evidence'];
                $result['analysis_method'] = 'yandex_vision_ocr_async';
                $result['analysis_pages'] = (int)$ocr['page_count'];
                $result['analysis_tables'] = (int)$ocr['table_count'];
                $result['provider_request_id'] = (string)$ocr['request_id'];
                $result['provider_operation_id'] = (string)$ocr['operation_id'];
                $result['analysis_model'] = (string)$ocr['model'];
            } catch (provider_exception $e) {
                $details = $e->get_provider_details();
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'yandex_vision_ocr_async';
                $result['provider_request_id'] = !empty($details['requestid']) ? (string)$details['requestid'] : '';
                $result['provider_operation_id'] = !empty($details['operationid']) ? (string)$details['operationid'] : '';
                $result['analysis_http_status'] = isset($details['httpstatus']) ? (int)$details['httpstatus'] : 0;
            } catch (\Throwable $e) {
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'yandex_vision_ocr_async';
            }
            $out[] = $result;
        }
        return $out;
    }

    /**
     * Keep existing text/vision/docx diagnostics and replace the legacy PDF
     * result with the corrected async PDF diagnostic.
     */
    public function diagnose($full = false) {
        $report = parent::diagnose($full);
        $service = new \local_kbaai\provider\yandex_ocr_service_v071();
        $cfg = $service->configuration();
        if (!empty($report['configuration'])) {
            $report['configuration']['ocr_endpoint'] = $cfg['endpoint'];
            $report['configuration']['ocr_result_endpoint'] = $cfg['result_endpoint'];
            $report['configuration']['ocr_model'] = $cfg['model'];
            $report['configuration']['ocr_timeout'] = $cfg['timeout'];
            $report['configuration']['pdf_max_chars'] = $cfg['max_chars'];
            $report['configuration']['ocr_mode'] = $cfg['mode'];
        }
        if ($full) {
            $report['ocr'] = $this->diagnose_pdf_ocr_v071();
        }
        return $report;
    }

    private function diagnose_pdf_ocr_v071() {
        $service = new \local_kbaai\provider\yandex_ocr_service_v071();
        $pdf = \local_kbaai\provider\yandex_ocr_service_v071::build_test_pdf();
        try {
            $result = $service->recognise_pdf($pdf, 'kba-pdf-test.pdf');
            return array(
                'ok' => true,
                'http_status' => isset($result['http_status']) ? (int)$result['http_status'] : 200,
                'request_id' => !empty($result['request_id']) ? (string)$result['request_id'] : '',
                'operation_id' => !empty($result['operation_id']) ? (string)$result['operation_id'] : '',
                'endpoint' => !empty($result['endpoint']) ? (string)$result['endpoint'] : '',
                'result_endpoint' => !empty($result['result_endpoint']) ? (string)$result['result_endpoint'] : '',
                'model' => !empty($result['model']) ? (string)$result['model'] : '',
                'mime_type' => !empty($result['mime_type']) ? (string)$result['mime_type'] : 'application/pdf',
                'mode' => !empty($result['mode']) ? (string)$result['mode'] : 'async_jsonl',
                'input_bytes' => strlen($pdf),
                'page_count' => !empty($result['page_count']) ? (int)$result['page_count'] : 0,
                'table_count' => !empty($result['table_count']) ? (int)$result['table_count'] : 0,
                'duration' => isset($result['duration']) ? $result['duration'] : null,
                'preview' => function_exists('mb_substr') ? mb_substr((string)$result['evidence'], 0, 500, 'UTF-8') : substr((string)$result['evidence'], 0, 500),
            );
        } catch (provider_exception $e) {
            $details = $e->get_provider_details();
            return array(
                'ok' => false,
                'error' => $e->getMessage(),
                'retryable' => $e->is_retryable(),
                'http_status' => isset($details['httpstatus']) ? (int)$details['httpstatus'] : 0,
                'request_id' => !empty($details['requestid']) ? (string)$details['requestid'] : '',
                'operation_id' => !empty($details['operationid']) ? (string)$details['operationid'] : '',
                'endpoint' => !empty($details['endpoint']) ? (string)$details['endpoint'] : $service->configuration()['endpoint'],
                'result_endpoint' => $service->configuration()['result_endpoint'],
                'mime_type' => 'application/pdf',
                'mode' => 'async_jsonl',
                'provider_message' => !empty($details['providermessage']) ? (string)$details['providermessage'] : '',
                'help' => get_string('pdfocrdiagnostichelp071', 'local_kbaai'),
                'input_bytes' => strlen($pdf),
            );
        }
    }
}
