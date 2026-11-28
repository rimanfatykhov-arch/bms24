<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * Presentation of the FIN-W2 Finance workspace. Render-only: it receives the authorized model from
 * finance_workspace_service and never reads business data or decides access. All user text is escaped.
 * Amounts are always printed with their own currency; a missing fact is printed as "no confirmed fact", never 0.
 */
final class finance_workspace_ui_service {
    private const ACTIVE = ['overview', 'model', 'centers', 'statements', 'budgets', 'planfact', 'resources'];

    private static function str(string $key, $a = null): string {
        return get_string('financews_' . $key, 'local_kbacompany', $a);
    }

    private static function money(float $value, string $currency): string {
        return format_float($value, 2, true, false) . ' ' . $currency;
    }

    private static function date(string $iso): string {
        return $iso === '' ? '—' : $iso;
    }

    /** Quick menu inside the workspace. Planned sections are plain text with a "planned" mark, never links. */
    public static function section_nav(string $companyuuid, string $active): string {
        $routes = finance_workspace_service::routes($companyuuid);
        $targets = ['overview' => 'overview', 'model' => 'model', 'centers' => 'centers', 'statements' => 'statements',
            'budgets' => 'budgets', 'planfact' => 'budgets', 'resources' => 'resources'];
        $items = '';
        foreach (self::ACTIVE as $key) {
            $current = $key === $active;
            $items .= html_writer::tag('li', html_writer::link($routes[$targets[$key]], self::str('section_' . $key), [
                'class' => 'bms24-wsnav-link' . ($current ? ' is-active' : ''),
                'aria-current' => $current ? 'page' : null,
            ]));
        }
        foreach (finance_workspace_service::PLANNED_SECTIONS as $key) {
            $items .= html_writer::tag('li', html_writer::tag('span',
                s(self::str('section_' . $key)) . ' ' .
                html_writer::tag('small', s(self::str('planned')), ['class' => 'bms24-wsnav-plannedmark']), [
                    'class' => 'bms24-wsnav-planned', 'aria-disabled' => 'true',
                    'title' => self::str('planned_hint'),
                    'data-finance-planned' => $key,
                ]));
        }
        return html_writer::tag('nav', html_writer::tag('ul', $items, ['class' => 'bms24-wsnav-list']), [
            'class' => 'bms24-wsnav bms-context-quicknav', 'aria-label' => self::str('nav_label'),
            'data-finance-nav' => '1',
        ]);
    }

    public static function render(array $model): string {
        $uuid = $model['company']['uuid'];
        $routes = finance_workspace_service::routes($uuid);
        $c = $model['counts'];
        $out = html_writer::start_div('bms24-ws bms-finance', ['data-finance-workspace' => '1']);
        $out .= html_writer::start_tag('header', ['class' => 'bms24-ws-header']);
        $out .= html_writer::tag('h1', s(self::str('title')));
        $out .= html_writer::tag('p', s(self::str('intro', format_string($model['company']['name']))),
            ['class' => 'bms24-ws-lead']);
        $out .= html_writer::end_tag('header');
        $out .= self::section_nav($uuid, 'overview');

        // Counters: authorized totals of the owner registries, each linked to the owner page.
        $cards = [
            ['scenarios', (int)$c['scenarios']['total'], $routes['scenarios']],
            ['centers', (int)$c['centers']['total'], $routes['centers']],
            ['statements', (int)$c['statements']['total'], $routes['statements']],
            ['budgetsapproved', (int)($c['budgets']['approved'] ?? 0), $routes['budgets']],
            ['budgetssubmitted', (int)($c['budgets']['submitted'] ?? 0), $routes['budgets']],
            ['gatesready', (int)($c['gates']['ready'] ?? 0), $routes['resources']],
            ['gatesblocked', (int)($c['gates']['blocked'] ?? 0), $routes['resources']],
        ];
        $stats = '';
        foreach ($cards as [$key, $value, $url]) {
            $body = html_writer::tag('span', s(self::str('count_' . $key)), ['class' => 'bms24-ws-statlabel']) .
                html_writer::tag('span', (string)$value, ['class' => 'bms24-ws-statvalue']) .
                html_writer::tag('span', s(self::str('count_' . $key . '_hint')), ['class' => 'bms24-ws-statcaption']);
            $warning = in_array($key, ['gatesblocked', 'budgetssubmitted'], true) && $value > 0;
            $stats .= html_writer::tag('li', html_writer::link($url, $body, [
                'class' => 'bms24-ws-stat' . ($warning ? ' is-warning' : ''),
                'data-finance-count' => $key, 'data-value' => $value]));
        }
        $out .= html_writer::tag('ul', $stats, ['class' => 'bms24-ws-stats', 'aria-label' => self::str('counts_label')]);

        $out .= html_writer::start_div('bms24-ws-grid');
        $out .= self::attention_card($model, $routes);
        $out .= self::actions_card($routes);
        $out .= self::plan_card($model['plan_by_currency'], $routes['budgets']);
        $out .= self::execution_card($model['execution'], $routes['budgets']);
        $out .= self::statements_card($model['plans'], $model['plans_total'], $routes['statements']);
        $out .= self::gates_card($model['gates'], $model['gates_total'], $routes['resources']);
        $out .= html_writer::end_div();

        $out .= html_writer::tag('p', s(self::str('scope_note')), ['class' => 'bms24-ws-note']);
        $out .= html_writer::end_div();
        return $out;
    }

