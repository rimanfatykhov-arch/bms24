<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', \context_system::instance());
$params = array();
foreach (array('provider', 'run', 'full') as $name) {
    if (isset($_GET[$name])) {
        $params[$name] = clean_param($_GET[$name], $name === 'provider' ? PARAM_ALPHANUMEXT : PARAM_BOOL);
    }
}
if (!empty($_GET['sesskey'])) {
    $params['sesskey'] = clean_param($_GET['sesskey'], PARAM_ALPHANUMEXT);
}
redirect(new moodle_url('/local/kbaai/provider_test_v082.php', $params));
