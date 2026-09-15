<?php
declare(strict_types=1);
function attendanceTime(?string $v): string { return $v?substr(koreanTime($v),0,16):'—'; }
function attendanceRequestControls(array $r): void {
    $failed=($_POST['action']??'')==='attendance_request_edit'&&($_POST['id']??'')===$r['id'];
    $in=substr(koreanTime($r['checkIn']),0,16);$out=$r['checkOut']?substr(koreanTime($r['checkOut']),0,16):'';
    $f=($failed?$_POST:[])+['workDate'=>$r['workDate'],'checkIn'=>substr($in,11,5),'checkOut'=>$out?substr($out,11,5):'','nextDay'=>$out&&substr($out,0,10)>$r['workDate']?'1':'0','reason'=>$r['reason']];
    $hidden=['id'=>$r['id'],'requestToken'=>attendanceRequestToken($r)];
    echo '<details'.($failed?' open':'').'><summary>신청 수정</summary><p>관리자 처리 전까지 수정할 수 있습니다. 출퇴근 기록은 승인 후 변경됩니다.</p>';
    formStart('attendance_request_edit',$hidden);
    field('workDate','근무일',$f['workDate'],'date','required max="'.e(attendanceToday()).'"');
    field('checkIn','출근 시각',$f['checkIn'],'time','required');field('checkOut','퇴근 시각',$f['checkOut'],'time');
    selectField('nextDay','퇴근 날짜',['0'=>'근무일 당일','1'=>'근무일 다음 날'],$f['nextDay']);
    field('reason','누락·정정 사유',$f['reason'],'text','required maxlength="500"');
    echo '<button class="primary">수정 저장</button></form></details>';
    buttonForm('attendance_request_cancel','신청 취소',$hidden,'link danger',true);
}
function attendanceMonth(): string {
    $v=$_GET['month']??substr(attendanceToday(),0,7);
    if(!is_string($v)||!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$v))throw new AppError('조회 월을 확인해주세요.');
    return $v;
}
const ATTENDANCE_FILTER_STATES=[''=>'전체 상태','missing'=>'퇴근 누락','working'=>'근무 중','complete'=>'퇴근 완료','conflict'=>'휴가·기록 중복','none'=>'기록 없음 (날짜별 현황)','leave'=>'휴가만 있음 (날짜별 현황)'];
function attendanceFilters(): array {
    $f=[];
    foreach(['q'=>100,'department'=>100,'workStatus'=>20] as $key=>$max){
        $v=$_GET[$key]??'';
        if(!is_string($v)||mb_strlen($v)>$max)throw new AppError('검색 조건을 확인해주세요.');
        $f[$key]=trim($v);
    }
    if(!array_key_exists($f['workStatus'],ATTENDANCE_FILTER_STATES))throw new AppError('근무 상태를 확인해주세요.');
    if(($_GET['page']??'')==='admin-attendance'&&($_GET['tab']??'')==='monthly'&&in_array($f['workStatus'],['none','leave'],true))$f['workStatus']='';
    return $f;
}
function attendanceMatchesPerson(array $r,array $f): bool {
    return ($f['q']===''||mb_stripos($r['name'],$f['q'])!==false)&&($f['department']===''||$r['department']===$f['department']);
}
function attendanceMatchesState(array $r,string $leave,array $f): bool {
    $state=empty($r['id'])?($leave?'leave':'none'):match(attendanceLabel($r,$leave)){'퇴근 누락'=>'missing','근무 중'=>'working','퇴근 완료'=>'complete',default=>'conflict'};
    return $f['workStatus']===''||$f['workStatus']===$state;
}
function attendanceFilterHidden(array $f): void {
    foreach($f as $key=>$value)echo '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';
}
function attendanceAdminTab(): string {
    $tab=$_GET['tab']??'requests';
    if(!is_string($tab)||!in_array($tab,['requests','daily','monthly','history'],true))throw new AppError('조회 화면을 확인해주세요.');
    return $tab;
}
function attendanceAdminTabs(array $f,string $month,string $tab): void {
    $pending=rows("SELECT u.name,u.department FROM `AttendanceRequest` r JOIN `User` u ON u.id=r.userId WHERE r.status='PENDING'");
    $count=count(array_filter($pending,fn($r)=>attendanceMatchesPerson($r,$f)));
    $context=$f+['month'=>$month,'day'=>dateValue($_GET['day']??attendanceToday())];
    echo '<nav class="attendance-admin-tabs" aria-label="근태 관리 화면">';
    foreach(['requests'=>'정정 대기 '.$count.'건','daily'=>'날짜별 현황','monthly'=>'월별 기록','history'=>'변경 이력'] as $key=>$label){
        $target=$context;if($key==='monthly'&&in_array($target['workStatus'],['none','leave'],true))$target['workStatus']='';
        echo '<a'.($key===$tab?' class="active" aria-current="page"':'').' href="'.e(url('admin-attendance',['tab'=>$key]+$target)).'">'.e($label).'</a>';
    }
    echo '</nav>';
}
function attendanceFilterForm(array $f,string $month,string $tab): void {
    $day=dateValue($_GET['day']??attendanceToday());
    $departments=[''=>'전체 부서'];foreach(rows('SELECT DISTINCT department FROM `User` ORDER BY department') as $r)$departments[$r['department']]=$r['department'];
    if($f['department']!==''&&!isset($departments[$f['department']]))$departments[$f['department']]=$f['department'];
    echo '<form method="get" class="employee-filters attendance-filter">';attendanceFilterHidden(['page'=>'admin-attendance','tab'=>$tab,'month'=>$month,'day'=>$day]);
    field('q','직원 이름',$f['q'],'search','maxlength="100" placeholder="직원 이름 검색"');selectField('department','부서',$departments,$f['department']);
    if(in_array($tab,['daily','monthly'],true))selectField('workStatus','근무 상태',$tab==='monthly'?array_diff_key(ATTENDANCE_FILTER_STATES,['none'=>true,'leave'=>true]):ATTENDANCE_FILTER_STATES,$f['workStatus']);else attendanceFilterHidden(['workStatus'=>$f['workStatus']]);
    echo '<button class="primary">검색</button><a class="secondary" href="'.e(url('admin-attendance',['tab'=>$tab,'month'=>$month,'day'=>$day])).'">검색 초기화</a></form>';
}
function attendanceRows(array $u,bool $all,string $month): array {
    if($all)admin($u);
    $args=[$month.'-01',dateObject($month.'-01')->modify('+1 month')->format('Y-m-d')];
    if(!$all)$args[]=$u['id'];
    $list=rows('SELECT d.*,u.name,u.department FROM `AttendanceDay` d JOIN `User` u ON u.id=d.userId WHERE d.workDate>=? AND d.workDate<?'.($all?'':' AND d.userId=?').' ORDER BY d.workDate DESC,u.name',$args);
    if(!$all)return $list;
    $f=attendanceFilters();
    return array_values(array_filter($list,fn($r)=>attendanceMatchesPerson($r,$f)&&attendanceMatchesState($r,attendanceLeave($r['userId'],$r['workDate']),$f)));
}
function attendanceLabel(array $r,string $leave): string {
    if($leave&&!in_array($leave,['AM_HALF','PM_HALF'],true))return '휴가와 기록 중복 · 확인 필요';
    return ['working'=>'근무 중','missing'=>'퇴근 누락','complete'=>'퇴근 완료'][attendanceWorkState($r)];
}
function attendanceMobileCards(array $records,?string $day=null): void {
    echo '<div class="admin-mobile-list">';
    if(!$records)echo '<p class="empty">'.($day?'조건에 맞는 직원 현황이 없습니다.':'표시할 출퇴근 기록이 없습니다. 조회 월과 검색 조건을 확인해주세요.').'</p>';
    foreach($records as $r){
        $date=$day??$r['workDate'];$leave=attendanceLeave($day?$r['employeeId']:$r['userId'],$date);
        $status=$r['id']?attendanceLabel($r,$leave):($leave?(LEAVE_TYPES[$leave]??$leave):'기록 없음');
        $schedule=$leave==='AM_HALF'?'14:00–18:00':($leave==='PM_HALF'?'09:00–14:00':'09:00–18:00');
        mobileAdminCard(e($r['name']).'<small>'.e($r['department']).' · '.e($date).'</small>',e($status),[
            '출근'=>e(attendanceTime($r['checkIn'])).'<small>'.e(attendanceLocationLabel($r,'in')).'</small>',
            '퇴근'=>e(attendanceTime($r['checkOut'])).'<small>'.e(attendanceLocationLabel($r,'out')).'</small>',
            '휴가·근무 기준'=>e($leave?(LEAVE_TYPES[$leave]??$leave):'정규 근무').'<small>'.e($schedule).'</small>'
        ]);
    }
    echo '</div>';
}
function attendanceDailyRoster(string $month): void {
    $day=dateValue($_GET['day']??attendanceToday());$f=attendanceFilters();
    echo '<section class="panel"><div class="panelhead"><h2>날짜별 직원 현황</h2></div><form method="get" class="employee-filters attendance-filter"><input type="hidden" name="page" value="admin-attendance"><input type="hidden" name="month" value="'.e($month).'">';attendanceFilterHidden(['tab'=>'daily']+$f);field('day','조회 날짜',$day,'date','required');echo '<button class="primary">현황 조회</button></form><div class="tablewrap admin-desktop-table"><table><thead><tr><th>직원</th><th>부서</th><th>출근</th><th>퇴근</th><th>상태</th></tr></thead><tbody>';
    $people=rows('SELECT u.id AS employeeId,u.name,u.department,d.* FROM `User` u LEFT JOIN `AttendanceDay` d ON d.userId=u.id AND d.workDate=? WHERE u.isActive=1 OR d.id IS NOT NULL ORDER BY u.department,u.name',[$day]);
    $people=array_values(array_filter($people,fn($r)=>attendanceMatchesPerson($r,$f)&&attendanceMatchesState($r,attendanceLeave($r['employeeId'],$day),$f)));
    foreach($people as $r){$leave=attendanceLeave($r['employeeId'],$day);$label=$r['id']?attendanceLabel($r,$leave):($leave?(LEAVE_TYPES[$leave]??$leave):'기록 없음');echo '<tr><td>'.e($r['name']).'</td><td>'.e($r['department']).'</td><td>'.e(attendanceTime($r['checkIn'])).'<small>'.e(attendanceLocationLabel($r,'in')).'</small></td><td>'.e(attendanceTime($r['checkOut'])).'<small>'.e(attendanceLocationLabel($r,'out')).'</small></td><td>'.e($label).'</td></tr>';}
    if(!$people)echo '<tr><td colspan="5">조건에 맞는 직원 현황이 없습니다.</td></tr>';echo '</tbody></table></div>';attendanceMobileCards($people,$day);echo '<p>기록 없음은 결근 판정이 아닙니다. 새로고침하면 최신 기록을 확인할 수 있습니다.</p></section>';
}
function attendanceExport(array $u): never {
    admin($u);attendanceSchema();$month=attendanceMonth();$records=attendanceRows($u,true,$month);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="attendance-'.$month.'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['근무일','이름','부서','출근(KST)','퇴근(KST)','휴가','상태','출근 위치 확인','퇴근 위치 확인'],',','"','');
    foreach($records as $r){$leave=attendanceLeave($r['userId'],$r['workDate']);$cells=[$r['workDate'],$r['name'],$r['department'],attendanceTime($r['checkIn']),attendanceTime($r['checkOut']),LEAVE_TYPES[$leave]??'',attendanceLabel($r,$leave),attendanceLocationLabel($r,'in'),attendanceLocationLabel($r,'out')];$cells=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v,$cells);fputcsv($out,$cells,',','"','');}
    fclose($out);exit;
}
function renderAttendance(array $u,bool $all): void {
    if($all)admin($u);
    attendanceSchema();$month=attendanceMonth();$today=attendanceToday();
    echo '<div class="attendance-page'.(!$all?' personal-attendance-page':'').'">';
    title($all?'근태 관리':'출퇴근 기록');
    if(!$all)echo '<p class="attendance-page-description">오늘의 출퇴근 상태와 월별 근무 기록을 확인할 수 있습니다.</p>';
    $tab=$all?attendanceAdminTab():'';
    if(!$all&&$u['role']==='ADMIN')tabs(['attendance'=>'내 출퇴근','admin-attendance'=>'직원 근태 관리'],'attendance');
    if($all){$filters=attendanceFilters();attendanceAdminTabs($filters,$month,$tab);attendanceFilterForm($filters,$month,$tab);if($tab==='daily')attendanceDailyRoster($month);}
    if(!$all){
        $open=one('SELECT * FROM `AttendanceDay` WHERE userId=? AND checkOut IS NULL ORDER BY workDate LIMIT 1',[$u['id']]);
        $current=$open??attendanceDay($u['id'],$today);$day=$open['workDate']??$today;$leave=attendanceLeave($u['id'],$today);$workState=attendanceWorkState($current);
        echo '<section class="panel attendance-punch '.($open?'is-working':($current?'is-complete':'is-before')).'"><div class="attendance-current-summary"><div class="attendance-overview"><div class="attendance-overview-heading"><span class="attendance-eyebrow">'.e($day).($day===$today?' · 오늘의 기록':' · 근무 시작일').'</span><h2>'.(['working'=>'근무 중','missing'=>'퇴근 누락','complete'=>'퇴근 완료','before'=>'출근 전'][$workState]).'</h2></div><div class="attendance-times"><div><span>출근 시각</span><strong>'.e(($current['checkIn']??null)?substr(koreanTime($current['checkIn']),11,5):'—').'</strong></div><div><span>퇴근 시각</span><strong>'.e(($current['checkOut']??null)?substr(koreanTime($current['checkOut']),11,5):'—').'</strong></div></div></div>';
        if($leave)echo '<p>오늘 승인 휴가: '.e(LEAVE_TYPES[$leave]??$leave).'</p>';
        if($open&&$workState==='missing')echo '<p class="error">출근 후 24시간이 지났습니다. 아래 누락·정정 신청으로 퇴근 시각을 등록해주세요.</p>';
        elseif($open&&$day!==$today)echo '<p>'.e($day).' 시작한 근무입니다. 퇴근하면 해당 근무일에 기록됩니다.</p>';
        echo '</div>';
        if($workState!=='missing'&&($open||!$current)){
            $mobile=attendanceMobile();
            $failedPunch=($_POST['action']??'')==='attendance_punch';
            $punchMode=$failedPunch&&is_string($_POST['punchMode']??null)&&in_array($_POST['punchMode'],['office','business_trip'],true)?$_POST['punchMode']:'office';
            $tripReason=$failedPunch&&is_string($_POST['tripReason']??null)?mb_substr($_POST['tripReason'],0,120):'';
            formStart('attendance_punch',['kind'=>$open?'out':'in','workDate'=>$day,'version'=>(string)($current['version']??0),'verificationMethod'=>$mobile?'gps':'manual'],'attendance-punch-form');
            echo '<div class="attendance-manual-fields"'.($mobile?' hidden':'').'>';
            field('entryTime',$open?'퇴근 시각':'출근 시각',substr(koreanTime(now()),11,5),'time',$mobile?'disabled':'required');
            echo '<label class="attendance-save-confirm"><input type="checkbox" name="confirmSave" value="1" '.($mobile?'disabled':'required').'>입력한 시간을 확인했습니다. 저장 후 변경은 관리자에게 요청합니다.</label></div>';
            echo '<div class="attendance-mobile-fields"'.(!$mobile?' hidden':'').'>';
            selectField('punchMode','근무 장소',['office'=>'회사 출퇴근','business_trip'=>'출장 출퇴근'],$punchMode);field('tripReason','출장지·사유',$tripReason,'text','maxlength="120"');
            echo '</div><input type="hidden" name="policyVersion" value="'.e(ATTENDANCE_GPS_POLICY).'"><p class="attendance-gps-status" role="status" aria-live="polite">'.($mobile?'현재 위치를 확인한 뒤 기록합니다.':'').'</p><button class="primary">'.($open?'퇴근 기록':'출근 기록').'</button><noscript><p>모바일 GPS 출퇴근은 JavaScript를 켜주세요.</p></noscript></form>';
        }
        echo '</section>';
        echo '<details class="panel attendance-request"'.(($_POST['action']??'')==='attendance_request'?' open':'').'><summary>누락·정정 신청하기</summary><p>변경 후의 출근·퇴근 시간을 모두 입력하세요. 퇴근을 비우면 근무 중으로 신청됩니다. 관리자 승인 전까지 기존 기록이 유지됩니다.</p>';
        formStart('attendance_request');echo '<div class="row">';field('workDate','근무일',$_POST['workDate']??$today,'date','required max="'.e($today).'"');field('checkIn','출근 시각',$_POST['checkIn']??'','time','required');field('checkOut','퇴근 시각',$_POST['checkOut']??'','time');echo '</div>';
        selectField('nextDay','퇴근 날짜',['0'=>'근무일 당일','1'=>'근무일 다음 날'],$_POST['nextDay']??'0');
        field('reason','누락·정정 사유',$_POST['reason']??'','text','required maxlength="500"');echo '<button class="primary">관리자에게 신청</button></form></details>';
    }
    if(!$all||in_array($tab,['monthly','history'],true)){
    echo '<form method="get" class="employee-filters attendance-filter"><input type="hidden" name="page" value="'.($all?'admin-attendance':'attendance').'">';if($all)attendanceFilterHidden(['tab'=>$tab,'day'=>dateValue($_GET['day']??$today)]+$filters);field('month',$tab==='history'?'근무 월':'조회 월',$month,'month','required');echo '<button class="primary">조회</button>';
    if($all&&$tab==='monthly')echo '<a class="secondary" href="'.e(url('attendance-export',['month'=>$month]+$filters)).'">조회 결과 CSV 다운로드</a>';
    echo '</form>';
    }
    if(!$all||$tab==='monthly'){
    echo ($all?'<section class="panel"><div class="panelhead"><h2>'.e($month).' 출퇴근 내역</h2></div>':'<details class="panel attendance-monthly" open><summary>'.e($month).' 출퇴근 내역</summary>').'<div class="tablewrap'.($all?' admin-desktop-table':'').'"><table><thead><tr><th>근무일</th>'.($all?'<th>직원</th>':'').'<th>출근</th><th>퇴근</th><th>휴가·근무 기준</th><th>상태</th></tr></thead><tbody>';
    $records=attendanceRows($u,$all,$month);
    foreach($records as $r){$leave=attendanceLeave($r['userId'],$r['workDate']);$schedule=$leave==='AM_HALF'?'14:00–18:00':($leave==='PM_HALF'?'09:00–14:00':'09:00–18:00');echo '<tr><td>'.e($r['workDate']).'</td>'.($all?'<td>'.e($r['name']).'<small>'.e($r['department']).'</small></td>':'').'<td>'.e(attendanceTime($r['checkIn'])).'<small>'.e(attendanceLocationLabel($r,'in')).'</small></td><td>'.e(attendanceTime($r['checkOut'])).'<small>'.e(attendanceLocationLabel($r,'out')).'</small></td><td>'.e($leave?(LEAVE_TYPES[$leave]??$leave):'정규 근무').'<small>'.e($schedule).'</small></td><td>'.e(attendanceLabel($r,$leave)).'</td></tr>';}
    if(!$records)echo '<tr><td colspan="'.($all?6:5).'" class="attendance-empty"><span class="attendance-empty-icon" aria-hidden="true">'.navIcon('calendar').'</span><strong>표시할 출퇴근 기록이 없습니다</strong><span>조회 월과 검색 조건을 확인해주세요.</span></td></tr>';
    echo '</tbody></table></div>';if($all)attendanceMobileCards($records);echo $all?'</section>':'</details>';
    }
    if(!$all||in_array($tab,['requests','history'],true)){
    // Pending requests from every month remain visible, even when a past month is selected.
    $where=$all?'1=1':'r.userId=?';$args=$all?[]:[$u['id']];
    $requests=rows('SELECT r.*,u.name,u.department,a.name AS decider FROM `AttendanceRequest` r JOIN `User` u ON u.id=r.userId LEFT JOIN `User` a ON a.id=r.decidedBy WHERE '.$where." AND (r.status='PENDING' OR (r.workDate>=? AND r.workDate<?)) ORDER BY CASE WHEN r.status='PENDING' THEN 0 ELSE 1 END,r.createdAt DESC",[...$args,$month.'-01',dateObject($month.'-01')->modify('+1 month')->format('Y-m-d')]);
    if($all)$requests=array_values(array_filter($requests,fn($r)=>attendanceMatchesPerson($r,$filters)));
    if($all)$requests=array_values(array_filter($requests,fn($r)=>$tab==='requests'?$r['status']==='PENDING':$r['status']!=='PENDING'));
    echo $all?'<section class="panel" id="attendance-requests"><div class="panelhead"><h2>'.($tab==='requests'?'대기 중인 정정 신청':'처리 완료 신청').'</h2></div>':'<details class="panel" id="attendance-requests" open><summary>누락·정정 신청</summary>';
    foreach($requests as $r){echo '<article class="attendance-review"><h3>'.e($r['workDate']).' · '.e($r['name']).' '.badge($r['status']).'</h3><p>신청 시간: '.e(attendanceTime($r['checkIn'])).' → '.e(attendanceTime($r['checkOut'])).'</p><p class="history-text">'.e($r['reason']).'</p>';
        if($all&&$r['status']==='PENDING'){ $basis=attendanceDay($r['userId'],$r['workDate']); echo '<p>현재 기록: '.e(attendanceTime($basis['checkIn']??null)).' → '.e(attendanceTime($basis['checkOut']??null)).'</p>'; }
        if($r['status']==='PENDING'&&$r['userId']===$u['id'])attendanceRequestControls($r);
        if($all&&$r['status']==='PENDING'&&$r['userId']!==$u['id']){echo '<div class="actions review-decision-controls">';buttonForm('attendance_decide','승인',['id'=>$r['id'],'requestToken'=>attendanceRequestToken($r),'decision'=>'APPROVE'],'primary',true);formStart('attendance_decide',['id'=>$r['id'],'requestToken'=>attendanceRequestToken($r),'decision'=>'REJECT'],'decision-form');field('reason','반려 사유','','text','required maxlength="500"');echo '<button class="reject">반려</button></form></div>';}
        if($all&&$r['status']==='PENDING'&&$r['userId']===$u['id'])echo '<p>본인 신청은 다른 관리자가 처리합니다.</p>';
        if($r['decidedAt'])echo '<p>'.e($r['decider']).' · '.e(koreanTime($r['decidedAt'])).' '.e($r['decisionReason']).'</p>';echo '</article>';}
    if(!$requests)echo '<div class="attendance-empty attendance-empty-small"><strong>'.($tab==='requests'?'대기 중인 신청이 없습니다':'표시할 신청이 없습니다').'</strong><span>'.($tab==='requests'?'직원·부서 검색 조건을 확인해주세요.':'조회 월과 검색 조건을 확인해주세요.').'</span></div>';echo $all?'</section>':'</details>';
    }
    if(!$all||$tab==='history'){
    $history=rows('SELECT h.*,u.name,u.department,a.name AS actor FROM `AttendanceHistory` h JOIN `User` u ON u.id=h.userId JOIN `User` a ON a.id=h.actorId WHERE h.workDate>=? AND h.workDate<?'.($all?'':' AND h.userId=?').' ORDER BY h.createdAt DESC,h.id DESC',[$month.'-01',dateObject($month.'-01')->modify('+1 month')->format('Y-m-d'),...($all?[]:[$u['id']])]);
    if($all)$history=array_values(array_filter($history,fn($r)=>attendanceMatchesPerson($r,$filters)));
    echo '<details class="panel"'.($all?' open':'').'><summary>출퇴근·변경 이력 ('.count($history).'건)</summary>';
    foreach($history as $h){$before=json_decode($h['beforeData'],true)??[];$after=json_decode($h['afterData'],true)??[];echo '<article class="attendance-review"><strong>'.e($h['workDate'].' · '.$h['name'].' · '.$h['action']).'</strong><p>이전: '.e(attendanceTime($before['checkIn']??null)).' → '.e(attendanceTime($before['checkOut']??null)).'<br>이후: '.e(attendanceTime($after['checkIn']??null)).' → '.e(attendanceTime($after['checkOut']??null)).'</p><p>'.e($h['actor'].' · '.koreanTime($h['createdAt'])).'</p><p class="history-text">'.e($h['reason']).'</p></article>';}
    if(!$history)echo '<p>변경 이력이 없습니다.</p>';echo '</details>';
    }
    echo '</div>';
}
