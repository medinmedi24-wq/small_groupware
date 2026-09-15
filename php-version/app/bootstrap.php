<?php
declare(strict_types=1);

final class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
function config(): array {
    static $config;
    if ($config === null) {
        $file = getenv('MNM_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
        if (!is_file($file)) throw new AppError('환경설정 파일이 없습니다. 배포 안내에 따라 config/local.php를 준비해주세요.', 503);
        $config = require $file;
    }
    return $config;
}
function db(): PDO {
    static $db;
    if (!$db) {
        $c = config();
        $db = new PDO($c['dsn'], $c['username'] ?? '', $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        // Row locks serialize reservations/decisions; subsequent reads must see the latest commit.
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $db;
}
function sql(string $query, array $args = []): PDOStatement { $q=db()->prepare($query); $q->execute($args); return $q; }
function rows(string $query, array $args=[]): array { return sql($query,$args)->fetchAll(); }
function one(string $query, array $args=[]): ?array { return sql($query,$args)->fetch() ?: null; }
function scalar(string $query, array $args=[]): mixed { return sql($query,$args)->fetchColumn(); }
function uid(): string { return bin2hex(random_bytes(16)); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function koreanTime(?string $value): string {
    if($value===null||trim($value)==='') return '';
    return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s').' KST';
}
function insert(string $table,array $data): string {
    $data=['id'=>$data['id']??uid()]+$data;
    $columns=implode(',',array_map(fn($k)=>"`$k`",array_keys($data)));
    sql("INSERT INTO `$table` ($columns) VALUES (".implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    return $data['id'];
}
function update(string $table,string $id,array $data): void {
    $sets=implode(',',array_map(fn($k)=>"`$k`=?",array_keys($data)));
    sql("UPDATE `$table` SET $sets WHERE id=?",[...array_values($data),$id]);
}
function transaction(callable $fn): mixed {
    db()->beginTransaction();
    try { $r=$fn(); db()->commit(); return $r; } catch (Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
}
function lockUser(string $id): array {
    $suffix=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    return one('SELECT * FROM `User` WHERE id=?'.$suffix,[$id]) ?? throw new AppError('직원을 찾을 수 없습니다.',404);
}
function e(mixed $v): string { return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function listContext(array $input,bool $fromQuery=false): array {
    $allowed=['admin-attendance'=>['tab'=>12,'q'=>100,'department'=>100,'workStatus'=>20,'day'=>10,'month'=>7],'approvals'=>['q'=>100,'from'=>10,'to'=>10],'employees'=>['q'=>191,'department'=>100,'active'=>1],'admin-leaves'=>['q'=>191,'department'=>100,'from'=>10,'to'=>10,'status'=>30],'admin-cards'=>['q'=>191,'merchant'=>120,'from'=>10,'to'=>10,'status'=>20]];
    $queryPage=$input['page']??'';
    $page=$fromQuery&&is_string($queryPage)&&isset($allowed[$queryPage])?$queryPage:($input['return_page']??'');
    if(!is_string($page)||!isset($allowed[$page]))return [];
    $direct=$fromQuery&&($input['page']??'')===$page;
    $context=['return_page'=>$page];
    foreach($allowed[$page] as $key=>$max){$value=$input[($direct?'':'return_').$key]??'';if(is_string($value)&&mb_strlen($value)<=$max&&trim($value)!=='')$context['return_'.$key]=trim($value);}
    if($page==='admin-leaves'){
        $listPage=$input[$direct?'listPage':'return_listPage']??'';
        if(is_string($listPage)&&preg_match('/^[1-9]\d{0,5}$/D',$listPage))$context['return_listPage']=$listPage;
        $scroll=$input[$direct?'scroll':'return_scroll']??'';
        if(is_string($scroll)&&preg_match('/^\d{1,7}$/D',$scroll))$context['return_scroll']=$scroll;
    }
    return $context;
}
function url(string $page='dashboard',array $args=[]): string {
    if(in_array($page,['employee','leave-edit','leave-cancel'],true))$args+=listContext($_GET,true);
    return 'index.php?'.http_build_query(['page'=>$page]+$args);
}
function redirect(string $page='dashboard',array $args=[]): never { header('Location: '.url($page,$args),true,303); exit; }
function storage(string $relative=''): string { return rtrim(config()['storage'],'/\\').($relative!==''?DIRECTORY_SEPARATOR.$relative:''); }
function startSession(): void {
    $dir=storage('sessions'); if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new RuntimeException('Session storage unavailable');
    session_save_path($dir); session_name('mnm_php_session');
    ini_set('session.use_strict_mode','1'); ini_set('session.gc_maxlifetime','86400');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(bool)config()['secure_cookie'],'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function checkCsrf(): void { if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf']??'',$_POST['csrf'])) throw new AppError('요청 보안 토큰이 만료되었습니다. 새로고침 후 다시 시도해주세요.',403); }
function currentUser(): ?array {
    if(empty($_SESSION['token'])) return null;
    $session=one('SELECT * FROM `Session` WHERE token=?',[hash('sha256',$_SESSION['token'])]);
    $user=$session?one('SELECT * FROM `User` WHERE id=?',[$session['userId']]):null;
    if(!$session||$session['expiresAt']<now()||!$user||!$user['isActive']) { unset($_SESSION['token']); return null; }
    return $user;
}
function admin(array $u): void { if($u['role']!=='ADMIN') throw new AppError('관리자 권한이 필요합니다.',403); }
function validPassword(string $p): bool { return strlen($p)>=10&&strlen($p)<=72&&preg_match('/[A-Za-z]/',$p)&&preg_match('/\d/',$p)&&preg_match('/[^A-Za-z0-9]/',$p); }
function required(mixed $v,string $label,int $max=500): string {
    if(!is_string($v)) throw new AppError("$label 값을 확인해주세요.");
    $v=trim($v); if($v===''||mb_strlen($v)>$max) throw new AppError("$label 항목은 1~{$max}자로 입력해주세요."); return $v;
}
function number(mixed $v,float $min,float $max,string $label): float {
    if(!is_scalar($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<$min||(float)$v>$max) throw new AppError("$label 값이 올바르지 않습니다."); return (float)$v;
}
function dateValue(mixed $v): string {
    if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)) throw new AppError('날짜 형식을 확인해주세요.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d')!==$v) throw new AppError('올바른 날짜를 입력해주세요.'); return $v;
}
function dateObject(string $v): DateTimeImmutable { return new DateTimeImmutable(substr($v,0,10),new DateTimeZone('UTC')); }
function yearValue(mixed $v): int { $n=number($v,2000,2100,'연도'); if(floor($n)!==$n) throw new AppError('연도를 확인해주세요.'); return (int)$n; }
