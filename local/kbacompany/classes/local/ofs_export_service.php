<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Export provider for the BMS OFS visual model.
 *
 * SVG, PDF and Draw.io must all be derived from the same poster projection.
 * Export files are delivery artefacts; BMS remains the source of truth.
 */
class ofs_export_service {
    public const CONTRACT_VERSION = '0.4.6.13';

    private const WIDTH = 1600;
    private const MARGIN = 36;
    private const GAP = 10;
    private const GOLD = '#E8B632';
    private const GOLD_LIGHT = '#FFF2BF';
    private const PANEL = '#F5F7F8';
    private const PANEL_HEAD = '#E8EDF2';
    private const INK = '#17202A';
    private const MUTED = '#66737D';
    private const BORDER = '#CDD5DB';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ofs_export',
            'version' => self::CONTRACT_VERSION,
            'source_of_truth' => 'ofs_visual_designer_service::poster()',
            'formats' => ['svg', 'pdf', 'drawio'],
            'pdf_papers' => ['AUTO', 'A0', 'A1', 'A2', 'A3', 'A4'],
            'rules' => [
                'one_visual_model_for_all_exports',
                'export_is_not_source_of_truth',
                'drawio_contains_bms_uuid_and_revision_metadata',
                'pdf_is_vector_svg_based',
                'no_business_data_written_during_export',
            ],
        ];
    }

    /** Build an export context from the same visual projection as the OFS designer. */
    public static function context(string $companyuuid, int $userid, string $designuuid = ''): array {
        $data = ofs_visual_designer_service::context($companyuuid, $userid, $designuuid);
        if (empty($data['poster'])) {
            throw new \moodle_exception('ofsdesigner_noproject', 'local_kbacompany');
        }
        $revision = 0;
        if (!empty($data['version'])) {
            $revision = (int)$data['version']->revision;
        } else if (!empty($data['selected'])) {
            $revision = (int)$data['selected']->revision;
        }
        return [
            'company' => $data['company'],
            'project' => $data['selected'],
            'revision' => $revision,
            'live' => !empty($data['live']),
            'poster' => $data['poster'],
            'layout' => self::layout($data['poster'], $revision),
            'contract' => self::contract(),
        ];
    }

    /**
     * Layout the poster once. SVG and Draw.io consume these coordinates.
     * Coordinates are visual units, not persisted business data.
     */
    public static function layout(array $poster, int $revision = 0): array {
        $width = self::WIDTH;
        $x = self::MARGIN;
        $contentw = $width - (2 * self::MARGIN);
        $y = 28;
        $nodes = [];
        $strips = [];

        $strips[] = [
            'kind' => 'brand', 'x' => $x, 'y' => $y, 'w' => $contentw, 'h' => 72,
            'title' => 'BMS', 'text' => (string)($poster['name'] ?? ''), 'fill' => '#FFFFFF',
        ];
        $y += 82;

        $addmain = static function(array $items, string $fallbacktitle, int $baseheight = 108) use (&$nodes, &$strips, &$y, $x, $contentw, $revision): void {
            if (!$items) {
                return;
            }
            $count = count($items);
            $gap = self::GAP;
            $maxw = min(760, ($contentw - (($count - 1) * $gap)) / max(1, $count));
            $roww = ($count * $maxw) + (($count - 1) * $gap);
            $startx = $x + (($contentw - $roww) / 2);
            $rowh = $baseheight;
            foreach ($items as $item) {
                $rowh = max($rowh, self::node_height($item, 54, true));
            }
            foreach ($items as $i => $item) {
                $nodes[] = self::node_box($item, $startx + ($i * ($maxw + $gap)), $y, $maxw, $rowh, $revision, true);
            }
            $y += $rowh + 12;
        };

        $addband = static function(string $label, array $main, array $children, string $childtier,
                int $maxcols = 7, bool $showresult = true) use (&$nodes, &$strips, &$y, $x, $contentw, $revision): void {
            if (!$main && !$children) {
                return;
            }
            $bandtitle = $label;
            if ($main) {
                $bandtitle = (string)$main[0]['name'];
            }
            $strips[] = [
                'kind' => 'band', 'x' => $x, 'y' => $y, 'w' => $contentw, 'h' => 56,
                'title' => $bandtitle, 'text' => '', 'fill' => self::GOLD,
            ];
            foreach ($main as $mainitem) {
                $nodes[] = self::node_box($mainitem, $x, $y, $contentw, 56, $revision, true, true);
            }
            $y += 62;

            if ($children) {
                $count = count($children);
                $cols = max(1, min($maxcols, $count));
                $gap = self::GAP;
                $cellw = ($contentw - (($cols - 1) * $gap)) / $cols;
                $rows = (int)ceil($count / $cols);
                for ($row = 0; $row < $rows; $row++) {
                    $slice = array_slice($children, $row * $cols, $cols);
                    $rowh = 126;
                    foreach ($slice as $item) {
                        $rowh = max($rowh, self::node_height($item, 44, false));
                    }
                    foreach ($slice as $col => $item) {
                        $nodes[] = self::node_box($item, $x + ($col * ($cellw + $gap)), $y,
                            $cellw, $rowh, $revision, false);
                    }
                    $y += $rowh + $gap;
                }
            }

            if ($showresult && $main) {
                $products = [];
                foreach ($main as $item) {
                    $product = trim((string)($item['product'] ?? ''));
                    if ($product !== '') {
                        $products[] = $product;
                    }
                }
                if ($products) {
                    $strips[] = [
                        'kind' => 'result', 'x' => $x, 'y' => $y, 'w' => $contentw, 'h' => 46,
                        'title' => get_string('ofsdesigner_result', 'local_kbacompany'),
                        'text' => implode(' · ', $products), 'fill' => self::GOLD_LIGHT,
                    ];
                    $y += 54;
                }
            }
            $y += 4;
        };

        $addmain($poster['investment'] ?? [], get_string('ofsdesigner_tier_investment', 'local_kbacompany'), 112);
        $addband(get_string('ofsdesigner_tier_strategic_office', 'local_kbacompany'),
            $poster['strategic_office'] ?? [], $poster['strategic_functions'] ?? [], 'strategic_function', 7, true);
        $addband(get_string('ofsdesigner_tier_management', 'local_kbacompany'),
            $poster['management'] ?? [], $poster['management_units'] ?? [], 'management_unit', 5, true);

        $executive = $poster['executive'] ?? [];
        if ($executive) {
            $strips[] = [
                'kind' => 'band', 'x' => $x, 'y' => $y, 'w' => $contentw, 'h' => 56,
                'title' => (string)$executive[0]['name'], 'text' => '', 'fill' => self::GOLD,
            ];
            foreach ($executive as $item) {
                $nodes[] = self::node_box($item, $x, $y, $contentw, 56, $revision, true, true);
            }
            $y += 66;
        }

        $departments = $poster['departments'] ?? [];
        if ($departments) {
            $count = count($departments);
            $cols = max(1, min(6, $count));
            $cellw = ($contentw - (($cols - 1) * self::GAP)) / $cols;
            $rows = (int)ceil($count / $cols);
            for ($row = 0; $row < $rows; $row++) {
                $slice = array_slice($departments, $row * $cols, $cols);
                $rowh = 190;
                foreach ($slice as $item) {
                    $rowh = max($rowh, self::node_height($item, 56, false, true));
                }
                foreach ($slice as $col => $item) {
                    $nodes[] = self::node_box($item, $x + ($col * ($cellw + self::GAP)), $y,
                        $cellw, $rowh, $revision, false, false, true);
                }
                $y += $rowh + self::GAP;
            }
        }

        $companyproduct = trim((string)($poster['companyproduct'] ?? ''));
        $strips[] = [
            'kind' => 'companyproduct', 'x' => $x, 'y' => $y, 'w' => $contentw, 'h' => 76,
            'title' => get_string('ofsdesigner_companyproduct', 'local_kbacompany'),
            'text' => $companyproduct !== '' ? $companyproduct : get_string('ofsdesigner_companyproduct_missing', 'local_kbacompany'),
            'fill' => '#EDF2F6',
        ];
        $y += 92;

        return [
            'width' => $width,
            'height' => max(900, $y),
            'nodes' => $nodes,
            'strips' => $strips,
            'revision' => $revision,
            'companyproduct' => $companyproduct,
        ];
    }

    private static function node_height(array $node, int $base, bool $compact = false, bool $department = false): int {
        if (!empty($node['band_only'])) {
            return $base;
        }
        $height = $base;
        $height += min(2, count($node['positions'] ?? [])) * 16;
        $functionlimit = $department ? 6 : ($compact ? 3 : 5);
        $height += min($functionlimit, count($node['functions'] ?? [])) * 18;
        $product = trim((string)($node['product'] ?? ''));
        if ($product !== '') {
            $height += 44;
        }
        return min(270, max($base, $height));
    }

    private static function node_box(array $node, float $x, float $y, float $w, float $h,
            int $revision, bool $compact = false, bool $bandonly = false, bool $department = false): array {
        return [
            'kind' => 'node',
            'uuid' => (string)($node['uuid'] ?? ''),
            'sourceuuid' => (string)($node['sourceuuid'] ?? ''),
            'code' => (string)($node['code'] ?? ''),
            'name' => (string)($node['name'] ?? ''),
            'tier' => (string)($node['visualtier'] ?? ''),
            'layout_parent' => (string)($node['layout_parent'] ?? ''),
            'product' => (string)($node['product'] ?? ''),
            'positions' => array_values($node['positions'] ?? []),
            'functions' => array_values($node['functions'] ?? []),
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'revision' => $revision,
            'compact' => $compact,
            'band_only' => $bandonly,
            'department' => $department,
        ];
    }

    /** Render the layout to standalone SVG. */
    public static function svg(array $layout, string $title = ''): string {
        $w = (int)$layout['width'];
        $h = (int)$layout['height'];
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' . "\n";
        $out .= '<rect width="100%" height="100%" fill="#FFFFFF"/>' . "\n";
        $out .= '<style>text{font-family:DejaVu Sans,Arial,sans-serif;fill:' . self::INK . ';}.small{font-size:13px}.body{font-size:14px}.head{font-size:18px;font-weight:700}.band{font-size:24px;font-weight:700}.brand{font-size:38px;font-weight:800}.muted{fill:' . self::MUTED . '}</style>' . "\n";

        foreach ($layout['strips'] as $strip) {
            $out .= self::svg_strip($strip);
        }
        foreach ($layout['nodes'] as $node) {
            $out .= self::svg_node($node);
        }
        $out .= '</svg>';
        return $out;
    }

    /**
     * Prepare SVG specifically for Moodle/TCPDF ImageSVG.
     *
     * TCPDF resolves SVG fonts by its internal font identifier, not by the
     * desktop family name. Using DejaVu Sans/Arial may silently fall back to
     * a core Latin font and turn Cyrillic into question marks. Force the
     * bundled Unicode font identifier on every text node for deterministic
     * Russian export.
     */
    public static function tcpdf_svg(array $layout, string $title = ''): string {
        $svg = self::svg($layout, $title);
        // Keep browser SVG standards-friendly while using TCPDF's canonical font id here.
        $svg = str_replace('font-family:DejaVu Sans,Arial,sans-serif', 'font-family:dejavusans', $svg);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $svg;
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="text" or local-name()="tspan"]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement) {
                    $node->setAttribute('font-family', 'dejavusans');
                }
            }
        }
        return $dom->saveXML();
    }

    private static function svg_strip(array $strip): string {
        $x = self::num($strip['x']); $y = self::num($strip['y']);
        $w = self::num($strip['w']); $h = self::num($strip['h']);
        $fill = self::xml((string)$strip['fill']);
        $kind = (string)$strip['kind'];
        $out = '<g><rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="4" fill="' . $fill . '" stroke="' . self::BORDER . '"/>';
        if ($kind === 'brand') {
            $out .= self::svg_text((float)$strip['x'] + 18, (float)$strip['y'] + 42, [(string)$strip['title']], 'brand', 'start');
            $name = trim((string)$strip['text']);
            if ($name !== '') {
                $out .= self::svg_text((float)$strip['x'] + 140, (float)$strip['y'] + 31,
                    self::wrap($name, 64, 2), 'head', 'start');
            }
        } else if ($kind === 'companyproduct') {
            $out .= self::svg_text((float)$strip['x'] + ((float)$strip['w'] / 2), (float)$strip['y'] + 28,
                [(string)$strip['title']], 'head', 'middle');
            $out .= self::svg_text((float)$strip['x'] + ((float)$strip['w'] / 2), (float)$strip['y'] + 51,
                self::wrap((string)$strip['text'], 145, 2), 'body', 'middle');
        } else if ($kind === 'result') {
            $out .= self::svg_text((float)$strip['x'] + ((float)$strip['w'] / 2), (float)$strip['y'] + 18,
                [(string)$strip['title']], 'small', 'middle');
            $out .= self::svg_text((float)$strip['x'] + ((float)$strip['w'] / 2), (float)$strip['y'] + 36,
                self::wrap((string)$strip['text'], 150, 1), 'small', 'middle');
        } else {
            $out .= self::svg_text((float)$strip['x'] + ((float)$strip['w'] / 2), (float)$strip['y'] + 36,
                [(string)$strip['title']], 'band', 'middle');
        }
        return $out . '</g>' . "\n";
    }

    private static function svg_node(array $node): string {
        $x = (float)$node['x']; $y = (float)$node['y']; $w = (float)$node['w']; $h = (float)$node['h'];
        if (!empty($node['band_only'])) {
            return self::svg_text($x + ($w / 2), $y + 36, self::wrap((string)$node['name'], 80, 2), 'band', 'middle');
        }
        $out = '<g data-bms-type="orgunit" data-bms-uuid="' . self::xml((string)$node['uuid']) . '" data-bms-revision="' . (int)$node['revision'] . '">';
        $isinvestment = ((string)$node['tier'] === 'investment');
        $bodyfill = $isinvestment ? self::GOLD_LIGHT : self::PANEL;
        $headfill = $isinvestment ? self::GOLD : self::PANEL_HEAD;
        $out .= '<rect x="' . self::num($x) . '" y="' . self::num($y) . '" width="' . self::num($w) . '" height="' . self::num($h) . '" rx="4" fill="' . $bodyfill . '" stroke="' . self::BORDER . '"/>';
        $headheight = 36;
        $out .= '<rect x="' . self::num($x) . '" y="' . self::num($y) . '" width="' . self::num($w) . '" height="' . $headheight . '" rx="4" fill="' . $headfill . '"/>';
        $out .= self::svg_text($x + ($w / 2), $y + 23, self::wrap((string)$node['name'], max(18, (int)($w / 11)), 2), 'small', 'middle');
        $cursor = $y + 52;
        $maxchars = max(16, (int)($w / 9));
        $positions = array_slice($node['positions'], 0, 2);
        foreach ($positions as $position) {
            $out .= self::svg_text($x + 10, $cursor, self::wrap('• ' . (string)$position['name'], $maxchars, 1), 'small', 'start');
            $cursor += 16;
        }
        $limit = !empty($node['department']) ? 6 : (!empty($node['compact']) ? 3 : 5);
        foreach (array_slice($node['functions'], 0, $limit) as $function) {
            if ($cursor > ($y + $h - 48)) {
                break;
            }
            $out .= self::svg_text($x + 10, $cursor, self::wrap('• ' . (string)$function['name'], $maxchars, 1), 'small', 'start');
            $cursor += 17;
        }
        $product = trim((string)$node['product']);
        if ($product !== '') {
            $ph = 48;
            $py = $y + $h - $ph;
            $out .= '<rect x="' . self::num($x) . '" y="' . self::num($py) . '" width="' . self::num($w) . '" height="' . $ph . '" fill="' . self::GOLD_LIGHT . '" stroke="' . self::BORDER . '"/>';
            $out .= self::svg_text($x + ($w / 2), $py + 13, [get_string('ofsdesigner_result', 'local_kbacompany')], 'small', 'middle');
            $out .= self::svg_text($x + ($w / 2), $py + 28, self::wrap($product, max(24, (int)($w / 7)), 2), 'small', 'middle');
        }
        return $out . '</g>' . "\n";
    }

    private static function svg_text(float $x, float $y, array $lines, string $class, string $anchor): string {
        $out = '<text x="' . self::num($x) . '" y="' . self::num($y) . '" class="' . self::xml($class) . '" text-anchor="' . self::xml($anchor) . '">';
        foreach ($lines as $i => $line) {
            $dy = $i === 0 ? 0 : 16;
            $out .= '<tspan x="' . self::num($x) . '" dy="' . $dy . '">' . self::xml($line) . '</tspan>';
        }
        return $out . '</text>';
    }

    /** Export the same node coordinates as an editable diagrams.net / Draw.io document. */
    public static function drawio(array $layout, string $diagramname = 'BMS OFS'): string {
        $cells = [];
        $cells[] = '<mxCell id="0"/>';
        $cells[] = '<mxCell id="1" parent="0"/>';
        $idmap = [];
        foreach ($layout['nodes'] as $index => $node) {
            $uuid = (string)$node['uuid'];
            if ($uuid === '') {
                continue;
            }
            $cellid = 'bms_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uuid);
            $idmap[$uuid] = $cellid;
            $label = self::drawio_label($node);
            if (!empty($node['band_only'])) {
                $style = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#E8B632;strokeColor=#C89A1D;fontStyle=1;fontSize=18;align=center;verticalAlign=middle;';
            } else if ((string)$node['tier'] === 'investment') {
                $style = 'rounded=1;whiteSpace=wrap;html=1;fillColor=#FFF2BF;strokeColor=#C89A1D;fontStyle=1;fontSize=12;align=left;verticalAlign=top;spacing=8;';
            } else {
                $style = 'rounded=1;whiteSpace=wrap;html=1;fillColor=#F5F7F8;strokeColor=#CDD5DB;fontSize=12;align=left;verticalAlign=top;spacing=8;';
            }
            $cells[] = '<mxCell id="' . self::xml($cellid) . '" value="' . self::xml($label) . '" style="' . self::xml($style) . '" vertex="1" parent="1" bmsType="orgunit" bmsUuid="' . self::xml($uuid) . '" bmsSourceUuid="' . self::xml((string)$node['sourceuuid']) . '" bmsTier="' . self::xml((string)$node['tier']) . '" bmsRevision="' . (int)$node['revision'] . '"><mxGeometry x="' . self::num($node['x']) . '" y="' . self::num($node['y']) . '" width="' . self::num($node['w']) . '" height="' . self::num($node['h']) . '" as="geometry"/></mxCell>';
        }

        $edgeindex = 0;
        foreach ($layout['nodes'] as $node) {
            $uuid = (string)$node['uuid'];
            $parent = (string)$node['layout_parent'];
            if ($uuid === '' || $parent === '' || !isset($idmap[$uuid], $idmap[$parent])) {
                continue;
            }
            $edgeindex++;
            $cells[] = '<mxCell id="edge_' . $edgeindex . '" style="edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;endArrow=none;strokeColor=#9AA5AD;" edge="1" parent="1" source="' . self::xml($idmap[$parent]) . '" target="' . self::xml($idmap[$uuid]) . '"><mxGeometry relative="1" as="geometry"/></mxCell>';
        }

        $product = trim((string)$layout['companyproduct']);
        if ($product !== '') {
            $strips = array_values(array_filter($layout['strips'], static function(array $strip): bool {
                return $strip['kind'] === 'companyproduct';
            }));
            if ($strips) {
                $strip = $strips[0];
                $value = '<b>' . self::html(get_string('ofsdesigner_companyproduct', 'local_kbacompany')) . '</b><br>' . self::html($product);
                $cells[] = '<mxCell id="bms_company_product" value="' . self::xml($value) . '" style="rounded=1;whiteSpace=wrap;html=1;fillColor=#EDF2F6;strokeColor=#CDD5DB;fontStyle=1;fontSize=14;align=center;verticalAlign=middle;" vertex="1" parent="1" bmsType="company_product" bmsRevision="' . (int)$layout['revision'] . '"><mxGeometry x="' . self::num($strip['x']) . '" y="' . self::num($strip['y']) . '" width="' . self::num($strip['w']) . '" height="' . self::num($strip['h']) . '" as="geometry"/></mxCell>';
            }
        }

        $model = '<mxGraphModel dx="1422" dy="794" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . (int)$layout['width'] . '" pageHeight="' . (int)$layout['height'] . '" math="0" shadow="0"><root>' . implode('', $cells) . '</root></mxGraphModel>';
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<mxfile host="app.diagrams.net" modified="' . gmdate('c') . '" agent="BMS" version="24.7.17" type="device"><diagram id="bms-ofs" name="' . self::xml($diagramname) . '">' . $model . '</diagram></mxfile>';
    }

    private static function drawio_label(array $node): string {
        if (!empty($node['band_only'])) {
            return '<b>' . self::html((string)$node['name']) . '</b>';
        }
        $parts = ['<b>' . self::html((string)$node['name']) . '</b>'];
        $positions = array_slice($node['positions'], 0, 3);
        foreach ($positions as $position) {
            $parts[] = self::html('• ' . (string)$position['name']);
        }
        $limit = !empty($node['department']) ? 6 : 4;
        foreach (array_slice($node['functions'], 0, $limit) as $function) {
            $parts[] = self::html('• ' . (string)$function['name']);
        }
        if (trim((string)$node['product']) !== '') {
            $parts[] = '<b>' . self::html(get_string('ofsdesigner_result', 'local_kbacompany')) . '</b> ' . self::html((string)$node['product']);
        }
        return implode('<br>', $parts);
    }

    /** Paper size for the vector PDF endpoint, in millimetres. */
    public static function pdf_page(string $paper, array $layout): array {
        $paper = strtoupper($paper);
        $sizes = [
            'A0' => [1189.0, 841.0],
            'A1' => [841.0, 594.0],
            'A2' => [594.0, 420.0],
            'A3' => [420.0, 297.0],
            'A4' => [297.0, 210.0],
        ];
        if (isset($sizes[$paper])) {
            return ['paper' => $paper, 'width' => $sizes[$paper][0], 'height' => $sizes[$paper][1], 'custom' => false];
        }
        $scale = 0.32;
        $width = (float)$layout['width'] * $scale;
        $height = (float)$layout['height'] * $scale;
        $maxscale = min(1.0, 1189.0 / max(1.0, $width), 841.0 / max(1.0, $height));
        $width *= $maxscale;
        $height *= $maxscale;
        if ($width < 297.0 && $height < 210.0) {
            $up = min(297.0 / max(1.0, $width), 210.0 / max(1.0, $height));
            $width *= $up;
            $height *= $up;
        }
        return ['paper' => 'AUTO', 'width' => round($width, 1), 'height' => round($height, 1), 'custom' => true];
    }

    private static function wrap(string $text, int $maxchars, int $maxlines): array {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [''];
        }
        $words = preg_split('/\s+/u', $text);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (\core_text::strlen($candidate) <= $maxchars || $line === '') {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
            if (count($lines) >= $maxlines) {
                break;
            }
        }
        if (count($lines) < $maxlines && $line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > $maxlines) {
            $lines = array_slice($lines, 0, $maxlines);
        }
        if ($words && count($lines) === $maxlines) {
            $joined = implode(' ', $lines);
            if (\core_text::strlen($joined) < \core_text::strlen($text)) {
                $last = $lines[$maxlines - 1];
                $last = \core_text::substr($last, 0, max(1, $maxchars - 1));
                $lines[$maxlines - 1] = rtrim($last) . '…';
            }
        }
        return $lines ?: [''];
    }

    private static function num($value): string {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    private static function xml(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
