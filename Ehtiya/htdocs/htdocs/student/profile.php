<?php
/** Student-owned profile and password forms, with writes limited to active accounts. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
// Dispatch only the existing profile/password actions and preserve their current form contracts.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    active_write();
    $a = input_string('action');
    if ($a === 'profile') {
        $name = trim(input_string('full_name'));
        $phone = trim(input_string('phone'));
        $emailVisibility = input_string('email_visibility');
        $phoneVisibility = input_string('phone_visibility');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || mb_strlen($phone) > 30) {
            flash('تحقق من الاسم (3 إلى 120 حرفًا) ورقم الهاتف (30 حرفًا كحد أقصى).', 'danger');
        } else {
            $pdo->prepare("UPDATE users SET full_name=?,phone=?,email_visibility=?,phone_visibility=? WHERE id=? AND status='active'")->execute([$name,$phone ?: null,in_array($emailVisibility, ['private','accepted_only'], true) ? $emailVisibility : 'private',in_array($phoneVisibility, ['private','accepted_only'], true) ? $phoneVisibility : 'private',me()['id']]);
            flash('تم تحديث الملف الشخصي.');
        }
    } elseif ($a === 'password') {
        $pw = input_string('password');
        if (!password_policy_valid($pw) || $pw !== input_string('confirm')) {
            flash('استخدم كلمة مرور من 8 أحرف على الأقل (حتى 64 بايتًا) وتأكد من تطابقها.', 'danger');
        } else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND status='active'")->execute([password_hash($pw, PASSWORD_DEFAULT),me()['id']]);
            flash('تم تغيير كلمة المرور.');
        }
    }go('/student/profile.php');
}$u = me();
header_ui('الملف الشخصي');?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?=e(app_url('student/index.php'))?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>حسابي / الملف الشخصي</span></nav>
<?php if ($u['status'] === 'suspended') {?><div class="account-notice" role="status"><i class="bi bi-lock"></i><div><strong>الملف للقراءة فقط</strong><span>الحساب موقوف مؤقتًا، لذلك لا يمكن تعديل البيانات حتى استعادة النشاط.</span></div></div><?php }?>
<div class="profile-layout">
 <section class="profile-main-section"><div class="profile-section-heading"><div class="profile-identity"><span class="profile-avatar" aria-hidden="true"><?=e(mb_substr($u['full_name'], 0, 1))?></span><div class="profile-identity-copy"><span class="section-kicker">حسابك في احتياج</span><h1>الملف الشخصي</h1><p class="profile-person-name"><?=e($u['full_name'])?><span><?=e($u['major'])?></span></p></div><span class="profile-status"><?=status_badge($u['status'])?></span></div><p class="profile-section-intro">حدّث اسمك وطرق التواصل التي ترغب بمشاركتها بعد قبول المساعدة.</p></div>
  <form method="post" class="profile-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="profile"><fieldset class="profile-fields" <?=$u['status'] !== 'active' ? 'disabled' : ''?>><section class="profile-data-group" aria-labelledby="profile-account-title"><h2 id="profile-account-title">معلومات الحساب</h2><div class="profile-field-grid">
   <div><label class="form-label" for="profile-name">الاسم الكامل</label><input id="profile-name" name="full_name" class="form-control" maxlength="120" value="<?=e($u['full_name'])?>" required></div>
   <div><label class="form-label" for="profile-email">البريد الإلكتروني <span class="optional-label">للقراءة فقط</span></label><input id="profile-email" class="form-control" value="<?=e($u['email'])?>" readonly></div>
   <div><label class="form-label" for="profile-phone">الهاتف</label><input id="profile-phone" name="phone" class="form-control" maxlength="30" value="<?=e($u['phone'])?>" placeholder="أضف رقم الهاتف عند الحاجة"></div>
  </div></section><section class="profile-data-group" aria-labelledby="profile-student-title"><h2 id="profile-student-title">المعلومات الدراسية والخصوصية</h2><div class="profile-field-grid">
   <div><label class="form-label" for="profile-major">التخصص <span class="optional-label">للقراءة فقط</span></label><input id="profile-major" class="form-control" value="<?=e($u['major'])?>" readonly></div>
   <div><label class="form-label" for="email-visibility">خصوصية البريد الإلكتروني</label><select id="email-visibility" name="email_visibility" class="form-select"><option value="private" <?=$u['email_visibility'] === 'private' ? 'selected' : ''?>>خاص دائمًا</option><option value="accepted_only" <?=$u['email_visibility'] === 'accepted_only' ? 'selected' : ''?>>يظهر للطرف في مساعدة مقبولة</option></select></div>
   <div><label class="form-label" for="phone-visibility">خصوصية الهاتف</label><select id="phone-visibility" name="phone_visibility" class="form-select"><option value="private" <?=$u['phone_visibility'] === 'private' ? 'selected' : ''?>>خاص دائمًا</option><option value="accepted_only" <?=$u['phone_visibility'] === 'accepted_only' ? 'selected' : ''?>>يظهر للطرف في مساعدة مقبولة</option></select></div>
  </div></section><div class="profile-form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> حفظ التغييرات</button></div></fieldset></form>
 </section>
 <aside class="profile-password-section"><span class="profile-lock-icon"><i class="bi bi-shield-lock"></i></span><h2>تغيير كلمة المرور</h2><p>اختر كلمة مرور جديدة من 8 أحرف على الأقل، ولا تشاركها مع الآخرين.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="password"><fieldset class="profile-fields" <?=$u['status'] !== 'active' ? 'disabled' : ''?>><div class="profile-password-field"><label class="form-label" for="profile-password">كلمة المرور الجديدة</label><div class="password-wrap mb-2"><input id="profile-password" type="password" name="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="64" placeholder="8 أحرف على الأقل" required><button class="password-toggle" type="button" data-password-target="profile-password" aria-label="إظهار كلمة المرور"><i class="bi bi-eye"></i></button></div></div><div class="profile-password-field"><label class="form-label" for="profile-password-confirm">تأكيد كلمة المرور</label><div class="password-wrap mb-3"><input id="profile-password-confirm" type="password" name="confirm" class="form-control" data-match="#profile-password" minlength="8" maxlength="64" placeholder="أعد كتابة كلمة المرور" required><button class="password-toggle" type="button" data-password-target="profile-password-confirm" aria-label="إظهار تأكيد كلمة المرور"><i class="bi bi-eye"></i></button></div></div><button class="btn btn-outline-primary" type="submit">تحديث كلمة المرور</button></fieldset></form>
 </aside>
</div>
<?php footer_ui();?>