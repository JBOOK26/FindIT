<?php

require_once __DIR__ . '/notifications.php';

final class NotificationMatchDiscovery
{
    private const REPORTS_PER_CHECK = 3;
    private const MATCH_FIELDS = ['title', 'description', 'location', 'occurred_at'];

    public static function check(PDO $pdo): array
    {
        $viewer = requireNotificationViewer($pdo);
        if ($viewer['role'] !== 'student') {
            throw new NotificationAccessDenied('Only active students can check for possible matches.');
        }

        $studentId = $viewer['id'];
        $cursor = $_SESSION['notification_match_cursor'] ?? null;
        $afterId = ($cursor['owner'] ?? null) === $studentId ? $cursor['after_id'] : '0';
        $reports = notificationQuery($pdo, "SELECT id, reporter_id, category_id, item_type, status,
            title, description, location, occurred_at FROM items FORCE INDEX (idx_items_reporter)
            WHERE reporter_id = :owner AND item_type = 'lost' AND status = 'open' AND id > :after_id
            ORDER BY id LIMIT :batch_size", [
            'owner' => $studentId, 'after_id' => $afterId, 'batch_size' => self::REPORTS_PER_CHECK + 1,
        ])->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($reports) > self::REPORTS_PER_CHECK;
        $batch = array_slice($reports, 0, self::REPORTS_PER_CHECK);
        foreach ($batch as $lost) {
            // Rank before acquiring write-transaction locks. Only the winning pairs need rechecking.
            try {
                $matches = findPotentialItemMatches($pdo, $lost, $studentId);
            } catch (PDOException $exception) {
                throw new NotificationStorageException('Notification storage is temporarily unavailable.');
            }
            if ($matches !== []) {
                self::storeCurrentMatches($pdo, $studentId, $lost, $matches);
            }
        }

        if ($hasMore) {
            $_SESSION['notification_match_cursor'] = ['owner' => $studentId, 'after_id' => (string) $batch[array_key_last($batch)]['id']];
        } else {
            unset($_SESSION['notification_match_cursor']);
        }

        return ['checked' => count($batch), 'has_more' => $hasMore];
    }

    private static function storeCurrentMatches(PDO $pdo, int $studentId, array $lost, array $matches): void
    {
        $lostId = (string) $lost['id'];
        $itemIds = ['lost_id' => $lostId];
        foreach ($matches as $index => $match) {
            $itemIds['found_' . $index] = $match['id'];
        }
        $placeholders = ':' . implode(', :', array_keys($itemIds));
        $ownsTransaction = !$pdo->inTransaction();

        try {
            if ($ownsTransaction && !$pdo->beginTransaction()) {
                throw new NotificationStorageException('Notification storage is temporarily unavailable.');
            }

            // Acquire item locks in primary-key order, followed by the student account.
            $locked = notificationQuery($pdo, "SELECT id, reporter_id, category_id, item_type, status,
                title, description, location, occurred_at FROM items FORCE INDEX (PRIMARY)
                WHERE id IN ($placeholders) ORDER BY id LOCK IN SHARE MODE", $itemIds)->fetchAll(PDO::FETCH_ASSOC);
            $current = array_column($locked, null, 'id');
            $currentLost = $current[$lostId] ?? null;

            if (canViewItemMatches($currentLost, $studentId)
                && (string) $currentLost['category_id'] === (string) $lost['category_id']
                && self::matchFieldsAgree($lost, $currentLost)
            ) {
                if (!claimStudentIsActive($pdo, $studentId, true)) {
                    throw new NotificationAccessDenied('An active student account is required to check for matches.');
                }

                foreach ($matches as $match) {
                    $found = $current[$match['id']] ?? null;
                    if ($found !== null && (string) $found['category_id'] === (string) $currentLost['category_id']
                        && claimItemIsEligible($found, $studentId) && self::matchFieldsAgree($match, $found)
                    ) {
                        NotificationEvents::possibleMatch($pdo, $lostId, $match['id']);
                    }
                }
            }

            if ($ownsTransaction && !$pdo->commit()) {
                throw new NotificationStorageException('Notification storage is temporarily unavailable.');
            }
        } catch (PDOException $exception) {
            throw new NotificationStorageException('Notification storage is temporarily unavailable.');
        } finally {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private static function matchFieldsAgree(array $ranked, array $current): bool
    {
        foreach (self::MATCH_FIELDS as $field) {
            if ($ranked[$field] !== $current[$field]) {
                return false;
            }
        }
        return true;
    }
}
