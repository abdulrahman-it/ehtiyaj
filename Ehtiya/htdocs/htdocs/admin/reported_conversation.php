<?php
/** Read-only, report-scoped conversation review; access is recorded in the admin audit log. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$reportId = input_int('report_id', 'get');
$q = $pdo->prepare('SELECT c.*,r.title FROM conversation_reports x JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id WHERE x.id=?');
$q->execute([$reportId]);
$c = $q->fetch();
if (!$c) {
    http_response_code(404);
    exit('لا يوجد بلاغ صالح مرتبط بهذه المحادثة.');
}
// This route intentionally renders messages only; it adds no send or moderation controls.
audit('access_reported_conversation', 'conversation', (int)$c['id'], 'دخول محدود عبر البلاغ رقم '.$reportId);
$count = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id=?');
$count->execute([$c['id']]);
$pagination = pagination_state((int)$count->fetchColumn(), 20);
$m = $pdo->prepare('SELECT m.*,u.full_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT ? OFFSET ?');
$m->bindValue(1, (int)$c['id'], PDO::PARAM_INT);
$m->bindValue(2, $pagination['per_page'], PDO::PARAM_INT);
$m->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
$m->execute();
$rows = array_reverse($m->fetchAll());
header_ui('مراجعة محادثة مبلّغ عنها');
?>
<?= admin_page_back_link('report-view', ['id' => $reportId, 'back_page' => input_int('back_page', 'get', 1), 'back_per_page' => input_int('back_per_page', 'get', 20)]) ?>
<?= admin_breadcrumbs([['label' => 'لوحة الإدارة', 'url' => admin_parent_url('dashboard')], ['label' => 'البلاغات', 'url' => admin_parent_url('reports', ['page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)])], ['label' => 'تفاصيل البلاغ', 'url' => admin_parent_url('report-view', ['id' => $reportId, 'back_page' => input_int('back_page', 'get', 1), 'back_per_page' => input_int('back_per_page', 'get', 20)])], ['label' => 'المحادثة المرتبطة']]) ?>
<?= admin_page_intro('مراجعة المحادثة', 'محادثة مرتبطة ببلاغ؛ العرض الإداري هنا محدود للقراءة والمراجعة.', 'chat-text') ?>
<div class="alert alert-warning">وصول إداري محدود للمراجعة عبر البلاغ رقم <?=e($reportId)?>. لا يمكن الإرسال أو تعديل الرسائل.</div>
<section class="card admin-detail-card admin-reported-conversation p-0"><header class="admin-review-header"><span class="section-kicker">الطلب المرتبط</span><h2><?=e($c['title'])?></h2></header><div class="admin-review-context"><div><span>رقم الطلب</span><strong>#<?= (int)$c['request_id'] ?></strong></div><div><span>رقم المحادثة</span><strong>#<?= (int)$c['id'] ?></strong></div><div><span>حالة المحادثة</span><?php if ($c['status'] === 'read_only'): ?><span class="status-badge status-secondary">مغلقة · للقراءة فقط</span><?php else: ?><?= status_badge($c['status']) ?><?php endif; ?></div><div><span>البلاغ</span><strong>#<?= (int)$reportId ?></strong></div></div><div class="admin-review-messages"><?php foreach ($rows as $message): ?><article class="admin-review-message"><strong><?=e($message['full_name'])?></strong> <small class="text-secondary"><?=e($message['created_at'])?></small><p><?=nl2br(e($message['body']??''))?></p><?php if ($message['image_path']): ?><img src="<?=e(app_url('chat/image.php?message_id='.(int)$message['id'].'&report_id='.$reportId))?>" class="admin-review-image" alt="مرفق المحادثة"><?php endif; ?></article><?php endforeach; ?><?php if (!$rows): ?><div class="empty">لا توجد رسائل.</div><?php endif; ?></div></section>
<?=pagination_ui($pagination, 'رسائل البلاغ')?>
<?php footer_ui(); ?>
