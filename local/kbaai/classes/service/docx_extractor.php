<?php
namespace local_kbaai\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Safe DOCX extraction for official submission evidence.
 *
 * DOCX is a ZIP package. This extractor reads only the main document XML and
 * media referenced by that document. No macros or embedded executables are
 * executed. Extracted text remains untrusted student-authored content.
 */
class docx_extractor {
    const MAX_DOCUMENT_XML_BYTES = 26214400; // 25 MiB uncompressed XML safety cap.
    const MAX_RELATIONSHIPS_BYTES = 2097152; // 2 MiB.
    const MAX_EMBEDDED_IMAGE_BYTES = 8388608; // 8 MiB per embedded image.

    /** Clamp an integer plugin setting. */
    private function setting_int($name, $default, $min, $max) {
        $value = (int)get_config('local_kbaai', $name);
        if ($value <= 0) {
            $value = (int)$default;
        }
        return min((int)$max, max((int)$min, $value));
    }

    /** Return a ZIP entry with an uncompressed-size safety cap. */
    private function zip_get(\ZipArchive $zip, $name, $maxbytes, $required = true) {
        $stat = $zip->statName($name);
        if ($stat === false) {
            if ($required) {
                throw new \local_kbaai\provider\provider_exception(
                    'docx_missing_part',
                    get_string('docxerr_missingpart', 'local_kbaai', $name),
                    false
                );
            }
            return '';
        }
        if (!empty($stat['size']) && (int)$stat['size'] > (int)$maxbytes) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_part_too_large',
                get_string('docxerr_parttoolarge', 'local_kbaai', $name),
                false
            );
        }
        $data = $zip->getFromName($name);
        if ($data === false) {
            if ($required) {
                throw new \local_kbaai\provider\provider_exception(
                    'docx_read_failed',
                    get_string('docxerr_readpart', 'local_kbaai', $name),
                    false
                );
            }
            return '';
        }
        if (strlen($data) > (int)$maxbytes) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_part_too_large',
                get_string('docxerr_parttoolarge', 'local_kbaai', $name),
                false
            );
        }
        return $data;
    }

    /** Safely parse XML without network access. */
    private function load_xml($xml, $label) {
        if (!class_exists('\\DOMDocument')) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_dom_missing',
                get_string('docxerr_dommissing', 'local_kbaai'),
                false
            );
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML((string)$xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_invalid_xml',
                get_string('docxerr_invalidxml', 'local_kbaai', $label),
                false
            );
        }
        return $dom;
    }

    /** Build an XPath object with the WordprocessingML namespaces we use. */
    private function xpath(\DOMDocument $dom) {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
        return $xp;
    }

    /** Recreate readable text from one Word paragraph/cell node. */
    private function node_text(\DOMNode $node) {
        $parts = array();
        $walk = function(\DOMNode $current) use (&$walk, &$parts) {
            if ($current instanceof \DOMElement) {
                $local = $current->localName;
                if ($local === 't') {
                    $parts[] = $current->textContent;
                    return;
                }
                if ($local === 'tab') {
                    $parts[] = "\t";
                    return;
                }
                if ($local === 'br' || $local === 'cr') {
                    $parts[] = "\n";
                    return;
                }
                if ($local === 'noBreakHyphen') {
                    $parts[] = '-';
                    return;
                }
            }
            foreach ($current->childNodes as $child) {
                $walk($child);
            }
        };
        $walk($node);
        $text = implode('', $parts);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim((string)$text);
    }

    /** Convert a paragraph into a compact plain/Markdown-like representation. */
    private function paragraph_text(\DOMElement $paragraph, \DOMXPath $xp) {
        $text = $this->node_text($paragraph);
        if ($text === '') {
            return '';
        }
        $style = '';
        $styles = $xp->query('./w:pPr/w:pStyle/@w:val', $paragraph);
        if ($styles && $styles->length) {
            $style = trim((string)$styles->item(0)->nodeValue);
        }
        $headinglevel = 0;
        if ($style !== '') {
            if (preg_match('/heading\s*([1-6])/iu', $style, $m)) {
                $headinglevel = (int)$m[1];
            } else if (preg_match('/заголовок\s*([1-6])/iu', $style, $m)) {
                $headinglevel = (int)$m[1];
            }
        }
        if ($headinglevel > 0) {
            return str_repeat('#', $headinglevel) . ' ' . $text;
        }
        $islist = $xp->query('./w:pPr/w:numPr', $paragraph);
        if ($islist && $islist->length) {
            return '- ' . $text;
        }
        return $text;
    }

    /** Convert one table to a readable row-oriented block. */
    private function table_text(\DOMElement $table, \DOMXPath $xp, $index) {
        $rows = array();
        $rownodes = $xp->query('./w:tr', $table);
        if ($rownodes) {
            foreach ($rownodes as $row) {
                $cells = array();
                $cellnodes = $xp->query('./w:tc', $row);
                if ($cellnodes) {
                    foreach ($cellnodes as $cell) {
                        $paragraphs = array();
                        $pnodes = $xp->query('.//w:p', $cell);
                        if ($pnodes) {
                            foreach ($pnodes as $p) {
                                $value = $this->node_text($p);
                                if ($value !== '') {
                                    $paragraphs[] = $value;
                                }
                            }
                        }
                        $cells[] = implode(' / ', $paragraphs);
                    }
                }
                if ($cells) {
                    $rows[] = implode(' | ', $cells);
                }
            }
        }
        if (!$rows) {
            return '';
        }
        $lines = array(get_string('docxtablelabel', 'local_kbaai', (int)$index));
        foreach ($rows as $i => $rowtext) {
            $lines[] = get_string('docxtablerow', 'local_kbaai', (object)array('row' => $i + 1, 'text' => $rowtext));
        }
        return implode("\n", $lines);
    }

    /** Normalize a relationship target such as media/image1.png. */
    private function normalize_word_target($target) {
        $target = str_replace('\\', '/', (string)$target);
        if ($target === '') {
            return '';
        }
        if ($target[0] === '/') {
            $target = ltrim($target, '/');
        } else {
            $target = 'word/' . $target;
        }
        $parts = array();
        foreach (explode('/', $target) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** Return MIME for supported embedded raster images. */
    private function image_mime($name) {
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 'image/jpeg';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }
        return '';
    }

    /** Find only images referenced from the main document body. */
    private function referenced_images(\ZipArchive $zip, \DOMDocument $documentdom, \DOMXPath $xp) {
        $ids = array();
        foreach (array('//a:blip/@r:embed', '//v:imagedata/@r:id') as $query) {
            $nodes = $xp->query($query);
            if ($nodes) {
                foreach ($nodes as $node) {
                    $id = trim((string)$node->nodeValue);
                    if ($id !== '') {
                        $ids[$id] = true;
                    }
                }
            }
        }
        if (!$ids) {
            return array('images' => array(), 'unsupported' => 0, 'skipped' => 0, 'referenced' => 0);
        }

        $relsxml = $this->zip_get($zip, 'word/_rels/document.xml.rels', self::MAX_RELATIONSHIPS_BYTES, false);
        if ($relsxml === '') {
            return array('images' => array(), 'unsupported' => count($ids), 'skipped' => 0, 'referenced' => count($ids));
        }
        $relsdom = $this->load_xml($relsxml, 'word/_rels/document.xml.rels');
        $relsxp = new \DOMXPath($relsdom);
        $relsxp->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $map = array();
        $rels = $relsxp->query('//rel:Relationship');
        if ($rels) {
            foreach ($rels as $rel) {
                if (!($rel instanceof \DOMElement)) {
                    continue;
                }
                $id = (string)$rel->getAttribute('Id');
                $target = (string)$rel->getAttribute('Target');
                if ($id !== '' && $target !== '') {
                    $map[$id] = $target;
                }
            }
        }

        $maximages = $this->setting_int('docxmaximages', 4, 0, 10);
        $images = array();
        $unsupported = 0;
        $skipped = 0;
        foreach (array_keys($ids) as $id) {
            if (empty($map[$id])) {
                $unsupported++;
                continue;
            }
            $path = $this->normalize_word_target($map[$id]);
            if (strpos($path, 'word/media/') !== 0) {
                $unsupported++;
                continue;
            }
            $mime = $this->image_mime($path);
            if ($mime === '') {
                $unsupported++;
                continue;
            }
            if (count($images) >= $maximages) {
                $skipped++;
                continue;
            }
            $stat = $zip->statName($path);
            if ($stat === false || (!empty($stat['size']) && (int)$stat['size'] > self::MAX_EMBEDDED_IMAGE_BYTES)) {
                $skipped++;
                continue;
            }
            $content = $zip->getFromName($path);
            if ($content === false || strlen($content) > self::MAX_EMBEDDED_IMAGE_BYTES) {
                $skipped++;
                continue;
            }
            $images[] = array(
                'filename' => basename($path),
                'mimetype' => $mime,
                'size' => strlen($content),
                'content' => $content,
                'relationshipid' => $id,
            );
        }
        return array(
            'images' => $images,
            'unsupported' => $unsupported,
            'skipped' => $skipped,
            'referenced' => count($ids),
        );
    }

    /**
     * Extract text/tables and referenced raster images from one DOCX binary.
     *
     * @param string $content Raw DOCX bytes.
     * @param string $filename Original filename.
     * @return array
     */
    public function extract($content, $filename) {
        global $CFG;
        if (!class_exists('\\ZipArchive')) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_zip_missing',
                get_string('docxerr_zipmissing', 'local_kbaai'),
                false
            );
        }
        $tmp = tempnam($CFG->tempdir, 'kbaai_docx_');
        if ($tmp === false || file_put_contents($tmp, (string)$content) === false) {
            throw new \local_kbaai\provider\provider_exception(
                'docx_temp_failed',
                get_string('docxerr_temp', 'local_kbaai'),
                false
            );
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($tmp);
        if ($opened !== true) {
            @unlink($tmp);
            throw new \local_kbaai\provider\provider_exception(
                'docx_invalid_package',
                get_string('docxerr_invalidpackage', 'local_kbaai'),
                false
            );
        }

        try {
            $documentxml = $this->zip_get($zip, 'word/document.xml', self::MAX_DOCUMENT_XML_BYTES, true);
            $dom = $this->load_xml($documentxml, 'word/document.xml');
            $xp = $this->xpath($dom);
            $body = $xp->query('//w:body')->item(0);
            if (!$body) {
                throw new \local_kbaai\provider\provider_exception(
                    'docx_missing_body',
                    get_string('docxerr_missingbody', 'local_kbaai'),
                    false
                );
            }

            $blocks = array();
            $paragraphcount = 0;
            $tablecount = 0;
            foreach ($body->childNodes as $child) {
                if (!($child instanceof \DOMElement)) {
                    continue;
                }
                if ($child->localName === 'p') {
                    $text = $this->paragraph_text($child, $xp);
                    if ($text !== '') {
                        $paragraphcount++;
                        $blocks[] = $text;
                    }
                } else if ($child->localName === 'tbl') {
                    $tablecount++;
                    $tabletext = $this->table_text($child, $xp, $tablecount);
                    if ($tabletext !== '') {
                        $blocks[] = $tabletext;
                    }
                }
            }

            $text = trim(implode("\n\n", $blocks));
            $maxchars = $this->setting_int('docxmaxchars', 60000, 10000, 180000);
            $truncated = false;
            $originalchars = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($originalchars > $maxchars) {
                $text = function_exists('mb_substr') ? mb_substr($text, 0, $maxchars, 'UTF-8') : substr($text, 0, $maxchars);
                $truncated = true;
            }

            $media = $this->referenced_images($zip, $dom, $xp);
            $header = get_string('docxevidenceheader', 'local_kbaai', (object)array(
                'filename' => $filename,
                'paragraphs' => $paragraphcount,
                'tables' => $tablecount,
            ));
            $evidence = trim($header . ($text !== '' ? "\n\n" . $text : ''));

            return array(
                'evidence' => $evidence,
                'text' => $text,
                'paragraph_count' => $paragraphcount,
                'table_count' => $tablecount,
                'char_count' => $originalchars,
                'truncated' => $truncated,
                'images' => $media['images'],
                'image_count_referenced' => $media['referenced'],
                'image_count_extracted' => count($media['images']),
                'image_count_unsupported' => $media['unsupported'],
                'image_count_skipped' => $media['skipped'],
            );
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /** Create and parse a tiny synthetic DOCX for administrator diagnostics. */
    public function diagnose() {
        global $CFG;
        $report = array(
            'ok' => false,
            'ziparchive' => class_exists('\\ZipArchive'),
            'domdocument' => class_exists('\\DOMDocument'),
            'paragraphs' => 0,
            'tables' => 0,
            'images' => 0,
            'preview' => '',
            'error' => '',
        );
        if (!$report['ziparchive'] || !$report['domdocument']) {
            $report['error'] = !$report['ziparchive'] ? get_string('docxerr_zipmissing', 'local_kbaai') : get_string('docxerr_dommissing', 'local_kbaai');
            return $report;
        }

        $tmp = tempnam($CFG->tempdir, 'kbaai_docx_test_');
        if ($tmp === false) {
            $report['error'] = get_string('docxerr_temp', 'local_kbaai');
            return $report;
        }
        $zip = new \ZipArchive();
        $res = $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($res !== true) {
            @unlink($tmp);
            $report['error'] = get_string('docxerr_invalidpackage', 'local_kbaai');
            return $report;
        }
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>'
            . '<w:p><w:r><w:t>KBA DOCX diagnostic text</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>A1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>B1</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '<w:p><w:r><w:drawing><a:blip r:embed="rId1"/></w:drawing></w:r></w:p>'
            . '<w:sectPr/></w:body></w:document>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/test.png"/>'
            . '</Relationships>';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2dOQAAAAASUVORK5CYII=');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        $zip->addFromString('word/media/test.png', $png);
        $zip->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);
        try {
            $result = $this->extract((string)$binary, 'kba-docx-diagnostic.docx');
            $report['paragraphs'] = (int)$result['paragraph_count'];
            $report['tables'] = (int)$result['table_count'];
            $report['images'] = (int)$result['image_count_extracted'];
            $report['preview'] = function_exists('mb_substr') ? mb_substr((string)$result['evidence'], 0, 500, 'UTF-8') : substr((string)$result['evidence'], 0, 500);
            $report['ok'] = ($report['paragraphs'] >= 1 && $report['tables'] >= 1 && $report['images'] >= 1);
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }
        return $report;
    }
}
