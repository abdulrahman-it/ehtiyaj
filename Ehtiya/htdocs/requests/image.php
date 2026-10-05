<?php
/** Deliver a request image only for a published request and a validated in-directory file. */
require __DIR__ . '/../includes/bootstrap.php';

// Match request visibility: only authenticated students may view images attached to published requests.
$user = me();
$imageId = input_int('image_id', 'get');
if (!$user || $user['role'] !== 'student' || $imageId < 1) {
    http_response_code(404);
    exit;
}

$query = $pdo->prepare(
    "SELECT i.storage_path, i.mime_type
     FROM request_images i
     JOIN requests r ON r.id = i.request_id
     WHERE i.id = ? AND r.moderation_status = 'published'"
);
$query->execute([$imageId]);
$image = $query->fetch();
if (!$image) {
    http_response_code(404);
    exit;
}

// Accept only application-generated storage keys, then verify the resolved path stays inside uploads/requests.
$relativePath = (string)$image['storage_path'];
if (!preg_match('#^/uploads/requests/[a-f0-9]{40}\.(?:jpg|png|webp)$#D', $relativePath)) {
    http_response_code(404);
    exit;
}
$base = realpath(__DIR__ . '/../uploads/requests');
$file = realpath(__DIR__ . '/..' . $relativePath);
if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true) || !hash_equals((string)$image['mime_type'], $mime)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="request-image.' . ($mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp')) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file);
