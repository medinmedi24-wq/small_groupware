<?php
declare(strict_types=1);
function navIcon(string $name): string {
    $paths = [
        'dashboard'=>'<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'calendar'=>'<path d="M8 2v4m8-4v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
        'card'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'attendance'=>'<rect x="5" y="4" width="14" height="18" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m9 13 2 2 4-4M9 18h6"/>',
        'card-review'=>'<path d="M21 12V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7M2 9h19M6 15h3m5 3 3 3 5-6"/>',
        'leave-review'=>'<path d="M8 2v4m8-4v4M3 10h18M21 12V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6m2-4 3 3 5-6"/>',
        'holiday'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'settings'=>'<path d="m9.7 4.3.5-2.3h3.6l.5 2.3 1.7 1 2.2-.7 1.8 3.1-1.7 1.6v2l1.7 1.6-1.8 3.1-2.2-.7-1.7 1-.5 2.3h-3.6l-.5-2.3-1.7-1-2.2.7L3.8 13l1.7-1.6v-2L3.8 7.8l1.8-3.1 2.2.7z"/><circle cx="12" cy="10.4" r="3"/>',
        'check'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8m-8 6 3 3 5-5"/>',
    ];
    return '<b aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['check']).'</svg></b>';
}
function dashboardMetric(string $label,mixed $value,string $unit,?string $href=null,bool $attention=false): void {
    echo $href===null?'<div class="card">':'<a class="card dashboard-metric-link'.($attention?' dashboard-pending':'').'" href="'.e($href).'">';
    echo '<span>'.e($label).'</span><strong>'.e($value).'<small>'.e($unit).'</small></strong>';
    if($attention)echo '<span class="dashboard-pending-label">승인 대기</span>';
    if($href!==null)echo '<svg class="dashboard-metric-arrow" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>';
    echo $href===null?'</div>':'</a>';
}
function adminLeaveTabs(array $u,string $page,string $stage=''): void {
    $back=[];$context=listContext($_GET);
    if($page==='admin-history'&&($context['return_page']??'')==='admin-leaves')foreach($context as $key=>$value)if($key!=='return_page')$back[substr($key,7)]=$value;
    $items=[['admin-leaves',$back,'전체 신청',$page==='admin-leaves']];
    foreach(STAGES as $s=>$value)$items[]=['approvals',['stage'=>$s],stageLabel($s).' 대기 '.count(stageRows($u,$s)).'건',$page==='approvals'&&$stage===$s];
    $items[]=['admin-history',[],'연차 이력',$page==='admin-history'];
    echo '<nav class="leave-tabs admin-leave-tabs" aria-label="연차 관리 메뉴">';
    foreach($items as [$target,$args,$label,$active])echo '<a'.($active?' class="active" aria-current="page"':'').' href="'.e(url($target,$args)).'">'.e($label).'</a>';
    echo '</nav>';
}
function renderDashboard(array $u): void {
    $year=(int)date('Y');$unit='연차';$b=balance($u,$year);
    echo '<div class="dashboard-page'.($u['role']==='ADMIN'?' admin-home':'').'">';
    title('대시보드');
    $approvalStage=null;$pending=0;foreach(STAGES as $s=>$v)if(canDecideStage($u,$s)){$approvalStage=$s;$pending+=count(stageRows($u,$s));}
    if($approvalStage!==null){
        echo '<div class="dashboard-action-card">';
        dashboardMetric('내가 처리할 결재',$pending,'건',url('approvals',['stage'=>$approvalStage]),$pending>0);
        echo '</div>';
    }
    if($u['role']==='ADMIN'){
        attendanceSchema();
        $attendancePending=(int)scalar("SELECT COUNT(*) FROM `AttendanceRequest` WHERE status='PENDING' AND userId<>?",[$u['id']]);
        if($attendancePending)echo '<section class="panel attendance-shortcut has-pending" aria-label="근태 정정 대기"><div><h2>처리할 근태 정정 <strong>'.e($attendancePending).'건</strong></h2><p>누락·정정 신청이 기다리고 있습니다. 이전 달 신청도 포함합니다.</p></div><a class="primary" href="'.e(url('admin-attendance',['tab'=>'requests'])).'#attendance-requests">대기 신청 확인</a></section>';
        $employees=scalar('SELECT COUNT(*) FROM `User` WHERE isActive=1');$total=scalar('SELECT COUNT(*) FROM `LeaveRequest` WHERE isDeleted=0');
        $approved=scalar("SELECT COUNT(*) FROM `LeaveRequest` WHERE isDeleted=0 AND status='APPROVED'");$rejected=scalar("SELECT COUNT(*) FROM `LeaveRequest` WHERE isDeleted=0 AND status='REJECTED'");$progress=scalar("SELECT COUNT(*) FROM `LeaveRequest` WHERE isDeleted=0 AND status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO')");
        echo '<div class="cards admin-key-metrics" aria-label="관리자 핵심 지표">';
        dashboardMetric('재직 중 직원',$employees,'명',url('employees',['active'=>'1']));
        dashboardMetric('전체 휴가 신청',$total,'건',url('admin-leaves'));
        dashboardMetric('휴가 결재 진행',$progress,'건',url('admin-leaves',['status'=>'IN_PROGRESS']));
        dashboardMetric('휴가 승인 완료',$approved,'건',url('admin-leaves',['status'=>'APPROVED']));
        dashboardMetric('휴가 반려',$rejected,'건',url('admin-leaves',['status'=>'REJECTED']));
        echo '</div><div class="admin-personal-grid">';
    }
    attendanceSchema();
    $today=attendanceToday();$todayRecord=attendanceDay($u['id'],$today);
    $openRecord=one('SELECT * FROM `AttendanceDay` WHERE userId=? AND checkOut IS NULL ORDER BY workDate LIMIT 1',[$u['id']]);
    $todayRecord=$openRecord??$todayRecord;
    $attendanceState=attendanceWorkState($todayRecord);
    $attendanceStatus=['before'=>'출근 전','working'=>'출근 완료 · 근무 중','complete'=>'퇴근 완료','missing'=>'퇴근 누락'][$attendanceState];
    echo '<section class="panel dashboard-attendance"><div class="dashboard-attendance-head"><div><h2>오늘의 출퇴근</h2><p class="dashboard-date">'.e($today).' · '.e(['일','월','화','수','목','금','토'][(int)dateObject($today)->format('w')]).'요일</p></div><span class="dashboard-attendance-status '.e($attendanceState).'">'.e($attendanceStatus).'</span></div><div class="dashboard-attendance-body"><dl class="dashboard-attendance-details"><div><dt>출근 시간</dt><dd>'.e(!empty($todayRecord['checkIn'])?substr(koreanTime($todayRecord['checkIn']),11,5):'—').'</dd></div><div><dt>퇴근 시간</dt><dd>'.e(!empty($todayRecord['checkOut'])?substr(koreanTime($todayRecord['checkOut']),11,5):'—').'</dd></div><div><dt>근무 상태</dt><dd class="dashboard-work-state">'.e(['before'=>'출근 기록 없음','working'=>'근무 중','complete'=>'근무 종료','missing'=>'퇴근 누락'][$attendanceState]).'</dd></div></dl><a class="primary" href="'.e(url('attendance')).'">출퇴근 기록하기</a></div>';
    if($openRecord&&$openRecord['workDate']!==$today)echo '<p class="dashboard-attendance-note">'.e($openRecord['workDate']).' 시작한 근무입니다. '.($attendanceState==='missing'?'24시간이 지나 누락·정정 신청이 필요합니다.':'퇴근할 때 출퇴근 기록에서 마무리해주세요.').'</p>';
    echo '</section>';
    echo '<section class="dashboard-leave"><div class="dashboard-card-head"><h2>연차 현황</h2><a class="primary" href="'.e(url('leave-apply')).'">연차 신청</a></div><div class="cards dashboard-leave-stats">';
    $leaveStat=static function(string $label,mixed $value,bool $emphasis=false): void { echo '<div class="card'.($emphasis?' dashboard-stat-emphasis':'').'"><span>'.e($label).'</span><strong>'.e($value).'<small>일</small></strong></div>'; };
    $leaveStat('발생 '.$unit,$b['annual']);$leaveStat('사용 '.$unit,$b['used']);$leaveStat('잔여 '.$unit,$b['remaining'],true);
    $leaveStat('결재 대기 휴가',$b['pending']);$leaveStat('추가 신청 가능',$b['available']);
    $recent=array_values(array_filter(leaveRows($u),fn($r)=>substr($r['startDate'],0,4)===(string)$year));
    $summer=array_sum(array_column(array_filter($recent,fn($r)=>$r['status']==='APPROVED'&&$r['leaveType']==='SUMMER_ADVANCE'),'days'));
    $leaveStat('여름휴가 선사용',$summer);
    echo '</div>';
    echo '</section>';if($u['role']==='ADMIN')echo '</div>';echo '<section class="panel dashboard-recent"><div class="panelhead"><h2>최근 휴가 신청</h2>';if($u['role']==='ADMIN')echo '<span class="admin-scope-label">내 신청 · 최근 '.count(array_slice($recent,0,5)).'건</span>';echo '</div>';leaveTable(array_slice($recent,0,5),$u);echo '</section>';
    $cardYear=yearValue($_GET['year']??date('Y'));$month=(int)number($_GET['month']??date('n'),1,12,'월');$period=sprintf('%04d-%02d',$cardYear,$month);if($period>date('Y-m'))throw new AppError('미래 월은 조회할 수 없습니다.');
    $selected=new DateTimeImmutable($period.'-01');$prev=$selected->modify('-1 month');$next=$selected->modify('+1 month');$all=$u['role']==='ADMIN';
    $cards=array_values(array_filter(cardRows($u,$all),fn($r)=>substr($r['usedAt'],0,7)===$period));
    echo '<section class="dashboard-cards"><div class="dashboard-card-head"><h2>'.($all?'법인카드 현황':'법인카드 사용 등록 현황').'</h2><div class="dashboard-head-actions"><div class="month-nav"><a aria-label="이전 달" href="'.e(url('dashboard',['year'=>$prev->format('Y'),'month'=>$prev->format('n')])).'">‹</a><strong>'.e($cardYear).'년 '.e($month).'월</strong>';
    if($period<date('Y-m'))echo '<a aria-label="다음 달" href="'.e(url('dashboard',['year'=>$next->format('Y'),'month'=>$next->format('n')])).'">›</a>';else echo '<button disabled aria-label="다음 달">›</button>';
    echo '</div>';if(!$all)echo '<a class="primary" href="'.e(url('cards')).'">사용 내역 등록</a>';echo '</div></div><div class="cards">';
    if($all)dashboardMetric($month.'월 사용액',number_format(array_sum(array_column(array_filter($cards,fn($r)=>$r['status']!=='CANCELLED'),'amount'))),'원');
    if($all){
        $cardPeriod=['from'=>$selected->format('Y-m-d'),'to'=>$selected->format('Y-m-t')];
        dashboardMetric('검토 대기',count(array_filter($cards,fn($r)=>$r['status']==='PENDING')),'건',url('admin-cards',$cardPeriod+['status'=>'PENDING']));
        dashboardMetric('승인 완료',count(array_filter($cards,fn($r)=>$r['status']==='APPROVED')),'건',url('admin-cards',$cardPeriod+['status'=>'APPROVED']));
    }
    else {statCard('검토 대기',count(array_filter($cards,fn($r)=>$r['status']==='PENDING')).'건','orange');statCard('승인 완료',count(array_filter($cards,fn($r)=>$r['status']==='APPROVED')).'건','green');if(!$all)statCard('반려',count(array_filter($cards,fn($r)=>$r['status']==='REJECTED')).'건');}
    echo '</div><section class="panel"><div class="panelhead"><h2>'.e($cardYear).'년 '.e($month).'월 '.($all?'법인카드 사용':'신청 상태').'</h2></div>';
    if(!$cards)echo '<div class="empty">표시할 법인카드 사용 내역이 없습니다.</div>';
    else {
        echo '<div class="tablewrap record-tablewrap"><table class="responsive-table"><thead><tr>'.($all?'<th scope="col">작성자</th>':'').'<th scope="col">지출일</th><th scope="col">사용처</th><th scope="col">상세 사용 내역</th><th scope="col" class="money">금액</th><th scope="col">상태</th></tr></thead><tbody>';
        foreach(array_slice($cards,0,$all?5:3) as $r){
            echo '<tr data-record-id="'.e($r['id']).'">';
            if($all)recordCell('작성자','<strong>'.e($r['name']).'</strong><small>'.e($r['department']).'</small>');
            recordCell('지출일',e(substr($r['usedAt'],0,10)));recordCell('사용처',e($r['merchant']),'record-identity');
            recordCell('사용 내역',e($r['purpose']),'detail-cell');recordCell('금액',number_format((float)$r['amount']).'원','money');
            recordCell('상태',badge($r['status']),'record-status');echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section></section></div>';
}
