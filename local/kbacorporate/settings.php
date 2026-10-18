<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_kbacorporate_admin',
        get_string('pluginname', 'local_kbacorporate')
    ));

    $settingspage = new admin_settingpage(
        'local_kbacorporate_settings',
        get_string('settings', 'local_kbacorporate')
    );
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/sessiongapminutes',
        get_string('sessiongapminutes', 'local_kbacorporate'),
        get_string('sessiongapminutes_desc', 'local_kbacorporate'),
        30,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/eventtailminutes',
        get_string('eventtailminutes', 'local_kbacorporate'),
        get_string('eventtailminutes_desc', 'local_kbacorporate'),
        5,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/bbbanalyticsheading',
        get_string('bbbanalyticsheading', 'local_kbacorporate'),
        get_string('bbbanalyticsheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/bbblatetoleranceminutes',
        get_string('bbblatetoleranceminutes', 'local_kbacorporate'),
        get_string('bbblatetoleranceminutes_desc', 'local_kbacorporate'),
        10,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/bbbearlyleavetoleranceminutes',
        get_string('bbbearlyleavetoleranceminutes', 'local_kbacorporate'),
        get_string('bbbearlyleavetoleranceminutes_desc', 'local_kbacorporate'),
        10,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/bbbmaxwindowminutes',
        get_string('bbbmaxwindowminutes', 'local_kbacorporate'),
        get_string('bbbmaxwindowminutes_desc', 'local_kbacorporate'),
        720,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/licensesettingsheading',
        get_string('licensesettingsheading', 'local_kbacorporate'),
        get_string('licensesettingsheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/licenseusagewarningpercent',
        get_string('licenseusagewarningpercent', 'local_kbacorporate'),
        get_string('licenseusagewarningpercent_desc', 'local_kbacorporate'),
        80,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/licenseexpiringdays',
        get_string('licenseexpiringdays', 'local_kbacorporate'),
        get_string('licenseexpiringdays_desc', 'local_kbacorporate'),
        30,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/licensecriticaldays',
        get_string('licensecriticaldays', 'local_kbacorporate'),
        get_string('licensecriticaldays_desc', 'local_kbacorporate'),
        7,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/gamificationsettingsheading',
        get_string('gamificationsettingsheading', 'local_kbacorporate'),
        get_string('gamificationsettingsheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_kbacorporate/gamificationenabled',
        get_string('gamificationenabled', 'local_kbacorporate'),
        get_string('gamificationenabled_desc', 'local_kbacorporate'),
        1
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_kbacorporate/gamificationshowleaderboard',
        get_string('gamificationshowleaderboard', 'local_kbacorporate'),
        get_string('gamificationshowleaderboard_desc', 'local_kbacorporate'),
        1
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_kbacorporate/gamificationshowcoins',
        get_string('gamificationshowcoins', 'local_kbacorporate'),
        get_string('gamificationshowcoins_desc', 'local_kbacorporate'),
        0
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/gamificationleaderboardlimit',
        get_string('gamificationleaderboardlimit', 'local_kbacorporate'),
        get_string('gamificationleaderboardlimit_desc', 'local_kbacorporate'),
        20,
        PARAM_INT
    ));

    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/attentionthresholdsheading',
        get_string('attentionthresholdsheading', 'local_kbacorporate'),
        get_string('attentionthresholdsheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentionnotstarteddays',
        get_string('attentionnotstarteddays', 'local_kbacorporate'),
        get_string('attentionnotstarteddays_desc', 'local_kbacorporate'),
        3,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentioninactivitydays',
        get_string('attentioninactivitydays', 'local_kbacorporate'),
        get_string('attentioninactivitydays_desc', 'local_kbacorporate'),
        7,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentionprogressthreshold',
        get_string('attentionprogressthreshold', 'local_kbacorporate'),
        get_string('attentionprogressthreshold_desc', 'local_kbacorporate'),
        50,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentiongradethreshold',
        get_string('attentiongradethreshold', 'local_kbacorporate'),
        get_string('attentiongradethreshold_desc', 'local_kbacorporate'),
        60,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentiontrainingthreshold',
        get_string('attentiontrainingthreshold', 'local_kbacorporate'),
        get_string('attentiontrainingthreshold_desc', 'local_kbacorporate'),
        80,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/attentionwebinarthreshold',
        get_string('attentionwebinarthreshold', 'local_kbacorporate'),
        get_string('attentionwebinarthreshold_desc', 'local_kbacorporate'),
        70,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configselect(
        'local_kbacorporate/attentiondigestminseverity',
        get_string('attentiondigestminseverity', 'local_kbacorporate'),
        get_string('attentiondigestminseverity_desc', 'local_kbacorporate'),
        'medium',
        [
            'high' => get_string('riskseverity_high', 'local_kbacorporate'),
            'medium' => get_string('riskseverity_medium', 'local_kbacorporate'),
            'low' => get_string('riskseverity_low', 'local_kbacorporate'),
        ]
    ));
    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/kbaconnectheading',
        get_string('kbaconnectheading', 'local_kbacorporate'),
        get_string('kbaconnectheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/erpauditretentiondays',
        get_string('erpauditretentiondays', 'local_kbacorporate'),
        get_string('erpauditretentiondays_desc', 'local_kbacorporate'),
        180,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/kbaconnectbaseurl',
        get_string('kbaconnectbaseurl', 'local_kbacorporate'),
        get_string('kbaconnectbaseurl_desc', 'local_kbacorporate'),
        '',
        PARAM_URL
    ));
    $settingspage->add(new admin_setting_heading(
        'local_kbacorporate/implementationremindersheading',
        get_string('implementationremindersheading', 'local_kbacorporate'),
        get_string('implementationremindersheading_desc', 'local_kbacorporate')
    ));
    $settingspage->add(new admin_setting_configcheckbox(
        'local_kbacorporate/implementationremindersenabled',
        get_string('implementationremindersenabled', 'local_kbacorporate'),
        get_string('implementationremindersenabled_desc', 'local_kbacorporate'),
        1
    ));
    $settingspage->add(new admin_setting_configtext(
        'local_kbacorporate/implementationreminderdays',
        get_string('implementationreminderdays', 'local_kbacorporate'),
        get_string('implementationreminderdays_desc', 'local_kbacorporate'),
        3,
        PARAM_INT
    ));
    $settingspage->add(new admin_setting_heading('local_kbacorporate/bitrixprogramheading',
        get_string('bitrixprogramheading', 'local_kbacorporate'), get_string('bitrixprogramheading_desc', 'local_kbacorporate')));
    $settingspage->add(new admin_setting_configpasswordunmask('local_kbacorporate/bitrixprogramwebhook',
        get_string('bitrixprogramwebhook', 'local_kbacorporate'), get_string('bitrixprogramwebhook_desc', 'local_kbacorporate'), ''));
    $settingspage->add(new admin_setting_configcheckbox('local_kbacorporate/bitrixprogramautocreate',
        get_string('bitrixprogramautocreate', 'local_kbacorporate'), get_string('bitrixprogramautocreate_desc', 'local_kbacorporate'), 0));

    $ADMIN->add('local_kbacorporate_admin', $settingspage);
    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage('local_kbacorporate_bitrixprogramselector',
        get_string('bitrixprogramselector', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/bitrix_program_settings.php'), 'moodle/site:config'));
    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage('local_kbacorporate_implpackages',
        get_string('implementationpackages', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/implementation_packages.php'),
        'local/kbacorporate:manageimplementationprograms'));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_versioninfo',
        get_string('versioninfo', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/versioninfo.php'),
        'local/kbacorporate:managecompanies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_companies',
        get_string('companies', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/companies.php'),
        'local/kbacorporate:managecompanies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_businessroles',
        get_string('businessroles', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/business_roles.php'),
        'local/kbacorporate:managerolecompetencies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_competencyaudits',
        get_string('competencyaudits', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/competency_audits.php'),
        'local/kbacorporate:managecompetencyaudits'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_competencymap',
        get_string('competencymap', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/competency_map.php'),
        'local/kbacorporate:viewcompetencies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_developmentplans',
        get_string('developmentplans', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/development_plans.php'),
        'local/kbacorporate:managedevelopmentplans'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_licenses',
        get_string('licensesmanage', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/companies.php'),
        'local/kbacorporate:managelicenses'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_kbaconnect',
        get_string('kbaconnectconnection', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/kbaconnect.php'),
        'local/kbacorporate:managecompanies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_bitrixroles',
        get_string('bitrixrolesprovisioning', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/bitrixroles.php'),
        'local/kbacorporate:managecompanies'
    ));


    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_health',
        get_string('healthcenter', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/health.php'),
        'local/kbacorporate:managecompanies'
    ));

    $ADMIN->add('local_kbacorporate_admin', new admin_externalpage(
        'local_kbacorporate_api',
        get_string('apidiagnostics', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/api.php'),
        'local/kbacorporate:managecompanies'
    ));
}
