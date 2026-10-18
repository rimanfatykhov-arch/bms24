<?php
namespace local_kbaai\service;
defined('MOODLE_INTERNAL') || die();

class submission_file_service {
    const MAX_IMAGE_BYTES = 8388608; // Product safety limit: 8 MiB per image for this release.
    const MAX_TEXT_BYTES = 2097152; // Do not load arbitrarily large text files into PHP memory.
    const MAX_PDF_BYTES = 10485760; // Yandex Vision OCR hard limit: 10 MiB.
    const MAX_DOCX_BYTES = 15728640; // Product safety limit: 15 MiB per DOCX.
    const MAX_XLSX_BYTES = 15728640; // Product safety limit: 15 MiB per XLSX.
    const MAX_IMAGES = 3;
    const MAX_PDFS = 2;
    const MAX_DOCXS = 2;
    const MAX_XLSXS = 2;

    private function context_for_instance($instanceid) {
        $cm = get_coursemodule_from_instance('kbaelement', (int)$instanceid, 0, false, MUST_EXIST);
        return \context_module::instance($cm->id);
    }

    private function classify($mimetype, $size, $filename = '') {
        $mimetype = strtolower((string)$mimetype);
        $extension = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        if (in_array($mimetype, array('image/png', 'image/jpeg', 'image/webp'), true)) {
            return $size <= self::MAX_IMAGE_BYTES ? 'image' : 'too_large';
        }
        if (in_array($mimetype, array('text/plain', 'text/csv', 'text/markdown', 'application/json'), true)) {
            return $size <= self::MAX_TEXT_BYTES ? 'text' : 'too_large';
        }
        if (in_array($mimetype, array('application/pdf', 'application/x-pdf'), true) || $extension === 'pdf') {
            return $size <= self::MAX_PDF_BYTES ? 'pdf' : 'too_large';
        }
        if ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $extension === 'docx') {
            return $size <= self::MAX_DOCX_BYTES ? 'docx' : 'too_large';
        }
        if ($mimetype === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $extension === 'xlsx') {
            return $size <= self::MAX_XLSX_BYTES ? 'xlsx' : 'too_large';
        }
        return 'unsupported';
    }

    public function get_manifest($instanceid, $submissionid) {
        $context = $this->context_for_instance($instanceid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_kbaelement', 'submission', (int)$submissionid,
            'filepath ASC, filename ASC', false);
        $manifest = array();
        foreach ($files as $file) {
            $manifest[] = array(
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'mimetype' => (string)$file->get_mimetype(),
                'size' => (int)$file->get_filesize(),
                'contenthash' => (string)$file->get_contenthash(),
                'kind' => $this->classify($file->get_mimetype(), $file->get_filesize(), $file->get_filename()),
            );
        }
        return $manifest;
    }

    public function get_payloads($instanceid, $submissionid, array $manifest) {
        $context = $this->context_for_instance($instanceid);
        $fs = get_file_storage();
        $current = $fs->get_area_files($context->id, 'mod_kbaelement', 'submission', (int)$submissionid,
            'filepath ASC, filename ASC', false);
        $bykey = array();
        foreach ($current as $file) {
            $bykey[$file->get_filepath() . $file->get_filename()] = $file;
        }
        $payloads = array();
        $images = 0;
        $pdfs = 0;
        $docxs = 0;
        $xlsxs = 0;
        foreach ($manifest as $item) {
            $key = $item['filepath'] . $item['filename'];
            if (empty($bykey[$key]) || !hash_equals((string)$item['contenthash'], (string)$bykey[$key]->get_contenthash())) {
                throw new \local_kbaai\provider\provider_exception('attachment_changed',
                    'Official submission attachment changed after the assessment was queued.', false);
            }
            $entry = $item;
            if ($item['kind'] === 'image') {
                $images++;
                if ($images > self::MAX_IMAGES) {
                    $entry['kind'] = 'unsupported';
                    $entry['unsupported_reason'] = 'image_limit';
                } else {
                    $entry['content'] = $bykey[$key]->get_content();
                }
            } else if ($item['kind'] === 'text') {
                $content = $bykey[$key]->get_content();
                if (!preg_match('//u', $content)) {
                    $entry['kind'] = 'unsupported';
                    $entry['unsupported_reason'] = 'invalid_utf8';
                } else {
                    $entry['content'] = mb_substr($content, 0, 30000, 'UTF-8');
                }
            } else if ($item['kind'] === 'pdf') {
                $pdfs++;
                if ($pdfs > self::MAX_PDFS) {
                    $entry['kind'] = 'unsupported';
                    $entry['unsupported_reason'] = 'pdf_limit';
                } else {
                    $entry['content'] = $bykey[$key]->get_content();
                }
            } else if ($item['kind'] === 'docx') {
                $docxs++;
                if ($docxs > self::MAX_DOCXS) {
                    $entry['kind'] = 'unsupported';
                    $entry['unsupported_reason'] = 'docx_limit';
                } else {
                    $entry['content'] = $bykey[$key]->get_content();
                }
            } else if ($item['kind'] === 'xlsx') {
                $xlsxs++;
                if ($xlsxs > self::MAX_XLSXS) {
                    $entry['kind'] = 'unsupported';
                    $entry['unsupported_reason'] = 'xlsx_limit';
                } else {
                    $entry['content'] = $bykey[$key]->get_content();
                }
            }
            $payloads[] = $entry;
        }
        return $payloads;
    }
}
