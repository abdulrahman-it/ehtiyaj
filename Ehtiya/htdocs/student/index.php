<?php
/** Student dashboard: owner-scoped summaries and a small recent-activity window. */
require __DIR__ . '/../includes/bootstrap.php';
need_role('student');

$user = me();
$studentId = (int) $user['id'];

$summary = [];
// These read-only counts belong to the signed-in student and do not change workflow state.
$summaryQueries = [
    'طلبات نشطة' => ['SELECT COUNT(*) FROM requests WHERE owner_id = ? AND status IN (\'receiving_offers\', \'in_progress\')', $studentId],
    'طلبات مكتملة' => ['SELECT COUNT(*) FROM requests WHERE owner_id = ? AND status = \'completed\'', $studentId],
    'عروض تنتظر مراجعتك' => ['SELECT COUNT(*) FROM offers o JOIN requests r ON r.id = o.request_id WHERE r.owner_id = ? AND o.status = \'submitted\'', $studentId],
];
foreach ($summaryQueries as $label => [$sql, $parameter]) {
    $statement = $pdo->prepare($sql);
    $statement->execute([$parameter]);
    $summary[$label] = (int) $statement->fetchColumn();
}

// Keep the dashboard preview compact; full history is available on the paginated requests page.
$recentRequests = $pdo->prepare(
    'SELECT id, title, status, created_at FROM requests WHERE owner_id = ? ORDER BY created_at DESC LIMIT 4'
);
$recentRequests->execute([$studentId]);
$recentRequests = $recentRequests->fetchAll();

$pendingOffers = $pdo->prepare(
    "SELECT o.id, o.message, o.created_at, r.id AS request_id, r.title, u.full_name AS helper_name
     FROM offers o
     JOIN requests r ON r.id = o.request_id
     JOIN users u ON u.id = o.student_id
     WHERE r.owner_id = ? AND o.status = 'submitted'
     ORDER BY o.created_at DESC LIMIT 4"
);
$pendingOffers->execute([$studentId]);
$pendingOffers = $pendingOffers->fetchAll();

$unreadStatement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStatement->execute([$studentId]);
$unreadNotifications = (int) $unreadStatement->fetchColumn();

header_ui('مساحة الطالب');
?>
<section class="dashboard-welcome">
    <div>
        <span class="eyebrow"><i class="bi bi-house-door"></i> الرئيسية</span>
        <h1>مرحبًا، <?= e($user['full_name']) ?> <span aria-hidden="true">👋</span></h1>
        <p>هذه مساحتك لمتابعة طلبات المساعدة والعروض التي قدمتها.</p>
    </div>
</section>

<?php if ($user['status'] === 'suspended'): ?>
    <div class="account-notice" role="status">
        <i class="bi bi-info-circle"></i>
        <div><strong>حسابك موقوف مؤقتًا</strong><span>يمكنك الاطلاع على بياناتك، لكن لن تتمكن من إرسال طلب أو عرض أو رسالة.</span></div>
        <a href="<?= e(app_url('student/account-status.php')) ?>">معرفة التفاصيل</a>
    </div>
<?php endif; ?>

<section class="dashboard-metrics" aria-label="ملخص نشاطك">
    <?php foreach ($summary as $label => $value): ?>
        <?php $summaryLink = $label === 'عروض تنتظر مراجعتك' ? '#dashboard-pending-offers' : app_url('requests/my.php'); ?>
        <a class="metric-item metric-link" href="<?= e($summaryLink) ?>">
            <span class="metric-icon" aria-hidden="true"><i class="bi <?= $label === 'طلبات مكتملة' ? 'bi-check2-circle' : ($label === 'عروض تنتظر مراجعتك' ? 'bi-hand-thumbs-up' : 'bi-journal-text') ?>"></i></span>
            <span class="metric-label"><?= e($label) ?></span>
            <strong><?= e($value) ?></strong>
            <span class="metric-link-label"><?= $label === 'عروض تنتظر مراجعتك' ? 'راجع أحدث العروض' : 'عرض طلباتي' ?> <i class="bi bi-arrow-left"></i></span>
        </a>
    <?php endforeach; ?>
    <a class="metric-item metric-link" href="<?= e(app_url('notifications/index.php')) ?>">
        <span class="metric-icon" aria-hidden="true"><i class="bi bi-bell"></i></span>
        <span class="metric-label">إشعارات غير مقروءة</span>
        <strong><?= e($unreadNotifications) ?></strong>
        <span class="metric-link-label">عرض الإشعارات <i class="bi bi-arrow-left"></i></span>
    </a>
</section>

