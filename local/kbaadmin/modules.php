<?php
// This file is part of Moodle - http://moodle.org/
//
// Unified KBA page for selected Moodle activity/resource settings.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_kbaadmin_modules');
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/kbaadmin/modules.php'));
$PAGE->set_title(get_string('pagetitle', 'local_kbaadmin'));
$PAGE->set_heading($SITE->fullname);
$PAGE->set_pagelayout('admin');
$PAGE->requires->css(new moodle_url('/local/kbaadmin/styles.css'));
$PAGE->requires->js(new moodle_url('/local/kbaadmin/js/modules.js'));

// The section names below are the native Moodle admin_settingpage identifiers.
// In Moodle 4.0 assign is nested under the admin category "modassignfolder",
// but the actual settings page is still "modsettingassign".
$registry = [
    'label' => [
        'section' => 'modsettinglabel',
        'title' => get_string('module_label_title', 'local_kbaadmin'),
        'description' => get_string('module_label_desc', 'local_kbaadmin'),
        'component' => 'mod_label',
        'symbol' => 'T',
        'group' => get_string('groupmaterials', 'local_kbaadmin'),
    ],
    'folder' => [
        'section' => 'modsettingfolder',
        'title' => get_string('module_folder_title', 'local_kbaadmin'),
        'description' => get_string('module_folder_desc', 'local_kbaadmin'),
        'component' => 'mod_folder',
        'symbol' => 'F',
        'group' => get_string('groupmaterials', 'local_kbaadmin'),
    ],
    'assign' => [
        'section' => 'modsettingassign',
        'title' => get_string('module_assign_title', 'local_kbaadmin'),
        'description' => get_string('module_assign_desc', 'local_kbaadmin'),
        'component' => 'mod_assign',
        'symbol' => 'A',
        'group' => get_string('grouplearning', 'local_kbaadmin'),
    ],
];

navigation_node::require_admin_tree();
$adminroot = admin_get_root();

