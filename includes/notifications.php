<?php

require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/item-matching.php';
require_once __DIR__ . '/../config/notifications.php';

final class NotificationAccessDenied extends RuntimeException {}
final class NotificationStorageException extends PDOException {}

const NOTIFICATION_MESSAGES = [
    'possible_match' => 'A possible match was found for your Lost report.',
    'claim_submitted' => 'Your claim was submitted for review.',
    'admin_pending_claim' => 'A new claim is awaiting staff review.',
    'claim_approved' => 'Your claim was approved and is awaiting handoff.',
    'claim_rejected' => 'Staff did not approve your claim.',
    'claim_completed' => 'The item return for your claim was recorded.',
    'claim_withdrawn' => 'You withdrew your claim.',
    'claim_pickup_cancelled' => 'Staff cancelled the planned pickup for your claim.',
    'claim_closed_after_handoff' => 'Your claim was closed after the item was returned.',
];

const NOTIFICATION_CLAIM_STATUSES = [
    'claim_submitted' => 'pending',
    'admin_pending_claim' => 'pending',
    'claim_approved' => 'approved',
    'claim_rejected' => 'rejected',
    'claim_completed' => 'completed',
    'claim_withdrawn' => 'cancelled',
    'claim_pickup_cancelled' => 'cancelled',
    'claim_closed_after_handoff' => 'rejected',
];

function notificationQuery(PDO $pdo, string $sql, array $parameters): PDOStatement
{
    try {
        $statement = $pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $parameterType = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $name, $value, $parameterType);
        }
        $statement->execute();
        return $statement;
    } catch (PDOException $exception) {
        throw new NotificationStorageException('Notification storage is temporarily unavailable.');
    }
}

function checkedNotificationId(mixed $id): string
{
    if (!claimIdIsValid($id)) {
        throw new InvalidArgumentException('Invalid notification or target ID.');
    }
    return $id;
}

// Event calls belong in trusted server code. They never take a submitted event key.
final class NotificationEvents
{
    // True means stored or already present. False means that the event is ineligible.
    public static function claim(PDO $pdo, string $type, mixed $claimId, mixed $staffId = null): bool
    {
        $claimId = checkedNotificationId($claimId);
        $staffAlert = $type === 'admin_pending_claim';
        if (!isset(NOTIFICATION_CLAIM_STATUSES[$type]) || $staffAlert !== ($staffId !== null)) {
            throw new InvalidArgumentException('Invalid claim notification type or recipient.');
        }
        if ($staffId !== null) {
            $staffId = checkedNotificationId($staffId);
        }

        $claim = notificationQuery($pdo, "SELECT c.claimant_id, c.status, u.role, u.account_status,
            item.item_type, item.status AS item_status
            FROM claims c JOIN users u ON u.id = c.claimant_id JOIN items item ON item.id = c.item_id
            WHERE c.id = :claim", ['claim' => $claimId])->fetch(PDO::FETCH_ASSOC);
        if (!$claim || $claim['status'] !== NOTIFICATION_CLAIM_STATUSES[$type] || $claim['item_type'] !== 'found') {
            return false;
        }

        if ($staffId !== null) {
            if (!in_array($claim['item_status'], ['open', 'claimed'], true)
                || notificationQuery($pdo, "SELECT id FROM users WHERE id = :staff
                    AND role = 'administrator' AND account_status = 'active'", ['staff' => $staffId])->fetchColumn() === false
            ) {
                return false;
            }
        } elseif ($claim['role'] !== 'student' || $claim['account_status'] !== 'active') {
            return false;
        }

        self::insert($pdo, $staffId ?? (string) $claim['claimant_id'], $type, $claimId, null, null);
        return true;
    }

    // The discovery caller supplies a ranked pair. Recheck its basic eligibility before storing it.
    public static function possibleMatch(PDO $pdo, mixed $lostId, mixed $foundId): bool
    {
        $lostId = checkedNotificationId($lostId);
        $foundId = checkedNotificationId($foundId);

        $owner = notificationQuery($pdo, "SELECT lost.reporter_id FROM items lost
            JOIN items found ON found.category_id = lost.category_id AND found.id = :found
            JOIN users u ON u.id = lost.reporter_id AND u.role = 'student' AND u.account_status = 'active'
            WHERE lost.id = :lost AND lost.item_type = 'lost' AND lost.status = 'open'
              AND found.item_type = 'found' AND found.status = 'open' AND found.reporter_id <> lost.reporter_id",
            ['lost' => $lostId, 'found' => $foundId])->fetchColumn();
        if ($owner === false) {
            return false;
        }

        self::insert($pdo, (string) $owner, 'possible_match', null, $lostId, $foundId);
        return true;
    }

    private static function insert(PDO $pdo, string $recipient, string $type, ?string $claimId, ?string $lostId, ?string $foundId): void
    {
        // The caller owns any transaction. Deduplication leaves read and unavailable timestamps intact.
        $key = $claimId === null ? 'match:' . $lostId . ':' . $foundId : 'claim:' . $claimId . ':' . $type;
        notificationQuery($pdo, 'INSERT INTO notifications (recipient_id, type, claim_id, lost_item_id, found_item_id, event_key)
            VALUES (:recipient, :type, :claim, :lost, :found, :event_key) ON DUPLICATE KEY UPDATE id = notifications.id', [
            'recipient' => $recipient, 'type' => $type, 'claim' => $claimId,
            'lost' => $lostId, 'found' => $foundId, 'event_key' => $key,
        ]);
    }
}

function requireNotificationViewer(PDO $pdo): array
{
    $session = requireSignedInSession();
    try {
        $viewer = currentSessionAccount($pdo, $session['id']);
    } catch (PDOException $exception) {
        throw new NotificationStorageException('Notification storage is temporarily unavailable.');
    }
    if ($viewer === null) {
        throw new NotificationAccessDenied('An active account is required to access notifications.');
    }

    return $viewer;
}

const NOTIFICATION_INBOX_SCOPE = " FROM notifications n
    JOIN users recipient ON recipient.id = n.recipient_id
    LEFT JOIN claims c ON c.id = n.claim_id
    LEFT JOIN items claimed_item ON claimed_item.id = c.item_id
    LEFT JOIN items lost ON lost.id = n.lost_item_id
    LEFT JOIN items found ON found.id = n.found_item_id
    WHERE n.recipient_id = :recipient AND recipient.role = :role AND recipient.account_status = 'active'
      AND n.created_at >= TIMESTAMPADD(DAY, :age_days, CURRENT_TIMESTAMP)
      AND ((recipient.role = 'administrator' AND n.type = 'admin_pending_claim')
        OR (recipient.role = 'student' AND n.type <> 'admin_pending_claim'))";

const NOTIFICATION_AVAILABLE = "n.unavailable_at IS NULL AND (
    (n.type = 'possible_match' AND lost.reporter_id = n.recipient_id AND lost.item_type = 'lost'
        AND lost.status = 'open' AND found.item_type = 'found' AND found.status = 'open'
        AND found.category_id = lost.category_id AND found.reporter_id <> lost.reporter_id)
    OR (n.type = 'admin_pending_claim' AND c.status = 'pending' AND claimed_item.item_type = 'found'
        AND claimed_item.status IN ('open', 'claimed'))
    OR (n.type NOT IN ('possible_match', 'admin_pending_claim') AND c.claimant_id = n.recipient_id)
)";

