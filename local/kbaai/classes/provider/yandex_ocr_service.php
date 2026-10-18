<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();

/**
 * Thin Yandex Vision OCR client used by the document extraction layer.
 *
 * 0.6.0 intentionally uses the synchronous OCR endpoint for PDFs up to
 * 10 MiB. Long/mass document processing can move to the asynchronous OCR
 * API in a later release without changing the assessment contract.
 */
class yandex_ocr_service {
    const DEFAULT_ENDPOINT = 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText';
    const DEFAULT_MODEL = 'page';

    private function api_key() {
        global $CFG;
        if (!empty($CFG->kbaai_yandex_apikey)) {
            return trim((string)$CFG->kbaai_yandex_apikey);
        }
        return trim((string)get_config('local_kbaai', 'yandexapikey'));
    }

    private function folder_id() {
        return trim((string)get_config('local_kbaai', 'yandexfolderid'));
    }

    private function endpoint() {
        $value = trim((string)get_config('local_kbaai', 'yandexocrendpoint'));
        return $value === '' ? self::DEFAULT_ENDPOINT : $value;
    }

    private function model() {
        $value = trim((string)get_config('local_kbaai', 'yandexocrmodel'));
        return $value === '' ? self::DEFAULT_MODEL : $value;
    }

    private function timeout() {
        $value = (int)get_config('local_kbaai', 'yandexocrtimeout');
        return $value > 0 ? min(300, max(15, $value)) : 120;
    }

    private function max_chars() {
        $value = (int)get_config('local_kbaai', 'pdfmaxchars');
        return $value > 0 ? min(180000, max(10000, $value)) : 60000;
    }

    public function configuration() {
        return array(
            'endpoint' => $this->endpoint(),
            'model' => $this->model(),
            'timeout' => $this->timeout(),
            'max_chars' => $this->max_chars(),
        );
    }

    private function parse_header_value($headers, $name) {
        if ($headers === '') {
            return '';
        }
        $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        if (preg_match_all($pattern, $headers, $matches) && !empty($matches[1])) {
            return trim((string)end($matches[1]));
        }
        return '';
    }

    private function request_id($curl) {
        $rawheaders = method_exists($curl, 'get_raw_response') ? $curl->get_raw_response() : array();
        $headers = is_array($rawheaders) ? implode('', $rawheaders) : '';
        foreach (array('x-request-id', 'x-server-trace-id', 'x-yandex-request-id') as $name) {
            $value = $this->parse_header_value($headers, $name);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function provider_message($body) {
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return '';
        }
        foreach (array('message', 'description', 'error_description') as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                return trim((string)$data[$key]);
            }
        }
        if (!empty($data['error'])) {
            if (is_string($data['error'])) {
                return trim($data['error']);
            }
            if (is_array($data['error']) && !empty($data['error']['message'])) {
                return trim((string)$data['error']['message']);
            }
        }
        return '';
    }


