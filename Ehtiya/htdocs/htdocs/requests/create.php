<?php
/** Student request creation handler and form, including optional image attachments. */
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/upload.php';
need_role('student');
active_write();
$back = ui_back_context('mine');
// New requests can select only active assistance types.
$types = $pdo->query('SELECT * FROM assistance_types WHERE is_active=1 ORDER BY name')->fetchAll();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (request_body_exceeds_post_max()) {
        http_response_code(413);
        exit('حجم الطلب المرسل يتجاوز الحد المسموح. قلل حجم الصور وأعد المحاولة.');
    }
    post_only();
    try {
        // Track moved files so they can be removed if the matching database transaction fails.
        $saved = [];
        $pdo->beginTransaction();
        if (!lock_active_student((int)me()['id'])) {
            throw new RuntimeException('الحساب لم يعد نشطًا؛ تعذر نشر الطلب.');
        }
        $title = trim(input_string('title'));
        $desc = trim(input_string('description'));
        $type = input_int('assistance_type_id');
        $typeCheck = $pdo->prepare('SELECT id FROM assistance_types WHERE id=? AND is_active=1');
        $typeCheck->execute([$type]);
        if (!$typeCheck->fetch()) {
            throw new RuntimeException('اختر نوع مساعدة متاحًا.');
        }
        $subject = trim(input_string('subject'));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180 || mb_strlen($desc) < 10 || mb_strlen($desc) > 3000 || mb_strlen($subject) > 180) {
            throw new RuntimeException('تحقق من طول العنوان والمادة والوصف (الوصف 10 إلى 3000 حرف).');
        }
        // Insert the request first so each subsequent attachment can reference its generated ID.
        $q = $pdo->prepare('INSERT INTO requests(owner_id,assistance_type_id,major,subject,title,description) VALUES(?,?,?,?,?,?)');
        $q->execute([me()['id'],$type,me()['major'],$subject !== '' ? $subject : null,$title,$desc]);
        $id = $pdo->lastInsertId();
        $files = $_FILES['images'] ?? null;
        if ($files !== null) {
            if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])
                || !isset($files['tmp_name'], $files['error'], $files['size'])
                || !is_array($files['tmp_name']) || !is_array($files['error']) || !is_array($files['size'])) {
                throw new RuntimeException('بيانات الصور غير صالحة.');
            }
            $names = $files['name'];
            if (count(array_filter($names, static fn($name) => is_string($name) && $name !== '')) > 3) {
                throw new RuntimeException('يمكن إرفاق ثلاث صور كحد أقصى.');
            }
            if (count($names) > 3) {
                throw new RuntimeException('يمكن إرفاق ثلاث صور كحد أقصى.');
            }
            for ($i = 0; $i < count($names); $i++) {
                if (!is_string($names[$i] ?? null)) {
                    throw new RuntimeException('بيانات الصور غير صالحة.');
                }
                if ($names[$i] === '') {
                    continue;
                }
                $one = [
                    'name' => $names[$i],
                    'tmp_name' => $files['tmp_name'][$i] ?? null,
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$i] ?? null,
                ];
                $im = save_image($one, 'requests');
                $saved[] = $im['path'];
                $pdo->prepare('INSERT INTO request_images(request_id,storage_path,mime_type,size_bytes,sort_order) VALUES(?,?,?,?,?)')->execute([$id,$im['path'],$im['mime'],$im['size'],$i]);
            }
        }
        // Alert active admins within the same transaction as the new request.
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $a) {
            notify($a['id'], 'new_request', 'طلب جديد', 'تم نشر طلب مساعدة جديد.', '/admin/request_view.php?id='.$id);
        }$pdo->commit();
        flash('تم نشر طلبك بنجاح. يمكن للطلاب الآن تقديم عروض للمساعدة في طلبك.');
        go('/requests/view.php?id='.$id);
    } catch (Throwable $e) {
        // Roll back related rows and remove any files that were moved before the failure.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }foreach (($saved ?? []) as $path) {
            $disk = __DIR__.'/..'.$path;
            if (is_file($disk)) {
                @unlink($disk);
            }
        }
        if (!($e instanceof RuntimeException) || $e instanceof PDOException) {
            error_log('[Ehtiyaj] Request creation failed: '.$e->getMessage());
        }
        $err = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'تعذر حفظ الطلب.';
    }
 }header_ui('إنشاء طلب');?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?=e(home_url())?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>إنشاء طلب</span></nav>
<?= page_back_link($back['url'], $back['label']) ?>
<header class="ux-page-header request-form-heading">
    <div><span class="eyebrow"><i class="bi bi-plus-circle"></i> طلب جديد</span><h1>إنشاء طلب مساعدة</h1><p>اشرح ما تحتاج إليه بوضوح؛ سيظهر طلبك للطلاب ويمكنهم إرسال عروضهم.</p></div>
