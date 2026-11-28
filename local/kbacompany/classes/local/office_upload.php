<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * DOC-W3 upload validation for incoming register files (owner decision 27.11.2026, #196): PDF, JPG/JPEG, PNG and
 * DOCX, at most 20 MB per file and never more than the site/PHP limit. The client name, MIME type and size are never
 * trusted: the server checks the real content signature, rejects macro containers and computes the SHA-256.
 * Storage, access and lifecycle stay in office_incoming_service.
 */
final class office_upload {
    public const MAX_BYTES = 20971520; // 20 MiB.
    public const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public static function max_bytes(): int {
        global $CFG;
        $site = (int)get_max_upload_file_size((int)($CFG->maxbytes ?? 0));
        return $site > 0 ? min(self::MAX_BYTES, $site) : self::MAX_BYTES;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('officein_err_' . $key, 'local_kbacompany');
    }

    /**
     * Validates one browser upload ($_FILES entry). Returns null when no file was chosen.
     * $trusted is only for server-side tests and CLI fixtures that pass a file which is not an HTTP upload.
     */
    public static function inspect(array $upload, bool $trusted = false): ?array {
        if (!$upload) { return null; }
        foreach (['error', 'name', 'tmp_name', 'size'] as $key) {
            if (!isset($upload[$key]) || !is_scalar($upload[$key])) { self::fail('upload'); }
        }
        $error = (int)$upload['error'];
        if ($error === UPLOAD_ERR_NO_FILE) { return null; }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { self::fail('toolarge'); }
        if ($error !== UPLOAD_ERR_OK) { self::fail('upload'); }
        $tmp = (string)$upload['tmp_name'];
        $raw = (string)$upload['name'];
        if ($tmp === '' || $raw === '' || preg_match('~[\\x00-\\x1f\\x7f/\\\\]~', $raw)
                || !($trusted ? is_file($tmp) : is_uploaded_file($tmp))) { self::fail('upload'); }
        $name = clean_filename($raw);
        if ($name === '' || \core_text::strlen($name) > 200 || $name[0] === '.') { self::fail('filename'); }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) { self::fail('type'); }
        $size = filesize($tmp);
        if ($size === false || $size <= 0) { self::fail('empty'); }
        if ($size > self::max_bytes()) { self::fail('toolarge'); }
        $head = file_get_contents($tmp, false, null, 0, 16);
        if ($head === false) { self::fail('upload'); }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($ext === 'pdf') {
            if (substr($head, 0, 5) !== '%PDF-' || $detected !== 'application/pdf') { self::fail('type'); }
        } else if ($ext === 'jpg' || $ext === 'jpeg') {
            if (substr($head, 0, 3) !== "\xFF\xD8\xFF" || $detected !== 'image/jpeg') { self::fail('type'); }
        } else if ($ext === 'png') {
            if (substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n" || $detected !== 'image/png') { self::fail('type'); }
        } else {
            self::docx_container($tmp);
        }
        if (!$trusted) { \core\antivirus\manager::scan_file($tmp, $name, true); }
        $hash = hash_file('sha256', $tmp);
        if (!$hash) { self::fail('upload'); }
        return ['tmp' => $tmp, 'filename' => $name, 'bytes' => (int)$size, 'mimetype' => self::TYPES[$ext],
            'sha256' => $hash];
    }

    /** Inspect the package without extracting or rendering it; macro-enabled content is rejected. */
    private static function docx_container(string $tmp): void {
        if (!class_exists('ZipArchive')) { self::fail('type'); }
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) { self::fail('type'); }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) { self::fail('package'); }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $info = $zip->statIndex($i);
                if (!$info || !empty($info['encryption_method'])) { self::fail('package'); }
                $total += (int)$info['size'];
                if ($total > 262144000 || preg_match('~(?:^|/)(?:vbaProject\.bin|Scripts|Basic)(?:/|$)~i', $info['name'])) {
                    self::fail('package');
                }
            }
            $ct = $zip->statName('[Content_Types].xml');
            if (!$ct || $ct['size'] > 1048576 || $zip->locateName('word/document.xml') === false) { self::fail('type'); }
            $types = $zip->getFromName('[Content_Types].xml');
            if ($types === false || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                self::fail('package');
            }
        } finally {
            $zip->close();
        }
    }

    /** Server-created manifest of a stored file. Malformed nonempty metadata fails closed. */
    public static function manifest(?string $json): ?array {
        if ($json === null || $json === '') { return null; }
        $m = json_decode($json, true);
        if (!is_array($m) || ($m['version'] ?? null) !== 1 ||
                !is_string($m['filename'] ?? null) || !is_string($m['sha256'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $m['sha256']) ||
                !is_int($m['bytes'] ?? null) || $m['bytes'] <= 0 ||
                !is_int($m['uploadedat'] ?? null) || $m['uploadedat'] <= 0 ||
                preg_match('~[\\x00-\\x1f\\x7f/\\\\]~', $m['filename']) ||
                !in_array($m['mimetype'] ?? '', self::TYPES, true)) { self::fail('manifest'); }
        return $m;
    }
}
