<?php

// Credentials come from the host environment or an external private PHP file returning an array.
// Never commit real credentials. The private file must resolve outside DOCUMENT_ROOT.
$settings = [];
$privatePath = getenv('EHTIYAJ_PRIVATE_CONFIG');
if ((!is_string($privatePath) || $privatePath === '') && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $defaultPrivate = dirname((string)$_SERVER['DOCUMENT_ROOT']) . '/ehtiyaj-private.php';
    $privatePath = is_file($defaultPrivate) ? $defaultPrivate : '';
}
if (is_string($privatePath) && $privatePath !== '') {
    $resolvedPrivate = realpath($privatePath);
    $resolvedDocument = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    if (!$resolvedPrivate || !$resolvedDocument || !is_file($resolvedPrivate)
        || $resolvedPrivate === $resolvedDocument
        || str_starts_with($resolvedPrivate, rtrim($resolvedDocument, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        error_log('[Ehtiyaj] Invalid private configuration location.');
        http_response_code(500);
        exit('تعذر الاتصال بالخدمة حاليًا. حاول مرة أخرى لاحقًا.');
    }
    $loadedSettings = require $resolvedPrivate;
    if (is_array($loadedSettings)) {
        $settings = $loadedSettings;
    }
}
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    $environmentValue = getenv($key);
    $value = $environmentValue !== false ? $environmentValue : ($settings[$key] ?? null);
    if (!is_string($value) || ($key !== 'DB_PASS' && trim($value) === '')) {
        error_log('[Ehtiyaj] Database configuration is incomplete.');
        http_response_code(500);
        exit('تعذر الاتصال بالخدمة حاليًا. حاول مرة أخرى لاحقًا.');
    }
    define($key, $value);
}
unset($settings, $loadedSettings, $value, $environmentValue, $key);

$projectRoot = realpath(__DIR__ . '/..');
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';

$projectPath = $projectRoot
    ? str_replace('\\', '/', $projectRoot)
    : '';

$documentPath = $documentRoot
    ? rtrim(str_replace('\\', '/', $documentRoot), '/')
    : '';

$isUnderDocumentRoot = $projectPath !== ''
    && $documentPath !== ''
    && (
        $projectPath === $documentPath
        || str_starts_with($projectPath, $documentPath . '/')
    );

$relativeRoot = $isUnderDocumentRoot
    && $projectPath !== $documentPath
    ? substr($projectPath, strlen($documentPath))
    : '';

$relativeRoot = '/' . trim(
    str_replace('\\', '/', $relativeRoot),
    '/'
);

define(
    'BASE_URL',
    $relativeRoot === '/'
        ? ''
        : rtrim($relativeRoot, '/')
);
