<?php

/** Validate and store an uploaded JPEG, PNG, or WEBP image in an approved folder. */
function save_image($file, $folder)
{
    if ($file === null) {
        return null;
    }
    if (!is_array($file)) {
        throw new RuntimeException('بيانات الصورة غير صالحة.');
    }
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $tmpName = $file['tmp_name'] ?? '';
    $size = $file['size'] ?? null;
    $clientName = $file['name'] ?? null;
    if ($error !== UPLOAD_ERR_OK || !is_string($tmpName) || $tmpName === ''
        || !is_string($clientName) || $clientName === ''
        || !is_int($size) || $size < 1 || $size > 2097152 || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('الصورة غير صالحة أو تتجاوز 2MB.');
    }
    if (!in_array($folder, ['requests', 'messages'], true)) {
        throw new RuntimeException('مجلد الصور غير صالح.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    $clientExtension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
    $extensionMime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!$ext || !isset($extensionMime[$clientExtension]) || $extensionMime[$clientExtension] !== $mime
        || @getimagesize($tmpName) === false) {
        throw new RuntimeException('الصيغ المقبولة JPEG وPNG وWEBP فقط.');
    }
    $name = bin2hex(random_bytes(20)).'.'.$ext;
    $dir = __DIR__.'/../uploads/'.$folder;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('تعذر إنشاء مجلد الصور.');
    }
    if (!@move_uploaded_file($tmpName, $dir.'/'.$name)) {
        throw new RuntimeException('تعذر حفظ الصورة.');
    }
    return ['path' => '/uploads/'.$folder.'/'.$name, 'mime' => $mime, 'size' => $size];
}
