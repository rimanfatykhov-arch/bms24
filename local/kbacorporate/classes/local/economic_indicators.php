<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company economic-indicator registry and value history.
 *
 * This class deliberately keeps the registry separate from the legacy
 * audit snapshot table. Existing snapshots are migrated to the registry on
 * upgrade, while the old table remains available for backwards compatibility.
 */
final class economic_indicators {
    public const CATEGORY_FINANCE = 'finance';
    public const CATEGORY_SALES = 'sales';
    public const CATEGORY_OPERATIONS = 'operations';
    public const CATEGORY_PEOPLE = 'people';
    public const CATEGORY_CLIENTS = 'clients';

    public const TYPE_MONEY = 'money';
    public const TYPE_PERCENT = 'percent';
    public const TYPE_COUNT = 'count';
    public const TYPE_RATIO = 'ratio';
    public const TYPE_DAYS = 'days';

    public const PERIOD_MONTH = 'month';
    public const PERIOD_QUARTER = 'quarter';
    public const PERIOD_YEAR = 'year';
    public const PERIOD_CUSTOM = 'custom';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ERP = 'erp';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_API = 'api';

    public const SCENARIO_ACTUAL = 'actual';
    public const SCENARIO_PLAN = 'plan';
    public const SCENARIO_FORECAST = 'forecast';

    public const DIRECTION_UP = 'up';
    public const DIRECTION_DOWN = 'down';
    public const DIRECTION_TARGET = 'target';

    /** @return string[] */
    public static function categories(): array {
        return [
            self::CATEGORY_FINANCE,
            self::CATEGORY_SALES,
            self::CATEGORY_OPERATIONS,
            self::CATEGORY_PEOPLE,
            self::CATEGORY_CLIENTS,
        ];
    }

    /** @return string[] */
    public static function value_types(): array {
        return [self::TYPE_MONEY, self::TYPE_PERCENT, self::TYPE_COUNT, self::TYPE_RATIO, self::TYPE_DAYS];
    }

    /** @return string[] */
    public static function period_types(): array {
        return [self::PERIOD_MONTH, self::PERIOD_QUARTER, self::PERIOD_YEAR, self::PERIOD_CUSTOM];
    }

    /** @return string[] */
    public static function sources(): array {
        return [self::SOURCE_MANUAL, self::SOURCE_ERP, self::SOURCE_IMPORT, self::SOURCE_API];
    }

    /** @return string[] */
    public static function scenarios(): array {
        return [self::SCENARIO_ACTUAL, self::SCENARIO_PLAN, self::SCENARIO_FORECAST];
    }

    /** @return string[] */
    public static function directions(): array {
        return [self::DIRECTION_UP, self::DIRECTION_DOWN, self::DIRECTION_TARGET];
    }

