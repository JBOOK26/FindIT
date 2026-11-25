CREATE DATABASE IF NOT EXISTS findit
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE findit;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id VARCHAR(50) NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'administrator') NOT NULL DEFAULT 'student',
    account_status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    phone VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_student_id (student_id),
    INDEX idx_users_role_status (role, account_status)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    INDEX idx_categories_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_id BIGINT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    item_type ENUM('lost', 'found') NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    occurred_at DATETIME NULL,
    status ENUM('open', 'matched', 'claimed', 'resolved', 'cancelled') NOT NULL DEFAULT 'open',
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_items_reporter
        FOREIGN KEY (reporter_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_items_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_items_reporter (reporter_id),
    INDEX idx_items_category (category_id),
    INDEX idx_items_type_status (item_type, status),
    INDEX idx_items_location (location),
    INDEX idx_items_occurred_at (occurred_at),
    INDEX idx_items_created_at (created_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id BIGINT UNSIGNED NOT NULL,
    claimant_id BIGINT UNSIGNED NOT NULL,
    evidence_description TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    completed_at DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_claims_item
        FOREIGN KEY (item_id) REFERENCES items (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_claims_claimant
        FOREIGN KEY (claimant_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_claims_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_claims_item_status (item_id, status),
    INDEX idx_claims_claimant_status (claimant_id, status),
    INDEX idx_claims_reviewer (reviewed_by),
    INDEX idx_claims_created_at (created_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    item_id BIGINT UNSIGNED NULL,
    claim_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_activity_logs_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_activity_logs_item
        FOREIGN KEY (item_id) REFERENCES items (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_activity_logs_claim
        FOREIGN KEY (claim_id) REFERENCES claims (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_activity_logs_actor (actor_id),
    INDEX idx_activity_logs_item (item_id),
    INDEX idx_activity_logs_claim (claim_id),
    INDEX idx_activity_logs_action_created (action, created_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
