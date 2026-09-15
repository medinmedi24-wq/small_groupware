<?php
declare(strict_types=1);
// The designated account belongs in private local configuration, never in source.
function finalApproverId(): string { return getenv('MNM_FINAL_APPROVER_ID') ?: (string)(config()['final_approver_id']??''); }
function isFinalApprover(array $u): bool { return $u['role']==='ADMIN' && $u['id']===finalApproverId(); }
function stageLabel(string $stage): string { return $stage==='team'?'팀장':'관리자'; }
function canDecideStage(array $u,string $stage): bool { return $stage==='team'?$u['role']==='TEAM_LEADER':($stage==='ceo'&&isFinalApprover($u)); }
function canChangeOwnLeave(array $u,array $leave): bool {
    if($leave['userId']!==$u['id']||$leave['isDeleted'])return false;
    if($leave['status']==='PENDING_TEAM_LEADER')return true;
    // Direct final approval is still the first review. Use the stored route, not a later role change.
    if($leave['status']!=='PENDING_CEO'||$leave['teamLeaderStatus']!=='SKIPPED'||$leave['directorStatus']!=='SKIPPED'||$leave['ceoStatus']!=='PENDING')return false;
    foreach(['teamLeader','director','ceo'] as $prefix)if(!empty($leave[$prefix.'ApprovedBy'])||!empty($leave[$prefix.'ApprovedAt']))return false;
    return true;
}
function approverNames(string $stage,array $owner): string {
    static $cache=[];
    $key=$stage.':'.($owner['department']??'').':'.$owner['id'];
    if(isset($cache[$key]))return $cache[$key];
    $people=$stage==='team'
        ?rows("SELECT name FROM `User` WHERE role='TEAM_LEADER' AND isActive=1 AND department=? AND id<>? ORDER BY name",[$owner['department'],$owner['id']])
        :rows("SELECT name FROM `User` WHERE id=? AND role='ADMIN' AND isActive=1",[finalApproverId()]);
    return $cache[$key]=$people?implode(', ',array_column($people,'name')).(count($people)>1?' 중 1명':''):'담당자 미지정 · 관리자에게 문의';
}
function leaveRoute(array $owner): string {
    $final='최종 결재: '.approverNames('ceo',$owner);
    return in_array($owner['role'],['TEAM_LEADER','DIRECTOR','GENERAL_MANAGER'],true)?$final:'팀장 결재: '.approverNames('team',$owner).' → '.$final;
}