    /**
     * Canonical built-in registry. Codes are stable integration identifiers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function default_definitions(): array {
        return [
            ['REV', 'Выручка', 'Доход компании от основной деятельности за период.', 'finance', 'money', 'currency', 'month', 'up', 10],
            ['GROSS_PROFIT', 'Валовая прибыль', 'Выручка за вычетом прямой себестоимости.', 'finance', 'money', 'currency', 'month', 'up', 20],
            ['OPERATING_PROFIT', 'Операционная прибыль', 'Финансовый результат основной деятельности до процентов и налога на прибыль.', 'finance', 'money', 'currency', 'month', 'up', 30],
            ['PROFIT', 'Чистая прибыль', 'Итоговая прибыль компании после расходов и налогов.', 'finance', 'money', 'currency', 'month', 'up', 40],
            ['EBITDA', 'EBITDA', 'Прибыль до процентов, налогов и амортизации.', 'finance', 'money', 'currency', 'month', 'up', 50],
            ['MARGIN', 'Рентабельность продаж', 'Отношение прибыли к выручке за период.', 'finance', 'percent', '%', 'month', 'up', 60],
            ['DEBT', 'Дебиторская задолженность', 'Объём задолженности покупателей и заказчиков на конец периода.', 'finance', 'money', 'currency', 'month', 'down', 70],
            ['PAYROLL', 'Фонд оплаты труда', 'Начисленный фонд оплаты труда за период.', 'people', 'money', 'currency', 'month', 'target', 80],
            ['EMPLOYEES', 'Среднесписочная численность', 'Среднее количество сотрудников за период.', 'people', 'count', 'чел.', 'month', 'target', 90],
            ['AVG_SALARY', 'Средняя начисленная зарплата', 'Средняя начисленная заработная плата одного сотрудника.', 'people', 'money', 'currency', 'month', 'up', 100],
            ['TURNOVER', 'Текучесть персонала', 'Доля сотрудников, покинувших компанию за период.', 'people', 'percent', '%', 'month', 'down', 110],
            ['HIRING_COST', 'Стоимость найма', 'Средняя стоимость закрытия одной вакансии.', 'people', 'money', 'currency', 'month', 'down', 120],
            ['CLIENTS', 'Количество клиентов', 'Количество активных или новых клиентов за период.', 'clients', 'count', 'шт.', 'month', 'up', 130],
            ['AVG_CHECK', 'Средний чек', 'Средняя сумма одной продажи или заказа.', 'sales', 'money', 'currency', 'month', 'up', 140],
            ['CONVERSION', 'Конверсия продаж', 'Доля целевых обращений, завершившихся продажей.', 'sales', 'percent', '%', 'month', 'up', 150],
            ['SALES_PLAN', 'Выполнение плана продаж', 'Фактические продажи относительно утверждённого плана.', 'sales', 'percent', '%', 'month', 'up', 160],
            ['REVENUE_PER_EMPLOYEE', 'Выручка на сотрудника', 'Выручка за период в расчёте на одного сотрудника.', 'operations', 'money', 'currency', 'month', 'up', 170],
            ['CYCLE_TIME', 'Длительность ключевого процесса', 'Среднее время прохождения выбранного ключевого процесса.', 'operations', 'days', 'дн.', 'month', 'down', 180],
            ['PROFIT_TAX', 'Налог на прибыль', 'Уплаченный или начисленный налог на прибыль за период.', 'finance', 'money', 'currency', 'month', 'target', 190],
            ['VAT', 'НДС', 'Уплаченный или начисленный НДС за период.', 'finance', 'money', 'currency', 'month', 'target', 200],
            ['SOCIAL_FUNDS', 'Страховые взносы', 'Страховые взносы и обязательные социальные платежи за период.', 'finance', 'money', 'currency', 'month', 'target', 210],
            ['OTHER_TAXES', 'Прочие налоги', 'Прочие налоги и обязательные платежи за период.', 'finance', 'money', 'currency', 'month', 'target', 220],
        ];
    }

    /** Seed the canonical global catalogue without overwriting existing rows. */
    public static function seed_defaults(int $actorid = 0): int {
        global $DB;
        if (!self::table_exists('local_kbacorp_econ_ind')) {
            return 0;
        }
        $created = 0;
        $now = time();
        foreach (self::default_definitions() as $definition) {
            [$code, $name, $description, $category, $valuetype, $unit, $periodtype, $direction, $sortorder] = $definition;
            if ($DB->record_exists('local_kbacorp_econ_ind', ['companyid' => 0, 'code' => $code])) {
                continue;
            }
            $DB->insert_record('local_kbacorp_econ_ind', (object)[
                'companyid' => 0,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'valuetype' => $valuetype,
                'unit' => $unit,
                'periodtype' => $periodtype,
                'defaultsource' => self::SOURCE_MANUAL,
                'direction' => $direction,
                'sortorder' => $sortorder,
                'active' => 1,
                'systemdefined' => 1,
                'createdby' => $actorid,
                'modifiedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $created++;
        }
        return $created;
    }

    /** Check table availability during upgrades and partially installed states. */
    private static function table_exists(string $tablename): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table($tablename));
    }

