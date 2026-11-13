<?php
/** Public information on currently available help channels. */
require __DIR__ . '/includes/bootstrap.php';
header_ui('التواصل');
?>
<article class="card p-4 p-md-5 trust-page">
    <span class="pill align-self-start">المساعدة</span><h1 class="mt-3">التواصل والمساعدة</h1>
    <p class="lead">لا تتوفر في التطبيق حاليًا بيانات تواصل رسمية مؤكدة، مثل بريد إلكتروني أو رقم هاتف للدعم أو للخصوصية.</p>
    <section><h2>الإبلاغ عن مشكلة في محادثة</h2><p>إذا كنت طرفًا في محادثة ولديك حساب طالب نشط، يمكنك فتح المحادثة واختيار «الإبلاغ عن مشكلة» وكتابة السبب. تُرسل البلاغات إلى الإدارة لمراجعتها. هذه الآلية مخصصة للمحادثات وليست نموذج تواصل عام أو طلبًا لحذف البيانات.</p></section>
    <section><h2>معلومات إضافية</h2><p>للتعرف على طريقة الاستخدام وما يظهر من بيانات، راجع <a href="<?= e(app_url('faq.php')) ?>">الأسئلة الشائعة</a> و<a href="<?= e(app_url('privacy.php')) ?>">سياسة الخصوصية</a>.</p></section>
</article>
<?php footer_ui(); ?>
