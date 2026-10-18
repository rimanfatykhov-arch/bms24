<?php
// This file is part of KBA AI Assessor for Moodle.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // A dedicated administration category keeps the growing AI settings readable and mirrors
    // the section-based administration pattern used by BMS Connect.
    $category = new admin_category(
        'local_kbaai_admin',
        get_string('admincategory', 'local_kbaai')
    );
    $ADMIN->add('localplugins', $category);

    $ADMIN->add('local_kbaai_admin', new admin_externalpage(
        'local_kbaai_overview',
        get_string('adminoverview', 'local_kbaai'),
        new moodle_url('/local/kbaai/admin/overview.php'),
        'moodle/site:config'
    ));

    // -------------------------------------------------------------------------
    // General settings.
    // -------------------------------------------------------------------------
    $general = new admin_settingpage(
        'local_kbaai_general',
        get_string('adminsectiongeneral', 'local_kbaai')
    );
    $ADMIN->add('local_kbaai_admin', $general);

    // Product/build information. Disk and DB values make incomplete upgrades immediately visible.
    $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_kbaai');
    $release = ($plugininfo && !empty($plugininfo->release)) ? (string)$plugininfo->release : '0.9.0';
    $versiondisk = ($plugininfo && !empty($plugininfo->versiondisk)) ? (string)$plugininfo->versiondisk : '2026081602';
    $versiondb = ($plugininfo && !empty($plugininfo->versiondb))
        ? (string)$plugininfo->versiondb
        : get_string('versionnotinstalled', 'local_kbaai');
    $versiontable = new html_table();
    $versiontable->attributes['class'] = 'generaltable table-sm mb-0';
    $versiontable->data = array(
        array(get_string('productversion', 'local_kbaai'), s($release)),
        array(get_string('plugincomponent', 'local_kbaai'), 'local_kbaai'),
        array(get_string('versionondisk', 'local_kbaai'), s($versiondisk)),
        array(get_string('versionindb', 'local_kbaai'), s($versiondb)),
    );
    $general->add(new admin_setting_heading(
        'local_kbaai/versioninfoheading',
        get_string('versioninfoheading', 'local_kbaai'),
        html_writer::table($versiontable)
    ));

    $general->add(new admin_setting_heading(
        'local_kbaai/providerheading',
        get_string('providerheading', 'local_kbaai'),
        get_string('adminsectiongeneral_desc', 'local_kbaai')
    ));
    $general->add(new admin_setting_configselect(
        'local_kbaai/defaultmode',
        get_string('defaultmode', 'local_kbaai'),
        get_string('defaultmode_desc', 'local_kbaai'),
        'assistant',
        array(
            'tutor' => 'AI Tutor',
            'assistant' => 'AI Assistant',
            'examiner' => 'AI Examiner',
        )
    ));
    $general->add(new admin_setting_configselect(
        'local_kbaai/defaultprovider',
        get_string('defaultprovider', 'local_kbaai'),
        '',
        'mock',
        array(
            'mock' => get_string('providermock', 'local_kbaai'),
            'yandex' => get_string('provideryandex', 'local_kbaai'),
        )
    ));
    $general->add(new admin_setting_configtext(
        'local_kbaai/defaultmodel',
        get_string('defaultmodel', 'local_kbaai'),
        '',
        'yandexgpt/latest',
        PARAM_TEXT
    ));
    $general->add(new admin_setting_configtext(
        'local_kbaai/defaultconfidence',
        get_string('defaultconfidence', 'local_kbaai'),
        get_string('defaultconfidence_desc', 'local_kbaai'),
        85,
        PARAM_INT
    ));
    $general->add(new admin_setting_configcheckbox(
        'local_kbaai/defaultautopublish',
        get_string('defaultautopublish', 'local_kbaai'),
        get_string('defaultautopublish_desc', 'local_kbaai'),
        0
    ));
    $general->add(new admin_setting_configtext(
        'local_kbaai/maxretries',
        get_string('maxretries', 'local_kbaai'),
        '',
        3,
        PARAM_INT
    ));
    $general->add(new admin_setting_configcheckbox(
        'local_kbaai/autoscan',
        get_string('autoscan', 'local_kbaai'),
        get_string('autoscan_desc', 'local_kbaai'),
        0
    ));

    // -------------------------------------------------------------------------
    // Yandex AI Studio.
    // -------------------------------------------------------------------------
    $yandex = new admin_settingpage(
        'local_kbaai_yandex',
        get_string('adminsectionyandex', 'local_kbaai')
    );
    $ADMIN->add('local_kbaai_admin', $yandex);

    $yandex->add(new admin_setting_heading(
        'local_kbaai/yandexheading',
        get_string('yandexheading', 'local_kbaai'),
        get_string('yandexproductionhint', 'local_kbaai')
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandexfolderid',
        get_string('yandexfolderid', 'local_kbaai'),
        get_string('yandexfolderid_desc', 'local_kbaai'),
        '',
        PARAM_TEXT
    ));
    $yandex->add(new admin_setting_configpasswordunmask(
        'local_kbaai/yandexapikey',
        get_string('yandexapikey', 'local_kbaai'),
        get_string('yandexapikey_desc', 'local_kbaai'),
        ''
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandexendpoint',
        get_string('yandexendpoint', 'local_kbaai'),
        get_string('yandexendpoint_desc', 'local_kbaai'),
        'https://llm.api.cloud.yandex.net/foundationModels/v1/completion',
        PARAM_URL
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandexopenaibase',
        get_string('yandexopenaibase', 'local_kbaai'),
        get_string('yandexopenaibase_desc', 'local_kbaai'),
        'https://ai.api.cloud.yandex.net/v1',
        PARAM_URL
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandexvisionmodel',
        get_string('yandexvisionmodel', 'local_kbaai'),
        get_string('yandexvisionmodel_desc', 'local_kbaai'),
        'qwen3.6-35b-a3b',
        PARAM_TEXT
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandextexttimeout',
        get_string('yandextexttimeout', 'local_kbaai'),
        get_string('yandextexttimeout_desc', 'local_kbaai'),
        90,
        PARAM_INT
    ));
    $yandex->add(new admin_setting_configtext(
        'local_kbaai/yandexvisiontimeout',
        get_string('yandexvisiontimeout', 'local_kbaai'),
        get_string('yandexvisiontimeout_desc', 'local_kbaai'),
        120,
        PARAM_INT
    ));
    $yandex->add(new admin_setting_description(
        'local_kbaai/yandexprovidertestlink',
        get_string('adminproviderdiagnostics', 'local_kbaai'),
        html_writer::link(
            new moodle_url('/local/kbaai/provider_test_v082.php', array('provider' => 'yandex')),
            get_string('openproviderdiagnostics', 'local_kbaai'),
            array('class' => 'btn btn-primary')
        )
    ));

    // -------------------------------------------------------------------------
    // Documents and files.
    // -------------------------------------------------------------------------
    $files = new admin_settingpage(
        'local_kbaai_files',
        get_string('adminsectionfiles', 'local_kbaai')
    );
    $ADMIN->add('local_kbaai_admin', $files);

    $files->add(new admin_setting_heading(
        'local_kbaai/pdfheading',
        get_string('pdfheading', 'local_kbaai'),
        get_string('pdfheading_desc', 'local_kbaai')
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/yandexocrendpoint',
        get_string('yandexocrendpoint', 'local_kbaai'),
        get_string('yandexocrendpoint_desc', 'local_kbaai'),
        'https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText',
        PARAM_URL
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/yandexocrmodel',
        get_string('yandexocrmodel', 'local_kbaai'),
        get_string('yandexocrmodel_desc', 'local_kbaai'),
        'page',
        PARAM_TEXT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/yandexocrtimeout',
        get_string('yandexocrtimeout', 'local_kbaai'),
        get_string('yandexocrtimeout_desc', 'local_kbaai'),
        120,
        PARAM_INT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/pdfmaxchars',
        get_string('pdfmaxchars', 'local_kbaai'),
        get_string('pdfmaxchars_desc', 'local_kbaai'),
        60000,
        PARAM_INT
    ));

    $files->add(new admin_setting_heading(
        'local_kbaai/docxheading',
        get_string('docxheading', 'local_kbaai'),
        get_string('docxheading_desc', 'local_kbaai')
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/docxmaxchars',
        get_string('docxmaxchars', 'local_kbaai'),
        get_string('docxmaxchars_desc', 'local_kbaai'),
        60000,
        PARAM_INT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/docxmaximages',
        get_string('docxmaximages', 'local_kbaai'),
        get_string('docxmaximages_desc', 'local_kbaai'),
        4,
        PARAM_INT
    ));

    $files->add(new admin_setting_heading(
        'local_kbaai/xlsxheading',
        get_string('xlsxheading', 'local_kbaai'),
        get_string('xlsxheading_desc', 'local_kbaai')
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/xlsxmaxchars',
        get_string('xlsxmaxchars', 'local_kbaai'),
        get_string('xlsxmaxchars_desc', 'local_kbaai'),
        80000,
        PARAM_INT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/xlsxmaxsheets',
        get_string('xlsxmaxsheets', 'local_kbaai'),
        get_string('xlsxmaxsheets_desc', 'local_kbaai'),
        20,
        PARAM_INT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/xlsxmaxcells',
        get_string('xlsxmaxcells', 'local_kbaai'),
        get_string('xlsxmaxcells_desc', 'local_kbaai'),
        10000,
        PARAM_INT
    ));
    $files->add(new admin_setting_configtext(
        'local_kbaai/xlsxmaxrows',
        get_string('xlsxmaxrows', 'local_kbaai'),
        get_string('xlsxmaxrows_desc', 'local_kbaai'),
        2000,
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // Supervisor assistant.
    // -------------------------------------------------------------------------
    $supervisor = new admin_settingpage(
        'local_kbaai_supervisor',
        get_string('adminsectionsupervisor', 'local_kbaai')
    );
    $ADMIN->add('local_kbaai_admin', $supervisor);

    $supervisor->add(new admin_setting_heading(
        'local_kbaai/supervisorcontextheading',
        get_string('supervisorcontextheading', 'local_kbaai'),
        get_string('adminsectionsupervisor_desc', 'local_kbaai')
    ));
    $supervisor->add(new admin_setting_configtext(
        'local_kbaai/supervisorrelatedlimit',
        get_string('supervisorrelatedlimit', 'local_kbaai'),
        get_string('supervisorrelatedlimit_desc', 'local_kbaai'),
        4,
        PARAM_INT
    ));
    $supervisor->add(new admin_setting_configtext(
        'local_kbaai/supervisorconversationlimit',
        get_string('supervisorconversationlimit', 'local_kbaai'),
        get_string('supervisorconversationlimit_desc', 'local_kbaai'),
        24,
        PARAM_INT
    ));
    $supervisor->add(new admin_setting_configtext(
        'local_kbaai/supervisorcontextchars',
        get_string('supervisorcontextchars', 'local_kbaai'),
        get_string('supervisorcontextchars_desc', 'local_kbaai'),
        32000,
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // KBA Element integration and schema mapping.
    // -------------------------------------------------------------------------
    $integration = new admin_settingpage(
        'local_kbaai_integration',
        get_string('adminsectionintegration', 'local_kbaai')
    );
    $ADMIN->add('local_kbaai_admin', $integration);

    $integration->add(new admin_setting_heading(
        'local_kbaai/mappingheading',
        get_string('mappingheading', 'local_kbaai'),
        get_string('mappingheading_desc', 'local_kbaai')
    ));
    foreach (array(
        'subinstancefield',
        'subuserfield',
        'subanswerfield',
        'submodifiedfield',
        'subrevisionfield',
        'instancecontentfield',
        'instancetaskfield',
        'instanceobjectivesfield',
        'instancecoursefield',
        'subgradefield',
        'subfeedbackfield',
        'substatusfield',
    ) as $field) {
        $integration->add(new admin_setting_configtext(
            'local_kbaai/' . $field,
            get_string($field, 'local_kbaai'),
            get_string($field . '_desc', 'local_kbaai'),
            '',
            PARAM_ALPHANUMEXT
        ));
    }
    $integration->add(new admin_setting_description(
        'local_kbaai/integrationdiagnosticslink',
        get_string('adminintegrationdiagnostics', 'local_kbaai'),
        html_writer::link(
            new moodle_url('/local/kbaai/diagnostics_v082.php'),
            get_string('openintegrationdiagnostics', 'local_kbaai'),
            array('class' => 'btn btn-secondary')
        )
    ));

    // Dedicated diagnostic pages remain visible as their own administration sections.
    $ADMIN->add('local_kbaai_admin', new admin_externalpage(
        'local_kbaai_providerdiagnostics',
        get_string('adminproviderdiagnostics', 'local_kbaai'),
        new moodle_url('/local/kbaai/provider_test_v082.php', array('provider' => 'yandex')),
        'moodle/site:config'
    ));
    $ADMIN->add('local_kbaai_admin', new admin_externalpage(
        'local_kbaai_integrationdiagnostics',
        get_string('adminintegrationdiagnostics', 'local_kbaai'),
        new moodle_url('/local/kbaai/diagnostics_v082.php'),
        'moodle/site:config'
    ));

    // Preserve old bookmarks to /admin/settings.php?section=local_kbaai.
    $legacy = new admin_settingpage(
        'local_kbaai',
        get_string('pluginname', 'local_kbaai'),
        'moodle/site:config',
        true
    );
    $legacy->add(new admin_setting_description(
        'local_kbaai/legacyredirect',
        get_string('admincategory', 'local_kbaai'),
        html_writer::link(
            new moodle_url('/admin/category.php', array('category' => 'local_kbaai_admin')),
            get_string('openadmincategory', 'local_kbaai'),
            array('class' => 'btn btn-primary')
        )
    ));
    $ADMIN->add('localplugins', $legacy);
}
