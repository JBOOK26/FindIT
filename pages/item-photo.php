<?php

require __DIR__ . '/../includes/auth.php';
$user = requireAuthenticatedUser();
session_write_close();
require __DIR__ . '/../includes/item-photos.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");

$id = $_GET['id'] ?? '';
if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,19}\z/', $id) !== 1
    || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
) {
    http_response_code(404);
    exit;
}

$pdo = appDatabase();
try {
    $statement = $pdo->prepare("SELECT image_path FROM items WHERE id = :id
        AND (status <> 'cancelled' OR reporter_id = :owner) LIMIT 1");
    $statement->execute(['id' => $id, 'owner' => $user['role'] === 'student' ? $user['id'] : 0]);
    $path = $statement->fetchColumn();
} catch (PDOException $exception) {
    error_log('FindIT photo lookup failed.');
    http_response_code(500);
    exit;
}

$file = is_string($path) ? itemPhotoFile($path) : null;
$size = $file === null ? false : @filesize($file);
$mime = $size !== false && $size > 0 && $size <= ITEM_PHOTO_MAX_BYTES ? itemPhotoFormat($file) : null;
$stream = $mime === null ? false : @fopen($file, 'rb');
if ($stream === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
fpassthru($stream);
fclose($stream);
