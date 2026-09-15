<?php
declare(strict_types=1);
require __DIR__.'/ui.php';
require __DIR__.'/attendance-views.php';
function title(string $name): void { echo '<div class="title"><h1>'.e($name).'</h1></div>'; }
function field(string $name,string $label,mixed $value='',string $type='text',string $attrs=''): void { echo '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" '.$attrs.'></label>'; }
function selectField(string $name,string $label,array $options,mixed $value): void { echo '<label>'.e($label).'<select name="'.e($name).'">';foreach($options as $k=>$v)echo '<option value="'.e($k).'"'.((string)$k===(string)$value?' selected':'').'>'.e($v).'</option>';echo '</select></label>'; }
function formStart(string $action,array $hidden=[],string $class='form',bool $upload=false,string $id=''): void { $hidden+=listContext($_GET,true);echo '<form method="post" class="'.e($class).'"'.($id!==''?' id="'.e($id).'"':'').($upload?' enctype="multipart/form-data"':'').'>'.csrf().'<input type="hidden" name="action" value="'.e($action).'">';foreach($hidden as $k=>$v)echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; }
function buttonForm(string $action,string $text,array $hidden=[],string $class='link',bool $confirm=false): void { $hidden+=listContext($_GET,true);echo '<form method="post" class="inline"'.($confirm?' data-confirm="정말 처리할까요?"':'').'>'.csrf().'<input type="hidden" name="action" value="'.e($action).'">';foreach($hidden as $k=>$v)echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';echo '<button class="'.e($class).'">'.e($text).'</button></form>'; }
function badge(string $status): string { return '<span class="badge '.e($status).'">'.e(STATUSES[$status]??$status).'</span>'; }
function statCard(string $label,mixed $value,string $tone='navy'): void { echo '<div class="card '.e($tone).'"><span>'.e($label).'</span><strong>'.e($value).'</strong></div>'; }
function tabs(array $items,string $active): void { echo '<div class="leave-tabs">';foreach($items as $p=>$label)echo '<a class="'.($active===$p?'active':'').'" href="'.e(url($p)).'">'.e($label).'</a>';echo '</div>'; }
function filteredAdminCards(): array {
    $filters=[];
    foreach(['q'=>191,'merchant'=>120,'from'=>10,'to'=>10,'status'=>20] as $key=>$max){
        $value=$_GET[$key]??'';
        if(!is_string($value)||mb_strlen($value)>$max)throw new AppError('검색 조건을 확인해주세요.');
        $filters[$key]=trim($value);
    }
    $statuses=[''=>'전체 상태','PENDING'=>'검토 대기','APPROVED'=>'승인 완료','REJECTED'=>'반려','CANCELLED'=>'취소'];
    echo '<form method="get" class="employee-filters"><input type="hidden" name="page" value="admin-cards">';
    field('q','직원 이름·아이디',$filters['q'],'search','maxlength="191" placeholder="이름 또는 아이디"');
    field('merchant','사용처',$filters['merchant'],'search','maxlength="120" placeholder="사용처 검색"');
    field('from','지출 기간 시작',$filters['from'],'date');field('to','지출 기간 종료',$filters['to'],'date');
    selectField('status','처리 상태',$statuses,$filters['status']);
    echo '<button class="primary">검색</button><a class="secondary" href="'.e(url('admin-cards')).'">초기화</a></form><p>지출일 기준으로 검색합니다. 날짜를 비우면 전체 기간을 조회합니다.</p>';
    try {
        if(!isset($statuses[$filters['status']]))throw new AppError('처리 상태를 확인해주세요.');
        foreach(['from','to'] as $key)if($filters[$key]!=='')dateValue($filters[$key]);
        if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])throw new AppError('지출 기간 종료는 시작보다 빠를 수 없습니다.');
    }catch(AppError $error){echo '<p class="error" role="alert">'.e($error->getMessage()).'</p>';return [[],false];}
    $where=[];$args=[];
    $pattern=fn($value)=>'%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($value)).'%';
    if($filters['q']!==''){$where[]="(LOWER(u.name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!')";$args[]=$pattern($filters['q']);$args[]=$pattern($filters['q']);}
    if($filters['merchant']!==''){$where[]="LOWER(c.merchant) LIKE ? ESCAPE '!'";$args[]=$pattern($filters['merchant']);}
    if($filters['status']!==''){$where[]='c.status=?';$args[]=$filters['status'];}
    if($filters['from']!==''){$where[]='c.usedAt>=?';$args[]=$filters['from'];}
    if($filters['to']!==''){$where[]='c.usedAt<?';$args[]=dateObject($filters['to'])->modify('+1 day')->format('Y-m-d');}
    return [rows('SELECT c.*,u.name,u.department,a.name AS decider FROM `CardExpense` c JOIN `User` u ON c.userId=u.id LEFT JOIN `User` a ON c.decidedById=a.id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY c.usedAt DESC,c.createdAt DESC,c.id',$args),true];
}
function filteredAdminLeaves(): array {
    $filters=[];
    foreach(['q'=>191,'department'=>100,'from'=>10,'to'=>10,'status'=>30] as $key=>$max){
        $value=$_GET[$key]??'';
        if(!is_string($value)||mb_strlen($value)>$max)throw new AppError('검색 조건을 확인해주세요.');
        $filters[$key]=trim($value);
    }
    $statuses=[''=>'전체 상태','IN_PROGRESS'=>'결재 진행 전체']+array_diff_key(STATUSES,['PENDING'=>true]);
    $departments=[''=>'전체 부서'];
    foreach(rows('SELECT DISTINCT department FROM `User` ORDER BY department') as $r)$departments[$r['department']]=$r['department'];
    if($filters['department']!==''&&!isset($departments[$filters['department']]))$departments[$filters['department']]=$filters['department'];
    $summary=array_filter([$filters['q'],$filters['department'],$filters['from'],$filters['to'],$filters['status']!==''?($statuses[$filters['status']]??$filters['status']):'']);
    echo '<details class="admin-leave-search" data-mobile-filter open><summary>검색 조건 <span>'.e($summary?implode(' · ',$summary):'전체 직원 · 전체 기간 · 전체 상태').'</span></summary><form method="get" class="employee-filters"><input type="hidden" name="page" value="admin-leaves">';
    field('q','직원 이름·아이디',$filters['q'],'search','maxlength="191" placeholder="이름 또는 아이디"');
    selectField('department','부서',$departments,$filters['department']);
    field('from','휴가 기간 시작',$filters['from'],'date');field('to','휴가 기간 종료',$filters['to'],'date');
    selectField('status','결재 상태',$statuses,$filters['status']);
    echo '<button class="primary">검색</button><a class="secondary" href="'.e(url('admin-leaves')).'">초기화</a></form></details>';
    $where=['l.isDeleted=0'];$args=[];
    try {
        if(!isset($statuses[$filters['status']]))throw new AppError('결재 상태를 확인해주세요.');
        foreach(['from','to'] as $key)if($filters[$key]!=='')dateValue($filters[$key]);
        if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])throw new AppError('휴가 기간 종료는 시작보다 빠를 수 없습니다.');
    }catch(AppError $error){echo '<p class="error" role="alert">'.e($error->getMessage()).'</p>';return [[],false];}
    if($filters['q']!==''){$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($filters['q'])).'%';$where[]="(LOWER(u.name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!')";$args[]=$pattern;$args[]=$pattern;}
    if($filters['department']!==''){$where[]='u.department=?';$args[]=$filters['department'];}
    if($filters['status']==='IN_PROGRESS')$where[]="l.status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO')";
    elseif($filters['status']!==''){$where[]='l.status=?';$args[]=$filters['status'];}
    if($filters['from']!==''){$where[]='l.endDate>=?';$args[]=$filters['from'];}
    if($filters['to']!==''){$where[]='l.startDate<?';$args[]=dateObject($filters['to'])->modify('+1 day')->format('Y-m-d');}
    return [rows('SELECT l.*,u.name,u.department FROM `LeaveRequest` l JOIN `User` u ON u.id=l.userId WHERE '.implode(' AND ',$where).' ORDER BY l.createdAt DESC,l.id',$args),true];
}
// Mobile cards use the same filtered records as the desktop tables.
function mobileAdminCard(string $identity,string $status,array $fields): void {
    echo '<article class="admin-mobile-card"><div class="admin-mobile-identity">'.$identity.'</div><div class="admin-mobile-status">'.$status.'</div><details><summary>상세 정보</summary><dl>';
    foreach($fields as $label=>$value)echo '<div><dt>'.e($label).'</dt><dd>'.$value.'</dd></div>';
    echo '</dl></details></article>';
}
function recordCellStart(string $label,string $class=''): void {
    echo '<td class="'.e($class).'"><span class="mobile-cell-label" aria-hidden="true">'.e($label).'</span><div class="cell-value">';
}
function recordCell(string $label,string $html,string $class=''): void {
    recordCellStart($label,$class);echo $html.'</div></td>';
}
function leaveListPagination(int $current,int $pages,int $count): void {
    if($pages<=1)return;
    $args=[];foreach(listContext($_GET,true) as $key=>$value)if(!in_array($key,['return_page','return_scroll','return_listPage'],true))$args[substr($key,7)]=$value;
    echo '<nav class="leave-pagination" aria-label="연차 목록 페이지">';
    if($current>1)echo '<a class="secondary" href="'.e(url('admin-leaves',['listPage'=>$current-1]+$args)).'">이전</a>';
    echo '<span>'.$current.' / '.$pages.'페이지 · '.(($current-1)*10+1).'–'.min($current*10,$count).'건</span>';
    if($current<$pages)echo '<a class="secondary" href="'.e(url('admin-leaves',['listPage'=>$current+1]+$args)).'">다음</a>';
    echo '</nav>';
}
function leaveTable(array $list,array $u,?string $stage=null,bool $manage=false): void {
    if(!$list){echo '<div class="empty">표시할 휴가 신청이 없습니다.</div>';return;}
    echo '<div class="tablewrap record-tablewrap"><table class="leave-table responsive-table"><thead><tr><th scope="col">직원</th><th scope="col">종류</th><th scope="col">기간</th><th scope="col">일수</th><th scope="col">사유</th><th scope="col">상태</th><th scope="col">처리</th></tr></thead><tbody>';
    foreach($list as $r){
        echo '<tr data-record-id="'.e($r['id']).'">';
        $employeeName=e($r['name']??$u['name']);
        if($manage&&$u['role']==='ADMIN')$employeeName='<a class="employee-name" data-preserve-list-scroll href="'.e(url('employee',['id'=>$r['userId']])).'">'.$employeeName.'</a>';
        recordCell('직원',$employeeName.'<small>'.e($r['department']??$u['department']).'</small>','record-identity');
        $compact=$manage&&($_GET['page']??'')==='admin-leaves';
        recordCell('종류',e(LEAVE_TYPES[$r['leaveType']]??$r['leaveType']));
        $start=substr($r['startDate'],0,10);$end=substr($r['endDate'],0,10);
        recordCell('기간',e($start).($compact&&$start===$end?'':'<br>~ '.e($end)));
        $currentDays=leaveDays($r['startDate'],$r['endDate'],$r['leaveType']);
        $calendarChanged=in_array($r['status'],['PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO','APPROVED'],true)&&abs($currentDays-(float)$r['days'])>0.001;
        recordCell('일수',e($r['days']).'일'.($calendarChanged?'<small>휴일 기준 재확인 필요 · 현재 '.e($currentDays).'일</small>':''));
        recordCell('사유',$compact?'<details class="leave-reason" data-mobile-filter open><summary>사유 보기</summary><div>'.e($r['reason']).'</div></details>':e($r['reason']),'detail-cell');
        recordCellStart('상태','record-status');echo badge($r['status']);
        foreach(['teamLeader','director','ceo'] as $p)if($r[$p.'RejectReason']??null)echo '<small>'.e($r[$p.'RejectReason']).'</small>';
        echo '</div></td>';recordCellStart('처리','record-actions');echo '<div class="actions approval-controls review-decision-controls'.($manage?' admin-leave-controls':'').'">';
        $decisionStage=$stage;
        if($manage&&$u['role']==='ADMIN'&&$r['status']==='PENDING_CEO')$decisionStage='ceo';
        if($decisionStage&&canDecideStage($u,$decisionStage)){buttonForm('leave_decide','승인',['id'=>$r['id'],'reviewToken'=>leaveReviewToken($r),'stage'=>$decisionStage,'decision'=>'APPROVE'],'approve',true);formStart('leave_decide',['id'=>$r['id'],'reviewToken'=>leaveReviewToken($r),'stage'=>$decisionStage,'decision'=>'REJECT'],'decision-form');echo '<label>반려 사유<input name="reason" required maxlength="500" placeholder="반려 사유" aria-label="반려 사유"></label><button class="reject">반려</button></form>';}
        if(!$stage) {if($u['role']==='ADMIN'||canChangeOwnLeave($u,$r))echo '<a data-dialog="leave" class="link" href="'.e(url('leave-edit',['id'=>$r['id']])).'">수정</a>';if($u['role']==='ADMIN'&&$r['status']==='APPROVED')echo '<a data-dialog="leave" class="link danger" href="'.e(url('leave-cancel',['id'=>$r['id']])).'">휴가 취소</a>';if(canChangeOwnLeave($u,$r))buttonForm('leave_cancel','취소',['id'=>$r['id'],'reviewToken'=>leaveReviewToken($r)],'link danger',true);}
        if($u['role']==='ADMIN'||$r['userId']===$u['id'])echo '<a class="link"'.($compact?' data-preserve-list-scroll':'').' href="'.e(url($u['role']==='ADMIN'&&($manage||$stage||$r['userId']!==$u['id'])?'admin-history':'history',['request'=>$r['id']]+($compact?listContext($_GET,true):[]))).'">이력</a>';
        echo '</div></div></td></tr>';
    }echo '</tbody></table></div>';
}
function cardTable(array $list,array $u,bool $manage=false): void {
    if(!$list){echo '<div class="empty">등록된 법인카드 사용 내역이 없습니다.</div>';return;}
    echo '<div class="tablewrap record-tablewrap" tabindex="0" role="region" aria-label="법인카드 사용 내역 목록"><table class="card-table responsive-table"><thead><tr><th scope="col">작성자</th><th scope="col">지출일</th><th scope="col">사용처</th><th scope="col">상세 사용 내역</th><th scope="col">금액</th><th scope="col">증빙·옵션</th><th scope="col">비고</th><th scope="col">상태</th><th scope="col">처리</th></tr></thead><tbody>';
    foreach($list as $r){
        echo '<tr data-record-id="'.e($r['id']).'">';
        recordCell('작성자',e($r['name']).'<small>'.e($r['department']).'</small>');
        recordCell('지출일',e(substr($r['usedAt'],0,10)));recordCell('사용처',e($r['merchant']),'record-identity');
        recordCell('사용 내역',e($r['purpose']),'detail-cell');recordCell('금액',number_format((float)$r['amount']).'원','money');
        recordCellStart('증빙·옵션');
        renderCardReceiptLinks($r);
        foreach(['isFixed'=>'고정 지출','hasReceipt'=>'영수증 있음','hasApprovalDocument'=>'품의서 있음'] as $k=>$v)if($r[$k])echo '<small>'.e($v).'</small>';
        echo '</div></td>';recordCell('비고',e($r['note']));
        recordCell('상태',badge($r['status']).'<small>'.e($r['decisionReason']).'</small><small>'.e($r['decider']).'</small>','record-status');
        recordCellStart('처리','record-actions');
        if($r['status']==='PENDING'){
            if($manage){echo '<div class="approval-controls review-decision-controls">';buttonForm('card_decide','승인',['id'=>$r['id'],'reviewToken'=>cardReviewToken($r),'decision'=>'APPROVE'],'approve',true);formStart('card_decide',['id'=>$r['id'],'reviewToken'=>cardReviewToken($r),'decision'=>'REJECT'],'decision-form');echo '<label>반려 사유<input name="reason" required maxlength="500" placeholder="반려 사유"></label><button class="reject">반려</button></form></div>';}
            elseif(canEditOwnCard($u,$r)){echo '<div class="actions approval-controls"><a data-dialog="card" class="link" href="'.e(url('card-edit',['id'=>$r['id']])).'">수정</a>';buttonForm('card_cancel','취소',['id'=>$r['id'],'reviewToken'=>cardReviewToken($r)],'link danger',true);echo '</div><details><summary>추가 증빙 첨부</summary>';formStart('card_receipt',['id'=>$r['id'],'reviewToken'=>cardReviewToken($r)],'form',true);echo '<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-label="추가 증빙 파일"><small>기존 파일은 유지되며, 선택한 파일이 추가됩니다. JPG, PNG, WEBP, PDF · 파일당 최대 10MB</small><button>증빙 추가</button></form></details>';}
        }echo '</div></td></tr>';
    }echo '</tbody></table></div>';
}
function renderCardReceiptLinks(array $card): void {
    $files=cardReceipts($card);
    if(!$files)return;
    echo '<ul class="card-receipt-list" aria-label="첨부된 증빙 '.count($files).'개">';
    foreach($files as $file)echo '<li><a href="'.e(url('receipt',['id'=>$card['id'],'attachment'=>$file['receiptFilePath']])).'">'.e($file['receiptFileName']?:'증빙 다운로드').'</a></li>';
    echo '</ul>';
}
function renderCardForm(array $u,?array $old=null): void {
    $action=$old?'card_edit':'card_create';
    $f=($old??[])+['usedAt'=>date('Y-m-d'),'merchant'=>'','purpose'=>'','amount'=>'','isFixed'=>0,'hasReceipt'=>0,'hasApprovalDocument'=>0,'note'=>''];
    if(($_POST['action']??'')===$action)foreach(array_keys($f) as $key)if(isset($_POST[$key])&&is_string($_POST[$key]))$f[$key]=$_POST[$key];
    if($old)echo '<section class="panel formpanel">';
    else echo '<details class="panel formpanel card-entry" open><summary>새 내역 등록</summary>';
    $registrationToken=$_POST['registrationToken']??'';
    if(!is_string($registrationToken)||!preg_match('/^[a-f0-9]{32}$/D',$registrationToken))$registrationToken=uid();
    formStart($action,$old?['id'=>$old['id'],'reviewToken'=>(($_POST['action']??'')==='card_edit'&&is_string($_POST['reviewToken']??null)?$_POST['reviewToken']:cardReviewToken($old))]:['registrationToken'=>$registrationToken],'form',!$old);
    echo '<div class="row">';field('usedAt','지출일',substr($f['usedAt'],0,10),'date','required');field('author','작성자',$u['name'],'text','readonly');echo '</div>';
    field('merchant','사용처',$f['merchant'],'text','required maxlength="120" placeholder="예: 주식회사 ○○"');
    echo '<label>상세 사용 내역<textarea name="purpose" required maxlength="500" placeholder="구체적인 사용 내역을 작성해주세요.">'.e($f['purpose']).'</textarea></label>';
    field('amount','금액',$f['amount'],'number','required min="1" max="1000000000" step="1" placeholder="원 단위"');
    echo '<div class="card-option-grid">';
    foreach(['isFixed'=>'고정 지출 여부','hasReceipt'=>'영수증 유무','hasApprovalDocument'=>'품의서 유무'] as $k=>$v){
        if($k==='hasReceipt'&&!empty($old['receiptFilePath']))echo '<label>영수증 유무<input value="O (증빙 첨부됨)" readonly><input type="hidden" name="hasReceipt" value="1"></label>';
        else selectField($k,$v,[''=>'X','1'=>'O'],empty($f[$k])?'':'1');
    }
    echo '</div>';
    if(!$old)echo '<label>증빙 파일<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf"><small>JPG, PNG, WEBP, PDF · 최대 10MB</small></label>';
    elseif(cardReceipts($old)){echo '<div class="card-receipt">첨부된 증빙은 그대로 유지됩니다.';renderCardReceiptLinks($old);echo '</div>';}
    field('note','비고',$f['note'],'text','maxlength="300" placeholder="업무용 카드"');
    echo '<button class="primary">'.($old?'변경 저장':'사용 내역 등록').'</button></form>'.($old?'</section>':'</details>');
}
function renderLeaveHistory(array $u,bool $all): void {
    if($all)admin($u);
    $page=$all?'admin-history':'history';$filters=[];
    foreach(['q'=>191,'from'=>10,'to'=>10,'request'=>191] as $key=>$max){
        $value=$_GET[$key]??'';
        if(!is_string($value)||mb_strlen($value)>$max)throw new AppError('검색 조건을 확인해주세요.');
        $filters[$key]=trim($value);
    }
    $context=$all?listContext($_GET):[];
    if(($context['return_page']??'')!=='admin-leaves')$context=[];
    $back=[];foreach($context as $key=>$value)if($key!=='return_page')$back[substr($key,7)]=$value;
    echo '<div class="leave-history-page">';
    if($context)echo '<p><a class="secondary" href="'.e(url('admin-leaves',$back)).'">← 연차 관리 목록</a></p>';
    echo '<form method="get" class="employee-filters"><input type="hidden" name="page" value="'.e($page).'">';
    foreach($context as $key=>$value)echo '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';
    if($filters['request']!=='')echo '<input type="hidden" name="request" value="'.e($filters['request']).'">';
    if($all)field('q','직원 이름·아이디',$filters['q'],'search','maxlength="191" placeholder="이름 또는 아이디"');
    field('from','처리 기간 시작',$filters['from'],'date');field('to','처리 기간 종료',$filters['to'],'date');
    echo '<button class="primary">검색</button><a class="secondary" href="'.e(url($page,$context)).'">'.($filters['request']!==''?'전체 이력 보기':'초기화').'</a></form>';
    echo '<p class="history-filter-help">처리일(한국 시간) 기준으로 검색합니다. 기간 내 변경이 있는 신청을 표시하며, 펼치면 기간 밖 기록을 포함한 전체 이력을 볼 수 있습니다.</p>';
    try{
        foreach(['from','to'] as $key)if($filters[$key]!=='')dateValue($filters[$key]);
        if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])throw new AppError('처리 기간 종료는 시작보다 빠를 수 없습니다.');
    }catch(AppError $ex){echo '<p class="error" role="alert">'.e($ex->getMessage()).'</p></div>';return;}
    $list=rows('SELECT h.*,a.name,o.name AS owner,o.email AS ownerEmail,o.department AS ownerDepartment,l.status AS currentStatus,l.leaveType AS currentType,l.startDate AS currentStart,l.endDate AS currentEnd,l.days AS currentDays FROM `LeaveHistory` h JOIN `User` a ON h.actorId=a.id JOIN `User` o ON h.userId=o.id LEFT JOIN `LeaveRequest` l ON l.id=h.leaveRequestId'.($all?'':' WHERE h.userId=?').' ORDER BY h.createdAt DESC,h.id DESC',$all?[]:[$u['id']]);
    $groups=[];foreach($list as $event)$groups[$event['leaveRequestId']][]=$event;
    $total=count($groups);
    $groups=array_filter($groups,function($events)use($filters,$all){
        $first=$events[0];
        if($filters['request']!==''&&$first['leaveRequestId']!==$filters['request'])return false;
        if($all&&$filters['q']!==''&&mb_stripos($first['owner'],$filters['q'])===false&&mb_stripos($first['ownerEmail'],$filters['q'])===false)return false;
        foreach($events as $event){$day=substr(koreanTime($event['createdAt']),0,10);if(($filters['from']===''||$day>=$filters['from'])&&($filters['to']===''||$day<=$filters['to']))return true;}
        return false;
    });
    echo '<p class="history-result-count">검색 결과 <strong>'.count($groups).'건</strong> / 전체 '.$total.'건 · 신청 기준</p>';
    if(!$groups)echo '<section class="panel"><p class="empty">조건에 맞는 신청 이력이 없습니다. 검색 조건을 변경하거나 초기화해주세요.</p></section>';
    $labels=['CREATED_ON_BEHALF'=>'휴가 대리 신청','APPROVAL_ROUTE_CORRECTED_ON_BEHALF'=>'대리 신청 결재 경로 수정','CREATED'=>'휴가 신청','UPDATED'=>'신청 수정','ADMIN_UPDATED'=>'관리자 수정','CANCELLED'=>'신청 취소','ADMIN_CANCELLED'=>'관리자 휴가 취소','TEAM_APPROVED'=>'팀장 승인','TEAM_REJECTED'=>'팀장 반려','DIRECTOR_APPROVED'=>'이사 승인','DIRECTOR_REJECTED'=>'이사 반려','CEO_APPROVED'=>'최종 승인','CEO_REJECTED'=>'최종 반려','ADMIN_IMPORTED'=>'관리자 과거 내역 등록'];
    foreach($groups as $requestId=>$events){
        $latest=$events[0];
        echo '<details class="panel leave-history-group" data-request-id="'.e($requestId).'"'.($filters['request']!==''?' open':'').'><summary><span class="history-group-main"><strong>'.e($latest['owner']).'</strong><span class="history-group-department">'.e($latest['ownerDepartment']).'</span><span class="history-group-period">';
        if($latest['currentStatus']!==null){
            $start=substr($latest['currentStart'],0,10);$end=substr($latest['currentEnd'],0,10);
            echo e((LEAVE_TYPES[$latest['currentType']]??$latest['currentType']).' · '.$start.($end!==$start?' ~ '.$end:'').' · '.$latest['currentDays'].'일');
        }else echo '현재 신청 정보가 없습니다.';
        echo '</span><span class="history-group-latest">최근 처리: '.e($labels[$latest['action']]??'기타 휴가 처리').' · '.e($latest['name']).' · '.e(koreanTime($latest['createdAt'])).'</span></span><span class="history-group-side">';
        if($latest['currentStatus']!==null)echo '<span>현재 상태 '.badge($latest['currentStatus']).'</span>';
        echo '<span>전체 이력 '.count($events).'건</span><span class="history-group-toggle" aria-hidden="true"></span></span></summary><div class="timeline">';
        renderLeaveHistoryEvents($events,$labels);
        echo '</div></details>';
    }
    echo '</div>';
}
function renderLeaveHistoryEvents(array $list,array $labels): void {
    foreach($list as $r){
        $after=leaveHistorySnapshot($r['afterData']);
        echo '<article data-history-id="'.e($r['id']).'"><b>'.e($labels[$r['action']]??'기타 휴가 처리').' · '.e($r['name']).'</b><p>'.e($r['owner']).'</p>';
        if(!$after)echo '<p>당시 상세 내용이 기록되지 않았습니다.</p>';
        else{
            $start=leaveHistoryValue($after,'startDate');$end=leaveHistoryValue($after,'endDate');
            $period=$start!==null&&$end!==null&&$start!==$end?$start.' ~ '.$end:($start??$end);
            $parts=array_filter([leaveHistoryValue($after,'leaveType'),$period,leaveHistoryValue($after,'days')],fn($value)=>$value!==null);
            if($parts)echo '<p>'.e(implode(' · ',$parts)).'</p>';
            if(isset($after['status']))echo '<p>'.badge((string)$after['status']).'</p>';
            echo '<p class="history-text">'.e(isset($after['reason'])?'신청 사유: '.$after['reason']:'당시 신청 사유가 기록되지 않았습니다.').'</p>';
            if(in_array($r['action'],['UPDATED','ADMIN_UPDATED'],true)){
                $before=leaveHistorySnapshot($r['beforeData']);$changes=[];
                foreach(['leaveType'=>'휴가 종류','startDate'=>'시작일','endDate'=>'종료일','days'=>'일수','reason'=>'신청 사유'] as $key=>$label){
                    $previous=leaveHistoryValue($before,$key);$current=leaveHistoryValue($after,$key);
                    if($current!==null&&$previous!==$current)$changes[]='<li><strong>'.e($label).'</strong>: '.e($previous??'이전 기록 없음').' → '.e($current).'</li>';
                }
                if($changes)echo '<details class="history-changes"><summary>변경 내용</summary><ul>'.implode('',$changes).'</ul></details>';
            }
            $reasonKey=match($r['action']){'TEAM_REJECTED'=>'teamLeaderRejectReason','DIRECTOR_REJECTED'=>'directorRejectReason','CEO_REJECTED'=>'ceoRejectReason','ADMIN_CANCELLED'=>'cancellationReason',default=>null};
            if($reasonKey)echo '<p class="history-text">'.e(($r['action']==='ADMIN_CANCELLED'?'취소':'반려').' 사유: '.($after[$reasonKey]??'당시 기록 없음')).'</p>';
        }
        echo '<small>'.e(koreanTime($r['createdAt'])).'</small></article>';
    }
}
function teamApprovalFilters(): array {
    $filters=[];
    foreach(['q'=>100,'from'=>10,'to'=>10] as $key=>$max){
        $value=$_GET[$key]??'';
        if(!is_string($value)||mb_strlen($value)>$max)throw new AppError('검색 조건을 확인해주세요.');
        $filters[$key]=trim($value);
    }
    return $filters;
}
function filterTeamApplications(array $list,string $page): ?array {
    $filters=teamApprovalFilters();
    echo '<form method="get" class="employee-filters"><input type="hidden" name="page" value="'.e($page).'">';
    if($page==='approvals')echo '<input type="hidden" name="stage" value="team">';
    field('q','직원 이름',$filters['q'],'search','maxlength="100" placeholder="직원 이름 검색"');
    field('from','휴가 기간 시작',$filters['from'],'date');field('to','휴가 기간 종료',$filters['to'],'date');
    echo '<button class="primary">검색</button><a class="secondary" href="'.e(url($page,$page==='approvals'?['stage'=>'team']:[])).'">초기화</a></form><p>신청일·처리일이 아닌 휴가 기간 기준입니다. 선택한 기간과 하루라도 겹치는 신청을 표시합니다.</p>';
    try{
        foreach(['from','to'] as $key)if($filters[$key]!=='')dateValue($filters[$key]);
        if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])throw new AppError('휴가 기간 종료는 시작보다 빠를 수 없습니다.');
    }catch(AppError $e){echo '<p class="error" role="alert">'.e($e->getMessage()).'</p>';return null;}
    $filtered=array_values(array_filter($list,fn($r)=>
        ($filters['q']===''||mb_stripos($r['name'],$filters['q'])!==false)&&
        ($filters['from']===''||substr($r['endDate'],0,10)>=$filters['from'])&&
        ($filters['to']===''||substr($r['startDate'],0,10)<=$filters['to'])
    ));
    echo '<p>검색 결과 '.count($filtered).'건 / 전체 '.count($list).'건</p>';
    if(!$filtered&&array_filter($filters))echo '<p class="empty">조건에 맞는 신청이 없습니다. 검색 조건을 변경하거나 초기화해주세요.</p>';
    return $filtered;
}
function teamApprovalTabs(string $active): void {
    $filters=array_filter(teamApprovalFilters(),fn($v)=>$v!=='');
    echo '<div class="leave-tabs">';
    foreach(['approvals'=>'승인 대기','team-processed'=>'내가 처리한 신청'] as $page=>$label)echo '<a class="'.($active===$page?'active':'').'" href="'.e(url($page,($page==='approvals'?['stage'=>'team']:[])+$filters)).'">'.e($label).'</a>';
    echo '</div>';
}
function renderTeamProcessed(array $u): void {
    $list=teamProcessedRows($u);
    title('팀장 결재');teamApprovalTabs('team-processed');
    $list=filterTeamApplications($list,'team-processed');if($list===null)return;
    echo '<section class="panel"><div class="panelhead"><h2>처리한 신청 '.count($list).'건</h2></div><div class="timeline">';
    if(!$list&&!array_filter(teamApprovalFilters()))echo '<p class="empty">아직 처리한 신청이 없습니다.</p>';
    foreach($list as $r){
        $start=substr($r['startDate'],0,10);$end=substr($r['endDate'],0,10);
        echo '<article data-leave-id="'.e($r['id']).'"><b>'.e($r['name']).' · '.e($r['department']).'</b><p>'.e(LEAVE_TYPES[$r['leaveType']]??$r['leaveType']).' · '.e($start===$end?$start:$start.' ~ '.$end).' · '.e($r['days']).'일</p><p>'.badge($r['status']).'</p><p class="history-text">현재 신청 사유: '.e($r['reason']).'</p><p>내 처리: '.($r['teamLeaderStatus']==='APPROVED'?'승인':'반려').' · '.e(koreanTime($r['teamLeaderApprovedAt'])).'</p>';
        if($r['ceoApprovedAt'])echo '<p>'.($r['ceoStatus']==='APPROVED'?'최종 승인':'최종 반려').': '.e($r['finalApprover']).' · '.e(koreanTime($r['ceoApprovedAt'])).'</p>';
        foreach(['teamLeaderRejectReason','directorRejectReason','ceoRejectReason'] as $key)if($r[$key])echo '<p class="history-text">반려 사유: '.e($r[$key]).'</p>';
        if($r['status']==='CANCELLED'){$cancel=leaveHistorySnapshot($r['cancellationData']);if(isset($cancel['cancellationReason']))echo '<p class="history-text">관리자 취소 사유: '.e($cancel['cancellationReason']).'</p>';}
        echo '</article>';
    }
    echo '</div></section>';
}
function renderPage(string $page,array $u): void {
    if(in_array($page,['attendance','admin-attendance'],true)){renderAttendance($u,$page==='admin-attendance');return;}
    if(in_array($page,['admin','employees','employee','admin-leaves','leave-cancel','admin-cards','settings','admin-history','admin-approvals'],true))admin($u);
    if($page==='password') {title('비밀번호 변경');echo '<section class="panel formpanel">';formStart('password');field('currentPassword','현재 비밀번호','','password','required autocomplete="current-password"');field('newPassword','새 비밀번호','','password','required minlength="10" maxlength="72" autocomplete="new-password"');field('confirmPassword','새 비밀번호 확인','','password','required minlength="10" maxlength="72" autocomplete="new-password"');echo '<button class="primary">비밀번호 변경</button></form></section>';return;}
    if($page==='dashboard'||$page==='admin') { renderDashboard($u);return; }
    if($page==='team-processed'){renderTeamProcessed($u);return;}
    if($page==='leave-cancel'){
        $leave=one('SELECT l.*,u.name,u.department FROM `LeaveRequest` l JOIN `User` u ON l.userId=u.id WHERE l.id=?',[(string)($_GET['id']??'')]);
        if(!$leave||$leave['isDeleted'])throw new AppError('신청을 찾을 수 없습니다.',404);
        if($leave['status']!=='APPROVED')throw new AppError('승인 완료된 휴가만 관리자 취소할 수 있습니다. 현재 상태를 확인해주세요.',409);
        title('승인된 휴가 취소');
        echo '<section class="panel formpanel"><div class="leave-guidance"><p><strong>'.e($leave['name']).'</strong> · '.e($leave['department']).'</p><p>'.e(LEAVE_TYPES[$leave['leaveType']]??$leave['leaveType']).' · '.e($leave['days']).'일</p><p>'.e(substr($leave['startDate'],0,10)).' ~ '.e(substr($leave['endDate'],0,10)).'</p><p class="history-text">신청 사유: '.e($leave['reason']).'</p><p>';
        echo in_array($leave['leaveType'],BALANCE_TYPES,true)?e(substr($leave['startDate'],0,4).'년 잔여 연차에 '.$leave['days'].'일이 복원됩니다.'):'연차를 차감하지 않는 휴가이므로 잔여 연차는 변하지 않습니다.';
        echo '</p></div>';
        formStart('leave_admin_cancel',['id'=>$leave['id'],'reviewToken'=>(($_POST['action']??'')==='leave_admin_cancel'&&is_string($_POST['reviewToken']??null)?$_POST['reviewToken']:leaveReviewToken($leave))]);
        echo '<label>취소 사유<textarea name="cancellationReason" required maxlength="500" placeholder="취소하는 이유를 입력해주세요.">'.e($_POST['cancellationReason']??'').'</textarea></label><button class="reject">휴가 취소 확정</button></form></section>';return;
    }
    if(in_array($page,['leaves','leave-apply','leave-edit','admin-leaves'],true)) {
        $old=$page==='leave-edit'?one('SELECT * FROM `LeaveRequest` WHERE id=?',[(string)($_GET['id']??'')]):null;
        if($page==='leave-edit'&&(!$old||$old['isDeleted']||$u['role']!=='ADMIN'&&!canChangeOwnLeave($u,$old)))throw new AppError('수정 권한이 없습니다.',403);
        if($page!=='admin-leaves')echo '<div class="personal-leave-page">';
        $editingEmployee=$old&&$u['role']==='ADMIN'&&$old['userId']!==$u['id'];
        title($page==='admin-leaves'?'연차 관리':($editingEmployee?'직원 연차 수정':'내 연차'));
        if($page!=='admin-leaves')echo '<p class="leave-page-description">연차를 신청하고 신청 내역과 처리 이력을 확인할 수 있습니다.</p>';
        if($page==='admin-leaves')adminLeaveTabs($u,$page);
        else tabs(['leave-apply'=>'연차 신청','leaves'=>'신청 내역','history'=>'이력'],$page);
        if($page==='leave-apply'||$page==='leave-edit'){
            $f=($_SERVER['REQUEST_METHOD']==='POST'?$_POST:[]) + ($old??[]) + ['leaveType'=>'ANNUAL','startDate'=>date('Y-m-d'),'endDate'=>date('Y-m-d'),'reason'=>''];
            $owner=$old?one('SELECT * FROM `User` WHERE id=?',[$old['userId']]):$u;
            echo '<section class="panel formpanel leave-request-panel"><div class="panelhead"><h2>'.($old?'연차 신청서 수정':'연차 신청서 작성').'</h2></div>';
            $summary=balance($owner);
            echo '<section class="leave-balance-summary" aria-label="현재 연차 현황"><h3>'.e(date('Y')).'년 연차 · 오늘 기준</h3><dl>';
            foreach(['remaining'=>'현재 잔여','pending'=>'결재 대기','available'=>'추가 신청 가능'] as $key=>$label)echo '<div><dt>'.e($label).'</dt><dd>'.e($summary[$key]).'<small>일</small></dd></div>';
            echo '</dl><p>추가 신청 가능 = 현재 잔여 − 결재 대기 연차. 신청일 기준 예상은 아래에서 확인하세요.</p></section>';
            echo '<div class="leave-guidance">';
            if($old)echo '<p><strong>신청자</strong> '.e($owner['name'].' · '.$owner['department']).'</p>';
            if($old)echo '<p>현재 상태: '.badge($old['status']).'. 변경 저장은 기존 결재 상태를 유지합니다.</p>';
            $direct=$old?($old['teamLeaderStatus']==='SKIPPED'&&$old['directorStatus']==='SKIPPED'):in_array($owner['role'],['TEAM_LEADER','DIRECTOR','GENERAL_MANAGER'],true);
            echo '<p>'.($direct?'관리자 결재 전까지 본인 신청을 수정·취소할 수 있습니다. 결재가 완료되면 관리자에게 문의하세요.':'본인 수정·취소는 팀장 승인 대기 중에만 가능합니다. 팀장 승인 이후에는 관리자에게 문의하세요.').'</p></div>';
            formStart($old?'leave_edit':'leave_create',$old?['id'=>$old['id'],'reviewToken'=>(($_POST['action']??'')==='leave_edit'&&is_string($_POST['reviewToken']??null)?$_POST['reviewToken']:leaveReviewToken($old))]:[]);$types=LEAVE_TYPES;unset($types['MONTHLY']);if(!$old||$u['role']!=='ADMIN')unset($types['SPECIAL']);selectField('leaveType','휴가 종류',$types,$f['leaveType']==='MONTHLY'?'ANNUAL':$f['leaveType']);echo '<div class="row">';field('startDate','시작일',substr($f['startDate'],0,10),'date','required');field('endDate','종료일',substr($f['endDate'],0,10),'date','required');echo '</div><label>사유<textarea name="reason" required maxlength="500">'.e($f['reason']).'</textarea></label><div class="leave-preview notice" role="status" aria-live="polite" data-preview-url="'.e(url('leave-preview',$old?['id'=>$old['id']]:[])).'">날짜와 휴가 종류를 선택하면 예상 차감과 신청 후 가능 일수가 표시됩니다. 최종 가능 여부는 저장 시 확인합니다.</div><button class="primary">'.($old?'변경 저장':'휴가 신청').'</button></form></section>';
        }else{
            $isAdminList=$page==='admin-leaves';
            if($isAdminList){[$list,$valid]=filteredAdminLeaves();if(!$valid)return;}else{$list=leaveRows($u);}
            $resultCount=count($list);$listPage=1;$pages=1;
            if($isAdminList){
                $rawPage=$_GET['listPage']??'1';
                if(!is_string($rawPage)||!preg_match('/^[1-9]\d{0,5}$/D',$rawPage))throw new AppError('목록 페이지를 확인해주세요.');
                $pages=max(1,(int)ceil($resultCount/10));$listPage=min((int)$rawPage,$pages);$_GET['listPage']=(string)$listPage;
                $list=array_slice($list,($listPage-1)*10,10);
            }
            echo '<section class="panel'.($isAdminList?' admin-leave-list':'').'"><div class="panelhead"><h2>'.($isAdminList?'검색 결과 ':'전체 신청 ').$resultCount.'건'.($isAdminList?' / 전체 '.e(scalar('SELECT COUNT(*) FROM `LeaveRequest` WHERE isDeleted=0')).'건':'').'</h2>';
            if($isAdminList)echo '<a class="secondary report-download" href="'.e(url('report',['year'=>date('Y')])).'">'.e(date('Y')).'년 전체 연차 보고서</a>';
            echo '</div>';
            if($isAdminList)leaveListPagination($listPage,$pages,$resultCount);
            if($isAdminList&&!$list)echo '<p class="empty">조건에 맞는 휴가 신청이 없습니다. 검색 조건을 변경하거나 초기화해주세요.</p>';else leaveTable($list,$u,null,$isAdminList);
            if($isAdminList)leaveListPagination($listPage,$pages,$resultCount);
            echo '</section>';
        }if($page!=='admin-leaves')echo '</div>';return;
    }
    if($page==='approvals') {
        $s=(string)($_GET['stage']??'team');$list=stageRows($u,$s);
        if($u['role']==='ADMIN'){title('연차 관리');adminLeaveTabs($u,$page,$s);}
        else title(stageLabel($s).' 결재'.(canDecideStage($u,$s)?'':' · 조회 전용'));
        if($s==='team'&&$u['role']==='TEAM_LEADER'){teamApprovalTabs('approvals');$list=filterTeamApplications($list,'approvals');if($list===null)return;}
        echo '<section class="panel"><div class="panelhead"><h2>'.e(stageLabel($s)).' 결재 대기 '.count($list).'건'.(canDecideStage($u,$s)?'':' · 조회 전용').'</h2></div>';leaveTable($list,$u,$s);echo '</section>';return;
    }
    if($page==='employees') {
        echo '<div class="employees-page">';title('직원 관리');echo '<p class="employees-description">직원 정보를 조회하고 계정 및 재직 상태를 관리할 수 있습니다.</p>';
        if(isset($_SESSION['temporary_password'])){echo '<div class="notice">새 임시 비밀번호: <strong>'.e($_SESSION['temporary_password']).'</strong><p>직원에게 안전하게 전달하세요. 이 화면을 떠나면 다시 표시하지 않습니다.</p></div>';unset($_SESSION['temporary_password']);}
        $filters=[];
        foreach(['q'=>191,'department'=>100,'active'=>1] as $key=>$max){$value=$_GET[$key]??'';if(!is_string($value)||mb_strlen($value)>$max)throw new AppError('검색 조건을 확인해주세요.');$filters[$key]=trim($value);}
        if(!in_array($filters['active'],['','0','1'],true))throw new AppError('재직 상태를 확인해주세요.');
        $where=[];$args=[];
        if($filters['q']!==''){$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($filters['q'])).'%';$where[]="(LOWER(name) LIKE ? ESCAPE '!' OR LOWER(email) LIKE ? ESCAPE '!')";$args[]=$pattern;$args[]=$pattern;}
        if($filters['department']!==''){$where[]='department=?';$args[]=$filters['department'];}
        if($filters['active']!==''){$where[]='isActive=?';$args[]=(int)$filters['active'];}
        $employees=rows('SELECT * FROM `User`'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY name,id',$args);
        $departments=[''=>'전체 부서'];foreach(rows('SELECT DISTINCT department FROM `User` ORDER BY department') as $dept)$departments[$dept['department']]=$dept['department'];
        if($filters['department']!==''&&!isset($departments[$filters['department']]))$departments[$filters['department']]=$filters['department'];
        echo '<form method="get" class="employee-filters"><input type="hidden" name="page" value="employees">';field('q','이름·아이디 검색',$filters['q'],'search','maxlength="191" placeholder="이름 또는 아이디"');selectField('department','부서',$departments,$filters['department']);selectField('active','재직 상태',[''=>'전체','1'=>'재직 중','0'=>'퇴사'],$filters['active']);echo '<button class="primary">검색</button><a class="secondary" href="'.e(url('employees')).'">초기화</a></form>';
        echo '<section class="panel"><div class="panelhead"><h2>검색 결과 '.count($employees).'명 / 전체 '.e(scalar('SELECT COUNT(*) FROM `User`')).'명</h2><a data-dialog="edit" class="primary small" href="'.e(url('employee')).'">직원 추가</a></div><div class="tablewrap admin-desktop-table" role="region" aria-label="직원 목록" tabindex="0"><table class="employee-directory-table"><thead><tr><th>직원</th><th>부서/직급</th><th>역할</th><th>잔여 연차</th><th>재직 상태</th></tr></thead><tbody>';
        if(!$employees)echo '<tr><td colspan="5" class="empty">조건에 맞는 직원이 없습니다. 검색어나 필터를 변경하거나 초기화해주세요.</td></tr>';
        foreach($employees as $r){$b=balance($r);$employeeBalances[$r['id']]=$b;echo '<tr><td><a class="employee-name" href="'.e(url('employee',['id'=>$r['id']])).'">'.e($r['name']).'</a><small>'.e($r['email']).'</small></td><td>'.e($r['department']).'<small>'.e($r['position']).'</small></td><td>'.e(ROLES[$r['role']]??$r['role']).'</td><td class="employee-balance"><strong>'.e($b['remaining']).'</strong><span>일</span><small>'.'연차'.' · '.($r['annualLeaveOverride']===null?'입사일 자동':'관리자 수동').'</small></td><td><span class="badge '.($r['isActive']?'APPROVED':'REJECTED').'">'.($r['isActive']?'재직 중':'퇴사').'</span></td></tr>'; }echo '</tbody></table></div><div class="admin-mobile-list">';
        if(!$employees)echo '<p class="empty">조건에 맞는 직원이 없습니다. 검색어나 필터를 변경하거나 초기화해주세요.</p>';
        foreach($employees as $r)mobileAdminCard('<a class="employee-name" href="'.e(url('employee',['id'=>$r['id']])).'">'.e($r['name']).'</a><small>'.e($r['department']).'</small>','<span class="badge '.($r['isActive']?'APPROVED':'REJECTED').'">'.($r['isActive']?'재직 중':'퇴사').'</span>',['아이디'=>e($r['email']),'직급'=>e($r['position']),'역할'=>e(ROLES[$r['role']]??$r['role']),'잔여 연차'=>e($employeeBalances[$r['id']]['remaining']).'일','연차 산정'=>$r['annualLeaveOverride']===null?'입사일 자동':'관리자 수동']);
        echo '</div></section></div>';return;
    }
    if($page==='employee') {
        $id=(string)($_GET['id']??'');$old=$id?one('SELECT * FROM `User` WHERE id=?',[$id]):null;if($id&&!$old)throw new AppError('직원을 찾을 수 없습니다.',404);
        $employeeContext=listContext($_GET,true);$employeeBack=[];
        $employeeBackPage=($employeeContext['return_page']??'')==='admin-leaves'?'admin-leaves':'employees';
        if(($employeeContext['return_page']??'')===$employeeBackPage)foreach($employeeContext as $key=>$value)if($key!=='return_page')$employeeBack[substr($key,7)]=$value;
        echo '<div class="employee-heading">';title($old?'직원 정보':'직원 추가');echo '<a class="secondary" href="'.e(url($employeeBackPage,$employeeBack)).'">← '.($employeeBackPage==='admin-leaves'?'연차 관리 목록':'직원 목록').'</a></div>';
        if($old)echo '<p class="employee-identity" data-login-id="'.e($old['email']).'" data-employee-name="'.e($old['name']).'">'.e($old['name']).' · '.e($old['department']).' · '.e($old['email']).'</p>';
        $f=(($_POST['action']??'')==='employee_save'?$_POST:[])+($old??[])+['name'=>'','department'=>'','position'=>'','role'=>'USER','joinDate'=>date('Y-m-d'),'isActive'=>1,'annualLeaveOverride'=>null];
        echo '<div class="employee-layout"><section class="panel formpanel">';formStart('employee_save',['id'=>$id],'form',false,'employee-save');if(!$old){field('email','아이디',$_POST['email']??'','text','required maxlength="191" autocomplete="off"');field('password','초기 비밀번호','','password','required minlength="10" maxlength="72" autocomplete="new-password"');}
        echo '<div class="row">';field('name','이름',$f['name'],'text','required maxlength="100"');field('joinDate','입사일',substr($f['joinDate'],0,10),'date','required');echo '</div><div class="row">';field('department','부서',$f['department'],'text','required maxlength="100"');field('position','직급',$f['position'],'text','required maxlength="100"');echo '</div><div class="row">';selectField('role','역할',ROLES,$f['role']);selectField('isActive','재직 상태',['1'=>'재직 중','0'=>'퇴사'],$f['isActive']);echo '</div><p>퇴사로 저장하면 로그인이 제한되고 기존 로그인도 종료됩니다. 이전 업무 기록은 유지됩니다.</p>';selectField('leaveAccrual','연차 산정',['AUTOMATIC'=>'입사일 기준 자동 (출퇴근·결근 미반영)','MANUAL'=>'관리자 수동 지정'],$f['leaveAccrual']??($f['annualLeaveOverride']===null?'AUTOMATIC':'MANUAL'));field('annualLeave','수동 연차 (수동 지정 선택 시 반영)',(($_POST['action']??'')==='employee_save'?($_POST['annualLeave']??15):($f['annualLeaveOverride']??15)),'number','min="0" max="366" step="0.5"');echo '</form><div class="employee-form-actions"><button type="submit" form="employee-save" class="primary">저장</button>';
        if($old&&$id!==$u['id']){buttonForm('employee_reset','임시 비밀번호 발급',['id'=>$id],'password-reset-button',true);}echo '</div></section>';
        if($old){$year=yearValue($_GET['year']??date('Y'));$b=balance($old,$year);echo '<section><form method="get" class="filter"><input type="hidden" name="page" value="employee"><input type="hidden" name="id" value="'.e($id).'">';foreach($employeeContext as $key=>$value)echo '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';echo '<input type="number" name="year" value="'.e($year).'" min="2000" max="2100" aria-label="조회 연도"><button>조회</button></form><div class="cards">';statCard('발생',$b['annual'].'일');statCard('사용',$b['used'].'일');statCard('조정',$b['adjustment'].'일');statCard('잔여',$b['remaining'].'일','green');statCard('결재 대기 휴가',$b['pending'].'일','orange');statCard('추가 신청 가능',$b['available'].'일','green');echo '</div>';
        if($year===(int)date('Y')){echo '<section class="panel formpanel balance-adjustment-panel"><div class="panelhead"><h2>연차 보정</h2></div><p class="notice">현재 잔여 연차는 <strong>'.e($b['remaining']).'일</strong>입니다.<br>변경 후 남아 있어야 할 총 일수를 입력하세요.<br>예: 잔여를 3일로 맞추려면 <strong>3</strong>을 입력합니다.</p>';formStart('employee_balance',['id'=>$id]);field('targetBalance','보정 후 잔여 연차 (일)',balance($old)['remaining'],'number','required min="0" max="366" step="0.5"');field('effectiveDate','보정 기록일',date('Y-m-d'),'date','required aria-describedby="balance-date-help"');echo '<small id="balance-date-help">연차는 저장 즉시 변경됩니다.</small>';field('reason','보정 사유','','text','required maxlength="200"');echo '<button class="primary">연차 보정 저장</button></form></section>';}else{echo '<p class="notice">선택한 연도는 조회 전용입니다. <a href="'.e(url('employee',['id'=>$id,'year'=>date('Y')])).'">올해 잔여 조정 화면으로 이동</a></p>';}echo '<details class="panel employee-history"><summary>'.e($year).'년 승인된 휴가</summary>';
            $leaves=array_values(array_filter(leaveRows($old),fn($r)=>$r['status']==='APPROVED'&&substr($r['startDate'],0,4)===(string)$year));$summer=array_sum(array_column(array_filter($leaves,fn($r)=>$r['leaveType']==='SUMMER_ADVANCE'),'days'));if($summer)echo '<p class="notice">여름휴가 선사용 '.e($summer).'일 · '.(underYear($old)?'1주년 도달 대기':'차감 면제 완료').'</p>';leaveTable($leaves,$u);echo '</details><details class="panel employee-history"><summary>잔여 일수 조정 이력</summary><div class="timeline">';foreach(rows('SELECT b.*,a.name FROM `LeaveBalanceAdjustment` b JOIN `User` a ON b.actorId=a.id WHERE b.userId=? ORDER BY b.createdAt DESC',[$id]) as $r)echo '<article><b>'.e($r['name']).' · '.e($r['previousBalance']).' → '.e($r['targetBalance']).'일</b><p>'.e($r['reason']).'</p><small>'.e(substr($r['effectiveDate'],0,10)).'</small></article>';echo '</div></details></section>'; }echo '</div>';return;
    }
    if($page==='card-edit'){
        $card=editableCard($u,(string)($_GET['id']??''));
        title('법인카드 내역 수정');renderCardForm($u,$card);return;
    }
    if($page==='cards'||$page==='admin-cards') {
        $manage=$page==='admin-cards';echo '<div class="card-expenses-page">';title($manage?'법인카드 사용 관리':'법인카드 사용 내역');
        if(!$manage)echo '<p class="card-page-description">법인카드 사용 내역을 입력하고 증빙 파일을 등록해 주세요.</p>';
        tabs($manage?['admin-cards'=>'사용 내역','admin-card-history'=>'처리 이력']:['cards'=>'사용 내역','card-history'=>'처리 이력'],$page);
        echo '<div'.($manage?'':' class="business-grid"').'>';
        if(!$manage)renderCardForm($u);
        if($manage){[$list,$valid]=filteredAdminCards();if(!$valid){echo '</div></div>';return;}}else{$list=cardRows($u);}
        echo '<section class="panel"><div class="panelhead"><h2>'.($manage?'검색 결과 ':'내 내역 ').count($list).'건'.($manage?' / 전체 '.e(scalar('SELECT COUNT(*) FROM `CardExpense`')).'건':'').'</h2></div>';
        if($manage)echo '<p class="report-scope"><strong>검색 결과 금액 합계: '.number_format(array_sum(array_column($list,'amount'))).'원</strong></p>';
        if($manage&&!$list)echo '<p class="empty">조건에 맞는 법인카드 내역이 없습니다. 검색 조건을 변경하거나 초기화해주세요.</p>';else cardTable($list,$u,$manage);
        echo '</section></div></div>';return;
    }
    if(in_array($page,['history','admin-history','card-history','admin-card-history'],true)) {
        $all=str_starts_with($page,'admin-');if($all)admin($u);
        if(str_contains($page,'card')){echo '<div class="card-expenses-page card-expenses-history">';title($all?'법인카드 사용 관리':'법인카드 사용 내역');if(!$all)echo '<p class="card-page-description">법인카드 사용 내역을 입력하고 증빙 파일을 등록해 주세요.</p>';tabs($all?['admin-cards'=>'사용 내역','admin-card-history'=>'처리 이력']:['cards'=>'사용 내역','card-history'=>'처리 이력'],$page);}
        else{if(!$all)echo '<div class="personal-leave-page">';title($all?'연차 관리':'내 연차');if(!$all)echo '<p class="leave-page-description">연차를 신청하고 신청 내역과 처리 이력을 확인할 수 있습니다.</p>';if($all)adminLeaveTabs($u,$page);else tabs(['leave-apply'=>'연차 신청','leaves'=>'신청 내역','history'=>'이력'],$page);}if(!str_contains($page,'card')){renderLeaveHistory($u,$all);if(!$all)echo '</div>';return;}echo '<section class="panel"><div class="timeline">';
        if(str_contains($page,'card')){echo '<h2 class="card-history-heading">처리 이력</h2>';$historyCards=cardRows($u,$all);if(!$historyCards)echo '<div class="empty">표시할 법인카드 처리 이력이 없습니다.</div>';foreach($historyCards as $r){echo '<article><b>'.e($r['name']).' · '.e($r['merchant']).' · '.number_format((float)$r['amount']).'원</b><p>등록: '.e(koreanTime($r['createdAt'])).'</p>';renderCardReceiptLinks($r);echo '<p>'.badge($r['status']).' '.e($r['decider']).' '.e($r['decisionReason']).'</p></article>';}}
        echo '</div></section>';if(str_contains($page,'card')||$page==='history')echo '</div>';return;
    }
    if($page==='settings') {title('회사 휴일 관리');renderHolidayImpacts();echo '<section class="panel holiday-panel"><div class="panelhead"><h2>회사 휴일 관리</h2></div>';formStart('holiday_add',[],'holiday-form');echo '<input type="date" name="date" required aria-label="날짜" value="'.e($_POST['date']??'').'"><input name="name" required maxlength="100" placeholder="휴일 이름" aria-label="휴일 이름" value="'.e($_POST['name']??'').'"><button class="primary">휴일 추가</button></form><div class="tablewrap"><table><thead><tr><th>날짜</th><th>휴일</th><th>처리</th></tr></thead><tbody>';foreach(rows('SELECT * FROM `CompanyHoliday` ORDER BY date') as $r){echo '<tr><td>'.e(substr($r['date'],0,10)).'</td><td>'.e($r['name']).'</td><td>';buttonForm('holiday_delete','삭제',['id'=>$r['id']],'link danger',true);echo '</td></tr>';}echo '</tbody></table></div></section>';return;}
    throw new AppError('페이지를 찾을 수 없습니다.',404);
}

function renderHolidayImpacts(): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!in_array($_POST['action']??'', ['holiday_add','holiday_delete'],true))return;
    if($_POST['action']==='holiday_delete'){
        $holiday=one('SELECT date FROM `CompanyHoliday` WHERE id=?',[(string)($_POST['id']??'')]);
        $date=substr($holiday['date']??'',0,10);
    }else $date=$_POST['date']??'';
    try{$date=dateValue($date);}catch(AppError){return;}
    $affected=holidayImpacts($date);if(!$affected)return;
    echo '<section class="panel"><div class="panelhead"><h2>휴일 변경에 영향받는 신청</h2></div><div class="tablewrap"><table><thead><tr><th>직원</th><th>기간</th><th>상태</th><th>처리</th></tr></thead><tbody>';
    foreach($affected as $r)echo '<tr><td>'.e($r['name']).'</td><td>'.e(substr($r['startDate'],0,10)).' ~ '.e(substr($r['endDate'],0,10)).'</td><td>'.badge($r['status']).'</td><td><a class="link" href="'.e(url('leave-edit',['id'=>$r['id']])).'">신청 확인 →</a></td></tr>';
    echo '</tbody></table></div></section>';
}
