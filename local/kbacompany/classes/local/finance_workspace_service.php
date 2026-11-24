<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * FIN-W2 "Finance" workspace: a read-only overview over the EXISTING financial owners
 * (financial_model_service, financial_statement_service, budgeting_service, resource_gate_service).
 *
 * The workspace owns no data, no formulas and no lifecycle. Access is exactly the existing financial
 * gate (financial_model_service::can_view = permission_service::can_manage_management); nothing is
 * computed before that check, and every owner context is read for the same company and actor.
 * Plan, forecast and confirmed fact stay separate: a budget without a recorded fact shows "no fact",
 * never zero; amounts are grouped by currency and never added across currencies.
 * Weekly plans, spend requests, reserves, payments, treasury and new tables are FIN-W3+.
 * The workspace is behind the site switch local_kbacompany/finance_workspace_enabled (default off).
 */
final class finance_workspace_service {
    public const CONTRACT_VERSION = '0.1.0';
    public const SETTING = 'finance_workspace_enabled';
    public const LIST_LIMIT = 10;
    /** Target sections of BMS-ARC-FIN-001 that have no canonical object yet (shown as planned, never as links). */
    public const PLANNED_SECTIONS = ['weekly', 'requests', 'council', 'calendar', 'treasury', 'reporting'];

    public static function enabled(): bool {
        return (bool)get_config('local_kbacompany', self::SETTING);
    }

    public static function require_enabled(): void {
        if (!self::enabled()) {
            throw new \moodle_exception('financews_disabled', 'local_kbacompany');
        }
    }

    public static function url(string $companyuuid): \moodle_url {
        return new \moodle_url('/local/kbacompany/finance.php', ['uuid' => $companyuuid]);
    }

    /** The same gate as every financial page; no financial role is invented and manageorganization is not widened. */
    public static function can_view(string $companyuuid, int $actor): bool {
        return financial_model_service::can_view($companyuuid, $actor);
    }

    public static function routes(string $companyuuid): array {
        $u = static function(string $path, array $params = []) use ($companyuuid): \moodle_url {
            return new \moodle_url('/local/kbacompany/' . $path, ['uuid' => $companyuuid] + $params);
        };
        return [
            'overview' => self::url($companyuuid),
            'model' => $u('financial_model.php'),
            'scenarios' => $u('financial_model.php', ['tab' => 'scenarios']),
            'centers' => $u('financial_model.php', ['tab' => 'centers']),
            'statements' => $u('financial_statements.php'),
            'budgets' => $u('budgeting.php'),
            'resources' => $u('resource_gate.php'),
        ];
    }

