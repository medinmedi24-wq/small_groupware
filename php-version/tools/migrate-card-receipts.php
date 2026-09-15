<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$driver=db()->getAttribute(PDO::ATTR_DRIVER_NAME);
$columns=$driver==='mysql'?array_column(rows('SHOW COLUMNS FROM `CardExpense`'),'Field'):array_column(rows('PRAGMA table_info(`CardExpense`)'),'name');
if(!$columns)throw new RuntimeException('Install the application schema first.');
if(!in_array('receiptAttachments',$columns,true)){
    db()->exec('ALTER TABLE `CardExpense` ADD COLUMN `receiptAttachments` '.($driver==='mysql'?'LONGTEXT':'TEXT').' NULL');
}
echo "Card receipt attachments schema ready. Existing receipts preserved.\n";
