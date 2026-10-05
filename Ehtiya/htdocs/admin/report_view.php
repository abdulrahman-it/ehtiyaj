<?php
/** Review one conversation report and close the report record through its existing workflow. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$id = input_int('id', 'get');
$q = $pdo->prepare('SELECT x.*,c.id cid,r.title,u.full_name FROM conversation_reports x JOIN conversations c ON c.id=x.conversation_id JOIN requests r ON r.id=c.request_id JOIN users u ON u.id=x.reporter_id WHERE x.id=?');
$q->execute([$id]);
$r = $q->fetch();
if (!$r) {
    http_response_code(404);
    exit('غير موجود');
}
// Closing a report updates the report record only; conversation permissions remain governed by their own lifecycle.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $note = trim(input_string('note'));
    try {
        // The conditional UPDATE makes repeated submissions idempotent; audit only a successful state change.
        $pdo->beginTransaction();
        $close = $pdo->prepare("UPDATE conversation_reports SET status='closed',reviewed_by=?,resolution_note=? WHERE id=? AND status='open'");
        $close->execute([me()['id'],$note ?: null,$id]);
        if ($close->rowCount() === 1) {
            audit('review_report', 'report', $id, 'إغلاق البلاغ');
            $pdo->commit();
            flash('تم إغلاق البلاغ.');
        } else {
            $pdo->rollBack();
            flash('تمت مراجعة البلاغ مسبقًا.', 'warning');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Ehtiyaj] Report review failed: '.$exception->getMessage());
        flash('تعذر إغلاق البلاغ. لم تُحفظ العملية.', 'danger');
    }
    go('/admin/report_view.php?id='.$id);
}header_ui('تفاصيل البلاغ');?><?= admin_page_back_link('reports', ['page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)]) ?><?= admin_breadcrumbs([['label' => 'لوحة الإدارة', 'url' => admin_parent_url('dashboard')], ['label' => 'البلاغات', 'url' => admin_parent_url('reports', ['page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)])], ['label' => 'تفاصيل البلاغ']]) ?><?= admin_page_intro('تفاصيل البلاغ', 'مراجعة سبب البلاغ والانتقال إلى المحادثة المرتبطة.', 'flag') ?><section class="card admin-detail-card admin-report-summary">
    <div class="admin-report-title"><span class="admin-report-icon"><i class="bi bi-flag" aria-hidden="true"></i></span><div><span class="section-kicker">معلومات البلاغ #<?= (int)$id ?></span><h2>بلاغ عن: <?=e($r['title'])?></h2></div><?= status_badge($r['status']) ?></div>
    <dl class="admin-report-facts">
        <div><dt>المبلّغ</dt><dd><?=e($r['full_name'])?></dd></div>
        <div><dt>الطلب المرتبط</dt><dd><?=e($r['title'])?></dd></div>
        <div><dt>رقم المحادثة</dt><dd>#<?= (int)$r['cid'] ?></dd></div>
    </dl>
    <div class="admin-report-reason"><span>سبب البلاغ</span><p><?=nl2br(e($r['reason']))?></p></div>
    <div class="admin-detail-actions"><a class="btn btn-outline-primary" href="<?= e(admin_detail_url('reported_conversation.php', ['report_id' => $id, 'back_page' => input_int('back_page', 'get', 1), 'back_per_page' => input_int('back_per_page', 'get', 20)])) ?>"><i class="bi bi-chat-square-text" aria-hidden="true"></i> مراجعة المحادثة المرتبطة</a></div>
</section><?php if ($r['status'] === 'open') {?><section class="card admin-detail-card admin-report-action mt-3"><h2 class="h5">إغلاق البلاغ</h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><textarea name="note" class="form-control mb-3" placeholder="ملاحظة الحل (اختياري)"></textarea><button class="btn btn-primary">إغلاق</button></form></section><?php }footer_ui();?>