    public static function overview(string $companyuuid, int $actor): array {
        self::require_enabled();
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actor);
        if (!self::can_view($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $model = financial_model_service::context($companyuuid, $actor);
        $statements = financial_statement_service::context($companyuuid, $actor);
        $budgeting = budgeting_service::context($companyuuid, $actor);
        $gates = resource_gate_service::context($companyuuid, $actor);

        $counts = [
            'scenarios' => self::by_status($model['scenarios']),
            'centers' => self::by_status($model['centers']),
            'statements' => self::by_status($statements['statements']),
            'budgets' => self::by_status($budgeting['budgets']),
            'gates' => self::by_status($gates['gates']),
        ];
        $plans = [];
        foreach ($statements['statements'] as $s) {
            $plans[] = [
                'uuid' => $s['uuid'], 'code' => $s['code'], 'name' => $s['name'], 'type' => $s['statementtype'],
                'status' => $s['status'], 'revision' => $s['revision'], 'currency' => $s['currency'],
                'periodstart' => $s['periodstart'], 'periodend' => $s['periodend'],
                'source' => $s['sourceinfo'], 'timemodified' => $s['timemodified'],
                'keyfigure' => self::key_figure($s['statementtype'], $s['summary']),
            ];
        }
        $execution = [];
        foreach ($budgeting['budgets'] as $b) {
            if ((int)$b['effectiveapprovedrevision'] <= 0) {
                continue;
            }
            $execution[] = self::execution_row($companyuuid, $actor, $b);
        }
        $gaterows = [];
        foreach ($gates['gates'] as $g) {
            $gaterows[] = ['uuid' => (string)$g['uuid'], 'code' => (string)$g['code'], 'name' => (string)$g['name'],
                'status' => (string)$g['status'], 'revision' => (int)$g['revision'],
                'required_ready' => (int)$g['coverage']['required_ready'],
                'required_pending' => (int)$g['coverage']['required_pending'],
                'required_blocked' => (int)$g['coverage']['required_blocked'],
                'missing' => count($g['coverage']['missing'])];
        }
        return [
            'company' => ['uuid' => $companyuuid, 'name' => (string)($company['name'] ?? '')],
            'counts' => $counts,
            'plans' => array_slice($plans, 0, self::LIST_LIMIT),
            'plans_total' => count($plans),
            'execution' => $execution,
            'plan_by_currency' => self::plan_by_currency($execution),
            'gates' => array_slice($gaterows, 0, self::LIST_LIMIT),
            'gates_total' => count($gaterows),
            'attention' => self::attention($counts, $execution, $gaterows),
            'planned' => self::PLANNED_SECTIONS,
            'contract' => self::contract(),
        ];
    }

    private static function by_status(array $rows): array {
        $out = ['total' => count($rows)];
        foreach ($rows as $row) {
            $status = (string)$row['status'];
            $out[$status] = ($out[$status] ?? 0) + 1;
        }
        return $out;
    }

    /** One planned (not actual) figure per statement type, taken from the owner's own calculation. */
    private static function key_figure(string $type, array $summary): array {
        $key = ['bdr' => 'netprofit', 'bdds' => 'closingcash', 'balance' => 'totalassets'][$type] ?? '';
        return $key === '' || !array_key_exists($key, $summary) ? [] : ['key' => $key, 'value' => (float)$summary[$key]];
    }

    /**
     * Plan-fact of the effective approved revision. Fact exists only where the owner recorded a fact snapshot;
     * lines without fact are excluded from the fact sum and the variance (missing data is not zero).
     */
    private static function execution_row(string $companyuuid, int $actor, array $b): array {
        $detail = budgeting_service::budget($companyuuid, $actor, (string)$b['uuid'], (int)$b['effectiveapprovedrevision']);
        $plan = 0.0; $fact = null; $planwithfact = 0.0; $withfact = 0; $asof = ''; $kinds = [];
        foreach ($detail['lines'] as $line) {
            $plan += (float)$line['planamount'];
            if ($line['actualamount'] === null) {
                continue;
            }
            $withfact++;
            $fact = ($fact ?? 0.0) + (float)$line['actualamount'];
            $planwithfact += (float)$line['planamount'];
            $asof = max($asof, (string)$line['asofdate']);
            $kinds[(string)$line['factsourcekind']] = true;
        }
        ksort($kinds);
        return [
            'uuid' => (string)$b['uuid'], 'code' => (string)$b['code'], 'name' => (string)$b['name'],
            'revision' => (int)$b['effectiveapprovedrevision'], 'currentstatus' => (string)$b['status'],
            'currency' => (string)$detail['budget']['currency'],
            'periodstart' => (string)$detail['budget']['periodstart'], 'periodend' => (string)$detail['budget']['periodend'],
            'lines' => count($detail['lines']), 'lineswithfact' => $withfact,
            'plan' => round($plan, 2), 'fact' => $fact === null ? null : round($fact, 2),
            'variance' => $fact === null ? null : round($fact - $planwithfact, 2),
            'asof' => $asof, 'factsources' => array_keys($kinds),
        ];
    }

    /** Plan totals per currency of approved budgets; currencies are never added together. */
    private static function plan_by_currency(array $execution): array {
        $out = [];
        foreach ($execution as $row) {
            $out[$row['currency']] = round(($out[$row['currency']] ?? 0.0) + $row['plan'], 2);
        }
        ksort($out);
        return $out;
    }

    private static function attention(array $counts, array $execution, array $gates): array {
        $out = [];
        $submitted = (int)($counts['budgets']['submitted'] ?? 0);
        if ($submitted > 0) { $out[] = ['key' => 'budgetsubmitted', 'count' => $submitted]; }
        $nofact = count(array_filter($execution, static function(array $r): bool { return $r['lineswithfact'] === 0; }));
        if ($nofact > 0) { $out[] = ['key' => 'budgetnofact', 'count' => $nofact]; }
        $blocked = count(array_filter($gates, static function(array $g): bool {
            return $g['status'] === 'blocked' || $g['required_blocked'] > 0;
        }));
        if ($blocked > 0) { $out[] = ['key' => 'gateblocked', 'count' => $blocked]; }
        $pending = count(array_filter($gates, static function(array $g): bool {
            return !in_array($g['status'], ['blocked', 'ready'], true) && $g['required_pending'] > 0;
        }));
        if ($pending > 0) { $out[] = ['key' => 'gatepending', 'count' => $pending]; }
        $drafts = (int)($counts['statements']['draft'] ?? 0);
        if ($drafts > 0) { $out[] = ['key' => 'statementdraft', 'count' => $drafts]; }
        return $out;
    }

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_finance_workspace',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'feature_switch' => 'local_kbacompany/' . self::SETTING,
            'owns_data' => false,
            'rules' => [
                'workspace_is_read_only_and_owns_no_records_or_formulas',
                'access_is_the_existing_financial_gate',
                'plan_forecast_and_confirmed_fact_are_separate',
                'missing_fact_is_not_zero',
                'amounts_are_never_added_across_currencies',
                'planned_sections_are_never_links',
                'switch_off_means_no_route_no_menu_item',
            ],
        ];
    }
}
