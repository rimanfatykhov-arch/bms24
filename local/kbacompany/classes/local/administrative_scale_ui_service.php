<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Presentation schema for the Administrative Scale CRM-style UI.
 *
 * This class owns field composition and project-card presentation only. Business validation and persistence
 * remain in administrative_scale_service.
 */
class administrative_scale_ui_service {
    /** @var string[] */
    private const VALID_KINDS = [
        'longtermgoal', 'goal', 'policy', 'plan', 'program', 'programstep',
        'project', 'decision', 'task', 'idealstate', 'statistic', 'ckp',
    ];

    /**
     * Return EditView/DetailView section schema for one Administrative Scale kind.
     *
     * @param string $kind
     * @return array
     */
    public static function schema(string $kind): array {
        if (!in_array($kind, self::VALID_KINDS, true)) {
            throw new \invalid_parameter_exception('Unknown Administrative Scale kind');
        }

        $basic = ['code', 'name'];
        if ($kind !== 'longtermgoal') {
            $basic[] = 'parentuuid';
        }
        if (in_array($kind, ['longtermgoal', 'goal', 'policy', 'decision'], true)) {
            $basic[] = 'sourceuuid';
        }
        $basic[] = 'ownerpositionuuid';

        $definition = [];
        switch ($kind) {
            case 'plan':
                $definition = ['situation', 'plangoal', 'description'];
                break;
            case 'statistic':
                $definition = ['description', 'metricuuid', 'targetvalue'];
                break;
            case 'ckp':
                $definition = ['description', 'expectedresult', 'productuuid'];
                break;
            case 'idealstate':
                $definition = ['description'];
                break;
            case 'policy':
                $definition = ['description'];
                break;
            default:
                $definition = ['description'];
                if (in_array($kind,
                        ['longtermgoal', 'goal', 'program', 'programstep', 'project', 'decision', 'task'], true)) {
                    $definition[] = 'expectedresult';
                }
                break;
        }

        $schedule = [];
        if (in_array($kind, ['plan', 'program', 'programstep', 'project', 'decision', 'task'], true)) {
            $schedule = ['startdate', 'duedate'];
        }

        return [
            [
                'key' => 'basic',
                'fields' => $basic,
            ],
            [
                'key' => 'definition',
                'fields' => $definition,
            ],
            [
                'key' => 'schedule',
                'fields' => $schedule,
            ],
            [
                'key' => 'advanced',
                'fields' => ['sortorder'],
                'collapsed' => true,
            ],
        ];
    }

    /**
     * Return the most specific language key for a field label.
     *
     * @param string $kind
     * @param string $field
     * @return string
     */
    public static function label_key(string $kind, string $field): string {
        $specific = 'adminscale_field_' . $kind . '_' . $field;
        if (get_string_manager()->string_exists($specific, 'local_kbacompany')) {
            return $specific;
        }
        $generic = [
            'code' => 'adminscale_code',
            'name' => 'adminscale_name',
            'parentuuid' => 'adminscale_parent',
            'sourceuuid' => self::source_label_key($kind),
            'description' => 'adminscale_description',
            'situation' => 'adminscale_situation',
            'plangoal' => 'adminscale_plangoal',
            'expectedresult' => 'adminscale_expectedresult',
            'ownerpositionuuid' => 'adminscale_ownerposition',
            'productuuid' => 'adminscale_product',
            'metricuuid' => 'adminscale_metric',
            'targetvalue' => 'adminscale_targetvalue',
            'startdate' => 'adminscale_startdate',
            'duedate' => 'adminscale_duedate',
            'sortorder' => 'adminscale_sortorder',
        ];
        return $generic[$field] ?? 'adminscale_' . $field;
    }

    /**
     * Fields required by the service for each kind.
     *
     * @param string $kind
     * @param string $field
     * @return bool
     */
    public static function required(string $kind, string $field): bool {
        if ($field === 'name') { return true; }
        if ($field === 'parentuuid') { return $kind !== 'longtermgoal'; }
        if ($field === 'sourceuuid') { return in_array($kind, ['longtermgoal', 'goal', 'policy'], true); }
        if (in_array($field, ['situation', 'plangoal'], true)) { return $kind === 'plan'; }
        if ($field === 'description') { return in_array($kind, ['plan', 'idealstate'], true); }
        if ($field === 'expectedresult') {
            return in_array($kind,
                ['longtermgoal', 'goal', 'program', 'programstep', 'project', 'decision', 'task', 'ckp'], true);
        }
        if ($field === 'metricuuid') { return $kind === 'statistic'; }
        return false;
    }

