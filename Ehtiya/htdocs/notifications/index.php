<?php
/** Shared student/admin inbox; each query and read action is scoped to the signed-in user. */
require_once __DIR__ . '/../includes/bootstrap.php';
need_login();

// POST and CSRF checks remain mandatory; update only the current user's unread row.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    try {
        $statement = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0');
        $statement->execute([input_int('id'), me()['id']]);
        if ($statement->rowCount() === 1) {
            flash('تم وضع الإشعار في قائمة المقروء.');
        } else {
            flash('لم يتم تحديث الإشعار؛ قد يكون غير متاح أو سبق تعليمه كمقروء.', 'warning');
        }
    } catch (Throwable $exception) {
        error_log('[Ehtiyaj] Unable to update notification read state.');
        flash('تعذر تحديث حالة الإشعار. حاول مرة أخرى.', 'danger');
    }
    // Preserve the inbox page and size across the POST/redirect/GET flow.
    $rawPage = input_string('page', 'get', '1');
    $rawPerPage = input_string('per_page', 'get', '20');
    $page = preg_match('/^[0-9]{1,7}$/D', $rawPage) ? max(1, (int)$rawPage) : 1;
    $perPage = preg_match('/^[0-9]{1,3}$/D', $rawPerPage) && in_array((int)$rawPerPage, [10, 20, 50], true) ? (int)$rawPerPage : 20;
    $inboxPath = me()['role'] === 'admin' ? 'admin/notifications.php' : 'notifications/index.php';
    $returnUrl = ui_context_url($inboxPath, ['page' => $page, 'per_page' => $perPage]);
    go(substr($returnUrl, strlen(BASE_URL)));
}

