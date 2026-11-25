<?php

require_once __DIR__ . '/claims.php';

const ADMIN_CLAIM_STATUSES = [
    'pending' => 'Pending review',
    'approved' => 'Awaiting handoff',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];
const ADMIN_CLAIM_ACTIONS = [
    'approve' => 'Approve claim',
    'reject' => 'Reject claim',
    'complete' => 'Confirm handoff',
    'cancel' => 'Cancel approved claim',
];
const ADMIN_CLAIM_REASON_LIMIT = 255;
const ADMIN_CLAIM_RETURNED_REASON = 'The item has been returned.';

final class AdminClaimAccessDenied extends RuntimeException {}

function requireAdminClaimSession(): array
{
    $actor = requireAuthenticatedUser();
    header('Cache-Control: private, no-store');
    if ($actor['role'] !== 'administrator') {
        denyFindItAccess('An active administrator account is required.');
    }

    return $actor;
}

function verifyActiveClaimAdministrator(PDO $pdo, int $actorId, bool $lock = false): void
{
    $statement = $pdo->prepare("SELECT id FROM users WHERE id = :id
        AND role = 'administrator' AND account_status = 'active'" . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute(['id' => $actorId]);
    if ($statement->fetchColumn() === false) {
        throw new AdminClaimAccessDenied();
    }
}

function adminClaimAvailableActions(array $state): array
{
    if ($state['item_type'] !== 'found') {
        return [];
    }
    if ($state['status'] === 'pending') {
        if (!in_array($state['item_status'], ['open', 'claimed'], true)) {
            return [];
        }
        $actions = [];
        if ($state['item_status'] === 'open' && !$state['has_other_approved'] && !$state['has_completed']
            && (string) $state['claimant_id'] !== (string) $state['reporter_id']
        ) {
            $actions[] = 'approve';
        }
        $actions[] = 'reject';
        return $actions;
    }

    if ($state['status'] === 'approved' && $state['item_status'] === 'claimed'
        && !$state['has_other_approved'] && !$state['has_completed']
    ) {
        return ['complete', 'cancel'];
    }

    return [];
}

function adminClaimRejectionReason(mixed $submitted): ?string
{
    if (!is_string($submitted) || strlen($submitted) > 4 * ADMIN_CLAIM_REASON_LIMIT) {
        throw new InvalidArgumentException('Use up to 255 characters for rejection feedback.');
    }
    $reason = trim($submitted);
    if (preg_match('/\A.{0,255}\z/us', $reason) !== 1) {
        throw new InvalidArgumentException('Enter valid text with up to 255 characters for rejection feedback.');
    }

    return $reason === '' ? null : $reason;
}

function loadAdminClaim(PDO $pdo, string $claimId): ?array
{
    $statement = $pdo->prepare("SELECT c.item_id, c.claimant_id, c.status, c.created_at, c.reviewed_at,
        c.completed_at, c.rejection_reason, c.evidence_description,
        i.reporter_id, i.title, i.item_type, i.status AS item_status, i.location, i.description,
        cat.name AS category_name,
        EXISTS(SELECT 1 FROM claims other WHERE other.item_id = c.item_id
            AND other.id <> c.id AND other.status = 'approved') AS has_other_approved,
        EXISTS(SELECT 1 FROM claims other WHERE other.item_id = c.item_id
            AND other.status = 'completed') AS has_completed
        FROM claims c INNER JOIN items i ON i.id = c.item_id
        INNER JOIN categories cat ON cat.id = i.category_id WHERE c.id = :id LIMIT 1");
    $statement->execute(['id' => $claimId]);

    return $statement->fetch() ?: null;
}

function reviewAdminClaim(PDO $pdo, string $claimId, string $itemId, int $actorId, string $action, ?string $reason): void
{
    require_once __DIR__ . '/notifications.php';

    $pdo->beginTransaction();
    try {
        // Match student claim writes and report management: lock the item first.
        $itemLock = $pdo->prepare('SELECT reporter_id, item_type, status AS item_status
            FROM items WHERE id = :item_id FOR UPDATE');
        $itemLock->execute(['item_id' => $itemId]);
        $item = $itemLock->fetch();
        if (!$item) {
            throw new ClaimConflict('The related report is no longer available.');
        }
        verifyActiveClaimAdministrator($pdo, $actorId, true);

        $claimLock = $pdo->prepare('SELECT id, claimant_id, status FROM claims
            WHERE item_id = :item_id ORDER BY id FOR UPDATE');
        $claimLock->execute(['item_id' => $itemId]);
        $claim = null;
        $hasOtherApproved = false;
        $hasCompleted = false;
        $pendingIds = [];
        foreach ($claimLock->fetchAll() as $row) {
            $id = (string) $row['id'];
            if ($id === $claimId) {
                $claim = $row;
            } elseif ($row['status'] === 'approved') {
                $hasOtherApproved = true;
            } elseif ($row['status'] === 'pending') {
                $pendingIds[] = $id;
            }
            $hasCompleted = $hasCompleted || $row['status'] === 'completed';
        }
        if ($claim === null || !in_array($action, adminClaimAvailableActions($item + $claim + [
            'has_other_approved' => $hasOtherApproved,
            'has_completed' => $hasCompleted,
        ]), true)) {
            throw new ClaimConflict('This action is no longer available for the current claim and item status.');
        }

        [$newStatus, $newItemStatus, $event, $noticeType] = match ($action) {
            'approve' => ['approved', 'claimed', 'claim_approved', 'claim_approved'],
            'reject' => ['rejected', null, 'claim_rejected', 'claim_rejected'],
            'complete' => ['completed', 'resolved', 'claim_handoff_completed', 'claim_completed'],
            'cancel' => ['cancelled', 'open', 'approved_claim_cancelled', 'claim_pickup_cancelled'],
        };
        $parameters = [
            'id' => $claimId, 'item_id' => $itemId, 'old_status' => $claim['status'], 'new_status' => $newStatus,
        ];
        $reviewFields = '';
        if ($action === 'approve' || $action === 'reject') {
            $reviewFields = ', reviewed_by = :reviewer, reviewed_at = CURRENT_TIMESTAMP, rejection_reason = :reason';
            $parameters['reviewer'] = $actorId;
            $parameters['reason'] = $action === 'reject' ? $reason : null;
        } elseif ($action === 'complete') {
            $reviewFields = ', completed_at = CURRENT_TIMESTAMP';
        }
        $update = $pdo->prepare('UPDATE claims SET status = :new_status' . $reviewFields .
            ' WHERE id = :id AND item_id = :item_id AND status = :old_status');
        $update->execute($parameters);
        if ($update->rowCount() !== 1) {
            throw new ClaimConflict('The claim status changed. Review the current status and try again.');
        }

        if ($newItemStatus !== null) {
            $itemUpdate = $pdo->prepare('UPDATE items SET status = :new_status
                WHERE id = :item_id AND item_type = :item_type AND status = :old_status');
            $itemUpdate->execute([
                'new_status' => $newItemStatus, 'item_id' => $itemId, 'item_type' => 'found',
                'old_status' => $item['item_status'],
            ]);
            if ($itemUpdate->rowCount() !== 1) {
                throw new ClaimConflict('The item status changed. Review the current status and try again.');
            }
        }

        $audit = $pdo->prepare('INSERT INTO activity_logs (actor_id, item_id, claim_id, action)
            VALUES (:actor_id, :item_id, :claim_id, :action)');
        $audit->execute(['actor_id' => $actorId, 'item_id' => $itemId, 'claim_id' => $claimId, 'action' => $event]);
        NotificationEvents::claim($pdo, $noticeType, $claimId);

        if ($action === 'complete' && $pendingIds !== []) {
            $close = $pdo->prepare("UPDATE claims SET status = 'rejected', reviewed_by = :reviewer,
                reviewed_at = CURRENT_TIMESTAMP, rejection_reason = :reason
                WHERE item_id = :item_id AND status = 'pending'");
            $close->execute(['reviewer' => $actorId, 'reason' => ADMIN_CLAIM_RETURNED_REASON, 'item_id' => $itemId]);
            if ($close->rowCount() !== count($pendingIds)) {
                throw new ClaimConflict('Pending claims changed. Review the current status and try again.');
            }
            foreach ($pendingIds as $id) {
                $audit->execute([
                    'actor_id' => $actorId, 'item_id' => $itemId, 'claim_id' => $id,
                    'action' => 'claim_closed_after_handoff',
                ]);
                NotificationEvents::claim($pdo, 'claim_closed_after_handoff', $id);
            }
        }
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