    /**
     * @param string $kind
     * @return string
     */
    private static function source_label_key(string $kind): string {
        if ($kind === 'longtermgoal') { return 'adminscale_sourceownerintent'; }
        if ($kind === 'goal') { return 'adminscale_sourcegoal'; }
        if ($kind === 'policy') { return 'adminscale_sourcepolicy'; }
        if ($kind === 'decision') { return 'adminscale_sourcedirective'; }
        return 'adminscale_source';
    }
    /** Semantic links, not a second client-side editor. */
    public static function project_tabs(string $companyuuid, string $projectuuid, string $active): string {
        $html = \html_writer::start_tag('nav', ['class' => 'bms-project-tabs',
            'aria-label' => get_string('projectexec_tabs', 'local_kbacompany')]);
        foreach (['description' => 'projectexec_description', 'execution' => 'projectexec_title'] as $tab => $key) {
            $attrs = ['class' => 'bms-project-tab' . ($active === $tab ? ' active' : '')];
            if ($active === $tab) { $attrs['aria-current'] = 'page'; }
            $html .= \html_writer::link(new \moodle_url('/local/kbacompany/administrative_scale.php', [
                'uuid' => $companyuuid, 'viewkind' => 'project', 'detailuuid' => $projectuuid, 'recordtab' => $tab,
            ]), get_string($key, 'local_kbacompany'), $attrs);
        }
        $html .= \html_writer::link(new \moodle_url('/local/kbacompany/administrative_scale.php', [
            'uuid' => $companyuuid, 'viewkind' => 'project',
        ]), get_string('projectexec_back', 'local_kbacompany'), ['class' => 'bms-project-tab bms-project-back']);
        return $html . \html_writer::end_tag('nav');
    }

