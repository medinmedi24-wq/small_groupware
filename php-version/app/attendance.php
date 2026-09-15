<?php
declare(strict_types=1);
require __DIR__.'/attendance-location.php';
require __DIR__.'/attendance-network.php';

function attendanceSchema(): void {
    static $ready=false;
    if($ready)return;
    // Additive upgrade for existing installations; never replace the business database.
    try { sql('SELECT id FROM `AttendanceHistory` LIMIT 0'); sql('SELECT id FROM `AttendanceRequest` LIMIT 0'); sql('SELECT id FROM `AttendanceDay` LIMIT 0'); sql('SELECT id FROM `AttendanceLocation` LIMIT 0'); sql('SELECT id FROM `AttendanceNetwork` LIMIT 0'); sql('SELECT id FROM `AttendanceManual` LIMIT 0'); }
    catch(PDOException $e) {
        if((string)$e->getCode()!=='42S02'&&!str_contains($e->getMessage(),'no such table:'))throw $e;
        $driver=db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/attendance-'.$driver.'.sql')) as $statement)if(trim($statement)!=='')db()->exec($statement);
    }
    $ready=true;
}
function attendanceMobile(): bool { return (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile/i',$_SERVER['HTTP_USER_AGENT']??''); }
function attendanceToday(): string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d'); }
function attendanceWorkState(?array $record): string {
    if(!$record)return 'before';
    if($record['checkOut'])return 'complete';
    return strtotime(now().' UTC')-strtotime($record['checkIn'].' UTC')>86400?'missing':'working';
}
function attendanceRevision(array $u,string $scope): string {
    if(!in_array($scope,['dashboard','attendance','admin-attendance'],true))throw new AppError('화면을 확인해주세요.',400);
    if($scope==='admin-attendance')admin($u);
    attendanceSchema();
    // Every accepted punch and correction appends an audit row in the same transaction.
    // Personal pages never expose another employee's activity, even via a revision token.
    $all=$u['role']==='ADMIN'&&$scope!=='attendance';
    $count=scalar('SELECT COUNT(*) FROM `AttendanceHistory`'.($all?'':' WHERE userId=?'),$all?[]:[$u['id']]);
    // Include the actual leave state, not only timestamps: two decisions can share a second.
    $leaves=rows('SELECT id,userId,leaveType,startDate,endDate,status,isDeleted FROM `LeaveRequest`'.($all?'':' WHERE userId=?').' ORDER BY id',$all?[]:[$u['id']]);
    return hash('sha256',json_encode([$u['id'],$scope,attendanceToday(),$count,$leaves]));
}
function attendanceManualCheckoutTime(string $checkIn,string $entered,string $submitted): string {
    // A minute-only current-time input must not turn a later punch into an earlier one.
    // Historical minutes keep their entered value and still undergo normal validation.
    if($entered<=$checkIn&&substr($entered,0,16)===substr($checkIn,0,16)&&substr($entered,0,16)===substr($submitted,0,16)&&$submitted>$checkIn)return $submitted;
    return $entered;
}
function attendanceDay(string $userId,string $day): ?array { return one('SELECT * FROM `AttendanceDay` WHERE userId=? AND workDate=?',[$userId,$day]); }
function attendanceLeave(string $userId,string $day): string {
    $r=one("SELECT leaveType FROM `LeaveRequest` WHERE userId=? AND isDeleted=0 AND status='APPROVED' AND startDate<=? AND endDate>=? ORDER BY createdAt DESC LIMIT 1",[$userId,$day.' 23:59:59',$day.' 00:00:00']);
    return $r['leaveType']??'';
}
function attendanceAudit(array $u,string $userId,string $day,string $action,?array $before,?array $after,string $reason=''): void {
    insert('AttendanceHistory',['userId'=>$userId,'workDate'=>$day,'actorId'=>$u['id'],'action'=>$action,'beforeData'=>json_encode($before,JSON_UNESCAPED_UNICODE),'afterData'=>json_encode($after,JSON_UNESCAPED_UNICODE),'reason'=>$reason,'createdAt'=>now()]);
}
function attendanceClock(mixed $value,string $day): ?string {
    if($value==='')return null;
    if(!is_string($value)||!preg_match('/^\d{2}:\d{2}$/D',$value))throw new AppError('시간은 HH:mm 형식으로 입력해주세요.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$day.' '.$value,new DateTimeZone('Asia/Seoul'));
    if(!$d||$d->format('Y-m-d H:i')!==$day.' '.$value)throw new AppError('시간을 확인해주세요.');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function attendanceValidateTimes(?string $in,?string $out): void {
    if(!$in)throw new AppError('출근 시각을 입력해주세요.');
    if($out&&$out<=$in)throw new AppError('퇴근은 출근보다 늦어야 합니다.');
    if($out&&strtotime($out.' UTC')-strtotime($in.' UTC')>86400)throw new AppError('24시간을 넘는 기록은 나누어 신청해주세요.');
    if($in>now()||($out&&$out>now()))throw new AppError('미래 시각은 기록할 수 없습니다.');
}
function attendanceRequestToken(array $r): string {
    return hash('sha256',json_encode(array_intersect_key($r,array_flip(['id','workDate','checkIn','checkOut','reason','baseVersion','status'])),JSON_UNESCAPED_UNICODE));
}
function attendanceAction(string $action,array $u,array $in): array {
    if($action==='attendance_decide')admin($u);
    attendanceSchema();
    return transaction(function()use($action,$u,$in){
        $request=null;
        if(in_array($action,['attendance_decide','attendance_request_edit','attendance_request_cancel'],true)){
            $request=one('SELECT * FROM `AttendanceRequest` WHERE id=?',[required($in['id']??'','신청 ID',64)]);
            if(!$request)throw new AppError('신청을 찾을 수 없습니다.',404);
        }
        $owner=lockUser($request['userId']??$u['id']);
        // Obtain a write lock before reads on SQLite as well as MySQL.
        sql('UPDATE `User` SET id=id WHERE id=?',[$owner['id']]);
        if($request){
            $request=one('SELECT * FROM `AttendanceRequest` WHERE id=?',[$request['id']]);
            if($action!=='attendance_decide'&&$request['userId']!==$u['id'])throw new AppError('본인 신청만 변경할 수 있습니다.',403);
            if($request['status']!=='PENDING')throw new AppError('이미 처리되거나 취소된 신청입니다.',409);
            if(!is_string($in['requestToken']??null)||!hash_equals(attendanceRequestToken($request),$in['requestToken']))throw new AppError('신청 내용이 변경되었습니다. 새로고침 후 다시 확인해주세요.',409);
        }
        if($action==='attendance_request_cancel'){
            update('AttendanceRequest',$request['id'],['status'=>'CANCELLED','decidedBy'=>$u['id'],'decidedAt'=>now(),'decisionReason'=>'본인 취소']);
            attendanceAudit($u,$u['id'],$request['workDate'],'정정 신청 취소',$request,array_replace($request,['status'=>'CANCELLED']),'본인 취소 · '.$request['reason']);
            return ['attendance','정정 신청을 취소했습니다. 출퇴근 기록은 변경되지 않았습니다.',['month'=>substr($request['workDate'],0,7)]];
        }
        if($action==='attendance_punch'){
            $method=$in['verificationMethod']??'gps';
            if(!in_array($method,['gps','manual'],true))throw new AppError('출퇴근 화면을 새로고침해주세요.');
            $location=null;
            if($method==='manual'){
                if(attendanceMobile())throw new AppError('모바일에서는 GPS로 출퇴근을 기록해주세요.',403);
                if(($in['confirmSave']??'')!=='1')throw new AppError('저장할 시간을 확인해주세요.');
                $recordedAt=attendanceClock($in['entryTime']??null,attendanceToday());
                if(!$recordedAt||$recordedAt>now())throw new AppError('현재 시각 이전의 시간을 입력해주세요.');
            }else { $location=attendanceLocation($in);$recordedAt=now(); }
            $kind=$in['kind']??'';
            if(!in_array($kind,['in','out'],true))throw new AppError('출퇴근 구분을 확인해주세요.');
            $day=attendanceToday();
            $open=one('SELECT * FROM `AttendanceDay` WHERE userId=? AND checkIn IS NOT NULL AND checkOut IS NULL ORDER BY workDate LIMIT 1',[$u['id']]);
            if($kind==='out'&&$open)$day=$open['workDate'];
            $old=attendanceDay($u['id'],$day);
            $version=(string)($old['version']??0);
            if(($in['workDate']??'')!==$day||($in['version']??'')!==$version)throw new AppError('기록이 변경되었거나 날짜가 바뀌었습니다. 새로고침 후 다시 시도해주세요.',409);
            if($kind==='in'){
                if($open||$old)throw new AppError('이미 출근 기록이 있습니다. 누락·정정 신청을 이용해주세요.',409);
                if($method==='manual'&&scalar('SELECT COUNT(*) FROM `AttendanceDay` WHERE userId=? AND checkIn<=? AND (checkOut IS NULL OR checkOut>?)',[$u['id'],$recordedAt,$recordedAt]))throw new AppError('다른 근무 기록과 시간이 겹칩니다. 입력 시간을 확인해주세요.',409);
                $leave=attendanceLeave($u['id'],$day);
                if($leave&&!in_array($leave,['AM_HALF','PM_HALF'],true))throw new AppError('승인된 종일 휴가에는 출근할 수 없습니다. 휴가 상태를 확인해주세요.',409);
                insert('AttendanceDay',['userId'=>$u['id'],'workDate'=>$day,'checkIn'=>$recordedAt,'checkOut'=>null,'version'=>1,'updatedAt'=>now()]);
            }else{
                if(!$open)throw new AppError('퇴근할 출근 기록이 없습니다.',409);
                if(strtotime(now().' UTC')-strtotime($open['checkIn'].' UTC')>86400)throw new AppError('24시간이 지난 미퇴근 기록입니다. 누락·정정 신청으로 퇴근 시각을 등록해주세요.',409);
                if($method==='manual'){
                    $recordedAt=attendanceManualCheckoutTime($old['checkIn'],$recordedAt,now());
                    attendanceValidateTimes($old['checkIn'],$recordedAt);
                }
                update('AttendanceDay',$old['id'],['checkOut'=>$recordedAt,'version'=>(int)$old['version']+1,'updatedAt'=>now()]);
            }
            $saved=attendanceDay($u['id'],$day);
            if($method==='manual')insert('AttendanceManual',['userId'=>$u['id'],'workDate'=>$day,'kind'=>$kind,'recordedAt'=>$recordedAt,'submittedAt'=>now()]);
            else insert('AttendanceLocation',$location+['userId'=>$u['id'],'workDate'=>$day,'kind'=>$kind,'recordedAt'=>$saved[$kind==='in'?'checkIn':'checkOut']]);
            attendanceAudit($u,$u['id'],$day,$kind==='in'?'출근 기록':'퇴근 기록',$old,attendanceDay($u['id'],$day));
            return ['attendance',$kind==='in'?'출근을 기록했습니다.':'퇴근을 기록했습니다.'];
        }
        if(in_array($action,['attendance_request','attendance_request_edit'],true)){
            $day=dateValue($in['workDate']??'');
            if($day>attendanceToday()||$day<substr($owner['joinDate'],0,10))throw new AppError('입사일부터 오늘까지의 날짜로 신청해주세요.');
            $checkIn=attendanceClock($in['checkIn']??null,$day);
            $outDay=($in['nextDay']??'')==='1'?dateObject($day)->modify('+1 day')->format('Y-m-d'):$day;
            $checkOut=attendanceClock($in['checkOut']??null,$outDay);
            attendanceValidateTimes($checkIn,$checkOut);
            $reason=required($in['reason']??'','신청 사유');$old=attendanceDay($u['id'],$day);
            if($old&&$old['checkIn']===$checkIn&&$old['checkOut']===$checkOut)throw new AppError('기존 기록과 다른 시간을 입력해주세요.');
            if(scalar("SELECT COUNT(*) FROM `AttendanceRequest` WHERE userId=? AND workDate=? AND status='PENDING' AND id<>?",[$u['id'],$day,$request['id']??'']))throw new AppError('해당 날짜의 신청이 이미 대기 중입니다.',409);
            if($request){
                $data=['workDate'=>$day,'checkIn'=>$checkIn,'checkOut'=>$checkOut,'reason'=>$reason,'baseVersion'=>$old['version']??0];
                update('AttendanceRequest',$request['id'],$data);
                $after=array_replace($request,$data);
                attendanceAudit($u,$u['id'],$request['workDate'],'정정 신청 수정',$request,$after,$reason);
                if($day!==$request['workDate'])attendanceAudit($u,$u['id'],$day,'정정 신청 날짜 변경',$request,$after,$reason);
                return ['attendance','정정 신청을 수정했습니다. 관리자 승인 후 반영됩니다.',['month'=>substr($day,0,7)]];
            }
            $id=insert('AttendanceRequest',['userId'=>$u['id'],'workDate'=>$day,'checkIn'=>$checkIn,'checkOut'=>$checkOut,'baseVersion'=>$old['version']??0,'reason'=>$reason,'status'=>'PENDING','createdAt'=>now()]);
            attendanceAudit($u,$u['id'],$day,'정정 신청',$old,['requestId'=>$id,'checkIn'=>$checkIn,'checkOut'=>$checkOut],$reason);
            return ['attendance','누락·정정 신청을 접수했습니다. 관리자 승인 후 반영됩니다.'];
        }
        if($action==='attendance_decide'){
            $request=one('SELECT * FROM `AttendanceRequest` WHERE id=?',[$request['id']]);
            if($request['userId']===$u['id'])throw new AppError('본인 신청은 다른 관리자가 처리해야 합니다.',403);
            if($request['status']!=='PENDING')throw new AppError('이미 처리된 신청입니다.',409);
            $decision=$in['decision']??'';
            if(!in_array($decision,['APPROVE','REJECT'],true))throw new AppError('처리 구분을 확인해주세요.');
            $reason=$decision==='REJECT'?required($in['reason']??'','반려 사유'):'';
            $day=$request['workDate'];$old=attendanceDay($owner['id'],$day);
            if($decision==='APPROVE'){
                if((int)($old['version']??0)!==(int)$request['baseVersion'])throw new AppError('신청 후 출퇴근 기록이 변경되었습니다. 반려 후 최신 기록으로 다시 신청해주세요.',409);
                attendanceValidateTimes($request['checkIn'],$request['checkOut']);
                $overlap=scalar('SELECT COUNT(*) FROM `AttendanceDay` WHERE userId=? AND workDate<>? AND (checkOut IS NULL OR checkOut>?) AND checkIn<?',[$owner['id'],$day,$request['checkIn'],$request['checkOut']??'9999-12-31 23:59:59']);
                if($overlap)throw new AppError('다른 날짜의 근무 기록과 겹칩니다. 해당 기록을 먼저 정정해주세요.',409);
                $data=['checkIn'=>$request['checkIn'],'checkOut'=>$request['checkOut'],'version'=>(int)($old['version']??0)+1,'updatedAt'=>now()];
                if($old)update('AttendanceDay',$old['id'],$data);else insert('AttendanceDay',$data+['userId'=>$owner['id'],'workDate'=>$day]);
            }
            update('AttendanceRequest',$request['id'],['status'=>$decision==='APPROVE'?'APPROVED':'REJECTED','decidedBy'=>$u['id'],'decidedAt'=>now(),'decisionReason'=>$reason]);
            attendanceAudit($u,$owner['id'],$day,$decision==='APPROVE'?'정정 승인':'정정 반려',$old,attendanceDay($owner['id'],$day),'신청 '.$request['id'].' · '.$request['reason'].($reason?' / 반려: '.$reason:''));
            return ['admin-attendance','신청을 처리했습니다.'];
        }
        throw new AppError('지원하지 않는 근태 요청입니다.',404);
    });
}
