<?php
/** Show a student account summary and its existing request count/actions. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
$id = input_int('id', 'get');
// Scope the lookup by both ID and role so an admin detail URL cannot expose another admin account.
$q = $pdo->prepare("SELECT id,full_name,email,major,status,created_at FROM users WHERE id=? AND role='student'");
$q->execute([$id]);
$u = $q->fetch();
if (!$u) {
    http_response_code(404);
    exit('المستخدم غير موجود.');
}$n = $pdo->prepare('SELECT COUNT(*) FROM requests WHERE owner_id=?');
$n->execute([$id]);
$request_count = $n->fetchColumn();
header_ui('ملف المستخدم');?><?= admin_page_back_link('users', ['q' => input_string('back_q', 'get'), 'page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)]) ?><?= admin_breadcrumbs([['label' => 'لوحة الإدارة', 'url' => admin_parent_url('dashboard')], ['label' => 'المستخدمون', 'url' => admin_parent_url('users', ['q' => input_string('back_q', 'get'), 'page' => input_int('back_page', 'get', 1), 'per_page' => input_int('back_per_page', 'get', 20)])], ['label' => 'تفاصيل المستخدم']]) ?><?= admin_page_intro('تفاصيل المستخدم', 'مراجعة بيانات الحساب وحالته والإجراءات المتاحة.', 'person-lines-fill') ?><section class="card admin-user-detail-card">
    <div class="admin-user-profile-head"><span class="admin-user-avatar" aria-hidden="true"><?= e(mb_substr($u['full_name'], 0, 1)) ?></span><div><span class="section-kicker">ملف طالب</span><h2><?= e($u['full_name']) ?></h2><p><?= e($u['email']) ?></p></div><?= status_badge($u['status']) ?></div>
    <div class="admin-user-facts">
        <div><span>الدور</span><strong>طالب</strong></div>
        <div><span>التخصص</span><strong><?= e($u['major']) ?></strong></div>
        <div><span>تاريخ الانضمام</span><strong><?= e($u['created_at']) ?></strong></div>
        <div><span>الطلبات المنشأة</span><strong><?= e($request_count) ?></strong></div>
    </div>
    <div class="admin-detail-actions">
        <?php if ($u['status'] !== 'deactivated') {?>
            <form method="post" action="<?=BASE_URL?>/admin/users.php" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?=$u['status'] === 'active' ? 'suspended' : 'active'?>"><button class="btn btn-outline-<?=$u['status'] === 'active' ? 'danger' : 'success'?>"><?=$u['status'] === 'active' ? 'إيقاف الحساب' : 'إعادة التفعيل'?></button></form>
        <?php }?>
    </div>
</section><?php footer_ui();?>