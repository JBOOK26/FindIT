<?php

require __DIR__ . '/../includes/claims.php';
$student = requireClaimStudent();
$pdo = appDatabase();

$claims = [];
$databaseError = false;
try {
    $statement = $pdo->prepare('SELECT c.id, c.status, c.created_at, i.title, i.item_type
        FROM claims c INNER JOIN items i ON i.id = c.item_id
        WHERE c.claimant_id = :claimant_id ORDER BY c.created_at DESC, c.id DESC');
    $statement->execute(['claimant_id' => $student['id']]);
    $claims = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('FindIT student claim list failed.');
    http_response_code(500);
    $databaseError = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Claims | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-reports">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="my-claims.php" aria-current="page">My Claims</a>
            <a href="browse-items.php">Browse Items</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <div class="browse-feed">
        <div class="feed-heading">
            <div>
                <h1>My Claims</h1>
                <p class="intro">Track your requests to recover found items.</p>
            </div>
        </div>
        <?php if ($databaseError): ?>
            <p class="message" role="alert">Your claims could not be loaded. Please try again later.</p>
        <?php elseif ($claims === []): ?>
            <section class="empty glass">
                <h2>You haven't submitted any claims</h2>
                <p><?= FINDIT_STUDENT_CLAIMS_ENABLED
                    ? 'Find an open Found report to submit a private claim.'
                    : 'Claim submissions will open once campus staff can review requests.' ?></p>
                <a class="button-secondary" href="browse-items.php">Browse items</a>
            </section>
        <?php else: ?>
            <section class="feed-posts" aria-label="Your claims">
                <?php foreach ($claims as $claim): ?>
                    <article class="feed-post glass">
                        <div class="feed-post-meta">
                            <span class="badge" data-kind="<?= claimHtml($claim['item_type']) ?>"><?= claimHtml(ucfirst($claim['item_type'])) ?></span>
                            <span class="feed-date">Submitted <?= claimHtml(date('M j, Y', strtotime($claim['created_at']))) ?></span>
                        </div>
                        <h2><?= claimHtml($claim['title']) ?></h2>
                        <footer class="feed-post-footer claim-post-footer">
                            <span class="badge claim-status" data-status="<?= claimHtml($claim['status']) ?>"><?= claimHtml(claimStatusLabel($claim['status'])) ?></span>
                            <a class="button-secondary feed-details" href="claim-details.php?id=<?= rawurlencode((string) $claim['id']) ?>">
                                View claim details
                            </a>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
