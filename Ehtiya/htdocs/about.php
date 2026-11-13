<?php
/** Public explanation of the student-help workflow. */
require __DIR__ . '/includes/bootstrap.php';
$viewer = me();
header_ui('عن احتياج');
?>
<article class="card p-4 p-md-5 trust-page">
    <span class="pill align-self-start">عن المنصة</span>
    <h1 class="mt-3">احتياج مساحة للتعاون الطلابي</h1>
    <p class="lead">منصة مساعدة الطلاب مجانًا لطلب المساعدة الأكاديمية والتقنية وتقديمها، وتبادل المعرفة بين الطلاب.</p>
    <section><h2>لماذا احتياج؟</h2><p>قد يحتاج الطالب إلى شرح فكرة أو مراجعة عمل ولا يعرف من يستطيع مساعدته. تهدف احتياج إلى تسهيل الوصول بين الطلاب الذين لديهم احتياج ومن يرغبون في مشاركة معرفتهم وخبراتهم.</p></section>
    <section><h2>كيف تساعد المنصة؟</h2><p>ينشر الطالب احتياجه ويستقبل عروضًا من طلاب آخرين. بعد قبول عرض، يمكن للطرفين التواصل في محادثة مرتبطة بالطلب. لا تتضمن المنصة مدفوعات أو عمليات تجارية.</p></section>
    <section><h2>قيمة للمجتمع الطلابي</h2><p>تتيح المنصة تبادل المعرفة والتعاون على الدراسة والمشاريع؛ وتعتمد فائدة المساعدة على مشاركة الطلاب وما يقدمه كل طرف.</p></section>
    <section><h2>كيف أبدأ؟</h2><ol><li>أنشئ حساب طالب وحدد تخصصك الدراسي.</li><li>انشر طلب مساعدة أو تصفح الطلبات المتاحة.</li><li>قدّم عرضًا أو اقبل عرضًا مناسبًا، ثم تابع التواصل عبر المحادثة.</li></ol></section>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if (!$viewer): ?><a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب طالب</a>
        <?php elseif ($viewer['role'] === 'student'): ?><a class="btn btn-primary" href="<?= e(app_url('requests/index.php')) ?>">تصفح الطلبات</a>
        <?php else: ?><a class="btn btn-primary" href="<?= e(app_url('admin/index.php')) ?>">لوحة الإدارة</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= e(app_url('faq.php')) ?>">الأسئلة الشائعة</a>
    </div>
</article>
<?php footer_ui(); ?>
