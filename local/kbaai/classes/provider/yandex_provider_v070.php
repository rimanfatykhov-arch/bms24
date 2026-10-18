<?php
namespace local_kbaai\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Production Yandex provider with DOCX extraction support.
 *
 * DOCX text/tables are extracted locally; only referenced raster images are
 * sent through the already-supported multimodal model.
 */
class yandex_provider_v070 extends yandex_provider_v060 {
    public function get_capabilities() {
        $caps = parent::get_capabilities();
        $caps['docx_extraction'] = true;
        return $caps;
    }

    /**
     * Analyse DOCX in addition to the attachment types supported by v060.
     */
    public function analyse_attachments(array $attachments, $language = 'ru') {
        $out = array();
        foreach ($attachments as $attachment) {
            if (empty($attachment['kind']) || $attachment['kind'] !== 'docx') {
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
                $extracted = (new \local_kbaai\service\docx_extractor())->extract(
                    $content,
                    !empty($attachment['filename']) ? (string)$attachment['filename'] : 'document.docx'
                );

                $evidenceparts = array();
                if (trim((string)$extracted['evidence']) !== '') {
                    $evidenceparts[] = trim((string)$extracted['evidence']);
                }
                $imageanalysed = 0;
                $imagefailed = 0;
                $imageindex = 0;
                foreach ($extracted['images'] as $image) {
                    $imageindex++;
                    $pseudo = array(
                        'filename' => (!empty($attachment['filename']) ? (string)$attachment['filename'] : 'document.docx')
                            . ' / ' . (string)$image['filename'],
                        'filepath' => '/',
                        'mimetype' => (string)$image['mimetype'],
                        'size' => (int)$image['size'],
                        'contenthash' => '',
                        'kind' => 'image',
                        'content' => (string)$image['content'],
                    );
                    $analysed = parent::analyse_attachments(array($pseudo), $language);
                    $ir = $analysed ? reset($analysed) : array();
                    if (!empty($ir['analysis_status']) && $ir['analysis_status'] === 'analysed' && !empty($ir['evidence'])) {
                        $imageanalysed++;
                        $evidenceparts[] = get_string('docximageevidence', 'local_kbaai', (object)array(
                            'index' => $imageindex,
                            'filename' => (string)$image['filename'],
                            'evidence' => trim((string)$ir['evidence']),
                        ));
                    } else {
                        $imagefailed++;
                    }
                }

                $partial = !empty($extracted['truncated'])
                    || !empty($extracted['image_count_unsupported'])
                    || !empty($extracted['image_count_skipped'])
                    || $imagefailed > 0;
                $hascontent = trim(implode("\n\n", $evidenceparts)) !== '';

                if (!$hascontent) {
                    $result['analysis_status'] = 'failed';
                    $result['analysis_error'] = get_string('docxerr_empty', 'local_kbaai');
                    $result['evidence'] = '';
                } else {
                    $result['analysis_status'] = $partial ? 'partial' : 'analysed';
                    $result['evidence'] = trim(implode("\n\n", $evidenceparts));
                    if ($partial) {
                        $result['analysis_error'] = get_string('docxpartialreason', 'local_kbaai');
                    }
                }

                $result['analysis_method'] = 'docx_xml_multimodal';
                $result['analysis_paragraphs'] = (int)$extracted['paragraph_count'];
                $result['analysis_tables'] = (int)$extracted['table_count'];
                $result['analysis_characters'] = (int)$extracted['char_count'];
                $result['analysis_truncated'] = !empty($extracted['truncated']) ? 1 : 0;
                $result['analysis_images'] = (int)$extracted['image_count_referenced'];
                $result['analysis_images_extracted'] = (int)$extracted['image_count_extracted'];
                $result['analysis_images_analysed'] = (int)$imageanalysed;
                $result['analysis_images_unsupported'] = (int)$extracted['image_count_unsupported'];
                $result['analysis_images_skipped'] = (int)$extracted['image_count_skipped'];
            } catch (provider_exception $e) {
                $details = $e->get_provider_details();
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'docx_xml_multimodal';
                $result['provider_request_id'] = !empty($details['requestid']) ? (string)$details['requestid'] : '';
                $result['analysis_http_status'] = isset($details['httpstatus']) ? (int)$details['httpstatus'] : 0;
            } catch (\Throwable $e) {
                $result['analysis_status'] = 'failed';
                $result['evidence'] = '';
                $result['analysis_error'] = $e->getMessage();
                $result['analysis_method'] = 'docx_xml_multimodal';
            }
            $out[] = $result;
        }
        return $out;
    }

    /** Add a local DOCX extraction check to the existing provider diagnostics. */
    public function diagnose($full = false) {
        $report = parent::diagnose($full);
        $report['docx'] = (new \local_kbaai\service\docx_extractor())->diagnose();
        return $report;
    }
}
