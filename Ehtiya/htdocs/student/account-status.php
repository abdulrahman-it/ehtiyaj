<?php
/** Account status information for an authenticated student. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
header_ui('حالة الحساب');?><?= page_back_link(home_url(), 'الرئيسية') ?><section class="card student-state-card"><span class="student-state-icon"><i class="bi bi-person-lock" aria-hidden="true"></i></span><span class="section-kicker">حالة الحساب</span><h1>حالة الحساب</h1><p>لا يمكن للحساب الموقوف تسجيل الدخول أو استخدام الصفحات المحمية حتى استعادة نشاطه.</p><p>للاستفسار راجع إدارة المنصة.</p></section><?php footer_ui();?>