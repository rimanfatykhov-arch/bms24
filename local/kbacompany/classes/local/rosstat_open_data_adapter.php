<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only discovery adapter for the official Rosstat Open Data catalogue.
 *
 * This is deliberately a catalogue adapter, not a statistical ingestion engine.
 * It returns source candidates that a user may review and attach as Market Evidence.
 */
class rosstat_open_data_adapter {
    public const PROVIDER_ID = 'rosstat_open_data';
    public const CATALOGUE_URL = 'https://rosstat.gov.ru/opendata';

    /** Provider metadata exposed through the Market Data Tool Gateway. */
    public static function descriptor(): array {
        return [
            'id' => self::PROVIDER_ID,
            'name' => 'Rosstat Open Data',
            'mode' => 'read_only_catalogue',
            'auth' => 'none',
            'source_url' => self::CATALOGUE_URL,
            'writes_canonical_data' => false,
        ];
    }

    /**
     * Search the public Rosstat open-data catalogue and return evidence candidates.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function search(string $query, int $limit = 10): array {
        global $CFG;
        $query = trim($query);
        if ($query === '') {
            throw new \invalid_parameter_exception('Market data search query is required.');
        }
        $limit = max(1, min(20, $limit));
        require_once($CFG->libdir . '/filelib.php');

        // Do not guess undocumented provider query parameters: fetch the official catalogue and filter locally.
        $url = self::CATALOGUE_URL;
        $curl = new \curl();
        $html = $curl->get($url, [], [
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'BMS24-MarketDataGateway/0.1',
        ]);
        $info = $curl->get_info();
        $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($html === false || $status < 200 || $status >= 400) {
            throw new \moodle_exception('marketdata_provider_unavailable', 'local_kbacompany');
        }

        $items = self::parse_catalogue((string)$html, $query, $limit);
        return [
            'provider' => self::PROVIDER_ID,
            'provider_name' => 'Rosstat Open Data',
            'query' => $query,
            'source_url' => $url,
            'fetched_at' => time(),
            'items' => $items,
            'read_only' => true,
        ];
    }

    /** Parse catalogue links conservatively; never invent a dataset URL. */
    private static function parse_catalogue(string $html, string $query, int $limit): array {
        if (!class_exists('DOMDocument')) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $anchors = $xpath->query('//a[@href]');
        $tokens = preg_split('/\\s+/u', mb_strtolower($query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];
        $items = [];
        foreach ($anchors as $anchor) {
            $title = trim(preg_replace('/\\s+/u', ' ', $anchor->textContent));
            $href = trim((string)$anchor->getAttribute('href'));
            if ($title === '' || $href === '' || mb_strlen($title, 'UTF-8') < 8) {
                continue;
            }
            // Only catalogue/dataset links from the official Rosstat host are candidates.
            if (strpos($href, '/opendata') === false) {
                continue;
            }
            $titlelower = mb_strtolower($title, 'UTF-8');
            $matches = empty($tokens);
            foreach ($tokens as $token) {
                if (mb_strlen($token, 'UTF-8') >= 3 && mb_strpos($titlelower, $token, 0, 'UTF-8') !== false) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            if (strpos($href, 'http://') !== 0 && strpos($href, 'https://') !== 0) {
                $href = 'https://rosstat.gov.ru/' . ltrim($href, '/');
            }
            if (strpos($href, 'https://rosstat.gov.ru/') !== 0 && strpos($href, 'https://www.rosstat.gov.ru/') !== 0) {
                continue;
            }
            $key = sha1($title . '|' . $href);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $context = '';
            $node = $anchor->parentNode;
            for ($i = 0; $node && $i < 3; $i++, $node = $node->parentNode) {
                $candidate = trim(preg_replace('/\\s+/u', ' ', $node->textContent));
                if (mb_strlen($candidate, 'UTF-8') <= 800) {
                    $context = $candidate;
                }
            }
            $updated = '';
            if (preg_match('/Обновлено:\\s*([0-9]{2}\\.[0-9]{2}\\.[0-9]{4})/u', $context, $m)) {
                $updated = $m[1];
            }
            $items[] = [
                'title' => $title,
                'source_ref' => $href,
                'provider' => self::PROVIDER_ID,
                'updated' => $updated,
                'evidence_marker' => 'data',
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }
}
