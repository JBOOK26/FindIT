<?php
require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();
if ($user['role'] !== 'student') {
    denyFindItAccess('Only student accounts can manage reports.');
}
$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';
require __DIR__ . '/../includes/item-matching.php';

$id = $_GET['id'] ?? '';
$validId = is_string($id) && preg_match('/\A[1-9][0-9]{0,19}\z/', $id) === 1
    && (strlen($id) < 20 || strcmp($id, '18446744073709551615') <= 0);
$report = null;
$error = '';
$databaseError = false;
if ($validId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session has expired. Please reload the page and try again.';
        http_response_code(403);
    } elseif (($_POST['action'] ?? null) !== 'cancel') {
        $error = 'The requested action is invalid.';
        http_response_code(400);
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM items WHERE id = :id AND reporter_id = :owner FOR UPDATE');
            $lock->execute(['id' => $id, 'owner' => $user['id']]);
            if (!$lock->fetch()) {
                $pdo->rollBack();
                http_response_code(404);
                $validId = false;
            } else {
                $update = $pdo->prepare("UPDATE items SET status = 'cancelled'
                    WHERE id = :id AND reporter_id = :owner AND status = 'open'
                    AND NOT EXISTS (SELECT 1 FROM claims WHERE claims.item_id = items.id)");
                $update->execute(['id' => $id, 'owner' => $user['id']]);
                if ($update->rowCount() !== 1) {
                    $pdo->rollBack();
                    http_response_code(409);
                    $error = 'This report can no longer be cancelled. Only open reports without claims can be cancelled.';
                } else {
                    $pdo->commit();
                    header('Location: my-report-details.php?id=' . rawurlencode($id) . '&cancelled=1', true, 303);
                    exit;
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('FindIT report cancellation failed.');
            $databaseError = true;
        }
    }
} elseif ($validId && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    $error = 'The requested method is not supported.';
}
if ($validId && !$databaseError) {
    try {
        $statement = $pdo->prepare("SELECT i.*, c.name AS category_name,
            EXISTS(SELECT 1 FROM claims cl WHERE cl.item_id = i.id) AS has_claims
            FROM items i INNER JOIN categories c ON c.id = i.category_id
            WHERE i.id = :id AND i.reporter_id = :owner LIMIT 1");
        $statement->execute(['id' => $id, 'owner' => $user['id']]);
        $report = $statement->fetch() ?: null;
    } catch (PDOException $exception) {
        error_log('FindIT private report details failed.');
        $databaseError = true;
    }
}
if ($databaseError) { http_response_code(500); }
elseif (!$validId || $report === null) { http_response_code(404); }
$eligible = $report !== null && $report['status'] === 'open' && !(bool) $report['has_claims'];
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$cancelled = isset($_GET['cancelled']) && $_GET['cancelled'] === '1' && $report !== null && $report['status'] === 'cancelled';
$showPotentialMatches = $_SERVER['REQUEST_METHOD'] === 'GET' && canViewItemMatches($report, $user['id']);
$potentialMatches = [];
$matchingUnavailable = false;
if ($showPotentialMatches) {
    try {
        $potentialMatches = findPotentialItemMatches($pdo, $report, $user['id']);
    } catch (PDOException $exception) {
        error_log('FindIT potential item matches could not be loaded.');
        $matchingUnavailable = true;
    }
}
function escapePrivateReport(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Report Details | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-details">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="my-reports.php" aria-current="location">My Reports</a>
            <a href="browse-items.php">Browse Items</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <article class="content-panel glass">
<?php if ($databaseError): ?><h1>Report unavailable</h1><p class="error" role="alert">Your report could not be loaded right now. Please try again later.</p>
<?php elseif ($report === null): ?><h1>Report unavailable</h1><p>This report could not be found.</p>
<?php else: ?><h1><?= escapePrivateReport($report['title']) ?></h1>
<?php if ($cancelled): ?><p class="notice" role="status">Your report was cancelled. Cancellation is final.</p><?php endif; ?>
<?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?><p class="notice" role="status">Your report was updated successfully.</p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= escapePrivateReport($error) ?></p><?php endif; ?>
<div class="detail-photo">
    <?php renderItemPhoto($id, $report['image_path'], $report['title'], false); ?>
</div>
<dl><dt>Type</dt><dd><?= escapePrivateReport(ucfirst($report['item_type'])) ?></dd><dt>Category</dt><dd><?= escapePrivateReport($report['category_name']) ?></dd><dt>Location</dt><dd><?= escapePrivateReport($report['location']) ?></dd><dt>Status</dt><dd class="status"><?= escapePrivateReport($report['status']) ?></dd><dt>Reported</dt><dd><?= escapePrivateReport(date('M j, Y \a\t g:i A', strtotime($report['created_at']))) ?></dd>
<dt>Date and time (Philippine time)</dt><dd><?= escapePrivateReport(formatCampusOccurrence($report['occurred_at'])) ?></dd></dl>
<h2>Description</h2><div class="description"><?= escapePrivateReport($report['description']) ?></div>
<?php if ($eligible && $confirm): ?><section class="confirm"><h2>Cancel this report?</h2><p>This will remove it from Browse Items. Cancellation is final and the report will remain in My Reports.</p><form method="post" action="my-report-details.php?id=<?= escapePrivateReport(rawurlencode($id)) ?>&amp;confirm=1"><input type="hidden" name="csrf_token" value="<?= escapePrivateReport(getCsrfToken()) ?>"><input type="hidden" name="action" value="cancel"><button type="submit">Confirm cancellation</button></form><p><a href="my-report-details.php?id=<?= escapePrivateReport(rawurlencode($id)) ?>">Keep report</a></p></section>
<?php elseif ($eligible): ?><div class="actions"><a href="edit-report.php?id=<?= escapePrivateReport(rawurlencode($id)) ?>">Edit report</a><a href="my-report-details.php?id=<?= escapePrivateReport(rawurlencode($id)) ?>&amp;confirm=1">Cancel report</a></div>
<?php else: ?><p>Only open reports without claims can be edited or cancelled.</p><?php endif; ?>
<?php if ($showPotentialMatches): ?>
    <section class="potential-matches" id="potential-matches" aria-labelledby="potential-matches-heading">
        <h2 id="potential-matches-heading">Potential Matches</h2>
        <?php if ($matchingUnavailable): ?>
            <p class="message" role="status">Potential matches are temporarily unavailable. Try again later.</p>
        <?php elseif ($potentialMatches === []): ?>
            <p class="message">No potential matches found yet. Check again later as new items are reported.</p>
        <?php else: ?>
            <p class="intro">These Found reports share details with yours. Review each report to see whether it might be your item.</p>
            <div class="match-list">
                <?php foreach ($potentialMatches as $match): ?>
                    <article class="match-card glass">
                        <div class="match-photo">
                            <?php renderItemPhoto($match['id'], $match['image_path'], $match['title']); ?>
                        </div>
                        <div class="match-content">
                            <span class="badge found">Possible match</span>
                            <h3><?= escapePrivateReport($match['title']) ?></h3>
                            <p class="match-meta"><?= escapePrivateReport($report['category_name']) ?> · <?= escapePrivateReport($match['location']) ?></p>
                            <ul class="match-reasons" aria-label="Matching reasons">
                                <?php foreach ($match['reasons'] as $reason): ?>
                                    <li><?= escapePrivateReport($reason) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <a class="button-secondary" href="item-details.php?id=<?= rawurlencode($match['id']) ?>">View Found report</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php endif; ?>
    <p class="back-link"><a href="my-reports.php">Back to My Reports</a></p>
    </article>
</main>
</body>
</html>
