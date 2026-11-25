<?php

require __DIR__ . '/../includes/admin-matches.php';
requireAdminMatchRead();
$pdo = appDatabase();

$searchInput = $_GET['q'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$requestedPage = $_GET['page'] ?? '1';
$selectedId = $_GET['lost_id'] ?? null;
$search = '';
$categories = [];
$reports = [];
$lostReport = null;
$suggestions = [];
$selectionError = '';
$error = '';
$total = 0;
$pageNumber = 1;
$totalPages = 1;
$perPage = 12;

try {

    if (!is_string($searchInput) || preg_match('/\A.{0,120}\z/us', $searchInput) !== 1
        || ($categoryFilter !== '' && !claimIdIsValid($categoryFilter))
        || !claimIdIsValid($requestedPage)
    ) {
        http_response_code(400);
        $error = 'Choose a valid search, category, and page.';
    } else {
        $search = trim($searchInput);
        $categories = $pdo->query("SELECT c.id, c.name FROM categories c
            WHERE EXISTS (SELECT 1 FROM items i WHERE i.category_id = c.id
            AND i.item_type = 'lost' AND i.status = 'open') ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

        $from = " FROM items i INNER JOIN categories c ON c.id = i.category_id
            WHERE i.item_type = 'lost' AND i.status = 'open'";
        $params = [];
        if ($search !== '') {
            $from .= " AND (i.title LIKE :title_search ESCAPE '!' OR i.location LIKE :location_search ESCAPE '!')";
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $params = ['title_search' => $pattern, 'location_search' => $pattern];
        }
        if ($categoryFilter !== '') {
            $from .= ' AND i.category_id = :category';
            $params['category'] = $categoryFilter;
        }

        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totalPages = intdiv(max(0, $total - 1), $perPage) + 1;
        $pageNumber = min((int) $requestedPage, $totalPages);

        $list = $pdo->prepare('SELECT i.id, i.title, i.location, c.name AS category_name' . $from
            . ' ORDER BY i.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $list->bindValue(':' . $key, $value);
        }
        $list->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $list->bindValue(':offset', ($pageNumber - 1) * $perPage, PDO::PARAM_INT);
        $list->execute();
        $reports = $list->fetchAll(PDO::FETCH_ASSOC);

        if ($selectedId !== null) {
            $lostReport = claimIdIsValid($selectedId) ? loadOpenLostMatchReport($pdo, $selectedId) : null;
            if ($lostReport === null) {
                http_response_code(404);
                $selectionError = 'This Lost report is unavailable for match review.';
            } else {
                $suggestions = rankOpenLostReportMatches($pdo, $lostReport);
            }
        }
    }
} catch (PDOException $exception) {
    error_log('FindIT administrator matches dashboard failed.');
    http_response_code(500);
    $error = 'Reports could not be loaded. Please try again later.';
}

$listQuery = ['q' => $search, 'category' => $categoryFilter];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator Matches | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-reports">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="admin-matches.php" aria-current="page">Matches</a>
            <a href="admin-claims.php">Manage Claims</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <div class="browse-feed admin-matches">
        <header class="feed-heading">
            <div>
                <h1>Administrator Matches</h1>
                <p class="intro">Select an open Lost report to see possible Found matches.</p>
            </div>
        </header>
        <form class="admin-claim-filter glass" method="get" action="admin-matches.php" role="search" aria-label="Lost reports">
            <div>
                <label for="q">Search by title or location</label>
                <input type="search" id="q" name="q" maxlength="120" value="<?= claimHtml($search) ?>">
            </div>
            <div>
                <label for="category">Category</label>
                <div class="feed-select">
                    <select id="category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= claimHtml((string) $category['id']) ?>" <?= (string) $category['id'] === $categoryFilter ? 'selected' : '' ?>><?= claimHtml($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Search</button>
        </form>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= claimHtml($error) ?></p>
        <?php else: ?>
            <?php if ($selectionError !== ''): ?>
                <p id="selected-report" class="error" role="alert"><?= claimHtml($selectionError) ?></p>
            <?php elseif ($lostReport !== null): ?>
                <section id="selected-report" class="feed-post glass admin-match-selection" aria-labelledby="match-selection-heading" tabindex="-1">
                    <div class="feed-post-meta"><span class="badge lost">Lost report</span></div>
                    <h2 id="match-selection-heading"><?= claimHtml($lostReport['title']) ?></h2>
                    <p class="match-meta"><?= claimHtml($lostReport['category_name']) ?> &middot; <?= claimHtml($lostReport['location']) ?></p>
                    <a href="item-details.php?id=<?= rawurlencode($selectedId) ?>">View Lost report details</a>
                    <h3>Possible Found matches</h3>
                    <?php if ($suggestions === []): ?>
                        <p class="match-meta">There are no possible matches for this Lost report right now.</p>
                    <?php else: ?>
                        <div class="admin-match-suggestions">
                            <?php foreach ($suggestions as $suggestion): ?>
                                <article>
                                    <h4><?= claimHtml($suggestion['title']) ?></h4>
                                    <p class="match-meta"><?= claimHtml($lostReport['category_name']) ?> &middot; <?= claimHtml($suggestion['location']) ?></p>
                                    <?php renderAdminMatchReasons($suggestion['reasons']); ?>
                                    <a class="button-secondary feed-details" href="admin-match-review.php?lost_id=<?= rawurlencode($selectedId) ?>&amp;found_id=<?= rawurlencode($suggestion['id']) ?>">Compare reports<span class="admin-match-sr-only"> for <?= claimHtml($suggestion['title']) ?></span></a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($reports === []): ?>
                <section class="empty glass">
                    <h2>No open Lost reports to show</h2>
                    <?php if ($search !== '' || $categoryFilter !== ''): ?>
                        <p>Try a different search or category.</p>
                        <a class="button-secondary" href="admin-matches.php">Clear filters</a>
                    <?php else: ?>
                        <p>Open Lost reports will appear here when students submit them.</p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <div class="feed-summary">
                    <h2 id="lost-reports-heading">Open Lost reports</h2>
                    <span><?= $total ?> <?= $total === 1 ? 'report' : 'reports' ?></span>
                </div>
                <section class="feed-posts" aria-labelledby="lost-reports-heading">
                    <?php foreach ($reports as $report): ?>
                        <?php $reportId = (string) $report['id']; ?>
                        <article class="feed-post glass admin-match-listing">
                            <div>
                                <h3><?= claimHtml($report['title']) ?></h3>
                                <p class="match-meta"><?= claimHtml($report['category_name']) ?> &middot; <?= claimHtml($report['location']) ?></p>
                            </div>
                            <a class="button-secondary feed-details" href="admin-matches.php?<?= claimHtml(http_build_query($listQuery + ['page' => $pageNumber, 'lost_id' => $reportId])) ?>#selected-report">View potential matches<span class="admin-match-sr-only"> for <?= claimHtml($report['title']) ?></span></a>
                        </article>
                    <?php endforeach; ?>
                </section>
                <?php if ($totalPages > 1): ?>
                    <nav class="pagination glass" aria-label="Lost report pages">
                        <?php if ($pageNumber > 1): ?>
                            <a class="button-secondary" href="admin-matches.php?<?= claimHtml(http_build_query($listQuery + ['page' => $pageNumber - 1])) ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= $pageNumber ?> of <?= $totalPages ?></span>
                        <?php if ($pageNumber < $totalPages): ?>
                            <a class="button-secondary" href="admin-matches.php?<?= claimHtml(http_build_query($listQuery + ['page' => $pageNumber + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
