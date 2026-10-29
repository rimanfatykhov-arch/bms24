<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared presentation adapter for BMS pages.
 *
 * Business controllers keep ownership of validation, permissions and page body.
 * This adapter only wraps that body in the optional KBA Theme BMS App Shell.
 */
class bms_page_shell_service {
    public const CONTRACT_VERSION = '0.6.0';

    /**
     * Prepare a page for unified BMS shell rendering.
     *
     * Must be called before any page output. For KBA Theme requests, page body
     * output is buffered until finish(). For fallback themes, the normal Moodle
     * header is emitted immediately.
     */
    public static function begin(string $companyuuid, int $userid, string $active,
            string $title): array {
        global $PAGE, $OUTPUT;

        $useowned = bms24_shell_service::is_enabled();
        $usetheme = !$useowned && bms_ui_service::supports_kba_shell();
        if ($useowned) {
            bms24_shell_service::prepare_page();
        } else {
            $PAGE->set_pagelayout($usetheme ? 'bms' : 'standard');
        }
        // The shared geometry stylesheet must load on every BMS route, not just Documents.
        $PAGE->requires->css(new \moodle_url('/local/kbacompany/styles.css'));
        $PAGE->requires->js_call_amd('local_kbacompany/bms_shell_navigation', 'init');
        if ($usetheme) {
            $PAGE->requires->js_call_amd('local_kbacompany/bms_ux_stabilizer', 'init');
        }

        $state = [
            'companyuuid' => $companyuuid,
            'userid' => $userid,
            'active' => $active,
            'title' => $title,
            'theme_supported' => $usetheme,
            'owned_supported' => $useowned,
            'bufferlevel' => ob_get_level(),
            'model' => null,
        ];

        if ($usetheme || $useowned) {
            $state['model'] = bms_ui_service::shell_model($companyuuid, $userid, $active);
            ob_start();
        } else {
            echo $OUTPUT->header();
        }

        return $state;
    }

