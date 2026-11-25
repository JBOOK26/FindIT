<?php

const ITEM_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const ITEM_PHOTO_FORMATS = [
    'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
    'image/png' => ['png', IMAGETYPE_PNG],
    'image/webp' => ['webp', IMAGETYPE_WEBP],
];

final class ItemPhotoException extends RuntimeException {}

function itemPhotoFormat(string $file): ?string
{
    if (!extension_loaded('fileinfo')) {
        return null;
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = @$fileInfo->file($file);
    $image = @getimagesize($file);
    if (!is_string($mime) || !isset(ITEM_PHOTO_FORMATS[$mime]) || $image === false
        || $image[2] !== ITEM_PHOTO_FORMATS[$mime][1]
        || $image[0] < 1 || $image[1] < 1 || $image[0] * $image[1] > 24_000_000
    ) {
        return null;
    }

    return $mime;
}

function itemPhotoFile(?string $storedPath): ?string
{
    if ($storedPath === null
        || preg_match('~\A(?:uploads/)?([a-z0-9_ -]+\.(?:jpe?g|png|webp))\z~i', $storedPath, $matches) !== 1
    ) {
        return null;
    }

    $directory = realpath(__DIR__ . '/../uploads');
    if ($directory === false) {
        return null;
    }
    $candidate = $directory . DIRECTORY_SEPARATOR . $matches[1];
    $file = realpath($candidate);
    if ($file === false || dirname($file) !== $directory || is_link($candidate)
        || !is_file($file) || !is_readable($file)
    ) {
        return null;
    }

    return $file;
}

function saveItemPhoto(mixed $upload): ?string
{
    if ($upload === null) {
        return null;
    }
    if (!is_array($upload) || !is_int($upload['error'] ?? null)) {
        throw new ItemPhotoException('Choose one JPEG, PNG, or WebP photo.');
    }
    if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new ItemPhotoException('The photo must be 5 MiB or smaller.');
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new ItemPhotoException('The photo could not be uploaded. Choose it again and retry.');
    }

    $temporaryFile = $upload['tmp_name'] ?? null;
    if (!is_string($temporaryFile) || !is_uploaded_file($temporaryFile)) {
        throw new ItemPhotoException('The photo upload was invalid. Choose the photo again.');
    }
    $size = @filesize($temporaryFile);
    if ($size === false || $size < 1 || $size > ITEM_PHOTO_MAX_BYTES) {
        throw new ItemPhotoException('Choose a non-empty photo no larger than 5 MiB.');
    }
    $mime = itemPhotoFormat($temporaryFile);
    if ($mime === null) {
        throw new ItemPhotoException('Choose a valid JPEG, PNG, or WebP photo up to 24 megapixels.');
    }

    $directory = realpath(__DIR__ . '/../uploads');
    if ($directory === false) {
        throw new ItemPhotoException('Photos are temporarily unavailable. Try again later.');
    }
    try {
        $filename = 'item-' . bin2hex(random_bytes(24)) . '.' . ITEM_PHOTO_FORMATS[$mime][0];
    } catch (Random\RandomException $exception) {
        throw new ItemPhotoException('Photos are temporarily unavailable. Try again later.');
    }
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    $source = @fopen($temporaryFile, 'rb');
    if ($source === false) {
        throw new ItemPhotoException('The photo could not be saved. Choose it again and retry.');
    }
    // Exclusive creation prevents a collision from replacing another image.
    $target = @fopen($destination, 'xb');
    if ($target === false) {
        fclose($source);
        throw new ItemPhotoException('The photo could not be saved. Choose it again and retry.');
    }
    $copied = @stream_copy_to_stream($source, $target, ITEM_PHOTO_MAX_BYTES + 1);
    fclose($source);
    $closed = @fclose($target);
    if ($copied !== $size || !$closed) {
        @unlink($destination);
        throw new ItemPhotoException('The photo could not be saved. Choose it again and retry.');
    }

    return 'uploads/' . $filename;
}

function removeUnreferencedItemPhoto(PDO $pdo, ?string $path): void
{
    if ($path === null || preg_match('~\Auploads/(item-[0-9a-f]{48}\.(?:jpg|png|webp))\z~', $path, $matches) !== 1) {
        return;
    }
    try {
        // Also check the legacy bare-filename representation of the same file.
        $references = $pdo->prepare('SELECT 1 FROM items WHERE image_path IN (:path, :filename) LIMIT 1');
        $references->execute(['path' => $path, 'filename' => $matches[1]]);
        if ($references->fetchColumn() !== false) {
            return;
        }
        $file = itemPhotoFile($path);
        if ($file !== null && !@unlink($file)) {
            error_log('FindIT could not remove an unreferenced managed photo.');
        }
    } catch (PDOException $exception) {
        // Preserve the file when its reference status cannot be confirmed.
        error_log('FindIT could not verify whether a managed photo is unreferenced.');
    }
}

function renderItemPhoto(string $id, ?string $path, string $title, bool $lazy = true): void
{
    $hasPhoto = itemPhotoFile($path) !== null;
    ?>
    <div class="feed-media">
        <span class="feed-photo-fallback">
            <span class="feed-photo-icon" aria-hidden="true"></span>
            <span>No photo available</span>
        </span>
        <?php if ($hasPhoto): ?>
            <img src="item-photo.php?id=<?= rawurlencode($id) ?>"
                 alt="<?= htmlspecialchars('Photo of ' . $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 <?= $lazy ? 'loading="lazy"' : '' ?> decoding="async" onerror="this.hidden = true">
        <?php endif; ?>
    </div>
    <?php
}

function renderItemPhotoInput(bool $replacement = false): void
{
    ?>
    <div class="field photo-field" data-photo-field>
        <label for="item_photo"><?= $replacement ? 'Add or Replace Photo' : 'Upload Item Photo' ?> <span>(optional)</span></label>
        <p id="photo-hint">JPEG, PNG, or WebP. Up to 5 MiB and 24 megapixels.</p>
        <input id="item_photo" name="item_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="photo-hint">
        <p class="photo-selection" data-photo-selection role="status"></p>
        <img class="photo-preview" data-photo-preview alt="Preview of the selected photo" hidden>
        <button class="button-secondary photo-clear" type="button" data-photo-clear hidden>Clear selection</button>
    </div>
    <?php
}