    private static function card(string $key, string $body, ?\moodle_url $more = null, int $total = 0,
            int $shown = 0): string {
        $head = html_writer::tag('h2', s(self::str('card_' . $key)), ['id' => 'finance-card-' . $key]);
        $foot = $more && $total > $shown
            ? html_writer::tag('p', html_writer::link($more, s(self::str('showall', $total))), ['class' => 'bms24-ws-more'])
            : '';
        return html_writer::tag('section', $head . $body . $foot, ['class' => 'bms24-ws-card',
            'aria-labelledby' => 'finance-card-' . $key, 'data-finance-card' => $key]);
    }

    private static function empty(string $key): string {
        return html_writer::tag('p', s(self::str('empty_' . $key)), ['class' => 'bms24-ws-empty', 'data-finance-empty' => $key]);
    }

    private static function attention_card(array $model, array $routes): string {
        if (!$model['attention']) {
            return self::card('attention', self::empty('attention'));
        }
        $targets = ['budgetsubmitted' => $routes['budgets'], 'budgetnofact' => $routes['budgets'],
            'gateblocked' => $routes['resources'], 'gatepending' => $routes['resources'],
            'statementdraft' => $routes['statements']];
        $items = '';
        foreach ($model['attention'] as $item) {
            $items .= html_writer::tag('li', html_writer::link($targets[$item['key']],
                s(self::str('attention_' . $item['key'], $item['count']))), ['data-finance-attention' => $item['key']]);
        }
        return self::card('attention', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']));
    }

    private static function actions_card(array $routes): string {
        $links = [];
        foreach (['model' => 'action_model', 'statements' => 'action_statements', 'budgets' => 'action_budgets',
                'resources' => 'action_resources'] as $route => $label) {
            $links[] = html_writer::link($routes[$route], s(self::str($label)), ['class' => 'bms24-ws-action']);
        }
        return self::card('actions', html_writer::div(implode('', $links), 'bms24-ws-actions'));
    }

    /** Planned totals of approved budgets, one line per currency (never one cross-currency total). */
    private static function plan_card(array $bycurrency, \moodle_url $more): string {
        if (!$bycurrency) {
            return self::card('plan', self::empty('plan'));
        }
        $items = '';
        foreach ($bycurrency as $currency => $amount) {
            $items .= html_writer::tag('li', html_writer::tag('strong', s(self::money((float)$amount, (string)$currency))) .
                html_writer::tag('span', s(self::str('plan_caption')), ['class' => 'bms24-ws-meta']),
                ['data-finance-plan' => (string)$currency, 'data-value' => (string)$amount]);
        }
        return self::card('plan', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more);
    }

    private static function execution_card(array $rows, \moodle_url $more): string {
        if (!$rows) {
            return self::card('execution', self::empty('execution'));
        }
        $items = '';
        foreach ($rows as $row) {
            $title = s($row['code'] . ' — ' . $row['name']);
            $meta = [self::str('revision', $row['revision']), $row['periodstart'] . ' — ' . $row['periodend'],
                self::str('planned_amount', self::money($row['plan'], $row['currency']))];
            if ($row['fact'] === null) {
                $fact = html_writer::tag('span', s(self::str('nofact')), ['class' => 'bms24-ws-badge is-nodata',
                    'data-finance-fact' => 'none']);
            } else {
                $sources = array_map(static function(string $kind): string {
                    return get_string('financews_source_' . ($kind === 'external' ? 'external' : 'manual'), 'local_kbacompany');
                }, $row['factsources']);
                $fact = html_writer::tag('span', s(self::str('fact_amount', self::money($row['fact'], $row['currency']))),
                        ['class' => 'bms24-ws-badge is-fact', 'data-finance-fact' => 'confirmed']) . ' ' .
                    html_writer::tag('span', s(self::str('variance', self::money($row['variance'], $row['currency']))),
                        ['class' => 'bms24-ws-meta']);
                $meta[] = self::str('factcoverage', (object)['with' => $row['lineswithfact'], 'total' => $row['lines']]);
                $meta[] = self::str('asof', self::date($row['asof']));
                $meta[] = self::str('source', implode(', ', $sources));
            }
            $items .= html_writer::tag('li', html_writer::tag('span', $title, ['class' => 'bms24-ws-title']) .
                html_writer::tag('div', $fact, ['class' => 'bms24-ws-factline']) .
                html_writer::tag('span', s(implode(' · ', $meta)), ['class' => 'bms24-ws-meta']),
                ['data-finance-budget' => $row['uuid']]);
        }
        return self::card('execution', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more);
    }

    private static function statements_card(array $rows, int $total, \moodle_url $more): string {
        if (!$rows) {
            return self::card('statements', self::empty('statements'));
        }
        $items = '';
        foreach ($rows as $row) {
            $meta = [get_string('financialstatement_type_' . $row['type'], 'local_kbacompany'),
                self::str('status_' . $row['status']), self::str('revision', $row['revision']),
                $row['periodstart'] . ' — ' . $row['periodend'], $row['currency'],
                self::str('updated', userdate((int)$row['timemodified'], get_string('strftimedatefullshort', 'langconfig')))];
            if ($row['source'] !== '') {
                $meta[] = self::str('source', $row['source']);
            }
            $figure = '';
            if ($row['keyfigure']) {
                $figure = html_writer::tag('span', s(self::str('keyfigure_' . $row['keyfigure']['key'],
                    self::money((float)$row['keyfigure']['value'], $row['currency']))), ['class' => 'bms24-ws-badge is-plan']);
            }
            $items .= html_writer::tag('li', html_writer::tag('span', s($row['code'] . ' — ' . $row['name']),
                    ['class' => 'bms24-ws-title']) . $figure . html_writer::tag('span', s(implode(' · ', $meta)),
                    ['class' => 'bms24-ws-meta']), ['data-finance-statement' => $row['type']]);
        }
        return self::card('statements', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more, $total,
            count($rows));
    }

    private static function gates_card(array $rows, int $total, \moodle_url $more): string {
        if (!$rows) {
            return self::card('gates', self::empty('gates'));
        }
        $items = '';
        foreach ($rows as $row) {
            $meta = [self::str('status_' . $row['status']), self::str('revision', $row['revision']),
                self::str('coverage', (object)['ready' => $row['required_ready'], 'pending' => $row['required_pending'],
                    'blocked' => $row['required_blocked']])];
            if ($row['missing'] > 0) {
                $meta[] = self::str('missingkinds', $row['missing']);
            }
            $items .= html_writer::tag('li', html_writer::tag('span', s($row['code'] . ' — ' . $row['name']),
                ['class' => 'bms24-ws-title']) . html_writer::tag('span', s(implode(' · ', $meta)), ['class' => 'bms24-ws-meta']),
                ['data-finance-gate' => $row['status']]);
        }
        return self::card('gates', html_writer::tag('ul', $items, ['class' => 'bms24-ws-list']), $more, $total, count($rows));
    }
}