function notificationScopeParameters(array $viewer): array
{
    return ['recipient' => $viewer['id'], 'role' => $viewer['role'], 'age_days' => -FINDIT_NOTIFICATION_INBOX_DAYS];
}

function fetchRecipientNotifications(PDO $pdo, int $page = 1, int $pageSize = 20, bool $unreadOnly = false): array
{
    if ($pageSize < 1 || $pageSize > 100 || $page < 1 || $page > intdiv(PHP_INT_MAX, $pageSize)) {
        throw new InvalidArgumentException('Invalid notification pagination.');
    }
    $viewer = requireNotificationViewer($pdo);
    $query = 'SELECT n.id, n.type, n.created_at, n.read_at' . NOTIFICATION_INBOX_SCOPE
        . ' AND (' . NOTIFICATION_AVAILABLE . ')' . ($unreadOnly ? ' AND n.read_at IS NULL' : '')
        . ' ORDER BY n.created_at DESC, n.id DESC LIMIT :page_size OFFSET :start';
    $rows = notificationQuery($pdo, $query, notificationScopeParameters($viewer) + [
        'page_size' => $pageSize, 'start' => ($page - 1) * $pageSize,
    ])->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (string) $row['id'];
        $row['message'] = NOTIFICATION_MESSAGES[$row['type']];
    }
    return $rows;
}

function countUnreadNotifications(PDO $pdo): int
{
    return countRecipientNotifications($pdo, true);
}

function countRecipientNotifications(PDO $pdo, bool $unreadOnly = false): int
{
    $viewer = requireNotificationViewer($pdo);
    return (int) notificationQuery($pdo, 'SELECT COUNT(*)' . NOTIFICATION_INBOX_SCOPE
        . ($unreadOnly ? ' AND n.read_at IS NULL' : '') . ' AND (' . NOTIFICATION_AVAILABLE . ')',
        notificationScopeParameters($viewer))->fetchColumn();
}

