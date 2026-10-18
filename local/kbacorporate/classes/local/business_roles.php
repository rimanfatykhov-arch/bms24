<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Business-role domain service.
 *
 * These roles describe a person's business function and competency target profile.
 * They are intentionally separate from Moodle roles and from corporate access flags.
 */
class business_roles {
    public const PRIORITY_CRITICAL = 'critical';
    public const PRIORITY_CORE = 'core';
    public const PRIORITY_ADDITIONAL = 'additional';

    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['local_kbacorp_busrole', 'local_kbacorp_rolecomp', 'local_kbacorp_member_busrole'] as $name) {
            if (!$dbman->table_exists(new \xmldb_table($name))) {
                return false;
            }
        }
        return true;
    }

    public static function normalise_code(string $code): string {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new \invalid_parameter_exception('Business role code is required.');
        }
        if (strpos($code, 'BMS-ROLE-') !== 0) {
            throw new \invalid_parameter_exception('Business role code must start with BMS-ROLE-.');
        }
        return $code;
    }

    public static function save_role(\stdClass $data): int {
        global $DB;
        $now = time();
        $id = max(0, (int)($data->id ?? 0));
        $existing = $id ? $DB->get_record('local_kbacorp_busrole', ['id' => $id], '*', MUST_EXIST) : null;
        $companyid = max(0, (int)($data->companyid ?? 0));
        if ($existing && (int)$existing->companyid !== $companyid) {
            throw new \invalid_parameter_exception('Business role company scope cannot be changed.');
        }
        $code = self::normalise_code((string)($data->code ?? ''));
        $name = trim((string)($data->name ?? ''));
        if (\core_text::strlen($code) > 100 || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception('Business role code or name is too long.');
        }
        if ($DB->record_exists_select(
                'local_kbacorp_busrole',
                'companyid = :companyid AND code = :code AND id <> :id',
                ['companyid' => $companyid, 'code' => $code, 'id' => $id]
        )) {
            throw new \invalid_parameter_exception('Business role code already exists in this company scope.');
        }
        $record = (object)[
            'companyid' => $companyid,
            'code' => $code,
            'uuid' => trim((string)($data->uuid ?? ($existing->uuid ?? ''))),
            'name' => $name,
            'description' => trim((string)($data->description ?? '')) ?: null,
            'active' => empty($data->active) ? 0 : 1,
            'source' => self::normalise_source((string)($data->source ?? ($existing->source ?? 'manual'))),
            'templateid' => max(0, (int)($data->templateid ?? ($existing->templateid ?? 0))),
            'templateversion' => max(1, (int)($data->templateversion ??
                ($existing->templateversion ?? 1))),
            'appliedversion' => max(0, (int)($data->appliedversion ??
                ($existing->appliedversion ?? 0))),
            'timemodified' => $now,
        ];
        if ($record->name === '') {
            throw new \invalid_parameter_exception('Business role name is required.');
        }
        if ($record->uuid === '') {
            $record->uuid = self::uuid_v4();
        }
        if ($existing) {
            if ($companyid === 0) {
                $record->templateversion = max(1, (int)$existing->templateversion + 1);
            }
            $record->id = $id;
            $DB->update_record('local_kbacorp_busrole', $record);
            if ($companyid === 0) {
                self::capture_template_version(
                    $record->id,
                    (string)($data->changenote ?? ''),
                    (int)($data->changedby ?? 0)
                );
            }
            return $record->id;
        }
        $record->timecreated = $now;
        $record->id = (int)$DB->insert_record('local_kbacorp_busrole', $record);
        if ($companyid === 0) {
            self::capture_template_version(
                $record->id,
                (string)($data->changenote ?? ''),
                (int)($data->changedby ?? 0)
            );
        }
        return $record->id;
    }

    /** Return global roles plus roles belonging to one explicit company. */
    public static function get_catalog(int $companyid, bool $includeinactive = false): array {
        global $DB;
        if (!self::available() || $companyid < 0) {
            return [];
        }
        $params = $companyid > 0 ? ['companyid' => $companyid] : [];
        $where = $companyid > 0 ? '(companyid = 0 OR companyid = :companyid)' : 'companyid = 0';
        if (!$includeinactive) {
            $where .= ' AND active = 1';
        }
        return array_values($DB->get_records_select(
            'local_kbacorp_busrole',
            $where,
            $params,
            'companyid ASC, name ASC'
        ));
    }

    public static function get_role(int $roleid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacorp_busrole', ['id' => $roleid], '*', MUST_EXIST);
    }

    /** Global roles are the centrally maintained role-template library. */
    public static function get_templates(bool $includeinactive = false): array {
        global $DB;
        $where = 'companyid = 0';
        if (!$includeinactive) {
            $where .= ' AND active = 1';
        }
        return array_values($DB->get_records_select(
            'local_kbacorp_busrole', $where, [], 'name ASC'
        ));
    }

    /** Return company roles derived from one or more templates. */
    public static function get_template_copies(int $companyid): array {
        global $DB;
        if ($companyid <= 0) {
            return [];
        }
        return array_values($DB->get_records_select(
            'local_kbacorp_busrole',
            'companyid = :companyid AND templateid > 0',
            ['companyid' => $companyid],
            'name ASC'
        ));
    }

    /** Return every company role derived from one global template. */
    public static function get_template_rollout(int $templateid): array {
        global $DB;
        $template = $DB->get_record('local_kbacorp_busrole', [
            'id' => $templateid,
            'companyid' => 0,
        ], '*', MUST_EXIST);
        $sql = "SELECT r.id, r.companyid, r.name, r.code, r.active,
                       r.appliedversion, r.timemodified, c.name AS companyname
                  FROM {local_kbacorp_busrole} r
                  JOIN {local_kbacorp_company} c ON c.id = r.companyid
                 WHERE r.templateid = :templateid
              ORDER BY c.name ASC, r.name ASC";
        $records = array_values($DB->get_records_sql($sql, ['templateid' => $templateid]));
        foreach ($records as $record) {
            $record->availableversion = (int)$template->templateversion;
            $record->updateavailable = (int)$record->appliedversion < (int)$template->templateversion;
        }
        return $records;
    }

    /**
     * Create an independent global template from an existing company role.
     *
     * The source role, its employee assignments and its requirement sources
     * remain untouched. The initial template starts at version 1.
     */
    public static function create_template_from_company_role(int $roleid): int {
        global $DB;
        $source = self::get_role($roleid);
        if ((int)$source->companyid <= 0) {
            throw new \moodle_exception('roletemplatecompanyrequired', 'local_kbacorporate');
        }
        if ($DB->record_exists('local_kbacorp_busrole', [
            'companyid' => 0,
            'code' => $source->code,
        ])) {
            throw new \moodle_exception('roletemplateglobalcodeexists', 'local_kbacorporate', '', $source->code);
        }

        $transaction = $DB->start_delegated_transaction();
        $templateid = self::save_role((object)[
            'companyid' => 0,
            'code' => $source->code,
            'name' => $source->name,
            'description' => $source->description,
            'active' => $source->active,
            'source' => 'company_role',
            'templateversion' => 1,
        ]);
        $now = time();
        foreach (self::get_role_competencies($roleid) as $requirement) {
            if (self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $DB->insert_record('local_kbacorp_rolecomp', (object)[
                'roleid' => $templateid,
                'competencyid' => (int)$requirement->competencyid,
                'targetlevel' => (int)$requirement->targetlevel,
                'priority' => self::normalise_priority((string)$requirement->priority),
                'required' => !empty($requirement->required) ? 1 : 0,
                'source' => 'manual',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        self::capture_template_version($templateid, '', 0);
        $transaction->allow_commit();
        return $templateid;
    }

    /**
     * Create an independent company role and copy the template requirements.
     *
     * Subsequent company edits mark an individual requirement as manual and
     * are therefore protected from future template updates.
     */
    public static function create_from_template(int $templateid, int $companyid): int {
        global $DB;
        if ($companyid <= 0 || !$DB->record_exists('local_kbacorp_company', ['id' => $companyid])) {
            throw new \invalid_parameter_exception('Unknown target company.');
        }
        $template = $DB->get_record('local_kbacorp_busrole', [
            'id' => $templateid,
            'companyid' => 0,
            'active' => 1,
        ], '*', MUST_EXIST);
        $existing = $DB->get_record('local_kbacorp_busrole', [
            'companyid' => $companyid,
            'code' => $template->code,
        ]);
        if ($existing) {
            if ((int)$existing->templateid === $templateid) {
                return (int)$existing->id;
            }
            throw new \moodle_exception('roletemplatecodeconflict', 'local_kbacorporate');
        }

        $transaction = $DB->start_delegated_transaction();
        $roleid = self::save_role((object)[
            'companyid' => $companyid,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'active' => 1,
            'source' => 'template',
            'templateid' => $templateid,
            'templateversion' => 1,
            'appliedversion' => max(1, (int)$template->templateversion),
        ]);
        foreach (self::get_role_competencies($templateid) as $requirement) {
            if (self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            self::set_role_competency(
                $roleid,
                (int)$requirement->competencyid,
                (int)$requirement->targetlevel,
                (string)$requirement->priority,
                !empty($requirement->required),
                'template'
            );
        }
        $transaction->allow_commit();
        return $roleid;
    }

    /**
     * Apply a newer template non-destructively.
     *
     * Template-sourced requirements are updated, missing requirements are
     * added, manual overrides and company-only requirements are preserved,
     * and no requirement is silently removed.
     */
    public static function apply_template_update(int $roleid): \stdClass {
        global $DB;
        $role = self::get_role($roleid);
        if ((int)$role->companyid <= 0 || (int)$role->templateid <= 0) {
            throw new \moodle_exception('rolenotfromtemplate', 'local_kbacorporate');
        }
        $template = $DB->get_record('local_kbacorp_busrole', [
            'id' => (int)$role->templateid,
            'companyid' => 0,
        ], '*', MUST_EXIST);
        $result = (object)['added' => 0, 'updated' => 0, 'preserved' => 0];
        $transaction = $DB->start_delegated_transaction();
        foreach (self::get_role_competencies((int)$template->id) as $requirement) {
            if (self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $existing = $DB->get_record('local_kbacorp_rolecomp', [
                'roleid' => $roleid,
                'competencyid' => (int)$requirement->competencyid,
            ]);
            if ($existing && $existing->source !== 'template') {
                $result->preserved++;
                continue;
            }
            self::set_role_competency(
                $roleid,
                (int)$requirement->competencyid,
                (int)$requirement->targetlevel,
                (string)$requirement->priority,
                !empty($requirement->required),
                'template'
            );
            if ($existing) {
                $result->updated++;
            } else {
                $result->added++;
            }
        }
        $role->appliedversion = max(1, (int)$template->templateversion);
        $role->timemodified = time();
        $DB->update_record('local_kbacorp_busrole', $role);
        $transaction->allow_commit();
        return $result;
    }

    /** Preview a template update without changing the company role. */
    public static function preview_template_update(int $roleid): \stdClass {
        global $DB;
        $role = self::get_role($roleid);
        if ((int)$role->companyid <= 0 || (int)$role->templateid <= 0) {
            throw new \moodle_exception('rolenotfromtemplate', 'local_kbacorporate');
        }
        $template = $DB->get_record('local_kbacorp_busrole', [
            'id' => (int)$role->templateid,
            'companyid' => 0,
        ], '*', MUST_EXIST);
        $current = [];
        foreach (self::get_role_competencies($roleid) as $item) {
            $current[(int)$item->competencyid] = $item;
        }
        $incoming = [];
        foreach (self::get_role_competencies((int)$template->id) as $item) {
            if (self::is_domain_competency_code((string)$item->idnumber)) {
                continue;
            }
            $incoming[(int)$item->competencyid] = $item;
        }
        $result = (object)[
            'role' => $role,
            'template' => $template,
            'added' => [],
            'changed' => [],
            'preserved' => [],
            'retained' => [],
        ];
        foreach ($incoming as $competencyid => $item) {
            if (!isset($current[$competencyid])) {
                $result->added[] = $item;
                continue;
            }
            $existing = $current[$competencyid];
            if ((string)$existing->source !== 'template') {
                $item->previous = $existing;
                $result->preserved[] = $item;
                continue;
            }
            if ((int)$existing->targetlevel !== (int)$item->targetlevel ||
                    (string)$existing->priority !== (string)$item->priority ||
                    (int)$existing->required !== (int)$item->required) {
                $item->previous = $existing;
                $result->changed[] = $item;
            }
        }
        foreach ($current as $competencyid => $item) {
            if (!isset($incoming[$competencyid])) {
                $result->retained[] = $item;
            }
        }
        return $result;
    }

    /** Whether an idnumber identifies a BMS grouping/domain node rather than an actionable competency. */
    public static function is_domain_competency_code(string $idnumber): bool {
        return (bool)preg_match('/^BMS-D\\d{2}$/i', trim($idnumber));
    }

    /**
     * Explicitly replace BMS-Dxx role requirements with their direct child competencies.
     *
     * Existing child requirements are preserved. A domain requirement is removed only when
     * at least one direct non-domain child exists. No change happens automatically on upgrade.
     */
    public static function expand_domain_competencies(int $roleid, int $changedby = 0): \stdClass {
        global $DB;
        $role = self::get_role($roleid);
        $requirements = self::get_role_competencies($roleid);
        $result = (object)[
            'domains' => 0,
            'added' => 0,
            'existing' => 0,
            'removed' => 0,
            'skipped' => 0,
        ];
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($requirements as $requirement) {
            if (!self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $result->domains++;
            $children = $DB->get_records('competency', [
                'parentid' => (int)$requirement->competencyid,
            ], 'shortname ASC', 'id,idnumber,shortname');
            $children = array_values(array_filter($children, static function($child) {
                return !self::is_domain_competency_code((string)$child->idnumber);
            }));
            if (!$children) {
                $result->skipped++;
                continue;
            }
            foreach ($children as $child) {
                if ($DB->record_exists('local_kbacorp_rolecomp', [
                    'roleid' => $roleid,
                    'competencyid' => (int)$child->id,
                ])) {
                    $result->existing++;
                    continue;
                }
                $DB->insert_record('local_kbacorp_rolecomp', (object)[
                    'roleid' => $roleid,
                    'competencyid' => (int)$child->id,
                    'targetlevel' => (int)$requirement->targetlevel,
                    'priority' => self::normalise_priority((string)$requirement->priority),
                    'required' => !empty($requirement->required) ? 1 : 0,
                    'source' => 'domain-expansion',
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $result->added++;
            }
            $DB->delete_records('local_kbacorp_rolecomp', ['id' => (int)$requirement->id]);
            $result->removed++;
        }
        if ($result->added > 0 || $result->removed > 0) {
            self::touch_template_version(
                $roleid,
                get_string('roleprofiledomainexpansionnote', 'local_kbacorporate'),
                $changedby
            );
        }
        $transaction->allow_commit();
        return $result;
    }

    /**
     * Selectively replace BMS-Dxx role requirements with chosen direct child competencies.
     *
     * $selections is keyed by competency id. Each item may contain targetlevel, priority and required.
     * Existing concrete role requirements are preserved and are never overwritten by this migration.
     * A source domain is removed only when it was explicitly requested for removal and at least one
     * direct concrete child is present in the role after the operation.
     */
    public static function replace_domain_competencies_selective(int $roleid, array $selections,
            array $removedomainids = [], int $changedby = 0): \stdClass {
        global $DB;

        self::get_role($roleid); // Validate role early.
        $requirements = self::get_role_competencies($roleid);
        $domainsbycomp = [];
        $domainsbyrow = [];
        foreach ($requirements as $requirement) {
            if (!self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $domainsbycomp[(int)$requirement->competencyid] = $requirement;
            $domainsbyrow[(int)$requirement->id] = $requirement;
        }

        $result = (object)[
            'selected' => 0,
            'added' => 0,
            'existing' => 0,
            'removed' => 0,
            'kept' => 0,
            'skipped' => 0,
        ];
        if (!$domainsbycomp) {
            return $result;
        }

        $removedomainids = array_values(array_unique(array_map('intval', $removedomainids)));
        $now = time();
        $transaction = $DB->start_delegated_transaction();

        foreach ($selections as $competencyid => $settings) {
            $competencyid = (int)$competencyid;
            if ($competencyid <= 0) {
                $result->skipped++;
                continue;
            }
            $child = $DB->get_record('competency', ['id' => $competencyid],
                'id,parentid,idnumber,shortname');
            if (!$child || self::is_domain_competency_code((string)$child->idnumber) ||
                    empty($domainsbycomp[(int)$child->parentid])) {
                // Never allow a crafted request to add a competency outside one of the source domains.
                $result->skipped++;
                continue;
            }
            $result->selected++;
            if ($DB->record_exists('local_kbacorp_rolecomp', [
                    'roleid' => $roleid,
                    'competencyid' => $competencyid,
                ])) {
                $result->existing++;
                continue;
            }

            $domain = $domainsbycomp[(int)$child->parentid];
            $targetlevel = isset($settings['targetlevel']) ? (int)$settings['targetlevel'] :
                (int)$domain->targetlevel;
            if ($targetlevel < 0 || $targetlevel > 99) {
                $result->skipped++;
                continue;
            }
            $priority = isset($settings['priority']) ? self::normalise_priority((string)$settings['priority']) :
                self::normalise_priority((string)$domain->priority);
            $required = array_key_exists('required', $settings) ? !empty($settings['required']) :
                !empty($domain->required);

            $DB->insert_record('local_kbacorp_rolecomp', (object)[
                'roleid' => $roleid,
                'competencyid' => $competencyid,
                'targetlevel' => $targetlevel,
                'priority' => $priority,
                'required' => $required ? 1 : 0,
                'source' => 'domain-selective',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $result->added++;
        }

        foreach ($domainsbyrow as $rowid => $domain) {
            if (!in_array($rowid, $removedomainids, true)) {
                $result->kept++;
                continue;
            }
            $sql = "SELECT 1
                      FROM {local_kbacorp_rolecomp} rc
                      JOIN {competency} c ON c.id = rc.competencyid
                     WHERE rc.roleid = :roleid
                       AND c.parentid = :parentid";
            $hasconcretechild = $DB->record_exists_sql($sql, [
                'roleid' => $roleid,
                'parentid' => (int)$domain->competencyid,
            ]);
            if (!$hasconcretechild) {
                $result->skipped++;
                $result->kept++;
                continue;
            }
            $DB->delete_records('local_kbacorp_rolecomp', ['id' => $rowid]);
            $result->removed++;
        }

        if ($result->added > 0 || $result->removed > 0) {
            self::touch_template_version(
                $roleid,
                get_string('roleprofileselectivemigrationnote', 'local_kbacorporate'),
                $changedby
            );
        }
        $transaction->allow_commit();
        return $result;
    }

    /**
     * Bulk-update target level, priority and mandatory flag for concrete role competencies.
     *
     * Existing source metadata and competency membership are preserved. The operation is
     * intentionally non-destructive and updates only rows already belonging to the role.
     * For a global template, the template version is touched once after the whole batch.
     *
     * @param int $roleid Business-role id.
     * @param array $updates Keyed by local_kbacorp_rolecomp row id.
     * @param string $changenote Version note for a global template.
     * @param int $changedby User id responsible for the change.
     * @return \stdClass Summary counters after the operation.
     */
    public static function bulk_update_role_competencies(int $roleid, array $updates,
            string $changenote = '', int $changedby = 0): \stdClass {
        global $DB;

        self::get_role($roleid); // Validate role early.
        $requirements = self::get_role_competencies($roleid);
        $allowed = [];
        foreach ($requirements as $requirement) {
            if (self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            $allowed[(int)$requirement->id] = $requirement;
        }

        $result = (object)[
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'mandatory' => 0,
            'supporting' => 0,
            'critical' => 0,
        ];
        $transaction = $DB->start_delegated_transaction();
        $now = time();

        foreach ($updates as $rowid => $settings) {
            $rowid = (int)$rowid;
            if (empty($allowed[$rowid])) {
                $result->skipped++;
                continue;
            }
            $existing = $allowed[$rowid];
            $targetlevel = isset($settings['targetlevel']) ? (int)$settings['targetlevel'] :
                (int)$existing->targetlevel;
            if ($targetlevel < 0 || $targetlevel > 9) {
                throw new \invalid_parameter_exception('Target level is out of range.');
            }
            $priority = isset($settings['priority']) ? self::normalise_priority((string)$settings['priority']) :
                self::normalise_priority((string)$existing->priority);
            $required = !empty($settings['required']) ? 1 : 0;

            if ((int)$existing->targetlevel === $targetlevel &&
                    (string)$existing->priority === $priority &&
                    (int)$existing->required === $required) {
                $result->unchanged++;
                continue;
            }

            $DB->update_record('local_kbacorp_rolecomp', (object)[
                'id' => $rowid,
                'targetlevel' => $targetlevel,
                'priority' => $priority,
                'required' => $required,
                'timemodified' => $now,
            ]);
            $result->changed++;
        }

        // Recalculate the post-update summary inside the same transaction.
        $final = self::get_role_competencies($roleid);
        foreach ($final as $requirement) {
            if (self::is_domain_competency_code((string)$requirement->idnumber)) {
                continue;
            }
            if (!empty($requirement->required)) {
                $result->mandatory++;
            } else {
                $result->supporting++;
            }
            if ((string)$requirement->priority === self::PRIORITY_CRITICAL) {
                $result->critical++;
            }
        }

        if ($result->changed > 0) {
            self::touch_template_version($roleid, $changenote, $changedby);
        }
        $transaction->allow_commit();
        return $result;
    }

    /** Return the competency target profile configured for a role. */
    public static function get_role_competencies(int $roleid): array {
        global $DB;
        $sql = "SELECT rc.*, c.shortname, c.idnumber,
                       c.scaleid AS competencyscaleid,
                       f.shortname AS frameworkname,
                       f.scaleid AS frameworkscaleid
                  FROM {local_kbacorp_rolecomp} rc
                  JOIN {competency} c ON c.id = rc.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                 WHERE rc.roleid = :roleid
              ORDER BY f.shortname ASC, c.shortname ASC";
        return array_values($DB->get_records_sql($sql, ['roleid' => $roleid]));
    }

    /** Explicitly remove one competency requirement from one role. */
    public static function remove_role_competency(int $roleid, int $rolecompetencyid,
            string $changenote = '', int $changedby = 0): void {
        global $DB;
        $record = $DB->get_record('local_kbacorp_rolecomp', [
            'id' => $rolecompetencyid,
            'roleid' => $roleid,
        ], 'id', MUST_EXIST);
        $DB->delete_records('local_kbacorp_rolecomp', ['id' => $record->id]);
        self::touch_template_version($roleid, $changenote, $changedby);
    }

    public static function set_role_competency(int $roleid, int $competencyid, int $targetlevel,
            string $priority = self::PRIORITY_CORE, bool $required = true, string $source = 'manual',
            string $changenote = '', int $changedby = 0): int {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_busrole', ['id' => $roleid])) {
            throw new \invalid_parameter_exception('Unknown business role.');
        }
        $competency = $DB->get_record('competency', ['id' => $competencyid], 'id,idnumber');
        if (!$competency) {
            throw new \invalid_parameter_exception('Unknown competency.');
        }
        if (self::is_domain_competency_code((string)$competency->idnumber)) {
            throw new \moodle_exception('roleprofiledomainnodeblocked', 'local_kbacorporate');
        }
        if ($targetlevel < 0 || $targetlevel > 99) {
            throw new \invalid_parameter_exception('Target level is out of range.');
        }
        $priority = self::normalise_priority($priority);
        $now = time();
        $existing = $DB->get_record('local_kbacorp_rolecomp', [
            'roleid' => $roleid,
            'competencyid' => $competencyid,
        ]);
        $record = (object)[
            'roleid' => $roleid,
            'competencyid' => $competencyid,
            'targetlevel' => $targetlevel,
            'priority' => $priority,
            'required' => $required ? 1 : 0,
            'source' => self::normalise_source($source),
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record('local_kbacorp_rolecomp', $record);
            self::touch_template_version($roleid, $changenote, $changedby);
            return $record->id;
        }
        $record->timecreated = $now;
        $id = (int)$DB->insert_record('local_kbacorp_rolecomp', $record);
        self::touch_template_version($roleid, $changenote, $changedby);
        return $id;
    }

    /** Store or refresh the immutable snapshot for the template's current version. */
    public static function capture_template_version(int $templateid, string $changenote = '',
            int $createdby = 0): int {
        global $DB, $USER;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table('local_kbacorp_rolever')) ||
                !$dbman->table_exists(new \xmldb_table('local_kbacorp_rolevercomp'))) {
            return 0;
        }
        $template = $DB->get_record('local_kbacorp_busrole', [
            'id' => $templateid,
            'companyid' => 0,
        ], '*', MUST_EXIST);
        if ($createdby <= 0 && !empty($USER->id)) {
            $createdby = (int)$USER->id;
        }
        $changenote = trim($changenote);
        if (\core_text::strlen($changenote) > 255) {
            $changenote = \core_text::substr($changenote, 0, 255);
        }
        $transaction = $DB->start_delegated_transaction();
        $snapshot = $DB->get_record('local_kbacorp_rolever', [
            'templateid' => $templateid,
            'version' => (int)$template->templateversion,
        ]);
        $record = (object)[
            'templateid' => $templateid,
            'version' => (int)$template->templateversion,
            'name' => $template->name,
            'description' => $template->description,
            'active' => (int)$template->active,
            'changenote' => $changenote !== '' ? $changenote : null,
            'createdby' => max(0, $createdby),
            'timecreated' => time(),
        ];
        if ($snapshot) {
            $record->id = (int)$snapshot->id;
            if ($changenote === '' && !empty($snapshot->changenote)) {
                $record->changenote = $snapshot->changenote;
            }
            if ($createdby <= 0) {
                $record->createdby = (int)$snapshot->createdby;
            }
            $record->timecreated = (int)$snapshot->timecreated;
            $DB->update_record('local_kbacorp_rolever', $record);
            $versionid = $record->id;
            $DB->delete_records('local_kbacorp_rolevercomp', ['roleversionid' => $versionid]);
        } else {
            $versionid = (int)$DB->insert_record('local_kbacorp_rolever', $record);
        }
        foreach (self::get_role_competencies($templateid) as $requirement) {
            $DB->insert_record('local_kbacorp_rolevercomp', (object)[
                'roleversionid' => $versionid,
                'competencyid' => (int)$requirement->competencyid,
                'targetlevel' => (int)$requirement->targetlevel,
                'priority' => (string)$requirement->priority,
                'required' => !empty($requirement->required) ? 1 : 0,
            ]);
        }
        $transaction->allow_commit();
        return $versionid;
    }

    /** Return template version metadata newest first. */
    public static function get_template_versions(int $templateid): array {
        global $DB;
        self::get_role($templateid);
        return array_values($DB->get_records(
            'local_kbacorp_rolever', ['templateid' => $templateid], 'version DESC'
        ));
    }

    /** Return one version's competency snapshot with display metadata. */
    public static function get_template_version_competencies(int $versionid): array {
        global $DB;
        $sql = "SELECT vc.*, c.shortname, c.idnumber, f.shortname AS frameworkname
                  FROM {local_kbacorp_rolevercomp} vc
                  JOIN {competency} c ON c.id = vc.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                 WHERE vc.roleversionid = :versionid
              ORDER BY f.shortname ASC, c.shortname ASC";
        return array_values($DB->get_records_sql($sql, ['versionid' => $versionid]));
    }

    /** Compare two stored template snapshots. */
    public static function compare_template_versions(int $fromversionid, int $toversionid): \stdClass {
        global $DB;
        $fromversion = $DB->get_record('local_kbacorp_rolever', ['id' => $fromversionid], '*', MUST_EXIST);
        $toversion = $DB->get_record('local_kbacorp_rolever', ['id' => $toversionid], '*', MUST_EXIST);
        if ((int)$fromversion->templateid !== (int)$toversion->templateid) {
            throw new \invalid_parameter_exception('Template versions belong to different templates.');
        }
        $from = [];
        foreach (self::get_template_version_competencies($fromversionid) as $item) {
            $from[(int)$item->competencyid] = $item;
        }
        $to = [];
        foreach (self::get_template_version_competencies($toversionid) as $item) {
            $to[(int)$item->competencyid] = $item;
        }
        $result = (object)['added' => [], 'changed' => [], 'removed' => []];
        foreach ($to as $competencyid => $item) {
            if (!isset($from[$competencyid])) {
                $result->added[] = $item;
                continue;
            }
            $old = $from[$competencyid];
            if ((int)$old->targetlevel !== (int)$item->targetlevel ||
                    (string)$old->priority !== (string)$item->priority ||
                    (int)$old->required !== (int)$item->required) {
                $item->previous = $old;
                $result->changed[] = $item;
            }
        }
        foreach ($from as $competencyid => $item) {
            if (!isset($to[$competencyid])) {
                $result->removed[] = $item;
            }
        }
        return $result;
    }

    public static function assign_user_role(int $companyid, int $userid, int $roleid,
            string $source = 'manual', string $externalref = '', bool $preserveexistingmetadata = false): int {
        global $DB;
        if (!$DB->record_exists('local_kbacorp_member', ['companyid' => $companyid, 'userid' => $userid])) {
            throw new \invalid_parameter_exception('User is not a member of the company.');
        }
        $role = $DB->get_record('local_kbacorp_busrole', ['id' => $roleid], '*', MUST_EXIST);
        if ((int)$role->companyid !== 0 && (int)$role->companyid !== $companyid) {
            throw new \invalid_parameter_exception('Business role belongs to another company.');
        }
        $now = time();
        $existing = $DB->get_record('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'userid' => $userid,
            'roleid' => $roleid,
        ]);
        if ($existing) {
            $wasactive = !empty($existing->active);
            if ($wasactive && $preserveexistingmetadata) {
                return (int)$existing->id;
            }
            if (!$preserveexistingmetadata) {
                $existing->source = self::normalise_source($source);
                $existing->externalref = trim($externalref) ?: null;
            }
            $existing->active = 1;
            if (!$wasactive) {
                $existing->effectivefrom = $now;
            }
            $existing->effectiveto = 0;
            $existing->timemodified = $now;
            $DB->update_record('local_kbacorp_member_busrole', $existing);
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_kbacorp_member_busrole', (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'roleid' => $roleid,
            'source' => self::normalise_source($source),
            'externalref' => trim($externalref) ?: null,
            'active' => 1,
            'effectivefrom' => $now,
            'effectiveto' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /** Deactivate one explicit assignment while retaining its history. */
    public static function deactivate_user_role(int $companyid, int $assignmentid): void {
        global $DB;
        $assignment = $DB->get_record('local_kbacorp_member_busrole', [
            'id' => $assignmentid,
            'companyid' => $companyid,
        ], '*', MUST_EXIST);
        $assignment->active = 0;
        $assignment->effectiveto = time();
        $assignment->timemodified = time();
        $DB->update_record('local_kbacorp_member_busrole', $assignment);
    }

    /** Return active role assignments inside one company. */
    public static function get_company_assignments(int $companyid, int $userid = 0): array {
        global $DB;
        if (!self::available() || $companyid <= 0) {
            return [];
        }
        $params = ['companyid' => $companyid];
        $userwhere = '';
        if ($userid > 0) {
            $userwhere = ' AND a.userid = :userid';
            $params['userid'] = $userid;
        }
        $sql = "SELECT a.*, r.code, r.name AS rolename, r.companyid AS rolecompanyid,
                       u.firstname, u.lastname, u.email
                  FROM {local_kbacorp_member_busrole} a
                  JOIN {local_kbacorp_busrole} r ON r.id = a.roleid
                  JOIN {user} u ON u.id = a.userid
                 WHERE a.companyid = :companyid
                   AND a.active = 1
                   AND r.active = 1
                   AND (r.companyid = 0 OR r.companyid = a.companyid)
                   {$userwhere}
              ORDER BY u.lastname ASC, u.firstname ASC, r.name ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    public static function get_user_roles(int $userid, int $companyid): array {
        global $DB;
        if (!self::available() || $companyid <= 0) {
            return [];
        }
        $now = time();
        $params = [
            'userid' => $userid,
            'companyid' => $companyid,
            'effectivefromnow' => $now,
            'effectivetonow' => $now,
        ];
        $sql = "SELECT ur.id AS assignmentid, ur.companyid, ur.source AS assignmentsource,
                       r.id, r.code, r.uuid, r.name, r.description, r.active, r.source
                  FROM {local_kbacorp_member_busrole} ur
                  JOIN {local_kbacorp_busrole} r ON r.id = ur.roleid
                 WHERE ur.userid = :userid
                   AND ur.active = 1
                   AND r.active = 1
                   AND ur.companyid = :companyid
                   AND (r.companyid = 0 OR r.companyid = ur.companyid)
                   AND (ur.effectivefrom = 0 OR ur.effectivefrom <= :effectivefromnow)
                   AND (ur.effectiveto = 0 OR ur.effectiveto >= :effectivetonow)
              ORDER BY r.name";
        return array_values($DB->get_records_sql($sql, $params));
    }

    public static function normalise_priority(string $priority): string {
        $priority = strtolower(trim($priority));
        return in_array($priority, [self::PRIORITY_CRITICAL, self::PRIORITY_CORE, self::PRIORITY_ADDITIONAL], true)
            ? $priority : self::PRIORITY_CORE;
    }

    private static function normalise_source(string $source): string {
        $source = strtolower(trim($source));
        return $source === '' ? 'manual' : substr($source, 0, 50);
    }

    /** Increment only centrally maintained global template versions. */
    private static function touch_template_version(int $roleid, string $changenote = '', int $changedby = 0): void {
        global $DB;
        $role = $DB->get_record('local_kbacorp_busrole', ['id' => $roleid], 'id,companyid,templateversion');
        if ($role && (int)$role->companyid === 0) {
            $DB->set_field(
                'local_kbacorp_busrole',
                'templateversion',
                max(1, (int)$role->templateversion + 1),
                ['id' => $roleid]
            );
            $DB->set_field('local_kbacorp_busrole', 'timemodified', time(), ['id' => $roleid]);
            self::capture_template_version($roleid, $changenote, $changedby);
        }
    }

    private static function uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
