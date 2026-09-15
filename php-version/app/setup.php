<?php
declare(strict_types=1);
function installSchema(): void {
    $driver=db()->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=file_get_contents(dirname(__DIR__).'/database/'.($driver==='mysql'?'mysql':'sqlite').'.sql');
    // DDL is deliberately separate from data transactions (MySQL implicitly commits DDL).
    foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec($statement);
}
function createAdministrator(string $login,string $name,string $password): void {
    if((int)scalar('SELECT COUNT(*) FROM `User`')!==0)throw new AppError('이미 사용자가 존재합니다. 초기 설치는 다시 실행할 수 없습니다.',403);
    if(!validPassword($password))throw new AppError('비밀번호는 10~72바이트, 영문·숫자·특수문자를 포함해야 합니다.');
    insert('User',['email'=>strtolower(required($login,'아이디',191)),'name'=>required($name,'이름',100),'passwordHash'=>password_hash($password,PASSWORD_BCRYPT),'department'=>'경영지원팀','position'=>'관리자','role'=>'ADMIN','joinDate'=>gmdate('Y-m-d').' 00:00:00','annualLeave'=>15,'isActive'=>1,'mustChangePassword'=>1,'createdAt'=>now(),'updatedAt'=>now()]);
}
function setupPage(): void {
    $token=(string)(config()['setup_token']??'');if(strlen($token)<32)throw new AppError('초기 설치가 비활성화되어 있습니다.',403);
    try{$count=(int)scalar('SELECT COUNT(*) FROM `User`');if($count>0)throw new AppError('이미 설치되어 있습니다.',403);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['42S02','HY000'],true))throw $e;}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        checkCsrf();if(!hash_equals($token,(string)($_POST['setup_token']??'')))throw new AppError('설치 토큰이 올바르지 않습니다.',403);
        $p=(string)($_POST['password']??'');if(!validPassword($p))throw new AppError('비밀번호는 영문·숫자·특수문자를 포함한 10~72바이트로 설정해주세요.');
        $login=required($_POST['email']??'','아이디',191);$name=required($_POST['name']??'','이름',100);
        // Lock shared storage so two initial setup requests cannot race.
        $handle=fopen(storage('setup.lock'),'c');if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Setup lock unavailable');
        try{
            try{$exists=scalar('SELECT COUNT(*) FROM `User`');}catch(PDOException $e){$exists=null;}
            if($exists===null)installSchema();
            createAdministrator($login,$name,$p);
        }finally{flock($handle,LOCK_UN);fclose($handle);}
        $_SESSION['flash']='설치했습니다. 설정에서 setup_token을 비우고 로그인해주세요.';redirect('login');
    }
    title('그룹웨어 초기 설치');formStart('setup');field('setup_token','설치 토큰','','password','required autocomplete="off"');field('email','관리자 아이디','','text','required');field('name','관리자 이름','','text','required');field('password','관리자 비밀번호','','password','required minlength="10" maxlength="72" autocomplete="new-password"');echo '<button class="primary">초기 설치</button></form>';
}