    private function text_from_blocks(array $annotation) {
        if (empty($annotation['blocks']) || !is_array($annotation['blocks'])) {
            return '';
        }
        $lines = array();
        foreach ($annotation['blocks'] as $block) {
            if (!is_array($block) || empty($block['lines']) || !is_array($block['lines'])) {
                continue;
            }
            foreach ($block['lines'] as $line) {
                if (is_array($line) && isset($line['text']) && trim((string)$line['text']) !== '') {
                    $lines[] = trim((string)$line['text']);
                }
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Recursively collect OCR pages from both current camelCase REST responses
     * and snake_case/gRPC-like structures used by some SDKs/proxies.
     */
    private function collect_pages($node, &$pages, $inheritedpage = null) {
        if (!is_array($node)) {
            return;
        }

        $page = $inheritedpage;
        if (isset($node['page']) && is_scalar($node['page'])) {
            $page = (int)$node['page'];
        }

        $annotation = null;
        if (!empty($node['textAnnotation']) && is_array($node['textAnnotation'])) {
            $annotation = $node['textAnnotation'];
        } else if (!empty($node['text_annotation']) && is_array($node['text_annotation'])) {
            $annotation = $node['text_annotation'];
        }

        if ($annotation !== null) {
            $text = '';
            if (isset($annotation['fullText'])) {
                $text = (string)$annotation['fullText'];
            } else if (isset($annotation['full_text'])) {
                $text = (string)$annotation['full_text'];
            }
            if (trim($text) === '') {
                $text = $this->text_from_blocks($annotation);
            }
            $markdown = isset($annotation['markdown']) ? (string)$annotation['markdown'] : '';
            $tables = $this->extract_tables($annotation);
            $pages[] = array(
                'page' => $page,
                'text' => trim($text),
                'markdown' => trim($markdown),
                'tables' => $tables,
            );
        }

        foreach ($node as $key => $value) {
            if (($key === 'textAnnotation' || $key === 'text_annotation') && is_array($value)) {
                continue;
            }
            if (is_array($value)) {
                $this->collect_pages($value, $pages, $page);
            }
        }
    }

    private function extract_tables(array $annotation) {
        $tables = !empty($annotation['tables']) && is_array($annotation['tables']) ? $annotation['tables'] : array();
        $out = array();
        foreach ($tables as $table) {
            if (!is_array($table)) {
                continue;
            }
            $cells = !empty($table['cells']) && is_array($table['cells']) ? $table['cells'] : array();
            $grid = array();
            $maxrow = -1;
            $maxcol = -1;
            foreach ($cells as $cell) {
                if (!is_array($cell)) {
                    continue;
                }
                $row = isset($cell['rowIndex']) ? (int)$cell['rowIndex'] : (isset($cell['row_index']) ? (int)$cell['row_index'] : 0);
                $col = isset($cell['columnIndex']) ? (int)$cell['columnIndex'] : (isset($cell['column_index']) ? (int)$cell['column_index'] : 0);
                $grid[$row][$col] = isset($cell['text']) ? trim((string)$cell['text']) : '';
                $maxrow = max($maxrow, $row);
                $maxcol = max($maxcol, $col);
            }
            $lines = array();
            for ($r = 0; $r <= $maxrow; $r++) {
                $cols = array();
                for ($c = 0; $c <= $maxcol; $c++) {
                    $cols[] = isset($grid[$r][$c]) ? $grid[$r][$c] : '';
                }
                $lines[] = implode(' | ', $cols);
            }
            if ($lines) {
                $out[] = implode("\n", $lines);
            }
        }
        return $out;
    }

    private function normalized_evidence(array $pages, $filename) {
        $chunks = array();
        foreach ($pages as $index => $page) {
            // Use response order for human-facing page numbers. It is stable and avoids
            // depending on provider-specific zero/one-based page numbering.
            $pageno = $index + 1;
            $body = $page['markdown'] !== '' ? $page['markdown'] : $page['text'];
            $part = '[PDF «' . $filename . '», страница ' . $pageno . ']';
            if ($body !== '') {
                $part .= "\n" . $body;
            }
            if (!empty($page['tables'])) {
                $part .= "\nТаблицы:\n" . implode("\n\n", $page['tables']);
            }
            $chunks[] = trim($part);
        }
        $evidence = trim(implode("\n\n", $chunks));
        $maxchars = $this->max_chars();
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($evidence, 'UTF-8') > $maxchars) {
                $evidence = mb_substr($evidence, 0, $maxchars, 'UTF-8') . "\n[PDF сокращён до лимита контекста]";
            }
        } else if (strlen($evidence) > $maxchars) {
            $evidence = substr($evidence, 0, $maxchars) . "\n[PDF сокращён до лимита контекста]";
        }
        return $evidence;
    }

    /**
     * Recognize one PDF with Yandex Vision OCR.
     *
     * @param string $binary Raw PDF bytes.
     * @param string $filename Original filename for evidence labels.
     * @return array evidence + diagnostics.
     */
    public function recognise_pdf($binary, $filename = 'document.pdf') {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $key = $this->api_key();
        $folder = $this->folder_id();
        if ($key === '') {
            throw new provider_exception('ocr_missing_api_key', get_string('yandexerr_missingkey', 'local_kbaai'), false,
                array('purpose' => 'pdf_ocr'));
        }
        if ($folder === '') {
            throw new provider_exception('ocr_missing_folder_id', get_string('yandexerr_missingfolder', 'local_kbaai'), false,
                array('purpose' => 'pdf_ocr'));
        }

        $payload = array(
            'mimeType' => 'PDF',
            'languageCodes' => array('*'),
            'model' => $this->model(),
            'content' => base64_encode($binary),
        );
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new provider_exception('ocr_json_encode', get_string('yandexerr_requestjson', 'local_kbaai'), false,
                array('purpose' => 'pdf_ocr'));
        }

        $curl = new \curl();
        $curl->setHeader(array(
            'Authorization: Api-Key ' . $key,
            'x-folder-id: ' . $folder,
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        $raw = $curl->post($this->endpoint(), $encoded, array(
            'CURLOPT_TIMEOUT' => $this->timeout(),
            'CURLOPT_CONNECTTIMEOUT' => min(15, $this->timeout()),
        ));
        $info = $curl->get_info();
        $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $requestid = $this->request_id($curl);
        $body = (string)$raw;

        if ($status < 200 || $status >= 300) {
            $message = $this->provider_message($body);
            $retryable = ($status === 0 || $status === 408 || $status === 429 || $status >= 500);
            $friendly = get_string('pdfocrhttperror', 'local_kbaai', $status);
            if ($message !== '') {
                $friendly .= ' ' . $message;
            }
            throw new provider_exception('pdf_ocr_http_' . $status, $friendly, $retryable, array(
                'purpose' => 'pdf_ocr',
                'httpstatus' => $status,
                'requestid' => $requestid,
                'providermessage' => $message,
                'endpoint' => $this->endpoint(),
            ));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new provider_exception('pdf_ocr_invalid_json', get_string('pdfocrinvalidresponse', 'local_kbaai'), true,
                array('purpose' => 'pdf_ocr', 'httpstatus' => $status, 'requestid' => $requestid));
        }

        $pages = array();
        $this->collect_pages($data, $pages, null);
        $evidence = $this->normalized_evidence($pages, $filename);
        if ($evidence === '' || count($pages) === 0) {
            throw new provider_exception('pdf_ocr_empty', get_string('pdfocrempty', 'local_kbaai'), false,
                array('purpose' => 'pdf_ocr', 'httpstatus' => $status, 'requestid' => $requestid));
        }

        return array(
            'evidence' => $evidence,
            'page_count' => count($pages),
            'table_count' => array_sum(array_map(function($page) { return count($page['tables']); }, $pages)),
            'request_id' => $requestid,
            'http_status' => $status,
            'duration' => !empty($info['total_time']) ? (float)$info['total_time'] : null,
            'endpoint' => $this->endpoint(),
            'model' => $this->model(),
            'input_bytes' => strlen($binary),
        );
    }

    /** Build a tiny one-page PDF with ASCII text for connectivity diagnostics. */
    public static function build_test_pdf() {
        $objects = array();
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $stream = "BT /F1 18 Tf 40 120 Td (KBA PDF TEST 123) Tj ET";
        $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = array(0);
        foreach ($objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
