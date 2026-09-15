<?php
declare(strict_types=1);
require __DIR__.'/approval-policy.php';
require __DIR__.'/attendance.php';
const ROLES=['USER'=>'직원','ASSISTANT_MANAGER'=>'대리','MANAGER'=>'과장','GENERAL_MANAGER'=>'부장','TEAM_LEADER'=>'팀장','DIRECTOR'=>'이사','CEO'=>'대표','ADMIN'=>'관리자'];
const LEAVE_TYPES=['ANNUAL'=>'연차','MONTHLY'=>'연차','AM_HALF'=>'오전 반차','PM_HALF'=>'오후 반차','SUMMER_ADVANCE'=>'여름휴가 선사용','SICK'=>'병가','SPECIAL'=>'특별휴가','PUBLIC_DUTY'=>'공가(예비군, 민방위 등)'];
const STATUSES=['PENDING_TEAM_LEADER'=>'팀장 승인 대기','PENDING_DIRECTOR'=>'이사 승인 대기 (이전 절차)','PENDING_CEO'=>'관리자 승인 대기','APPROVED'=>'승인 완료','REJECTED'=>'반려','CANCELLED'=>'취소','PENDING'=>'검토 대기'];
const BALANCE_TYPES=['ANNUAL','MONTHLY','AM_HALF','PM_HALF'];
const STAGES=['team'=>['TEAM_LEADER','PENDING_TEAM_LEADER','PENDING_CEO','teamLeader'],'ceo'=>['ADMIN','PENDING_CEO','APPROVED','ceo']];
function anniversary(string $joined,int $months): DateTimeImmutable {
    $d=dateObject($joined); $first=$d->modify('first day of this month')->modify("+$months months");
    return $first->setDate((int)$first->format('Y'),(int)$first->format('m'),min((int)$d->format('d'),(int)$first->format('t')));
}
function underYear(array $u,?string $asOf=null): bool { return dateObject($asOf??gmdate('Y-m-d'))<anniversary($u['joinDate'],12); }
function allowance(array $u,?string $asOf=null): float {
    if($u['annualLeaveOverride']!==null) return (float)$u['annualLeaveOverride'];
    $today=dateObject($asOf??gmdate('Y-m-d')); $joined=dateObject($u['joinDate']); if($today<$joined) return 0;
    $months=((int)$today->format('Y')-(int)$joined->format('Y'))*12+(int)$today->format('m')-(int)$joined->format('m');
    if($today<anniversary($u['joinDate'],$months)) $months--;
    if($months<12) return max(0,min(11,$months));
    return min(25,15+floor((intdiv($months,12)-1)/2));
}
function balance(array $u,?int $year=null): array {
    $year??=(int)date('Y'); $annual=allowance($u,$year===(int)date('Y')?null:"$year-12-31");
    $used=(float)scalar("SELECT COALESCE(SUM(days),0) FROM `LeaveRequest` WHERE userId=? AND isDeleted=0 AND status='APPROVED' AND leaveType IN ('ANNUAL','MONTHLY','AM_HALF','PM_HALF') AND startDate>=? AND startDate<?",[$u['id'],"$year-01-01",($year+1).'-01-01']);
    $pending=(float)scalar("SELECT COALESCE(SUM(days),0) FROM `LeaveRequest` WHERE userId=? AND isDeleted=0 AND status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO') AND leaveType IN ('ANNUAL','MONTHLY','AM_HALF','PM_HALF') AND startDate>=? AND startDate<?",[$u['id'],"$year-01-01",($year+1).'-01-01']);
    $adjustment=(float)$u['leaveBalanceAdjustment']; $remaining=$annual-$used+$adjustment;
    return ['annual'=>$annual,'used'=>$used,'adjustment'=>$adjustment,'remaining'=>$remaining,'pending'=>$pending,'available'=>max(0,$remaining-$pending)];
}
// Take the same administrator locks as employee maintenance, before any owner lock.
// This serializes calendar changes with leave writes without a schema migration.
function lockLeaveCalendar(): void {
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')rows("SELECT id FROM `User` WHERE role='ADMIN' AND isActive=1 ORDER BY id FOR UPDATE");
    else sql("UPDATE `User` SET isActive=isActive WHERE role='ADMIN' AND isActive=1");
}
function leaveDays(string $start,string $end,string $type): int|float {
    $start=substr($start,0,10);$end=substr($end,0,10);
    $holidays=array_column(rows('SELECT date FROM `CompanyHoliday` WHERE date>=? AND date<?',[$start,$end.' 23:59:59']),'date');
    $holidays=array_map(fn($v)=>substr($v,0,10),$holidays); $days=0;
    for($d=dateObject($start);$d<=dateObject($end);$d=$d->modify('+1 day')) if((int)$d->format('N')<6&&!in_array($d->format('Y-m-d'),$holidays,true)) $days++;
    return $days&&in_array($type,['AM_HALF','PM_HALF'],true)?0.5:$days;
}
function holidayImpacts(string $date): array {
    if((int)dateObject($date)->format('N')>=6)return [];
    return rows("SELECT l.*,u.name,u.department FROM `LeaveRequest` l JOIN `User` u ON u.id=l.userId WHERE l.isDeleted=0 AND l.status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO','APPROVED') AND l.startDate<=? AND l.endDate>=? ORDER BY l.startDate,u.name,l.id",[$date.' 00:00:00',$date.' 00:00:00']);
}
function checkLeaveCalendar(array $leave): void {
    $days=leaveDays($leave['startDate'],$leave['endDate'],$leave['leaveType']);
    if(abs($days-(float)$leave['days'])>0.001)throw new AppError('회사 휴일 기준이 변경되어 신청 일수와 다릅니다. 신청을 수정하여 일수를 다시 확인하거나 취소한 뒤 처리해주세요.',409);
}
function leaveInput(array $in): array {
    $type=$in['leaveType']??''; if(!is_string($type)||!isset(LEAVE_TYPES[$type])) throw new AppError('휴가 종류를 확인해주세요.');
    if($type==='MONTHLY') $type='ANNUAL';
    $start=dateValue($in['startDate']??''); $end=in_array($type,['AM_HALF','PM_HALF'],true)?$start:dateValue($in['endDate']??'');
    if($end<$start||substr($start,0,4)!==substr($end,0,4)) throw new AppError('시작일과 종료일은 같은 연도이며 올바른 순서여야 합니다.');
    $days=leaveDays($start,$end,$type);
    if(!$days) throw new AppError('주말·회사 휴일에는 휴가를 신청할 수 없습니다.');
    return ['leaveType'=>$type,'startDate'=>$start.' 00:00:00','endDate'=>$end.' 00:00:00','days'=>$days,'reason'=>required($in['reason']??'','사유')];
}
function leavePreview(array $u,array $in): array {
    $old=null;$owner=$u;
    if(!empty($in['id'])){
        $old=one('SELECT * FROM `LeaveRequest` WHERE id=?',[(string)$in['id']]);
        if(!$old||$old['isDeleted']||$u['role']!=='ADMIN'&&!canChangeOwnLeave($u,$old))throw new AppError('수정 권한이 없습니다.',403);
        $owner=one('SELECT * FROM `User` WHERE id=?',[$old['userId']]);
    }
    $data=leaveInput(['reason'=>'미리보기']+$in);
    $start=substr($data['startDate'],0,10);$year=yearValue(substr($start,0,4));
    $b=balance($owner,$year);$used=$b['used'];$pending=$b['pending'];
    if($old&&substr($old['startDate'],0,4)===(string)$year&&in_array($old['leaveType'],BALANCE_TYPES,true)){
        if($old['status']==='APPROVED')$used-=(float)$old['days'];
        elseif(in_array($old['status'],['PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO'],true))$pending-=(float)$old['days'];
    }
    $capacity=allowance($owner,$start)+$b['adjustment']-$used-$pending;
    $deduction=in_array($data['leaveType'],BALANCE_TYPES,true)?$data['days']:0;
    return ['startDate'=>$start,'days'=>$data['days'],'deduction'=>$deduction,'available'=>max(0,$capacity),
        'after'=>max(0,$capacity-$deduction),'shortage'=>$deduction>0?max(0,$deduction-$capacity):0,'editing'=>$old!==null];
}
function reserve(array $u,array $data,?string $exclude=null): void {
    $active="status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO','APPROVED') AND isDeleted=0 AND userId=? AND id<>?";
    if(scalar("SELECT COUNT(*) FROM `LeaveRequest` WHERE $active AND startDate<=? AND endDate>=?",[$u['id'],$exclude??'',$data['endDate'],$data['startDate']])) throw new AppError('같은 기간에 진행 중이거나 승인된 휴가가 있습니다.',409);
    if(!in_array($data['leaveType'],BALANCE_TYPES,true)) return;
    $year=(int)substr($data['startDate'],0,4);
    $reserved=(float)scalar("SELECT COALESCE(SUM(days),0) FROM `LeaveRequest` WHERE $active AND leaveType IN ('ANNUAL','MONTHLY','AM_HALF','PM_HALF') AND startDate>=? AND startDate<?",[$u['id'],$exclude??'',"$year-01-01",($year+1).'-01-01']);
    if($reserved+$data['days']>allowance($u,$data['startDate'])+(float)$u['leaveBalanceAdjustment']) throw new AppError('보유 연차를 초과하여 신청할 수 없습니다.',409);
}
function fingerprint(string $userId,array $d): string { return hash('sha256',$userId.':'.$d['leaveType'].':'.substr($d['startDate'],0,10).'T00:00:00.000Z:'.substr($d['endDate'],0,10).'T00:00:00.000Z'); }
function history(string $id,array $actor,string $action,?array $before=null,array $eventData=[]): void {
    $after=one('SELECT * FROM `LeaveRequest` WHERE id=?',[$id]);
    insert('LeaveHistory',['leaveRequestId'=>$id,'userId'=>$after['userId'],'actorId'=>$actor['id'],'action'=>$action,'beforeData'=>$before?json_encode($before,JSON_UNESCAPED_UNICODE):null,'afterData'=>json_encode($after+$eventData,JSON_UNESCAPED_UNICODE),'createdAt'=>now()]);
}
function leaveHistorySnapshot(?string $json): array {
    $data=json_decode($json??'');
    if(!$data instanceof stdClass)return [];
    return array_filter(get_object_vars($data),fn($value)=>is_scalar($value)||$value===null);
}
function leaveHistoryValue(array $snapshot,string $key): ?string {
    if(!isset($snapshot[$key]))return null;
    $value=(string)$snapshot[$key];
    return match($key){
        'leaveType'=>LEAVE_TYPES[$value]??$value,
        'startDate','endDate'=>substr($value,0,10),
        'days'=>is_numeric($value)?(string)(float)$value.'일':$value,
        default=>$value
    };
}
function leaveRows(array $u,bool $all=false): array {
    $where=$all&&$u['role']==='ADMIN'?'1=1':'l.userId=?';
    return rows("SELECT l.*,u.name,u.department FROM `LeaveRequest` l JOIN `User` u ON u.id=l.userId WHERE l.isDeleted=0 AND $where ORDER BY l.createdAt DESC",$where==='1=1'?[]:[$u['id']]);
}
function stageRows(array $u,string $stage): array {
    $s=STAGES[$stage]??throw new AppError('결재 단계가 올바르지 않습니다.');
    if(!in_array($u['role'],[$s[0],'ADMIN'],true)) throw new AppError('결재 권한이 없습니다.',403);
    $filter=$stage==='team'&&$u['role']!=='ADMIN';
    return rows('SELECT l.*,u.name,u.department FROM `LeaveRequest` l JOIN `User` u ON u.id=l.userId WHERE l.isDeleted=0 AND l.status=?'.($filter?' AND u.department=? AND u.id<>?':'').' ORDER BY l.createdAt', $filter?[$s[1],$u['department'],$u['id']]:[$s[1]]);
}
function teamProcessedRows(array $u): array {
    if($u['role']!=='TEAM_LEADER')throw new AppError('팀장 권한이 필요합니다.',403);
    return rows("SELECT l.*,u.name,u.department,a.name AS finalApprover,
        (SELECT h.afterData FROM `LeaveHistory` h WHERE h.leaveRequestId=l.id AND h.action='ADMIN_CANCELLED' ORDER BY h.createdAt DESC,h.id DESC LIMIT 1) AS cancellationData
        FROM `LeaveRequest` l JOIN `User` u ON u.id=l.userId LEFT JOIN `User` a ON a.id=l.ceoApprovedBy
        WHERE l.isDeleted=0 AND l.userId<>? AND l.teamLeaderApprovedBy=? AND l.teamLeaderStatus IN ('APPROVED','REJECTED')
        ORDER BY l.updatedAt DESC,l.id DESC",[$u['id'],$u['id']]);
}
function cardInput(array $in): array {
    $amount=number($in['amount']??'',1,1000000000,'금액');
    if(floor($amount)!==$amount)throw new AppError('금액은 정수로 입력해주세요.');
    $note=$in['note']??'';
    if(!is_string($note)||mb_strlen(trim($note))>300)throw new AppError('비고는 300자 이내로 입력해주세요.');
    return ['usedAt'=>dateValue($in['usedAt']??'').' 00:00:00','merchant'=>required($in['merchant']??'','사용처',120),
        'amount'=>(int)$amount,'purpose'=>required($in['purpose']??'','상세 사용 내역'),
        'isFixed'=>!empty($in['isFixed'])?1:0,'hasReceipt'=>!empty($in['hasReceipt'])?1:0,
        'hasApprovalDocument'=>!empty($in['hasApprovalDocument'])?1:0,'note'=>trim($note)===''?null:trim($note)];
}
function canEditOwnCard(array $u,array $card): bool {
    return $card['userId']===$u['id']&&$card['status']==='PENDING';
}
function leaveReviewToken(array $leave): string {
    $snapshot=[];
    foreach(['id','userId','leaveType','startDate','endDate','days','reason','status','teamLeaderStatus','directorStatus','ceoStatus','teamLeaderApprovedAt','teamLeaderApprovedBy','directorApprovedAt','directorApprovedBy','ceoApprovedAt','ceoApprovedBy','isDeleted','updatedAt'] as $key)$snapshot[$key]=$leave[$key]??null;
    return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function checkLeaveReviewToken(array $leave,array $input): void {
    if(!is_string($input['reviewToken']??null)||!hash_equals(leaveReviewToken($leave),$input['reviewToken']))throw new AppError('휴가 신청 내용이 변경되었습니다. 새로고침하여 최신 내용을 확인한 뒤 다시 처리해주세요.',409);
}
function cardReviewToken(array $card): string {
    $snapshot=[];
    foreach(['id','userId','usedAt','merchant','purpose','amount','isFixed','hasReceipt','hasApprovalDocument','note','receiptFilePath','receiptFileName','receiptMimeType','status','updatedAt'] as $key)$snapshot[$key]=$card[$key]??null;
    return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function checkCardReviewToken(array $card,array $input): void {
    if(!is_string($input['reviewToken']??null)||!hash_equals(cardReviewToken($card),$input['reviewToken']))throw new AppError('내역 또는 증빙이 변경되었습니다. 새로고침하여 최신 내용을 확인한 뒤 다시 처리해주세요.',409);
}
function editableCard(array $u,string $id): array {
    $card=one('SELECT * FROM `CardExpense` WHERE id=?',[$id]);
    if(!$card)throw new AppError('내역을 찾을 수 없습니다.',404);
    if($card['userId']!==$u['id'])throw new AppError('본인 내역만 수정할 수 있습니다.',403);
    if(!canEditOwnCard($u,$card))throw new AppError('검토 대기 중인 내역만 수정할 수 있습니다. 현재 상태를 확인해주세요.',409);
    return $card;
}
function cardRows(array $u,bool $all=false): array {
    return rows('SELECT c.*,u.name,u.department,a.name AS decider FROM `CardExpense` c JOIN `User` u ON c.userId=u.id LEFT JOIN `User` a ON c.decidedById=a.id'.($all&&$u['role']==='ADMIN'?'':' WHERE c.userId=?').' ORDER BY c.usedAt DESC,c.createdAt DESC',$all&&$u['role']==='ADMIN'?[]:[$u['id']]);
}