</header>
<div class="request-form-layout">
    <aside class="request-form-aside">
        <span class="request-form-aside-icon"><i class="bi bi-lightbulb" aria-hidden="true"></i></span>
        <strong>طلب واضح يساعد الآخرين على مساعدتك</strong>
        <p>اذكر الموضوع وما الذي تحتاجه بالتحديد، وتجنب مشاركة معلوماتك الخاصة.</p>
    </aside>
    <div class="card request-form-card">
        <div class="request-form-card-heading"><span class="section-kicker">تفاصيل المساعدة</span><h2>معلومات الطلب</h2><p>الحقول المطلوبة موضحة بعلامة النجمة.</p></div>
        <?php if ($err) { echo '<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> '.e($err).'</div>'; } ?>
        <form method="post" enctype="multipart/form-data" class="request-form">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <section class="request-form-section" aria-labelledby="request-info-title">
                <div class="request-form-section-heading"><span class="request-form-step">01</span><div><h3 id="request-info-title">عن الطلب</h3><p>التصنيف الذي سيساعد الطلاب على العثور على احتياجك.</p></div></div>
                <div class="request-form-grid">
                    <div class="request-form-field"><label class="form-label" for="request-type">نوع المساعدة <span class="required" aria-hidden="true">*</span></label><select id="request-type" name="assistance_type_id" class="form-select" required><option value="">اختر نوعًا</option><?php foreach ($types as $t) {?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php }?></select></div>
                    <div class="request-form-field"><label class="form-label" for="request-subject">المادة <span class="optional-label">اختياري</span></label><input id="request-subject" name="subject" class="form-control" maxlength="180" placeholder="مثال: قواعد البيانات"></div>
                    <div class="request-form-field request-major-field"><label class="form-label" for="request-major">التخصص</label><input id="request-major" class="form-control" value="<?=e(me()['major'])?>" readonly><small class="form-text">يُسجل الطلب على تخصص حسابك.</small></div>
                </div>
            </section>
            <section class="request-form-section" aria-labelledby="request-description-title">
                <div class="request-form-section-heading"><span class="request-form-step">02</span><div><h3 id="request-description-title">اشرح ما تحتاجه</h3><p>عنوان مختصر ووصف يمنح الطالب سياقًا كافيًا.</p></div></div>
                <div class="request-form-field"><label class="form-label" for="request-title">عنوان الطلب <span class="required" aria-hidden="true">*</span></label><input id="request-title" name="title" class="form-control" minlength="3" maxlength="180" data-count="180" required aria-describedby="title-help"><div class="field-meta"><small id="title-help">اختر عنوانًا واضحًا يلخص احتياجك.</small><small><span data-count-output>0</span>/180</small></div></div>
                <div class="request-form-field"><label class="form-label" for="request-description">التفاصيل <span class="required" aria-hidden="true">*</span></label><textarea id="request-description" name="description" class="form-control" rows="6" minlength="10" maxlength="3000" data-count="3000" required aria-describedby="description-help"></textarea><div class="field-meta"><small id="description-help">اشرح ما جربته ونوع المساعدة المطلوبة، دون مشاركة معلومات خاصة.</small><small><span data-count-output>0</span>/3000</small></div></div>
            </section>
            <section class="request-form-section request-upload-section" aria-labelledby="request-images-title">
                <div class="request-form-section-heading"><span class="request-form-step">03</span><div><h3 id="request-images-title">صور توضيحية <span class="optional-label">اختياري</span></h3><p>أرفق صورًا تساعد على شرح الطلب، إن وجدت.</p></div></div>
                <label class="upload-dropzone" for="request-images"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span><strong>اختر الصور لإرفاقها</strong><small>JPEG أو PNG أو WEBP · حتى 3 صور · 2MB للصورة</small></span><span class="upload-choose">اختيار الصور</span></label>
                <input id="request-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-image-preview="request-image-preview" aria-describedby="request-images-help" class="form-control visually-hidden">
                <div id="request-images-help" class="field-meta upload-file-count"><small>يمكنك متابعة الصور المختارة قبل النشر.</small><small data-file-count>0/3</small></div>
                <div id="request-image-preview" class="image-preview-grid" aria-live="polite"></div>
            </section>
            <div class="request-form-actions"><span><i class="bi bi-shield-check" aria-hidden="true"></i> راجع التفاصيل قبل نشر الطلب</span><button class="btn btn-primary" type="submit"><i class="bi bi-send" aria-hidden="true"></i> نشر الطلب</button></div>
        </form>
    </div>
</div>
<?php footer_ui();?>