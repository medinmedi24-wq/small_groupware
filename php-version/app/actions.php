<?php
declare(strict_types=1);
function returnToFilteredList(array $result,string $action,?array $u,array $input): array {
    if(($u['role']??'')==='TEAM_LEADER'&&$action==='leave_decide'&&($input['stage']??'')==='team'){
        $context=listContext($input);
        if(($context['return_page']??'')==='approvals'){
            $args=['stage'=>'team'];foreach(['q','from','to'] as $key)if(isset($context['return_'.$key]))$args[$key]=$context['return_'.$key];
            return ['approvals','처리했습니다. 처리한 신청은 승인 대기 목록에서 제외되며, 내가 처리한 신청에서 확인할 수 있습니다.',$args];
        }
    }
    if(($u['role']??'')!=='ADMIN')return $result;
    $context=listContext($input);$page=$context['return_page']??'';
    if($page==='admin-leaves'&&in_array($action,['employee_save','employee_balance'],true)&&!empty($input['id']))return ['employee',$result[1],['id'=>(string)$input['id']]+$context];
    $allowed=['admin-attendance'=>['attendance_decide','attendance_request_edit','attendance_request_cancel'],'employees'=>['employee_save','employee_reset'],'admin-leaves'=>['leave_edit','leave_cancel','leave_admin_cancel','leave_decide'],'admin-cards'=>['card_decide','card_cancel']];
    if(!in_array($action,$allowed[$page]??[],true))return $result;
    $args=[];foreach($context as $key=>$value)if($key!=='return_page')$args[substr($key,7)]=$value;
    $message=$result[1].' 검색 조건을 유지했습니다.';
    $newStatus=$action==='card_decide'?($input['decision']==='APPROVE'?'APPROVED':'REJECTED'):(in_array($action,['leave_cancel','leave_admin_cancel','card_cancel'],true)?'CANCELLED':null);
    if($action==='leave_decide')$newStatus=$input['decision']==='APPROVE'?(STAGES[$input['stage']][2]??null):'REJECTED';
    if($newStatus&&isset($args['status'])&&$args['status']!==$newStatus)$message.=' 처리한 내역은 선택한 상태와 달라 목록에서 제외되었습니다.';
    elseif(in_array($action,['employee_save','leave_edit'],true))$message.=' 변경한 내용이 검색 조건과 다르면 목록에 표시되지 않습니다.';
    return [$page,$message,$args];
}
function handleAction(string $action,?array $u,array $in): array {
    if($action==='login') {
        // The session bucket limits repeated attempts without storing passwords or usernames.
        $attempts=array_filter($_SESSION['login_attempts']??[],fn($t)=>$t>time()-300);
        if(count($attempts)>=10) throw new AppError('잠시 후 다시 로그인해주세요.',429);
        $attempts[]=time(); $_SESSION['login_attempts']=$attempts;
        $login=strtolower(required($in['email']??'','아이디',191));
        $user=one('SELECT * FROM `User` WHERE email=?',[$login]);
        if(!$user||!$user['isActive']||!is_string($in['password']??null)||!password_verify($in['password'],$user['passwordHash'])) throw new AppError('아이디 또는 비밀번호가 올바르지 않습니다.',401);
        session_regenerate_id(true); $_SESSION['token']=bin2hex(random_bytes(32)); $_SESSION['csrf']=bin2hex(random_bytes(32)); unset($_SESSION['login_attempts']);
        insert('Session',['token'=>hash('sha256',$_SESSION['token']),'userId'=>$user['id'],'expiresAt'=>gmdate('Y-m-d H:i:s',time()+86400),'createdAt'=>now()]);
        return [$user['mustChangePassword']?'password':'dashboard','로그인했습니다.'];
    }
    if(!$u) throw new AppError('로그인이 필요합니다.',401);
    if($u['mustChangePassword']&&!in_array($action,['password','logout'],true)) throw new AppError('비밀번호를 먼저 변경해주세요.',403);
    if($action==='logout') { sql('DELETE FROM `Session` WHERE token=?',[hash('sha256',$_SESSION['token'])]); unset($_SESSION['token']); session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); return ['login','로그아웃했습니다.']; }
    if($action==='password') {
        $password=$in['newPassword']??'';
        if(!is_string($password)||!validPassword($password)) throw new AppError('비밀번호는 10~72바이트이며 영문·숫자·특수문자를 포함해야 합니다.');
        if(!password_verify((string)($in['currentPassword']??''),$u['passwordHash'])) throw new AppError('현재 비밀번호가 올바르지 않습니다.');
        if(password_verify($password,$u['passwordHash'])) throw new AppError('기존과 다른 비밀번호를 입력해주세요.');
        if($password!==($in['confirmPassword']??'')) throw new AppError('새 비밀번호 확인이 일치하지 않습니다.');
        transaction(function()use($u,$password){update('User',$u['id'],['passwordHash'=>password_hash($password,PASSWORD_BCRYPT),'mustChangePassword'=>0,'passwordChangedAt'=>now(),'updatedAt'=>now()]);sql('DELETE FROM `Session` WHERE userId=? AND token<>?',[$u['id'],hash('sha256',$_SESSION['token'])]);});
        session_regenerate_id(true); return ['dashboard','비밀번호가 변경되었습니다.'];
    }
    if(str_starts_with($action,'attendance_'))return attendanceAction($action,$u,$in);
    if(str_starts_with($action,'employee_')||str_starts_with($action,'holiday_')||$action==='card_decide') admin($u);
    if(in_array($action,['leave_create','leave_edit'],true)) {
        transaction(function()use($action,$u,$in){
            lockLeaveCalendar();
            $old=$action==='leave_edit'?one('SELECT * FROM `LeaveRequest` WHERE id=?',[(string)($in['id']??'')]):null;
            if($action==='leave_edit'&&(!$old||$old['isDeleted'])) throw new AppError('신청을 찾을 수 없습니다.',404);
            if($old&&$u['role']!=='ADMIN'&&!canChangeOwnLeave($u,$old)) throw new AppError('이 신청은 수정할 수 없습니다.',403);
            $owner=lockUser($old['userId']??$u['id']);
            if($old){
                $old=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$old['id']]);
                if(!$old||$old['isDeleted']||$u['role']!=='ADMIN'&&!canChangeOwnLeave($u,$old))throw new AppError('신청 상태가 변경되어 수정할 수 없습니다.',409);
            }
            if($old)checkLeaveReviewToken($old,$in);
            $data=leaveInput($in);
            if(!$old||$u['role']!=='ADMIN'){
                if($data['leaveType']==='SPECIAL') throw new AppError('특별휴가는 관리자에게 문의해주세요.',403);
                $under=underYear($owner,$data['startDate']);
                if(!$under&&$data['leaveType']==='SUMMER_ADVANCE') throw new AppError('근속 기간에 맞는 휴가 종류를 선택해주세요.');
            }
            reserve($owner,$data,$old['id']??null);
            $data+=['requestFingerprint'=>fingerprint($owner['id'],$data),'updatedAt'=>now()];
            if($old){update('LeaveRequest',$old['id'],$data);history($old['id'],$u,$u['role']==='ADMIN'?'ADMIN_UPDATED':'UPDATED',$old);}
            else {
                $skip=in_array($owner['role'],['TEAM_LEADER','DIRECTOR','GENERAL_MANAGER'],true);
                $id=insert('LeaveRequest',$data+['userId'=>$owner['id'],'status'=>$skip?'PENDING_CEO':'PENDING_TEAM_LEADER','teamLeaderStatus'=>$skip?'SKIPPED':'PENDING','directorStatus'=>'SKIPPED','createdAt'=>now()]); history($id,$u,'CREATED');
            }
        }); return [$u['role']==='ADMIN'&&$action==='leave_edit'?'admin-leaves':'leaves','휴가 신청을 저장했습니다.'];
    }
    if($action==='leave_admin_cancel') {
        admin($u);
        transaction(function()use($u,$in){
            lockLeaveCalendar();
            $id=(string)($in['id']??'');
            $before=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$id]);
            if(!$before||$before['isDeleted'])throw new AppError('신청을 찾을 수 없습니다.',404);
            lockUser($before['userId']);
            $old=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$id]);
            if(!$old||$old['isDeleted'])throw new AppError('신청을 찾을 수 없습니다.',404);
            if($old['status']!=='APPROVED')throw new AppError('승인 완료된 휴가만 관리자 취소할 수 있습니다. 현재 상태를 확인해주세요.',409);
            checkLeaveReviewToken($old,$in);
            $reason=required($in['cancellationReason']??'','취소 사유');
            update('LeaveRequest',$id,['status'=>'CANCELLED','requestFingerprint'=>null,'updatedAt'=>now()]);
            history($id,$u,'ADMIN_CANCELLED',$old,['cancellationReason'=>$reason]);
        });
        return ['admin-leaves','승인된 휴가를 취소했습니다.'];
    }
    if($action==='leave_cancel'||$action==='leave_decide') {
        transaction(function()use($action,$u,$in){
            lockLeaveCalendar();
            $id=(string)($in['id']??''); $before=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$id]);
            if(!$before||$before['isDeleted']) throw new AppError('신청을 찾을 수 없습니다.',404);
            $owner=lockUser($before['userId']); $old=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$id]);
            if(!$old||$old['isDeleted'])throw new AppError('신청을 찾을 수 없습니다.',404);
            if($action==='leave_cancel') {
                if(!canChangeOwnLeave($u,$old)) throw new AppError('아직 결재가 처리되지 않은 본인 신청만 취소할 수 있습니다.',409);
                checkLeaveReviewToken($old,$in);
                update('LeaveRequest',$id,['status'=>'CANCELLED','requestFingerprint'=>null,'updatedAt'=>now()]); history($id,$u,'CANCELLED',$old); return;
            }
            $stage=(string)($in['stage']??''); $s=STAGES[$stage]??throw new AppError('결재 단계가 올바르지 않습니다.');
            if(!canDecideStage($u,$stage)) throw new AppError('결재 권한이 없습니다.',403);
            if($stage==='team'&&$u['role']!=='ADMIN'&&($owner['department']!==$u['department']||$owner['id']===$u['id'])) throw new AppError('다른 팀 또는 본인 신청은 결재할 수 없습니다.',403);
            if($old['status']!==$s[1]) throw new AppError('이미 처리되었거나 현재 결재 단계가 아닙니다.',409);
            checkLeaveReviewToken($old,$in);
            $decision=$in['decision']??''; if(!in_array($decision,['APPROVE','REJECT'],true)) throw new AppError('결재 값을 확인해주세요.');
            if($decision==='APPROVE')checkLeaveCalendar($old);
            $approved=$decision==='APPROVE'; $data=['status'=>$approved?$s[2]:'REJECTED',$s[3].'Status'=>$approved?'APPROVED':'REJECTED',$s[3].'ApprovedAt'=>now(),$s[3].'ApprovedBy'=>$u['id'],'updatedAt'=>now()];
            if(!$approved) $data+=[$s[3].'RejectReason'=>required($in['reason']??'','반려 사유'),'requestFingerprint'=>null];
            update('LeaveRequest',$id,$data); history($id,$u,strtoupper($stage).'_'.($approved?'APPROVED':'REJECTED'),$old);
        });return [$action==='leave_cancel'?'leaves':'approvals','처리했습니다.',['stage'=>(string)($in['stage']??'team')]];
    }
    if($action==='employee_save') {
        $id=(string)($in['id']??''); $old=$id?one('SELECT * FROM `User` WHERE id=?',[$id]):null;
        if($id&&!$old) throw new AppError('직원을 찾을 수 없습니다.',404);
        $role=$in['role']??''; if(!is_string($role)||!isset(ROLES[$role])) throw new AppError('역할을 확인해주세요.');
        $accrual=$in['leaveAccrual']??''; if(!in_array($accrual,['AUTOMATIC','MANUAL'],true)) throw new AppError('연차 산정 방식을 확인해주세요.');
        $data=['name'=>required($in['name']??'','이름',100),'department'=>required($in['department']??'','부서',100),'position'=>required($in['position']??'','직급',100),'joinDate'=>dateValue($in['joinDate']??'').' 00:00:00','role'=>$role,'annualLeaveOverride'=>$accrual==='MANUAL'?number($in['annualLeave']??'',0,366,'연차'):null,'isActive'=>($in['isActive']??'1')==='1'?1:0,'updatedAt'=>now()];
        if(!$old){$p=(string)($in['password']??'');if(!validPassword($p)) throw new AppError('초기 비밀번호는 10~72바이트, 영문·숫자·특수문자를 포함해야 합니다.');$data+=['email'=>strtolower(required($in['email']??'','아이디',191)),'passwordHash'=>password_hash($p,PASSWORD_BCRYPT),'annualLeave'=>15,'mustChangePassword'=>1,'createdAt'=>now()];insert('User',$data);}
        else transaction(function()use($id,$data){
            if($id===finalApproverId()&&($data['role']!=='ADMIN'||!$data['isActive'])) throw new AppError('최종 결재자는 재직 중인 관리자여야 합니다. 먼저 최종 결재자 설정을 변경해주세요.');
            // Lock administrators in stable order to prevent removing the last active administrator.
            $suffix=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $admins=rows("SELECT id FROM `User` WHERE role='ADMIN' AND isActive=1 ORDER BY id".$suffix);
            if(count($admins)===1&&$admins[0]['id']===$id&&($data['role']!=='ADMIN'||!$data['isActive'])) throw new AppError('최소 한 명의 재직 중인 관리자가 필요합니다.');
            update('User',$id,$data); if(!$data['isActive']) sql('DELETE FROM `Session` WHERE userId=?',[$id]);
        });
        return ['employees','직원 정보를 저장했습니다.'];
    }
    if($action==='employee_reset') {
        if(($in['id']??'')===$u['id']) throw new AppError('본인 계정은 비밀번호 변경 메뉴를 이용해주세요.');
        $id=(string)($in['id']??''); if(!one('SELECT id FROM `User` WHERE id=?',[$id])) throw new AppError('직원을 찾을 수 없습니다.',404);
        $p=bin2hex(random_bytes(8)).'!7a'; transaction(function()use($id,$p){update('User',$id,['passwordHash'=>password_hash($p,PASSWORD_BCRYPT),'mustChangePassword'=>1,'passwordChangedAt'=>null,'updatedAt'=>now()]);sql('DELETE FROM `Session` WHERE userId=?',[$id]);});
        $_SESSION['temporary_password']=$p; return ['employees','임시 비밀번호를 발급했습니다.'];
    }
    if($action==='employee_balance') {
        if(yearValue($_GET['year']??date('Y'))!==(int)date('Y')) throw new AppError('과거·미래 연도 조회에서는 잔여 일수를 조정할 수 없습니다. 올해 화면으로 이동해주세요.',409);
        transaction(function()use($in,$u){$owner=lockUser((string)($in['id']??''));$target=number($in['targetBalance']??'',0,366,'잔여 일수');$reason=required($in['reason']??'','조정 사유',200);$effective=dateValue($in['effectiveDate']??'');$b=balance($owner);$delta=$target-$b['remaining'];update('User',$owner['id'],['leaveBalanceAdjustment'=>(float)$owner['leaveBalanceAdjustment']+$delta,'leaveBalanceAdjustedAt'=>$effective.' 00:00:00','updatedAt'=>now()]);insert('LeaveBalanceAdjustment',['userId'=>$owner['id'],'actorId'=>$u['id'],'previousBalance'=>$b['remaining'],'targetBalance'=>$target,'adjustmentDelta'=>$delta,'reason'=>$reason,'effectiveDate'=>$effective.' 00:00:00','createdAt'=>now()]);});
        return ['employee','잔여 일수를 반영했습니다.',['id'=>(string)$in['id']]];
    }
    if(in_array($action,['holiday_add','holiday_delete'],true)) {
        transaction(function()use($action,$in){
            lockLeaveCalendar();
            if($action==='holiday_add'){
                $date=dateValue($in['date']??'');$name=required($in['name']??'','휴일 이름',100);
                if(one('SELECT id FROM `CompanyHoliday` WHERE date=?',[$date.' 00:00:00']))throw new AppError('이미 등록된 회사 휴일입니다.',409);
            }else{
                $holiday=one('SELECT * FROM `CompanyHoliday` WHERE id=?',[(string)($in['id']??'')]);
                if(!$holiday)throw new AppError('휴일을 찾을 수 없습니다. 새로고침해주세요.',404);
                $date=substr($holiday['date'],0,10);
            }
            $affected=holidayImpacts($date);
            if($affected)throw new AppError('해당 날짜에 대기·승인된 휴가 '.count($affected).'건이 있어 휴일을 변경할 수 없습니다. 아래 신청을 취소하거나 해당 날짜를 제외하도록 수정한 뒤 다시 시도해주세요.',409);
            if($action==='holiday_add')insert('CompanyHoliday',['date'=>$date.' 00:00:00','name'=>$name,'createdAt'=>now()]);
            else sql('DELETE FROM `CompanyHoliday` WHERE id=?',[$holiday['id']]);
        });
        return ['settings',$action==='holiday_add'?'휴일을 추가했습니다.':'휴일을 삭제했습니다.'];
    }
    if($action==='card_create') {
        $token=$in['registrationToken']??'';
        if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new AppError('등록 화면을 새로고침한 뒤 다시 신청해주세요.');
        // A form submission has a stable, owner-scoped ID; new forms get new IDs.
        $id=substr(hash('sha256','card-create:'.$u['id'].':'.$token),0,32);
        $upload=null;
        try { $created=transaction(function()use($u,$in,$id,&$upload){
            lockUser($u['id']);
            if(one('SELECT id FROM `CardExpense` WHERE id=?',[$id]))return false;
            $data=cardInput($in)+['id'=>$id,'userId'=>$u['id'],'category'=>'','createdAt'=>now(),'updatedAt'=>now()];
            $upload=prepareReceipt($_FILES['receipt']??null);
            if($upload)$data=array_replace($data,$upload['data']);
            insert('CardExpense',$data);return true;
        }); }
        catch(Throwable $e){if($upload)@unlink($upload['path']);throw $e;}
        return ['cards',$created?'법인카드 사용 내역을 등록했습니다.':'이미 등록된 요청입니다. 기존 사용 내역을 확인해주세요.'];
    }
    if($action==='card_edit') {
        transaction(function()use($u,$in){
            // Receipt uploads and decisions lock the same owner before checking the current status.
            lockUser($u['id']);$card=editableCard($u,(string)($in['id']??''));
            checkCardReviewToken($card,$in);
            $data=cardInput($in);
            if($card['receiptFilePath'])$data['hasReceipt']=1;
            update('CardExpense',$card['id'],$data+['updatedAt'=>now()]);
        });
        return ['cards','법인카드 사용 내역을 수정했습니다.'];
    }
    if($action==='card_receipt') {
        $upload=null;$oldReceipt=null;
        try {
            transaction(function()use($u,$in,&$upload,&$oldReceipt){
                $id=(string)($in['id']??'');lockUser($u['id']);
                $card=one('SELECT * FROM `CardExpense` WHERE id=?',[$id]);
                if(!$card||$card['userId']!==$u['id']||$card['status']!=='PENDING')throw new AppError('본인의 검토 대기 내역만 첨부할 수 있습니다.',403);
                checkCardReviewToken($card,$in);
                $upload=prepareReceipt($_FILES['receipt']??null);
                if(!$upload)throw new AppError('증빙 파일을 선택해주세요.');
                $oldReceipt=$card['receiptFilePath'];
                update('CardExpense',$id,$upload['data']+['updatedAt'=>now()]);
            });
        }catch(Throwable $e){if($upload)@unlink($upload['path']);throw $e;}
        // The replacement is committed before removing an unreferenced old file.
        if($oldReceipt){
            try{removeReplacedReceipt($oldReceipt);}catch(Throwable $e){error_log('MNM old receipt cleanup failed');}
        }
        return ['cards','증빙을 첨부했습니다.'];
    }
    if($action==='card_cancel'||$action==='card_decide') {
        transaction(function()use($action,$u,$in){$id=(string)($in['id']??'');$card=one('SELECT * FROM `CardExpense` WHERE id=?',[$id]);if(!$card)throw new AppError('내역을 찾을 수 없습니다.',404);lockUser($card['userId']);$card=one('SELECT * FROM `CardExpense` WHERE id=?',[$id]);if($card['status']!=='PENDING')throw new AppError('이미 처리된 내역입니다.',409);
            if($action==='card_cancel'&&$card['userId']!==$u['id'])throw new AppError('본인 내역만 취소할 수 있습니다.',403);
            checkCardReviewToken($card,$in);
            if($action==='card_cancel'){$data=['status'=>'CANCELLED'];}
            else {if(!in_array($in['decision']??'',['APPROVE','REJECT'],true))throw new AppError('처리를 선택해주세요.');$reject=$in['decision']==='REJECT';$data=['status'=>$reject?'REJECTED':'APPROVED','decidedById'=>$u['id'],'decidedAt'=>now(),'decisionReason'=>$reject?required($in['reason']??'','반려 사유'):null];}
            update('CardExpense',$id,$data+['updatedAt'=>now()]);});return [$action==='card_cancel'?'cards':'admin-cards','처리했습니다.'];
    }
    throw new AppError('지원하지 않는 요청입니다.',404);
}
function prepareReceipt(?array $file): ?array {
    if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
    if(!is_int($file['error'])||$file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name'])||!is_uploaded_file($file['tmp_name'])) throw new AppError('파일 업로드에 실패했습니다. 호스팅 업로드 제한을 확인해주세요.');
    if(filesize($file['tmp_name'])>10*1024*1024) throw new AppError('증빙은 10MiB 이하만 가능합니다.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null;
    if(!$ext) throw new AppError('JPEG·PNG·WebP·PDF 파일만 첨부할 수 있습니다.');
    $dir=storage('receipts');if(!is_dir($dir))mkdir($dir,0700,true);$name=uid().'.'.$ext;$path=$dir.DIRECTORY_SEPARATOR.$name;
    if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('Upload storage unavailable');
    return ['path'=>$path,'data'=>['hasReceipt'=>1,'receiptFileName'=>mb_substr(basename(str_replace('\\','/',(string)$file['name'])),0,240),'receiptFilePath'=>$name,'receiptMimeType'=>$mime]];
}

function removeReplacedReceipt(string $name): void {
    if($name!==basename($name)||str_contains($name,'\\')||scalar('SELECT COUNT(*) FROM `CardExpense` WHERE receiptFilePath=?',[$name]))return;
    $directory=realpath(storage('receipts'));
    $path=$directory===false?false:realpath($directory.DIRECTORY_SEPARATOR.$name);
    if($path===false||dirname($path)!==$directory||!is_file($path)||is_link($directory.DIRECTORY_SEPARATOR.$name))return;
    if(!@unlink($path))error_log('MNM old receipt cleanup failed');
}
