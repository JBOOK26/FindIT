<?php

require __DIR__ . '/../includes/admin-claims.php';
requireAdminClaimSession();
$pdo = appDatabase();

$filter = $_GET['status'] ?? 'pending';
$requestedPage = $_GET['page'] ?? '1';
$claims = [];
$total = 0;
$pageNumber = 1;
$totalPages = 1;
$perPage = 12;
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        exit;
    }
    if (!is_string($filter) || ($filter !== 'all' && !isset(ADMIN_CLAIM_STATUSES[$filter]))
        || !claimIdIsValid($requestedPage)
    ) {
        http_response_code(400);
        $error = 'Choose a valid claim status and page.';
        $filter = 'pending';
    } else {
        $where = $filter === 'all' ? '' : ' WHERE c.status = :status';
        $filters = $filter === 'all' ? [] : ['status' => $filter];
        $count = $pdo->prepare('SELECT COUNT(*) FROM claims c' . $where);
        $count->execute($filters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $pageNumber = min((int) $requestedPage, $totalPages);

        $list = $pdo->prepare('SELECT c.id, c.status, c.created_at, i.title, i.item_type, i.status AS item_status
            FROM claims c INNER JOIN items i ON i.id = c.item_id' . $where .
            ' ORDER BY c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset');
        if ($filter !== 'all') {
            $list->bindValue(':status', $filter);
        }
        $list->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $list->bindValue(':offset', ($pageNumber - 1) * $perPage, PDO::PARAM_INT);
        $list->execute();
        $claims = $list->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('FindIT administrator claim queue failed.');
    http_response_code(500);
    $error = 'Claims could not be loaded. Please try again later.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Claims | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-reports">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="admin-claims.php" aria-current="page">Manage Claims</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <div class="browse-feed">
        <div class="feed-heading">
            <div>
                <h1>Manage Claims</h1>
                <p class="intro">Review requests and record when found items are returned.</p>
            </div>
        </div>
        <form class="admin-claim-filter glass" method="get" action="admin-claims.php">
            <div>
                <label for="status">Claim status</label>
                <div class="feed-select">
                    <select id="status" name="status">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All statuses</option>
                        <?php foreach (ADMIN_CLAIM_STATUSES as $value => $label): ?>
                            <option value="<?= claimHtml($value) ?>" <?= $filter === $value ? 'selected' : '' ?>><?= claimHtml($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Apply filter</button>
        </form>
        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= claimHtml($error) ?></p>
        <?php elseif ($claims === []): ?>
            <section class="empty glass">
                <h2>No claims to show</h2>
                <p><?= $filter === 'all' ? 'Claim requests will appear here when students submit them.' : 'Try another status to view other requests.' ?></p>
                <?php if ($filter !== 'all'): ?>
                    <a class="button-secondary" href="admin-claims.php?status=all">Show all claims</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="feed-summary">
                <h2><?= claimHtml($filter === 'all' ? 'All claims' : ADMIN_CLAIM_STATUSES[$filter]) ?></h2>
                <span><?= $total ?> <?= $total === 1 ? 'claim' : 'claims' ?></span>
            </div>
            <section class="feed-posts" aria-label="Claims">
                <?php foreach ($claims as $claim): ?>
                    <article class="feed-post glass">
                        <div class="feed-post-meta">
                            <span class="badge claim-status" data-status="<?= claimHtml($claim['status']) ?>"><?= claimHtml(ADMIN_CLAIM_STATUSES[$claim['status']]) ?></span>
                            <span class="feed-date">Submitted <?= claimHtml(date('M j, Y', strtotime($claim['created_at']))) ?></span>
                        </div>
                        <h3><?= claimHtml($claim['title']) ?></h3>
                        <footer class="feed-post-footer claim-post-footer">
                            <p class="admin-item-state"><?= claimHtml(ucfirst($claim['item_type'])) ?> report · <?= claimHtml(ucfirst($claim['item_status'])) ?></p>
                            <a class="button-secondary feed-details" href="admin-claim-details.php?id=<?= rawurlencode((string) $claim['id']) ?>">Review claim</a>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination glass" aria-label="Claims pages">
                    <?php if ($pageNumber > 1): ?>
                        <a class="button-secondary" href="admin-claims.php?<?= claimHtml(http_build_query(['status' => $filter, 'page' => $pageNumber - 1])) ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $pageNumber ?> of <?= $totalPages ?></span>
                    <?php if ($pageNumber < $totalPages): ?>
                        <a class="button-secondary" href="admin-claims.php?<?= claimHtml(http_build_query(['status' => $filter, 'page' => $pageNumber + 1])) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
