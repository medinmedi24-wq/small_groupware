<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/domain.php';
$input=$argv[1]??'';
if(!$input||!is_file($input)){fwrite(STDERR,"Usage: php tools/import.php export.zip\nPrepare an empty database with database/mysql.sql first.\n");exit(1);}
$zip=new ZipArchive();if($zip->open($input)!==true)throw new RuntimeException('Cannot open export');
$manifest=json_decode($zip->getFromName('manifest.json')?:'',true,512,JSON_THROW_ON_ERROR);
$data=json_decode($zip->getFromName('data.json')?:'',true,512,JSON_THROW_ON_ERROR);
$tables=['User','CompanyHoliday','LeaveRequest','LeaveHistory','LeaveBalanceAdjustment','CardExpense','ReportDelivery'];
if(($manifest['format']??null)!==1||array_keys($data)!==$tables)throw new RuntimeException('Unsupported export');
foreach([...$tables,'Session'] as $table)if((int)scalar("SELECT COUNT(*) FROM `$table`")!==0)throw new RuntimeException('Destination must be empty: '.$table);
if(!array_filter($data['User'],fn($u)=>$u['role']==='ADMIN'&&$u['isActive']))throw new RuntimeException('No active administrator');
$dir=storage('receipts');if(!is_dir($dir))mkdir($dir,0700,true);$written=[];
try{
    foreach($manifest['receipts'] as $name=>$hash){if(basename($name)!==$name||str_contains($name,'\\')||str_contains($name,'/'))throw new RuntimeException('Unsafe receipt path');$bytes=$zip->getFromName('receipts/'.$name);if($bytes===false||!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Receipt checksum mismatch');$file=$dir.DIRECTORY_SEPARATOR.$name;if(is_file($file))throw new RuntimeException('Receipt already exists');$f=fopen($file,'xb');if(!$f)throw new RuntimeException('Receipt storage unavailable');$written[]=$file;if(fwrite($f,$bytes)!==strlen($bytes))throw new RuntimeException('Receipt write failed');fclose($f);}
    transaction(function()use($tables,$data,$manifest){foreach($tables as $table){$columns=array_keys(one("SELECT * FROM `$table` LIMIT 1")??[]);$driver=db()->getAttribute(PDO::ATTR_DRIVER_NAME);$columns=$driver==='mysql'?array_column(rows("SHOW COLUMNS FROM `$table`"),'Field'):array_column(rows("PRAGMA table_info(`$table`)"),'name');foreach($data[$table] as $row){if(array_diff(array_keys($row),$columns))throw new RuntimeException('Unknown import column');insert($table,$row);}if((int)scalar("SELECT COUNT(*) FROM `$table`")!==(int)$manifest['counts'][$table])throw new RuntimeException('Import count mismatch');}});
}catch(Throwable $e){foreach($written as $file)@unlink($file);throw $e;}finally{$zip->close();}
echo json_encode(['imported'=>true,'counts'=>$manifest['counts'],'receipts'=>count($written),'sessionsMigrated'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;
