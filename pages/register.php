<?php

ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);

session_start();

require __DIR__ . '/../config/database.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$values = [
    'full_name' => '',
    'student_id' => '',
    'email' => '',
];
$registered = isset($_GET['registered']) && $_GET['registered'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $value) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $values[$field] = trim($_POST[$field]);
        }
    }

    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errors[] = 'Your form session has expired. Please try again.';
    }

    $values['email'] = strtolower($values['email']);

    $fullNameLength = preg_match_all('/./us', $values['full_name']);
    if ($values['full_name'] === '') {
        $errors[] = 'Full Name is required.';
    } elseif ($fullNameLength === false) {
        $errors[] = 'Full Name must contain valid text.';
    } elseif ($fullNameLength > 150) {
        $errors[] = 'Full Name must be 150 characters or fewer.';
    }

    $studentIdLength = preg_match_all('/./us', $values['student_id']);
    if ($values['student_id'] === '') {
        $errors[] = 'Student ID is required.';
    } elseif ($studentIdLength === false) {
        $errors[] = 'Student ID must contain valid text.';
    } elseif ($studentIdLength > 50) {
        $errors[] = 'Student ID must be 50 characters or fewer.';
    }

    $emailLength = preg_match_all('/./us', $values['email']);
    if ($values['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (
        $emailLength === false
        || $emailLength > 191
        || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = 'Enter a valid email address of no more than 191 characters.';
    }

    $passwordLength = preg_match_all('/./us', $password);
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif ($passwordLength === false) {
        $errors[] = 'Password must contain valid text.';
    } elseif ($passwordLength < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors[] = 'Password must not exceed 72 bytes.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if ($errors === []) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $nextCsrfToken = bin2hex(random_bytes(32));

        try {
            $duplicateQuery = $pdo->prepare(
                'SELECT
                    EXISTS(SELECT 1 FROM users WHERE email = :email) AS email_exists,
                    EXISTS(SELECT 1 FROM users WHERE student_id = :student_id) AS student_id_exists'
            );
            $duplicateQuery->execute([
                'email' => $values['email'],
                'student_id' => $values['student_id'],
            ]);
            $duplicates = $duplicateQuery->fetch();

            if ((int) $duplicates['email_exists'] === 1) {
                $errors[] = 'That email address is already registered.';
            }

            if ((int) $duplicates['student_id_exists'] === 1) {
                $errors[] = 'That Student ID is already registered.';
            }

            if ($errors === []) {
                $insertUser = $pdo->prepare(
                    "INSERT INTO users (full_name, student_id, email, password_hash, role)
                     VALUES (:full_name, :student_id, :email, :password_hash, 'student')"
                );
                $insertUser->execute([
                    'full_name' => $values['full_name'],
                    'student_id' => $values['student_id'],
                    'email' => $values['email'],
                    'password_hash' => $passwordHash,
                ]);

                $_SESSION['csrf_token'] = $nextCsrfToken;
                header('Location: register.php?registered=1', true, 303);
                exit;
            }
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'That email address or Student ID is already registered.';
            } else {
                error_log('FindIT student registration database operation failed.');
                $errors[] = 'Registration is temporarily unavailable. Please try again later.';
            }
        }
    }
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Registration | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-auth">
<main class="auth-shell">
    <header class="topbar glass">
        <a class="brand" href="login.php">FindIT</a>
        <nav aria-label="Account navigation">
            <a href="login.php">Log In</a>
            <a href="register.php" aria-current="page">Register</a>
        </nav>
    </header>
    <div class="auth-layout">
    <section class="content-panel glass">
    <h1>Create a student account</h1>
    <p class="intro">Register to use FindIT campus lost and found.</p>

    <?php if ($registered): ?>
        <p class="notice" role="status">Registration successful. Your student account has been created.</p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="errors" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeHtml($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">

        <div class="field">
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" type="text" maxlength="150" autocomplete="name" required
                   value="<?= escapeHtml($values['full_name']) ?>">
        </div>

        <div class="field">
            <label for="student_id">Student ID</label>
            <input id="student_id" name="student_id" type="text" maxlength="50" autocomplete="off" required
                   value="<?= escapeHtml($values['student_id']) ?>">
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="191" autocomplete="email" required
                   value="<?= escapeHtml($values['email']) ?>">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
        </div>

        <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="8"
                   autocomplete="new-password" required>
        </div>

        <button type="submit">Register</button>
    </form>
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
