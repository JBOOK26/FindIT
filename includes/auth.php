<?php

require_once __DIR__ . '/app-database.php';

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['user'])) {
        return;
    }

    $user = $_SESSION['user'];
    $isValidUser = is_array($user)
        && isset($user['id'], $user['name'], $user['role'])
        && is_int($user['id'])
        && $user['id'] > 0
        && is_string($user['name'])
        && in_array($user['role'], ['student', 'administrator'], true);
    $lastActivity = $_SESSION['last_activity'] ?? null;

    if (
        !$isValidUser
        || !is_int($lastActivity)
        || time() - $lastActivity >= 1800
    ) {
        destroyAppSession();
        session_start();
        return;
    }

    $_SESSION['last_activity'] = time();
}

function getCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken($submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function destroyAppSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $cookieParams = session_get_cookie_params();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => $cookieParams['httponly'],
            'samesite' => $cookieParams['samesite'],
        ]);
    }

    session_destroy();
}

function requireSignedInSession(): array
{
    startAppSession();

    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $projectRoot = rtrim(dirname(dirname($scriptName)), '/\\');
        $loginUrl = ($projectRoot === '' ? '' : $projectRoot) . '/pages/login.php';
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }

    return $_SESSION['user'];
}

function currentSessionAccount(PDO $pdo, int $id): ?array
{
    static $checkedConnection = null;
    static $checkedId = null;
    static $account = null;

    if ($checkedConnection !== $pdo || $checkedId !== $id) {
        $query = $pdo->prepare("SELECT full_name, role FROM users WHERE id = :id AND account_status = 'active'
            AND role IN ('student', 'administrator')");
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $account = $row === false ? null : ['id' => $id, 'name' => $row['full_name'], 'role' => $row['role']];
        $checkedConnection = $pdo;
        $checkedId = $id;
    }

    return $account;
}

function requireAuthenticatedUser(): array
{
    $session = requireSignedInSession();
    try {
        $account = currentSessionAccount(appDatabase(), $session['id']);
    } catch (PDOException $exception) {
        error_log('FindIT could not verify the signed-in account.');
        http_response_code(503);
        header('Cache-Control: private, no-store');
        exit('Account access is temporarily unavailable. Please try again.');
    }

    if ($account === null) {
        destroyAppSession();
        denyFindItAccess('An active account is required. If you need help, contact FindIT staff.');
    }

    $_SESSION['user'] = $account;
    return $account;
}

function denyFindItAccess(string $message): never
{
    http_response_code(403);
    header('Cache-Control: private, no-store');
    $signedIn = isset($_SESSION['user']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Access unavailable | FindIT</title>
        <link rel="stylesheet" href="../assets/css/findit.css">
    </head>
    <body class="page-details">
    <main>
        <section class="content-panel glass" aria-labelledby="access-heading">
            <h1 id="access-heading">Access unavailable</h1>
            <p class="intro"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <a class="button-secondary" href="<?= $signedIn ? 'account.php' : 'login.php' ?>"><?= $signedIn ? 'Back to Account' : 'Log in' ?></a>
        </section>
    </main>
    </body>
    </html>
    <?php
    exit;
}
