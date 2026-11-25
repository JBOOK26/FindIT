<?php
require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();
if ($user['role'] !== 'student') {
    denyFindItAccess('Only student accounts can manage reports.');
}
$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';

$reports = [];
$databaseError = false;
try {
    $statement = $pdo->prepare("SELECT i.id, i.title, i.item_type, i.location, i.status, i.created_at, i.image_path,
        c.name AS category_name, EXISTS(SELECT 1 FROM claims cl WHERE cl.item_id = i.id) AS has_claims
        FROM items i INNER JOIN categories c ON c.id = i.category_id
        WHERE i.reporter_id = :reporter_id ORDER BY i.created_at DESC, i.id DESC");
    $statement->execute(['reporter_id' => $user['id']]);
    $reports = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('FindIT private report list failed.');
    $databaseError = true;
    http_response_code(500);
}
function escapeMyReports(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reports | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-reports">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="my-reports.php" aria-current="page">My Reports</a>
            <a href="report-item.php">Report an Item</a>
            <a href="browse-items.php">Browse Items</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <div class="browse-feed">
    <div class="feed-heading">
        <div>
        <h1>My Reports</h1>
        <p class="intro">View and manage the reports you submitted.</p>
        </div>
        <a class="button-secondary feed-report-shortcut" href="report-item.php">Report an item</a>
    </div>
<?php if ($databaseError): ?><p class="message" role="alert">Your reports are temporarily unavailable. Please try again later.</p>
<?php elseif ($reports === []): ?><p class="message">You have not submitted any reports yet. <a href="report-item.php">Report an item</a> to get started.</p>
<?php else: ?><section class="feed-posts" aria-label="My item reports">
<?php foreach ($reports as $report): $id = (string) $report['id']; $eligible = $report['status'] === 'open' && !(bool) $report['has_claims']; ?>
        <article class="feed-post glass">
            <div class="feed-post-meta">
                <span class="badge" data-kind="<?= escapeMyReports($report['item_type']) ?>"><?= escapeMyReports(ucfirst($report['item_type'])) ?></span>
                <span class="feed-category"><?= escapeMyReports($report['category_name']) ?></span>
                <span class="feed-date"><?= escapeMyReports(date('M j, Y', strtotime($report['created_at']))) ?></span>
            </div>
            <p class="feed-location">Location: <?= escapeMyReports($report['location']) ?></p>
            <h2><?= escapeMyReports($report['title']) ?></h2>
            <?php renderItemPhoto($id, $report['image_path'], $report['title']); ?>
            <footer class="feed-post-footer">
                <dl class="feed-facts">
                    <div>
                        <dt>Status</dt><dd><span class="badge status"><?= escapeMyReports($report['status']) ?></span></dd>
                    </div>
                </dl>
            <div class="actions">
                <a class="button-secondary feed-details" href="my-report-details.php?id=<?= escapeMyReports(rawurlencode($id)) ?>">View details</a>
                <?php if ($report['item_type'] === 'lost' && $report['status'] === 'open'): ?>
                    <a class="button-secondary" href="my-report-details.php?id=<?= rawurlencode($id) ?>#potential-matches">View Potential Matches</a>
                <?php endif; ?>
                <?php if ($eligible): ?>
                    <a class="button-secondary" href="edit-report.php?id=<?= escapeMyReports(rawurlencode($id)) ?>">Edit</a>
                    <a class="danger" href="my-report-details.php?id=<?= escapeMyReports(rawurlencode($id)) ?>&amp;confirm=1">Cancel</a>
                <?php endif; ?>
            </div>
            </footer>
        </article>
<?php endforeach; ?></section><?php endif; ?>
    </div>
</main>
</body>
</html>
