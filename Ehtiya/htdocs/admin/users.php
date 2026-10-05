<?php
/** Search student accounts and apply the existing status/major management actions. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
// Mutations are POST-only, CSRF-checked, and restricted to student records.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_only();
    $id = input_int('id');
    $action = input_string('action');
    try {
        // Lock the target row before changing a status so concurrent admin actions cannot overwrite each other.
        if ($action === 'status' && in_array(input_string('status'), ['active', 'suspended'], true)) {
            $status = input_string('status');
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT status FROM users WHERE id=? AND role='student' FOR UPDATE");
            $lock->execute([$id]);
            $user = $lock->fetch();
            if ($user && in_array($user['status'], ['active', 'suspended'], true)) {
                $update = $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='student' AND status IN ('active','suspended')");
                $update->execute([$status, $id]);
                if ($update->rowCount() === 1) {
                    audit($status === 'suspended' ? 'suspend' : 'reactivate', 'user', $id, 'تغيير حالة الحساب إلى '.$status);
                    $notificationType = $status === 'suspended' ? 'account_suspended' : 'account_reactivated';
                    $notificationTitle = $status === 'suspended' ? 'تم إيقاف الحساب مؤقتًا' : 'أُعيد تفعيل الحساب';
                    notify($id, $notificationType, $notificationTitle, $status === 'suspended' ? 'أوقف المدير حسابك مؤقتًا.' : 'أعاد المدير تفعيل حسابك.', '/student/account-status.php');
                }
            }
            $pdo->commit();
        // Major changes update the student's stored label only; request major snapshots remain untouched.
        } elseif ($action === 'major') {
            $major = trim(input_string('major'));
            $legacyLookup = $pdo->prepare("SELECT major FROM users WHERE id=? AND role='student'");
            $legacyLookup->execute([$id]);
            $currentMajor = $legacyLookup->fetchColumn();
            $keepsLegacy = is_string($currentMajor) && hash_equals($currentMajor, $major);
            if ($major !== '' && mb_strlen($major) <= 120 && (is_active_major($major) || $keepsLegacy)) {
                $pdo->beginTransaction();
                $lock = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' FOR UPDATE");
                $lock->execute([$id]);
                if ($lock->fetch()) {
                    $change = $pdo->prepare("UPDATE users SET major=? WHERE id=? AND role='student'");
                    $change->execute([$major, $id]);
                    if ($change->rowCount() === 1) {
                        audit('change_major', 'user', $id, 'تغيير التخصص');
                        notify($id, 'major_changed', 'تم تحديث التخصص', 'غيّر المدير تخصصك إلى: ' . $major, '/student/profile.php');
                    }
                }
                $pdo->commit();
            } else {
                flash('اختر تخصصًا معتمدًا ونشطًا.', 'danger');
            }
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Ehtiyaj] Admin user operation failed: '.$exception->getMessage());
        flash('تعذر حفظ التغيير. لم تُحفظ العملية.', 'danger');
    }
    go('/admin/users.php?q='.urlencode(input_string('q', 'get')));
}
// Prepare a paginated, parameterized search; form filters are preserved in detail-return links.
$term = trim(input_string('q', 'get'));
$majors = active_majors();
$searchPattern = '%'.$term.'%';
$count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND (full_name LIKE ? OR email LIKE ? OR major LIKE ?)");
$count->execute([$searchPattern, $searchPattern, $searchPattern]);
$pagination = pagination_state((int)$count->fetchColumn());
$q = $pdo->prepare("SELECT id,full_name,email,major,status,created_at FROM users WHERE role='student' AND (full_name LIKE ? OR email LIKE ? OR major LIKE ?) ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?");
$q->bindValue(1, $searchPattern, PDO::PARAM_STR);
$q->bindValue(2, $searchPattern, PDO::PARAM_STR);
$q->bindValue(3, $searchPattern, PDO::PARAM_STR);
$q->bindValue(4, $pagination['per_page'], PDO::PARAM_INT);
$q->bindValue(5, $pagination['offset'], PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();
header_ui('المستخدمون');?><?= admin_page_back_link('dashboard') ?><?= admin_page_intro('المستخدمون', 'ابحث في حسابات الطلاب وراجع بياناتها وحالتها.', 'people') ?><form class="admin-search-form" method="get" role="search"><label class="visually-hidden" for="admin-user-search">بحث عن مستخدم</label><i class="bi bi-search" aria-hidden="true"></i><input id="admin-user-search" class="form-control" name="q" value="<?=e($term)?>" placeholder="بحث بالاسم أو البريد أو التخصص"><button class="btn btn-primary" type="submit">بحث</button></form><div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>الاسم</th><th>البريد</th><th>التخصص</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><?php foreach ($rows as $u) {?><tr><td><a class="admin-user-table-link" href="<?=e(admin_detail_url('user_view.php', ['id' => (int)$u['id'], 'back_q' => $term, 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']]))?>"><span class="admin-table-avatar" aria-hidden="true"><?=e(mb_substr($u['full_name'], 0, 1))?></span><?=e($u['full_name'])?></a></td><td><?=e($u['email'])?></td><td><form method="post" class="d-flex gap-1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="action" value="major"><select class="form-select form-select-sm" name="major" aria-label="تخصص <?=e($u['full_name'])?>"><?php foreach ($majors as $major) {?><option value="<?=e($major)?>" <?=$u['major'] === $major ? 'selected' : ''?>><?=e($major)?></option><?php }if (!is_active_major($u['major'])) {?><option value="<?=e($u['major'])?>" selected><?=e($u['major'])?> (تخصص قديم معطل)</option><?php }?></select><button class="btn btn-sm btn-outline-primary">تغيير</button></form></td><td><?=status_badge($u['status'])?></td><td><?php if ($u['status'] !== 'deactivated') {?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?=$u['status'] === 'active' ? 'suspended' : 'active'?>"><button class="btn btn-sm btn-outline-<?=$u['status'] === 'active' ? 'danger' : 'success'?>"><?=$u['status'] === 'active' ? 'إيقاف' : 'إعادة تفعيل'?></button></form><?php }?></td></tr><?php }?></tbody></table></div></div><?=pagination_ui($pagination, 'المستخدمين')?><?php footer_ui();?>