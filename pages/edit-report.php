<?php
require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();
if ($user['role'] !== 'student') {
    denyFindItAccess('Only student accounts can manage reports.');
}
$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';

$id = $_GET['id'] ?? '';
$validId = is_string($id) && preg_match('/\A[1-9][0-9]{0,19}\z/', $id) === 1
    && (strlen($id) < 20 || strcmp($id, '18446744073709551615') <= 0);
$report = null;
$categories = [];
$errors = [];
$databaseError = false;
$values = ['item_type' => '', 'category_id' => '', 'title' => '', 'description' => '', 'location' => '', 'occurred_at' => ''];
if ($validId) {
    try {
        $statement = $pdo->prepare("SELECT i.*, EXISTS(SELECT 1 FROM claims cl WHERE cl.item_id = i.id) AS has_claims
            FROM items i WHERE i.id = :id AND i.reporter_id = :owner LIMIT 1");
        $statement->execute(['id' => $id, 'owner' => $user['id']]);
        $report = $statement->fetch() ?: null;
        if ($report !== null) {
            $categories = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
            foreach ($values as $field => $unused) { $values[$field] = (string) ($report[$field] ?? ''); }
            $values['occurred_at'] = campusOccurrenceInput($report['occurred_at']);
        }
    } catch (PDOException $exception) {
        error_log('FindIT edit report lookup failed.');
        $databaseError = true;
    }
}
$eligible = $report !== null && $report['status'] === 'open' && !(bool) $report['has_claims'];
if ($validId && $report !== null && !$databaseError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $unused) {
        $submitted = $_POST[$field] ?? '';
        $values[$field] = is_string($submitted) ? trim($submitted) : '';
    }
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = $_POST === [] && $_FILES === []
            ? 'The form could not be received. Choose a photo up to 5 MiB and try again.'
            : 'Your form session has expired. Please reload the page and try again.';
    }
    if (!$eligible) { $errors[] = 'Only open reports without claims can be edited.'; }
    if (!in_array($values['item_type'], ['lost', 'found'], true)) { $errors[] = 'Choose whether the item was lost or found.'; }
    $categoryIsActive = false;
    foreach ($categories as $category) {
        if ($values['category_id'] !== '' && ctype_digit($values['category_id']) && (int) $category['id'] === (int) $values['category_id']) { $categoryIsActive = true; break; }
    }
    if (!$categoryIsActive) { $errors[] = 'Choose an available category.'; }
    $titleLength = preg_match_all('/./us', $values['title']);
    if ($values['title'] === '') { $errors[] = 'Item title is required.'; }
    elseif ($titleLength === false) { $errors[] = 'Item title must contain valid text.'; }
    elseif ($titleLength > 150) { $errors[] = 'Item title must be 150 characters or fewer.'; }
    $descriptionLength = preg_match_all('/./us', $values['description']);
    if ($values['description'] === '') { $errors[] = 'Description is required.'; }
    elseif ($descriptionLength === false) { $errors[] = 'Description must contain valid text.'; }
    elseif (strlen($values['description']) > 65535) { $errors[] = 'Description is too long.'; }
    $locationLength = preg_match_all('/./us', $values['location']);
    if ($values['location'] === '') { $errors[] = 'Location is required.'; }
    elseif ($locationLength === false) { $errors[] = 'Location must contain valid text.'; }
    elseif ($locationLength > 255) { $errors[] = 'Location must be 255 characters or fewer.'; }
    $occurredAt = null;
    $date = parseCampusDate('Y-m-d\TH:i', $values['occurred_at']);
    if (!is_string($_POST['occurred_at'] ?? '') || ($values['occurred_at'] !== '' && $date === null)) {
        $errors[] = 'Enter a valid date and time in Philippine time.';
    } else {
        $occurredAt = $date?->format('Y-m-d H:i:s');
    }
    if ($categories === []) { $errors[] = 'No active categories are available. Please try again later.'; }
    if ($errors === []) {
        $replacementPath = null;
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT i.image_path, i.status, i.occurred_at,
                EXISTS(SELECT 1 FROM claims cl WHERE cl.item_id = i.id) AS has_claims
                FROM items i WHERE i.id = :id AND i.reporter_id = :owner FOR UPDATE");
            $lock->execute(['id' => $id, 'owner' => $user['id']]);
            $lockedReport = $lock->fetch();
            if (!$lockedReport) {
                $pdo->rollBack();
                $report = null;
            } elseif ($lockedReport['status'] !== 'open' || (bool) $lockedReport['has_claims']) {
                $pdo->rollBack();
                http_response_code(409);
                $eligible = false;
                $errors[] = 'This report can no longer be edited.';
            } else {
                $categoryLock = $pdo->prepare('SELECT id FROM categories WHERE id = :category AND is_active = 1 FOR UPDATE');
                $categoryLock->execute(['category' => (int) $values['category_id']]);
                if (!$categoryLock->fetch()) {
                    $pdo->rollBack();
                    $errors[] = 'Choose an available category.';
                } else {
                    // Keep the stored wall time, including seconds, when the date field is unchanged.
                    if ($values['occurred_at'] === campusOccurrenceInput($lockedReport['occurred_at'])) {
                        $occurredAt = $lockedReport['occurred_at'];
                    }
                    $replacementPath = saveItemPhoto($_FILES['item_photo'] ?? null);
                    $update = $pdo->prepare("UPDATE items SET item_type = :item_type, category_id = :category_id,
                        title = :title, description = :description, location = :location, occurred_at = :occurred_at,
                        image_path = COALESCE(:image_path, image_path)
                        WHERE id = :id AND reporter_id = :owner AND status = 'open'
                        AND NOT EXISTS (SELECT 1 FROM claims WHERE claims.item_id = items.id)");
                    $update->execute(['item_type' => $values['item_type'], 'category_id' => (int) $values['category_id'],
                        'title' => $values['title'], 'description' => $values['description'], 'location' => $values['location'],
                        'occurred_at' => $occurredAt, 'image_path' => $replacementPath, 'id' => $id, 'owner' => $user['id']]);
                    // MySQL may report zero affected rows when an eligible report was unchanged.
                    $check = $pdo->prepare("SELECT id FROM items WHERE id = :id AND reporter_id = :owner AND status = 'open'
                        AND NOT EXISTS (SELECT 1 FROM claims WHERE claims.item_id = items.id)");
                    $check->execute(['id' => $id, 'owner' => $user['id']]);
                    if (!$check->fetch()) {
                        $pdo->rollBack();
                        removeUnreferencedItemPhoto($pdo, $replacementPath);
                        http_response_code(409);
                        $eligible = false;
                        $errors[] = 'This report can no longer be edited.';
                    } else {
                        $pdo->commit();
                        if ($replacementPath !== null) {
                            removeUnreferencedItemPhoto($pdo, $lockedReport['image_path']);
                        }
                        header('Location: my-report-details.php?id=' . rawurlencode($id) . '&updated=1', true, 303);
                        exit;
                    }
                }
            }
        } catch (ItemPhotoException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            removeUnreferencedItemPhoto($pdo, $replacementPath);
            error_log('FindIT report edit failed.');
            $errors[] = 'Your report could not be updated right now. Please try again later.';
            http_response_code(500);
        }
    }
} elseif ($validId && $report !== null && !$databaseError && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); header('Allow: GET, POST'); $errors[] = 'The requested method is not supported.';
}
if ($databaseError) { http_response_code(500); }
elseif (!$validId || $report === null) { http_response_code(404); }
function escapeEditReport(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Report | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
    <script src="../assets/js/item-photo-preview.js" defer></script>
</head>
<body class="page-form">
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
    <section class="content-panel glass">
<?php if ($databaseError): ?><h1>Report unavailable</h1><p role="alert">Your report could not be loaded right now. Please try again later.</p>
<?php elseif ($report === null): ?><h1>Report unavailable</h1><p>This report could not be found.</p>
<?php else: ?><h1>Edit report</h1><p class="intro">Update your report details. Only open reports without claims can be edited.</p>
<?php if ($errors !== []): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= escapeEditReport($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$eligible): ?><p>This report cannot be edited.</p><?php else: ?>
<form class="report-form" method="post" action="edit-report.php?id=<?= escapeEditReport(rawurlencode($id)) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= escapeEditReport(getCsrfToken()) ?>">
<div class="field"><label for="item_type">Item type</label><select id="item_type" name="item_type" required><option value="">Choose lost or found</option><option value="lost" <?= $values['item_type'] === 'lost' ? 'selected' : '' ?>>Lost</option><option value="found" <?= $values['item_type'] === 'found' ? 'selected' : '' ?>>Found</option></select></div>
<div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id" required><option value="">Choose an active category</option><?php foreach ($categories as $category): ?><option value="<?= escapeEditReport((string) $category['id']) ?>" <?= $values['category_id'] === (string) $category['id'] ? 'selected' : '' ?>><?= escapeEditReport($category['name']) ?></option><?php endforeach; ?></select><small>If your previous category is inactive, choose an active category.</small></div>
<div class="field"><label for="title">Item title</label><input id="title" name="title" maxlength="150" required value="<?= escapeEditReport($values['title']) ?>"></div>
<div class="field"><label for="description">Description</label><textarea id="description" name="description" required><?= escapeEditReport($values['description']) ?></textarea></div>
<div class="field"><label for="location">Location</label><input id="location" name="location" maxlength="255" required value="<?= escapeEditReport($values['location']) ?>"></div>
<div class="field"><label for="occurred_at">Date and time in Philippine time (optional)</label><input id="occurred_at" name="occurred_at" type="datetime-local" value="<?= escapeEditReport($values['occurred_at']) ?>"></div>
<div class="saved-photo">
    <h2>Current photo</h2>
    <?php renderItemPhoto($id, $report['image_path'], $report['title'], false); ?>
    <small>Leave the photo field empty to keep the current photo.</small>
</div>
<?php renderItemPhotoInput(true); ?>
<div class="actions"><button type="submit" <?= $categories === [] ? 'disabled' : '' ?>>Save changes</button><a href="my-report-details.php?id=<?= escapeEditReport(rawurlencode($id)) ?>">Back to report</a></div></form><?php endif; ?><?php endif; ?>
    <p class="back-link"><a href="my-reports.php">Back to My Reports</a></p>
    </section>
</main>
</body>
</html>
