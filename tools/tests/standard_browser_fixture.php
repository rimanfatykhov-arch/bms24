<?php
/** Clone-only browser fixture; never run through the web or against production. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_RECOVERY_FIXTURE') !== '1') { exit(2); }
$scratch = realpath(getenv('TEST_ROOT') ?: '');
$runner = realpath(getenv('RUNNER_TEMP') ?: '');
$core = realpath($argv[1] ?? '');
if (!$scratch || !$runner || strpos($scratch, $runner . '/') !== 0 ||
        $core !== $scratch . '/fresh/core') { exit(2); }
if (!$core || !is_file($core . '/config.php')) { exit(2); }
define('CLI_SCRIPT', true);
require $core . '/config.php';
require_once($CFG->dirroot . '/user/lib.php');
if ($CFG->wwwroot !== 'http://127.0.0.1:8080' || !preg_match('/^bmsci_[a-f0-9]{10}_fresh$/D', $CFG->dbname) || $CFG->dbhost !== '127.0.0.1' ||
        (string)get_config('local_kbacompany', 'version') !== '2026111100' ||
        (string)get_config('local_kbacorporate', 'version') !== '2026111100') {
    fwrite(STDERR, "Refused: clone identity/version mismatch.\n"); exit(2);
}
$private = $scratch . '/actors.private.json';
if (!is_dir(dirname($private)) || is_link(dirname($private)) || file_exists($private) || is_link($private)) {
    fwrite(STDERR, "Refused: evidence location unavailable or already initialized.\n"); exit(2);
}
umask(0077);
$handle = fopen($private, 'x');
if (!$handle || !chmod($private, 0600)) { throw new RuntimeException('Private evidence unavailable'); }
$record = ['status'=>'INITIALIZING', 'created_utc'=>gmdate('c'), 'wwwroot'=>$CFG->wwwroot,
    'companies'=>[], 'actors'=>[], 'tasks'=>[], 'documents'=>[], 'plans'=>[], 'roles'=>[],
    'revocation'=>'RA initially active. Revoke only its recorded fixture memberships after baseline login.'];
$persist = static function() use (&$record, $handle): void {
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    rewind($handle); ftruncate($handle, 0);
    if (fwrite($handle, $json) !== strlen($json)) { throw new RuntimeException('Private evidence write failed'); }
    fflush($handle);
};
$persist();
try {
    $transaction = $DB->start_delegated_transaction();
    $admin = get_admin();
    \core\session\manager::set_user($admin);
    $context = context_system::instance();
    $nonce = bin2hex(random_bytes(5));
    $base = 'rc30browser' . $nonce;
    $viewrole = create_role('RC30 clone browser ' . $nonce, $base . 'view', 'Clone-only synthetic account role');
    $managerrole = create_role('RC30 clone scoped manager ' . $nonce, $base . 'manager', 'Company access still requires membership');
    assign_capability('local/kbacompany:viewowncompany', CAP_ALLOW, $viewrole, $context->id);
    assign_capability('local/kbacorporate:managedevelopmentplans', CAP_ALLOW, $managerrole, $context->id);
    assign_capability('local/kbacompany:manageorganization', CAP_ALLOW, $managerrole, $context->id);
    assign_capability('local/kbacompany:managepeople', CAP_ALLOW, $managerrole, $context->id);
    $record['roles'] = ['view'=>$viewrole, 'manager'=>$managerrole];
    foreach (['A','B'] as $tenant) {
        $cid = \local_kbacorporate\local\repository::save_company((object)[
            'name'=>'RC30 synthetic '.$nonce.' '.$tenant, 'active'=>1]);
        $uuid = \local_kbacompany\local\identity_registry::company_uuid($cid);
        $record['companies'][$tenant] = ['id'=>$cid,'uuid'=>$uuid];
    }
    foreach (['MA'=>['A',true], 'EA'=>['A',false], 'MB'=>['B',true], 'EB'=>['B',false], 'RA'=>['A',false]] as $label=>$spec) {
        [$tenant,$manager] = $spec;
        $password = 'Rc30!' . bin2hex(random_bytes(18)) . 'a9';
        $username = $base . strtolower($label);
        $uid = user_create_user((object)['username'=>$username, 'password'=>$password, 'auth'=>'manual',
            'confirmed'=>1, 'mnethostid'=>$CFG->mnet_localhost_id, 'firstname'=>'Synthetic',
            'lastname'=>$label, 'email'=>$username.'@example.invalid', 'lang'=>'en', 'maildisplay'=>0], true, false);
        role_assign($viewrole, $uid, $context->id);
        if ($manager) { role_assign($managerrole, $uid, $context->id); }
        $company = $record['companies'][$tenant];
        // Narrow fixture insertion mirrors document/task tests and avoids repository
        // ensure_manager_role modifying a shared restored Moodle role definition.
        $cm = $DB->insert_record('local_kbacorp_member', (object)['companyid'=>$company['id'],
            'userid'=>$uid, 'membertype'=>$manager?'manager':'employee', 'isemployee'=>$manager?0:1,
            'ismanager'=>$manager?1:0, 'isdepartmentmanager'=>0, 'ishr'=>0,
            'timecreated'=>time(), 'timemodified'=>time()]);
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $muuid = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        $bm = $DB->insert_record('local_kbacompany_member', (object)['uuid'=>$muuid,
            'companyuuid'=>$company['uuid'], 'userid'=>$uid, 'membertype'=>$manager?'manager':'employee',
            'active'=>1, 'isemployee'=>$manager?0:1]);
        if ($manager) {
            $role = \local_kbacompany\local\access_role_service::create_role($company['uuid'], (int)$admin->id, 'Clone task manager '.$nonce, '');
            $record['roles']['bms_'.$label] = $role;
            \local_kbacompany\local\access_role_service::set_grant($company['uuid'], (int)$admin->id, $role, 'task', 'edit', true);
            \local_kbacompany\local\access_role_service::assign($company['uuid'], (int)$admin->id, $role, $uid, true);
        }
        if (is_siteadmin($uid) || has_capability('local/kbacompany:viewallcompanies',$context,$uid)) {
            throw new RuntimeException('Unexpected broad actor permission');
        }
        $record['actors'][$label] = ['userid'=>$uid,'username'=>$username,'password'=>$password,
            'tenant'=>$tenant,'corporate_membership'=>$cm,'bms_membership'=>$bm,'bms_membership_uuid'=>$muuid];
        $persist();
    }
    foreach (['A','B'] as $tenant) {
        $company = $record['companies'][$tenant];
        $manager = $record['actors']['M'.$tenant]['userid'];
        $employee = $record['actors']['E'.$tenant]['userid'];
        \core\session\manager::set_user(\core_user::get_user($manager));
        if (!\local_kbacompany\local\task_service::can_manage($company['uuid'],$manager)) {
            throw new RuntimeException('Scoped manager fixture unavailable');
        }
        $record['tasks'][$tenant] = \local_kbacompany\local\task_service::create($company['uuid'],$manager,[
            'title'=>'RC30 PRIVATE '.$nonce.' '.$tenant,'product'=>'Synthetic browser result',
            'description'=>'Synthetic clone-only task','assigneeid'=>$employee,'reviewerid'=>0,
            'observerid'=>0,'priority'=>'normal','stream'=>'operate','startdate'=>'',
            'duedate'=>date('Y-m-d',time()+7*DAYSECS),'planminutes'=>30]);
        \core\session\manager::set_user($admin);
        $record['documents'][$tenant] = \local_kbacompany\local\document_service::create_manual($company['uuid'],(int)$admin->id,[
            'code'=>'RC30-'.$nonce.'-'.$tenant,'title'=>'RC30 PRIVATE DOCUMENT '.$tenant,
            'doctype'=>'procedure','doclevel'=>2,'content'=>'Synthetic tenant '.$tenant.' restricted marker '.$nonce]);
    }
    foreach (['tasks'=>'local_kbacompany_task','documents'=>'local_kbacompany_doc'] as $collection=>$table) {
        foreach (['A','B'] as $tenant) {
            $obj=$DB->get_record($table,['companyuuid'=>$record['companies'][$tenant]['uuid'],'uuid'=>$record[$collection][$tenant]],'*',MUST_EXIST);
            $record[$collection][$tenant]=['uuid'=>$obj->uuid,'title'=>$obj->title];
        }
    }
    // A minimal draft plan deliberately has no fabricated role-gap or competency
    // evidence. It tests own/foreign/revoked route access, not full plan lifecycle.
    $record['plans']['RA'] = $DB->insert_record('local_kbacorp_devplan',(object)[
        'companyid'=>$record['companies']['A']['id'],'userid'=>$record['actors']['RA']['userid'],
        'title'=>'RC30 revoked-owner probe '.$nonce,'status'=>'draft','createdby'=>$record['actors']['MA']['userid'],
        'timecreated'=>time(),'timemodified'=>time()]);
    $record['status']='PREPARED'; $persist();
    $transaction->allow_commit();
    $record['status']='READY'; $persist();
    fclose($handle);
    echo "PASS clone-only fixtures prepared; private evidence recorded.\n";
} catch (Throwable $error) {
    $record['status']='FAILED'; $record['failure_class']=get_class($error); $record['failure_file']=$error->getFile(); $record['failure_line']=$error->getLine(); $record['failure_trace']=array_map(static fn($f)=>array_intersect_key($f,array_flip(['file','line','class','function'])), $error->getTrace()); $persist();
    // Do not emit exception text: SQL diagnostics can contain account/password data.
    if (isset($transaction)) { try { $transaction->rollback($error); } catch (Throwable $ignored) {} }
    fclose($handle);
    fwrite(STDERR, "FAIL clone fixture setup; private evidence retained.\n"); exit(1);
}



