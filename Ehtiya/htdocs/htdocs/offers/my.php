<?php
/** Student offer history and the existing accepted-offer withdrawal workflow. */
require __DIR__.'/../includes/bootstrap.php';
need_role('student');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    active_write();
    $offerId = input_int('id');
    try {
        // Withdrawal, conversation lock-down, request reopening, and notices must remain consistent as one unit.
        $pdo->beginTransaction();
        if (!lock_active_student((int)me()['id'])) {
            throw new RuntimeException('الحساب لم يعد نشطًا؛ تعذر الانسحاب.');
        }
        $lookup = $pdo->prepare('SELECT request_id FROM offers WHERE id=? AND student_id=?');
        $lookup->execute([$offerId, me()['id']]);
        $offerRef = $lookup->fetch();
        if (!$offerRef) {
            throw new RuntimeException('العرض غير متاح للانسحاب.');
        }
        $requestLock = $pdo->prepare('SELECT id,owner_id,status FROM requests WHERE id=? FOR UPDATE');
        $requestLock->execute([$offerRef['request_id']]);
        $request = $requestLock->fetch();
        $offerLock = $pdo->prepare('SELECT id,status FROM offers WHERE id=? AND request_id=? AND student_id=? FOR UPDATE');
        $offerLock->execute([$offerId, $offerRef['request_id'], me()['id']]);
        $offer = $offerLock->fetch();
        if (!$request || $request['status'] !== 'in_progress' || !$offer || $offer['status'] !== 'accepted') {
            throw new RuntimeException('العرض أو الطلب لم يعد في حالة تسمح بالانسحاب.');
        }
        $withdraw = $pdo->prepare("UPDATE offers SET status='withdrawn',withdrawn_at=NOW() WHERE id=? AND student_id=? AND status='accepted'");
        $withdraw->execute([$offerId, me()['id']]);
        if ($withdraw->rowCount() !== 1) {
            throw new RuntimeException('تغيرت حالة العرض؛ أعد تحميل الصفحة.');
        }
        $pdo->prepare("UPDATE conversations SET status='read_only',read_only_reason='withdrawal' WHERE offer_id=? AND status='open'")->execute([$offerId]);
        // Reopen only if no accepted offer remains; notify the owner and waiting helpers if that transition succeeds.
        $remaining = $pdo->prepare("SELECT id FROM offers WHERE request_id=? AND status='accepted' FOR UPDATE");
        $remaining->execute([$request['id']]);
        $reopened = false;
        if (!$remaining->fetch()) {
            $reopen = $pdo->prepare("UPDATE requests SET status='receiving_offers' WHERE id=? AND status='in_progress'");
            $reopen->execute([$request['id']]);
            $reopened = $reopen->rowCount() === 1;
        }
        notify((int)$request['owner_id'], 'helper_withdrew', 'انسحاب مساعد', 'انسحب مساعد من الطلب.', '/requests/view.php?id='.$request['id']);
        if ($reopened) {
            $reopenMessage = 'عاد الطلب لاستقبال عروض المساعدة لعدم وجود مساعد فعال.';
            notify((int)$request['owner_id'], 'request_reopened', 'عاد الطلب لاستقبال العروض', $reopenMessage, '/requests/view.php?id='.$request['id']);
            $pendingOffers = $pdo->prepare("SELECT DISTINCT student_id FROM offers WHERE request_id=? AND status='submitted'");
            $pendingOffers->execute([$request['id']]);
            foreach ($pendingOffers as $pendingHelper) {
                notify((int)$pendingHelper['student_id'], 'request_reopened', 'عاد الطلب لاستقبال العروض', 'عاد الطلب الذي سبق أن قدمت عليه عرضًا لاستقبال المساعدة.', '/requests/view.php?id='.$request['id']);
            }
        }
        $pdo->commit();
        flash('تم الانسحاب.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Ehtiyaj] Offer withdrawal failed: '.$exception->getMessage());
        $safeMessage = $exception instanceof RuntimeException && !($exception instanceof PDOException) ? $exception->getMessage() : 'تعذر إتمام الانسحاب. لم تُحفظ العملية.';
        flash($safeMessage, 'danger');
    }
    go('/offers/my.php');
}
$count = $pdo->prepare('SELECT COUNT(*) FROM offers WHERE student_id=?');
$count->execute([me()['id']]);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare('SELECT o.*,r.title,r.id rid,r.status request_status FROM offers o JOIN requests r ON r.id=o.request_id WHERE o.student_id=? ORDER BY o.created_at DESC,o.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, (int)me()['id'], PDO::PARAM_INT);
$q->bindValue(2, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
$returnContextParams = ['return_to' => 'offers', 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']];
header_ui('عروضي');
?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(app_url('student/index.php')) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>عروضي</span></nav>
<div class="ux-page-header">
    <div><span class="eyebrow"><i class="bi bi-hand-thumbs-up"></i> ما قدمته لزملائك</span><h1>عروضي</h1><p>العروض التي أرسلتها استجابةً لطلبات الطلاب.</p></div>
    <a class="btn btn-outline-primary" href="<?= e(app_url('requests/index.php')) ?>"><i class="bi bi-search"></i> استكشف الطلبات</a>
</div>
<p class="ux-list-intro"><strong>الطلب</strong> هو ما يحتاجه زميلك؛ و<strong>العرض</strong> هو رسالتك التي تنتظر رده عليها.</p>

<?php if ($rows): ?>
    <section class="my-offer-list" aria-label="العروض التي قدمتها">
        <?php foreach ($rows as $o): ?>
            <article class="my-offer-card">
                <span class="offer-identity"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i> عرضك على طلب</span>
                <h2><a href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$o['rid']] + $returnContextParams)) ?>"><?= e($o['title']) ?></a></h2>
                <div class="offer-message-preview"><span>رسالتي</span><p><?= e($o['message']) ?></p></div>
                <time class="my-offer-date" datetime="<?= e(date(DATE_ATOM, strtotime($o['created_at']))) ?>"><i class="bi bi-calendar3" aria-hidden="true"></i> أُرسل في <?= e(date('Y-m-d', strtotime($o['created_at']))) ?></time>
                <div class="offer-state-grid">
                    <div><span>حالة العرض</span><?= status_badge($o['status']) ?><small><?php if ($o['status'] === 'submitted'): ?>بانتظار رد صاحب الطلب.<?php elseif ($o['status'] === 'accepted'): ?>اختار صاحب الطلب عرضك.<?php elseif ($o['status'] === 'rejected'): ?>اختار صاحب الطلب عرضًا آخر.<?php else: ?>انسحبت من هذا العرض.<?php endif; ?></small></div>
                    <div><span>حالة الطلب</span><?= status_badge($o['request_status']) ?><small><?php if ($o['request_status'] === 'receiving_offers'): ?>ما زال يستقبل العروض.<?php elseif ($o['request_status'] === 'in_progress'): ?>المساعدة قيد التنفيذ.<?php elseif ($o['request_status'] === 'completed'): ?>اكتملت المساعدة.<?php else: ?>أُلغي الطلب.<?php endif; ?></small></div>
                </div>
                <div class="my-offer-actions">
                    <a class="btn btn-outline-primary" href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$o['rid']] + $returnContextParams)) ?>">عرض الطلب <i class="bi bi-arrow-left"></i></a>
                    <?php if ($o['status'] === 'accepted'): ?><a class="btn btn-primary" href="<?= e(app_url('chat/index.php')) ?>">انتقل إلى محادثاتي <i class="bi bi-chat-dots"></i></a><?php endif; ?>
                    <?php if ($o['status'] === 'accepted' && $o['request_status'] === 'in_progress' && me()['status'] === 'active'): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-outline-danger" data-confirm="الانسحاب نهائي وسيتم إغلاق المحادثة للكتابة.">انسحاب من المساعدة</button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="ux-empty-state"><span class="ux-empty-icon"><i class="bi bi-hand-thumbs-up"></i></span><h2>لم تقدم أي عروض حتى الآن</h2><p>استكشف الطلبات المتاحة، وافتح طلبًا تستطيع المساعدة فيه، ثم أرسل عرضك لصاحبه.</p><a class="btn btn-primary" href="<?= e(app_url('requests/index.php')) ?>"><i class="bi bi-search"></i> استكشاف الطلبات</a></section>
<?php endif; ?>
<?= pagination_ui($pagination, 'عروضك') ?>
<?php footer_ui(); ?>
