<?php
namespace local_kbaai\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Safe local XLSX extraction for official submission evidence.
 *
 * XLSX is an OOXML ZIP package. This class reads workbook metadata, worksheet
 * values, cached formula results, merged ranges and table definitions. It does
 * not execute formulas, macros, external links, Power Query, OLE objects or
 * any other active content. Extracted workbook content remains untrusted
 * student-authored evidence.
 */
class xlsx_extractor {
    const MAX_WORKBOOK_XML_BYTES = 5242880;       // 5 MiB.
    const MAX_WORKSHEET_XML_BYTES = 52428800;     // 50 MiB per worksheet.
    const MAX_SHARED_STRINGS_BYTES = 26214400;    // 25 MiB.
    const MAX_STYLES_BYTES = 10485760;            // 10 MiB.
    const MAX_RELATIONSHIPS_BYTES = 4194304;       // 4 MiB.
    const MAX_TABLE_XML_BYTES = 2097152;           // 2 MiB per table definition.

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
                    'xlsx_missing_part',
                    get_string('xlsxerr_missingpart', 'local_kbaai', $name),
                    false
                );
            }
            return '';
        }
        if (!empty($stat['size']) && (int)$stat['size'] > (int)$maxbytes) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_part_too_large',
                get_string('xlsxerr_parttoolarge', 'local_kbaai', $name),
                false
            );
        }
        $data = $zip->getFromName($name);
        if ($data === false) {
            if ($required) {
                throw new \local_kbaai\provider\provider_exception(
                    'xlsx_read_failed',
                    get_string('xlsxerr_readpart', 'local_kbaai', $name),
                    false
                );
            }
            return '';
        }
        if (strlen($data) > (int)$maxbytes) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_part_too_large',
                get_string('xlsxerr_parttoolarge', 'local_kbaai', $name),
                false
            );
        }
        return $data;
    }

    /** Safely parse XML without network access. */
    private function load_xml($xml, $label) {
        if (!class_exists('\\DOMDocument')) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_dom_missing',
                get_string('xlsxerr_dommissing', 'local_kbaai'),
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
                'xlsx_invalid_xml',
                get_string('xlsxerr_invalidxml', 'local_kbaai', $label),
                false
            );
        }
        return $dom;
    }

    /** Build XPath with SpreadsheetML namespaces. */
    private function worksheet_xpath(\DOMDocument $dom) {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        return $xp;
    }

    /** Normalize an OOXML relationship target relative to a package part. */
    private function normalize_target($sourcepart, $target) {
        $target = str_replace('\\', '/', trim((string)$target));
        if ($target === '') {
            return '';
        }
        if ($target[0] === '/') {
            $candidate = ltrim($target, '/');
        } else {
            $base = dirname(str_replace('\\', '/', (string)$sourcepart));
            $candidate = ($base === '.' ? '' : $base . '/') . $target;
        }
        $parts = array();
        foreach (explode('/', $candidate) as $part) {
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

    /** Read relationship map; external targets are counted but never followed. */
    private function relationship_map(\ZipArchive $zip, $sourcepart, $relspart, &$externalcount) {
        $externalcount = 0;
        $xml = $this->zip_get($zip, $relspart, self::MAX_RELATIONSHIPS_BYTES, false);
        if ($xml === '') {
            return array();
        }
        $dom = $this->load_xml($xml, $relspart);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $map = array();
        $nodes = $xp->query('//rel:Relationship');
        if ($nodes) {
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }
                $id = (string)$node->getAttribute('Id');
                $target = (string)$node->getAttribute('Target');
                $mode = strtolower((string)$node->getAttribute('TargetMode'));
                $type = (string)$node->getAttribute('Type');
                if ($mode === 'external') {
                    $externalcount++;
                    $map[$id] = array('external' => true, 'target' => $target, 'type' => $type);
                    continue;
                }
                $map[$id] = array(
                    'external' => false,
                    'target' => $this->normalize_target($sourcepart, $target),
                    'type' => $type,
                );
            }
        }
        return $map;
    }

    /** Collect shared strings including rich-text runs. */
    private function shared_strings(\ZipArchive $zip) {
        $xml = $this->zip_get($zip, 'xl/sharedStrings.xml', self::MAX_SHARED_STRINGS_BYTES, false);
        if ($xml === '') {
            return array();
        }
        $dom = $this->load_xml($xml, 'xl/sharedStrings.xml');
        $xp = $this->worksheet_xpath($dom);
        $out = array();
        $nodes = $xp->query('//s:si');
        if ($nodes) {
            foreach ($nodes as $si) {
                $parts = array();
                $texts = $xp->query('.//s:t', $si);
                if ($texts) {
                    foreach ($texts as $text) {
                        $parts[] = (string)$text->textContent;
                    }
                }
                $out[] = implode('', $parts);
            }
        }
        return $out;
    }

    /** Parse style indexes and number formats used for dates/times. */
    private function styles(\ZipArchive $zip) {
        $xml = $this->zip_get($zip, 'xl/styles.xml', self::MAX_STYLES_BYTES, false);
        if ($xml === '') {
            return array('xf_numfmt' => array(), 'custom' => array());
        }
        $dom = $this->load_xml($xml, 'xl/styles.xml');
        $xp = $this->worksheet_xpath($dom);
        $custom = array();
        $numfmts = $xp->query('//s:numFmts/s:numFmt');
        if ($numfmts) {
            foreach ($numfmts as $numfmt) {
                if ($numfmt instanceof \DOMElement) {
                    $custom[(int)$numfmt->getAttribute('numFmtId')] = (string)$numfmt->getAttribute('formatCode');
                }
            }
        }
        $xf = array();
        $xfs = $xp->query('//s:cellXfs/s:xf');
        if ($xfs) {
            foreach ($xfs as $node) {
                $xf[] = $node instanceof \DOMElement ? (int)$node->getAttribute('numFmtId') : 0;
            }
        }
        return array('xf_numfmt' => $xf, 'custom' => $custom);
    }

    /** Determine whether a number format represents a date or time. */
    private function date_format_kind($numfmtid, array $styles) {
        $dateids = array(14, 15, 16, 17, 22, 27, 28, 29, 30, 31, 34, 35, 36, 50, 51, 52, 53, 54, 55, 56, 57, 58);
        $timeids = array(18, 19, 20, 21, 45, 46, 47);
        if (in_array((int)$numfmtid, $dateids, true)) {
            return 'date';
        }
        if (in_array((int)$numfmtid, $timeids, true)) {
            return 'time';
        }
        if (empty($styles['custom'][(int)$numfmtid])) {
            return '';
        }
        $code = strtolower((string)$styles['custom'][(int)$numfmtid]);
        $code = preg_replace('/"[^"]*"/u', '', $code);
        $code = preg_replace('/\\\\./u', '', $code);
        $code = preg_replace('/\[[^\]]*\]/u', '', $code);
        $hasdate = preg_match('/(^|[^a-z])[dmy]+([^a-z]|$)/u', $code);
        $hastime = preg_match('/(^|[^a-z])[hs]+([^a-z]|$)/u', $code);
        if ($hasdate && $hastime) {
            return 'datetime';
        }
        if ($hasdate) {
            return 'date';
        }
        if ($hastime) {
            return 'time';
        }
        return '';
    }

    /** Convert an Excel serial date/time to ISO-like text. */
    private function excel_date($value, $kind, $date1904) {
        if (!is_numeric($value)) {
            return (string)$value;
        }
        $serial = (float)$value;
        $days = floor($serial);
        $fraction = $serial - $days;
        if ($date1904) {
            $base = new \DateTimeImmutable('1904-01-01 00:00:00', new \DateTimeZone('UTC'));
        } else {
            // 1899-12-30 accounts for Excel's historical 1900 leap-year bug.
            $base = new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC'));
        }
        $seconds = (int)round(($days * 86400) + ($fraction * 86400));
        $dt = $base->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
        if ($kind === 'time') {
            return $dt->format('H:i:s');
        }
        if ($kind === 'datetime') {
            return $dt->format('Y-m-d H:i:s');
        }
        return $dt->format('Y-m-d');
    }

    /** Return a readable value for one SpreadsheetML cell. */
    private function cell_value(\DOMElement $cell, \DOMXPath $xp, array $shared, array $styles, $date1904) {
        $type = (string)$cell->getAttribute('t');
        $styleindex = $cell->hasAttribute('s') ? (int)$cell->getAttribute('s') : 0;
        $vnode = $xp->query('./s:v', $cell);
        $raw = ($vnode && $vnode->length) ? (string)$vnode->item(0)->textContent : '';
        if ($type === 'inlineStr') {
            $parts = array();
            $tnodes = $xp->query('./s:is//s:t', $cell);
            if ($tnodes) {
                foreach ($tnodes as $node) {
                    $parts[] = (string)$node->textContent;
                }
            }
            return implode('', $parts);
        }
        if ($type === 's') {
            $idx = (int)$raw;
            return array_key_exists($idx, $shared) ? (string)$shared[$idx] : '';
        }
        if ($type === 'b') {
            return $raw === '1' ? get_string('yes') : get_string('no');
        }
        if ($type === 'e') {
            return $raw === '' ? '#ERROR' : $raw;
        }
        if ($type === 'str' || $type === 'd') {
            return $raw;
        }
        $numfmtid = !empty($styles['xf_numfmt'][$styleindex]) ? (int)$styles['xf_numfmt'][$styleindex] : 0;
        $kind = $this->date_format_kind($numfmtid, $styles);
        if ($kind !== '' && $raw !== '') {
            return $this->excel_date($raw, $kind, $date1904);
        }
        return $raw;
    }

    /** Read table definitions attached to one worksheet. */
    private function sheet_tables(\ZipArchive $zip, $sheetpart, \DOMDocument $sheetdom, \DOMXPath $sheetxp, &$externalcount) {
        $tables = array();
        $relspart = dirname($sheetpart) . '/_rels/' . basename($sheetpart) . '.rels';
        $sheetexternal = 0;
        $rels = $this->relationship_map($zip, $sheetpart, $relspart, $sheetexternal);
        $externalcount += $sheetexternal;
        $ids = $sheetxp->query('//s:tableParts/s:tablePart/@r:id');
        if (!$ids) {
            return $tables;
        }
        foreach ($ids as $idnode) {
            $id = (string)$idnode->nodeValue;
            if (empty($rels[$id]) || !empty($rels[$id]['external'])) {
                continue;
            }
            $part = (string)$rels[$id]['target'];
            $xml = $this->zip_get($zip, $part, self::MAX_TABLE_XML_BYTES, false);
            if ($xml === '') {
                continue;
            }
            $dom = $this->load_xml($xml, $part);
            $root = $dom->documentElement;
            if (!$root) {
                continue;
            }
            $tables[] = array(
                'name' => (string)($root->getAttribute('displayName') ?: $root->getAttribute('name')),
                'ref' => (string)$root->getAttribute('ref'),
            );
        }
        return $tables;
    }

    /** Detect potentially active or unsupported package features without executing them. */
    private function package_flags(\ZipArchive $zip) {
        $macros = 0;
        $externallinkparts = 0;
        $drawings = 0;
        $embeddings = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = !empty($stat['name']) ? strtolower(str_replace('\\', '/', (string)$stat['name'])) : '';
            if ($name === '') {
                continue;
            }
            if (substr($name, -14) === 'vbaproject.bin' || strpos($name, '/vbaproject.bin') !== false) {
                $macros++;
            }
            if (strpos($name, 'xl/externallinks/') === 0) {
                $externallinkparts++;
            }
            if (strpos($name, 'xl/drawings/') === 0 || strpos($name, 'xl/charts/') === 0) {
                $drawings++;
            }
            if (strpos($name, 'xl/embeddings/') === 0) {
                $embeddings++;
            }
        }
        return array(
            'macros' => $macros,
            'external_link_parts' => $externallinkparts,
            'drawings' => $drawings,
            'embeddings' => $embeddings,
        );
    }

    /** Compact text clamp used for evidence. */
    private function clamp_text($text, $maxchars, &$truncated) {
        $text = (string)$text;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length <= (int)$maxchars) {
            return $text;
        }
        $truncated = true;
        return function_exists('mb_substr') ? mb_substr($text, 0, (int)$maxchars, 'UTF-8') : substr($text, 0, (int)$maxchars);
    }

    /**
     * Extract normalized workbook evidence.
     *
     * @param string $content XLSX binary.
     * @param string $filename Display filename.
     * @return array Extraction result and diagnostics.
     */
    public function extract($content, $filename = 'workbook.xlsx') {
        if (!class_exists('\\ZipArchive')) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_zip_missing',
                get_string('xlsxerr_zipmissing', 'local_kbaai'),
                false
            );
        }
        if (!class_exists('\\DOMDocument')) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_dom_missing',
                get_string('xlsxerr_dommissing', 'local_kbaai'),
                false
            );
        }
        $maxchars = $this->setting_int('xlsxmaxchars', 80000, 10000, 240000);
        $maxsheets = $this->setting_int('xlsxmaxsheets', 20, 1, 100);
        $maxcells = $this->setting_int('xlsxmaxcells', 10000, 100, 50000);
        $maxrows = $this->setting_int('xlsxmaxrows', 2000, 10, 20000);

        $temp = tempnam(make_temp_directory('local_kbaai'), 'xlsx_');
        if ($temp === false || file_put_contents($temp, (string)$content) === false) {
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_temp_failed',
                get_string('xlsxerr_temp', 'local_kbaai'),
                false
            );
        }
        $zip = new \ZipArchive();
        $open = $zip->open($temp);
        if ($open !== true) {
            @unlink($temp);
            throw new \local_kbaai\provider\provider_exception(
                'xlsx_invalid_package',
                get_string('xlsxerr_invalidpackage', 'local_kbaai'),
                false
            );
        }

        try {
            $flags = $this->package_flags($zip);
            $shared = $this->shared_strings($zip);
            $styles = $this->styles($zip);
            $workbookxml = $this->zip_get($zip, 'xl/workbook.xml', self::MAX_WORKBOOK_XML_BYTES, true);
            $workbookdom = $this->load_xml($workbookxml, 'xl/workbook.xml');
            $workbookxp = $this->worksheet_xpath($workbookdom);
            $date1904 = false;
            $workbookpr = $workbookxp->query('//s:workbookPr/@date1904');
            if ($workbookpr && $workbookpr->length) {
                $date1904 = in_array(strtolower((string)$workbookpr->item(0)->nodeValue), array('1', 'true'), true);
            }
            $externalrelationships = 0;
            $workbookrels = $this->relationship_map(
                $zip,
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
                $externalrelationships
            );

            $sheetnodes = $workbookxp->query('//s:sheets/s:sheet');
            if (!$sheetnodes || !$sheetnodes->length) {
                throw new \local_kbaai\provider\provider_exception(
                    'xlsx_no_sheets',
                    get_string('xlsxerr_nosheets', 'local_kbaai'),
                    false
                );
            }

            $evidenceparts = array();
            $sheetsummaries = array();
            $sheetcount = (int)$sheetnodes->length;
            $processed = 0;
            $cellcount = 0;
            $formulacount = 0;
            $formulacached = 0;
            $formulauncached = 0;
            $formulaunresolved = 0;
            $mergedcount = 0;
            $tablecount = 0;
            $rowstruncated = 0;
            $cellstruncated = 0;
            $truncated = false;

            $header = get_string('xlsxevidenceheader', 'local_kbaai', (object)array(
                'filename' => $filename,
                'sheets' => $sheetcount,
            ));
            $evidenceparts[] = $header;

            foreach ($sheetnodes as $sheetnode) {
                if ($processed >= $maxsheets || $cellcount >= $maxcells) {
                    $truncated = true;
                    break;
                }
                if (!($sheetnode instanceof \DOMElement)) {
                    continue;
                }
                $name = (string)$sheetnode->getAttribute('name');
                $state = (string)$sheetnode->getAttribute('state');
                $rid = (string)$sheetnode->getAttributeNS(
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                    'id'
                );
                if ($rid === '' || empty($workbookrels[$rid]) || !empty($workbookrels[$rid]['external'])) {
                    $truncated = true;
                    continue;
                }
                $sheetpart = (string)$workbookrels[$rid]['target'];
                $sheetxml = $this->zip_get($zip, $sheetpart, self::MAX_WORKSHEET_XML_BYTES, true);
                $sheetdom = $this->load_xml($sheetxml, $sheetpart);
                $sheetxp = $this->worksheet_xpath($sheetdom);
                $sheetexternal = 0;
                $tables = $this->sheet_tables($zip, $sheetpart, $sheetdom, $sheetxp, $sheetexternal);
                $externalrelationships += $sheetexternal;
                $tablecount += count($tables);

                $merged = array();
                $mergenodes = $sheetxp->query('//s:mergeCells/s:mergeCell/@ref');
                if ($mergenodes) {
                    foreach ($mergenodes as $merge) {
                        $merged[] = (string)$merge->nodeValue;
                    }
                }
                $mergedcount += count($merged);

                $sheetlines = array();
                $sheetlines[] = get_string('xlsxsheetlabel', 'local_kbaai', (object)array(
                    'name' => $name,
                    'state' => $state === '' ? 'visible' : $state,
                ));
                if ($tables) {
                    $labels = array();
                    foreach ($tables as $table) {
                        $labels[] = ($table['name'] !== '' ? $table['name'] : get_string('xlsxtableunnamed', 'local_kbaai'))
                            . ($table['ref'] !== '' ? ' [' . $table['ref'] . ']' : '');
                    }
                    $sheetlines[] = get_string('xlsxtableslabel', 'local_kbaai') . ': ' . implode(', ', $labels);
                }
                if ($merged) {
                    $sheetlines[] = get_string('xlsxmergedlabel', 'local_kbaai') . ': ' . implode(', ', array_slice($merged, 0, 100));
                    if (count($merged) > 100) {
                        $truncated = true;
                    }
                }

                $sheetcells = 0;
                $sheetformulas = 0;
                $sheetrows = 0;
                $rownodes = $sheetxp->query('//s:sheetData/s:row');
                if ($rownodes) {
                    foreach ($rownodes as $rownode) {
                        if ($sheetrows >= $maxrows || $cellcount >= $maxcells) {
                            $truncated = true;
                            $rowstruncated++;
                            break;
                        }
                        $sheetrows++;
                        $rownumber = $rownode instanceof \DOMElement && $rownode->hasAttribute('r')
                            ? (int)$rownode->getAttribute('r') : $sheetrows;
                        $rowvalues = array();
                        $cells = $sheetxp->query('./s:c', $rownode);
                        if ($cells) {
                            foreach ($cells as $cell) {
                                if ($cellcount >= $maxcells) {
                                    $truncated = true;
                                    $cellstruncated++;
                                    break;
                                }
                                if (!($cell instanceof \DOMElement)) {
                                    continue;
                                }
                                $ref = (string)$cell->getAttribute('r');
                                if ($ref === '') {
                                    $ref = '?' . $rownumber;
                                }
                                $formula = '';
                                $fnode = $sheetxp->query('./s:f', $cell);
                                $hasformula = $fnode && $fnode->length;
                                if ($hasformula) {
                                    $formula = trim((string)$fnode->item(0)->textContent);
                                    if ($formula === '') {
                                        $formula = get_string('xlsxsharedformula', 'local_kbaai');
                                        $formulaunresolved++;
                                    }
                                }
                                $value = $this->cell_value($cell, $sheetxp, $shared, $styles, $date1904);
                                if ($value === '' && !$hasformula) {
                                    continue;
                                }
                                $cellcount++;
                                $sheetcells++;
                                $safevalue = str_replace(array("\r", "\n", "\t"), array(' ', ' / ', ' '), (string)$value);
                                $safevalue = preg_replace('/\s+/u', ' ', $safevalue);
                                $valuelen = function_exists('mb_strlen') ? mb_strlen($safevalue, 'UTF-8') : strlen($safevalue);
                                if ($valuelen > 4000) {
                                    $safevalue = function_exists('mb_substr') ? mb_substr($safevalue, 0, 4000, 'UTF-8') : substr($safevalue, 0, 4000);
                                    $safevalue .= '…';
                                    $truncated = true;
                                    $cellstruncated++;
                                }
                                if ($hasformula) {
                                    $formulacount++;
                                    $sheetformulas++;
                                    if ($value !== '') {
                                        $formulacached++;
                                        $rowvalues[] = $ref . ' = ' . $safevalue . ' [formula: =' . $formula . ']';
                                    } else {
                                        $formulauncached++;
                                        $rowvalues[] = $ref . ' [formula: =' . $formula . '; cached result unavailable]';
                                    }
                                } else {
                                    $rowvalues[] = $ref . ' = ' . $safevalue;
                                }
                            }
                        }
                        if ($rowvalues) {
                            $sheetlines[] = get_string('xlsxrowlabel', 'local_kbaai', (object)array(
                                'row' => $rownumber,
                                'text' => implode(' | ', $rowvalues),
                            ));
                        }
                    }
                }

                $sheetsummaries[] = array(
                    'name' => $name,
                    'state' => $state === '' ? 'visible' : $state,
                    'rows' => $sheetrows,
                    'cells' => $sheetcells,
                    'formulas' => $sheetformulas,
                    'merged' => count($merged),
                    'tables' => count($tables),
                );
                $evidenceparts[] = implode("\n", $sheetlines);
                $processed++;
            }

            $evidence = trim(implode("\n\n", $evidenceparts));
            $evidence = $this->clamp_text($evidence, $maxchars, $truncated);
            $partial = $truncated
                || $processed < $sheetcount
                || $formulauncached > 0
                || $formulaunresolved > 0
                || $externalrelationships > 0
                || !empty($flags['external_link_parts'])
                || !empty($flags['macros'])
                || !empty($flags['drawings'])
                || !empty($flags['embeddings']);

            return array(
                'evidence' => $evidence,
                'sheet_count' => $sheetcount,
                'sheet_count_processed' => $processed,
                'cell_count' => $cellcount,
                'formula_count' => $formulacount,
                'formula_cached_count' => $formulacached,
                'formula_uncached_count' => $formulauncached,
                'formula_unresolved_count' => $formulaunresolved,
                'merged_count' => $mergedcount,
                'table_count' => $tablecount,
                'external_link_count' => $externalrelationships + (int)$flags['external_link_parts'],
                'macro_count' => (int)$flags['macros'],
                'drawing_count' => (int)$flags['drawings'],
                'embedding_count' => (int)$flags['embeddings'],
                'date1904' => $date1904,
                'truncated' => $truncated,
                'partial' => $partial,
                'rows_truncated' => $rowstruncated,
                'cells_truncated' => $cellstruncated,
                'sheet_summaries' => $sheetsummaries,
                'char_count' => function_exists('mb_strlen') ? mb_strlen($evidence, 'UTF-8') : strlen($evidence),
            );
        } finally {
            $zip->close();
            @unlink($temp);
        }
    }

    /** Build a small synthetic XLSX used by the administrator self-test. */
    public static function build_test_xlsx() {
        if (!class_exists('\\ZipArchive')) {
            return '';
        }
        $temp = tempnam(sys_get_temp_dir(), 'kba_xlsx_test_');
        if ($temp === false) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($temp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            return '';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="План" sheetId="1" r:id="rId1"/><sheet name="Данные" sheetId="2" state="hidden" r:id="rId2"/></sheets>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $strings = array('План продаж', 'Показатель', 'Значение', 'Продажи', 'Итого', 'Источник', 'Тест');
        $sis = '';
        foreach ($strings as $value) {
            $sis .= '<si><t>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="7" uniqueCount="7">' . $sis . '</sst>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2" t="s"><v>2</v></c><c r="C2" t="inlineStr"><is><t>Дата</t></is></c></row>'
            . '<row r="3"><c r="A3" t="s"><v>3</v></c><c r="B3"><v>120</v></c><c r="C3" s="1"><v>46253</v></c></row>'
            . '<row r="4"><c r="A4" t="s"><v>4</v></c><c r="B4"><f>SUM(B3:B3)</f><v>120</v></c></row>'
            . '</sheetData><mergeCells count="1"><mergeCell ref="A1:C1"/></mergeCells>'
            . '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
            . '</worksheet>');
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/tables/table1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="PlanTable" displayName="PlanTable" ref="A2:C4" totalsRowShown="0">'
            . '<autoFilter ref="A2:C4"/><tableColumns count="3"><tableColumn id="1" name="Показатель"/><tableColumn id="2" name="Значение"/><tableColumn id="3" name="Дата"/></tableColumns>'
            . '</table>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>5</v></c><c r="B1" t="s"><v>6</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();
        $content = file_get_contents($temp);
        @unlink($temp);
        return $content === false ? '' : $content;
    }

    /** Local extraction diagnostic; no external API call is made. */
    public function diagnose() {
        $base = array(
            'ziparchive' => class_exists('\\ZipArchive'),
            'domdocument' => class_exists('\\DOMDocument'),
        );
        if (!$base['ziparchive'] || !$base['domdocument']) {
            $base['ok'] = false;
            $base['error'] = !$base['ziparchive']
                ? get_string('xlsxerr_zipmissing', 'local_kbaai')
                : get_string('xlsxerr_dommissing', 'local_kbaai');
            return $base;
        }
        try {
            $content = self::build_test_xlsx();
            if ($content === '') {
                throw new \RuntimeException(get_string('xlsxerr_testbuild', 'local_kbaai'));
            }
            $result = $this->extract($content, 'kba-xlsx-test.xlsx');
            $base['ok'] = !empty($result['evidence'])
                && (int)$result['sheet_count'] === 2
                && (int)$result['formula_count'] >= 1
                && (int)$result['merged_count'] >= 1
                && (int)$result['table_count'] >= 1;
            $base['input_bytes'] = strlen($content);
            $base['sheets'] = (int)$result['sheet_count'];
            $base['sheets_processed'] = (int)$result['sheet_count_processed'];
            $base['cells'] = (int)$result['cell_count'];
            $base['formulas'] = (int)$result['formula_count'];
            $base['formulas_cached'] = (int)$result['formula_cached_count'];
            $base['merged'] = (int)$result['merged_count'];
            $base['tables'] = (int)$result['table_count'];
            $base['preview'] = function_exists('mb_substr')
                ? mb_substr((string)$result['evidence'], 0, 1200, 'UTF-8')
                : substr((string)$result['evidence'], 0, 1200);
            if (!$base['ok']) {
                $base['error'] = get_string('xlsxerr_selftest', 'local_kbaai');
            }
        } catch (\Throwable $e) {
            $base['ok'] = false;
            $base['error'] = $e->getMessage();
        }
        return $base;
    }
}
