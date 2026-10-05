<?php
/** Public landing page; authenticated users are directed to their role-specific workspace. */
require __DIR__ . '/includes/bootstrap.php';
header_ui('الرئيسية');
?>
<section class="landing-hero">
    <div class="landing-hero-copy">
        <span class="eyebrow landing-eyebrow"><i class="bi bi-mortarboard"></i> مجتمع طلابي مجاني</span>
        <h1>المعرفة أجمل<br>حين نتشاركها.</h1>
        <p>احتياج يقرّب الطلاب لمشاركة المساعدة التعليمية والتقنية — ببساطة، وبخصوصية.</p>
        <div class="landing-actions">
            <?php if (me()): ?>
                <a class="btn btn-primary" href="<?= e(app_url('student/index.php')) ?>">اذهب إلى مساحتك <i class="bi bi-arrow-left"></i></a>
                <a class="landing-secondary-link" href="<?= e(app_url('requests/index.php')) ?>">تصفح طلبات المساعدة <i class="bi bi-arrow-left"></i></a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">ابدأ الآن <i class="bi bi-arrow-left"></i></a>
                <a class="landing-secondary-link" href="<?= e(app_url('about.php')) ?>">كيف تعمل احتياج؟</a>
            <?php endif; ?>
        </div>
        <div class="landing-trust"><span><i class="bi bi-check-circle"></i> مساعدة مجانية</span><span><i class="bi bi-shield-check"></i> تواصل بخصوصية</span></div>
    </div>
    <div class="landing-art" aria-hidden="true">
        <div class="landing-orbit orbit-one"></div><div class="landing-orbit orbit-two"></div>
        <span class="art-bubble bubble-book"><i class="bi bi-book-half"></i></span>
        <span class="art-bubble bubble-chat"><i class="bi bi-chat-left-text"></i></span>
        <span class="art-bubble bubble-star"><i class="bi bi-stars"></i></span>
        <div class="art-center"><span class="art-center-mark">ا</span><span>نتعلّم معًا</span></div>
    </div>
</section>

<section class="how-section" aria-labelledby="how-title">
    <div class="section-intro"><span class="section-kicker">كيف تعمل المنصة؟</span><h2 id="how-title">ثلاث خطوات للتعاون</h2><p>من احتياجك إلى مساعدة واضحة ومحادثة خاصة.</p></div>
    <div class="how-steps">
        <article class="how-step"><span class="step-number">01</span><span class="step-icon"><i class="bi bi-pencil-square"></i></span><h3>اكتب ما تحتاجه</h3><p>أنشئ طلبًا يشرح احتياجك الدراسي أو التقني.</p></article>
        <article class="how-step"><span class="step-number">02</span><span class="step-icon"><i class="bi bi-hand-thumbs-up"></i></span><h3>اختر المساعدة</h3><p>راجع عروض الطلاب واختر من يساعدك.</p></article>
        <article class="how-step"><span class="step-number">03</span><span class="step-icon"><i class="bi bi-chat-dots"></i></span><h3>تواصل وتعاون</h3><p>تحدثا في محادثة خاصة بعد قبول العرض.</p></article>
    </div>
</section>

<section class="landing-cta">
    <div><span class="section-kicker">مجتمع يتشارك المعرفة</span><h2>خطوتك الأولى تبدأ من هنا.</h2><p>أنشئ حسابًا طالبًا وابدأ في طلب المساعدة أو تقديمها.</p></div>
    <?php if (me()): ?>
        <a class="btn btn-primary" href="<?= e(app_url('student/index.php')) ?>">افتح مساحتك <i class="bi bi-arrow-left"></i></a>
    <?php else: ?>
        <a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب طالب <i class="bi bi-arrow-left"></i></a>
    <?php endif; ?>
</section>
<?php footer_ui(); ?>
