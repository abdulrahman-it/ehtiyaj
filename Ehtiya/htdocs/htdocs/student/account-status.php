<?php
/** Read-only status explanation for a suspended student account. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
header_ui('حالة الحساب');?><?= page_back_link(home_url(), 'الرئيسية') ?><section class="card student-state-card"><span class="student-state-icon"><i class="bi bi-person-lock" aria-hidden="true"></i></span><span class="section-kicker">حالة الحساب</span><h1>الحساب موقوف مؤقتًا</h1><p>يمكنك تصفح المعلومات المسموح بها، لكن لا يمكنك تنفيذ عمليات مثل إنشاء الطلبات أو تقديم العروض أو إرسال الرسائل.</p><p>للاستفسار راجع إدارة المنصة.</p></section><?php footer_ui();?>