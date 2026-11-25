<?php

$host = 'localhost';
$database = 'findit';
$username = 'REPLACE_WITH_LOCAL_DB_USERNAME';
$password = 'REPLACE_WITH_YOUR_LOCAL_PASSWORD';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $exception) {
    error_log('FindIT database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable.');
}
