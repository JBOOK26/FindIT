<?php

require_once __DIR__ . '/../includes/notification-ui.php';
requireAuthenticatedUser();
header('Cache-Control: private, no-store');
$pdo = appDatabase();

function notificationInboxUrl(string $view, int $page = 1): string
{
    return 'notifications.php?' . http_build_query(['view' => $view, 'page' => $page]);
}

$submittedView = $_GET['view'] ?? 'all';
$submittedPage = $_GET['page'] ?? '1';
$viewIsValid = in_array($submittedView, ['all', 'unread'], true);
$pageIsValid = is_string($submittedPage) && preg_match('/\A[1-9][0-9]{0,8}\z/', $submittedPage) === 1;
$view = $viewIsValid ? $submittedView : 'all';
$page = $pageIsValid ? (int) $submittedPage : 1;
$pageSize = 12;
$totalPages = 1;
$unread = null;
$csrfToken = '';
$snapshot = null;
$rows = [];
$feedback = null;
$error = '';
$activeViewer = false;
$isStudent = false;
$moreMatchReports = false;

try {
    $viewer = requireNotificationViewer($pdo);
    $activeViewer = true;
    $isStudent = $viewer['role'] === 'student';
    $method = $_SERVER['REQUEST_METHOD'];

    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        $error = 'This request is unavailable.';
    } elseif (!$viewIsValid || !$pageIsValid) {
        http_response_code(400);
        $error = 'This notification view is unavailable.';
    } elseif ($method === 'POST') {
        $action = $_POST['action'] ?? null;
        if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $error = 'Your form session expired. Open Notifications and try again.';
        } elseif ($action === 'mark-one' && claimIdIsValid($_POST['notification_id'] ?? null)) {
            $marked = markNotificationRead($pdo, $_POST['notification_id']);
            $feedback = $marked ? ['ok' => true, 'message' => 'Notification marked as read.']
                : ['ok' => false, 'message' => 'This notification is unavailable or has already been read.'];
        } elseif ($action === 'mark-all') {
            try {
                $marked = markNotificationSnapshotRead($pdo, $_POST['snapshot'] ?? null);
                $feedback = ['ok' => true, 'message' => match ($marked) {
                    0 => 'There were no unread notifications in this view to update.',
                    1 => 'One notification marked as read.',
                    default => $marked . ' notifications marked as read.',
                }];
            } catch (InvalidArgumentException $exception) {
                $feedback = ['ok' => false, 'message' => 'This notification view changed or expired. Review the list and try again.'];
            }
        } elseif ($action === 'check-matches') {
            require_once __DIR__ . '/../includes/notification-match-discovery.php';
            $result = NotificationMatchDiscovery::check($pdo);
            $checked = $result['checked'];
            $message = match ($checked) {
                0 => 'There are no more open Lost reports to check.',
                1 => 'Checked one open Lost report for possible matches.',
                default => 'Checked ' . $checked . ' open Lost reports for possible matches.',
            };
            if ($checked > 0) {
                $message .= $result['has_more'] ? ' More reports are ready to check.' : ' This check is complete.';
            }
            $feedback = ['ok' => true, 'message' => $message];
        } else {
            http_response_code(400);
            $error = 'This notification action is unavailable.';
        }

        if ($feedback !== null) {
            $_SESSION['notification_ui_feedback'] = $feedback;
            header('Location: ' . notificationInboxUrl($view, $page), true, 303);
            exit;
        }
    } elseif (array_key_exists('open', $_GET)) {
        $target = claimIdIsValid($_GET['open']) ? resolveRecipientNotification($pdo, $_GET['open']) : null;
        if ($target !== null && $target['available']) {
            header('Location: ' . $target['url'], true, 303);
            exit;
        }
        http_response_code(404);
        $error = $target['message'] ?? 'This notification is no longer available.';
    } else {
        $unread = countUnreadNotifications($pdo);
        $total = $view === 'unread' ? $unread : countRecipientNotifications($pdo);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $csrfToken = getCsrfToken();
        if ($unread > 0) {
            $snapshot = captureNotificationReadSnapshot($pdo);
        }
        $rows = fetchRecipientNotifications($pdo, $page, $pageSize, $view === 'unread');
        $moreMatchReports = ($_SESSION['notification_match_cursor']['owner'] ?? null) === $viewer['id'];
        $feedback = $_SESSION['notification_ui_feedback'] ?? null;
        unset($_SESSION['notification_ui_feedback']);
    }
} catch (NotificationAccessDenied $exception) {
    http_response_code(403);
    $activeViewer = false;
    $error = 'An active account is required to access notifications.';
} catch (PDOException $exception) {
    error_log('FindIT notifications could not be loaded or updated.');
    http_response_code(503);
    $error = 'Notifications are temporarily unavailable. Please try again.';
}

