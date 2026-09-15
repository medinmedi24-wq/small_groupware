<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/setup.php';
$password=getenv('MNM_ADMIN_PASSWORD')?:'';$login=$argv[1]??'';$name=$argv[2]??'관리자';
if(!$login||!validPassword($password)){fwrite(STDERR,"Usage: set MNM_ADMIN_PASSWORD, then php tools/install.php LOGIN NAME\nPassword must include letters, digits and symbols (10-72 bytes).\n");exit(1);}
try{$count=scalar('SELECT COUNT(*) FROM `User`');}catch(PDOException $e){$count=null;}
if($count===null)installSchema();
createAdministrator($login,$name,$password);
echo "Administrator created. Change the password after first login.\n";
