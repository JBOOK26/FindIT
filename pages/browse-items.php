<?php

require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();

$pdo = appDatabase();
require __DIR__ . '/../includes/item-photos.php';

$itemsPerPage = 12;
$maximumPage = 1000000;
$searchTerm = '';
$itemType = 'all';
$categoryId = '';
$requestedPage = 1;
$queryError = '';
$databaseError = false;
$categories = [];
$items = [];
$totalItems = 0;
$totalPages = 1;

$submittedSearch = $_GET['q'] ?? '';
if (!is_string($submittedSearch)) {
    $queryError = 'The search value is invalid.';
} else {
    $searchTerm = trim($submittedSearch);
    $searchLength = preg_match_all('/./us', $searchTerm);
    if ($searchLength === false || $searchLength > 100) {
        $queryError = 'Search must be 100 characters or fewer.';
    }
}

$submittedType = $_GET['type'] ?? 'all';
if (!is_string($submittedType) || !in_array($submittedType, ['all', 'lost', 'found'], true)) {
    $queryError = 'Choose a valid item type filter.';
} else {
    $itemType = $submittedType;
}

$submittedCategory = $_GET['category'] ?? '';
if (!is_string($submittedCategory)) {
    $queryError = 'The category filter is invalid.';
} elseif ($submittedCategory !== '') {
    $validatedCategory = filter_var(
        $submittedCategory,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 4294967295]]
    );
    if ($validatedCategory === false) {
        $queryError = 'Choose a valid category filter.';
    } else {
        $categoryId = (string) $validatedCategory;
    }
}

$submittedPage = $_GET['page'] ?? '1';
if (
    !is_string($submittedPage)
    || preg_match('/\A[1-9][0-9]{0,6}\z/', $submittedPage) !== 1
    || (int) $submittedPage > $maximumPage
) {
    $queryError = 'The requested page number is invalid.';
} else {
    $requestedPage = (int) $submittedPage;
}

try {
    $categoryQuery = $pdo->query(
        'SELECT DISTINCT c.id, c.name
         FROM categories c
         INNER JOIN items i ON i.category_id = c.id
         WHERE i.status <> \'cancelled\'
         ORDER BY c.name'
    );
    $categories = $categoryQuery->fetchAll();

    if ($queryError === '' && $categoryId !== '') {
        $categoryIsAvailable = false;
        foreach ($categories as $category) {
            if ((string) $category['id'] === $categoryId) {
                $categoryIsAvailable = true;
                break;
            }
        }

        if (!$categoryIsAvailable) {
            $queryError = 'Choose a category that has browsable reports.';
        }
    }

    if ($queryError === '') {
        $conditions = ['i.status <> \'cancelled\''];
        $queryParameters = [];

        if ($itemType !== 'all') {
            $conditions[] = 'i.item_type = :item_type';
            $queryParameters['item_type'] = $itemType;
        }

        if ($categoryId !== '') {
            $conditions[] = 'i.category_id = :category_id';
            $queryParameters['category_id'] = (int) $categoryId;
        }

        if ($searchTerm !== '') {
            $escapedSearch = strtr($searchTerm, [
                '!' => '!!',
                '%' => '!%',
                '_' => '!_',
            ]);
            $searchPattern = '%' . $escapedSearch . '%';
            $conditions[] = '(i.title LIKE :search_title ESCAPE \'!\''
                . ' OR i.description LIKE :search_description ESCAPE \'!\')';
            $queryParameters['search_title'] = $searchPattern;
            $queryParameters['search_description'] = $searchPattern;
        }

        $whereClause = implode(' AND ', $conditions);
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM items i
             WHERE ' . $whereClause
        );
        $countStatement->execute($queryParameters);
        $totalItems = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalItems / $itemsPerPage));
        $currentPage = min($requestedPage, $totalPages);
        $offset = ($currentPage - 1) * $itemsPerPage;

        $itemsStatement = $pdo->prepare(
            'SELECT i.id, i.item_type, i.title, i.location, i.status, i.created_at,
                    i.description, i.image_path,
                    c.name AS category_name
             FROM items i
             INNER JOIN categories c ON c.id = i.category_id
             WHERE ' . $whereClause . '
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($queryParameters as $parameterName => $parameterValue) {
            $itemsStatement->bindValue(':' . $parameterName, $parameterValue);
        }
        $itemsStatement->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
        $itemsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $itemsStatement->execute();
        $items = $itemsStatement->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('FindIT item browse query failed.');
    $databaseError = true;
}

if ($queryError !== '') {
    http_response_code(400);
} elseif ($databaseError) {
    http_response_code(500);
}

function escapeBrowseItemsHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function browseItemsDescriptionPreview(string $description): string
{
    $description = trim($description);
    $description = preg_replace('/\s+/u', ' ', $description) ?? $description;
    if (preg_match('/\A(.{180})./us', $description, $matches) === 1) {
        return rtrim($matches[1]) . '…';
    }

    return $description;
}

function browseItemsPageUrl(int $page, string $search, string $type, string $category): string
{
    $parameters = ['page' => $page];
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    if ($type !== 'all') {
        $parameters['type'] = $type;
    }
    if ($category !== '') {
        $parameters['category'] = $category;
    }

    return 'browse-items.php?' . http_build_query($parameters);
}