// Save settings through the same core function used by /admin/settings.php.
$errormsg = '';
if ($data = data_submitted()) {
    if (confirm_sesskey() && isset($data->action) && $data->action === 'save-settings') {
        $count = admin_write_settings($data);
        if (empty($adminroot->errors)) {
            redirect(
                $PAGE->url,
                get_string('changesaved', 'local_kbaadmin'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        $errormsg = get_string('settingsfailed', 'local_kbaadmin');
        // Re-locate pages because admin_write_settings reloads the admin tree.
        $adminroot = admin_get_root();
    }
}

$modules = [];
foreach ($registry as $key => $item) {
    $page = $adminroot->locate($item['section'], true);
    if ($page instanceof admin_settingpage && $page->check_access()) {
        $item['key'] = $key;
        $item['page'] = $page;
        $item['standardurl'] = new moodle_url('/admin/settings.php', ['section' => $item['section']]);
        $modules[] = $item;

        if ($page->has_dependencies()) {
            $PAGE->requires->js_call_amd('core/showhidesettings', 'init', [[
                'dependencies' => $page->get_dependencies_for_javascript(),
            ]]);
        }
    }
}

// Keep Moodle's standard unsaved-change protection for this unified form.
$PAGE->requires->js_call_amd('core_form/changechecker', 'watchFormById', ['adminsettings']);

echo $OUTPUT->header();

if ($errormsg !== '') {
    echo $OUTPUT->notification($errormsg, \core\output\notification::NOTIFY_ERROR);
}

?>
<div class="kba-modules-page" data-kba-modules-page>
    <section class="kba-hero">
        <div>
            <div class="kba-eyebrow">Kazan Business Academy</div>
            <h2><?php echo s(get_string('pagetitle', 'local_kbaadmin')); ?></h2>
            <p><?php echo s(get_string('pagesubtitle', 'local_kbaadmin')); ?></p>
        </div>
        <div class="kba-hero-count" aria-hidden="true">
            <strong><?php echo count($modules); ?></strong>
            <span>module settings</span>
        </div>
    </section>

    <?php if (empty($modules)): ?>
        <div class="alert alert-warning">
            <?php echo s(get_string('nosettingsfound', 'local_kbaadmin')); ?>
        </div>
    <?php else: ?>
        <div class="kba-toolbar">
            <label class="kba-search">
                <span class="sr-only"><?php echo s(get_string('searchplaceholder', 'local_kbaadmin')); ?></span>
                <span class="kba-search-icon" aria-hidden="true">⌕</span>
                <input type="search"
                       class="form-control"
                       placeholder="<?php echo s(get_string('searchplaceholder', 'local_kbaadmin')); ?>"
                       data-kba-search>
            </label>
        </div>

        <div class="kba-module-cards" data-kba-cards>
            <?php foreach ($modules as $item): ?>
                <button type="button"
                        class="kba-module-card"
                        data-kba-open="<?php echo s($item['key']); ?>"
                        data-kba-search-text="<?php echo s(core_text::strtolower($item['title'] . ' ' . $item['description'] . ' ' . $item['component'])); ?>">
                    <span class="kba-module-symbol" aria-hidden="true"><?php echo s($item['symbol']); ?></span>
                    <span class="kba-module-card-copy">
                        <span class="kba-module-group"><?php echo s($item['group']); ?></span>
                        <strong><?php echo s($item['title']); ?></strong>
                        <span><?php echo s($item['description']); ?></span>
                        <small><?php echo s($item['component']); ?></small>
                    </span>
                    <span class="kba-card-arrow" aria-hidden="true">→</span>
                </button>
            <?php endforeach; ?>
        </div>

        <form action="<?php echo $PAGE->url->out(false); ?>" method="post" id="adminsettings" class="kba-settings-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save-settings">

            <div class="kba-settings-sections">
                <?php foreach ($modules as $index => $item): ?>
                    <?php
                    $sectionid = 'kba-section-' . $item['key'];
                    $isopen = !empty($adminroot->errors) || $index === 0;
                    ?>
                    <section class="kba-settings-section<?php echo $isopen ? ' is-open' : ''; ?>"
                             id="<?php echo s($sectionid); ?>"
                             data-kba-section="<?php echo s($item['key']); ?>"
                             data-kba-search-text="<?php echo s(core_text::strtolower($item['title'] . ' ' . $item['description'] . ' ' . $item['component'])); ?>">
                        <button type="button"
                                class="kba-section-toggle"
                                aria-expanded="<?php echo $isopen ? 'true' : 'false'; ?>"
                                aria-controls="<?php echo s($sectionid); ?>-body"
                                data-kba-toggle>
                            <span class="kba-module-symbol kba-module-symbol-small" aria-hidden="true"><?php echo s($item['symbol']); ?></span>
                            <span class="kba-section-title">
                                <span class="kba-module-group"><?php echo s($item['group']); ?></span>
                                <strong><?php echo s($item['title']); ?></strong>
                                <small><?php echo s($item['component']); ?></small>
                            </span>
                            <span class="kba-chevron" aria-hidden="true">⌄</span>
                        </button>

                        <div class="kba-section-body" id="<?php echo s($sectionid); ?>-body">
                            <div class="kba-section-intro">
                                <p><?php echo s($item['description']); ?></p>
                                <a href="<?php echo $item['standardurl']->out(false); ?>" target="_self" class="kba-standard-link">
                                    <?php echo s(get_string('standardpage', 'local_kbaadmin')); ?> ↗
                                </a>
                            </div>
                            <div class="kba-native-settings">
                                <?php echo $item['page']->output_html(); ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="kba-savebar">
                <div class="kba-savebar-copy">
                    <strong><?php echo s(get_string('pagetitle', 'local_kbaadmin')); ?></strong>
                    <span><?php echo s(get_string('pagesubtitle', 'local_kbaadmin')); ?></span>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <?php echo s(get_string('savechanges', 'local_kbaadmin')); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php

echo $OUTPUT->footer();
