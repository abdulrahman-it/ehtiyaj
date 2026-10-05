<?php
/**
 * Application bootstrap and shared view helpers.
 * Keep authentication, request safety and layout primitives centralized here.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/majors.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf('[Ehtiyaj] Uncaught %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.';
    exit;
});

$httpsOn = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)(getenv('APP_HTTPS') ?: '') === '1';
$serverHostValue = $_SERVER['SERVER_NAME'] ?? '';
$serverHost = is_string($serverHostValue) ? strtolower(trim($serverHostValue)) : '';
if (preg_match('/^\[([a-f0-9:]+)\](?::[0-9]{1,5})?$/iD', $serverHost, $hostMatch)) {
    $serverHost = strtolower($hostMatch[1]);
} else {
    $serverHost = preg_replace('/:[0-9]{1,5}$/D', '', $serverHost) ?? $serverHost;
}
$serverHost = rtrim($serverHost, '.');
$isLocalHost = in_array($serverHost, ['localhost', '127.0.0.1', '::1'], true)
    || str_ends_with($serverHost, '.localhost');

// Enforce HTTPS for non-local web hosts only. XAMPP loopback URLs stay on HTTP.
if (!$isLocalHost && !$httpsOn && PHP_SAPI !== 'cli') {
    if (!preg_match('/\\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\\z/iD', $serverHost)) {
        http_response_code(400);
        exit('إعداد النطاق غير صالح.');
    }
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    if (!is_string($requestUri) || !str_starts_with($requestUri, '/') || str_starts_with($requestUri, '//') || preg_match('/[\\r\\n]/', $requestUri)) {
        $requestUri = '/';
    }
    header('Location: https://' . $serverHost . $requestUri, true, 302);
    exit;
}
$sessionCookieSecure = $httpsOn || !$isLocalHost;
ini_set('session.use_only_cookies', '1');
ini_set('session.use_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $sessionCookieSecure ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => BASE_URL === '' ? '/' : BASE_URL . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $sessionCookieSecure,
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $exception) {
    error_log(sprintf('[Ehtiyaj] Database connection failed: %s', $exception->getMessage()));
    http_response_code(500);
    exit('تعذر الاتصال بالخدمة حاليًا. حاول مرة أخرى لاحقًا.');
}

/** Read scalar request values without allowing arrays to reach PHP string/int APIs. */
function input_string(string $key, string $source = 'post', string $default = ''): string
{
    $input = $source === 'get' ? $_GET : $_POST;
    $value = $input[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

/** Return an optional scalar request value, or null for missing and non-string input. */
function optional_input_string(string $key, string $source = 'post'): ?string
{
    $input = $source === 'get' ? $_GET : $_POST;
    if (!array_key_exists($key, $input) || !is_string($input[$key])) {
        return null;
    }
    return $input[$key];
}

/** Parse a signed integer from GET or POST without accepting arrays or malformed strings. */
function input_int(string $key, string $source = 'post', int $default = 0): int
{
    $input = $source === 'get' ? $_GET : $_POST;
    $value = $input[$key] ?? $default;
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value)) {
        return $default;
    }
    return (int) $value;
}

/** Detect a POST body rejected by PHP before normal form fields could be populated. */
function request_body_exceeds_post_max(): bool
{
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (!is_string($contentLength) || !ctype_digit($contentLength)) {
        return false;
    }
    $limit = trim((string)ini_get('post_max_size'));
    if ($limit === '' || $limit === '0') {
        return false;
    }
    $unit = strtolower(substr($limit, -1));
    $number = (float)$limit;
    $bytes = match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
    return (float)$contentLength > $bytes;
}

/** Escape untrusted content before output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect to a project-internal path while honoring subdirectory installs. */
function go(string $path): void
{
    if (str_starts_with($path, '/') && BASE_URL !== '') {
        $path = BASE_URL . $path;
    }
    header('Location: ' . $path);
    exit;
}

/** Apply the existing password strength policy used by account forms. */
function password_policy_valid(string $password): bool
{
    // Portable baseline: eight characters minimum, no digit-only/character-class restriction.
    // A 64-byte ceiling avoids silent truncation with bcrypt-based PASSWORD_DEFAULT.
    return strlen($password) >= 8 && strlen($password) <= 64 && !preg_match('/[\\x00-\\x1F\\x7F]/', $password);
}

