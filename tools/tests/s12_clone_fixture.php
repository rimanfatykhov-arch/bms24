<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_RECOVERY_FIXTURE') !== '1') { exit(2); }
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->dirroot.'/user/lib.php');
if ($CFG->dbname !== 'recovery' || $CFG->wwwroot !== 'http://127.0.0.1:8080' ||
    (int)get_config('local_kbacompany','version') !== 2026111100 ||
    (int)get_config('local_kbacorporate','version') !== 2026111100) { exit(3); }
umask(0077);
$dir='/recovery/evidence/s12'; $phase=$argv[1] ?? '';
$admin=get_admin(); \core\session\manager::set_user($admin);
if ($phase === 'create') {
    if (file_exists($dir.'/actor.private.json')) { exit(4); }
    $nonce=bin2hex(random_bytes(5));$username='rc30s12'.$nonce;
    $password='S12!'.bin2hex(random_bytes(18)).'a9';
    $uid=(int)user_create_user((object)['username'=>$username,'password'=>$password,'auth'=>'manual',
        'confirmed'=>1,'mnethostid'=>$CFG->mnet_localhost_id,'firstname'=>'Synthetic','lastname'=>'S12',
        'email'=>$username.'@example.invalid','lang'=>'en','maildisplay'=>0],true,false);
    $role=create_role('S12 clone '.$nonce,$username,'Synthetic isolated acceptance only');
    foreach (['local/kbacompany:viewowncompany','local/kbacorporate:managecompanies',
            'local/kbacorporate:managemembers'] as $cap) {
        assign_capability($cap,CAP_ALLOW,$role,context_system::instance()->id);
    }
    role_assign($role,$uid,context_system::instance()->id);
    $f=['userid'=>$uid,'username'=>$username,'password'=>$password,'companies'=>[]];
    foreach (['A','B'] as $label) {
        $id=\local_kbacorporate\local\repository::save_company((object)['name'=>'S12 '.$nonce.' '.$label,'active'=>1]);
        $uuid=\local_kbacompany\local\identity_registry::company_uuid($id);
        $member=\local_kbacorporate\local\repository::add_member($id,$uid,'employee');
        \local_kbacompany\local\employee_service::ensure_membership($uuid,$uid,[],'bms');
        $plan=$DB->insert_record('local_kbacorp_devplan',(object)['companyid'=>$id,'userid'=>$uid,
            'title'=>'S12 private retained plan '.$nonce.' '.$label,'status'=>'draft','startdate'=>time(),
            'targetdate'=>time()+30*DAYSECS,'timecreated'=>time(),'timemodified'=>time()]);
        $f['companies'][$label]=['id'=>$id,'uuid'=>$uuid,'member'=>$member,'plan'=>$plan];
    }
    foreach (['old','new'] as $session) {
        $f['controls'][$session]=$DB->insert_record('local_kbacorp_devplan',(object)[
            'companyid'=>$f['companies']['B']['id'],'userid'=>$uid,'title'=>'S12 positive POST '.$session,
            'status'=>'pending','startdate'=>time(),'targetdate'=>time()+30*DAYSECS]);
    }
    file_put_contents($dir.'/actor.private.json',json_encode($f));
} else {
    $f=json_decode(file_get_contents($dir.'/actor.private.json'),true);
    $u=$DB->get_record('user',['id'=>$f['userid']],'*',MUST_EXIST);
    if ($u->username !== $f['username'] || strpos($u->username,'rc30s12') !== 0) { exit(5); }
    $a=$f['companies']['A'];
    if ($phase === 'revoke') {
        \local_kbacompany\local\employee_service::close_membership($a['uuid'],(int)$u->id,(int)$admin->id);
    } else if ($phase === 'reopen') {
        \local_kbacompany\local\employee_service::reopen_membership_access($a['uuid'],(int)$u->id,(int)$admin->id);
        \local_kbacompany\local\employee_service::ensure_membership($a['uuid'],(int)$u->id,[],'bms');
    } else if ($phase !== 'check') { exit(6); }
}
$history=[];
foreach ($f['companies'] as $label=>$c) {
    $history[$label]=['plan'=>$DB->get_record('local_kbacorp_devplan',['id'=>$c['plan']],'*',MUST_EXIST),
        'member'=>$DB->get_record('local_kbacorp_member',['id'=>$c['member']],'*',MUST_EXIST)];
}
$hash=hash('sha256',json_encode($history));
if ($phase==='create') { file_put_contents($dir.'/history-before.json',json_encode(['sha256'=>$hash])); }
$before=json_decode(file_get_contents($dir.'/history-before.json'),true);
$report=['phase'=>$phase,'history_unchanged'=>hash_equals($before['sha256'],$hash),
    'A_revoked'=>\local_kbacompany\local\membership_access_service::is_revoked($f['companies']['A']['uuid'],(int)$f['userid']),
    'B_revoked'=>\local_kbacompany\local\membership_access_service::is_revoked($f['companies']['B']['uuid'],(int)$f['userid'])];
foreach ($f['controls'] as $session=>$id) {
    $report['controls'][$session]=$DB->get_field('local_kbacorp_devplan','status',['id'=>$id],MUST_EXIST);
}
if ($phase==='revoke') {
    try { \local_kbacompany\local\employee_service::ensure_membership($f['companies']['A']['uuid'],(int)$f['userid'],[],'bitrix24');$report['sync_denied']=false; }
    catch (moodle_exception $e) { $report['sync_denied']=$e->errorcode==='companynotavailable'; }
}
$report['status']=$report['history_unchanged'] && !$report['B_revoked'] &&
    ($phase!=='revoke' || ($report['A_revoked'] && $report['sync_denied'])) &&
    ($phase!=='reopen' || !$report['A_revoked']) ? 'PASS':'FAIL';
file_put_contents($dir.'/state-'.$phase.'.json',json_encode($report));echo json_encode($report).PHP_EOL;
if ($report['status']!=='PASS') { exit(7); }
