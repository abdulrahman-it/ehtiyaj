<?php

define('DB_HOST', 'sql207.infinityfree.com');
define('DB_NAME', 'if0_43072358_ehtiyaj');
define('DB_USER', 'if0_43072358');
define('DB_PASS', '4nqj9A02rc6');

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
