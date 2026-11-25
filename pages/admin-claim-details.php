<?php

require __DIR__ . '/../includes/admin-claims.php';
$actor = requireAdminClaimSession();
$pdo = appDatabase();

$claimId = $_GET['id'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$confirmation = $_GET['confirm'] ?? '';
$claim = null;
$reason = null;
$notice = '';
$error = '';
$databaseError = false;

try {
    if ($method !== 'GET' && $method !== 'POST') {
        header('Allow: GET, POST');
        http_response_code(405);
        exit;
    }
    if (claimIdIsValid($claimId)) {
        $claim = loadAdminClaim($pdo, $claimId);
    }
    if ($claim === null) {
        http_response_code(404);
    } elseif ($method === 'POST') {
        $action = $_POST['action'] ?? null;
        if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $error = 'Your form session expired. Reload this page and try again.';
        } elseif (!is_string($action) || !isset(ADMIN_CLAIM_ACTIONS[$action]) || $confirmation !== $action) {
            http_response_code(400);
            $error = 'Choose and confirm a valid review action.';
        } else {
            try {
                if ($action === 'reject') {
                    $reason = adminClaimRejectionReason($_POST['rejection_reason'] ?? '');
                }
                reviewAdminClaim($pdo, $claimId, (string) $claim['item_id'], $actor['id'], $action, $reason);
                $message = match ($action) {
                    'approve' => 'Claim approved. The item is reserved and awaiting handoff.',
                    'reject' => 'Claim rejected.',
                    'complete' => 'Handoff recorded. The item is resolved and remaining pending claims were closed.',
                    'cancel' => 'The approved claim was cancelled. The item is open again.',
                };
                $_SESSION['admin_claim_notice'] = ['id' => $claimId, 'message' => $message];
                header('Location: admin-claim-details.php?id=' . rawurlencode($claimId), true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                http_response_code(422);
                $error = $exception->getMessage();
            } catch (ClaimConflict $exception) {
                http_response_code(409);
                $error = $exception->getMessage();
                $claim = loadAdminClaim($pdo, $claimId);
            }
        }
    } elseif (($_SESSION['admin_claim_notice']['id'] ?? null) === $claimId) {
        $notice = $_SESSION['admin_claim_notice']['message'];
        unset($_SESSION['admin_claim_notice']);
    }
} catch (AdminClaimAccessDenied $exception) {
    denyFindItAccess('An active administrator account is required.');
} catch (PDOException $exception) {
    error_log('FindIT administrator claim details or review failed.');
    http_response_code(500);
    $databaseError = true;
}

$availableActions = $claim === null ? [] : adminClaimAvailableActions($claim);
$confirmedAction = in_array($confirmation, $availableActions, true) ? $confirmation : null;
$confirmationText = [
    'approve' => 'Approve only after verifying the claim and confirming the item is available. This reserves the item for handoff.',
    'reject' => 'This closes this claim. The item and other claims keep their current status.',
    'complete' => 'Confirm only once the item has actually been returned. This resolves the report and closes all other pending claims for it.',
    'cancel' => 'Cancel the reservation if pickup fell through. The item will reopen and other pending claims will remain available for review.',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Claim | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-details">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="admin-claims.php" aria-current="location">Manage Claims</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <article class="content-panel glass">
        <?php if ($databaseError || $claim === null): ?>
            <h1>Claim unavailable</h1>
            <p class="message" role="status"><?= $databaseError
                ? 'The claim could not be loaded right now. Please try again later.'
                : 'This claim could not be found.' ?></p>
        <?php else: ?>
            <h1><?= claimHtml($claim['title']) ?></h1>
            <?php if ($notice !== ''): ?><p class="notice" role="status"><?= claimHtml($notice) ?></p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="error" role="alert"><?= claimHtml($error) ?></p><?php endif; ?>
            <dl>
                <dt>Claim reference</dt><dd>#<?= claimHtml($claimId) ?></dd>
                <dt>Claim status</dt>
                <dd><span class="badge claim-status" data-status="<?= claimHtml($claim['status']) ?>"><?= claimHtml(ADMIN_CLAIM_STATUSES[$claim['status']]) ?></span></dd>
                <dt>Submitted</dt><dd><?= claimHtml(date('M j, Y, g:i A', strtotime($claim['created_at']))) ?></dd>
                <?php if ($claim['reviewed_at'] !== null): ?>
                    <dt>Reviewed</dt><dd><?= claimHtml(date('M j, Y, g:i A', strtotime($claim['reviewed_at']))) ?></dd>
                <?php endif; ?>
                <?php if ($claim['completed_at'] !== null): ?>
                    <dt>Handoff recorded</dt><dd><?= claimHtml(date('M j, Y, g:i A', strtotime($claim['completed_at']))) ?></dd>
                <?php endif; ?>
            </dl>
            <h2>Related item</h2>
            <dl>
                <dt>Type</dt><dd><?= claimHtml(ucfirst($claim['item_type'])) ?></dd>
                <dt>Category</dt><dd><?= claimHtml($claim['category_name']) ?></dd>
                <dt>Status</dt><dd><?= claimHtml(ucfirst($claim['item_status'])) ?></dd>
                <dt>Location</dt><dd><?= claimHtml($claim['location']) ?></dd>
            </dl>
            <p class="description"><?= claimHtml($claim['description']) ?></p>
            <h2>Private claim evidence</h2>
            <p class="description"><?= claimHtml($claim['evidence_description']) ?></p>
            <?php if ($claim['status'] === 'rejected' && $claim['rejection_reason'] !== null): ?>
                <h2>Feedback sent to the claimant</h2>
                <p class="description"><?= claimHtml($claim['rejection_reason']) ?></p>
            <?php endif; ?>

            <?php if ($confirmedAction !== null): ?>
                <section class="admin-claim-confirm" aria-labelledby="review-confirm">
                    <h2 id="review-confirm" tabindex="-1"><?= claimHtml(ADMIN_CLAIM_ACTIONS[$confirmedAction]) ?></h2>
                    <p><?= claimHtml($confirmationText[$confirmedAction]) ?></p>
                    <form method="post" action="admin-claim-details.php?id=<?= rawurlencode($claimId) ?>&amp;confirm=<?= rawurlencode($confirmedAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= claimHtml(getCsrfToken()) ?>">
                        <?php if ($confirmedAction === 'reject'): ?>
                            <div class="field">
                                <label for="rejection-reason">Feedback shown to the claimant <span>(optional)</span></label>
                                <p id="rejection-hint" class="claim-hint">Use up to 255 characters. Keep the feedback respectful and leave out personal information, private evidence, and details of other claims.</p>
                                <textarea id="rejection-reason" name="rejection_reason" maxlength="<?= ADMIN_CLAIM_REASON_LIMIT ?>"
                                          aria-describedby="rejection-hint"><?= claimHtml($reason ?? '') ?></textarea>
                            </div>
                        <?php endif; ?>
                        <div class="actions">
                            <button type="submit" name="action" value="<?= claimHtml($confirmedAction) ?>"><?= claimHtml(ADMIN_CLAIM_ACTIONS[$confirmedAction]) ?></button>
                            <a class="button-secondary" href="admin-claim-details.php?id=<?= rawurlencode($claimId) ?>">Go back</a>
                        </div>
                    </form>
                </section>
            <?php elseif ($availableActions !== []): ?>
                <div class="actions">
                    <?php foreach ($availableActions as $action): ?>
                        <a class="<?= in_array($action, ['reject', 'cancel'], true) ? 'danger' : 'button-secondary' ?>"
                           href="admin-claim-details.php?id=<?= rawurlencode($claimId) ?>&amp;confirm=<?= rawurlencode($action) ?>#review-confirm"><?= claimHtml(ADMIN_CLAIM_ACTIONS[$action]) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($claim['status'] === 'pending' && $claim['item_status'] === 'claimed'): ?>
                    <p class="claim-hint admin-item-state">Approval is paused while another claim holds the reservation.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="message">No review actions are available for the current status.</p>
            <?php endif; ?>
        <?php endif; ?>
        <p class="back-link"><a href="admin-claims.php">Back to Manage Claims</a></p>
    </article>
</main>
</body>
</html>