$currentPage = $queryError === '' ? min($requestedPage, $totalPages) : 1;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browse Items | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-browse">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="browse-items.php" aria-current="page">Browse Items</a>
            <a href="account.php">Account</a>
            <?php if ($user['role'] === 'student'): ?>
                <a href="report-item.php">Report an Item</a>
            <?php endif; ?>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>

    <div class="browse-feed">
        <div class="feed-heading">
            <div>
                <h1>Campus lost &amp; found</h1>
                <p class="intro">Browse recent reports from across campus.</p>
            </div>
            <?php if ($user['role'] === 'student'): ?>
                <a class="button-secondary feed-report-shortcut" href="report-item.php">Report an item</a>
            <?php endif; ?>
        </div>

        <section class="feed-filter-bar glass" aria-label="Search and filters">
            <form class="feed-filters" role="search" method="get" action="browse-items.php">
                <div class="feed-search">
                    <label for="q">Search reports</label>
                    <div class="search-field">
                        <span class="icon-search" aria-hidden="true"></span>
                        <input id="q" name="q" type="search" maxlength="100"
                               value="<?= escapeBrowseItemsHtml($searchTerm) ?>"
                               placeholder="e.g. blue bottle">
                    </div>
                </div>
                <fieldset class="feed-type">
                    <legend>Item type</legend>
                    <div class="feed-segments">
                        <?php foreach (['all' => 'All', 'lost' => 'Lost', 'found' => 'Found'] as $typeValue => $typeName): ?>
                            <label>
                                <input type="radio" name="type" value="<?= $typeValue ?>" <?= $itemType === $typeValue ? 'checked' : '' ?>>
                                <span><?= $typeName ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div>
                    <label for="category">Category</label>
                    <div class="feed-select">
                        <select id="category" name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <?php $availableCategoryId = (string) $category['id']; ?>
                                <option value="<?= escapeBrowseItemsHtml($availableCategoryId) ?>"
                                    <?= $categoryId === $availableCategoryId ? 'selected' : '' ?>>
                                    <?= escapeBrowseItemsHtml($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit">Search</button>
                <a class="feed-clear" href="browse-items.php">Clear filters</a>
            </form>
        </section>

        <?php if ($queryError !== ''): ?>
            <p class="error" role="alert"><?= escapeBrowseItemsHtml($queryError) ?></p>
        <?php elseif ($databaseError): ?>
            <p class="error" role="alert">Items are temporarily unavailable. Please try again later.</p>
        <?php else: ?>
            <div class="feed-summary">
                <h2>Recent reports</h2>
                <span><?= $totalItems ?> <?= $totalItems === 1 ? 'report' : 'reports' ?></span>
            </div>

            <?php if ($items === []): ?>
                <div class="empty glass" role="status">
                    <span class="shortcut-icon icon-search" aria-hidden="true"></span>
                    <h2>No matching reports</h2>
                    <p>Try a different search or reset your filters.</p>
                    <a class="button-secondary" href="browse-items.php">Clear filters</a>
                </div>
            <?php else: ?>
                <section class="feed-posts" aria-label="Item reports">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $typeLabel = $item['item_type'] === 'lost' ? 'Lost' : 'Found';
                        $typeClass = $item['item_type'] === 'found' ? 'badge found' : 'badge';
                        $reportDate = date('M j, Y', strtotime((string) $item['created_at']));
                        $detailUrl = 'item-details.php?' . http_build_query(['id' => (string) $item['id']]);
                        $descriptionPreview = browseItemsDescriptionPreview($item['description']);
                        ?>
                        <article class="feed-post glass">
                            <div class="feed-post-meta">
                                <span class="<?= escapeBrowseItemsHtml($typeClass) ?>"><?= escapeBrowseItemsHtml($typeLabel) ?></span>
                                <span class="feed-category"><?= escapeBrowseItemsHtml($item['category_name']) ?></span>
                                <span class="feed-date"><?= escapeBrowseItemsHtml($reportDate) ?></span>
                            </div>
                            <p class="feed-location">Location: <?= escapeBrowseItemsHtml($item['location']) ?></p>
                            <h3><?= escapeBrowseItemsHtml($item['title']) ?></h3>
                            <?php renderItemPhoto((string) $item['id'], $item['image_path'], $item['title']); ?>

                            <?php if ($descriptionPreview !== ''): ?>
                                <p class="feed-preview"><?= escapeBrowseItemsHtml($descriptionPreview) ?></p>
                            <?php endif; ?>

                            <footer class="feed-post-footer">
                                <dl class="feed-facts">
                                    <div>
                                        <dt>Status</dt>
                                        <dd><span class="badge status"><?= escapeBrowseItemsHtml(ucfirst((string) $item['status'])) ?></span></dd>
                                    </div>
                                </dl>
                                <a class="button-secondary feed-details" href="<?= escapeBrowseItemsHtml($detailUrl) ?>"
                                   aria-label="<?= escapeBrowseItemsHtml('View details for ' . $item['title']) ?>">View details</a>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </section>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination glass" aria-label="Browse results pages">
                        <?php if ($currentPage > 1): ?>
                            <a class="button-secondary" href="<?= escapeBrowseItemsHtml(browseItemsPageUrl($currentPage - 1, $searchTerm, $itemType, $categoryId)) ?>">
                                Previous
                            </a>
                        <?php endif; ?>
                        <span>Page <?= $currentPage ?> of <?= $totalPages ?></span>
                        <?php if ($currentPage < $totalPages): ?>
                            <a class="button-secondary" href="<?= escapeBrowseItemsHtml(browseItemsPageUrl($currentPage + 1, $searchTerm, $itemType, $categoryId)) ?>">
                                Next
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
