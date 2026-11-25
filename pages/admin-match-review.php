<?php

require __DIR__ . '/../includes/admin-matches.php';
require_once __DIR__ . '/../includes/item-photos.php';
requireAdminMatchRead();
$pdo = appDatabase();

$lostId = $_GET['lost_id'] ?? null;
$foundId = $_GET['found_id'] ?? null;
$lostReport = null;
$foundReport = null;
$error = '';

try {

    if (claimIdIsValid($lostId) && claimIdIsValid($foundId) && $lostId !== $foundId) {
        $lostReport = loadOpenLostMatchReport($pdo, $lostId);
        if ($lostReport !== null) {
            foreach (rankOpenLostReportMatches($pdo, $lostReport) as $suggestion) {
                if ($suggestion['id'] === $foundId) {
                    $foundReport = $suggestion;
                    break;
                }
            }
        }
    }

    if ($foundReport === null) {
        http_response_code(404);
        $error = 'This pair is unavailable. Select a current suggestion from Administrator Matches.';
    }
} catch (PDOException $exception) {
    error_log('FindIT administrator match comparison failed.');
    http_response_code(500);
    $error = 'The comparison could not be loaded. Please try again later.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Match Review | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-reports">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="admin-matches.php">Matches</a>
            <a href="admin-claims.php">Manage Claims</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <div class="browse-feed admin-matches">
        <header class="feed-heading admin-match-review-heading">
            <div>
                <h1>Match Review</h1>
                <?php if ($error === ''): ?>
                    <p class="intro">Compare the reported details. Ownership requires separate verification.</p>
                <?php endif; ?>
            </div>
            <a class="button-secondary" href="admin-matches.php<?= $lostReport === null ? '' : '?lost_id=' . rawurlencode($lostId) . '#selected-report' ?>">Back to matches</a>
        </header>
        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= claimHtml($error) ?></p>
        <?php else: ?>
            <section class="feed-post glass admin-match-explanation" aria-labelledby="match-reasons-heading">
                <div class="feed-post-meta"><span class="badge claim-status">Possible match</span></div>
                <h2 id="match-reasons-heading">Why these reports were suggested</h2>
                <?php renderAdminMatchReasons($foundReport['reasons']); ?>
            </section>
            <div class="admin-match-pair">
                <?php foreach (['lost' => $lostReport, 'found' => $foundReport] as $type => $report): ?>
                    <?php
                        $typeLabel = ucfirst($type);
                        $summary = preg_replace('/\s+/u', ' ', trim($report['description'])) ?? '';
                        if (preg_match('/\A(.{240})./us', $summary, $preview) === 1) {
                            $summary = rtrim($preview[1]) . '…';
                        }
                    ?>
                    <article class="feed-post glass admin-match-report" aria-labelledby="<?= $type ?>-report-title">
                        <div class="feed-post-meta"><span class="badge <?= $type ?>"><?= $typeLabel ?> report</span></div>
                        <h2 id="<?= $type ?>-report-title"><?= claimHtml($report['title']) ?></h2>
                        <?php renderItemPhoto((string) $report['id'], $report['image_path'], $report['title']); ?>
                        <dl class="admin-match-facts">
                            <div><dt>Category</dt><dd><?= claimHtml($lostReport['category_name']) ?></dd></div>
                            <div><dt>Location</dt><dd><?= claimHtml($report['location']) ?></dd></div>
                            <div><dt>Occurrence date (Philippine time)</dt><dd><?= claimHtml(formatCampusOccurrence($report['occurred_at'])) ?></dd></div>
                        </dl>
                        <h3>Description</h3>
                        <p class="feed-preview"><?= $summary === '' ? 'No description provided.' : claimHtml($summary) ?></p>
                        <a class="button-secondary feed-details" href="item-details.php?id=<?= rawurlencode((string) $report['id']) ?>">View <?= $typeLabel ?> report details</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
