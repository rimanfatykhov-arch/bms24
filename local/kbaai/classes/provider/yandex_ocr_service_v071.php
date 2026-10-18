<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();

/**
 * Yandex Vision OCR client for PDF files.
 *
 * 0.7.1 uses the documented asynchronous PDF flow:
 * recognizeTextAsync -> getRecognition (JSONL, one page per line).
 */
class yandex_ocr_service_v071 {
    const DEFAULT_SYNC_ENDPOINT = 'https://ai.api.cloud.yandex.net/ocr/v1/recognizeText';
    const DEFAULT_ASYNC_ENDPOINT = 'https://ai.api.cloud.yandex.net/ocr/v1/recognizeTextAsync';
    const DEFAULT_RESULT_ENDPOINT = 'https://ai.api.cloud.yandex.net/ocr/v1/getRecognition';
    const DEFAULT_MODEL = 'page';
    const POLL_INTERVAL_SECONDS = 2;

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

    /**
     * Preserve a custom OCR endpoint, but normalize our old defaults to the
     * current AI API host documented by Yandex.
     */
    private function sync_endpoint() {
        $value = trim((string)get_config('local_kbaai', 'yandexocrendpoint'));
        if ($value === '' || $value === 'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText') {
            return self::DEFAULT_SYNC_ENDPOINT;
        }
        return $value;
    }

    private function async_endpoint() {
        $sync = $this->sync_endpoint();
        if (preg_match('~/recognizeText$~', $sync)) {
            return preg_replace('~/recognizeText$~', '/recognizeTextAsync', $sync);
        }
        return self::DEFAULT_ASYNC_ENDPOINT;
    }

    private function result_endpoint() {
        $sync = $this->sync_endpoint();
        if (preg_match('~/recognizeText$~', $sync)) {
            return preg_replace('~/recognizeText$~', '/getRecognition', $sync);
        }
        return self::DEFAULT_RESULT_ENDPOINT;
    }

    private function model() {
        $value = trim((string)get_config('local_kbaai', 'yandexocrmodel'));
        return $value === '' ? self::DEFAULT_MODEL : $value;
    }

    private function timeout() {
        $value = (int)get_config('local_kbaai', 'yandexocrtimeout');
        return $value > 0 ? min(600, max(20, $value)) : 120;
    }

    private function max_chars() {
        $value = (int)get_config('local_kbaai', 'pdfmaxchars');
        return $value > 0 ? min(180000, max(10000, $value)) : 60000;
    }

    public function configuration() {
        return array(
            'endpoint' => $this->async_endpoint(),
            'sync_endpoint' => $this->sync_endpoint(),
            'result_endpoint' => $this->result_endpoint(),
            'model' => $this->model(),
            'timeout' => $this->timeout(),
            'max_chars' => $this->max_chars(),
            'mode' => 'async_pdf_jsonl',
        );
    }

