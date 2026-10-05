<?php
/** Validate and create a student account; field names and constraints mirror the registration form. */
require __DIR__ . '/../includes/bootstrap.php';

$errors = [];
$values = ['full_name' => '', 'email' => '', 'major' => '', 'phone' => ''];
$majors = active_majors();
// Keep submitted values for the form while validating each field server-side.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    foreach (array_keys($values) as $field) {
        $values[$field] = trim(input_string($field));
    }
    $password = input_string('password');
    $confirmation = input_string('confirm_password');
    if (mb_strlen($values['full_name']) < 3 || mb_strlen($values['full_name']) > 120) {
        $errors[] = 'الاسم يجب أن يكون بين 3 و120 حرفًا.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 190) {
        $errors[] = 'أدخل بريدًا إلكترونيًا صالحًا.';
    }
    if ($values['major'] === '' || !is_active_major($values['major'])) {
        $errors[] = 'اختر تخصصًا معتمدًا ونشطًا.';
    }
    if (mb_strlen($values['phone']) > 30) {
        $errors[] = 'رقم الهاتف يجب ألا يتجاوز 30 حرفًا.';
    }
    if (!password_policy_valid($password)) {
        $errors[] = 'استخدم كلمة مرور بطول 8 أحرف على الأقل وحتى 64 بايتًا.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'تأكيد كلمة المرور غير مطابق.';
    }
    // The unique email constraint remains authoritative; this pre-check gives the common case a friendly message.
    if (!$errors) {
        $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$values['email']]);
        if ($statement->fetch()) {
            $errors[] = 'تعذر إنشاء الحساب بهذه البيانات. تحقق منها وحاول مرة أخرى.';
        }
    }
    if (!$errors) {
        try {
            // Store only the password hash, then regenerate the session ID before signing the new user in.
            $statement = $pdo->prepare('INSERT INTO users(full_name, email, password_hash, phone, major) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([
                $values['full_name'],
                $values['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $values['phone'] !== '' ? $values['phone'] : null,
                $values['major'],
            ]);
            session_regenerate_id(true);
            $_SESSION['uid'] = $pdo->lastInsertId();
            go('/student/index.php');
        } catch (PDOException $exception) {
            $driverCode = (int)($exception->errorInfo[1] ?? 0);
            if ($exception->getCode() === '23000' && $driverCode === 1062) {
                $errors[] = 'تعذر إنشاء الحساب بهذه البيانات. تحقق منها وحاول مرة أخرى.';
            } else {
                throw $exception;
            }
        }
    }
}

header_ui('إنشاء حساب', true);
?>
<section class="auth-layout auth-register-layout">
    <aside class="auth-aside">
        <span class="auth-aside-mark"><i class="bi bi-people"></i></span>
        <span class="eyebrow">أهلًا بك في احتياج</span>
        <h1>تعلّم، شارك،<br>وساعد زملاءك.</h1>
        <p>أنشئ حسابك الطلابي وابدأ بطلب المعرفة أو تقديمها.</p>
        <div class="auth-benefits">
            <span><i class="bi bi-check-circle"></i> مساحة طلابية مجانية</span>
            <span><i class="bi bi-shield-check"></i> تحكم في خصوصية بياناتك</span>
            <span><i class="bi bi-chat-dots"></i> تواصل بعد قبول المساعدة</span>
        </div>
        <span class="auth-decoration" aria-hidden="true">احتياج</span>
    </aside>

    <div class="auth-form-panel auth-register-panel">
        <div class="auth-heading">
            <span class="section-kicker">حساب جديد</span>
            <h2>إنشاء حساب جديد</h2>
            <p>أنشئ حسابك وابدأ في طلب المساعدة أو تقديمها للطلاب.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger auth-errors" role="alert">
                <strong><i class="bi bi-exclamation-circle"></i> راجع البيانات التالية:</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <section class="auth-form-group" aria-labelledby="register-basic-title">
                <div class="auth-form-group-heading"><span class="auth-group-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><div><h3 id="register-basic-title">المعلومات الأساسية</h3><p>الاسم والبريد والتخصص الدراسي.</p></div></div>
                <div class="auth-fields-grid">
                <div>
                    <label class="form-label" for="register-name">الاسم الكامل</label>
                    <input id="register-name" name="full_name" class="form-control" minlength="3" maxlength="120" autocomplete="name" value="<?= e($values['full_name']) ?>" placeholder="الاسم الذي سيظهر للطلاب" required>
                </div>
                <div>
                    <label class="form-label" for="register-major">التخصص</label>
                    <select id="register-major" name="major" class="form-select" required>
                        <option value="">اختر تخصصك</option>
                        <?php foreach ($majors as $major): ?>
                            <option value="<?= e($major) ?>" <?= $values['major'] === $major ? 'selected' : '' ?>><?= e($major) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="register-email">البريد الإلكتروني</label>
                    <input id="register-email" name="email" type="email" class="form-control" maxlength="190" autocomplete="email" value="<?= e($values['email']) ?>" placeholder="name@example.com" required>
                </div>
                <div>
                    <label class="form-label" for="register-phone">الهاتف <span class="optional-label">اختياري</span></label>
                    <input id="register-phone" name="phone" class="form-control" maxlength="30" autocomplete="tel" value="<?= e($values['phone']) ?>" placeholder="رقم الهاتف">
                </div>
                </div>
            </section>
            <section class="auth-form-group auth-security-group" aria-labelledby="register-security-title">
                <div class="auth-form-group-heading"><span class="auth-group-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><div><h3 id="register-security-title">أمان الحساب</h3><p>أنشئ كلمة مرور يصعب تخمينها وأكدها.</p></div></div>
                <div class="auth-fields-grid auth-password-grid">
                <div>
                    <label class="form-label" for="reg-password">كلمة المرور</label>
                    <div class="password-wrap">
                        <input id="reg-password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="64" aria-describedby="password-help" required>
                        <button class="password-toggle" type="button" data-password-target="reg-password" aria-label="إظهار كلمة المرور"><i class="bi bi-eye"></i></button>
                    </div>
                    <small id="password-help" class="form-help">8 أحرف على الأقل، حتى 64 بايتًا. يمكن استخدام الحروف والرموز والمسافات.</small>
                </div>
                <div>
                    <label class="form-label" for="reg-confirm">تأكيد كلمة المرور</label>
                    <div class="password-wrap">
                        <input id="reg-confirm" name="confirm_password" type="password" class="form-control" data-match="#reg-password" autocomplete="new-password" required>
                        <button class="password-toggle" type="button" data-password-target="reg-confirm" aria-label="إظهار تأكيد كلمة المرور"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                </div>
            </section>
            <button class="btn btn-primary w-100 auth-submit" type="submit">إنشاء الحساب <i class="bi bi-arrow-left"></i></button>
        </form>
        <p class="auth-switch">لديك حساب بالفعل؟ <a href="<?= e(app_url('auth/login.php')) ?>">سجّل الدخول</a></p>
        <p class="auth-home-return"><a href="<?= e(app_url('index.php')) ?>"><i class="bi bi-arrow-right" aria-hidden="true"></i> العودة إلى الرئيسية</a></p>
    </div>
</section>
<?php footer_ui(true); ?>
