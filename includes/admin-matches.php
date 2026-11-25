<?php

require_once __DIR__ . '/admin-claims.php';
require_once __DIR__ . '/item-matching.php';

function requireAdminMatchRead(): void
{
    requireAdminClaimSession();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        exit;
    }
}

function loadOpenLostMatchReport(PDO $pdo, string $reportId): ?array
{
    $statement = $pdo->prepare("SELECT i.id, i.reporter_id, i.title, i.description, i.location,
        i.occurred_at, i.image_path, c.name AS category_name
        FROM items i INNER JOIN categories c ON c.id = i.category_id
        WHERE i.id = :id AND i.item_type = 'lost' AND i.status = 'open'");
    $statement->execute(['id' => $reportId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function renderAdminMatchReasons(array $reasons): void
{
    ?>
    <ul class="match-reasons" aria-label="Matching reasons">
        <?php foreach ($reasons as $reason): ?>
            <li><?= claimHtml($reason) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php
}
