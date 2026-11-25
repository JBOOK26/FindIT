<?php

require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();

if ($user['role'] !== 'student') {
    denyFindItAccess('Only student accounts can report items.');
}

$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';

$errors = [];
$values = [
    'item_type' => '',
    'category_id' => '',
    'title' => '',
    'description' => '',
    'location' => '',
    'occurred_at' => '',
];
$reported = isset($_GET['reported']) && $_GET['reported'] === '1';
$categories = [];
$categoryLoadFailed = false;

try {
    $categoryQuery = $pdo->query(
        'SELECT id, name
         FROM categories
         WHERE is_active = 1
         ORDER BY name'
    );
    $categories = $categoryQuery->fetchAll();
} catch (PDOException $exception) {
    error_log('FindIT active category lookup failed.');
    $categoryLoadFailed = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $unusedValue) {
        $submittedValue = $_POST[$field] ?? '';
        $values[$field] = is_string($submittedValue) ? trim($submittedValue) : '';
    }

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = $_POST === [] && $_FILES === []
            ? 'The form could not be received. Choose a photo up to 5 MiB and try again.'
            : 'Your form session has expired. Please reload the page and try again.';
    }

    if (!in_array($values['item_type'], ['lost', 'found'], true)) {
        $errors[] = 'Choose whether the item was lost or found.';
    }

    $categoryIsActive = false;
    foreach ($categories as $category) {
        if ($values['category_id'] !== ''
            && ctype_digit($values['category_id'])
            && (int) $category['id'] === (int) $values['category_id']
        ) {
            $categoryIsActive = true;
            break;
        }
    }

    if (!$categoryIsActive) {
        $errors[] = 'Choose an available category.';
    }

    $titleLength = preg_match_all('/./us', $values['title']);
    if ($values['title'] === '') {
        $errors[] = 'Item title is required.';
    } elseif ($titleLength === false) {
        $errors[] = 'Item title must contain valid text.';
    } elseif ($titleLength > 150) {
        $errors[] = 'Item title must be 150 characters or fewer.';
    }

    $descriptionLength = preg_match_all('/./us', $values['description']);
    if ($values['description'] === '') {
        $errors[] = 'Description is required.';
    } elseif ($descriptionLength === false) {
        $errors[] = 'Description must contain valid text.';
    } elseif (strlen($values['description']) > 65535) {
        $errors[] = 'Description is too long.';
    }

    $locationLength = preg_match_all('/./us', $values['location']);
    if ($values['location'] === '') {
        $errors[] = 'Location is required.';
    } elseif ($locationLength === false) {
        $errors[] = 'Location must contain valid text.';
    } elseif ($locationLength > 255) {
        $errors[] = 'Location must be 255 characters or fewer.';
    }

    $occurredAt = null;
    $date = parseCampusDate('Y-m-d\TH:i', $values['occurred_at']);
    if (!is_string($_POST['occurred_at'] ?? '') || ($values['occurred_at'] !== '' && $date === null)) {
        $errors[] = 'Enter a valid date and time in Philippine time.';
    } else {
        $occurredAt = $date?->format('Y-m-d H:i:s');
    }

    if ($categoryLoadFailed) {
        $errors[] = 'Categories are temporarily unavailable. Please try again later.';
    } elseif ($categories === []) {
        $errors[] = 'Reporting is unavailable because no active categories have been set up yet.';
    }

    if ($errors === []) {
        $imagePath = null;
        try {
            $imagePath = saveItemPhoto($_FILES['item_photo'] ?? null);
            $insertItem = $pdo->prepare(
                'INSERT INTO items
                    (reporter_id, category_id, item_type, title, description, location, occurred_at, image_path)
                 VALUES
                    (:reporter_id, :category_id, :item_type, :title, :description, :location, :occurred_at, :image_path)'
            );
            $insertItem->execute([
                'reporter_id' => $user['id'],
                'category_id' => (int) $values['category_id'],
                'item_type' => $values['item_type'],
                'title' => $values['title'],
                'description' => $values['description'],
                'location' => $values['location'],
                'occurred_at' => $occurredAt,
                'image_path' => $imagePath,
            ]);

            header('Location: report-item.php?reported=1', true, 303);
            exit;
        } catch (ItemPhotoException $exception) {
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            removeUnreferencedItemPhoto($pdo, $imagePath);
            error_log('FindIT item report insert failed.');
            $errors[] = 'Your report could not be submitted right now. Please try again later.';
        }
    }
}

function escapeReportItemHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrfToken = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report an Item | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
    <script src="../assets/js/item-photo-preview.js" defer></script>
</head>
<body class="page-form">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="report-item.php" aria-current="page">Report an Item</a>
            <a href="my-reports.php">My Reports</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <section class="content-panel glass">
    <h1>Report an item</h1>
    <p class="intro">Share details about an item you lost or found on campus.</p>

    <?php if ($reported): ?>
        <p class="notice" role="status">Your item report was submitted successfully.</p>
    <?php endif; ?>

    <?php if ($categoryLoadFailed): ?>
        <p class="empty-categories" role="alert">
            Categories are temporarily unavailable. Please try again later.
        </p>
    <?php elseif ($categories === []): ?>
        <p class="empty-categories" role="status">
            Item reporting requires an active category. No active categories are available yet.
            Please contact the FindIT administrator.
        </p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="errors" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escapeReportItemHtml($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form class="report-form" method="post" action="report-item.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escapeReportItemHtml($csrfToken) ?>">

        <div class="field">
            <label for="item_type">Item type</label>
            <select id="item_type" name="item_type" required>
                <option value="">Choose lost or found</option>
                <option value="lost" <?= $values['item_type'] === 'lost' ? 'selected' : '' ?>>Lost</option>
                <option value="found" <?= $values['item_type'] === 'found' ? 'selected' : '' ?>>Found</option>
            </select>
        </div>

        <div class="field">
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id" required <?= $categories === [] ? 'disabled' : '' ?>>
                <option value="">Choose a category</option>
                <?php foreach ($categories as $category): ?>
                    <?php $categoryId = (string) $category['id']; ?>
                    <option value="<?= escapeReportItemHtml($categoryId) ?>"
                        <?= $values['category_id'] === $categoryId ? 'selected' : '' ?>>
                        <?= escapeReportItemHtml($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="title">Item title</label>
            <input id="title" name="title" type="text" maxlength="150" required
                   value="<?= escapeReportItemHtml($values['title']) ?>">
        </div>

        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" required><?= escapeReportItemHtml($values['description']) ?></textarea>
        </div>

        <div class="field">
            <label for="location">Location</label>
            <input id="location" name="location" type="text" maxlength="255" required
                   value="<?= escapeReportItemHtml($values['location']) ?>">
        </div>

        <div class="field">
            <label for="occurred_at">Date and time in Philippine time <span>(optional)</span></label>
            <input id="occurred_at" name="occurred_at" type="datetime-local"
                   value="<?= escapeReportItemHtml($values['occurred_at']) ?>">
        </div>

        <?php renderItemPhotoInput(); ?>
        <div class="actions">
            <button type="submit" <?= $categories === [] || $categoryLoadFailed ? 'disabled' : '' ?>>
                Submit report
            </button>
            <a class="secondary-link" href="browse-items.php">Browse Items</a>
            <a class="secondary-link" href="account.php">Back to account</a>
        </div>
    </form>
    </section>
</main>
</body>
</html>