    private function parse_header_value($headers, $name) {
        if ($headers === '') { return ''; }
        $pattern = '/^' . preg_quote($name, '/') . ':\\s*(.+)$/mi';
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
            if ($value !== '') { return $value; }
        }
        return '';
    }

    private function provider_message($body) {
        $data = json_decode((string)$body, true);
        if (!is_array($data)) { return ''; }
        foreach (array('message', 'description', 'error_description') as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) { return trim((string)$data[$key]); }
        }
        if (!empty($data['error'])) {
            if (is_string($data['error'])) { return trim($data['error']); }
            if (is_array($data['error']) && !empty($data['error']['message'])) {
                return trim((string)$data['error']['message']);
            }
        }
        return '';
    }

    private function new_curl($acceptjson = true) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $headers = array(
            'Authorization: Api-Key ' . $this->api_key(),
            'x-folder-id: ' . $this->folder_id(),
            'x-data-logging-enabled: true',
        );
        if ($acceptjson) {
            $headers[] = 'Accept: application/json';
        }
        $curl->setHeader($headers);
        return $curl;
    }

    private function text_from_blocks(array $annotation) {
        if (empty($annotation['blocks']) || !is_array($annotation['blocks'])) { return ''; }
        $lines = array();
        foreach ($annotation['blocks'] as $block) {
            if (!is_array($block) || empty($block['lines']) || !is_array($block['lines'])) { continue; }
            foreach ($block['lines'] as $line) {
                if (is_array($line) && isset($line['text']) && trim((string)$line['text']) !== '') {
                    $lines[] = trim((string)$line['text']);
                }
            }
        }
        return implode("\n", $lines);
    }

    private function extract_tables(array $annotation) {
        $tables = !empty($annotation['tables']) && is_array($annotation['tables']) ? $annotation['tables'] : array();
        $out = array();
        foreach ($tables as $table) {
            if (!is_array($table)) { continue; }
            $cells = !empty($table['cells']) && is_array($table['cells']) ? $table['cells'] : array();
            $grid = array(); $maxrow = -1; $maxcol = -1;
            foreach ($cells as $cell) {
                if (!is_array($cell)) { continue; }
                $row = isset($cell['rowIndex']) ? (int)$cell['rowIndex'] : (isset($cell['row_index']) ? (int)$cell['row_index'] : 0);
                $col = isset($cell['columnIndex']) ? (int)$cell['columnIndex'] : (isset($cell['column_index']) ? (int)$cell['column_index'] : 0);
                $grid[$row][$col] = isset($cell['text']) ? trim((string)$cell['text']) : '';
                $maxrow = max($maxrow, $row); $maxcol = max($maxcol, $col);
            }
            $lines = array();
            for ($r = 0; $r <= $maxrow; $r++) {
                $cols = array();
                for ($c = 0; $c <= $maxcol; $c++) { $cols[] = isset($grid[$r][$c]) ? $grid[$r][$c] : ''; }
                $lines[] = implode(' | ', $cols);
            }
            if ($lines) { $out[] = implode("\n", $lines); }
        }
        return $out;
    }

    private function page_from_object(array $node) {
        // Current REST async response wraps each page into result.
        if (!empty($node['result']) && is_array($node['result'])) { $node = $node['result']; }
        $annotation = null;
        if (!empty($node['textAnnotation']) && is_array($node['textAnnotation'])) {
            $annotation = $node['textAnnotation'];
        } else if (!empty($node['text_annotation']) && is_array($node['text_annotation'])) {
            $annotation = $node['text_annotation'];
        }
        if ($annotation === null) { return null; }
        $text = isset($annotation['fullText']) ? (string)$annotation['fullText'] : (isset($annotation['full_text']) ? (string)$annotation['full_text'] : '');
        if (trim($text) === '') { $text = $this->text_from_blocks($annotation); }
        $markdown = isset($annotation['markdown']) ? (string)$annotation['markdown'] : '';
        return array(
            'page' => isset($node['page']) ? (int)$node['page'] : 0,
            'text' => trim($text),
            'markdown' => trim($markdown),
            'tables' => $this->extract_tables($annotation),
        );
    }

    private function parse_jsonl_pages($body) {
        $pages = array();
        $body = trim((string)$body);
        if ($body === '') { return $pages; }

        // Some gateways may return a normal JSON object/array; accept both.
        $whole = json_decode($body, true);
        if (is_array($whole)) {
            $islist = array_keys($whole) === range(0, count($whole) - 1);
            $items = $islist ? $whole : array($whole);
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                $page = $this->page_from_object($item);
                if ($page !== null) { $pages[] = $page; }
            }
            if ($pages) { return $pages; }
        }

        foreach (preg_split('/\\r?\\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $item = json_decode($line, true);
            if (!is_array($item)) { continue; }
            $page = $this->page_from_object($item);
            if ($page !== null) { $pages[] = $page; }
        }
        return $pages;
    }

    private function normalized_evidence(array $pages, $filename) {
        usort($pages, function($a, $b) {
            $ap = !empty($a['page']) ? (int)$a['page'] : PHP_INT_MAX;
            $bp = !empty($b['page']) ? (int)$b['page'] : PHP_INT_MAX;
            return $ap <=> $bp;
        });
        $chunks = array();
        foreach ($pages as $index => $page) {
            $pageno = !empty($page['page']) ? (int)$page['page'] : ($index + 1);
            $body = $page['markdown'] !== '' ? $page['markdown'] : $page['text'];
            $part = '[PDF «' . $filename . '», страница ' . $pageno . ']';
            if ($body !== '') { $part .= "\n" . $body; }
            if (!empty($page['tables'])) { $part .= "\nТаблицы:\n" . implode("\n\n", $page['tables']); }
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

    /** Recognize a PDF through the documented asynchronous OCR REST flow. */
    public function recognise_pdf($binary, $filename = 'document.pdf') {
        if ($this->api_key() === '') {
            throw new provider_exception('ocr_missing_api_key', get_string('yandexerr_missingkey', 'local_kbaai'), false, array('purpose'=>'pdf_ocr'));
        }
        if ($this->folder_id() === '') {
            throw new provider_exception('ocr_missing_folder_id', get_string('yandexerr_missingfolder', 'local_kbaai'), false, array('purpose'=>'pdf_ocr'));
        }
        if (substr((string)$binary, 0, 5) !== '%PDF-') {
            throw new provider_exception('pdf_invalid_magic', get_string('pdfinvalidfile', 'local_kbaai'), false, array('purpose'=>'pdf_ocr'));
        }

        $started = microtime(true);
        $payload = array(
            'mimeType' => 'application/pdf',
            'languageCodes' => array('*'),
            'model' => $this->model(),
            'content' => base64_encode($binary),
        );
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new provider_exception('ocr_json_encode', get_string('yandexerr_requestjson', 'local_kbaai'), false, array('purpose'=>'pdf_ocr'));
        }

        $curl = $this->new_curl();
        $curl->setHeader(array('Content-Type: application/json'));
        $raw = $curl->post($this->async_endpoint(), $encoded, array(
            'CURLOPT_TIMEOUT' => min(60, $this->timeout()),
            'CURLOPT_CONNECTTIMEOUT' => 15,
        ));
        $info = $curl->get_info();
        $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $submitrequestid = $this->request_id($curl);
        if ($status < 200 || $status >= 300) {
            $message = $this->provider_message($raw);
            throw new provider_exception('pdf_ocr_submit_http_' . $status,
                get_string('pdfocrhttperror', 'local_kbaai', $status) . ($message !== '' ? ' ' . $message : ''),
                ($status === 0 || $status === 408 || $status === 429 || $status >= 500),
                array('purpose'=>'pdf_ocr','httpstatus'=>$status,'requestid'=>$submitrequestid,'providermessage'=>$message,'endpoint'=>$this->async_endpoint()));
        }
        $op = json_decode((string)$raw, true);
        $operationid = is_array($op) && !empty($op['id']) ? trim((string)$op['id']) : '';
        if ($operationid === '') {
            throw new provider_exception('pdf_ocr_missing_operation', get_string('pdfocrasyncnooperation', 'local_kbaai'), true,
                array('purpose'=>'pdf_ocr','httpstatus'=>$status,'requestid'=>$submitrequestid,'endpoint'=>$this->async_endpoint()));
        }

        $deadline = microtime(true) + $this->timeout();
        $resultbody = '';
        $resultrequestid = '';
        $laststatus = 0;
        do {
            $resultcurl = $this->new_curl(false);
            $url = $this->result_endpoint() . '?operationId=' . rawurlencode($operationid);
            $body = $resultcurl->get($url, array(
                'CURLOPT_TIMEOUT' => min(30, $this->timeout()),
                'CURLOPT_CONNECTTIMEOUT' => 15,
            ));
            $rinfo = $resultcurl->get_info();
            $laststatus = isset($rinfo['http_code']) ? (int)$rinfo['http_code'] : 0;
            $resultrequestid = $this->request_id($resultcurl);
            $trimmed = trim((string)$body);
            if ($laststatus >= 200 && $laststatus < 300 && $trimmed !== '') {
                $resultbody = $trimmed;
                break;
            }
            if ($laststatus !== 0 && $laststatus !== 200 && $laststatus !== 204 && $laststatus !== 404 && $laststatus !== 429) {
                $message = $this->provider_message($body);
                throw new provider_exception('pdf_ocr_result_http_' . $laststatus,
                    get_string('pdfocrhttperror', 'local_kbaai', $laststatus) . ($message !== '' ? ' ' . $message : ''),
                    ($laststatus === 408 || $laststatus === 429 || $laststatus >= 500),
                    array('purpose'=>'pdf_ocr','httpstatus'=>$laststatus,'requestid'=>$resultrequestid,'providermessage'=>$message,'endpoint'=>$url,'operationid'=>$operationid));
            }
            if (microtime(true) < $deadline) { sleep(self::POLL_INTERVAL_SECONDS); }
        } while (microtime(true) < $deadline);

        if ($resultbody === '') {
            throw new provider_exception('pdf_ocr_timeout', get_string('pdfocrasynctimeout', 'local_kbaai'), true,
                array('purpose'=>'pdf_ocr','httpstatus'=>$laststatus,'requestid'=>$resultrequestid,'endpoint'=>$this->result_endpoint(),'operationid'=>$operationid));
        }

        $pages = $this->parse_jsonl_pages($resultbody);
        $evidence = $this->normalized_evidence($pages, $filename);
        if (!$pages || $evidence === '') {
            throw new provider_exception('pdf_ocr_empty', get_string('pdfocrempty', 'local_kbaai'), false,
                array('purpose'=>'pdf_ocr','httpstatus'=>$laststatus,'requestid'=>$resultrequestid,'endpoint'=>$this->result_endpoint(),'operationid'=>$operationid));
        }

        $tablecount = 0;
        foreach ($pages as $page) { $tablecount += !empty($page['tables']) ? count($page['tables']) : 0; }
        return array(
            'evidence' => $evidence,
            'page_count' => count($pages),
            'table_count' => $tablecount,
            'request_id' => $resultrequestid !== '' ? $resultrequestid : $submitrequestid,
            'operation_id' => $operationid,
            'http_status' => $laststatus ?: $status,
            'duration' => microtime(true) - $started,
            'endpoint' => $this->async_endpoint(),
            'result_endpoint' => $this->result_endpoint(),
            'model' => $this->model(),
            'input_bytes' => strlen($binary),
            'mime_type' => 'application/pdf',
            'mode' => 'async_jsonl',
        );
    }

    /** Build a small, standards-compliant single-page test PDF. */
    public static function build_test_pdf() {
        // This is intentionally the same structurally valid PDF used in 0.7.0;
        // 0.7.1 fixes the API flow and MIME type rather than masking the issue.
        $objects = array();
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $stream = "BT /F1 18 Tf 40 120 Td (KBA PDF TEST 123) Tj ET";
        $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $pdf = "%PDF-1.4\n"; $offsets = array(0);
        foreach ($objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
