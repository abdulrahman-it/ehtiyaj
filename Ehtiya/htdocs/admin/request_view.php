<?php
/** Inspect one request and, when still published, allow the existing moderation removal action. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$id = input_int('id', 'get');
$q = $pdo->prepare('SELECT r.*,u.full_name,u.email FROM requests r JOIN users u ON u.id=r.owner_id WHERE r.id=?');
$q->execute([$id]);
$r = $q->fetch();
if (!$r) {
    http_response_code(404);
    exit('غير موجود');
}
// Removal is deliberately separate from the request's student-facing lifecycle status.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $reason = trim(input_string('reason'));
    if ($reason === '') {
        flash('سبب الإزالة مطلوب.', 'danger');
    } else {
        try {
            // Keep the moderation-state check, removal, audit entry, and affected-user notices atomic.
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT owner_id,moderation_status FROM requests WHERE id=? FOR UPDATE");
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || $current['moderation_status'] !== 'published') {
                throw new RuntimeException('لم يعد الطلب منشورًا للإزالة.');
            }
            $update = $pdo->prepare("UPDATE requests SET moderation_status='removed',moderation_reason=?,moderated_by=?,moderated_at=NOW() WHERE id=? AND moderation_status='published'");
            $update->execute([$reason, me()['id'], $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('تغيرت حالة الطلب أثناء العملية.');
            }
            notify((int)$current['owner_id'], 'request_removed', 'أزيل الطلب من المنصة', 'تمت إزالة طلبك لمخالفته. السبب: '.$reason, '/requests/my.php');
            $helpers = $pdo->prepare("SELECT DISTINCT student_id FROM offers WHERE request_id=? AND status IN ('submitted','accepted')");
            $helpers->execute([$id]);
            foreach ($helpers as $helper) {
                notify((int)$helper['student_id'], 'request_removed', 'أزيل الطلب من المنصة', 'أزيل الطلب الذي قدمت عليه عرضًا. السبب: '.$reason, '/offers/my.php');
            }
            audit('remove_request', 'request', $id, 'إزالة طلب مخالف: '.$reason);
            $pdo->commit();
            flash('تمت إزالة الطلب.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Ehtiyaj] Admin remove request failed: '.$exception->getMessage());
            flash('تعذر إتمام إزالة الطلب. لم تُحفظ العملية.', 'danger');
        }
    }
    go('/admin/request_view.php?id='.$id);
}
header_ui('مراجعة الطلب');?><?= admin_page_back_link('requests', ['page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)]) ?><?= admin_breadcrumbs([['label' => 'لوحة الإدارة', 'url' => admin_parent_url('dashboard')], ['label' => 'الطلبات', 'url' => admin_parent_url('requests', ['page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)])], ['label' => 'مراجعة الطلب']]) ?><?= admin_page_intro('مراجعة الطلب', 'تفاصيل الطلب وبيانات صاحبه وحالة النشر.', 'journal-text') ?><section class="card admin-detail-card admin-request-summary mb-3"><div class="admin-request-title"><span class="section-kicker">تفاصيل الطلب #<?= (int)$id ?></span><h2><?=e($r['title'])?></h2><div class="admin-request-statuses"><span><small>حالة الطلب</small><?=status_badge($r['status'])?></span><span><small>حالة النشر</small><?=status_badge($r['moderation_status'])?></span></div></div><div class="admin-request-description"><h3>وصف الطلب</h3><p><?=nl2br(e($r['description']))?></p></div><dl class="admin-request-facts"><div><dt>صاحب الطلب</dt><dd><?=e($r['full_name'])?></dd></div><div><dt>البريد الإلكتروني</dt><dd><?=e($r['email'])?></dd></div></dl></section><?php if ($r['moderation_status'] === 'published') {?><div class="card admin-detail-card mt-3"><h5 class="text-danger">إزالة طلب مخالف</h5><form method="post" data-confirm="إزالة هذا الطلب؟"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><textarea name="reason" class="form-control mb-3" required placeholder="سبب الإزالة"></textarea><button class="btn btn-danger">إزالة الطلب</button></form></div><?php }footer_ui();?>