/** Return the session-bound CSRF token, creating it when the session has none. */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Validate the submitted CSRF token and stop the request when it is invalid. */
function check_csrf(): void
{
    $sessionToken = $_SESSION['csrf'] ?? '';
    $postedToken = $_POST['csrf'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && (!is_string($sessionToken) || !is_string($postedToken) || !hash_equals($sessionToken, $postedToken))) {
        http_response_code(419);
        exit('انتهت صلاحية النموذج. أعد تحميل الصفحة.');
    }
}

/** Enforce POST-only handling and reject unexpected array-shaped form values. */
function post_only(array $arrayFields = []): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('طريقة غير مسموحة.');
    }
    check_csrf();
    foreach ($_POST as $key => $value) {
        if (!is_string($value) && !in_array((string)$key, $arrayFields, true)) {
            http_response_code(400);
            exit('أحد الحقول غير صالح. أعد إرسال النموذج بعد تصحيحه.');
        }
    }
}

/** Store one escaped-on-render flash message in the current session. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [$message, $type];
}

/** Present internal state identifiers as clear, localized status labels. */
function status_label(string $status): string
{
    return [
        'receiving_offers' => 'يستقبل العروض',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
        'published' => 'منشور',
        'removed' => 'أزيل من المنصة',
        'submitted' => 'بانتظار رد صاحب الطلب',
        'accepted' => 'مقبول',
        'rejected' => 'مرفوض',
        'withdrawn' => 'منسحب',
        'open' => 'مفتوح',
        'read_only' => 'للقراءة فقط',
        'pending' => 'بانتظار المراجعة',
        'closed' => 'مغلق',
        'active' => 'نشط',
        'suspended' => 'موقوف مؤقتًا',
        'deactivated' => 'معطل',
    ][$status] ?? $status;
}

function status_class(string $status): string
{
    return match ($status) {
        'completed', 'accepted', 'active', 'published' => 'success',
        'in_progress', 'open' => 'info',
        'receiving_offers', 'submitted', 'pending' => 'warning',
        'cancelled', 'removed', 'rejected', 'suspended', 'deactivated' => 'danger',
        'withdrawn', 'closed', 'read_only' => 'secondary',
        default => 'light',
    };
}

function status_badge(string $status): string
{
    return '<span class="status-badge status-' . e(status_class($status)) . '">' . e(status_label($status)) . '</span>';
}

/** Return the current authenticated user record, or null when signed out. */
function me(): ?array
{
    global $pdo;
    if (empty($_SESSION['uid'])) {
        return null;
    }

    $statement = $pdo->prepare('SELECT id,full_name,email,phone,major,role,status,email_visibility,phone_visibility,created_at FROM users WHERE id = ?');
    $statement->execute([$_SESSION['uid']]);
    $user = $statement->fetch();
    if (!$user || $user['status'] === 'deactivated') {
        unset($_SESSION['uid']);
        return null;
    }
    return $user;
}

/** Require an authenticated session, redirecting anonymous visitors to sign-in. */
function need_login(): void
{
    if (!me()) {
        go('/auth/login.php');
    }
}

/** Require the requested role and enforce the existing account-state restrictions. */
function need_role(string $role): void
{
    need_login();
    $user = me();
    if ($user['role'] !== $role || ($role === 'admin' && $user['status'] !== 'active')) {
        http_response_code(403);
        exit('غير مصرح لك بالوصول.');
    }
}

/** Block state-changing actions for accounts that are not permitted to write. */
function active_write(): void
{
    need_login();
    if (me()['status'] !== 'active') {
        http_response_code(403);
        exit('الحساب موقوف مؤقتًا؛ يمكنك التصفح فقط.');
    }
}

/** Lock and re-check the student account inside a write transaction. */
function lock_active_student(int $userId): bool
{
    global $pdo;
    if (!$pdo->inTransaction()) {
        throw new LogicException('Locking the student account requires an active transaction.');
    }
    $statement = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' AND status='active' FOR UPDATE");
    $statement->execute([$userId]);
    return (bool)$statement->fetchColumn();
}

