<?php
require __DIR__ . '/../includes/bootstrap.php';

// Never disclose storage paths or distinguish absent IDs from unauthorized images.
$messageId = input_int('message_id', 'get');
$reportId = input_int('report_id', 'get');
$user = me();
if (!$user || $messageId < 1 || $user['status'] !== 'active') {
    http_response_code(404);
    exit;
}

$query = $pdo->prepare(
    'SELECT m.image_path,m.image_mime,c.id conversation_id,c.owner_id,c.helper_id
     FROM messages m JOIN conversations c ON c.id=m.conversation_id
     WHERE m.id=? AND m.image_path IS NOT NULL'
);
$query->execute([$messageId]);
$image = $query->fetch();
if (!$image) {
    http_response_code(404);
    exit;
}

// Student access is participant-scoped; admin access requires a report tied to this conversation.
$allowed = false;
if ($user['role'] === 'student') {
    $allowed = (int)$image['owner_id'] === (int)$user['id'] || (int)$image['helper_id'] === (int)$user['id'];
} elseif ($user['role'] === 'admin' && $reportId > 0) {
    $report = $pdo->prepare('SELECT id FROM conversation_reports WHERE id=? AND conversation_id=?');
    $report->execute([$reportId, $image['conversation_id']]);
    $allowed = (bool)$report->fetchColumn();
}
if (!$allowed) {
    http_response_code(404);
    exit;
}

// Validate the generated storage key and resolved directory before reading file bytes.
$relativePath = (string)$image['image_path'];
if (!preg_match('#^/uploads/messages/[a-f0-9]{40}\.(?:jpg|png|webp)$#D', $relativePath)) {
    http_response_code(404);
    exit;
}
$base = realpath(__DIR__ . '/../uploads/messages');
$file = realpath(__DIR__ . '/..' . $relativePath);
if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $image['image_mime']) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="conversation-image.' . ($mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp')) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file);
