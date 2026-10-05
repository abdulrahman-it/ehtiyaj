<?php
/** Student deactivation page; records remain stored while the current session is ended. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
active_write();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $reason = trim(input_string('reason'));
    if (!$reason) {
        flash('سبب تعطيل الحساب مطلوب.', 'danger');
    } else {
        // Retain the user and related records; transition only this active account and then terminate its session.
        $deactivate = $pdo->prepare("UPDATE users SET status='deactivated',deactivation_reason=?,deactivated_at=NOW() WHERE id=? AND status='active'");
        $deactivate->execute([$reason,me()['id']]);
        if ($deactivate->rowCount() === 1) {
            session_destroy();
            go('/');
        }
        flash('تغيرت حالة الحساب، ولم يتم تطبيق التعطيل.', 'warning');
    }
}header_ui('تعطيل الحساب');?><?= page_back_link(app_url('student/profile.php'), 'الملف الشخصي') ?><section class="card student-state-card student-deactivate-card"><span class="student-state-icon danger-state-icon"><i class="bi bi-person-x" aria-hidden="true"></i></span><span class="section-kicker">إدارة الحساب</span><h1>تعطيل الحساب</h1><p>التعطيل مؤقت من ناحية البيانات، ولا تحذف علاقاتك وطلباتك تلقائيًا. لن تتمكن من تسجيل الدخول.</p><form method="post" data-confirm="تعطيل الحساب؟ لن تتمكن من تسجيل الدخول."><input type="hidden" name="csrf" value="<?=e(csrf())?>"><label class="form-label" for="deactivation-reason">سبب التعطيل</label><textarea id="deactivation-reason" name="reason" class="form-control mb-3" required></textarea><button class="btn btn-danger"><i class="bi bi-person-x" aria-hidden="true"></i> تعطيل الحساب</button></form></section><?php footer_ui();?>