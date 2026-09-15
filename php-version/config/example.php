<?php
// Copy to local.php. Keep config/ and storage/ outside the web document root.
return [
    'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
    'username' => 'YOUR_DATABASE_USER',
    'password' => '',
    'secure_cookie' => true,
    'timezone' => 'Asia/Seoul',
    // Exact company public IPs (IPv4/IPv6). Empty means GPS only. Never add proxy server IPs.
    'attendance_office_ips' => [],
    // Set the designated administrator's user ID in private local.php.
    'final_approver_id' => '',
    'storage' => dirname(__DIR__) . '/storage',
    // Set a long random value only during initial installation; clear afterwards.
    'setup_token' => '',
];
