<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation model for an already authorised R1 snapshot. */
final class role_readiness_view {
    public const PAGE_SIZE = 50;
    private const STATUSES = ['basic_links_present', 'needs_description'];
    private const GAPS = ['purpose_missing', 'published_functions_missing', 'published_role_dna_missing'];

    private static function options(array $values, string $selected): array {
        if ($selected !== '' && !in_array($selected, $values, true)) {
            throw new \invalid_parameter_exception('Unknown readiness filter');
        }
        $options = [];
        foreach (array_merge([''], $values) as $value) {
            $options[] = ['value' => $value, 'selected' => $selected === $value,
                'label' => position_ui_service::tr($value === '' ? 'pdu_all' : 'readiness_' . $value)];
        }
        return $options;
    }

    public static function model(array $snapshot, string $status, string $gap, int $page): array {
        $uuid = $snapshot['company_uuid'];
        $statuses = self::options(self::STATUSES, $status);
        $gaps = self::options(self::GAPS, $gap);
        $matches = array_filter($snapshot['positions'], static function(array $row) use ($status, $gap): bool {
            return ($status === '' || $status === $row['design_status']) &&
                ($gap === '' || in_array($gap, $row['gaps'], true));
        });
        $total = count($matches);
        $page = min(max(0, $page), max(0, (int)ceil($total / self::PAGE_SIZE) - 1));
        $rows = [];
        foreach (array_slice($matches, $page * self::PAGE_SIZE, self::PAGE_SIZE) as $row) {
            $labels = [];
            foreach ($row['gaps'] as $reason) {
                $labels[] = position_ui_service::tr('readiness_' . $reason);
            }
            $row['details'] = implode(', ', $labels);
            $row['statuslabel'] = position_ui_service::tr('readiness_' . $row['design_status']);
            $row['url'] = position_ui_service::url($uuid, ['view' => 'detail', 'position' => $row['uuid']])->out(false);
            $rows[] = $row;
        }
        return ['uuid' => $uuid, 'statuses' => $statuses, 'gaps' => $gaps, 'rows' => $rows,
            'page' => $page, 'total' => $total,
            'formurl' => position_ui_service::url($uuid, [], 'role_readiness')->out_omit_querystring(),
            'directoryurl' => position_ui_service::url($uuid)->out(false)];
    }
}