    /** Finish the page and emit either the BMS App Shell or normal Moodle footer. */
    public static function finish(array $state): void {
        global $OUTPUT;

        if (empty($state['theme_supported']) && empty($state['owned_supported'])) {
            echo $OUTPUT->footer();
            return;
        }

        $contenthtml = '';
        if (ob_get_level() > (int)$state['bufferlevel']) {
            $contenthtml = (string)ob_get_clean();
        }

        // Add the contextual quick-navigation bar on shell pages that did not
        // render a domain-specific one themselves. Existing nav renderers mark
        // themselves with bms-context-quicknav, so there is no duplication.
        if (strpos($contenthtml, 'bms-context-quicknav') === false) {
            $week = optional_param('week', '', PARAM_RAW_TRIMMED);
            $contextnav = bms_workspace_ux_service::contextual_nav(
                (string)$state['companyuuid'], (string)$state['active'], $week);
            if ($contextnav !== '') {
                $contenthtml = $contextnav . $contenthtml;
            }
        }

        $model = $state['model'];
        self::decorate_icons($model);
        $model['navigation_groups'] = bms_main_tool_service::personalise_groups(
            (string)$state['companyuuid'], (int)$state['userid'], $model['navigation_groups']);
        $company = $model['company'];
        $routes = $model['route_map'];

        $workspaceurl = $routes['workspace']['url'] ?? (new \moodle_url(
            '/local/kbacompany/bms_workspace.php', ['uuid' => $state['companyuuid']]))->out(false);
        $homeurl = (new \moodle_url('/local/kbacompany/bms_home.php',
            ['uuid' => $state['companyuuid']]))->out(false);
        $preferencesurl = (new \moodle_url('/local/kbacompany/bms_home_preferences.php',
            ['uuid' => $state['companyuuid']]))->out(false);
        $communicationurl = $routes['communications']['url'] ?? (new \moodle_url(
            '/local/kbacompany/communication_center.php', ['uuid' => $state['companyuuid']]))->out(false);
        $tasksurl = $routes['tasks']['url'] ?? (new \moodle_url(
            '/local/kbacompany/tasks.php', [
                'uuid' => $state['companyuuid'], 'scope' => 'my',
            ]))->out(false);

        $settingsurl = $routes['settings']['url'] ?? (new \moodle_url(
            '/local/kbacompany/bms_settings.php', ['uuid' => $state['companyuuid']]))->out(false);
        $planurl = (new \moodle_url('/local/kbacompany/bms_settings.php', [
            'uuid' => $state['companyuuid'], 'tab' => 'plan',
        ]))->out(false);

        $quickactions = \html_writer::link($tasksurl, get_string('taskcenter_title', 'local_kbacompany'),
            ['class' => 'kba-bms-button kba-bms-button--primary']);
        $quickactions .= ' ' . \html_writer::link($communicationurl,
            get_string('communicationcenter_title', 'local_kbacompany'), ['class' => 'kba-bms-button']);

        $shell = [
            'sidebar' => [
                'arialabel' => get_string('bmsworkspace_sidebar_label', 'local_kbacompany'),
                'brandmarkhtml' => \html_writer::link($homeurl, \html_writer::span('BMS24', 'bms-sidebar-brandmark-text'), ['class' => 'bms-brand-home-link', 'aria-label' => 'BMS24 — ' . get_string('bmsworkspace_nav', 'local_kbacompany')]),
                'brandlabel' => trim(preg_replace('/^BMS24\s*[—-]\s*/u', '', get_string('pluginname', 'local_kbacompany'))),
                'togglelabel' => get_string('bmsworkspace_collapse', 'local_kbacompany'),
                'groups' => $model['navigation_groups'],
                'utilityitems' => [[
                    'label' => get_string('bmsmain_change', 'local_kbacompany'),
                    'url' => $preferencesurl,
                    'active' => false,
                    'iconhtml' => $OUTPUT->pix_icon('i/settings', '', 'moodle', ['class' => 'icon']),
                ], [
                    'label' => get_string('bms_settings_title', 'local_kbacompany'),
                    'url' => $settingsurl,
                    'active' => $state['active'] === 'settings',
                    'iconhtml' => $OUTPUT->pix_icon('i/settings', '', 'moodle', ['class' => 'icon']),
                ]],
                'footerhtml' => \html_writer::span('© BMS24, 2026', 'bms-sidebar-copyright'),
                'footerbrandlabel' => '',
                'footercompanyhtml' => '',
            ],
            'topbar' => [
                'mobilemenulabel' => get_string('bmsworkspace_mobilemenu', 'local_kbacompany'),
                'sectiontitle' => '',
                'breadcrumbs' => [
                    'items' => [
                        [
                            'label' => get_string('mycompany', 'local_kbacompany'),
                            'url' => (new \moodle_url('/local/kbacompany/index.php'))->out(false),
                        ],
                        [
                            'label' => format_string($company['name']),
                            'url' => $workspaceurl,
                        ],
                        ['label' => $state['title'], 'url' => null],
                    ],
                ],
                'quickactionhtml' => $quickactions,
                'planhtml' => \html_writer::link($planurl, get_string('bms_plan_title', 'local_kbacompany'),
                    ['class' => 'kba-bms-button kba-bms-button--plan']),
                'notificationshtml' => '',
                'usermenuhtml' => $OUTPUT->user_menu(),
            ],
            // Ordinary pages already own their semantic H1/intro/actions. Avoid
            // duplicating the same title in an additional App Shell content header.
            'contenthtml' => $contenthtml,
        ];

        echo $OUTPUT->header();
        echo !empty($state['owned_supported'])
            ? bms24_shell_service::render($shell)
            : $OUTPUT->render_from_template('theme_kba/bms/app_shell', $shell);
        echo $OUTPUT->footer();
    }

    /** Finish and stop execution for early-return page branches. */
    public static function finish_and_exit(array $state): void {
        self::finish($state);
        exit;
    }

    /** Convert semantic icon descriptors after pagelayout has been selected. */
    private static function decorate_icons(array &$model): void {
        global $OUTPUT;

        foreach ($model['navigation_groups'] as &$group) {
            foreach ($group['items'] as &$item) {
                $icon = $item['icon'] ?? null;
                if (empty($item['iconhtml'])) {
                    $item['iconhtml'] = '';
                    if (!empty($icon['key'])) {
                        $item['iconhtml'] = $OUTPUT->pix_icon((string)$icon['key'], '',
                            (string)($icon['component'] ?? 'moodle'), ['class' => 'icon']);
                    }
                }
                unset($item['icon']);
            }
            unset($item);
        }
        unset($group);
    }
}