<section class="dashboard-next-step" aria-labelledby="dashboard-next-title">
    <span class="dashboard-next-icon" aria-hidden="true"><i class="bi bi-stars"></i></span>
    <div><span class="section-kicker">ابدأ من هنا</span><h2 id="dashboard-next-title">ماذا تريد أن تفعل؟</h2><p>أنشئ طلبًا لتحصل على مساعدة، أو استكشف طلبات زملائك لتقدم عرضًا.</p></div>
    <div class="dashboard-next-actions">
        <?php if ($user['status'] === 'active'): ?>
            <a class="btn btn-primary" href="<?= e(app_url('requests/create.php')) ?>"><i class="bi bi-plus-lg"></i> إنشاء طلب</a>
            <a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php')) ?>"><i class="bi bi-search"></i> استكشف الطلبات</a>
        <?php else: ?>
            <a class="btn btn-outline-primary" href="<?= e(app_url('student/account-status.php')) ?>">معرفة حالة الحساب</a>
        <?php endif; ?>
    </div>
</section>

<div class="dashboard-columns">
    <section class="dashboard-section">
        <div class="section-heading">
            <div><span class="section-kicker">متابعة طلباتك</span><h2><i class="bi bi-journal-check" aria-hidden="true"></i> أحدث طلباتي</h2></div>
            <a href="<?= e(app_url('requests/my.php')) ?>">كل طلباتي <i class="bi bi-arrow-left"></i></a>
        </div>
        <?php if ($recentRequests): ?>
            <div class="activity-list">
                <?php foreach ($recentRequests as $request): ?>
                    <a class="activity-row" href="<?= e(app_url('requests/view.php?id=' . $request['id'])) ?>">
                        <span class="activity-icon"><i class="bi bi-journal-text"></i></span>
                        <span class="activity-content"><strong><?= e($request['title']) ?></strong><small>أُنشئ في <?= e(date('Y-m-d', strtotime($request['created_at']))) ?></small></span>
                        <span class="activity-state"><span class="ux-status-caption">حالة الطلب</span><?= status_badge($request['status']) ?></span>
                        <span class="activity-cta">عرض الطلب <i class="bi bi-arrow-left"></i></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="dashboard-empty"><i class="bi bi-journal-plus"></i><strong>لا توجد لديك طلبات حتى الآن</strong><span>أنشئ طلبًا يوضح المساعدة التي تحتاج إليها، ثم تابع العروض من تفاصيله.</span><?php if ($user['status'] === 'active'): ?><a href="<?= e(app_url('requests/create.php')) ?>">إنشاء أول طلب</a><?php endif; ?></div>
        <?php endif; ?>
    </section>

    <section class="dashboard-section" id="dashboard-pending-offers">
        <div class="section-heading">
            <div><span class="section-kicker">الخطوة التالية: راجع العروض</span><h2><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i> عروض على طلباتك</h2></div>
            <a href="<?= e(app_url('requests/my.php')) ?>">طلباتي <i class="bi bi-arrow-left"></i></a>
        </div>
        <?php if ($pendingOffers): ?>
            <div class="activity-list">
                <?php foreach ($pendingOffers as $offer): ?>
                    <article class="activity-row offer-activity">
                        <span class="activity-icon offer-icon"><i class="bi bi-hand-thumbs-up"></i></span>
                        <span class="activity-content"><strong><?= e($offer['helper_name']) ?> قدّم عرض مساعدة</strong><small>على طلبك: <?= e($offer['title']) ?></small><span><?= e(mb_strimwidth($offer['message'], 0, 88, '…')) ?></span></span>
                        <a class="activity-cta" href="<?= e(app_url('requests/view.php?id=' . $offer['request_id'])) ?>">مراجعة العروض <i class="bi bi-arrow-left"></i></a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="dashboard-empty compact"><i class="bi bi-inbox"></i><strong>لا توجد عروض بانتظار مراجعتك</strong><span>عندما يقدم طالب عرضًا على أحد طلباتك، سيظهر هنا. راجع العروض من صفحة الطلب.</span></div>
        <?php endif; ?>
    </section>
</div>

<section class="dashboard-shortcuts" aria-label="وصول سريع">
    <a href="<?= e(app_url('offers/my.php')) ?>"><i class="bi bi-hand-thumbs-up"></i><span><strong>عروضي</strong><small>تابع العروض التي قدمتها</small></span><i class="bi bi-arrow-left"></i></a>
    <a href="<?= e(app_url('chat/index.php')) ?>"><i class="bi bi-chat-dots"></i><span><strong>محادثاتي</strong><small>تواصل بشأن مساعدة قُبلت</small></span><i class="bi bi-arrow-left"></i></a>
    <a href="<?= e(app_url('notifications/index.php')) ?>"><i class="bi bi-bell"></i><span><strong>الإشعارات</strong><small>تابع آخر التحديثات</small></span><i class="bi bi-arrow-left"></i></a>
</section>
<?php footer_ui(); ?>