$inboxUrl = notificationInboxUrl($view, $page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-notifications">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="account.php">Account</a>
            <a href="browse-items.php">Browse Items</a>
            <?php if ($activeViewer) { renderNotificationBell($pdo, true, $unread); } ?>
        </nav>
    </header>

    <div class="browse-feed notification-inbox">
        <header class="feed-heading">
            <h1>Notifications</h1>
        </header>

        <?php if ($error !== ''): ?>
            <section class="empty glass" aria-label="Notification status">
                <p role="alert"><?= claimHtml($error) ?></p>
                <?php if ($activeViewer): ?>
                    <a class="button-secondary" href="<?= claimHtml($inboxUrl) ?>">Back to notifications</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <?php if ($feedback !== null): ?>
                <p class="<?= $feedback['ok'] ? 'notice' : 'message' ?>" role="status"><?= claimHtml($feedback['message']) ?></p>
            <?php endif; ?>

            <div class="notification-toolbar glass">
                <nav class="notification-filters" aria-label="Filter notifications">
                    <a href="<?= claimHtml(notificationInboxUrl('all')) ?>"<?= $view === 'all' ? ' aria-current="page"' : '' ?>>All</a>
                    <a href="<?= claimHtml(notificationInboxUrl('unread')) ?>"<?= $view === 'unread' ? ' aria-current="page"' : '' ?>>Unread</a>
                </nav>
                <div class="actions">
                    <?php if ($isStudent): ?>
                        <form method="post" action="<?= claimHtml($inboxUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= claimHtml($csrfToken) ?>">
                            <button type="submit" name="action" value="check-matches" class="button-secondary"><?= $moreMatchReports ? 'Check remaining reports' : 'Check for matches' ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($snapshot !== null): ?>
                        <form method="post" action="<?= claimHtml($inboxUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= claimHtml($csrfToken) ?>">
                            <input type="hidden" name="snapshot" value="<?= claimHtml($snapshot) ?>">
                            <button type="submit" name="action" value="mark-all" class="button-secondary">Mark all as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($rows === []): ?>
                <section class="empty glass">
                    <?php if ($view === 'unread'): ?>
                        <h2>No unread notifications</h2>
                        <a class="button-secondary" href="<?= claimHtml(notificationInboxUrl('all')) ?>">View all notifications</a>
                    <?php else: ?>
                        <h2>No notifications yet</h2>
                        <p>New updates will appear here.</p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="feed-posts" aria-label="Notifications list">
                    <?php foreach ($rows as $notification): ?>
                        <?php
                        $isUnread = $notification['read_at'] === null;
                        $title = NOTIFICATION_TITLES[$notification['type']];
                        $posted = new DateTimeImmutable($notification['created_at']);
                        $notificationUrl = $inboxUrl . '&open=' . rawurlencode($notification['id']);
                        ?>
                        <article class="feed-post glass notification-card<?= $isUnread ? ' notification-card-unread' : '' ?>">
                            <div class="notification-card-heading">
                                <h2><?= claimHtml($title) ?></h2>
                                <span class="notification-state"><?= $isUnread ? 'Unread' : 'Read' ?></span>
                            </div>
                            <p><?= claimHtml($notification['message']) ?></p>
                            <time datetime="<?= claimHtml($posted->format('Y-m-d\TH:i:s')) ?>"><?= claimHtml($posted->format('M j, Y, g:i A')) ?></time>
                            <footer class="notification-actions">
                                <a class="button-secondary" href="<?= claimHtml($notificationUrl) ?>" aria-label="<?= claimHtml('View details for ' . $title) ?>">View details</a>
                                <?php if ($isUnread): ?>
                                    <form method="post" action="<?= claimHtml($inboxUrl) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= claimHtml($csrfToken) ?>">
                                        <input type="hidden" name="notification_id" value="<?= claimHtml($notification['id']) ?>">
                                        <button class="button-secondary" type="submit" name="action" value="mark-one" aria-label="<?= claimHtml('Mark as read for ' . $title) ?>">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </section>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination glass" aria-label="Notification pages">
                        <?php if ($page > 1): ?>
                            <a class="button-secondary" rel="prev" href="<?= claimHtml(notificationInboxUrl($view, $page - 1)) ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a class="button-secondary" rel="next" href="<?= claimHtml(notificationInboxUrl($view, $page + 1)) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
