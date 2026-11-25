<?php

require_once __DIR__ . '/notifications.php';

const NOTIFICATION_TITLES = [
    'possible_match' => 'Possible match',
    'claim_submitted' => 'Claim submitted',
    'admin_pending_claim' => 'Claim ready for review',
    'claim_approved' => 'Claim approved',
    'claim_rejected' => 'Claim not approved',
    'claim_completed' => 'Item returned',
    'claim_withdrawn' => 'Claim withdrawn',
    'claim_pickup_cancelled' => 'Pickup cancelled',
    'claim_closed_after_handoff' => 'Claim closed',
];

// The inbox can pass its existing count after authenticating the recipient.
function renderNotificationBell(PDO $pdo, bool $current = false, ?int $unread = null): void
{
    try {
        $unread ??= countUnreadNotifications($pdo);
    } catch (NotificationAccessDenied $exception) {
        return;
    } catch (PDOException $exception) {
        error_log('FindIT could not load the notification count.');
        return;
    }

    $label = $unread === 0 ? 'Notifications' : 'Notifications, ' . $unread . ' unread';
    ?>
    <a class="notification-bell" href="notifications.php" aria-label="<?= claimHtml($label) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
        <?php if ($unread > 0): ?>
            <span class="notification-bell-count" aria-hidden="true"><?= $unread > 99 ? '99+' : $unread ?></span>
        <?php endif; ?>
    </a>
    <?php
}
