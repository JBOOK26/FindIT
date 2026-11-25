<?php

require __DIR__ . '/../includes/auth.php';
startAppSession();

if (isset($_SESSION['user'])) {
    header('Location: account.php', true, 303);
    exit;
}

$errors = [];
$email = '';
$loginFailed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $submittedPassword = $_POST['password'] ?? '';
    $submittedPassword = is_string($submittedPassword) ? $submittedPassword : '';

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form session has expired. Please reload the page and try again.';
    } elseif (
        $email === ''
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || strlen($email) > 191
        || $submittedPassword === ''
    ) {
        $loginFailed = true;
    } else {
        require __DIR__ . '/../config/database.php';

        try {
            $statement = $pdo->prepare(
                'SELECT id, full_name, password_hash, role, account_status
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (
                $user !== false
                && $user['account_status'] === 'active'
                && in_array($user['role'], ['student', 'administrator'], true)
                && password_verify($submittedPassword, $user['password_hash'])
            ) {
                if (!session_regenerate_id(true)) {
                    error_log('FindIT could not regenerate the session ID during login.');
                    $errors[] = 'Login is temporarily unavailable. Please try again later.';
                } else {
                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'name' => $user['full_name'],
                        'role' => $user['role'],
                    ];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: account.php', true, 303);
                    exit;
                }
            } else {
                $loginFailed = true;
            }
        } catch (PDOException $exception) {
            error_log('FindIT login database operation failed.');
            $errors[] = 'Login is temporarily unavailable. Please try again later.';
        }
    }

    if ($loginFailed) {
        $errors[] = 'Email or password is incorrect.';
    }
}

$csrfToken = getCsrfToken();

function escapeLoginHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log In | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-auth">
<main class="auth-shell">
    <header class="topbar glass">
        <a class="brand" href="login.php">FindIT</a>
        <nav aria-label="Account navigation">
            <a href="login.php" aria-current="page">Log In</a>
            <a href="register.php">Register</a>
        </nav>
    </header>
    <div class="auth-layout">
    <section class="content-panel glass">
    <h1>Log in to FindIT</h1>
    <p class="intro">Use your registered email and password.</p>

    <?php if ($errors !== []): ?>
        <div class="errors" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeLoginHtml($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= escapeLoginHtml($csrfToken) ?>">

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="191" autocomplete="username" required
                   value="<?= escapeLoginHtml($email) ?>">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button type="submit">Log In</button>
    </form>

    <p class="register-link"><a href="register.php">Create a student account</a></p>
    </section>
    <div class="auth-scene" aria-hidden="true">
        <div class="locator-art">
            <div class="locator-orbit"></div>
            <div class="locator-tile"><span class="locator-pin"></span></div>
        </div>
    </div>
    </div>
</main>
</body>
</html>
