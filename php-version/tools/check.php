<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$checks=['php_82_or_later'=>version_compare(PHP_VERSION,'8.2','>=')];
foreach(['pdo','pdo_mysql','mbstring','fileinfo','zip'] as $ext)$checks[$ext]=extension_loaded($ext);
try{$checks['config']=true;$c=config();$checks['storage_writable']=is_dir(storage())&&is_writable(storage());$checks['db_connection']=(int)scalar('SELECT 1')===1;$checks['active_admin']=(int)scalar("SELECT COUNT(*) FROM `User` WHERE role='ADMIN' AND isActive=1")>0;$checks['secure_cookie']=(bool)$c['secure_cookie'];$checks['setup_disabled']=empty($c['setup_token']);}catch(Throwable $e){$checks['config_or_database']=false;}
echo json_encode($checks,JSON_PRETTY_PRINT).PHP_EOL;exit(in_array(false,$checks,true)?1:0);