// A null result is indistinguishable for an unknown ID and another user's ID.
function resolveRecipientNotification(PDO $pdo, mixed $id): ?array
{
    $id = checkedNotificationId($id);
    $viewer = requireNotificationViewer($pdo);
    $row = notificationQuery($pdo, 'SELECT n.type, n.claim_id, n.lost_item_id, n.found_item_id, (' . NOTIFICATION_AVAILABLE
        . ') AS available' . NOTIFICATION_INBOX_SCOPE . ' AND n.id = :id', notificationScopeParameters($viewer) + ['id' => $id])
        ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $url = null;
    if ((bool) $row['available']) {
        if ($row['type'] === 'possible_match') {
            $lost = notificationQuery($pdo, 'SELECT id, reporter_id, item_type, status, title, description, location, occurred_at
                FROM items WHERE id = :lost AND reporter_id = :owner', [
                'lost' => (string) $row['lost_item_id'], 'owner' => $viewer['id'],
            ])->fetch(PDO::FETCH_ASSOC);
            try {
                $matches = $lost ? findPotentialItemMatches($pdo, $lost, $viewer['id']) : [];
            } catch (PDOException $exception) {
                throw new NotificationStorageException('Notification storage is temporarily unavailable.');
            }
            if (in_array((string) $row['found_item_id'], array_column($matches, 'id'), true)) {
                $url = 'my-report-details.php?id=' . rawurlencode((string) $row['lost_item_id']) . '#potential-matches';
            }
        } else {
            $page = $row['type'] === 'admin_pending_claim' ? 'admin-claim-details.php' : 'claim-details.php';
            $url = $page . '?id=' . rawurlencode((string) $row['claim_id']);
        }
    }

    return [
        'message' => $url !== null ? NOTIFICATION_MESSAGES[$row['type']] : match ($row['type']) {
            'possible_match' => 'This suggestion is no longer available.',
            default => 'This notification is no longer available.',
        },
        'url' => $url,
        'available' => $url !== null,
    ];
}

function markNotificationRead(PDO $pdo, mixed $id): bool
{
    return updateNotificationReadIds($pdo, [checkedNotificationId($id)]) === 1;
}

// Keep the exact IDs visible at capture time, including items beyond the current page.
function captureNotificationReadSnapshot(PDO $pdo): string
{
    $viewer = requireNotificationViewer($pdo);
    $ids = notificationQuery($pdo, 'SELECT n.id' . NOTIFICATION_INBOX_SCOPE
        . ' AND n.read_at IS NULL AND (' . NOTIFICATION_AVAILABLE . ') ORDER BY n.id', notificationScopeParameters($viewer))
        ->fetchAll(PDO::FETCH_COLUMN);
    $token = bin2hex(random_bytes(32));
    $_SESSION['notification_read_snapshot'] = [
        'token' => $token, 'recipient' => $viewer['id'], 'role' => $viewer['role'],
        'ids' => array_map('strval', $ids), 'expires' => time() + 900,
    ];
    return $token;
}

function markNotificationSnapshotRead(PDO $pdo, mixed $token): int
{
    $viewer = requireNotificationViewer($pdo);
    $snapshot = $_SESSION['notification_read_snapshot'] ?? null;
    if (!is_string($token) || strlen($token) !== 64 || $snapshot === null
        || $snapshot['recipient'] !== $viewer['id'] || $snapshot['role'] !== $viewer['role']
        || $snapshot['expires'] <= time() || !hash_equals($snapshot['token'], $token)
    ) {
        throw new InvalidArgumentException('Your notification list changed or expired. Reload it and try again.');
    }
    if ($snapshot['ids'] === []) {
        return 0;
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction && !$pdo->beginTransaction()) {
            throw new NotificationStorageException('Notification storage is temporarily unavailable.');
        }
        $marked = 0;
        foreach (array_chunk($snapshot['ids'], 250) as $ids) {
            $marked += updateNotificationReadIds($pdo, $ids);
        }
        if ($ownsTransaction && !$pdo->commit()) {
            throw new NotificationStorageException('Notification storage is temporarily unavailable.');
        }
        return $marked;
    } catch (PDOException $exception) {
        throw new NotificationStorageException('Notification storage is temporarily unavailable.');
    } finally {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

// Internal chunk writer. Read eligibility without item/claim locks, then update inbox rows only.
function updateNotificationReadIds(PDO $pdo, array $ids): int
{
    $viewer = requireNotificationViewer($pdo);
    if ($ids === []) {
        return 0;
    }

    $idParameters = [];
    foreach ($ids as $index => $id) {
        $idParameters['notification_' . $index] = checkedNotificationId($id);
    }
    $placeholders = ':' . implode(', :', array_keys($idParameters));
    $eligible = notificationQuery($pdo, 'SELECT n.id' . NOTIFICATION_INBOX_SCOPE
        . ' AND n.id IN (' . $placeholders . ') AND n.read_at IS NULL AND (' . NOTIFICATION_AVAILABLE . ')',
        notificationScopeParameters($viewer) + $idParameters)->fetchAll(PDO::FETCH_COLUMN);
    if ($eligible === []) {
        return 0;
    }

    $idParameters = [];
    foreach ($eligible as $index => $id) {
        $idParameters['notification_' . $index] = (string) $id;
    }
    $placeholders = ':' . implode(', :', array_keys($idParameters));
    return notificationQuery($pdo, "UPDATE notifications SET read_at = CURRENT_TIMESTAMP
        WHERE recipient_id = :recipient AND id IN ($placeholders) AND read_at IS NULL AND unavailable_at IS NULL
        AND EXISTS (SELECT 1 FROM users WHERE id = notifications.recipient_id AND account_status = 'active' AND role = :role)",
        ['recipient' => $viewer['id'], 'role' => $viewer['role']] + $idParameters)->rowCount();
}
