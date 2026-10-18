<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Bridge between KBA corporate role/GAP metadata and Moodle core Learning Plans.
 *
 * Moodle core remains authoritative for Learning Plan templates, plans and plan
 * competency membership. KBA tables retain company-specific metadata that core
 * Learning Plans do not model: business-role target levels, priorities, GAP
 * baselines, recommended courses, agreement workflow and BMS/ERP context.
 */
class moodle_learning_plans {
    /** Stable marker used to recover links if local foreign IDs were reset. */
    private const PLAN_MARKER_PREFIX = 'KBA-DEVPLAN:';
    private const TEMPLATE_MARKER_PREFIX = 'KBA-BUSROLE:';

    /** Whether the standard Moodle competency/Learning Plan subsystem is usable. */
    public static function available(): bool {
        global $DB;
        if (!get_config('core_competency', 'enabled')) {
            return false;
        }
        if (!class_exists('\\core_competency\\api') || !class_exists('\\core_competency\\plan') ||
                !class_exists('\\core_competency\\template')) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('competency_plan')) &&
            $dbman->table_exists(new \xmldb_table('competency_plancomp')) &&
            $dbman->table_exists(new \xmldb_table('competency_template')) &&
            $dbman->table_exists(new \xmldb_table('competency_templatecomp'));
    }

    /** URL of the standard Moodle Learning Plan page. */
    public static function plan_url(int $moodleplanid): \moodle_url {
        return new \moodle_url('/admin/tool/lp/plan.php', ['id' => $moodleplanid]);
    }

    /**
     * URL of the standard Moodle Learning Plan template page.
     *
     * Moodle requires both templateid and pagecontextid on this endpoint. The
     * template context is the correct default for templates created by KBA and
     * also keeps category-level templates compatible with Moodle navigation.
     *
     * @param int $templateid Moodle competency template ID.
     * @param int $pagecontextid Optional page context. When omitted it is read
     *        from competency_template and falls back to the system context.
     * @return \moodle_url
     */
    public static function template_url(int $templateid, int $pagecontextid = 0): \moodle_url {
        global $DB;

        if ($pagecontextid <= 0) {
            $pagecontextid = (int)$DB->get_field(
                'competency_template',
                'contextid',
                ['id' => $templateid],
                IGNORE_MISSING
            );
        }
        if ($pagecontextid <= 0) {
            $pagecontextid = (int)\context_system::instance()->id;
        }

        return new \moodle_url('/admin/tool/lp/templatecompetencies.php', [
            'templateid' => $templateid,
            'pagecontextid' => $pagecontextid,
        ]);
    }

    /** Return a readable status name for a Moodle core plan. */
    public static function plan_status_name(int $status): string {
        if (!class_exists('\\core_competency\\plan')) {
            return (string)$status;
        }
        switch ($status) {
            case \core_competency\plan::STATUS_DRAFT:
                return get_string('moodleplanstatusdraft', 'local_kbacorporate');
            case \core_competency\plan::STATUS_ACTIVE:
                return get_string('moodleplanstatusactive', 'local_kbacorporate');
            case \core_competency\plan::STATUS_COMPLETE:
                return get_string('moodleplanstatuscomplete', 'local_kbacorporate');
            case \core_competency\plan::STATUS_WAITING_FOR_REVIEW:
                return get_string('moodleplanstatuswaiting', 'local_kbacorporate');
            case \core_competency\plan::STATUS_IN_REVIEW:
                return get_string('moodleplanstatusreview', 'local_kbacorporate');
            default:
                return (string)$status;
        }
    }

    /**
     * Ensure that a KBA business role has a matching Moodle Learning Plan template.
     *
     * The Moodle template stores competency membership. KBA remains authoritative
     * for target levels, priority and mandatory flags because Moodle templates do
     * not have those company-specific fields.
     */
    public static function ensure_role_template(int $roleid): \stdClass {
        global $DB;
        self::require_available();

        $role = business_roles::get_role($roleid);
        $context = \context_system::instance();
        $marker = self::TEMPLATE_MARKER_PREFIX . $roleid;
        $templateid = property_exists($role, 'moodletemplateid') ? (int)$role->moodletemplateid : 0;

        if ($templateid > 0 && !$DB->record_exists('competency_template', ['id' => $templateid])) {
            $templateid = 0;
        }
        if ($templateid <= 0) {
            $existing = $DB->get_record_select('competency_template',
                $DB->sql_like('description', ':marker', false), ['marker' => '%' . $marker . '%'], 'id', IGNORE_MULTIPLE);
            if ($existing) {
                $templateid = (int)$existing->id;
            }
        }

        $shortname = self::template_name($role);
        $description = self::template_description($role, $marker);
        if ($templateid > 0) {
            $template = \core_competency\api::read_template($templateid);
            $record = $template->to_record();
            $record->id = $templateid;
            $record->shortname = $shortname;
            $record->description = $description;
            $record->descriptionformat = FORMAT_PLAIN;
            $record->visible = !empty($role->active) ? 1 : 0;
            $record->contextid = $context->id;
            \core_competency\api::update_template($record);
        } else {
            $template = \core_competency\api::create_template((object)[
                'shortname' => $shortname,
                'description' => $description,
                'descriptionformat' => FORMAT_PLAIN,
                'duedate' => 0,
                'visible' => !empty($role->active) ? 1 : 0,
                'contextid' => $context->id,
            ]);
            $templateid = (int)$template->get('id');
        }

        if (property_exists($role, 'moodletemplateid') && (int)$role->moodletemplateid !== $templateid) {
            $DB->set_field('local_kbacorp_busrole', 'moodletemplateid', $templateid, ['id' => $roleid]);
        }

        $required = [];
        foreach (business_roles::get_role_competencies($roleid) as $requirement) {
            if (business_roles::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $required[(int)$requirement->competencyid] = true;
        }
        $current = [];
        foreach (\core_competency\api::list_competencies_in_template($templateid) as $competency) {
            $current[(int)$competency->get('id')] = true;
        }

        $added = 0;
        $removed = 0;
        foreach (array_diff_key($required, $current) as $competencyid => $unused) {
            \core_competency\api::add_competency_to_template($templateid, (int)$competencyid);
            $added++;
        }
        foreach (array_diff_key($current, $required) as $competencyid => $unused) {
            \core_competency\api::remove_competency_from_template($templateid, (int)$competencyid);
            $removed++;
        }

        return (object)[
            'roleid' => $roleid,
            'templateid' => $templateid,
            'competencies' => count($required),
            'added' => $added,
            'removed' => $removed,
        ];
    }

    /** Ensure that a KBA development plan has a standard Moodle Learning Plan. */
    public static function ensure_plan(int $kbaplanid): \stdClass {
        global $DB;
        self::require_available();

        $plan = development_plans::get_plan($kbaplanid);
        $moodleplanid = property_exists($plan, 'moodleplanid') ? (int)$plan->moodleplanid : 0;
        if ($moodleplanid > 0) {
            $linked = $DB->get_record('competency_plan', ['id' => $moodleplanid], 'id,userid,templateid,status');
            if (!$linked || (int)$linked->userid !== (int)$plan->userid) {
                $moodleplanid = 0;
            }
        }

        $marker = self::PLAN_MARKER_PREFIX . $kbaplanid;
        if ($moodleplanid <= 0) {
            $existing = $DB->get_record_select('competency_plan',
                'userid = :userid AND ' . $DB->sql_like('description', ':marker', false), [
                    'userid' => (int)$plan->userid,
                    'marker' => '%' . $marker . '%',
                ], 'id', IGNORE_MULTIPLE);
            if ($existing) {
                $moodleplanid = (int)$existing->id;
            }
        }

        $created = false;
        if ($moodleplanid <= 0) {
            // Core plan is immediately active; KBA agreement/approval remains an extension workflow.
            // This avoids abusing Moodle's reviewer workflow while making the standard plan usable now.
            $coreplan = \core_competency\api::create_plan((object)[
                'name' => self::plan_name($plan),
                'description' => self::plan_description($plan, $marker),
                'descriptionformat' => FORMAT_PLAIN,
                'userid' => (int)$plan->userid,
                'templateid' => null,
                'origtemplateid' => null,
                'status' => \core_competency\plan::STATUS_ACTIVE,
                'duedate' => max(0, (int)$plan->targetdate),
                'reviewerid' => null,
            ]);
            $moodleplanid = (int)$coreplan->get('id');
            $created = true;
        } else {
            $coreplan = \core_competency\api::read_plan($moodleplanid);
            if ((int)$coreplan->get('status') !== \core_competency\plan::STATUS_COMPLETE && !$coreplan->is_based_on_template()) {
                $record = $coreplan->to_record();
                $record->id = $moodleplanid;
                $record->name = self::plan_name($plan);
                $record->description = self::plan_description($plan, $marker);
                $record->descriptionformat = FORMAT_PLAIN;
                $record->duedate = max(0, (int)$plan->targetdate);
                \core_competency\api::update_plan($record);
            }
        }

        if (property_exists($plan, 'moodleplanid') && (int)$plan->moodleplanid !== $moodleplanid) {
            $DB->set_field('local_kbacorp_devplan', 'moodleplanid', $moodleplanid, ['id' => $kbaplanid]);
        }

        $sync = self::sync_plan_competencies($kbaplanid, $moodleplanid);
        $coreplan = \core_competency\api::read_plan($moodleplanid);
        return (object)[
            'kbaplanid' => $kbaplanid,
            'moodleplanid' => $moodleplanid,
            'created' => $created,
            'added' => $sync->added,
            'removed' => $sync->removed,
            'competencies' => $sync->competencies,
            'status' => (int)$coreplan->get('status'),
        ];
    }

    /** Synchronise standard Moodle plan competency membership from KBA development items. */
    public static function sync_plan_competencies(int $kbaplanid, int $moodleplanid): \stdClass {
        self::require_available();
        $coreplan = \core_competency\api::read_plan($moodleplanid);
        if ($coreplan->is_based_on_template()) {
            throw new \coding_exception('Расширенные планы KBA должны быть независимыми учебными планами Moodle.');
        }
        if ((int)$coreplan->get('status') === \core_competency\plan::STATUS_COMPLETE) {
            return (object)['added' => 0, 'removed' => 0, 'competencies' => count(self::plan_competency_ids($moodleplanid))];
        }

        $required = [];
        foreach (development_plans::get_items($kbaplanid) as $item) {
            $required[(int)$item->competencyid] = true;
        }
        $current = array_fill_keys(self::plan_competency_ids($moodleplanid), true);
        $added = 0;
        $removed = 0;
        foreach (array_diff_key($required, $current) as $competencyid => $unused) {
            \core_competency\api::add_competency_to_plan($moodleplanid, (int)$competencyid);
            $added++;
        }
        foreach (array_diff_key($current, $required) as $competencyid => $unused) {
            \core_competency\api::remove_competency_from_plan($moodleplanid, (int)$competencyid);
            $removed++;
        }
        return (object)['added' => $added, 'removed' => $removed, 'competencies' => count($required)];
    }

    /** IDs of competencies in a standard Moodle plan, indexed as a plain array. */
    public static function plan_competency_ids(int $moodleplanid): array {
        self::require_available();
        $ids = [];
        foreach (\core_competency\api::list_plan_competencies($moodleplanid) as $entry) {
            if (is_object($entry) && property_exists($entry, 'competency') && $entry->competency) {
                $ids[] = (int)$entry->competency->get('id');
            }
        }
        return array_values(array_unique($ids));
    }

    /** Read a linked core plan without throwing when the link is stale. */
    public static function linked_plan(int $kbaplanid): ?\core_competency\plan {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $record = $DB->get_record('local_kbacorp_devplan', ['id' => $kbaplanid], 'id,moodleplanid');
        if (!$record || empty($record->moodleplanid) || !$DB->record_exists('competency_plan', ['id' => $record->moodleplanid])) {
            return null;
        }
        return \core_competency\api::read_plan((int)$record->moodleplanid);
    }

    /**
     * Non-destructive migration/synchronisation for one company.
     *
     * Existing KBA records are never deleted. Open KBA plans receive a linked
     * Moodle plan; business roles receive standard Moodle templates.
     */
    public static function migrate_company(int $companyid): \stdClass {
        global $DB;
        self::require_available();

        $result = (object)[
            'roles' => 0,
            'templatescreated' => 0,
            'plans' => 0,
            'planscreated' => 0,
            'errors' => [],
        ];
        foreach (business_roles::get_catalog($companyid, false) as $role) {
            try {
                $before = property_exists($role, 'moodletemplateid') ? (int)$role->moodletemplateid : 0;
                $synced = self::ensure_role_template((int)$role->id);
                $result->roles++;
                if ($before <= 0 && $synced->templateid > 0) {
                    $result->templatescreated++;
                }
            } catch (\Throwable $e) {
                $result->errors[] = 'Role #' . (int)$role->id . ': ' . $e->getMessage();
            }
        }

        $plans = $DB->get_records_select('local_kbacorp_devplan',
            'companyid = :companyid AND status IN (:draft, :pending, :active)', [
                'companyid' => $companyid,
                'draft' => development_plans::STATUS_DRAFT,
                'pending' => development_plans::STATUS_PENDING,
                'active' => development_plans::STATUS_ACTIVE,
            ], 'id ASC', 'id,moodleplanid');
        foreach ($plans as $plan) {
            try {
                $before = property_exists($plan, 'moodleplanid') ? (int)$plan->moodleplanid : 0;
                $synced = self::ensure_plan((int)$plan->id);
                $result->plans++;
                if ($before <= 0 && $synced->moodleplanid > 0) {
                    $result->planscreated++;
                }
            } catch (\Throwable $e) {
                $result->errors[] = 'Plan #' . (int)$plan->id . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    /** Company integration health counters. */
    public static function company_status(int $companyid): \stdClass {
        global $DB;
        $roles = business_roles::get_catalog($companyid, false);
        $rolelinked = 0;
        foreach ($roles as $role) {
            if (property_exists($role, 'moodletemplateid') && (int)$role->moodletemplateid > 0 &&
                    $DB->record_exists('competency_template', ['id' => (int)$role->moodletemplateid])) {
                $rolelinked++;
            }
        }
        $plans = $DB->get_records_select('local_kbacorp_devplan',
            'companyid = :companyid AND status IN (:draft, :pending, :active)', [
                'companyid' => $companyid,
                'draft' => development_plans::STATUS_DRAFT,
                'pending' => development_plans::STATUS_PENDING,
                'active' => development_plans::STATUS_ACTIVE,
            ], '', 'id,moodleplanid');
        $planlinked = 0;
        foreach ($plans as $plan) {
            if (property_exists($plan, 'moodleplanid') && (int)$plan->moodleplanid > 0 &&
                    $DB->record_exists('competency_plan', ['id' => (int)$plan->moodleplanid])) {
                $planlinked++;
            }
        }
        return (object)[
            'roles' => count($roles),
            'rolelinked' => $rolelinked,
            'plans' => count($plans),
            'planlinked' => $planlinked,
        ];
    }

    private static function require_available(): void {
        if (!self::available()) {
            throw new \moodle_exception('moodlelearningplansdisabled', 'local_kbacorporate');
        }
    }

    private static function template_name(\stdClass $role): string {
        $prefix = '[KBA] ';
        $company = (int)$role->companyid > 0 ? 'C' . (int)$role->companyid . ' ' : 'GLOBAL ';
        $code = trim((string)$role->code);
        $name = $prefix . $company . ($code !== '' ? $code . ' — ' : '') . trim((string)$role->name);
        return \core_text::substr($name, 0, 100);
    }

    private static function template_description(\stdClass $role, string $marker): string {
        return $marker . "\n" .
            'Шаблон учебного плана KBA для бизнес-роли.' . "\n" .
            'Бизнес-роль: ' . (string)$role->name . "\n" .
            'Идентификатор компании: ' . (int)$role->companyid . "\n" .
            'KBA хранит целевой уровень, приоритет, обязательность и метаданные BMS/ERP.';
    }

    private static function plan_name(\stdClass $plan): string {
        return \core_text::substr((string)$plan->title, 0, 100);
    }

    private static function plan_description(\stdClass $plan, string $marker): string {
        return $marker . "\n" .
            'Стандартный учебный план Moodle, связанный с корпоративными данными развития KBA.' . "\n" .
            'Идентификатор компании: ' . (int)$plan->companyid . "\n" .
            'Идентификатор плана KBA: ' . (int)$plan->id . "\n" .
            'KBA хранит исходные разрывы компетенций, целевой уровень, приоритет, рекомендуемый курс, BMS-контекст и данные для интеграции с ERP.';
    }
}
