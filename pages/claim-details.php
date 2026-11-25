<?php

require __DIR__ . '/../includes/claims.php';
$student = requireClaimStudent();
$pdo = appDatabase();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if ($method === 'POST' && !FINDIT_STUDENT_CLAIMS_ENABLED) {
    http_response_code(403);
    exit('Claim actions are not available yet.');
}

$claimId = $_GET['id'] ?? '';
$claim = null;
$notice = '';
$error = '';
$databaseError = false;

if (!claimIdIsValid($claimId)) {
    http_response_code(404);
} else {
    try {
        $statement = $pdo->prepare('SELECT c.item_id, c.status, c.created_at, c.evidence_description, c.rejection_reason,
            i.title, i.item_type FROM claims c INNER JOIN items i ON i.id = c.item_id
            WHERE c.id = :id AND c.claimant_id = :claimant_id LIMIT 1');
        $statement->execute(['id' => $claimId, 'claimant_id' => $student['id']]);
        $claim = $statement->fetch() ?: null;

        if ($claim === null) {
            http_response_code(404);
        } elseif ($method === 'POST') {
            if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $error = 'Your form session expired. Reload this page and try again.';
            } elseif (($_POST['action'] ?? null) !== 'withdraw' || ($_GET['confirm'] ?? null) !== '1') {
                http_response_code(400);
                $error = 'Choose a valid claim action.';
            } else {
                try {
                    withdrawStudentClaim($pdo, $claimId, (string) $claim['item_id'], $student['id']);
                    $_SESSION['claim_notice'] = ['id' => $claimId, 'message' => 'Your claim was withdrawn.'];
                    header('Location: claim-details.php?id=' . rawurlencode($claimId), true, 303);
                    exit;
                } catch (ClaimConflict $exception) {
                    http_response_code(409);
                    $error = $exception->getMessage() . ' Reload the page to see the latest status.';
                }
            }
        } elseif (($_SESSION['claim_notice']['id'] ?? null) === $claimId) {
            $notice = $_SESSION['claim_notice']['message'];
            unset($_SESSION['claim_notice']);
        }
    } catch (PDOException $exception) {
        error_log('FindIT student claim details failed.');
        http_response_code(500);
        $databaseError = true;
    }
}

$canWithdraw = FINDIT_STUDENT_CLAIMS_ENABLED && $claim !== null && $claim['status'] === 'pending';
$confirm = $canWithdraw && ($_GET['confirm'] ?? null) === '1';

if (!isset($pdo)) {
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim Details | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-details">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="my-claims.php" aria-current="location">My Claims</a>
            <a href="browse-items.php">Browse Items</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <article class="content-panel glass">
        <?php if ($databaseError || $claim === null): ?>
            <h1>Claim unavailable</h1>
            <p class="message" role="status"><?= $databaseError
                ? 'Your claim could not be loaded right now. Please try again later.'
                : 'This claim could not be found.' ?></p>
        <?php else: ?>
            <h1><?= claimHtml($claim['title']) ?></h1>
            <?php if ($notice !== ''): ?>
                <p class="notice" role="status"><?= claimHtml($notice) ?></p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="error" role="alert"><?= claimHtml($error) ?></p>
            <?php endif; ?>
            <dl>
                <dt>Item type</dt><dd><?= claimHtml(ucfirst($claim['item_type'])) ?></dd>
                <dt>Submitted</dt><dd><?= claimHtml(date('M j, Y', strtotime($claim['created_at']))) ?></dd>
                <dt>Claim status</dt>
                <dd><span class="badge claim-status" data-status="<?= claimHtml($claim['status']) ?>"><?= claimHtml(claimStatusLabel($claim['status'])) ?></span></dd>
            </dl>
            <h2>Your private evidence</h2>
            <p class="description"><?= claimHtml($claim['evidence_description']) ?></p>
            <?php if ($claim['status'] === 'rejected' && $claim['rejection_reason'] !== null): ?>
                <h2>Feedback on your claim</h2>
                <p class="description"><?= claimHtml($claim['rejection_reason']) ?></p>
            <?php endif; ?>
            <?php if ($confirm): ?>
                <section class="confirm" aria-labelledby="withdraw-heading">
                    <h2 id="withdraw-heading">Withdraw this claim?</h2>
                    <p>The request will be closed and you won't be able to submit a new claim for this item.</p>
                    <div class="actions">
                        <form method="post" action="claim-details.php?id=<?= rawurlencode($claimId) ?>&amp;confirm=1">
                            <input type="hidden" name="csrf_token" value="<?= claimHtml(getCsrfToken()) ?>">
                            <button type="submit" name="action" value="withdraw">Confirm withdrawal</button>
                        </form>
                        <a class="button-secondary" href="claim-details.php?id=<?= rawurlencode($claimId) ?>">Keep claim</a>
                    </div>
                </section>
            <?php elseif ($canWithdraw): ?>
                <div class="actions">
                    <a class="danger" href="claim-details.php?id=<?= rawurlencode($claimId) ?>&amp;confirm=1">Withdraw claim</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="back-link"><a href="my-claims.php">Back to My Claims</a></p>
    </article>
</main>
</body>
</html>
