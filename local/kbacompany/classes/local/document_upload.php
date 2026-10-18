<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Validates a browser upload. Storage, access and revision lifecycle remain in document_service. */
class document_upload {
    public const MAX_BYTES = 26214400; // 25 MiB, further limited by site/PHP configuration.
    public const TYPES = [
        'pdf'=>'application/pdf',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'=>'application/vnd.oasis.opendocument.text',
        'ods'=>'application/vnd.oasis.opendocument.spreadsheet',
        'odp'=>'application/vnd.oasis.opendocument.presentation',
        'rtf'=>'application/rtf', 'txt'=>'text/plain', 'csv'=>'text/csv',
    ];

    public static function max_bytes(): int {
        global $CFG;
        $site = (int)get_max_upload_file_size((int)($CFG->maxbytes ?? 0));
        return $site > 0 ? min(self::MAX_BYTES, $site) : self::MAX_BYTES;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('dcf_' . $key, 'local_kbacompany');
    }

    /** No persisted data and no trusted client MIME, size, hash, path or file ID. */
    public static function inspect(array $upload, bool $scan = false): ?array {
        if (!$upload) { return null; }
        foreach (['error','name','tmp_name','size'] as $key) {
            if (!isset($upload[$key]) || !is_scalar($upload[$key])) { self::fail('upload_error'); }
        }
        $error = (int)$upload['error'];
        if ($error === UPLOAD_ERR_NO_FILE) { return null; }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { self::fail('too_large'); }
        if ($error !== UPLOAD_ERR_OK) { self::fail('upload_error'); }
        $tmp = (string)$upload['tmp_name'];
        $raw = (string)$upload['name'];
        if ($tmp === '' || !is_uploaded_file($tmp) || $raw === '' ||
                preg_match('~[\\x00-\\x1f\\x7f/\\\\]~', $raw)) { self::fail('upload_error'); }
        $name = clean_filename($raw);
        if ($name === '' || \core_text::strlen($name) > 200 || $name[0] === '.') { self::fail('filename_error'); }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) { self::fail('type_error'); }
        $size = filesize($tmp);
        if ($size === false || $size <= 0) { self::fail('empty_file'); }
        if ($size > self::max_bytes()) { self::fail('too_large'); }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $head = file_get_contents($tmp, false, null, 0, 16);
        if ($head === false) { self::fail('upload_error'); }
        if ($ext === 'pdf') {
            if (substr($head, 0, 5) !== '%PDF-' || $detected !== 'application/pdf') { self::fail('type_error'); }
        } else if ($ext === 'rtf') {
            if (substr($head, 0, 5) !== '{\\rtf' || !in_array($detected, ['text/rtf','application/rtf','text/plain'], true)) { self::fail('type_error'); }
        } else if (in_array($ext, ['txt','csv'], true)) {
            if (!in_array($detected, ['text/plain','text/csv','application/csv'], true)) { self::fail('type_error'); }
        } else {
            self::office_container($tmp, $ext);
        }
        if ($scan) { \core\antivirus\manager::scan_file($tmp, $name, true); }
        $hash = hash_file('sha256', $tmp);
        if (!$hash) { self::fail('upload_error'); }
        return ['tmp'=>$tmp, 'filename'=>$name, 'bytes'=>(int)$size, 'mimetype'=>self::TYPES[$ext], 'sha256'=>$hash];
    }

    /** Inspect the package without extracting or rendering any uploaded content. */
    private static function office_container(string $tmp, string $ext): void {
        if (!class_exists('ZipArchive')) { self::fail('zip_unavailable'); }
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) { self::fail('type_error'); }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) { self::fail('package_error'); }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if (!$info || !empty($info['encryption_method'])) { self::fail('package_error'); }
                $total += (int)$info['size'];
                if ($total > 262144000 || preg_match('~(?:^|/)(?:vbaProject\.bin|Scripts|Basic)(?:/|$)~i', $info['name'])) {
                    self::fail('package_error');
                }
            }
            $parts = ['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'];
            if (isset($parts[$ext])) {
                $ct = $zip->statName('[Content_Types].xml');
                if (!$ct || $ct['size'] > 1048576 || $zip->locateName($parts[$ext]) === false) { self::fail('type_error'); }
                $types = $zip->getFromName('[Content_Types].xml');
                // Do not accept macro-enabled containers disguised as ordinary Office documents.
                if ($types === false || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                    self::fail('package_error');
                }
            } else {
                $mi = $zip->statName('mimetype');
                if (!$mi || $mi['size'] > 256 || trim((string)$zip->getFromName('mimetype')) !== self::TYPES[$ext] ||
                        $zip->locateName('content.xml') === false) { self::fail('type_error'); }
            }
        } finally { $zip->close(); }
    }

    /** Decode only server-created manifest fields; malformed nonempty metadata fails closed. */
    public static function manifest(?string $json): ?array {
        if ($json === null || $json === '') { return null; }
        $m = json_decode($json, true);
        if (!is_array($m) || ($m['version'] ?? null) !== 1 ||
                !is_string($m['filename'] ?? null) || !is_string($m['sha256'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $m['sha256']) ||
                !is_int($m['bytes'] ?? null) || $m['bytes'] <= 0 ||
                !is_int($m['uploadedat'] ?? null) || $m['uploadedat'] <= 0 ||
                preg_match('~[\\x00-\\x1f\\x7f/\\\\]~', $m['filename']) ||
                !in_array($m['mimetype'] ?? '', self::TYPES, true)) { self::fail('manifest_error'); }
        return $m;
    }
}
