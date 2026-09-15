<?php
declare(strict_types=1);
const ATTENDANCE_GPS_POLICY='songdo-ait-v1';
function attendanceLocation(array $in): array {
    $lat=number($in['latitude']??null,-90,90,'위도');
    $lng=number($in['longitude']??null,-180,180,'경도');
    $accuracy=number($in['accuracy']??null,0.01,100,'위치 정확도 (허용 오차 100m)');
    $captured=number($in['capturedAt']??null,1,9999999999999,'위치 확인 시각');
    $age=microtime(true)*1000-$captured;
    if($age>120000||$age< -30000)throw new AppError('위치 정보가 오래되었거나 시각이 잘못되었습니다. 위치를 다시 확인해주세요.',422);
    if(($in['policyVersion']??'')!==ATTENDANCE_GPS_POLICY)throw new AppError('위치 기준이 변경되었습니다. 새로고침해주세요.',409);
    $mode=$in['punchMode']??'office';
    if(!in_array($mode,['office','business_trip'],true))throw new AppError('근무 장소를 확인해주세요.');
    $a=sin(deg2rad($lat-37.3796517)/2)**2+cos(deg2rad(37.3796517))*cos(deg2rad($lat))*sin(deg2rad($lng-126.6659428)/2)**2;
    $distance=6371008.8*2*asin(min(1,sqrt($a)));
    $inside=$distance+$accuracy<=150;$outside=$distance-$accuracy>150;
    if(!$inside&&!$outside)throw new AppError('GPS 오차가 회사 경계와 겹칩니다. 정확한 위치를 켜고 다시 시도해주세요.',422);
    if($mode==='office'&&!$inside)throw new AppError('송도 AIT센터 반경 150m 밖입니다. 출장 중이면 출장 출퇴근을 선택하고 사유를 입력해주세요.',422);
    if($mode==='business_trip'&&!$outside)throw new AppError('회사 안에서는 일반 출퇴근으로 기록해주세요.',422);
    $reason=$mode==='business_trip'?required($in['tripReason']??'','출장지·사유',120):'';
    if($mode==='business_trip'&&mb_strlen($reason)<2)throw new AppError('출장지·사유를 2자 이상 입력해주세요.');
    return ['latitude'=>$lat,'longitude'=>$lng,'accuracy'=>$accuracy,'capturedAt'=>gmdate('Y-m-d H:i:s',(int)($captured/1000)),'distanceMeters'=>$distance,'punchMode'=>$mode,'reason'=>$reason,'policyVersion'=>ATTENDANCE_GPS_POLICY];
}
function attendanceLocationLabel(array $day,string $kind): string {
    $time=$day[$kind==='in'?'checkIn':'checkOut']??null;
    if(!$time)return '';
    if(scalar('SELECT COUNT(*) FROM `AttendanceManual` WHERE userId=? AND workDate=? AND kind=? AND recordedAt=?',[$day['userId'],$day['workDate'],$kind,$time]))return '직접 입력';
    if(scalar('SELECT COUNT(*) FROM `AttendanceNetwork` WHERE userId=? AND workDate=? AND kind=? AND recordedAt=?',[$day['userId'],$day['workDate'],$kind,$time]))return '회사 네트워크 확인';
    $evidence=one('SELECT punchMode,reason FROM `AttendanceLocation` WHERE userId=? AND workDate=? AND kind=? AND recordedAt=?',[$day['userId'],$day['workDate'],$kind,$time]);
    return $evidence?($evidence['punchMode']==='office'?'회사 반경 확인':'출장 위치 기록 · '.$evidence['reason']):'GPS 미확인 · 기존/정정 기록';
}
