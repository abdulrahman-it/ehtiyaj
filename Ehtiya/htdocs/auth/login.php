<?php
/** Authenticate a user, apply file-backed throttling, then establish a regenerated session. */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/login_throttle.php';

$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $email = trim(input_string('email'));
    $withinEmailLimit = strlen($email) <= 190;
    if (!$withinEmailLimit) {
        $email = substr($email, 0, 190);
    }
    $password = input_string('password');
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : 'unknown';
    $pairKey = 'pair|' . $ip . '|' . strtolower($email);
    $ipKey = 'ip|' . $ip;
    // Apply both per-account/IP-pair and per-IP limits before consulting credentials.
    $pairWait = login_throttle_state($pairKey, 10, 900);
    $ipWait = login_throttle_state($ipKey, 60, 900);
    $wait = max($pairWait, $ipWait);

    if ($wait > 0) {
        http_response_code(429);
        header('Retry-After: ' . $wait);
        flash('تعذر تسجيل الدخول. تحقق من البريد وكلمة المرور أو حالة الحساب، ثم حاول لاحقًا.', 'danger');
    } else {
        $user = null;
        if ($withinEmailLimit) {
            $statement = $pdo->prepare('SELECT id,email,password_hash,role,status FROM users WHERE email = ?');
            $statement->execute([$email]);
            $user = $statement->fetch() ?: null;
        }
        // Verify a fixed dummy hash for unknown addresses to reduce timing-based account discovery.
        $hash = is_array($user) ? (string)$user['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordMatches = strlen($password) <= 4096 && password_verify($password, $hash);

        if ($user && $user['status'] !== 'deactivated' && $passwordMatches) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $user['id'];
            login_throttle_state($pairKey, 10, 900, 'reset');
            login_throttle_state($ipKey, 60, 900, 'reset');
            login_throttle_cleanup();
            go($user['role'] === 'admin' ? '/admin/index.php' : '/student/index.php');
        }
        login_throttle_state($pairKey, 10, 900, 'record');
        login_throttle_state($ipKey, 60, 900, 'record');
        login_throttle_cleanup();
        flash('تعذر تسجيل الدخول. تحقق من البريد وكلمة المرور أو حالة الحساب.', 'danger');
    }
}

header_ui('تسجيل الدخول', true);
?>
<section class="auth-layout auth-login-layout">
    <aside class="auth-aside">
        <span class="auth-aside-mark"><i class="bi bi-mortarboard"></i></span>
        <span class="eyebrow">مجتمع احتياج</span>
        <h1>معًا، تصبح<br>الدراسة أسهل.</h1>
        <p>سجل دخولك لمتابعة طلباتك والتواصل مع زملائك.</p>
        <div class="auth-benefits">
            <span><i class="bi bi-check-circle"></i> دعم طلابي مجاني</span>
            <span><i class="bi bi-shield-check"></i> محادثات خاصة بعد قبول العرض</span>
        </div>
        <span class="auth-decoration" aria-hidden="true">احتياج</span>
    </aside>
    <div class="auth-form-panel">
        <div class="auth-heading">
            <span class="section-kicker">مساحة الطالب</span>
            <h2>تسجيل الدخول</h2>
            <p>سجّل دخولك للوصول إلى حسابك وطلباتك ومحادثاتك.</p>
        </div>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <div class="mb-3">
                <label class="form-label" for="login-email">البريد الإلكتروني</label>
                <input id="login-email" class="form-control" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($email) ?>" placeholder="name@example.com" required>
            </div>
            <div class="mb-4">
                <label class="form-label" for="login-password">كلمة المرور</label>
                <div class="password-wrap">
                    <input id="login-password" class="form-control" name="password" type="password" autocomplete="current-password" placeholder="أدخل كلمة المرور" required>
                    <button class="password-toggle" type="button" data-password-target="login-password" aria-label="إظهار كلمة المرور"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button class="btn btn-primary w-100 auth-submit" type="submit">تسجيل الدخول <i class="bi bi-arrow-left"></i></button>
        </form>
        <p class="auth-switch">ليس لديك حساب؟ <a href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب طالب</a></p>
        <p class="auth-home-return"><a href="<?= e(app_url('index.php')) ?>"><i class="bi bi-arrow-right" aria-hidden="true"></i> العودة إلى الرئيسية</a></p>
    </div>
</section>
<?php footer_ui(true); ?>
