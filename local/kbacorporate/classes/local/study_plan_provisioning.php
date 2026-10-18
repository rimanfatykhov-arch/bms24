<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit provisioning workflow for KBA development plans and Moodle Learning Plans.
 *
 * The service sits between the selectable preview and the two storage layers:
 * - KBA keeps role targets, confirmed gaps, priorities, courses, deadlines and approval workflow.
 * - Moodle core keeps standard Learning Plan templates, plans and competency membership.
 *
 * Provisioning is idempotent. Repeating the operation updates the existing draft/linked plan
 * and does not create duplicate KBA items, Moodle plans or role templates.
 */
class study_plan_provisioning {
    /** Normalise a submitted competency ID list while preserving the first-seen order. */
    public static function normalise_selected_ids(array $ids): array {
        $result = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
        }
        return $result;
    }

    /** Compare expected and actual Moodle plan competency sets. */
    public static function compare_competency_sets(array $expectedids, array $actualids): \stdClass {
        $expected = array_fill_keys(self::normalise_selected_ids($expectedids), true);
        $actual = array_fill_keys(self::normalise_selected_ids($actualids), true);
        $missing = array_map('intval', array_keys(array_diff_key($expected, $actual)));
        $extra = array_map('intval', array_keys(array_diff_key($actual, $expected)));
        sort($missing, SORT_NUMERIC);
        sort($extra, SORT_NUMERIC);
        return (object)[
            'expected' => count($expected),
            'actual' => count($actual),
            'matched' => count(array_intersect_key($expected, $actual)),
            'missing' => $missing,
            'extra' => $extra,
            'ok' => !$missing && !$extra,
        ];
    }

    /**
     * Build a final read-only confirmation model from the selectable gap preview.
     *
     * Only currently eligible, confirmed positive gaps can enter the selected set. Submitted
     * competency IDs that are no longer eligible are reported as ignored and never provisioned.
     */
    public static function preview(int $companyid, int $userid, array $selectedcompetencyids): \stdClass {
        global $DB;

        if (!moodle_learning_plans::available()) {
            throw new \moodle_exception('moodlelearningplansdisabled', 'local_kbacorporate');
        }

        $selectedids = self::normalise_selected_ids($selectedcompetencyids);
        $selectedmap = array_fill_keys($selectedids, true);
        $source = development_plans::preview_gap_learning_plan($companyid, $userid);
        $eligiblemap = [];
        $rows = [];
        $withcourse = 0;
        $withoutcourse = 0;
        $mandatorywithoutcourse = 0;

        foreach ($source->rows as $row) {
            if (empty($row->eligible)) {
                continue;
            }
            $competencyid = (int)$row->competencyid;
            $eligiblemap[$competencyid] = true;
            if (!isset($selectedmap[$competencyid])) {
                continue;
            }
            $rows[] = $row;
            if ((int)$row->courseid > 0) {
                $withcourse++;
            } else {
                $withoutcourse++;
                if (!empty($row->required)) {
                    $mandatorywithoutcourse++;
                }
            }
        }

        $ignoredids = array_map('intval', array_keys(array_diff_key($selectedmap, $eligiblemap)));
        sort($ignoredids, SORT_NUMERIC);

        $templateactions = [];
        foreach ((array)$source->projection->roles as $role) {
            // Assignment projections intentionally expose only role display fields. Load the full
            // role record here so the confirmation page can report an existing Moodle template.
            $fullrole = business_roles::get_role((int)$role->id);
            $templateid = property_exists($fullrole, 'moodletemplateid') ? (int)$fullrole->moodletemplateid : 0;
            $valid = $templateid > 0 && $DB->record_exists('competency_template', ['id' => $templateid]);
            $templateactions[] = (object)[
                'roleid' => (int)$fullrole->id,
                'rolename' => (string)$fullrole->name,
                'templateid' => $valid ? $templateid : 0,
                'action' => $valid ? 'update' : 'create',
            ];
        }

        $kbaplanaction = $source->plan ? 'update' : 'create';
        $moodleplanaction = 'create';
        $linkedplan = null;
        if ($source->plan) {
            $linkedplan = moodle_learning_plans::linked_plan((int)$source->plan->id);
            if ($linkedplan) {
                $moodleplanaction = 'update';
            }
        }

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'source' => $source,
            'selectedids' => array_values(array_map(static function($row) {
                return (int)$row->competencyid;
            }, $rows)),
            'ignoredids' => $ignoredids,
            'rows' => $rows,
            'counts' => (object)[
                'selected' => count($rows),
                'ignored' => count($ignoredids),
                'eligible' => (int)$source->counts->eligible,
                'withcourse' => $withcourse,
                'withoutcourse' => $withoutcourse,
                'mandatorywithoutcourse' => $mandatorywithoutcourse,
                'roles' => count((array)$source->projection->roles),
            ],
            'templateactions' => $templateactions,
            'kbaplanaction' => $kbaplanaction,
            'moodleplanaction' => $moodleplanaction,
            'linkedplan' => $linkedplan,
            'canprovision' => !$source->locked && count($rows) > 0,
        ];
    }

    /**
     * Provision role templates, the KBA development plan and the linked Moodle Learning Plan.
     *
     * Role-template failures are isolated and returned as warnings. A KBA plan remains valid if
     * Moodle synchronisation fails; the operation can be repeated safely after fixing Moodle.
     */
    public static function provision(int $companyid, int $userid, int $actorid,
            array $selectedcompetencyids): \stdClass {
        global $DB;

        $preview = self::preview($companyid, $userid, $selectedcompetencyids);
        if (!$preview->canprovision) {
            if ($preview->source->locked) {
                throw new \moodle_exception('gaplearningplanlocked', 'local_kbacorporate');
            }
            throw new \moodle_exception('gaplearningplannoselection', 'local_kbacorporate');
        }

        $templates = [];
        $templateerrors = [];
        foreach ((array)$preview->source->projection->roles as $role) {
            try {
                $templates[] = moodle_learning_plans::ensure_role_template((int)$role->id);
            } catch (\Throwable $e) {
                $templateerrors[] = get_string('studyplanprovisiontemplateerror', 'local_kbacorporate', (object)[
                    'role' => (string)$role->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $build = development_plans::build_gap_learning_plan(
            $companyid,
            $userid,
            $actorid,
            $preview->selectedids
        );

        $plan = development_plans::get_plan((int)$build->planid);
        $moodleplan = moodle_learning_plans::linked_plan((int)$build->planid);
        $moodleerror = (string)($build->moodleerror ?? '');
        $verification = (object)[
            'expected' => count(development_plans::get_items((int)$build->planid)),
            'actual' => 0,
            'matched' => 0,
            'missing' => [],
            'extra' => [],
            'ok' => false,
        ];
        $ownerok = false;
        $status = null;

        if ($moodleplan) {
            $ownerok = (int)$moodleplan->get('userid') === $userid;
            $status = (int)$moodleplan->get('status');
            $expectedids = [];
            foreach (development_plans::get_items((int)$build->planid) as $item) {
                $expectedids[] = (int)$item->competencyid;
            }
            $actualids = moodle_learning_plans::plan_competency_ids((int)$moodleplan->get('id'));
            $verification = self::compare_competency_sets($expectedids, $actualids);
        }

        $complete = $moodleplan && $ownerok && $verification->ok && $moodleerror === '' && !$templateerrors;
        $warnings = $templateerrors;
        if ($moodleerror !== '') {
            $warnings[] = $moodleerror;
        }
        if ($moodleplan && !$ownerok) {
            $warnings[] = get_string('studyplanprovisionownererror', 'local_kbacorporate');
        }
        if ($moodleplan && !$verification->ok) {
            $warnings[] = get_string('studyplanprovisionverificationerror', 'local_kbacorporate', (object)[
                'missing' => count($verification->missing),
                'extra' => count($verification->extra),
            ]);
        }

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'kbaplanid' => (int)$build->planid,
            'moodleplanid' => $moodleplan ? (int)$moodleplan->get('id') : 0,
            'created' => !empty($build->created),
            'added' => (int)$build->added,
            'updated' => (int)$build->updated,
            'unchanged' => (int)$build->unchanged,
            'selected' => (int)$build->selected,
            'skipped' => (int)$build->skipped,
            'retained' => (int)$build->retained,
            'templates' => $templates,
            'templateerrors' => $templateerrors,
            'moodlecreated' => !empty($build->moodle) && !empty($build->moodle->created),
            'moodleadded' => !empty($build->moodle) ? (int)$build->moodle->added : 0,
            'moodleremoved' => !empty($build->moodle) ? (int)$build->moodle->removed : 0,
            'moodlecompetencies' => !empty($build->moodle) ? (int)$build->moodle->competencies : (int)$verification->actual,
            'moodlestatus' => $status,
            'verification' => $verification,
            'ownerok' => $ownerok,
            'complete' => (bool)$complete,
            'warnings' => $warnings,
            'timecompleted' => time(),
            'planstatus' => (string)$plan->status,
        ];
    }
}
