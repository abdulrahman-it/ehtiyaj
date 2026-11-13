<?php
/** Search student accounts and apply the existing status/major management actions. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
if (array_key_exists('q', $_GET) && !is_string($_GET['q'])) {
    http_response_code(400);
    exit('عبارة البحث غير صالحة.');
}
$userStatus = admin_filter_input('user_status');
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
            $result = ['لم يُعثر على حساب الطالب.', 'warning'];
            if ($user && !in_array($user['status'], ['active', 'suspended'], true)) {
                $result = ['لا يمكن تغيير حالة هذا الحساب بهذه العملية.', 'warning'];
            } elseif ($user) {
                $update = $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='student' AND status IN ('active','suspended')");
                $update->execute([$status, $id]);
                if ($update->rowCount() === 1) {
                    audit($status === 'suspended' ? 'suspend' : 'reactivate', 'user', $id, 'تغيير حالة الحساب إلى '.$status);
                    $notificationType = $status === 'suspended' ? 'account_suspended' : 'account_reactivated';
                    $notificationTitle = $status === 'suspended' ? 'تم إيقاف الحساب مؤقتًا' : 'أُعيد تفعيل الحساب';
                    notify($id, $notificationType, $notificationTitle, $status === 'suspended' ? 'أوقف المدير حسابك مؤقتًا.' : 'أعاد المدير تفعيل حسابك.', '/student/account-status.php');
                    $result = ['تم تحديث حالة حساب الطالب.', 'success'];
                } else {
                    $result = ['لم تتغير حالة الحساب.', 'warning'];
                }
            }
            if (!$pdo->commit()) throw new RuntimeException('Commit failed');
            flash($result[0], $result[1]);
        // Major changes update the student's stored label only; request major snapshots remain untouched.
        } elseif ($action === 'major') {
            $major = trim(input_string('major'));
            $legacyLookup = $pdo->prepare("SELECT major FROM users WHERE id=? AND role='student'");
            $legacyLookup->execute([$id]);
            $currentMajor = $legacyLookup->fetchColumn();
            $keepsLegacy = is_string($currentMajor) && hash_equals($currentMajor, $major);
            if ($currentMajor === false) {
                flash('لم يُعثر على حساب الطالب.', 'warning');
            } elseif ($major !== '' && mb_strlen($major) <= 120 && (is_active_major($major) || $keepsLegacy)) {
                $pdo->beginTransaction();
                $lock = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' FOR UPDATE");
                $lock->execute([$id]);
                $result = ['لم يُعثر على حساب الطالب.', 'warning'];
                if ($lock->fetch()) {
                    $change = $pdo->prepare("UPDATE users SET major=? WHERE id=? AND role='student'");
                    $change->execute([$major, $id]);
                    if ($change->rowCount() === 1) {
                        audit('change_major', 'user', $id, 'تغيير التخصص');
                        notify($id, 'major_changed', 'تم تحديث التخصص', 'غيّر المدير تخصصك إلى: ' . $major, '/student/profile.php');
                        $result = ['تم تحديث تخصص الطالب.', 'success'];
                    } else {
                        $result = ['لم يتغير تخصص الطالب.', 'warning'];
                    }
                }
                if (!$pdo->commit()) throw new RuntimeException('Commit failed');
                flash($result[0], $result[1]);
            } else {
                flash('اختر تخصصًا معتمدًا ونشطًا.', 'danger');
            }
        } else {
            flash('الإجراء المطلوب غير صالح.', 'warning');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Ehtiyaj] Admin user operation failed: '.$exception->getMessage());
        flash('تعذر تأكيد التغيير. راجع حالة الحساب قبل إعادة المحاولة.', 'danger');
    }
    $returnUrl = admin_parent_url('users', ['q' => input_string('q', 'get'), 'page' => input_string('page', 'get'), 'per_page' => input_string('per_page', 'get'), 'user_status' => $userStatus]);
    go(substr($returnUrl, strlen(BASE_URL)));
}
// Prepare a paginated, parameterized search; form filters are preserved in detail-return links.
$term = trim(input_string('q', 'get'));
$majors = active_majors();
$searchPattern = '%'.$term.'%';
$userFilterSql = $userStatus !== '' ? ' AND status = ?' : '';
$userSearchParams = [$searchPattern, $searchPattern, $searchPattern];
if ($userStatus !== '') $userSearchParams[] = $userStatus;
$loadError = false;
$rows = [];
$pagination = pagination_state(0);
$emptyMessage = '';
try {
    $count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND (full_name LIKE ? OR email LIKE ? OR major LIKE ?)" . $userFilterSql);
    $count->execute($userSearchParams);
    $pagination = pagination_state((int)$count->fetchColumn());
    $q = $pdo->prepare("SELECT id,full_name,email,major,status,created_at FROM users WHERE role='student' AND (full_name LIKE ? OR email LIKE ? OR major LIKE ?)" . $userFilterSql . ' ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?');
    $paramIndex = 1;
    foreach ($userSearchParams as $param) $q->bindValue($paramIndex++, $param, PDO::PARAM_STR);
    $q->bindValue($paramIndex++, $pagination['per_page'], PDO::PARAM_INT);
    $q->bindValue($paramIndex, $pagination['offset'], PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll();
    if (!$rows) {
        $studentCount = $term === '' && $userStatus === '' ? $pagination['total'] : (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
        $emptyMessage = $studentCount === 0 ? 'لا توجد حسابات طلاب مسجلة.' : 'لا توجد حسابات مطابقة للبحث أو التصفية.';
    }
} catch (Throwable $exception) {
    error_log('[Ehtiyaj] Admin user list failed: '.$exception->getMessage());
    http_response_code(500);
    $loadError = true;
    $rows = [];
}
header_ui('المستخدمون');?><?= admin_page_back_link('dashboard') ?><?= admin_page_intro('المستخدمون', 'ابحث في حسابات الطلاب وراجع بياناتها وحالتها.', 'people') ?><form class="d-flex flex-wrap align-items-end gap-2 mb-3" method="get" role="search"><div class="flex-grow-1"><label class="form-label" for="admin-user-search"><i class="bi bi-search" aria-hidden="true"></i> البحث عن طالب</label><input id="admin-user-search" class="form-control" name="q" value="<?=e($term)?>" placeholder="بحث بالاسم أو البريد أو التخصص"></div><input type="hidden" name="per_page" value="<?= (int)$pagination['per_page'] ?>"><div><label class="form-label" for="user-status-filter">حالة الحساب</label><select class="form-select" id="user-status-filter" name="user_status"><option value="">جميع الطلاب</option><option value="active" <?= $userStatus === 'active' ? 'selected' : '' ?>>نشط</option><option value="suspended" <?= $userStatus === 'suspended' ? 'selected' : '' ?>>موقوف مؤقتًا</option></select></div><button class="btn btn-primary" type="submit">بحث وتصفية</button></form><div class="card admin-data-card"><div class="table-responsive"><table class="table"><thead><tr><th>الاسم</th><th>البريد</th><th>التخصص</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><?php foreach ($rows as $u) {?><tr><td><a class="admin-user-table-link" href="<?=e(admin_detail_url('user_view.php', ['id' => (int)$u['id'], 'back_q' => $term, 'back_user_status' => $userStatus, 'back_page' => $pagination['page'], 'back_per_page' => $pagination['per_page']]))?>"><span class="admin-table-avatar" aria-hidden="true"><?=e(mb_substr($u['full_name'], 0, 1))?></span><?=e($u['full_name'])?></a></td><td><?=e($u['email'])?></td><td><form method="post" class="d-flex gap-1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="action" value="major"><select class="form-select form-select-sm" name="major" aria-label="تخصص <?=e($u['full_name'])?>"><?php foreach ($majors as $major) {?><option value="<?=e($major)?>" <?=$u['major'] === $major ? 'selected' : ''?>><?=e($major)?></option><?php }if (!is_active_major($u['major'])) {?><option value="<?=e($u['major'])?>" selected><?=e($u['major'])?> (تخصص قديم معطل)</option><?php }?></select><button class="btn btn-sm btn-outline-primary">تغيير</button></form></td><td><?=status_badge($u['status'])?></td><td><?php if ($u['status'] !== 'deactivated') {?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?=$u['status'] === 'active' ? 'suspended' : 'active'?>"><button class="btn btn-sm btn-outline-<?=$u['status'] === 'active' ? 'danger' : 'success'?>"><?=$u['status'] === 'active' ? 'إيقاف' : 'إعادة تفعيل'?></button></form><?php }?></td></tr><?php }?><?php if ($loadError): ?><tr><td colspan="5" class="empty" role="alert">تعذر تحميل حسابات الطلاب. حاول مرة أخرى لاحقًا.</td></tr><?php elseif (!$rows): ?><tr><td colspan="5" class="empty"><?=e($emptyMessage)?></td></tr><?php endif; ?></tbody></table></div></div><?php if (!$loadError): ?><?=pagination_ui($pagination, 'المستخدمين')?><?php endif; ?><?php footer_ui();?>