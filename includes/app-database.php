<?php

require_once __DIR__ . '/campus-time.php';

function appDatabase(): PDO
{
    static $connection = null;
    if ($connection !== null) {
        return $connection;
    }

    try {
        $config = __DIR__ . '/../config/database.php';
        if (!is_readable($config)) {
            throw new RuntimeException('Missing database configuration.');
        }
        // The config's local variables stay in this function's scope.
        require $config;
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new RuntimeException('Invalid database connection.');
        }
        // Use an offset so the server does not need MariaDB time-zone tables.
        $pdo->exec("SET SESSION time_zone = '+08:00'");
        date_default_timezone_set(FINDIT_CAMPUS_TIME_ZONE);
        $connection = $pdo;
        return $connection;
    } catch (Throwable $exception) {
        error_log('FindIT could not initialize its database connection.');
        http_response_code(503);
        header('Cache-Control: private, no-store');
        exit('The service is temporarily unavailable.');
    }
}
