<?php
/** Public landing page; student-only request previews retain the existing access rules. */
require __DIR__ . '/includes/bootstrap.php';
$viewer = me();
$latestRequests = [];
$latestError = false;
if ($viewer && $viewer['role'] === 'student') {
    // Match the protected student listing: published, receiving offers, and not the viewer's own.
    need_role('student');
    try {
        $latestQuery = $pdo->prepare("SELECT r.id, r.title, r.major, r.created_at, t.name AS type_name
            FROM requests r
            JOIN users u ON u.id = r.owner_id
            JOIN assistance_types t ON t.id = r.assistance_type_id
            WHERE r.moderation_status = 'published'
              AND r.status = 'receiving_offers'
              AND r.owner_id <> ?
            ORDER BY r.created_at DESC, r.id DESC LIMIT 3");
        $latestQuery->execute([(int)$viewer['id']]);
        $latestRequests = $latestQuery->fetchAll();
    } catch (Throwable $exception) {
        error_log('[Ehtiyaj] Landing request preview failed: ' . $exception->getMessage());
        $latestError = true;
    }
}
header_ui('الرئيسية');
?>
<section class="landing-hero">
    <div class="landing-hero-copy">
        <span class="eyebrow landing-eyebrow"><i class="bi bi-mortarboard" aria-hidden="true"></i> مجتمع طلابي مجاني</span>
        <h1>احتياجك الدراسي له حل، ومساعدتك تصنع فرقًا</h1>
        <p>منصة طلابية تجمع من يحتاج المساعدة بمن يستطيع تقديمها، لتبادل الخبرات والمعرفة ومساندة بعضنا في الدراسة والمشاريع.</p>
        <div class="landing-actions">
            <?php if ($viewer && $viewer['role'] === 'student'): ?>
                <a class="btn btn-primary" href="<?= e(app_url('requests/create.php')) ?>">أنشئ احتياجك الآن <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
                <a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php')) ?>">قدّم المساعدة <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
            <?php elseif ($viewer && $viewer['role'] === 'admin'): ?>
                <a class="btn btn-primary" href="<?= e(app_url('admin/index.php')) ?>">اذهب إلى لوحة الإدارة <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">أنشئ احتياجك الآن <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
                <a class="btn btn-outline-primary" href="<?= e(app_url('auth/register.php')) ?>">قدّم المساعدة <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
                <span>لديك حساب؟ <a class="landing-secondary-link" href="<?= e(app_url('auth/login.php')) ?>">سجّل الدخول</a></span>
            <?php endif; ?>
        </div>
        <div class="landing-trust"><span><i class="bi bi-check-circle" aria-hidden="true"></i> مساعدة مجانية</span><span><i class="bi bi-shield-check" aria-hidden="true"></i> تواصل بخصوصية بعد قبول العرض</span></div>
    </div>
    <div class="landing-art" aria-hidden="true">
        <div class="landing-orbit orbit-one"></div><div class="landing-orbit orbit-two"></div>
        <span class="art-bubble bubble-book"><i class="bi bi-book-half"></i></span>
        <span class="art-bubble bubble-chat"><i class="bi bi-chat-left-text"></i></span>
        <span class="art-bubble bubble-star"><i class="bi bi-stars"></i></span>
        <div class="art-center"><span class="art-center-mark">ا</span><span>نتعلّم معًا</span></div>
    </div>
</section>

<section class="how-section" aria-labelledby="examples-title">
    <div class="section-intro"><span class="section-kicker">ماذا يمكنك أن تطلب؟</span><h2 id="examples-title">احتياجات تبدأ بها الفكرة</h2><p>أمثلة توضيحية لما يمكن مشاركته، وليست طلبات منشورة.</p></div>
    <ul class="row g-3 list-unstyled mb-0">
        <?php foreach (['أحتاج مساعدة في مشروع تخرج.', 'أبحث عن شرح لمادة برمجة.', 'أحتاج مراجعة تصميم واجهة مستخدم.', 'أبحث عن شخص يساعدني في الرياضيات.', 'أحتاج مساعدة في إعداد بحث أكاديمي.'] as $example): ?>
            <li class="col-12 col-md-6 col-lg-4"><div class="card h-100 p-3"><span class="d-flex align-items-start gap-2"><i class="bi bi-chat-square-text text-primary" aria-hidden="true"></i><span><?= e($example) ?></span></span></div></li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="how-section" aria-labelledby="how-title">
    <div class="section-intro"><span class="section-kicker">كيف تعمل المنصة؟</span><h2 id="how-title">ثلاث خطوات للتعاون</h2><p>من وصف احتياجك إلى التعاون مع زملائك.</p></div>
    <div class="how-steps">
        <article class="how-step"><span class="step-number">01</span><span class="step-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span><h3>أنشئ احتياجك</h3><p>اكتب طلبًا يشرح ما تحتاج إليه في الدراسة أو المشاريع.</p></article>
        <article class="how-step"><span class="step-number">02</span><span class="step-icon"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i></span><h3>استقبل المساعدة</h3><p>راجع عروض الطلاب وتواصل بعد قبول العرض المناسب.</p></article>
        <article class="how-step"><span class="step-number">03</span><span class="step-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span><h3>أنجز احتياجك</h3><p>تابع المساعدة مع زميلك حتى الوصول إلى النتيجة المطلوبة.</p></article>
    </div>
</section>

<section class="how-section" aria-labelledby="latest-title">
    <div class="section-intro"><span class="section-kicker">مجتمع احتياج</span><h2 id="latest-title">أحدث الاحتياجات المتاحة</h2><p>طلبات منشورة تستقبل عروض المساعدة حاليًا.</p></div>
    <?php if (!$viewer): ?>
        <div class="card p-4"><p class="mb-3">سجّل الدخول أو أنشئ حساب طالب لتصفح الاحتياجات المنشورة وتقديم المساعدة.</p><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب طالب</a><a class="btn btn-outline-primary" href="<?= e(app_url('auth/login.php')) ?>">تسجيل الدخول</a></div></div>
    <?php elseif ($viewer['role'] === 'student'): ?>
        <?php if ($latestError): ?>
            <div class="alert alert-warning" role="alert">تعذر تحميل أحدث الاحتياجات. حاول مرة أخرى لاحقًا.</div>
        <?php elseif (!$latestRequests): ?>
            <div class="card p-4"><p class="mb-0">لا توجد احتياجات متاحة لك تستقبل عروضًا حاليًا. يمكنك زيارة الطلبات لاحقًا أو إنشاء احتياجك.</p></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($latestRequests as $request): ?>
                    <div class="col-12 col-md-6 col-lg-4"><article class="card h-100 p-3"><span class="section-kicker"><?= e($request['type_name']) ?></span><h3 class="h5 mt-2 text-break"><?= e($request['title']) ?></h3><p class="text-secondary text-break mb-2">التخصص: <?= e($request['major']) ?></p><p class="mb-2"><?= status_badge('receiving_offers') ?></p><time class="text-secondary small mb-3" datetime="<?= e(date(DATE_ATOM, strtotime($request['created_at']))) ?>">نُشر: <?= e(date('Y-m-d', strtotime($request['created_at']))) ?></time><a class="btn btn-outline-primary align-self-start mt-auto" href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$request['id'], 'return_to' => 'public'])) ?>">عرض الاحتياج</a></article></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <a class="btn btn-outline-primary mt-3" href="<?= e(app_url('requests/index.php')) ?>">استعرض جميع الاحتياجات المتاحة <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <?php else: ?>
        <div class="card p-4"><p class="mb-3">راجع الطلبات المنشورة وجميع حالات الطلبات من لوحة الإدارة.</p><a class="btn btn-outline-primary align-self-start" href="<?= e(app_url('admin/requests.php')) ?>">عرض طلبات الإدارة</a></div>
    <?php endif; ?>
</section>

<section class="landing-cta">
    <div><span class="section-kicker">مجتمع يتشارك المعرفة</span><h2>خطوتك الأولى تبدأ من هنا.</h2><p><?= $viewer ? 'واصل رحلتك في احتياج من مساحتك.' : 'أنشئ حساب طالب لطلب المساعدة أو تقديمها.' ?></p></div>
    <?php if ($viewer): ?>
        <a class="btn btn-primary" href="<?= e(home_url($viewer)) ?>">افتح مساحتك <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <?php else: ?>
        <a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب طالب <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <?php endif; ?>
</section>
<?php footer_ui(); ?>