/** Create a user-scoped notification using the shared database connection. */
function notify(int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    global $pdo;
    $statement = $pdo->prepare(
        'INSERT INTO notifications(user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$userId, $type, $title, $message, $link]);
}

/** Append an administrative audit record for the currently authenticated admin. */
function audit(string $action, string $entityType, ?int $entityId, string $description): void
{
    global $pdo;
    $statement = $pdo->prepare(
        'INSERT INTO admin_logs(admin_id, action, entity_type, entity_id, description) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([me()['id'] ?? null, $action, $entityType, $entityId, $description]);
}

/** Parse and clamp safe pagination parameters. Counts are supplied from a separate SQL COUNT query. */
function pagination_state(int $total, int $defaultPerPage = 20): array
{
    $rawPage = input_string('page', 'get', '1');
    $rawPerPage = input_string('per_page', 'get', (string)$defaultPerPage);
    $page = preg_match('/^[0-9]{1,9}$/D', $rawPage) ? max(1, (int)$rawPage) : 1;
    $requestedPerPage = preg_match('/^[0-9]{1,3}$/D', $rawPerPage) ? (int)$rawPerPage : $defaultPerPage;
    $perPage = in_array($requestedPerPage, [10, 20, 50], true) ? $requestedPerPage : $defaultPerPage;
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 0;
    $page = $totalPages > 0 ? min($page, $totalPages) : 1;
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => max(0, $total),
        'total_pages' => $totalPages,
        'next' => $totalPages > 0 && $page < $totalPages,
        'previous' => $page > 1,
        'offset' => ($page - 1) * $perPage,
    ];
}

/** Render page links while preserving the current safe scalar filters and sort query. */
function pagination_ui(array $pagination, string $label = 'النتائج'): string
{
    if ($pagination['total_pages'] < 1) {
        return '<nav class="pagination-summary" aria-label="ترقيم الصفحات"><span>لا توجد ' . e($label) . '.</span></nav>';
    }
    $query = [];
    foreach ($_GET as $key => $value) {
        if (is_string($key) && is_scalar($value) && !in_array($key, ['page', 'per_page'], true)) {
            $query[$key] = (string)$value;
        }
    }
    $perPage = (int)$pagination['per_page'];
    $link = static function (int $page) use ($query, $perPage): string {
        $values = $query;
        $values['page'] = $page;
        $values['per_page'] = $perPage;
        return '?' . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    };
    $p = $pagination;
    $html = '<nav class="pagination-summary" aria-label="صفحات ' . e($label) . '"><span>صفحة ' . (int)$p['page'] . ' من ' . (int)$p['total_pages'] . ' · ' . (int)$p['total'] . ' نتيجة</span><div class="pagination-actions">';
    if ($p['previous']) {
        $html .= '<a class="btn btn-sm btn-outline-primary" rel="prev" href="' . e($link($p['page'] - 1)) . '">السابق</a>';
    }
    if ($p['next']) {
        $html .= '<a class="btn btn-sm btn-outline-primary" rel="next" href="' . e($link($p['page'] + 1)) . '">التالي</a>';
    }
    return $html . '</div></nav>';
}

/** Internal link helper for HTML templates. */
function app_url(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Internal URL builder for context-aware navigation links in templates. */
function ui_context_url(string $path, array $parameters = []): string
{
    $allowedKeys = ['id', 'report_id', 'q', 'major', 'page', 'per_page', 'return_to', 'back_q', 'back_major', 'back_page', 'back_per_page', 'back_report_id', 'back_request_id'];
    $safe = [];
    foreach ($parameters as $key => $value) {
        if (in_array($key, $allowedKeys, true) && is_scalar($value)) {
            $safe[$key] = (string)$value;
        }
    }
    $query = http_build_query($safe, '', '&', PHP_QUERY_RFC3986);
    return app_url($path . ($query !== '' ? '?' . $query : ''));
}

/** Resolve only known internal destinations; direct URLs always receive a sensible fallback. */
function ui_back_context(string $fallback = 'home'): array
{
    $contexts = [
        'home' => ['path' => 'student/index.php', 'label' => 'الرئيسية'],
        'public' => ['path' => 'requests/index.php', 'label' => 'الطلبات'],
        'mine' => ['path' => 'requests/my.php', 'label' => 'طلباتي'],
        'offers' => ['path' => 'offers/my.php', 'label' => 'عروضي'],
        'chats' => ['path' => 'chat/index.php', 'label' => 'محادثاتي'],
        'notifications' => ['path' => 'notifications/index.php', 'label' => 'الإشعارات'],
        'profile' => ['path' => 'student/profile.php', 'label' => 'الملف الشخصي'],
        'request' => ['path' => 'requests/view.php', 'label' => 'تفاصيل الطلب'], 
        'admin-users' => ['path' => 'admin/users.php', 'label' => 'المستخدمون'],
        'admin-requests' => ['path' => 'admin/requests.php', 'label' => 'طلبات الإدارة'],
        'admin-reports' => ['path' => 'admin/reports.php', 'label' => 'البلاغات'],
        'admin-logs' => ['path' => 'admin/logs.php', 'label' => 'سجل الإدارة'],
    ];
    if (!isset($contexts[$fallback])) {
        $fallback = 'home';
    }
    $context = input_string('return_to', 'get', $fallback);
    if (!isset($contexts[$context])) {
        $context = $fallback;
    }
    $rawPage = input_string('back_page', 'get', '1');
    $page = preg_match('/^[0-9]{1,7}$/D', $rawPage) ? max(1, (int)$rawPage) : 1;
    $rawPerPage = input_string('back_per_page', 'get', '20');
    $perPage = preg_match('/^[0-9]{1,3}$/D', $rawPerPage) && in_array((int)$rawPerPage, [10, 20, 50], true) ? (int)$rawPerPage : 20;
    $backRequestId = input_int('back_request_id', 'get', 0);
    if ($context === 'request' && $backRequestId < 1) {
        $context = $fallback;
    }
    $query = []; 
    $forward = ['return_to' => $context, 'back_page' => $page, 'back_per_page' => $perPage];
    if ($context === 'public') {
        $q = input_string('back_q', 'get');
        $major = input_string('back_major', 'get');
        $query = ['q' => $q, 'major' => $major, 'page' => $page, 'per_page' => $perPage];
        $forward['back_q'] = $q;
        $forward['back_major'] = $major;
    } elseif ($context === 'request') {
        $query = ['id' => $backRequestId];
        $forward['back_request_id'] = $backRequestId;
    } elseif ($context === 'admin-users') {
        $q = input_string('back_q', 'get');
        $query = ['q' => $q, 'page' => $page, 'per_page' => $perPage];
        $forward['back_q'] = $q;
    } elseif (!in_array($context, ['home'], true)) {
        $query = ['page' => $page, 'per_page' => $perPage];
    }
    return [
        'key' => $context,
        'label' => $contexts[$context]['label'],
        'url' => ui_context_url($contexts[$context]['path'], $query),
        'forward' => $forward,
    ];
}

function home_url(?array $user = null): string
{
    $user = $user ?? me();
    if (!$user) {
        return app_url('index.php');
    }
    return app_url($user['role'] === 'admin' ? 'admin/index.php' : 'student/index.php');
}

/** Render the shared, explicitly-labelled return control used on detail pages. */
function page_back_link(string $url, string $label): string
{
    return '<div class="ux-back-row"><a class="ux-back-link" href="' . e($url) . '"><i class="bi bi-arrow-left" aria-hidden="true"></i> العودة إلى ' . e($label) . '</a></div>';
}

function header_nav_links(?array $user, bool $mobile = false): void
{
    if (!$user) {
        ?>
        <a class="nav-item-link nav-home-item" href="<?= e(home_url()) ?>"><i class="bi bi-house-door-fill" aria-hidden="true"></i><span>الرئيسية</span></a>
        <a class="nav-item-link" href="<?= e(app_url('about.php')) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i><span>عن المنصة</span></a>
        <?php
        return;
    }

    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (BASE_URL !== '' && str_starts_with($currentPath, BASE_URL)) {
        $currentPath = substr($currentPath, strlen(BASE_URL));
    }

    if ($user['role'] === 'admin') {
        $allLinks = [
            ['admin/index.php', 'house-door-fill', 'الرئيسية'],
            ['admin/users.php', 'people', 'المستخدمون'],
            ['admin/requests.php', 'journal-text', 'الطلبات'],
            ['admin/reports.php', 'flag', 'البلاغات'],
            ['admin/assistance_types.php', 'grid', 'أنواع المساعدة'],
            ['admin/majors.php', 'mortarboard', 'التخصصات'],
            ['admin/logs.php', 'clock-history', 'سجل الإدارة'],
        ];
        $mainLinkCount = $mobile ? count($allLinks) : 4;
        $primaryLinks = array_slice($allLinks, 0, $mainLinkCount);
        $moreLinks = array_slice($allLinks, $mainLinkCount);
    } else {
        // Keep the student navigation focused on the three places used most often.
        $primaryLinks = [
            ['student/index.php', 'house-door', 'الرئيسية'],
            ['requests/index.php', 'search', 'الطلبات'],
            ['chat/index.php', 'chat-dots', 'محادثاتي'],
        ];
        $moreLinks = [
            ['requests/my.php', 'journal-check', 'طلباتي'],
            ['offers/my.php', 'hand-thumbs-up', 'عروضي'],
            ['student/profile.php', 'person-gear', 'الملف الشخصي'],
        ];
    }

    foreach ($primaryLinks as [$path, $icon, $label]) {
        $isCurrent = rtrim($currentPath, '/') === '/' . $path;
        if ($user['role'] === 'admin') {
            $adminChildPaths = [
                'admin/users.php' => ['/admin/user_view.php'],
                'admin/requests.php' => ['/admin/request_view.php'],
                'admin/reports.php' => ['/admin/report_view.php', '/admin/reported_conversation.php'],
            ];
            if (in_array($currentPath, $adminChildPaths[$path] ?? [], true)) {
                $isCurrent = true;
            }
        }
        if ($path === 'requests/index.php' && basename($currentPath) === 'view.php') {
            $isCurrent = true;
        }
        if ($path === 'chat/index.php' && $currentPath === '/chat/view.php') {
            $isCurrent = true;
        }
        ?>
        <a class="nav-item-link<?= $label === 'الرئيسية' ? ' nav-home-item' : '' ?><?= $isCurrent ? ' is-current' : '' ?>" href="<?= e(app_url($path)) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
            <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
        </a>
        <?php
    }

    if ($moreLinks) {
        $accountPaths = ['requests/my.php', 'offers/my.php', 'student/profile.php', 'requests/create.php'];
        $moreIsCurrent = in_array(ltrim($currentPath, '/'), $accountPaths, true)
            || in_array($currentPath, ['/requests/edit.php', '/student/account-status.php', '/student/deactivate.php'], true)
            || ($user['role'] === 'admin' && in_array(ltrim($currentPath, '/'), ['admin/assistance_types.php', 'admin/majors.php', 'admin/logs.php'], true));
        $menuLabel = $user['role'] === 'student' ? 'حسابي' : 'المزيد';
        $menuIcon = $user['role'] === 'student' ? 'person-circle' : 'three-dots';
        ?>
        <details class="nav-more<?= $user['role'] === 'student' ? ' account-nav' : '' ?>">
            <summary class="nav-item-link<?= $moreIsCurrent ? ' is-current' : '' ?>" aria-expanded="false"><i class="bi bi-<?= e($menuIcon) ?>" aria-hidden="true"></i><span><?= e($menuLabel) ?></span><i class="bi bi-chevron-down nav-more-chevron" aria-hidden="true"></i></summary>
            <div class="nav-more-menu">
                <?php foreach ($moreLinks as [$path, $icon, $label]): ?>
                    <?php $isCurrent = rtrim($currentPath, '/') === '/' . $path; ?>
                    <a class="nav-item-link<?= $label === 'الرئيسية' ? ' nav-home-item' : '' ?><?= $isCurrent ? ' is-current' : '' ?>" href="<?= e(app_url($path)) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                        <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
        <?php
    }
}

/** Render the shared document header and navigation for the selected layout. */
function header_ui(string $title = 'احتياج', bool $authLayout = false): void
{
    $user = me();
    $unreadCount = 0;
    if ($user) {
        global $pdo;
        $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $statement->execute([$user['id']]);
        $unreadCount = (int) $statement->fetchColumn();
    }
    $currentAdminPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (BASE_URL !== '' && str_starts_with($currentAdminPath, BASE_URL)) {
        $currentAdminPath = substr($currentAdminPath, strlen(BASE_URL));
    }
    $adminNotificationsCurrent = $user && $user['role'] === 'admin' && $currentAdminPath === '/admin/notifications.php';
    $bodyClass = $authLayout ? 'auth-page' : (($user && $user['role'] === 'student') ? 'student-experience' : (($user && $user['role'] === 'admin') ? 'admin-experience' : ''));
    ?>
    <!doctype html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#10283f">
        <title><?= e($title) ?> | احتياج</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link href="<?= e(app_url('assets/style.css')) ?>" rel="stylesheet">
        <?php if ($bodyClass === 'student-experience'): ?><link href="<?= e(app_url('assets/student.css')) ?>" rel="stylesheet"><?php elseif ($bodyClass === 'admin-experience'): ?><link href="<?= e(app_url('assets/admin.css')) ?>" rel="stylesheet"><?php endif; ?>
    </head>
    <body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
        <?php if ($authLayout): ?>
            <a class="skip-link" href="#main-content">تجاوز إلى المحتوى</a>
            <header class="auth-topbar">
                <div class="container auth-topbar-inner">
                    <a class="auth-brand" href="<?= e(app_url('index.php')) ?>" aria-label="احتياج — الصفحة الرئيسية">
                        <span class="brand-symbol" aria-hidden="true">ا</span>
                        <span class="brand-copy"><strong>احتياج</strong><small>EHTIYAJ</small></span>
                    </a>
                    <a class="auth-home-link" href="<?= e(app_url('index.php')) ?>"><i class="bi bi-house-door" aria-hidden="true"></i> العودة إلى الرئيسية</a>
                </div>
            </header>
            <main class="container auth-main" id="main-content" tabindex="-1">
                <?php if (!empty($_SESSION['flash'])): ?>
                    <?php [$message, $type] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
                    <div class="alert alert-<?= e($type) ?> alert-dismissible" role="status" data-flash-type="<?= e($type) ?>" data-flash-message="<?= e($message) ?>">
                        <?= e($message) ?>
                        <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                    </div>
                <?php endif; ?>
        <?php return; endif; ?>
        <a class="skip-link" href="#main-content">تجاوز إلى المحتوى</a>
        <header class="site-header topbar">
            <div class="container header-inner">
                <a class="navbar-brand" href="<?= e(home_url($user)) ?>" aria-label="احتياج — الصفحة الرئيسية">
                    <span class="brand-symbol" aria-hidden="true">ا</span>
                    <span class="brand-copy"><strong>احتياج</strong><small>EHTIYAJ</small></span>
                </a>
                <a class="mobile-home-link" href="<?= e(home_url($user)) ?>"><i class="bi bi-house-door-fill" aria-hidden="true"></i><span>الرئيسية</span></a>

                <?php if ($user): ?>
                    <div class="desktop-nav" aria-label="التنقل الرئيسي">
                        <?php header_nav_links($user); ?>
                    </div>
                <?php else: ?>
                    <div class="desktop-nav" aria-label="التنقل الرئيسي">
                        <?php header_nav_links(null); ?>
                    </div>
                <?php endif; ?>

                <div class="header-actions">
                    <?php if ($user): ?>
                        <?php if ($user['role'] === 'student' && $user['status'] === 'active'): ?>
                            <a class="header-create-link" href="<?= e(app_url('requests/create.php')) ?>"><i class="bi bi-plus-lg"></i><span>إنشاء طلب</span></a>
                        <?php endif; ?>
                        <a class="notification-action<?= $adminNotificationsCurrent ? ' is-current' : '' ?>" href="<?= e(app_url($user['role'] === 'admin' ? 'admin/notifications.php' : 'notifications/index.php')) ?>" aria-label="الإشعارات، غير المقروء <?= $unreadCount ?>"<?= $adminNotificationsCurrent ? ' aria-current="page"' : '' ?>>
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <?php if ($unreadCount > 0): ?><span class="notification-count"><?= min($unreadCount, 99) ?></span><?php endif; ?>
                        </a>
                        <div class="user-chip">
                            <span class="user-avatar" aria-hidden="true"><?= e(mb_substr($user['full_name'], 0, 1)) ?></span>
                            <span class="user-chip-copy"><strong><?= e($user['full_name']) ?></strong><small><?= $user['role'] === 'admin' ? 'إدارة المنصة' : e($user['major']) ?></small></span>
                        </div>
                        <form method="post" action="<?= e(app_url('auth/logout.php')) ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <button class="logout-button" type="submit" aria-label="تسجيل الخروج"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>خروج</span></button>
                        </form>
                    <?php else: ?>
                        <a class="login-link" href="<?= e(app_url('auth/login.php')) ?>">دخول</a>
                        <a class="signup-link" href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب <i class="bi bi-arrow-left" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>

                <button class="mobile-menu-toggle" type="button" aria-label="فتح القائمة" aria-controls="mobile-menu" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </header>

        <div class="mobile-menu-scrim" data-menu-close></div>
        <aside class="mobile-menu" id="mobile-menu" aria-hidden="true" aria-label="القائمة الرئيسية">
            <div class="mobile-menu-head">
                <a class="navbar-brand" href="<?= e(home_url($user)) ?>">
                    <span class="brand-symbol" aria-hidden="true">ا</span>
                    <span class="brand-copy"><strong>احتياج</strong><small>EHTIYAJ</small></span>
                </a>
                <button class="mobile-menu-close" type="button" aria-label="إغلاق القائمة" data-menu-close><i class="bi bi-x-lg"></i></button>
            </div>
            <?php if ($user): ?>
                <div class="mobile-user-card">
                    <span class="user-avatar" aria-hidden="true"><?= e(mb_substr($user['full_name'], 0, 1)) ?></span>
                    <span><strong><?= e($user['full_name']) ?></strong><small><?= $user['role'] === 'admin' ? 'إدارة المنصة' : e($user['major']) ?></small></span>
                </div>
            <?php endif; ?>
            <nav class="mobile-nav" aria-label="روابط الموقع">
                <?php header_nav_links($user, true); ?>
                <?php if (!$user): ?>
                    <a class="nav-item-link" href="<?= e(app_url('auth/login.php')) ?>"><i class="bi bi-box-arrow-in-left"></i><span>تسجيل الدخول</span></a>
                    <a class="nav-item-link" href="<?= e(app_url('auth/register.php')) ?>"><i class="bi bi-person-plus"></i><span>إنشاء حساب</span></a>
                <?php endif; ?>
            </nav>
            <?php if ($user): ?>
                <form method="post" action="<?= e(app_url('auth/logout.php')) ?>" class="mobile-logout-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button class="mobile-logout" type="submit"><i class="bi bi-box-arrow-right"></i> تسجيل الخروج</button>
                </form>
            <?php endif; ?>
            <div class="mobile-menu-foot">تعاون طلابي مجاني <i class="bi bi-heart-fill"></i></div>
        </aside>

        <main class="container py-4" id="main-content" tabindex="-1">
            <?php if (!empty($_SESSION['flash'])): ?>
                <?php [$message, $type] = $_SESSION['flash'];
                unset($_SESSION['flash']); ?>
                <div class="alert alert-<?= e($type) ?> alert-dismissible" role="status" data-flash-type="<?= e($type) ?>" data-flash-message="<?= e($message) ?>">
                    <?= e($message) ?>
                    <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
                </div>
            <?php endif; ?>
    <?php
}

/** Render the shared application footer and close the document structure. */
function footer_ui(bool $compact = false): void
{
    $user = me();
    $isLoginPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';
    ?>
        </main>
        <?php if ($compact): ?>
            <footer class="auth-footer">
                <div class="container auth-footer-inner">
                    <a class="auth-footer-brand" href="<?= e(app_url('index.php')) ?>"><span class="footer-mark" aria-hidden="true">ا</span><span><strong>احتياج</strong><small>EHTIYAJ</small></span></a>
                    <nav aria-label="روابط صفحات المصادقة">
                        <a href="<?= e(app_url('index.php')) ?>">الرئيسية</a>
                        <a href="<?= e(app_url('about.php')) ?>">عن احتياج</a>
                        <?php if ($isLoginPage): ?>
                            <a href="<?= e(app_url('auth/register.php')) ?>">إنشاء حساب</a>
                        <?php else: ?>
                            <a href="<?= e(app_url('auth/login.php')) ?>">تسجيل الدخول</a>
                        <?php endif; ?>
                    </nav>
                    <small class="auth-footer-copy">© <?= date('Y') ?> احتياج</small>
                </div>
            </footer>
        <?php else: ?>
            <footer class="site-footer">
                <div class="container">
                    <div class="footer-main">
                        <section class="footer-brand" aria-label="عن احتياج">
                            <a class="footer-logo" href="<?= e(home_url($user)) ?>">
                                <span class="footer-mark" aria-hidden="true">ا</span>
                                <span><strong>احتياج</strong><small>EHTIYAJ</small></span>
                            </a>
                            <p>منصة طلابية مجانية لطلب المساعدة التعليمية والتقنية وتقديمها، والتعاون مع زملائك بخصوصية.</p>
                        </section>
                        <nav class="footer-links" aria-label="روابط المنصة">
                            <h2>روابط المنصة</h2>
                            <a href="<?= e(app_url('index.php')) ?>"><i class="bi bi-house-door" aria-hidden="true"></i> الرئيسية</a>
                            <a href="<?= e(app_url('about.php')) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i> عن احتياج وكيف تعمل</a>
                            <?php if ($user && $user['role'] === 'student'): ?>
                                <a href="<?= e(app_url('requests/index.php')) ?>"><i class="bi bi-search" aria-hidden="true"></i> تصفح الطلبات</a>
                                <?php if ($user['status'] === 'active'): ?><a href="<?= e(app_url('requests/create.php')) ?>"><i class="bi bi-plus-circle" aria-hidden="true"></i> إنشاء طلب</a><?php endif; ?>
                            <?php endif; ?>
                        </nav>
                        <nav class="footer-links" aria-label="روابط الحساب">
                            <h2>حسابك</h2>
                            <?php if (!$user): ?>
                                <a href="<?= e(app_url('auth/login.php')) ?>"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> تسجيل الدخول</a>
                                <a href="<?= e(app_url('auth/register.php')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> إنشاء حساب</a>
                            <?php elseif ($user['role'] === 'student'): ?>
                                <a href="<?= e(app_url('student/index.php')) ?>"><i class="bi bi-grid" aria-hidden="true"></i> لوحة الطالب</a>
                                <a href="<?= e(app_url('student/profile.php')) ?>"><i class="bi bi-person-gear" aria-hidden="true"></i> الملف الشخصي</a>
                                <a href="<?= e(app_url('notifications/index.php')) ?>"><i class="bi bi-bell" aria-hidden="true"></i> الإشعارات</a>
                            <?php else: ?>
                                <a href="<?= e(app_url('admin/index.php')) ?>"><i class="bi bi-grid" aria-hidden="true"></i> لوحة الإدارة</a>
                                <a href="<?= e(app_url('admin/notifications.php')) ?>"><i class="bi bi-bell" aria-hidden="true"></i> الإشعارات</a>
                            <?php endif; ?>
                        </nav>
                        <section class="footer-help">
                            <span class="footer-note-icon"><i class="bi bi-heart-fill" aria-hidden="true"></i></span>
                            <div><h2>تعاون طلابي مجاني</h2><p>تعرّف على آلية المنصة وخطوات طلب المساعدة وتقديمها.</p><a href="<?= e(app_url('about.php')) ?>">اكتشف كيف تعمل احتياج <i class="bi bi-arrow-left" aria-hidden="true"></i></a></div>
                        </section>
                    </div>
                    <div class="footer-bottom">
                        <span>© <?= date('Y') ?> احتياج · Ehtiyaj</span>
                        <span><i class="bi bi-shield-check ms-1" aria-hidden="true"></i> تعاون طلابي يحترم الخصوصية</span>
                    </div>
                </div>
            </footer>
        <?php endif; ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="<?= e(app_url('assets/app.js')) ?>"></script>
    </body>
    </html>
    <?php
}
