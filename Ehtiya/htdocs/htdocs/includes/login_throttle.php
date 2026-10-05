<?php

/** Small file-backed login limiter; state stays outside the document root and no DB table is needed. */
function login_throttle_file(string $scope): ?string
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (!$temporaryRoot || !is_writable($temporaryRoot)) {
        error_log('[Ehtiyaj] Login throttle temp directory is unavailable.');
        return null;
    }
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $temporaryPath = str_replace('\\', '/', $temporaryRoot);
    $documentPath = $documentRoot !== '' ? rtrim(str_replace('\\', '/', $documentRoot), '/') : '';
    if ($documentPath !== '' && ($temporaryPath === $documentPath || str_starts_with($temporaryPath, $documentPath . '/'))) {
        error_log('[Ehtiyaj] Login throttle storage resolves inside the document root.');
        return null;
    }
    $directory = $temporaryRoot . DIRECTORY_SEPARATOR . 'ehtiyaj-login-' . substr(hash('sha256', __DIR__), 0, 16);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('[Ehtiyaj] Could not create login throttle storage.');
        return null;
    }
    if (is_link($directory)) {
        error_log('[Ehtiyaj] Login throttle storage must not be a symlink.');
        return null;
    }
    @chmod($directory, 0700);
    return $directory . DIRECTORY_SEPARATOR . hash('sha256', $scope) . '.json';
}

/** Read or update a login-attempt counter while holding an exclusive file lock. */
function login_throttle_state(string $scope, int $maximum, int $windowSeconds, string $operation = 'read'): int
{
    $path = login_throttle_file($scope);
    if ($path === null) {
        return $operation === 'reset' ? 0 : $windowSeconds;
    }
    $handle = @fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        error_log('[Ehtiyaj] Could not lock login throttle state.');
        return $operation === 'reset' ? 0 : $windowSeconds;
    }
    @chmod($path, 0600);
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $now = time();
        $started = is_array($state) && isset($state['started']) && is_int($state['started']) ? $state['started'] : $now;
        $attempts = is_array($state) && isset($state['attempts']) && is_int($state['attempts']) ? max(0, $state['attempts']) : 0;
        if ($started > $now || $now - $started >= $windowSeconds) {
            $started = $now;
            $attempts = 0;
        }
        if ($operation === 'record') {
            $attempts++;
        } elseif ($operation === 'reset') {
            $attempts = 0;
            $started = $now;
        }
        $encoded = json_encode(['started' => $started, 'attempts' => $attempts], JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
            error_log('[Ehtiyaj] Could not persist login throttle state.');
            return $operation === 'reset' ? 0 : $windowSeconds;
        }
        return $attempts >= $maximum ? max(1, $started + $windowSeconds - $now) : 0;
    } catch (Throwable $exception) {
        error_log('[Ehtiyaj] Login throttle state error: ' . $exception->getMessage());
        return $operation === 'reset' ? 0 : $windowSeconds;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Remove expired throttle records without exposing their storage directory publicly. */
function login_throttle_cleanup(): void
{
    try {
        if (random_int(1, 128) !== 1) {
            return;
        }
    } catch (Throwable $exception) {
        return;
    }
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (!$temporaryRoot) {
        return;
    }
    $directory = $temporaryRoot . DIRECTORY_SEPARATOR . 'ehtiyaj-login-' . substr(hash('sha256', __DIR__), 0, 16);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        if (is_file($path) && !is_link($path) && (filemtime($path) ?: time()) < time() - 86400) {
            @unlink($path);
        }
    }
}
