<?php

require __DIR__ . '/includes/auth.php';

startAppSession();
header('Cache-Control: private, no-store');
header('Location: ' . (isset($_SESSION['user']) ? 'pages/account.php' : 'pages/login.php'), true, 303);
exit;
