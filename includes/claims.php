<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/claims.php';

const CLAIM_EVIDENCE_MAX_CHARACTERS = 2000;

final class ClaimConflict extends RuntimeException {}

function requireClaimStudent(): array
{
    $student = requireAuthenticatedUser();
    header('Cache-Control: private, no-store');
    if ($student['role'] !== 'student') {
        denyFindItAccess('Only students can access student claims.');
    }

    return $student;
}

function claimIdIsValid(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[1-9][0-9]{0,19}\z/', $id) === 1
        && (strlen($id) < 20 || strcmp($id, '18446744073709551615') <= 0);
}

function claimStudentIsActive(PDO $pdo, int $id, bool $lock = false): bool
{
    $statement = $pdo->prepare("SELECT id FROM users WHERE id = :id
        AND role = 'student' AND account_status = 'active'" . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['id' => $id]);

    return $statement->fetchColumn() !== false;
}

function claimItemIsEligible(array $item, int $studentId): bool
{
    return $item['item_type'] === 'found' && $item['status'] === 'open'
        && (string) $item['reporter_id'] !== (string) $studentId;
}

function findStudentClaim(PDO $pdo, string $itemId, int $studentId, bool $lock = false): ?array
{
    $statement = $pdo->prepare('SELECT id, status FROM claims
        WHERE item_id = :item_id AND claimant_id = :claimant_id ORDER BY id DESC LIMIT 1'
        . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['item_id' => $itemId, 'claimant_id' => $studentId]);

    return $statement->fetch() ?: null;
}

function claimEvidenceIsValid(mixed $evidence): bool
{
    return is_string($evidence) && strlen($evidence) <= 4 * CLAIM_EVIDENCE_MAX_CHARACTERS
        && preg_match('/[^\s\p{Z}\p{C}]/u', $evidence) === 1
        && preg_match_all('/./us', $evidence) <= CLAIM_EVIDENCE_MAX_CHARACTERS;
}

function submitStudentClaim(PDO $pdo, string $itemId, int $studentId, string $evidence): string
{
    require_once __DIR__ . '/notifications.php';

    $pdo->beginTransaction();
    try {
        // Report editing, cancellation and all claim writes lock the parent item first.
        $lock = $pdo->prepare('SELECT reporter_id, item_type, status FROM items WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $itemId]);
        $item = $lock->fetch();
        if (!$item || !claimItemIsEligible($item, $studentId)) {
            throw new ClaimConflict('This item is no longer available for you to claim.');
        }

        // Staff review takes its administrator lock before touching claimant accounts.
        // Read updated_at to lock the user rows, in addition to the role/status index entries.
        $staffRecipients = notificationQuery($pdo, "SELECT id, updated_at FROM users FORCE INDEX (idx_users_role_status)
            WHERE role = 'administrator' AND account_status = 'active' ORDER BY id LOCK IN SHARE MODE", [])
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!claimStudentIsActive($pdo, $studentId, true)) {
            throw new ClaimConflict('This item is no longer available for you to claim.');
        }

        // Prior claims are retained. A second claim for this student and item is unavailable.
        if (findStudentClaim($pdo, $itemId, $studentId, true) !== null) {
            throw new ClaimConflict('You already have a claim record for this item. Check My Claims for its status.');
        }

        $insert = $pdo->prepare("INSERT INTO claims (item_id, claimant_id, evidence_description, status)
            VALUES (:item_id, :claimant_id, :evidence, 'pending')");
        $insert->execute(['item_id' => $itemId, 'claimant_id' => $studentId, 'evidence' => $evidence]);
        $claimId = $pdo->lastInsertId();
        NotificationEvents::claim($pdo, 'claim_submitted', $claimId);
        foreach ($staffRecipients as $recipientId) {
            NotificationEvents::claim($pdo, 'admin_pending_claim', $claimId, (string) $recipientId);
        }
        $pdo->commit();

        return $claimId;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function withdrawStudentClaim(PDO $pdo, string $claimId, string $itemId, int $studentId): void
{
    require_once __DIR__ . '/notifications.php';

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM items WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $itemId]);
        if ($lock->fetchColumn() === false || !claimStudentIsActive($pdo, $studentId, true)) {
            throw new ClaimConflict('This claim cannot be withdrawn.');
        }

        $update = $pdo->prepare("UPDATE claims SET status = 'cancelled'
            WHERE id = :id AND item_id = :item_id AND claimant_id = :claimant_id AND status = 'pending'");
        $update->execute(['id' => $claimId, 'item_id' => $itemId, 'claimant_id' => $studentId]);
        if ($update->rowCount() !== 1) {
            throw new ClaimConflict('This claim is no longer pending and cannot be withdrawn.');
        }
        NotificationEvents::claim($pdo, 'claim_withdrawn', $claimId);
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function claimHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function claimStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Not approved',
        'completed' => 'Returned',
        'cancelled' => 'Cancelled',
        default => 'Status unavailable',
    };
}
