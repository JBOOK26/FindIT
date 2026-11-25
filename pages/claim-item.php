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
    exit('Claim submissions are not available yet.');
}

$itemId = $_GET['id'] ?? '';
$item = null;
$priorClaim = null;
$evidence = '';
$errors = [];
$unavailable = '';

if (!claimIdIsValid($itemId)) {
    http_response_code(404);
    $unavailable = 'This item could not be found.';
} elseif (!FINDIT_STUDENT_CLAIMS_ENABLED) {
    $unavailable = 'Claim submissions will open once campus staff can review requests.';
} else {
    try {
        $statement = $pdo->prepare('SELECT i.reporter_id, i.item_type, i.status, i.title, c.name AS category_name
            FROM items i INNER JOIN categories c ON c.id = i.category_id WHERE i.id = :id');
        $statement->execute(['id' => $itemId]);
        $item = $statement->fetch() ?: null;
        if ($item === null || !claimItemIsEligible($item, $student['id'])) {
            http_response_code(404);
            $unavailable = 'This item is unavailable for you to claim.';
            $item = null;
        } else {
            if ($method === 'POST') {
                $submittedEvidence = $_POST['evidence_description'] ?? null;
                if (is_string($submittedEvidence) && strlen($submittedEvidence) <= 4 * CLAIM_EVIDENCE_MAX_CHARACTERS) {
                    $evidence = trim($submittedEvidence);
                }
                if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
                    http_response_code(403);
                    $errors[] = 'Your form session expired. Reload this page and try again.';
                } elseif (!claimEvidenceIsValid($evidence)) {
                    http_response_code(422);
                    $errors[] = 'Enter private identifying details in 2,000 characters or fewer.';
                } else {
                    try {
                        $claimId = submitStudentClaim($pdo, $itemId, $student['id'], $evidence);
                        $_SESSION['claim_notice'] = ['id' => $claimId, 'message' => 'Your claim was submitted for review.'];
                        header('Location: claim-details.php?id=' . rawurlencode($claimId), true, 303);
                        exit;
                    } catch (ClaimConflict $exception) {
                        http_response_code(409);
                        $errors[] = $exception->getMessage();
                    }
                }
            }
            $priorClaim = findStudentClaim($pdo, $itemId, $student['id']);
        }
    } catch (PDOException $exception) {
        error_log('FindIT student claim request failed.');
        http_response_code(500);
        $unavailable = 'Claims are temporarily unavailable. Please try again later.';
    }
}

if (!isset($pdo)) {
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Claim Item | FindIT</title>
    <link rel="stylesheet" href="../assets/css/findit.css">
</head>
<body class="page-form">
<main>
    <header class="topbar glass">
        <a class="brand" href="account.php">FindIT</a>
        <nav aria-label="Main navigation">
            <a href="browse-items.php">Browse Items</a>
            <a href="my-claims.php">My Claims</a>
            <a href="account.php">Account</a>
            <?php require_once __DIR__ . '/../includes/notification-ui.php'; renderNotificationBell($pdo); ?>
        </nav>
    </header>
    <section class="content-panel glass">
        <h1>Claim item</h1>
        <?php if ($unavailable !== ''): ?>
            <p class="message" role="status"><?= claimHtml($unavailable) ?></p>
        <?php else: ?>
            <div class="claim-summary">
                <span class="badge found">Found</span>
                <h2><?= claimHtml($item['title']) ?></h2>
                <p><?= claimHtml($item['category_name']) ?></p>
            </div>
            <?php if ($errors !== []): ?>
                <div class="errors" role="alert">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= claimHtml($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($priorClaim !== null): ?>
                <p>You already have a claim on record for this item.</p>
                <a class="button-secondary" href="claim-details.php?id=<?= rawurlencode((string) $priorClaim['id']) ?>">View my claim</a>
            <?php else: ?>
                <p class="intro">Submitting a claim does not establish ownership. Campus staff will review the identifying details you provide.</p>
                <form method="post" action="claim-item.php?id=<?= rawurlencode($itemId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= claimHtml(getCsrfToken()) ?>">
                    <div class="field">
                        <label for="evidence_description">Private identifying details</label>
                        <p class="claim-hint" id="evidence-hint">Describe a distinguishing feature absent from the listing. Use up to 2,000 characters. Leave out passwords, student ID numbers, phone numbers, and unnecessary personal information.</p>
                        <textarea id="evidence_description" name="evidence_description" maxlength="<?= CLAIM_EVIDENCE_MAX_CHARACTERS ?>"
                                  aria-describedby="evidence-hint" required><?= claimHtml($evidence) ?></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit">Submit claim</button>
                        <a class="button-secondary" href="item-details.php?id=<?= rawurlencode($itemId) ?>">Back to item</a>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
