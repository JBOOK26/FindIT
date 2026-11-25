<?php

require __DIR__ . '/../includes/auth.php';
$viewer = requireAuthenticatedUser();

$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';
require __DIR__ . '/../includes/claims.php';

$item = null;
$databaseError = false;
$submittedItemId = $_GET['id'] ?? '';
$itemIdIsValid = is_string($submittedItemId)
    && preg_match('/\A[1-9][0-9]{0,19}\z/', $submittedItemId) === 1
    && (
        strlen($submittedItemId) < 20
        || strcmp($submittedItemId, '18446744073709551615') <= 0
    );

if ($itemIdIsValid) {
    try {
        $itemStatement = $pdo->prepare(
            'SELECT i.reporter_id, i.item_type, i.title, i.description, i.location, i.status,
                    i.occurred_at, i.created_at, i.image_path, c.name AS category_name
             FROM items i
             INNER JOIN categories c ON c.id = i.category_id
             WHERE i.id = :item_id
               AND i.status <> \'cancelled\'
             LIMIT 1'
        );
        $itemStatement->execute(['item_id' => $submittedItemId]);
        $item = $itemStatement->fetch();
        if ($item === false) {
            $item = null;
        }
    } catch (PDOException $exception) {
        error_log('FindIT item details query failed.');
        $databaseError = true;
    }
}

if ($databaseError) {
    http_response_code(500);
} elseif (!$itemIdIsValid || $item === null) {
    http_response_code(404);
}

$claimAction = null;
$existingClaim = null;
if (FINDIT_STUDENT_CLAIMS_ENABLED && $item !== null && $viewer['role'] === 'student'
    && claimItemIsEligible($item, $viewer['id'])
) {
    try {
        $existingClaim = findStudentClaim($pdo, $submittedItemId, $viewer['id']);
        $claimAction = $existingClaim === null ? 'Claim item' : 'View my claim';
    } catch (PDOException $exception) {
        error_log('FindIT item claim action lookup failed.');
    }
}

function escapeItemDetailsHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $item === null ? 'Item Not Found' : 'Item Details' ?> | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-details">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="browse-items.php" aria-current="location">Browse Items</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>

    <article class="content-panel glass">
    <?php if ($databaseError): ?>
        <h1>Item details unavailable</h1>
        <p class="message" role="alert">This report could not be loaded right now. Please try again later.</p>
    <?php elseif ($item === null): ?>
        <h1>Item not found</h1>
        <p class="message" role="status">This report is unavailable or may no longer be browsable.</p>
    <?php else: ?>
        <?php $typeLabel = $item['item_type'] === 'lost' ? 'Lost' : 'Found'; ?>
        <?php $typeClass = $item['item_type'] === 'found' ? 'type found' : 'type'; ?>
        <h1><?= escapeItemDetailsHtml($item['title']) ?></h1>
        <span class="<?= escapeItemDetailsHtml($typeClass) ?>"><?= escapeItemDetailsHtml($typeLabel) ?></span>
        <div class="detail-photo">
            <?php renderItemPhoto($submittedItemId, $item['image_path'], $item['title'], false); ?>
        </div>

        <dl>
            <dt>Category</dt>
            <dd><?= escapeItemDetailsHtml($item['category_name']) ?></dd>
            <dt>Location</dt>
            <dd><?= escapeItemDetailsHtml($item['location']) ?></dd>
            <dt>Status</dt>
            <dd class="status"><?= escapeItemDetailsHtml(ucfirst((string) $item['status'])) ?></dd>
            <dt>Reported</dt>
            <dd><?= escapeItemDetailsHtml(date('M j, Y \a\t g:i A', strtotime((string) $item['created_at']))) ?></dd>
            <dt>Date and time (Philippine time)</dt>
            <dd><?= escapeItemDetailsHtml(formatCampusOccurrence($item['occurred_at'])) ?></dd>
        </dl>

        <h2>Description</h2>
        <section class="description" aria-label="Full item description"><?= escapeItemDetailsHtml($item['description']) ?></section>
        <?php if ($claimAction !== null): ?>
            <div class="actions">
                <a class="button-secondary feed-report-shortcut" href="<?= $existingClaim === null
                    ? 'claim-item.php?id=' . rawurlencode($submittedItemId)
                    : 'claim-details.php?id=' . rawurlencode((string) $existingClaim['id']) ?>"><?= escapeItemDetailsHtml($claimAction) ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="back-link"><a href="browse-items.php">Back to Browse Items</a></p>
    </article>
</main>
</body>
</html>
