-- Select the intended FindIT database before running this migration.
CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_id BIGINT UNSIGNED NOT NULL,
    type ENUM('possible_match', 'claim_submitted', 'admin_pending_claim', 'claim_approved',
        'claim_rejected', 'claim_completed', 'claim_withdrawn', 'claim_pickup_cancelled',
        'claim_closed_after_handoff') NOT NULL,
    claim_id BIGINT UNSIGNED NULL,
    lost_item_id BIGINT UNSIGNED NULL,
    found_item_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    unavailable_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_recipient_event (recipient_id, event_key),
    INDEX idx_notifications_inbox (recipient_id, created_at, id),
    INDEX idx_notifications_unread (recipient_id, read_at, created_at, id),
    CONSTRAINT chk_notifications_references CHECK (
        (type = 'possible_match' AND claim_id IS NULL AND lost_item_id IS NOT NULL
            AND found_item_id IS NOT NULL AND lost_item_id <> found_item_id)
        OR (type <> '' AND type <> 'possible_match' AND claim_id IS NOT NULL
            AND lost_item_id IS NULL AND found_item_id IS NULL)
    ),
    CONSTRAINT chk_notifications_event_key CHECK (
        (type = 'possible_match' AND BINARY event_key = BINARY CONCAT('match:', lost_item_id, ':', found_item_id))
        OR (type <> 'possible_match' AND BINARY event_key = BINARY CONCAT('claim:', claim_id, ':', type))
    ),
    CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_notifications_claim FOREIGN KEY (claim_id) REFERENCES claims (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_notifications_lost_item FOREIGN KEY (lost_item_id) REFERENCES items (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_notifications_found_item FOREIGN KEY (found_item_id) REFERENCES items (id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
