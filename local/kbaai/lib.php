<?php
// Navigation callbacks for KBA AI Assessor.
defined('MOODLE_INTERNAL') || die();

/**
 * Extend settings navigation on KBA Element pages.
 *
 * Moodle 4.0 builds activity administration under the `modulesettings`
 * node. Adding a local-plugin link only to the settings-navigation root can
 * leave it invisible in the activity secondary navigation / More menu.
 * Therefore we attach our item to `modulesettings` when available and fall
 * back to the root only if Moodle did not create that node.
 *
 * @param settings_navigation $nav
 * @param context $context
 * @return void
 */
function local_kbaai_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;

    if (empty($PAGE->cm) || empty($PAGE->cm->id) || $PAGE->cm->modname !== 'kbaelement') {
        return;
    }

    // Do not rely on the callback context: some pages may initialise settings
    // navigation with a parent context. Resolve the real module context from cm.
    $modulecontext = context_module::instance((int)$PAGE->cm->id);
    $cmid = (int)$PAGE->cm->id;

    $canmanage = is_siteadmin()
        || has_capability('mod/kbaelement:grade', $modulecontext)
        || has_capability('local/kbaai:manage', $modulecontext);

    if ($canmanage) {
        $text = get_string('pluginname', 'local_kbaai');
        $url = new moodle_url('/local/kbaai/console.php', array('cmid' => $cmid));
        $key = 'local_kbaai_manage';
    } else if (has_capability('local/kbaai:viewownfeedback', $modulecontext)) {
        $text = get_string('aifeedback', 'local_kbaai');
        $url = new moodle_url('/local/kbaai/student.php', array('cmid' => $cmid));
        $key = 'local_kbaai_student';
    } else {
        return;
    }

    $parent = $nav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$parent) {
        $parent = $nav;
    }

    // Avoid a duplicate if navigation is rebuilt more than once in the request.
    if ($parent->get($key, navigation_node::TYPE_SETTING)) {
        return;
    }

    $node = $parent->add(
        $text,
        $url,
        navigation_node::TYPE_SETTING,
        null,
        $key,
        new pix_icon('i/settings', '')
    );

    // Moodle 4.0 secondary navigation can surface children of the activity
    // administration node. Keep the item eligible for that navigation.
    if (property_exists($node, 'showinsecondarynavigation')) {
        $node->showinsecondarynavigation = true;
    }
}
