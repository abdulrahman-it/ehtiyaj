<?php
/** Minimal file-backed major catalog to honor the MVP ERD (no majors table). */
function major_catalog(): array
{
    $path = __DIR__ . '/../config/majors.json';
    $json = @file_get_contents($path);
    $items = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($items)) {
        return [];
    }
    $catalog = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
            continue;
        }
        $name = trim($item['name']);
        if ($name !== '' && mb_strlen($name) <= 120) {
            $catalog[] = ['name' => $name, 'active' => (bool)($item['active'] ?? false)];
        }
    }
    usort($catalog, static fn(array $a, array $b): int => strcoll($a['name'], $b['name']));
    return $catalog;
}

/** Return only currently selectable entries from the JSON-backed major catalog. */
function active_majors(): array
{
    return array_values(array_map(
        static fn(array $major): string => $major['name'],
        array_filter(major_catalog(), static fn(array $major): bool => $major['active'])
    ));
}

/** Check whether a submitted major name is present in the active catalog. */
function is_active_major(string $name): bool
{
    foreach (major_catalog() as $major) {
        if ($major['active'] && hash_equals($major['name'], $name)) {
            return true;
        }
    }
    return false;
}

/** Atomic, locked JSON replacement; no database table or schema migration. */
function write_major_catalog(array $catalog): bool
{
    $directory = __DIR__ . '/../config';
    $lock = @fopen($directory . '/majors.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return false;
    }
    $temporary = $directory . '/majors.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        $json = json_encode(array_values($catalog), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0640);
        return @rename($temporary, $directory . '/majors.json');
    } catch (Throwable $exception) {
        error_log('[Ehtiyaj] Major catalog write failed: ' . $exception->getMessage());
        return false;
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
