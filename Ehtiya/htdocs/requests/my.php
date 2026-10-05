<?php
/** Paginated request history scoped to the signed-in student. */
require_once __DIR__ . '/../includes/bootstrap.php';
need_role('student');
// Count only the current owner's requests so the page total matches the scoped result list.
$count = $pdo->prepare('SELECT COUNT(*) FROM requests WHERE owner_id=?');
$count->execute([me()['id']]);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare('SELECT r.*,t.name type_name FROM requests r JOIN assistance_types t ON t.id=r.assistance_type_id WHERE r.owner_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, (int)me()['id'], PDO::PARAM_INT);
$q->bindValue(2, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
$returnContextParams = ['return_to' => 'mine', 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']];
header_ui('طلباتي');
?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(app_url('student/index.php')) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>طلباتي</span></nav>
<div class="ux-page-header">
    <div><span class="eyebrow"><i class="bi bi-journal-check"></i> ما أنشأته</span><h1>طلباتي</h1><p>الطلبات التي نشرتها للحصول على مساعدة من الطلاب.</p></div>
    <a class="btn btn-primary" href="<?= e(ui_context_url('requests/create.php', $returnContextParams)) ?>"><i class="bi bi-plus-lg"></i> إنشاء طلب</a>
</div>
<p class="ux-list-intro">افتح أي طلب لمراجعة العروض وتحديث حالته. <strong>العرض ليس طلبًا جديدًا؛ إنه استجابة من طالب لطلبك.</strong></p>

<?php if ($rows): ?>
    <section class="my-request-list" aria-label="الطلبات التي أنشأتها">
        <?php foreach ($rows as $r): ?>
            <article class="my-request-card">
                <div class="my-request-card-top"><span class="request-identity"><i class="bi bi-journal-text" aria-hidden="true"></i> طلب مساعدة</span><span class="type-label"><i class="bi bi-bookmark"></i><?= e($r['type_name']) ?></span><span class="ux-status-caption">حالة الطلب</span><?= status_badge($r['status']) ?></div>
                <h2><a href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$r['id']] + $returnContextParams)) ?>"><?= e($r['title']) ?></a></h2>
                <div class="my-request-meta"><time datetime="<?= e(date(DATE_ATOM, strtotime($r['created_at']))) ?>"><i class="bi bi-calendar3"></i> أُنشئ في <?= e(date('Y-m-d', strtotime($r['created_at']))) ?></time></div>
                <p class="my-request-description"><?= e(mb_strimwidth($r['description'], 0, 135, '…')) ?></p>
                <p class="my-request-next">
                    <?php if ($r['status'] === 'receiving_offers'): ?>بانتظار عروض الطلاب؛ ستراجعها من تفاصيل هذا الطلب.<?php elseif ($r['status'] === 'in_progress'): ?>المساعدة قيد التنفيذ؛ تابع المحادثات من «محادثاتي».<?php elseif ($r['status'] === 'completed'): ?>اكتملت المساعدة لهذا الطلب.<?php else: ?>أُلغي هذا الطلب ولا يستقبل عروضًا جديدة.<?php endif; ?>
                </p>
                <div class="my-request-actions"><a class="btn btn-outline-primary" href="<?= e(ui_context_url('requests/view.php', ['id' => (int)$r['id']] + $returnContextParams)) ?>">عرض الطلب ومتابعته <i class="bi bi-arrow-left"></i></a><?php if ($r['status'] === 'in_progress'): ?><a class="text-action" href="<?= e(app_url('chat/index.php')) ?>">فتح محادثاتي</a><?php endif; ?></div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="ux-empty-state"><span class="ux-empty-icon"><i class="bi bi-journal-plus"></i></span><h2>لا توجد لديك طلبات حتى الآن</h2><p>أنشئ طلبًا يشرح ما تحتاج إليه؛ ستظهر عروض الطلاب داخل تفاصيل الطلب نفسه.</p><a class="btn btn-primary" href="<?= e(ui_context_url('requests/create.php', $returnContextParams)) ?>"><i class="bi bi-plus-lg"></i> إنشاء أول طلب</a></section>
<?php endif; ?>
<?= pagination_ui($pagination, 'طلباتك') ?>
<?php footer_ui(); ?>