    /** Return a global or company-owned indicator and enforce company isolation. */
    public static function get_indicator(int $indicatorid, int $companyid): \stdClass {
        global $DB;
        $indicator = $DB->get_record('local_kbacorp_econ_ind', ['id' => $indicatorid], '*', MUST_EXIST);
        if ((int)$indicator->companyid !== 0 && (int)$indicator->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        return $indicator;
    }

    /** Return one company value and enforce company isolation. */
    public static function get_value(int $valueid, int $companyid): \stdClass {
        global $DB;
        $value = $DB->get_record('local_kbacorp_econ_val', ['id' => $valueid], '*', MUST_EXIST);
        if ((int)$value->companyid !== $companyid) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        return $value;
    }

    /**
     * List global and company-specific indicators, with their latest value.
     *
     * @return array<int,\stdClass>
     */
    public static function get_catalog(int $companyid, array $filters = []): array {
        global $DB;
        $where = ['(i.companyid = 0 OR i.companyid = :companyid)'];
        $params = ['companyid' => $companyid];
        if (!empty($filters['category']) && in_array($filters['category'], self::categories(), true)) {
            $where[] = 'i.category = :category';
            $params['category'] = $filters['category'];
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== '' && $filters['active'] !== null) {
            $where[] = 'i.active = :active';
            $params['active'] = empty($filters['active']) ? 0 : 1;
        }
        if (!empty($filters['query'])) {
            $query = trim((string)$filters['query']);
            $where[] = '(' . $DB->sql_like('i.code', ':qcode', false) . ' OR ' .
                $DB->sql_like('i.name', ':qname', false) . ')';
            $params['qcode'] = '%' . $query . '%';
            $params['qname'] = '%' . $query . '%';
        }
        $records = $DB->get_records_sql(
            'SELECT i.* FROM {local_kbacorp_econ_ind} i WHERE ' . implode(' AND ', $where) .
            ' ORDER BY i.category, i.sortorder, i.name, i.id', $params);
        foreach ($records as $record) {
            $latest = $DB->get_record_sql(
                'SELECT v.* FROM {local_kbacorp_econ_val} v
                  WHERE v.companyid = :companyid AND v.indicatorid = :indicatorid
                    AND v.scenario = :scenario
               ORDER BY v.periodend DESC, v.id DESC',
                ['companyid' => $companyid, 'indicatorid' => (int)$record->id,
                    'scenario' => self::SCENARIO_ACTUAL], IGNORE_MULTIPLE);
            $record->latest = $latest ?: null;
        }
        return array_values($records);
    }

    /** Return summary counts for the registry header. */
    public static function get_summary(int $companyid): \stdClass {
        global $DB;
        $indicators = (int)$DB->count_records_select('local_kbacorp_econ_ind',
            '(companyid = 0 OR companyid = :companyid) AND active = 1', ['companyid' => $companyid]);
        $values = $DB->count_records('local_kbacorp_econ_val', ['companyid' => $companyid]);
        $verified = $DB->count_records('local_kbacorp_econ_val', ['companyid' => $companyid, 'verified' => 1]);
        $linkedaudits = (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT auditid) FROM {local_kbacorp_econ_val}
              WHERE companyid = :companyid AND auditid > 0', ['companyid' => $companyid]);
        return (object)[
            'indicators' => $indicators,
            'values' => $values,
            'verified' => $verified,
            'linkedaudits' => $linkedaudits,
        ];
    }

    /** Save a company-specific indicator definition. */
    public static function save_indicator(\stdClass $data, int $actorid): int {
        global $DB;
        $companyid = (int)$data->companyid;
        if ($companyid <= 0) {
            throw new \invalid_parameter_exception('A company context is required.');
        }
        $id = (int)($data->id ?? 0);
        $existing = null;
        if ($id) {
            $existing = self::get_indicator($id, $companyid);
            if ((int)$existing->companyid === 0 || !empty($existing->systemdefined)) {
                throw new \moodle_exception('economicbuiltinreadonly', 'local_kbacorporate');
            }
        }
        $code = strtoupper(trim((string)$data->code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $code)) {
            throw new \invalid_parameter_exception('Invalid indicator code.');
        }
        $params = ['companyid' => $companyid, 'code' => $code, 'id' => $id];
        if ($DB->record_exists_select('local_kbacorp_econ_ind',
                '(companyid = 0 OR companyid = :companyid) AND code = :code AND id <> :id', $params)) {
            throw new \moodle_exception('economiccodeduplicate', 'local_kbacorporate');
        }
        $name = trim((string)($data->name ?? ''));
        if ($name === '') {
            throw new \invalid_parameter_exception('Indicator name is required.');
        }
        $now = time();
        $record = (object)[
            'companyid' => $companyid,
            'code' => $code,
            'name' => $name,
            'description' => trim((string)($data->description ?? '')) ?: null,
            'category' => self::normalise_enum((string)$data->category, self::categories(), self::CATEGORY_FINANCE),
            'valuetype' => self::normalise_enum((string)$data->valuetype, self::value_types(), self::TYPE_MONEY),
            'unit' => trim((string)($data->unit ?? '')) ?: 'currency',
            'periodtype' => self::normalise_enum((string)$data->periodtype, self::period_types(), self::PERIOD_MONTH),
            'defaultsource' => self::normalise_enum((string)$data->defaultsource, self::sources(), self::SOURCE_MANUAL),
            'direction' => self::normalise_enum((string)$data->direction, self::directions(), self::DIRECTION_UP),
            'sortorder' => max(0, (int)($data->sortorder ?? 0)),
            'active' => empty($data->active) ? 0 : 1,
            'systemdefined' => 0,
            'modifiedby' => $actorid,
            'timemodified' => $now,
        ];
        if ($existing) {
            $before = self::indicator_snapshot($existing);
            $record->id = (int)$existing->id;
            $record->createdby = (int)$existing->createdby;
            $record->timecreated = (int)$existing->timecreated;
            $DB->update_record('local_kbacorp_econ_ind', $record);
            self::log_change($companyid, 'indicator', (int)$record->id, 'updated', $before,
                self::indicator_snapshot($record), $actorid, self::SOURCE_MANUAL);
            return (int)$record->id;
        }
        $record->createdby = $actorid;
        $record->timecreated = $now;
        $id = (int)$DB->insert_record('local_kbacorp_econ_ind', $record);
        $record->id = $id;
        self::log_change($companyid, 'indicator', $id, 'created', null,
            self::indicator_snapshot($record), $actorid, self::SOURCE_MANUAL);
        return $id;
    }

    /** Archive a company-specific indicator without deleting values or history. */
    public static function archive_indicator(int $indicatorid, int $companyid, int $actorid): void {
        global $DB;
        $indicator = self::get_indicator($indicatorid, $companyid);
        if ((int)$indicator->companyid === 0 || !empty($indicator->systemdefined)) {
            throw new \moodle_exception('economicbuiltinreadonly', 'local_kbacorporate');
        }
        if (empty($indicator->active)) {
            return;
        }
        $before = self::indicator_snapshot($indicator);
        $indicator->active = 0;
        $indicator->modifiedby = $actorid;
        $indicator->timemodified = time();
        $DB->update_record('local_kbacorp_econ_ind', $indicator);
        self::log_change($companyid, 'indicator', $indicatorid, 'archived', $before,
            self::indicator_snapshot($indicator), $actorid, self::SOURCE_MANUAL);
    }

    /** Parse a locale-friendly decimal value. */
    public static function parse_decimal(string $value): ?float {
        $normalised = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $normalised = str_replace(',', '.', $normalised);
        return $normalised !== '' && is_numeric($normalised) ? (float)$normalised : null;
    }

    /** Normalise a reporting period and derive a stable period key. */
    public static function normalise_period(string $periodtype, int $periodstart, int $periodend): \stdClass {
        $periodtype = self::normalise_enum($periodtype, self::period_types(), self::PERIOD_MONTH);
        $date = usergetdate($periodstart > 0 ? $periodstart : time());
        $year = (int)$date['year'];
        $month = (int)$date['mon'];
        if ($periodtype === self::PERIOD_MONTH) {
            $start = make_timestamp($year, $month, 1, 0, 0, 0);
            [$nextyear, $nextmonth] = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
            $end = make_timestamp($nextyear, $nextmonth, 1, 0, 0, 0) - 1;
            $key = sprintf('%04d-%02d', $year, $month);
        } else if ($periodtype === self::PERIOD_QUARTER) {
            $quarter = (int)floor(($month - 1) / 3) + 1;
            $startmonth = ($quarter - 1) * 3 + 1;
            $start = make_timestamp($year, $startmonth, 1, 0, 0, 0);
            $nextmonth = $startmonth + 3;
            $nextyear = $year;
            if ($nextmonth > 12) {
                $nextmonth -= 12;
                $nextyear++;
            }
            $end = make_timestamp($nextyear, $nextmonth, 1, 0, 0, 0) - 1;
            $key = sprintf('%04d-Q%d', $year, $quarter);
        } else if ($periodtype === self::PERIOD_YEAR) {
            $start = make_timestamp($year, 1, 1, 0, 0, 0);
            $end = make_timestamp($year + 1, 1, 1, 0, 0, 0) - 1;
            $key = sprintf('%04d', $year);
        } else {
            $start = usergetmidnight($periodstart > 0 ? $periodstart : time());
            $end = usergetmidnight($periodend > 0 ? $periodend : $start) + DAYSECS - 1;
            if ($end < $start) {
                throw new \invalid_parameter_exception('Period end precedes period start.');
            }
            $key = userdate($start, '%Y-%m-%d') . '_' . userdate($end, '%Y-%m-%d');
        }
        return (object)[
            'periodtype' => $periodtype,
            'periodstart' => $start,
            'periodend' => $end,
            'periodkey' => $key,
        ];
    }

    /** Save or update a company indicator value. */
    public static function save_value(\stdClass $data, int $actorid): int {
        global $DB;
        $companyid = (int)$data->companyid;
        if ($companyid <= 0) {
            throw new \invalid_parameter_exception('A company context is required.');
        }
        $id = (int)($data->id ?? 0);
        $existing = $id ? self::get_value($id, $companyid) : null;
        $indicator = self::get_indicator((int)$data->indicatorid, $companyid);
        if (empty($indicator->active) && !$existing) {
            throw new \moodle_exception('economicindicatorinactive', 'local_kbacorporate');
        }
        $number = self::parse_decimal((string)$data->value);
        if ($number === null) {
            throw new \invalid_parameter_exception('A numeric value is required.');
        }
        $period = self::normalise_period((string)$data->periodtype,
            (int)$data->periodstart, (int)$data->periodend);
        $auditid = (int)($data->auditid ?? 0);
        if ($auditid > 0) {
            $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $auditid], 'id,companyid', MUST_EXIST);
            if ((int)$audit->companyid !== $companyid) {
                throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
            }
        }
        $scenario = self::normalise_enum((string)($data->scenario ?? ''), self::scenarios(),
            self::SCENARIO_ACTUAL);
        $duplicate = $DB->get_record('local_kbacorp_econ_val', [
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'periodkey' => $period->periodkey,
            'scenario' => $scenario,
        ]);
        if ($duplicate && (!$existing || (int)$duplicate->id !== (int)$existing->id)) {
            throw new \moodle_exception('economicperiodduplicate', 'local_kbacorporate');
        }
        $now = time();
        $verified = empty($data->verified) ? 0 : 1;
        $currency = strtoupper(trim((string)($data->currency ?? 'RUB')));
        if (!in_array($currency, ['RUB', 'USD', 'EUR', 'KZT'], true)) {
            $currency = 'RUB';
        }
        $source = self::normalise_enum((string)($data->source ?? ''), self::sources(),
            (string)$indicator->defaultsource);
        $verifiedby = $verified ? max(0, (int)($data->verifiedby ?? $actorid)) : 0;
        $verifiedat = $verified ? max(0, (int)($data->verifiedat ?? $now)) : 0;
        if ($verified && $verifiedat === 0) {
            $verifiedat = $now;
        }
        $record = (object)[
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => $auditid,
            'periodtype' => $period->periodtype,
            'periodstart' => $period->periodstart,
            'periodend' => $period->periodend,
            'periodkey' => $period->periodkey,
            'scenario' => $scenario,
            'value' => $number,
            'currency' => $currency,
            'source' => $source,
            'comment' => trim((string)($data->comment ?? '')) ?: null,
            'verified' => $verified,
            'verifiedby' => $verifiedby,
            'verifiedat' => $verifiedat,
            'modifiedby' => $actorid,
            'timemodified' => $now,
        ];
        if ($existing) {
            $before = self::value_snapshot($existing);
            $record->id = (int)$existing->id;
            $record->createdby = (int)$existing->createdby;
            $record->timecreated = (int)$existing->timecreated;
            $DB->update_record('local_kbacorp_econ_val', $record);
            self::log_change($companyid, 'value', (int)$record->id, 'updated', $before,
                self::value_snapshot($record), $actorid, $source);
            return (int)$record->id;
        }
        $record->createdby = $actorid;
        $record->timecreated = $now;
        $id = (int)$DB->insert_record('local_kbacorp_econ_val', $record);
        $record->id = $id;
        self::log_change($companyid, 'value', $id, 'created', null,
            self::value_snapshot($record), $actorid, $source);
        return $id;
    }

    /**
     * Idempotent API-ready upsert using stable indicator code and period data.
     */
    public static function upsert_external_value(int $companyid, string $indicatorcode,
            string $periodtype, int $periodstart, int $periodend, $value,
            string $source, int $actorid = 0, int $auditid = 0,
            string $currency = 'RUB', string $comment = '',
            string $scenario = self::SCENARIO_ACTUAL): int {
        global $DB;
        if ($companyid <= 0) {
            throw new \invalid_parameter_exception('A company context is required.');
        }
        if (!in_array($periodtype, self::period_types(), true)) {
            throw new \invalid_parameter_exception('Unsupported period type.');
        }
        if (!in_array($source, self::sources(), true)) {
            throw new \invalid_parameter_exception('Unsupported economic data source.');
        }
        if (!in_array($scenario, self::scenarios(), true)) {
            throw new \invalid_parameter_exception('Unsupported value scenario.');
        }
        $currency = strtoupper(trim($currency));
        if (!in_array($currency, ['RUB', 'USD', 'EUR', 'KZT'], true)) {
            throw new \invalid_parameter_exception('Unsupported currency.');
        }
        $indicatorcode = strtoupper(trim($indicatorcode));
        $indicator = $DB->get_record_select('local_kbacorp_econ_ind',
            '(companyid = 0 OR companyid = :companyid) AND code = :code AND active = 1',
            ['companyid' => $companyid, 'code' => $indicatorcode], '*', IGNORE_MULTIPLE);
        if (!$indicator) {
            throw new \moodle_exception('economicindicatornotfound', 'local_kbacorporate', '', $indicatorcode);
        }
        $period = self::normalise_period($periodtype, $periodstart, $periodend);
        $scenario = self::normalise_enum($scenario, self::scenarios(), self::SCENARIO_ACTUAL);
        $existing = $DB->get_record('local_kbacorp_econ_val', [
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'periodkey' => $period->periodkey,
            'scenario' => $scenario,
        ]);
        return self::save_value((object)[
            'id' => $existing ? (int)$existing->id : 0,
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => $auditid,
            'periodtype' => $periodtype,
            'periodstart' => $periodstart,
            'periodend' => $periodend,
            'scenario' => $scenario,
            'value' => (string)$value,
            'currency' => $currency,
            'source' => $source,
            'comment' => $comment,
            'verified' => 0,
        ], $actorid);
    }

    /**
     * Return values with indicator and optional audit labels.
     *
     * @return array<int,\stdClass>
     */
    public static function get_values(int $companyid, array $filters = []): array {
        global $DB;
        $where = ['v.companyid = :companyid'];
        $params = ['companyid' => $companyid];
        foreach (['indicatorid', 'auditid'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 'v.' . $field . ' = :' . $field;
                $params[$field] = (int)$filters[$field];
            }
        }
        if (!empty($filters['periodtype']) && in_array($filters['periodtype'], self::period_types(), true)) {
            $where[] = 'v.periodtype = :periodtype';
            $params['periodtype'] = $filters['periodtype'];
        }
        if (!empty($filters['source']) && in_array($filters['source'], self::sources(), true)) {
            $where[] = 'v.source = :source';
            $params['source'] = $filters['source'];
        }
        if (!empty($filters['scenario']) && in_array($filters['scenario'], self::scenarios(), true)) {
            $where[] = 'v.scenario = :scenario';
            $params['scenario'] = $filters['scenario'];
        }
        if (array_key_exists('verified', $filters) && $filters['verified'] !== '' && $filters['verified'] !== null) {
            $where[] = 'v.verified = :verified';
            $params['verified'] = empty($filters['verified']) ? 0 : 1;
        }
        if (!empty($filters['category']) && in_array($filters['category'], self::categories(), true)) {
            $where[] = 'i.category = :indicatorcategory';
            $params['indicatorcategory'] = $filters['category'];
        }
        if (array_key_exists('indicatoractive', $filters) && $filters['indicatoractive'] !== '' &&
                $filters['indicatoractive'] !== null) {
            $where[] = 'i.active = :indicatoractive';
            $params['indicatoractive'] = empty($filters['indicatoractive']) ? 0 : 1;
        }
        if (!empty($filters['query'])) {
            $query = trim((string)$filters['query']);
            $where[] = '(' . $DB->sql_like('i.code', ':valueqcode', false) . ' OR ' .
                $DB->sql_like('i.name', ':valueqname', false) . ')';
            $params['valueqcode'] = '%' . $query . '%';
            $params['valueqname'] = '%' . $query . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'v.periodend >= :fromdate';
            $params['fromdate'] = (int)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'v.periodstart <= :todate';
            $params['todate'] = (int)$filters['to'];
        }
        return array_values($DB->get_records_sql(
            'SELECT v.*, i.code AS indicatorcode, i.name AS indicatorname, i.category,
                    i.valuetype, i.unit, i.direction, a.title AS audittitle
               FROM {local_kbacorp_econ_val} v
               JOIN {local_kbacorp_econ_ind} i ON i.id = v.indicatorid
          LEFT JOIN {local_kbacorp_impl_audit} a ON a.id = v.auditid
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY v.periodend DESC, i.sortorder, i.name, v.id DESC', $params));
    }

    /** Return company audit options suitable for forms. */
    public static function get_audit_options(int $companyid): array {
        global $DB;
        $options = [0 => get_string('economicnoauditlink', 'local_kbacorporate')];
        $records = $DB->get_records('local_kbacorp_impl_audit', ['companyid' => $companyid],
            'auditdate DESC, id DESC', 'id,title,auditdate,status');
        foreach ($records as $record) {
            $options[(int)$record->id] = format_string($record->title) . ' · ' .
                userdate((int)$record->auditdate, get_string('strftimedatefullshort'));
        }
        return $options;
    }

    /** Return value and definition change history for one indicator. */
    public static function get_indicator_history(int $companyid, int $indicatorid): array {
        global $DB;
        self::get_indicator($indicatorid, $companyid);
        $valueids = $DB->get_fieldset_select('local_kbacorp_econ_val', 'id',
            'companyid = :companyid AND indicatorid = :indicatorid',
            ['companyid' => $companyid, 'indicatorid' => $indicatorid]);
        $where = '(entitytype = :indicatortype AND entityid = :indicatorid)';
        $params = ['indicatortype' => 'indicator', 'indicatorid' => $indicatorid, 'companyid' => $companyid];
        if ($valueids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $valueids), SQL_PARAMS_NAMED, 'valueid');
            $where .= ' OR (entitytype = :valuetype AND entityid ' . $insql . ')';
            $params['valuetype'] = 'value';
            $params += $inparams;
        }
        return array_values($DB->get_records_select('local_kbacorp_econ_log',
            'companyid = :companyid AND (' . $where . ')', $params,
            'timecreated DESC, id DESC'));
    }

    /** Format a value using the definition's semantic type. */
    public static function format_value($value, string $valuetype, string $unit, string $currency = 'RUB'): string {
        if ($value === null || $value === '') {
            return '—';
        }
        $decimals = in_array($valuetype, [self::TYPE_PERCENT, self::TYPE_RATIO], true) ? 2 :
            ($valuetype === self::TYPE_COUNT ? 0 : 2);
        $formatted = format_float((float)$value, $decimals);
        if ($valuetype === self::TYPE_MONEY || $unit === 'currency') {
            return $formatted . ' ' . s($currency);
        }
        return $formatted . ($unit !== '' ? ' ' . s($unit) : '');
    }

    /** Human-readable period label. */
    public static function period_label(\stdClass $value): string {
        if (!empty($value->periodkey)) {
            return s($value->periodkey);
        }
        return userdate((int)$value->periodstart, get_string('strftimedatefullshort')) . ' — ' .
            userdate((int)$value->periodend, get_string('strftimedatefullshort'));
    }

    /** Prefix potentially executable spreadsheet text. */
    public static function csv_safe(string $value): string {
        return preg_match('/^[=+\-@]/', ltrim($value)) ? "'" . $value : $value;
    }

    /**
     * Synchronise one legacy audit snapshot into the generic registry.
     *
     * The operation is idempotent and updates the same company / indicator /
     * period record instead of creating a duplicate.
     */
    public static function sync_legacy_snapshot(int $snapshotid, int $actorid = 0): \stdClass {
        global $DB;
        $result = (object)['created' => 0, 'updated' => 0, 'skipped' => 0];
        if (!self::table_exists('local_kbacorp_impl_econ') ||
                !self::table_exists('local_kbacorp_econ_ind') ||
                !self::table_exists('local_kbacorp_econ_val')) {
            return $result;
        }
        $snapshot = $DB->get_record('local_kbacorp_impl_econ', ['id' => $snapshotid]);
        if (!$snapshot) {
            return $result;
        }
        self::seed_defaults($actorid);
        $mapping = self::legacy_mapping();
        $periodstart = (int)$snapshot->periodstart;
        $periodend = (int)$snapshot->periodend;
        if ($periodstart <= 0) {
            $auditdate = (int)$DB->get_field('local_kbacorp_impl_audit', 'auditdate',
                ['id' => (int)$snapshot->auditid], IGNORE_MISSING);
            $periodstart = $auditdate > 0 ? $auditdate :
                ((int)($snapshot->timecreated ?? 0) > 0 ? (int)$snapshot->timecreated : time());
        }
        if ($periodend <= 0) {
            $periodend = $periodstart;
        }
        $period = self::normalise_period((string)$snapshot->periodtype, $periodstart, $periodend);
        $source = self::legacy_source((string)$snapshot->source);
        $currency = strtoupper(trim((string)($snapshot->currency ?? 'RUB')));
        if (!in_array($currency, ['RUB', 'USD', 'EUR', 'KZT'], true)) {
            $currency = 'RUB';
        }
        $comment = trim((string)($snapshot->comment ?? ''));
        $verified = empty($snapshot->verified) ? 0 : 1;
        $verifiedby = $verified ? max(0, (int)($snapshot->verifiedby ?? 0)) : 0;
        $verifiedat = $verified ? max(0, (int)($snapshot->verifiedat ?? 0)) : 0;
        foreach ($mapping as $field => $code) {
            if ($snapshot->{$field} === null || $snapshot->{$field} === '') {
                continue;
            }
            $indicator = $DB->get_record('local_kbacorp_econ_ind', [
                'companyid' => 0,
                'code' => $code,
            ]);
            if (!$indicator) {
                $result->skipped++;
                continue;
            }
            $existing = $DB->get_record('local_kbacorp_econ_val', [
                'companyid' => (int)$snapshot->companyid,
                'indicatorid' => (int)$indicator->id,
                'periodkey' => $period->periodkey,
                'scenario' => self::SCENARIO_ACTUAL,
            ]);
            $expectedvalue = (float)$snapshot->{$field};
            if ($existing && (float)$existing->value === $expectedvalue &&
                    (int)$existing->auditid === (int)$snapshot->auditid &&
                    (string)$existing->source === $source &&
                    (int)$existing->verified === $verified &&
                    (int)$existing->verifiedby === $verifiedby &&
                    (int)$existing->verifiedat === $verifiedat &&
                    (string)$existing->currency === $currency &&
                    (string)($existing->comment ?? '') === $comment) {
                $result->skipped++;
                continue;
            }
            self::save_value((object)[
                'id' => $existing ? (int)$existing->id : 0,
                'companyid' => (int)$snapshot->companyid,
                'indicatorid' => (int)$indicator->id,
                'auditid' => (int)$snapshot->auditid,
                'periodtype' => $period->periodtype,
                'periodstart' => $period->periodstart,
                'periodend' => $period->periodend,
                'scenario' => self::SCENARIO_ACTUAL,
                'value' => (string)$snapshot->{$field},
                'currency' => $currency,
                'source' => $source,
                'comment' => $comment,
                'verified' => $verified,
                'verifiedby' => $verifiedby,
                'verifiedat' => $verifiedat,
            ], $actorid);
            if ($existing) {
                $result->updated++;
            } else {
                $result->created++;
            }
        }
        return $result;
    }

    /** Backfill the generic registry from the legacy one-snapshot-per-audit table. */
    public static function migrate_legacy_snapshots(int $actorid = 0): \stdClass {
        global $DB;
        $result = (object)['snapshots' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        if (!self::table_exists('local_kbacorp_impl_econ') ||
                !self::table_exists('local_kbacorp_econ_ind') ||
                !self::table_exists('local_kbacorp_econ_val')) {
            return $result;
        }
        foreach ($DB->get_records('local_kbacorp_impl_econ', null, 'id', 'id') as $snapshot) {
            $result->snapshots++;
            $one = self::sync_legacy_snapshot((int)$snapshot->id, $actorid);
            $result->created += (int)$one->created;
            $result->updated += (int)$one->updated;
            $result->skipped += (int)$one->skipped;
        }
        return $result;
    }

    /** Map legacy fixed snapshot columns to stable registry codes. */
    private static function legacy_mapping(): array {
        return [
            'revenue' => 'REV',
            'grossprofit' => 'GROSS_PROFIT',
            'operatingprofit' => 'OPERATING_PROFIT',
            'netprofit' => 'PROFIT',
            'profittax' => 'PROFIT_TAX',
            'vat' => 'VAT',
            'socialfunds' => 'SOCIAL_FUNDS',
            'othertaxes' => 'OTHER_TAXES',
            'payroll' => 'PAYROLL',
            'employees' => 'EMPLOYEES',
            'averagesalary' => 'AVG_SALARY',
        ];
    }

    private static function legacy_source(string $source): string {
        if (in_array($source, ['odoo', '1c', 'erp'], true)) {
            return self::SOURCE_ERP;
        }
        if ($source === 'file') {
            return self::SOURCE_IMPORT;
        }
        if ($source === 'api') {
            return self::SOURCE_API;
        }
        return self::SOURCE_MANUAL;
    }

    private static function normalise_enum(string $value, array $allowed, string $fallback): string {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function indicator_snapshot(\stdClass $record): array {
        $fields = ['id', 'companyid', 'code', 'name', 'description', 'category', 'valuetype', 'unit',
            'periodtype', 'defaultsource', 'direction', 'sortorder', 'active', 'systemdefined'];
        return self::snapshot($record, $fields);
    }

    private static function value_snapshot(\stdClass $record): array {
        $fields = ['id', 'companyid', 'indicatorid', 'auditid', 'periodtype', 'periodstart', 'periodend',
            'periodkey', 'scenario', 'value', 'currency', 'source', 'comment', 'verified', 'verifiedby', 'verifiedat'];
        return self::snapshot($record, $fields);
    }

    private static function snapshot(\stdClass $record, array $fields): array {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = property_exists($record, $field) ? $record->{$field} : null;
        }
        return $result;
    }

    private static function log_change(int $companyid, string $entitytype, int $entityid,
            string $action, ?array $old, ?array $new, int $actorid, string $source): void {
        global $DB;
        if (!self::table_exists('local_kbacorp_econ_log')) {
            return;
        }
        $DB->insert_record('local_kbacorp_econ_log', (object)[
            'companyid' => $companyid,
            'entitytype' => $entitytype,
            'entityid' => $entityid,
            'action' => $action,
            'actorid' => $actorid,
            'oldjson' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'newjson' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source' => $source,
            'timecreated' => time(),
        ]);
    }
}
