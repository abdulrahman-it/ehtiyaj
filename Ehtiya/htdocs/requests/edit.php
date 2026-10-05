<?php
/** Owner-only request editor with locked state checks and coordinated attachment updates. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/upload.php';
need_role('student');
active_write();
$back = ui_back_context('mine');

// The URL id is only a lookup key: every read/write is scoped to the authenticated owner.
$id = input_int('id', 'get');
$loadRequest = static function () use ($pdo, $id): ?array {
    $query = $pdo->prepare('SELECT * FROM requests WHERE id=? AND owner_id=?');
    $query->execute([$id, me()['id']]);
    return $query->fetch() ?: null;
};
$r = $loadRequest();
if (!$r) {
    http_response_code(404);
    exit('الطلب غير موجود.');
}
if ($r['status'] !== 'receiving_offers') {
    http_response_code(409);
    exit('لا يمكن تعديل الطلب بعد بدء العمل أو إكماله أو إلغائه.');
}
$error = '';
// Prepare the current image list for the form after the owner-scoped request lookup.
$imagesQuery = $pdo->prepare('SELECT id,storage_path,mime_type,size_bytes FROM request_images WHERE request_id=? ORDER BY sort_order,id');
$imagesQuery->execute([$id]);
$currentImages = $imagesQuery->fetchAll();
$activeTypes = $pdo->query('SELECT id,name,is_active FROM assistance_types WHERE is_active=1 ORDER BY name')->fetchAll();
$typeOptions = $activeTypes;
if (!in_array((int)$r['assistance_type_id'], array_map(static fn(array $type): int => (int)$type['id'], $activeTypes), true)) {
    $oldType = $pdo->prepare('SELECT id,name,is_active FROM assistance_types WHERE id=?');
    $oldType->execute([$r['assistance_type_id']]);
    $existingType = $oldType->fetch();
    if ($existingType) {
        $typeOptions[] = $existingType;
    }
}
$titleValue = optional_input_string('title') ?? $r['title'];
$subjectValue = optional_input_string('subject') ?? (string)($r['subject'] ?? '');
$descriptionValue = optional_input_string('description') ?? $r['description'];
$typeValue = input_int('assistance_type_id', 'post', (int)$r['assistance_type_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_body_exceeds_post_max()) {
        http_response_code(413);
        exit('حجم الطلب المرسل يتجاوز الحد المسموح.');
    }
    post_only(['delete_images']);
    $title = trim(input_string('title'));
    $subject = trim(input_string('subject'));
    $description = trim(input_string('description'));
    $typeId = input_int('assistance_type_id');
    $typeValue = $typeId;
    if (mb_strlen($title) < 3 || mb_strlen($title) > 180
        || mb_strlen($subject) > 180 || mb_strlen($description) < 10 || mb_strlen($description) > 3000) {
        $error = 'تحقق من بيانات الطلب وطول العنوان والمادة والوصف.';
    } elseif (!in_array($typeId, array_map(static fn(array $type): int => (int)$type['id'], $activeTypes), true)
        && $typeId !== (int)$r['assistance_type_id']) {
        $error = 'اختر نوع مساعدة متاحًا.';
    } else {
        $postedDeletes = $_POST['delete_images'] ?? [];
        if (!is_array($postedDeletes)) {
            $postedDeletes = [];
        }
        $deleteIds = [];
        foreach ($postedDeletes as $candidate) {
            if (!(is_string($candidate) && preg_match('/^[0-9]+$/D', $candidate))) {
                $error = 'اختيار الصور المراد حذفها غير صالح.';
                break;
            }
            $deleteIds[] = (int)$candidate;
        }
        $deleteIds = array_values(array_unique(array_filter($deleteIds, static fn(int $imageId): bool => $imageId > 0)));
        $newFiles = $_FILES['images'] ?? null;
        if ($error === '' && $newFiles !== null) {
            if (!is_array($newFiles) || !is_array($newFiles['name'] ?? null)
                || !is_array($newFiles['tmp_name'] ?? null) || !is_array($newFiles['error'] ?? null)
                || !is_array($newFiles['size'] ?? null)) {
                $error = 'بيانات الصور غير صالحة.';
            }
        }
        // Recheck ownership and request state under lock before changing the request or its attachment rows.
        if ($error === '') {
            $savedFiles = [];
            $deletedAfterCommit = [];
            try {
                $pdo->beginTransaction();
                if (!lock_active_student((int)me()['id'])) {
                    throw new RuntimeException('الحساب لم يعد نشطًا؛ تعذر تعديل الطلب.');
                }
                $requestLock = $pdo->prepare("SELECT id,assistance_type_id,status FROM requests WHERE id=? AND owner_id=? AND status='receiving_offers' FOR UPDATE");
                $requestLock->execute([$id, me()['id']]);
                $lockedRequest = $requestLock->fetch();
                if (!$lockedRequest) {
                    throw new RuntimeException('تغيرت حالة الطلب أو لا تملك صلاحية تعديله.');
                }
                $imageLock = $pdo->prepare('SELECT id,storage_path,mime_type,size_bytes FROM request_images WHERE request_id=? FOR UPDATE');
                $imageLock->execute([$id]);
                $lockedImages = $imageLock->fetchAll();
                $knownIds = array_map(static fn(array $image): int => (int)$image['id'], $lockedImages);
                if (array_diff($deleteIds, $knownIds)) {
                    throw new RuntimeException('إحدى الصور المحددة لا تخص هذا الطلب.');
                }
                $remaining = count($lockedImages) - count($deleteIds);
                $names = $newFiles['name'] ?? [];
                $newNames = array_filter($names, static fn($name): bool => is_string($name) && $name !== '');
                if ($remaining + count($newNames) > 3) {
                    throw new RuntimeException('يمكن أن يحتوي الطلب على ثلاث صور إجمالًا كحد أقصى.');
                }
                $validType = $pdo->prepare('SELECT id FROM assistance_types WHERE id=? AND is_active=1');
                $validType->execute([$typeId]);
                if (!$validType->fetchColumn() && $typeId !== (int)$lockedRequest['assistance_type_id']) {
                    throw new RuntimeException('نوع المساعدة المختار غير متاح.');
                }
                $update = $pdo->prepare("UPDATE requests SET title=?,subject=?,description=?,assistance_type_id=? WHERE id=? AND owner_id=? AND status='receiving_offers'");
                $update->execute([$title, $subject !== '' ? $subject : null, $description, $typeId, $id, me()['id']]);
                foreach ($lockedImages as $image) {
                    if (in_array((int)$image['id'], $deleteIds, true)) {
                        $deletedAfterCommit[] = $image['storage_path'];
                    }
                }
                if ($deleteIds) {
                    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                    $delete = $pdo->prepare("DELETE FROM request_images WHERE request_id=? AND id IN ($placeholders)");
                    $delete->execute(array_merge([$id], $deleteIds));
                    if ($delete->rowCount() !== count($deleteIds)) {
                        throw new RuntimeException('تعذر حذف الصور المحددة.');
                    }
                }
                $sortOrder = $remaining;
                foreach ($names as $index => $originalName) {
                    if (!is_string($originalName)) {
                        throw new RuntimeException('بيانات الصور غير صالحة.');
                    }
                    if ($originalName === '') {
                        continue;
                    }
                    $one = [
                        'name' => $originalName,
                        'tmp_name' => $newFiles['tmp_name'][$index] ?? null,
                        'error' => $newFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $newFiles['size'][$index] ?? null,
                    ];
                    $saved = save_image($one, 'requests');
                    if (!$saved) {
                        continue;
                    }
                    $savedFiles[] = $saved['path'];
                    $insertImage = $pdo->prepare('INSERT INTO request_images(request_id,storage_path,mime_type,size_bytes,sort_order) VALUES(?,?,?,?,?)');
                    $insertImage->execute([$id, $saved['path'], $saved['mime'], $saved['size'], $sortOrder++]);
                }
                $pdo->commit();
                // Remove old disk files only after commit, so rollback cannot leave committed rows without files.
                foreach ($deletedAfterCommit as $relativePath) {
                    if (preg_match('#^/uploads/requests/[a-f0-9]{40}\.(?:jpg|png|webp)$#D', $relativePath)) {
                        $diskPath = realpath(__DIR__ . '/..' . $relativePath);
                        $requestDir = realpath(__DIR__ . '/../uploads/requests');
                        if ($diskPath && $requestDir && str_starts_with($diskPath, $requestDir . DIRECTORY_SEPARATOR) && is_file($diskPath) && !@unlink($diskPath)) {
                            error_log('[Ehtiyaj] Could not remove deleted request image: ' . basename($diskPath));
                        }
                    }
                }
                flash('تم تحديث الطلب وصوره.');
                go('/requests/view.php?id=' . $id);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($savedFiles ?? [] as $relativePath) {
                    $diskPath = __DIR__ . '/..' . $relativePath;
                    if (is_file($diskPath)) {
                        @unlink($diskPath);
                    }
                }
                if (!($exception instanceof RuntimeException) || $exception instanceof PDOException) {
                    error_log('[Ehtiyaj] Request edit failed: ' . $exception->getMessage());
                }
                $error = $exception instanceof RuntimeException && !($exception instanceof PDOException)
                    ? $exception->getMessage() : 'تعذر حفظ تعديل الطلب.';
            }
        }
    }
    // Keep the current images list fresh if validation failed.
    $imagesQuery->execute([$id]);
    $currentImages = $imagesQuery->fetchAll();
}

header_ui('تعديل الطلب');
?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(home_url()) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>تعديل الطلب</span></nav>
<?= page_back_link(ui_context_url('requests/view.php', ['id' => $id] + $back['forward']), 'تفاصيل الطلب') ?>
<div class="card p-4 request-edit-card">
    <span class="section-kicker"><i class="bi bi-pencil-square" aria-hidden="true"></i> تحديث التفاصيل والمرفقات</span>
    <h1 class="h3 mt-1">تعديل الطلب</h1>
    <p class="request-edit-intro">حدّث معلومات طلبك وصوره. ستبقى العروض مرتبطة بالطلب نفسه.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="request-edit-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <section class="request-form-section"><div class="request-form-section-heading"><span class="request-form-step">01</span><div><h2>معلومات الطلب</h2><p>العنوان والتصنيف يساعدان الطلاب على فهم احتياجك.</p></div></div>
        <div class="request-form-grid request-edit-meta-grid">
        <div class="request-form-field"><label class="form-label" for="edit-title">العنوان</label>
        <input id="edit-title" class="form-control" name="title" minlength="3" maxlength="180" required value="<?= e($titleValue) ?>"></div>
        <div class="request-form-field"><label class="form-label" for="edit-type">نوع المساعدة</label>
        <select id="edit-type" class="form-select mb-3" name="assistance_type_id" required>
            <?php foreach ($typeOptions as $type): ?>
                <option value="<?= (int)$type['id'] ?>" <?= $typeValue === (int)$type['id'] ? 'selected' : '' ?>><?= e($type['name']) ?><?= !(int)$type['is_active'] ? ' (غير متاح للطلبات الجديدة)' : '' ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="request-form-field"><label class="form-label" for="edit-subject">المادة</label>
        <input id="edit-subject" class="form-control" name="subject" maxlength="180" value="<?= e($subjectValue) ?>"></div>
        </div></section>
        <section class="request-form-section"><div class="request-form-section-heading"><span class="request-form-step">02</span><div><h2>تفاصيل المساعدة</h2><p>احتفظ بوصف واضح يساعد الطرف الآخر على فهم المطلوب.</p></div></div>
        <div class="request-form-field"><label class="form-label" for="edit-description">الوصف</label>
        <textarea id="edit-description" class="form-control" name="description" minlength="10" maxlength="3000" required><?= e($descriptionValue) ?></textarea></div>
        </section>

        <section class="request-form-section"><div class="request-form-section-heading"><span class="request-form-step">03</span><div><h2>الصور</h2><p>احتفظ بالصور الحالية أو احذفها وأضف بدائل.</p></div></div>
        <fieldset class="mb-3">
            <legend class="form-label">الصور الحالية (<?= count($currentImages) ?>/3)</legend>
            <?php if ($currentImages): ?>
                <div class="request-edit-images">
                    <?php foreach ($currentImages as $image): ?>
                        <label class="request-edit-image">
                            <img src="<?= e(app_url('requests/image.php?image_id=' . (int)$image['id'])) ?>" alt="صورة مرفقة بالطلب" loading="lazy">
                            <span><input type="checkbox" name="delete_images[]" value="<?= (int)$image['id'] ?>"> حذف هذه الصورة</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-secondary">لا توجد صور مرفقة حاليًا.</p>
            <?php endif; ?>
        </fieldset>
        <label class="form-label" for="edit-images">إضافة صور</label>
        <input id="edit-images" class="form-control" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-image-preview="edit-image-preview">
        <small class="form-text text-secondary d-block mt-2">JPEG أو PNG أو WEBP، حتى 2MB للصورة. الحد الإجمالي 3 صور بعد الحذف والإضافة.</small>
        <div id="edit-image-preview" class="image-preview-grid mt-2" aria-live="polite"></div>
        </section>
        <div class="request-form-actions"><span><i class="bi bi-shield-check" aria-hidden="true"></i> راجع التغييرات قبل حفظها</span><div><button class="btn btn-primary" type="submit">حفظ التعديلات</button><a class="btn btn-outline-secondary" href="<?= e(app_url('requests/view.php?id=' . $id)) ?>">إلغاء</a></div></div>
    </form>
</div>
<?php footer_ui(); ?>
