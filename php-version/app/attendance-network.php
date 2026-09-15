<?php
declare(strict_types=1);
function attendancePublicIp(mixed $value): ?string {
    if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
    $packed=inet_pton($value);
    // IPv4-mapped IPv6 must not bypass private/loopback filtering.
    if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")return attendancePublicIp(inet_ntop(substr($packed,12)));
    return inet_ntop($packed);
}
function attendanceOfficeIp(array $server,array $settings): ?string {
    // Trust the actual peer only. Forwarded headers are user-controlled unless a
    // deployment-specific trusted proxy policy is established separately.
    $ip=attendancePublicIp($server['REMOTE_ADDR']??null);
    if(!$ip)return null;
    $allowed=$settings['attendance_office_ips']??[];
    if(!is_array($allowed))return null;
    foreach($allowed as $candidate)if(attendancePublicIp($candidate)===$ip)return $ip;
    return null;
}
function attendanceNetworkCheck(?array $u): never {
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new AppError('지원하지 않는 요청입니다.',405);
    if(!$u||$u['mustChangePassword'])throw new AppError('로그인 상태를 확인해주세요.',401);
    checkCsrf();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['office'=>attendanceOfficeIp($_SERVER,config())!==null]);exit;
}
