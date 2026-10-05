<?php

/**
 * Production configuration.
 * Prefer environment variables on the hosting server. If the host does not
 * support them, replace the four CHANGE_ME values below before uploading.
 */
function ehtiyaj_env(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return $value === false ? $fallback : trim((string) $value);
}

define('DB_HOST', ehtiyaj_env('DB_HOST', 'CHANGE_ME_DB_HOST'));
define('DB_NAME', ehtiyaj_env('DB_NAME', 'CHANGE_ME_DB_NAME'));
define('DB_USER', ehtiyaj_env('DB_USER', 'CHANGE_ME_DB_USER'));
define('DB_PASS', ehtiyaj_env('DB_PASS', 'CHANGE_ME_DB_PASSWORD'));

if (DB_HOST === 'CHANGE_ME_DB_HOST' || DB_NAME === 'CHANGE_ME_DB_NAME'
    || DB_USER === 'CHANGE_ME_DB_USER' || DB_PASS === 'CHANGE_ME_DB_PASSWORD') {
    error_log('[Ehtiyaj] Production database credentials are not configured.');
}

$projectRoot = realpath(__DIR__ . '/..');
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$projectPath = $projectRoot ? str_replace('\\', '/', $projectRoot) : '';
$documentPath = $documentRoot ? rtrim(str_replace('\\', '/', $documentRoot), '/') : '';
$isUnderDocumentRoot = $projectPath !== '' && $documentPath !== ''
    && ($projectPath === $documentPath || str_starts_with($projectPath, $documentPath . '/'));
$relativeRoot = $isUnderDocumentRoot && $projectPath !== $documentPath
    ? substr($projectPath, strlen($documentPath))
    : '';
$relativeRoot = '/' . trim(str_replace('\\', '/', $relativeRoot), '/');
define('BASE_URL', $relativeRoot === '/' ? '' : rtrim($relativeRoot, '/'));
