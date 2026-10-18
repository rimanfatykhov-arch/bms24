<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $root = new admin_category(
        'local_bitrixsync_root',
        get_string('pluginname', 'local_bitrixsync')
    );
    $ADMIN->add('localplugins', $root);

    // Compact main settings: only the switches an administrator needs
    // during normal operation. Everything else lives in a dedicated page.
    $settings = new admin_settingpage(
        'local_bitrixsync',
        get_string('essential_settings', 'local_bitrixsync')
    );

    $settings->add(new admin_setting_heading(
        'local_bitrixsync/workflowheading',
        get_string('essential_settings', 'local_bitrixsync'),
        get_string('essential_settings_desc', 'local_bitrixsync')
    ));

    $settings->add(new admin_setting_configselect(
        'local_bitrixsync/engine_mode',
        get_string('engine_mode', 'local_bitrixsync'),
        get_string('engine_mode_desc', 'local_bitrixsync'),
        'legacy',
        [
            'legacy' => get_string('engine_legacy', 'local_bitrixsync'),
            'v5' => get_string('engine_v5', 'local_bitrixsync'),
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_bitrixsync/stageselectorlink',
        get_string('stage_selector_title', 'local_bitrixsync'),
        html_writer::link(
            new moodle_url('/local/bitrixsync/funnels.php'),
            get_string('open_stage_selector', 'local_bitrixsync'),
            ['class' => 'btn btn-outline-primary']
        )
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/queue_enabled',
        get_string('queue_enabled', 'local_bitrixsync'),
        get_string('queue_enabled_desc', 'local_bitrixsync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/auto_create_users',
        get_string('auto_create_users', 'local_bitrixsync'),
        get_string('auto_create_users_desc', 'local_bitrixsync'),
        1
    ));


    $settings->add(new admin_setting_configpasswordunmask(
        'local_bitrixsync/corporate_integration_token',
        get_string('corporate_integration_token', 'local_bitrixsync'),
        get_string('corporate_integration_token_desc', 'local_bitrixsync'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/access_delivery_enable',
        get_string('access_delivery_enable', 'local_bitrixsync'),
        get_string('access_delivery_enable_desc', 'local_bitrixsync'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/access_recover_kba_users',
        get_string('access_recover_kba_users', 'local_bitrixsync'),
        get_string('access_recover_kba_users_desc', 'local_bitrixsync'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/access_write_legacy_password',
        get_string('access_write_legacy_password', 'local_bitrixsync'),
        get_string('access_write_legacy_password_desc', 'local_bitrixsync'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/corporate_employee_sync_enable',
        get_string('corporate_employee_sync_enable', 'local_bitrixsync'),
        get_string('corporate_employee_sync_enable_desc', 'local_bitrixsync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/bms_bridge_enabled',
        get_string('bms_bridge_enabled', 'local_bitrixsync'),
        get_string('bms_bridge_enabled_desc', 'local_bitrixsync'),
        0
    ));

    // Production-safe explicit BMS admission field. No field is auto-selected.
    // Prefer the already verified CRM Field Engine cache so the administrator
    // can select a field even when Bitrix REST is temporarily unavailable
    // while Moodle renders the settings page. Live REST is only an additive
    // refresh source and never removes cached verified options.
    $bmsfieldoptions = [
        '' => get_string('bms_client_company_field_none', 'local_bitrixsync'),
    ];
    $configuredbmsfield = trim((string)get_config('local_bitrixsync', 'bms_client_company_field'));
    if (!empty($ADMIN->fulltree)) {
        $fieldmanager = new \local_bitrixsync\services\crm_field_manager();

        // 1. Verified local CRM Field Engine resolution cache.
        // The cache is keyed by logical field names (for example company => bms_client),
        // not by raw Bitrix FIELD_NAME. crm_fields.php renders its Ready state from this
        // same structure, so expose every resolved Company field exactly as cached.
        try {
            $cachedfields = $fieldmanager->get_cached_resolution();
            foreach ((array)($cachedfields['company'] ?? []) as $logicalkey => $cachedfield) {
                if (empty($cachedfield['ready'])) {
                    continue;
                }
                $fieldname = trim((string)($cachedfield['field_name'] ?? ''));
                if ($fieldname === '') {
                    continue;
                }
                $label = trim((string)($cachedfield['label'] ?? ''));
                if ($label === '') {
                    $label = $fieldname;
                }
                $xmlid = trim((string)($cachedfield['xml_id'] ?? ''));
                $type = trim((string)($cachedfield['user_type_id'] ?? ''));
                $suffix = $xmlid !== '' ? ' / ' . $xmlid : '';
                $typesuffix = $type !== '' ? ' {' . $type . '}' : '';
                $bmsfieldoptions[$fieldname] = $label . ' [' . $fieldname . ']' . $suffix . $typesuffix;
            }
        } catch (\Throwable $e) {
            // Cache is optional; fall through to live discovery.
        }

        // 2. Live Bitrix24 discovery supplements the cache when available. Show all
        // Company userfields here so the administrator can explicitly identify the
        // intended field even if Bitrix reports an unexpected type. The bridge itself
        // still validates that the selected field is single-value boolean before sync.
        try {
            $companyfields = $fieldmanager->list_entity_fields('company');
            foreach ($companyfields as $companyfield) {
                $fieldname = trim((string)($companyfield['FIELD_NAME'] ?? ''));
                if ($fieldname === '') {
                    continue;
                }
                $label = '';
                foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $labelkey) {
                    $candidate = $companyfield[$labelkey] ?? '';
                    if (is_array($candidate)) {
                        $candidate = $candidate['ru'] ?? $candidate['en'] ?? reset($candidate);
                    }
                    if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                        $label = trim((string)$candidate);
                        break;
                    }
                }
                if ($label === '') {
                    $label = $fieldname;
                }
                $xmlid = trim((string)($companyfield['XML_ID'] ?? ''));
                $type = trim((string)($companyfield['USER_TYPE_ID'] ?? ''));
                $multiple = (string)($companyfield['MULTIPLE'] ?? 'N') === 'Y';
                $suffix = $xmlid !== '' ? ' / ' . $xmlid : '';
                $typesuffix = $type !== '' ? ' {' . $type . ($multiple ? ', multiple' : '') . '}' : '';
                $bmsfieldoptions[$fieldname] = $label . ' [' . $fieldname . ']' . $suffix . $typesuffix;
            }
        } catch (\Throwable $e) {
            // Keep the settings page usable even when Bitrix REST is temporarily unavailable.
        }
    }
    if ($configuredbmsfield !== '' && !isset($bmsfieldoptions[$configuredbmsfield])) {
        $bmsfieldoptions[$configuredbmsfield] = get_string(
            'bms_client_company_field_saved_unavailable',
            'local_bitrixsync',
            $configuredbmsfield
        );
    }

    $settings->add(new admin_setting_configselect(
        'local_bitrixsync/bms_client_company_field',
        get_string('bms_client_company_field', 'local_bitrixsync'),
        get_string('bms_client_company_field_desc', 'local_bitrixsync'),
        '',
        $bmsfieldoptions
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_bitrixsync/bms_company_webhook_enabled',
        get_string('bms_company_webhook_enabled', 'local_bitrixsync'),
        get_string('bms_company_webhook_enabled_desc', 'local_bitrixsync'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_bitrixsync/quicklinks',
        get_string('quick_actions', 'local_bitrixsync'),
        html_writer::link(
            new moodle_url('/local/bitrixsync/setup_center.php'),
            get_string('open_setup_center', 'local_bitrixsync'),
            ['class' => 'btn btn-primary mr-2']
        ) . ' ' .
        html_writer::link(
            new moodle_url('/local/bitrixsync/admin_dashboard.php'),
            get_string('open_admin_dashboard', 'local_bitrixsync'),
            ['class' => 'btn btn-secondary']
        )
    ));

    $ADMIN->add('local_bitrixsync_root', $settings);

    $pages = [
        [
            'id' => 'local_bitrixsync_admindashboard',
            'title' => 'admin_dashboard_title',
            'url' => '/local/bitrixsync/admin_dashboard.php',
        ],
        [
            'id' => 'local_bitrixsync_setupcenter',
            'title' => 'setup_center_title',
            'url' => '/local/bitrixsync/setup_center.php',
        ],
        [
            'id' => 'local_bitrixsync_bitrixconnection',
            'title' => 'bitrix_connection_title',
            'url' => '/local/bitrixsync/bitrix_connection.php',
        ],
        [
            'id' => 'local_bitrixsync_crmfields',
            'title' => 'crm_fields_title',
            'url' => '/local/bitrixsync/crm_fields.php',
        ],
        [
            'id' => 'local_bitrixsync_messagetemplates',
            'title' => 'message_templates_title',
            'url' => '/local/bitrixsync/message_templates.php',
        ],
        [
            'id' => 'local_bitrixsync_coursemapping',
            'title' => 'course_mapping_title',
            'url' => '/local/bitrixsync/course_mapping.php',
        ],
        [
            'id' => 'local_bitrixsync_license_reconciliation',
            'title' => 'license_reconciliation_title',
            'url' => '/local/bitrixsync/license_reconciliation.php',
        ],
        [
            'id' => 'local_bitrixsync_corporate',
            'title' => 'corporate_settings_title',
            'url' => '/local/bitrixsync/corporate_rules.php',
        ],
        [
            'id' => 'local_bitrixsync_funnels',
            'title' => 'funnels_title',
            'url' => '/local/bitrixsync/funnels.php',
        ],
        [
            'id' => 'local_bitrixsync_automation',
            'title' => 'automation_title',
            'url' => '/local/bitrixsync/automation_center.php',
        ],
        [
            'id' => 'local_bitrixsync_history',
            'title' => 'history_title',
            'url' => '/local/bitrixsync/history.php',
        ],
        [
            'id' => 'local_bitrixsync_diagnostics',
            'title' => 'diagnostics_title',
            'url' => '/local/bitrixsync/diagnostics_hub.php',
        ],
        [
            'id' => 'local_bitrixsync_productioncontrol',
            'title' => 'production_control_title',
            'url' => '/local/bitrixsync/production_control.php',
        ],
        [
            'id' => 'local_bitrixsync_productionwizard',
            'title' => 'production_wizard_title',
            'url' => '/local/bitrixsync/setup_wizard.php',
        ],
    ];

    foreach ($pages as $page) {
        $ADMIN->add(
            'local_bitrixsync_root',
            new admin_externalpage(
                $page['id'],
                get_string($page['title'], 'local_bitrixsync'),
                new moodle_url($page['url']),
                'local/bitrixsync:manage'
            )
        );
    }

    // Technical parameters are intentionally moved away from the normal
    // operator workflow.
    $advanced = new admin_settingpage(
        'local_bitrixsync_advanced',
        get_string('advanced_settings', 'local_bitrixsync')
    );

    $advanced->add(new admin_setting_heading(
        'local_bitrixsync/advancedwarning',
        get_string('advanced_settings', 'local_bitrixsync'),
        get_string('advanced_settings_desc', 'local_bitrixsync')
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/bitrix_connect_timeout',
        get_string('bitrix_connect_timeout', 'local_bitrixsync'),
        get_string('bitrix_connect_timeout_desc', 'local_bitrixsync'),
        3,
        PARAM_INT
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/bitrix_request_timeout',
        get_string('bitrix_request_timeout', 'local_bitrixsync'),
        get_string('bitrix_request_timeout_desc', 'local_bitrixsync'),
        15,
        PARAM_INT
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/queue_batch_size',
        get_string('queue_batch_size', 'local_bitrixsync'),
        get_string('queue_batch_size_desc', 'local_bitrixsync'),
        20,
        PARAM_INT
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/bms_bridge_batch_size',
        get_string('bms_bridge_batch_size', 'local_bitrixsync'),
        get_string('bms_bridge_batch_size_desc', 'local_bitrixsync'),
        20,
        PARAM_INT
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/default_roleid',
        get_string('default_roleid', 'local_bitrixsync'),
        get_string('default_roleid_desc', 'local_bitrixsync'),
        '',
        PARAM_INT
    ));

    $advanced->add(new admin_setting_configcheckbox(
        'local_bitrixsync/legacy_alias_resolution',
        get_string('legacy_alias_resolution', 'local_bitrixsync'),
        get_string('legacy_alias_resolution_desc', 'local_bitrixsync'),
        1
    ));

    $advanced->add(new admin_setting_configcheckbox(
        'local_bitrixsync/legacy_alias_autosave',
        get_string('legacy_alias_autosave', 'local_bitrixsync'),
        get_string('legacy_alias_autosave_desc', 'local_bitrixsync'),
        1
    ));

    $advanced->add(new admin_setting_heading(
        'local_bitrixsync/contactsync_heading',
        'Contact Sync',
        'Параметры сопоставления и автоматического создания пользователей Moodle.'
    ));

    $advanced->add(new admin_setting_configcheckbox(
        'local_bitrixsync/match_users_by_email',
        get_string('match_users_by_email', 'local_bitrixsync'),
        get_string('match_users_by_email_desc', 'local_bitrixsync'),
        1
    ));

    $advanced->add(new admin_setting_configselect(
        'local_bitrixsync/logging_level',
        'Logging level',
        'DEBUG включайте временно. В режиме NORMAL подробные контактные данные не пишутся в server error log.',
        'normal',
        [
            'off' => 'OFF',
            'normal' => 'NORMAL',
            'debug' => 'DEBUG',
        ]
    ));

    $advanced->add(new admin_setting_configtext(
        'local_bitrixsync/debug_deal_id',
        'DEBUG Deal ID',
        'Если указано, DEBUG записи создаются только для этой сделки. 0 = без ограничения.',
        0,
        PARAM_INT
    ));

    $ADMIN->add('local_bitrixsync_root', $advanced);
}