$notifications = [];
$loadError = false;
$pagination = pagination_state(0);
try {
    $count = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?');
    $count->execute([me()['id']]);
    $pagination = pagination_state((int)$count->fetchColumn());
    // Show unread items first and keep the newest items at the top of each bounded page.
    $statement = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY is_read ASC,created_at DESC,id DESC LIMIT ? OFFSET ?');
    $statement->bindValue(1, (int)me()['id'], PDO::PARAM_INT);
    $statement->bindValue(2, $pagination['per_page'], PDO::PARAM_INT);
    $statement->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
    $statement->execute();
    $notifications = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('[Ehtiyaj] Unable to load notification inbox.');
    $loadError = true;
}
$notificationContext = ['return_to' => 'notifications', 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']];
$notificationIcons = [
    'new_request' => 'bi-journal-plus',
    'new_offer' => 'bi-hand-thumbs-up',
    'offer_accepted' => 'bi-check-circle',
    'offer_rejected' => 'bi-arrow-return-left',
    'helper_withdrew' => 'bi-person-dash',
    'request_completed' => 'bi-check2-all',
    'request_cancelled' => 'bi-x-circle',
    'request_removed' => 'bi-shield-exclamation',
    'request_reopened' => 'bi-arrow-repeat',
    'new_report' => 'bi-flag',
    'assistance_type_suggestion' => 'bi-lightbulb',
    'assistance_type_suggestion_accepted' => 'bi-lightbulb-fill',
    'assistance_type_suggestion_rejected' => 'bi-lightbulb-off',
    'account_suspended' => 'bi-person-lock',
    'account_reactivated' => 'bi-person-check',
    'major_changed' => 'bi-mortarboard',
    'new_message' => 'bi-chat-dots',
];
$notificationActionLabels = [
    'new_request' => 'مراجعة الطلب',
    'new_offer' => 'مراجعة العروض',
    'offer_accepted' => 'فتح المحادثة',
    'offer_rejected' => 'عرض عروضي',
    'new_message' => 'فتح المحادثة',
    'request_cancelled' => 'عرض الطلب',
    'request_reopened' => 'عرض الطلب',
    'helper_withdrew' => 'عرض الطلب',
    'request_completed' => 'عرض المحادثات',
    'request_removed' => 'عرض العناصر المرتبطة',
    'new_report' => 'مراجعة البلاغات',
    'assistance_type_suggestion' => 'مراجعة الاقتراحات',
    'major_changed' => 'فتح الملف الشخصي',
    'account_suspended' => 'معرفة حالة الحساب',
    'account_reactivated' => 'فتح الحساب',
];

header_ui('الإشعارات');
?>
<?php if (me()['role'] === 'admin' && function_exists('admin_page_back_link')): ?><?= admin_page_back_link('dashboard') ?><?php elseif (me()['role'] === 'student'): ?><nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(app_url('student/index.php')) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>الإشعارات</span></nav><?php endif; ?>
<div class="page-heading">
    <div><span class="eyebrow"><i class="bi bi-bell"></i> تحديثات مرتبطة بنشاطك</span><h1>الإشعارات</h1><p>تظهر هنا تحديثات نشاطك، ويُحدَّث العداد عند تحميل الصفحة. فتح الرابط لا يغيّر حالة قراءة الإشعار؛ استخدم زر «تحديد كمقروء». قد تتغير حالة العنصر المرتبط بعد وصول الإشعار.</p></div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-warning" role="alert">تعذر تحميل الإشعارات حاليًا. حاول تحديث الصفحة لاحقًا.</div>
<?php elseif ($notifications): ?>
    <section class="notification-list" aria-label="قائمة الإشعارات">
        <?php foreach ($notifications as $notification): ?>
            <?php
            $actionPath = $notification['link'];
            $actionHref = null;
            $actionLabel = $notificationActionLabels[$notification['type']] ?? 'عرض التفاصيل';
            if ($notification['type'] === 'new_offer' && $actionPath) {
                $linkQuery = [];
                parse_str((string)(parse_url($actionPath, PHP_URL_QUERY) ?? ''), $linkQuery);
                if (isset($linkQuery['request_id']) && is_scalar($linkQuery['request_id']) && ctype_digit((string)$linkQuery['request_id'])) {
                    $actionPath = 'requests/view.php?id=' . (int)$linkQuery['request_id'];
                }
            }
            if ($notification['type'] === 'request_removed' && $actionPath) {
                $actionLabel = str_contains($actionPath, 'offers/my.php') ? 'عرض عروضي' : 'عرض طلباتي';
            }
            if ($actionPath) {
                $targetPath = ltrim((string)(parse_url($actionPath, PHP_URL_PATH) ?? ''), '/');
                $targetQuery = [];
                parse_str((string)(parse_url($actionPath, PHP_URL_QUERY) ?? ''), $targetQuery);
                if ($targetPath === 'requests/view.php' && isset($targetQuery['id']) && is_scalar($targetQuery['id']) && ctype_digit((string)$targetQuery['id'])) {
                    $actionHref = ui_context_url('requests/view.php', ['id' => (int)$targetQuery['id']] + $notificationContext);
                } elseif ($targetPath === 'chat/view.php' && isset($targetQuery['id']) && is_scalar($targetQuery['id']) && ctype_digit((string)$targetQuery['id'])) {
                    $actionHref = ui_context_url('chat/view.php', ['id' => (int)$targetQuery['id']] + $notificationContext);
                } elseif ($targetPath === 'chat/index.php' && empty($targetQuery)) {
                    $actionHref = ui_context_url('chat/index.php', $notificationContext);
                } else {
                    $actionHref = app_url($actionPath);
                }
            }
            ?>
            <article class="notification-row<?= !$notification['is_read'] ? ' is-unread' : '' ?>">
                <span class="notification-icon"><i class="bi <?= e($notificationIcons[$notification['type']] ?? 'bi-bell') ?>" aria-hidden="true"></i></span>
                <div class="notification-body">
                    <div class="notification-title-line"><h2><?= e($notification['title']) ?></h2><?php if (!$notification['is_read']): ?><span class="unread-label">جديد</span><?php endif; ?></div>
                    <p><?= e(mb_strimwidth($notification['message'], 0, 180, '…')) ?></p>
                    <time datetime="<?= e(date(DATE_ATOM, strtotime($notification['created_at']))) ?>"><?= e(date('Y-m-d · H:i', strtotime($notification['created_at']))) ?></time>
                </div>
                <div class="notification-actions">
                    <?php if ($actionHref): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e($actionHref) ?>"><?= e($actionLabel) ?> <i class="bi bi-arrow-left"></i></a>
                    <?php endif; ?>
                    <?php if (!$notification['is_read']): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($notification['id']) ?>"><button class="notification-read" type="submit" aria-label="وضع علامة مقروء على <?= e($notification['title']) ?>"><i class="bi bi-check2"></i><span>تحديد كمقروء</span></button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="results-empty notification-empty">
        <span class="results-empty-icon"><i class="bi bi-bell-slash"></i></span>
        <h2>أنت على اطلاع</h2>
        <p>لا توجد إشعارات حاليًا. ستظهر هنا التحديثات المرتبطة بنشاطك عند وصولها.</p>
        <?php if (me()['role'] === 'student'): ?><a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php')) ?>">تصفح الطلبات</a><?php endif; ?>
    </section>
<?php endif; ?>
<?php if (!$loadError): ?><?= pagination_ui($pagination, 'الإشعارات') ?><?php endif; ?>
<?php footer_ui(); ?>
