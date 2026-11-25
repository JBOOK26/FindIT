<?php

require __DIR__ . '/../includes/auth.php';
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Your session has expired. Please log in again.');
}

destroyAppSession();
header('Location: login.php', true, 303);
exit;
