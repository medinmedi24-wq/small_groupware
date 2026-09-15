<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/domain.php';
require dirname(__DIR__).'/app/actions.php';
require dirname(__DIR__).'/app/views.php';
require dirname(__DIR__).'/app/downloads.php';
require dirname(__DIR__).'/app/setup.php';
header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
$u=null;$error=null;$content='';$attendanceRevision=null;$page=is_string($_GET['page']??null)?$_GET['page']:'dashboard';
try {
    date_default_timezone_set(config()['timezone']??'Asia/Seoul');startSession();
    // Page links and filters accept scalar query values only. Remove malformed
    // values before rendering an error so templates cannot cast arrays to strings.
    $invalidQuery=false;
    foreach($_GET as $key=>$value)if(!is_string($value)){unset($_GET[$key]);$invalidQuery=true;}
    if($invalidQuery){$u=currentUser();throw new AppError('주소의 요청 값을 확인해주세요.',400);}
    if($page==='setup'){ob_start();try{echo '<section class="setup panel formpanel">';setupPage();echo '</section>';$content=ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}}
    else {
        $u=currentUser();
        if($page==='attendance-revision'){
            header('Content-Type: application/json; charset=UTF-8');
            try{
                if($_SERVER['REQUEST_METHOD']!=='GET')throw new AppError('GET 요청만 허용됩니다.',405);
                if(!$u)throw new AppError('로그인이 필요합니다.',401);
                if($u['mustChangePassword'])throw new AppError('비밀번호를 먼저 변경해주세요.',403);
                echo json_encode(['revision'=>attendanceRevision($u,$_GET['scope']??'attendance')],JSON_THROW_ON_ERROR);
            }catch(AppError $ex){http_response_code($ex->status);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
            exit;
        }
        if($page==='attendance-network')attendanceNetworkCheck($u);
        if($_SERVER['REQUEST_METHOD']==='POST') {
            try { checkCsrf(); }
            catch(AppError $ex) {
                if(!$u&&($_POST['action']??null)==='login') {
                    $_SESSION['flash']='로그인 화면을 갱신했습니다. 아이디와 비밀번호를 다시 입력해주세요.';
                    redirect('login');
                }
                throw $ex;
            }
            if(!is_string($_POST['action']??null))throw new AppError('요청을 확인해주세요.');
            $result=handleAction($_POST['action'],$u,$_POST);
            [$next,$message,$args]=array_pad(returnToFilteredList($result,$_POST['action'],$u,$_POST),3,[]);$_SESSION['flash']=$message;redirect($next,$args);
        }
        if(!$u)$page='login';elseif($u['mustChangePassword'])$page='password';elseif($page==='login')redirect();
        if($u&&$page==='admin-approvals'){admin($u);redirect('admin-leaves');}
        if($u&&$page==='leave-preview'){
            header('Content-Type: application/json; charset=UTF-8');
            try{echo json_encode(leavePreview($u,$_GET),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
            catch(AppError $ex){http_response_code($ex->status);echo json_encode(['error'=>$ex->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
            exit;
        }
        if($u&&$page==='receipt')downloadReceipt($u,(string)($_GET['id']??''));
        if($u&&!$u['mustChangePassword']&&$page==='attendance-export')attendanceExport($u);
        if($u&&$page==='report')downloadReport($u,yearValue($_GET['year']??date('Y')));
    }
} catch(AppError $ex){$error=$ex->getMessage();http_response_code($ex->status);}
catch(PDOException $ex){$duplicate=$ex->getCode()==='23000';$error=$duplicate?'중복되거나 참조 중인 데이터입니다. 입력 내용을 확인해주세요.':'DB에 연결하거나 정보를 처리하지 못했습니다. 관리자에게 문의해주세요.';http_response_code($duplicate?409:503);error_log('MNM database error code: '.$ex->getCode());}
catch(Throwable $ex){$error='요청 처리에 실패했습니다. 관리자에게 문의해주세요.';http_response_code(500);error_log('MNM error: '.get_class($ex));}
if(!$error&&$u&&$page!=='setup'){
    if(!$u['mustChangePassword']&&in_array($page,['dashboard','attendance','admin-attendance'],true)){
        try{$attendanceRevision=attendanceRevision($u,$page);}catch(Throwable $ex){$attendanceRevision=null;}
    }
    ob_start();try{renderPage($page,$u);$content=ob_get_clean();}catch(AppError $ex){ob_end_clean();$error=$ex->getMessage();http_response_code($ex->status);}catch(Throwable $ex){ob_end_clean();$error='화면을 불러오지 못했습니다. 관리자에게 문의해주세요.';http_response_code(500);error_log('MNM view error: '.get_class($ex));}
}
// On a rejected POST, render only the authorized page and keep non-password form values.
if($error&&$u&&$page!=='setup') {ob_start();try{renderPage($u['mustChangePassword']?'password':$page,$u);$content=ob_get_clean();}catch(Throwable $ex){ob_end_clean();$content='<p><a href="'.e(url()).'">대시보드로 이동</a></p>';}}
?><!doctype html>
<html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#2b4390"><title>MEDI&amp;MEDI</title><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/groupware-180.png?v=2"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/admin.css"><link rel="stylesheet" href="assets/icons.css"><link rel="stylesheet" href="assets/php.css?v=26"><link rel="stylesheet" href="assets/branding.css?v=2"><script src="assets/splash.js?v=2"></script><script src="assets/app.js?v=7" defer></script><script src="assets/attendance-gps.js?v=4" defer></script><script src="assets/attendance-sync.js?v=1" defer></script></head><body>
<div class="app-splash" aria-hidden="true"><img src="assets/groupware-splash.jpg" alt="" width="1200" height="1200" fetchpriority="high"></div>
<?php if($u): ?>
<div class="shell"><aside id="sidebar"><div class="brand sidebar-brand"><img src="assets/medinmedi-logo.png" alt="MEDI&amp;MEDI"></div><nav aria-label="주 메뉴">
<?php
$menu=['dashboard'=>'대시보드'];
if($u['role']==='ADMIN')$menu['employees']='직원 관리';
$menu+=['attendance'=>'출퇴근 기록','leaves'=>'내 연차','cards'=>'법인카드 사용 등록'];
$activePage=match($page){'leave-apply','leave-edit','history'=>'leaves','employee'=>'employees','leave-cancel','admin-history','admin-approvals'=>'admin-leaves','card-history','card-edit'=>'cards','admin-card-history'=>'admin-cards',default=>$page};
if($page==='approvals'&&$u['role']==='ADMIN')$activePage='admin-leaves';
foreach($menu as $p=>$label)echo '<a class="'.($activePage===$p?'active':'').'" href="'.e(url($p)).'">'.navIcon(match($p){'dashboard'=>'dashboard','employees'=>'users','attendance'=>'clock','leaves'=>'calendar','cards'=>'card','admin-attendance'=>'attendance','admin-cards'=>'card-review','admin-leaves'=>'leave-review','settings'=>'holiday',default=>'check'}).e($label).'</a>';
foreach(STAGES as $s=>$v)if($u['role']!=='ADMIN'&&canDecideStage($u,$s))echo '<a class="'.(($page==='approvals'&&($_GET['stage']??'team')===$s||$page==='team-processed'&&$s==='team')?'active':'').'" href="'.e(url('approvals',['stage'=>$s])).'">'.navIcon('check').e(stageLabel($s)).' 결재</a>';
if($u['role']==='ADMIN')foreach(['admin-attendance'=>'근태 관리','admin-cards'=>'법인카드 사용 관리','admin-leaves'=>'연차 관리','settings'=>'회사 휴일 관리'] as $p=>$label)echo '<a class="'.($activePage===$p?'active':'').'" href="'.e(url($p)).'">'.navIcon(match($p){'dashboard'=>'dashboard','employees'=>'users','attendance'=>'clock','leaves'=>'calendar','cards'=>'card','admin-attendance'=>'attendance','admin-cards'=>'card-review','admin-leaves'=>'leave-review','settings'=>'holiday',default=>'check'}).e($label).'</a>';
?></nav></aside><main><header><button class="hamb" id="menu-toggle" aria-label="메뉴 열기" aria-controls="sidebar" aria-expanded="false">☰</button><div class="header-profile"><span class="header-avatar"><?=e(mb_substr($u['name'],0,1))?></span><div class="header-user"><strong><?=e($u['name'])?>님, 좋은 하루예요.</strong><small><?=e($u['department'])?> · <?=e($u['position'])?></small></div><a class="header-logout" href="<?=e(url('password'))?>">비밀번호 변경</a><?php buttonForm('logout','로그아웃',[],'header-logout'); ?></div></header><section class="content">
<?php else: ?><div class="login"><?php if($page!=='setup'&&isset($_SESSION['csrf'])){formStart('login',[],'login-form');}else{echo '<div class="login-panel">';} ?><div class="brand login-brand"><img src="assets/medinmedi-logo.png" alt="메디앤메디 MEDI&amp;MEDI"></div><?php endif; ?>
<?php if($error): ?><div class="error" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if(isset($_SESSION['flash'])): ?><div class="notice" role="status"<?=in_array($_SESSION['flash'],['로그인했습니다.','로그아웃했습니다.'],true)?' data-auth-notice':''?>><?=e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']);endif; ?>
<?php if($attendanceRevision&&!$error): ?><div data-attendance-sync="<?=e(url('attendance-revision',['scope'=>$page]))?>" data-revision="<?=e($attendanceRevision)?>"><div class="notice" role="status" hidden>다른 기기에서 근태 기록이 변경되었습니다. 입력 내용을 저장한 뒤 새로고침해주세요.</div></div><?php endif; ?>
<?php if($u||$page==='setup'): echo $content; else: ?>
<h1>GROUPWARE</h1>
<?php if(isset($_SESSION['csrf'])){field('email','아이디',$_POST['email']??'','text','required maxlength="191" autocomplete="username" autocapitalize="none" placeholder="아이디 입력" spellcheck="false"');field('password','비밀번호','','password','required autocomplete="current-password"');echo '<button>로그인</button>';} ?>
<?php endif; ?>
<?php if($u): ?></section></main></div><?php else: ?><?php echo $page!=='setup'&&isset($_SESSION['csrf'])?'</form>':'</div>'; ?></div><?php endif; ?></body></html>
