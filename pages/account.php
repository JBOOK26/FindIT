<?php

require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();
$pdo = appDatabase();

function escapeAccountHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$roleLabel = $user['role'] === 'administrator' ? 'Administrator' : 'Student';
$csrfToken = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-dashboard">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="account.php" aria-current="page">Account</a>
            <a href="browse-items.php">Browse Items</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <section class="dashboard-hero">
        <div class="hero-copy">
            <p class="eyebrow">Campus lost &amp; found</p>
            <h1>Welcome, <?= escapeAccountHtml($user['name']) ?></h1>
            <p class="role">Account type: <?= escapeAccountHtml($roleLabel) ?></p>
        </div>
        <div class="locator-art" aria-hidden="true">
            <div class="locator-orbit"></div>
            <div class="locator-tile"><span class="locator-pin"></span></div>
        </div>
    </section>
    <h2 class="dashboard-label">Quick actions</h2>
    <div class="dashboard-actions">
        <a class="shortcut glass" href="browse-items.php">
            <span class="shortcut-icon icon-search" aria-hidden="true"></span>
            <span class="shortcut-title">Browse Items</span>
            <span class="shortcut-description">Search lost and found reports across campus.</span>
        </a>
        <?php if ($user['role'] === 'student'): ?>
            <a class="shortcut shortcut-primary" href="report-item.php">
                <span class="shortcut-icon icon-plus" aria-hidden="true"></span>
                <span class="shortcut-title">Report an Item</span>
                <span class="shortcut-description">Tell the campus community what you lost or found.</span>
            </a>
            <a class="shortcut glass" href="my-reports.php">
                <span class="shortcut-icon icon-list" aria-hidden="true"></span>
                <span class="shortcut-title">My Reports</span>
                <span class="shortcut-description">View, edit, or cancel your own reports.</span>
            </a>
            <a class="shortcut glass" href="my-claims.php">
                <span class="shortcut-icon icon-list" aria-hidden="true"></span>
                <span class="shortcut-title">My Claims</span>
                <span class="shortcut-description">Track your requests to recover found items.</span>
            </a>
        <?php elseif ($user['role'] === 'administrator'): ?>
            <a class="shortcut glass" href="admin-claims.php">
                <span class="shortcut-icon icon-list" aria-hidden="true"></span>
                <span class="shortcut-title">Manage Claims</span>
                <span class="shortcut-description">Review requests and confirm item handoffs.</span>
            </a>
            <a class="shortcut glass" href="admin-matches.php">
                <span class="shortcut-icon icon-search" aria-hidden="true"></span>
                <span class="shortcut-title">Administrator Matches</span>
                <span class="shortcut-description">Review possible links between Lost and Found reports.</span>
            </a>
        <?php endif; ?>
    </div>
    <div class="dashboard-footer">
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= escapeAccountHtml($csrfToken) ?>">
            <button class="button-secondary" type="submit">Log Out</button>
        </form>
    </div>
</main>
</body>
</html>
