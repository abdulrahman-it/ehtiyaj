<?php
/** List conversations for either participant and show each conversation's latest message preview. */
require_once __DIR__ . '/../includes/bootstrap.php';
need_role('student');
$count = $pdo->prepare('SELECT COUNT(*) FROM conversations WHERE owner_id=? OR helper_id=?');
$count->execute([me()['id'], me()['id']]);
$pagination = pagination_state((int)$count->fetchColumn());
// Resolve the other participant relative to the current user; the scalar subquery fetches the latest message body.
$q = $pdo->prepare('SELECT c.*,r.title,u.full_name other_name,(SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_msg FROM conversations c JOIN requests r ON r.id=c.request_id JOIN users u ON u.id=IF(c.owner_id=?,c.helper_id,c.owner_id) WHERE c.owner_id=? OR c.helper_id=? ORDER BY c.updated_at DESC,c.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, (int)me()['id'], PDO::PARAM_INT);
$q->bindValue(2, (int)me()['id'], PDO::PARAM_INT);
$q->bindValue(3, (int)me()['id'], PDO::PARAM_INT);
$q->bindValue(4, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(5, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
$chatBack = ui_back_context('chats');
$returnContextParams = $chatBack['key'] === 'chats'
    ? ['return_to' => 'chats', 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']]
    : $chatBack['forward'];
header_ui('محادثاتي');
?>
<nav class="ux-breadcrumb" aria-label="مسار الصفحة"><a href="<?= e(app_url('student/index.php')) ?>">الرئيسية</a><i class="bi bi-chevron-left" aria-hidden="true"></i><span>محادثاتي</span></nav>
<div class="ux-page-header"><div><span class="eyebrow"><i class="bi bi-chat-dots"></i> تواصل مرتبط بمساعدة</span><h1>محادثاتي</h1><p>كل محادثة مرتبطة بطلب وبعرض تم قبوله؛ ستعرف سبب التواصل ومن الطرف الآخر.</p></div></div>
<?php if ($rows): ?>
    <section class="conversation-list" aria-label="محادثاتك">
        <?php foreach ($rows as $c): ?>
            <article class="conversation-card" data-request-id="<?= (int)$c['request_id'] ?>" data-other-name="<?= e($c['other_name']) ?>" data-conversation-status="<?= e($c['status']) ?>">
                <div class="conversation-card-heading"><span class="conversation-card-icon"><i class="bi bi-chat-square-text"></i></span><div><span class="section-kicker">الطلب</span><h2><?= e($c['title']) ?></h2></div></div>
                <p class="conversation-with">مع <strong><?= e($c['other_name']) ?></strong></p>
                <div class="conversation-last-message"><span>آخر رسالة</span><p><?= e($c['last_msg'] ?? 'افتح المحادثة لمراجعة الرسائل أو بدء التواصل.') ?></p></div>
                <div class="conversation-card-bottom"><span class="conversation-card-state"><span class="ux-status-caption">حالة المحادثة</span><?= status_badge($c['status']) ?><time datetime="<?= e(date(DATE_ATOM, strtotime($c['updated_at']))) ?>"><i class="bi bi-clock-history" aria-hidden="true"></i> آخر نشاط <?= e(date('Y-m-d · H:i', strtotime($c['updated_at']))) ?></time></span><a class="btn btn-primary" data-open-conversation href="<?= e(ui_context_url('chat/view.php', ['id' => (int)$c['id']] + $returnContextParams)) ?>">فتح المحادثة <i class="bi bi-arrow-left"></i></a></div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="ux-empty-state"><span class="ux-empty-icon"><i class="bi bi-chat-square"></i></span><h2>لا توجد لديك محادثات بعد</h2><p>تُنشأ المحادثة تلقائيًا بعد قبول عرضٍ على طلب تشارك فيه. يمكنك فتح الطلب المقبول أو استكشاف طلبات المساعدة.</p><div class="ux-empty-actions"><a class="btn btn-primary" href="<?= e(app_url('requests/index.php')) ?>">استكشف الطلبات</a><a class="btn btn-outline-primary" href="<?= e(app_url('offers/my.php')) ?>">راجع عروضي</a></div></section>
<?php endif; ?>
<?= pagination_ui($pagination, 'المحادثات') ?>
<?php footer_ui(); ?>