    /** Render an already authorised read model. No database calls or mutations. */
    public static function project_execution(array $model): string {
        $tr = static function(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); };
        $field = static function(string $label, string $value): string {
            return \html_writer::tag('div', \html_writer::tag('dt', s($label)) .
                \html_writer::tag('dd', nl2br(s($value !== '' ? $value : '—'))), ['class' => 'bms-project-field']);
        };
        $project = $model['project'];
        $html = \html_writer::start_tag('section', ['class' => 'bms-project-execution',
            'aria-label' => $tr('projectexec_title')]);
        $html .= \html_writer::tag('p', $tr('projectexec_intro'), ['class' => 'bms-project-intro']);
        $html .= \html_writer::start_tag('dl', ['class' => 'bms-project-grid']);
        $html .= $field($tr('adminscale_expectedresult'), $project['expectedresult']);
        $html .= $field($tr('adminscale_ownerposition'), $model['labels']['owner']);
        $html .= $field($tr('adminscale_period'), trim($project['startdate'] . ' — ' . $project['duedate'], ' —'));
        $html .= $field($tr('adminscale_product'), $model['labels']['product']);
        $html .= \html_writer::end_tag('dl');
        if ($model['indicator_url'] !== '') {
            $html .= \html_writer::tag('p', s($tr('adminscale_metric')) . ': ' .
                \html_writer::link($model['indicator_url'], s($model['labels']['indicator'])));
        }
        $html .= \html_writer::tag('p', $tr('projectexec_effect_notice'), ['class' => 'bms-project-note']);
        $html .= \html_writer::start_tag('div', ['class' => 'bms-project-summary']);
        foreach (['tasks' => 'projectexec_count_tasks', 'done' => 'projectexec_count_done',
                'results' => 'projectexec_count_results', 'meetings' => 'projectexec_count_meetings',
                'decisions' => 'projectexec_count_decisions'] as $key => $label) {
            $html .= \html_writer::tag('div', \html_writer::tag('strong', (string)$model['summary'][$key]) .
                \html_writer::tag('span', $tr($label)), ['class' => 'bms-project-stat']);
        }
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::tag('p', $tr('projectexec_scope_notice'), ['class' => 'bms-project-note']);
        $html .= \html_writer::start_tag('div', ['class' => 'bms-project-actions']);
        $html .= \html_writer::link($model['tasks_url'], $tr('projectexec_open_tasks'), ['class' => 'btn btn-primary']);
        if ($model['meeting_url'] !== '') {
            $html .= \html_writer::link($model['meeting_url'], $tr('admincycle_start_meeting'),
                ['class' => 'btn btn-outline-secondary']);
        }
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::tag('p', $tr('projectexec_task_help'), ['class' => 'bms-project-note']);
        if ($project['status'] !== 'published' || $project['stale']) {
            $html .= \html_writer::tag('p', $tr('projectexec_draft_notice'), ['class' => 'alert alert-info']);
        }
        $html .= \html_writer::tag('h4', $tr('projectexec_tasks'), ['class' => 'bms-project-heading']);
        if (!$model['tasks']) {
            $html .= \html_writer::tag('p', $tr('projectexec_empty_tasks'), ['class' => 'bms-crm-empty']);
        }
        foreach ($model['tasks'] as $task) {
            $html .= \html_writer::start_tag('article', ['class' => 'bms-project-card']);
            $html .= \html_writer::tag('h5', \html_writer::link($task['url'], s($task['title'])));
            $meta = $tr('taskcenter_status_' . $task['status']) . ' · ' . $task['assignee_name'];
            if ($task['due_date'] !== '') { $meta .= ' · ' . $tr('adminscale_duedate') . ': ' . $task['due_date']; }
            $html .= \html_writer::tag('p', s($meta), ['class' => 'bms-project-note']);
            $html .= \html_writer::tag('p', s($tr('projectexec_task_product')) . ': ' . nl2br(s($task['product'])));
            $html .= \html_writer::tag('p', $tr('projectexec_task_time', (object)[
                'plan' => $task['plan_minutes'], 'fact' => $task['fact_minutes'],
            ]), ['class' => 'bms-project-note']);
            if (!empty($task['project_revision_differs'])) {
                $html .= \html_writer::tag('p', s($tr('projectexec_task_revision', $task['context_revision'])),
                    ['class' => 'bms-project-note']);
            }
            if ($task['battle_plans']) {
                $html .= \html_writer::start_tag('div', ['class' => 'bms-project-actions']);
                foreach ($task['battle_plans'] as $plan) {
                    $html .= \html_writer::link($plan['url'], s($tr('battleplan_title') . ' · ' . $plan['periodstart']),
                        ['class' => 'bms-project-plan-link']);
                }
                $html .= \html_writer::end_tag('div');
            }
            $result = trim((string)$task['result']);
            $html .= \html_writer::tag('div', $result !== ''
                ? \html_writer::tag('strong', $tr('admincycle_result') . ': ') . nl2br(s($result))
                : $tr('projectexec_no_result'), ['class' => 'bms-project-result']);
            $html .= \html_writer::end_tag('article');
        }
        $html .= \html_writer::tag('h4', $tr('projectexec_meetings'), ['class' => 'bms-project-heading']);
        if (!$model['meetings']) {
            $html .= \html_writer::tag('p', $tr('projectexec_empty_meetings'), ['class' => 'bms-crm-empty']);
        }
        foreach ($model['meetings'] as $meeting) {
            $html .= \html_writer::start_tag('article', ['class' => 'bms-project-card']);
            $html .= \html_writer::tag('h5', \html_writer::link($meeting['url'], s($meeting['title'])));
            $meta = $tr('meeting_status_' . $meeting['status']) . ' · ' .
                $tr('admincycle_source_revision', $meeting['source_revision']);
            if (!empty($meeting['project_revision_differs'])) { $meta .= ' · ' . $tr('admincycle_view_revision_differs'); }
            $html .= \html_writer::tag('p', s($meta), ['class' => 'bms-project-note']);
            if (!$meeting['decisions']) { $html .= \html_writer::tag('p', $tr('projectexec_empty_decisions')); }
            foreach ($meeting['decisions'] as $decision) {
                $html .= \html_writer::start_tag('div', ['class' => 'bms-project-decision']);
                $html .= \html_writer::tag('strong', s($decision['title']));
                $html .= \html_writer::tag('p', $tr('meeting_decision_status_' . $decision['status']),
                    ['class' => 'bms-project-note']);
                if ($decision['expected_result'] !== '') {
                    $html .= \html_writer::tag('p', nl2br(s($decision['expected_result'])));
                }
                $html .= \html_writer::end_tag('div');
            }
            $html .= \html_writer::end_tag('article');
        }
        return $html . \html_writer::end_tag('section');
    }


}
