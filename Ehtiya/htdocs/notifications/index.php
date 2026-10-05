<?php
/** Shared student/admin inbox; each query and read action is scoped to the signed-in user. */
require_once __DIR__ . '/../includes/bootstrap.php';
need_login();

// A notification is marked read only when its ID and current user ID match the same row.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $statement = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $statement->execute([input_int('id'), me()['id']]);
    flash('تم وضع الإشعار في قائمة المقروء.');
    go(me()['role'] === 'admin' ? '/admin/notifications.php' : '/notifications/index.php');
}

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
    'new_offer' => 'مراجعة العروض',
    'offer_accepted' => 'فتح المحادثة',
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
    <div><span class="eyebrow"><i class="bi bi-bell"></i> تحديثات مرتبطة بنشاطك</span><h1>الإشعارات</h1><p>كل إشعار يوضح ما حدث، ويقودك إلى الطلب أو المحادثة المرتبطة به.</p></div>
</div>

<?php if ($notifications): ?>
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
        <p>لا توجد إشعارات حاليًا. ستظهر التحديثات هنا عند حدوث جديد.</p>
        <?php if (me()['role'] === 'student'): ?><a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php')) ?>">تصفح الطلبات</a><?php endif; ?>
    </section>
<?php endif; ?>
<?= pagination_ui($pagination, 'الإشعارات') ?>
<?php footer_ui(); ?>
